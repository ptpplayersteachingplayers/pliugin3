<?php
/**
 * PTP Admin AJAX Handlers
 * Session management, compliance emails, payouts
 */

defined('ABSPATH') || exit;

class PTP_Admin_Ajax {
    
    public static function init() {
        add_action('wp_ajax_ptp_admin_create_booking', array(__CLASS__, 'create_booking'));
        add_action('wp_ajax_ptp_admin_update_booking', array(__CLASS__, 'update_booking'));
        add_action('wp_ajax_ptp_admin_delete_booking', array(__CLASS__, 'delete_booking'));
        add_action('wp_ajax_ptp_admin_get_booking', array(__CLASS__, 'get_booking'));
        add_action('wp_ajax_ptp_admin_update_trainer', array(__CLASS__, 'update_trainer'));
        add_action('wp_ajax_ptp_admin_get_trainer', array(__CLASS__, 'get_trainer'));
        add_action('wp_ajax_ptp_admin_upload_trainer_photo', array(__CLASS__, 'upload_trainer_photo'));
        add_action('wp_ajax_ptp_admin_send_safesport_request', array(__CLASS__, 'send_safesport_request'));
        add_action('wp_ajax_ptp_admin_send_w9_request', array(__CLASS__, 'send_w9_request'));
        add_action('wp_ajax_ptp_admin_send_background_check_request', array(__CLASS__, 'send_background_check_request'));
        add_action('wp_ajax_ptp_admin_mark_verified', array(__CLASS__, 'mark_trainer_verified'));
        add_action('wp_ajax_ptp_admin_process_payout', array(__CLASS__, 'process_payout'));
        add_action('wp_ajax_ptp_admin_mark_payout_complete', array(__CLASS__, 'mark_payout_complete'));
        add_action('wp_ajax_ptp_admin_get_parent_players', array(__CLASS__, 'get_parent_players'));
        // v200: Parent detail + SMS
        add_action('wp_ajax_ptp_admin_get_parent_detail', array(__CLASS__, 'get_parent_detail'));
        add_action('wp_ajax_ptp_admin_send_sms', array(__CLASS__, 'admin_send_sms'));
        // v216: Send Stripe Express Dashboard link to trainer
        add_action('wp_ajax_ptp_send_stripe_dashboard_link', array(__CLASS__, 'send_stripe_dashboard_link'));
        // v135: Reset trainer password, nudge, impersonate
        add_action('wp_ajax_ptp_admin_reset_trainer_password', array(__CLASS__, 'reset_trainer_password'));
        add_action('wp_ajax_ptp_admin_update_trainer_email', array(__CLASS__, 'update_trainer_email'));
        add_action('wp_ajax_ptp_admin_nudge_trainer', array(__CLASS__, 'nudge_trainer'));
        add_action('wp_ajax_ptp_admin_impersonate_trainer', array(__CLASS__, 'impersonate_trainer'));
    }
    
    private static function verify_admin() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            exit;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_admin_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            exit;
        }
    }
    
    public static function create_booking() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $parent_id = intval($_POST['parent_id']);
        $player_id = intval($_POST['player_id']);
        $session_date = sanitize_text_field($_POST['session_date']);
        $start_time = sanitize_text_field($_POST['start_time']);
        $end_time = sanitize_text_field($_POST['end_time']);
        $location = sanitize_text_field($_POST['location']);
        $hourly_rate = floatval($_POST['hourly_rate']);
        $notes = sanitize_textarea_field($_POST['notes']);
        $status = sanitize_text_field($_POST['status']);
        
        $start = strtotime($start_time);
        $end = strtotime($end_time);
        $duration = ($end - $start) / 60;
        
        // v223-fix: Prevent double booking (overlap-aware)
        if (class_exists('PTP_Booking') && method_exists('PTP_Booking', 'has_conflict')) {
            $conflict = PTP_Booking::has_conflict($trainer_id, $session_date, $start_time, $duration);
            if ($conflict) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT booking_number, start_time, end_time FROM {$wpdb->prefix}ptp_bookings WHERE id = %d", $conflict
                ));
                $msg = 'Double booking blocked — trainer already has a session on ' . $session_date;
                if ($existing) {
                    $msg .= ' from ' . date('g:i A', strtotime($existing->start_time)) . ' to ' . date('g:i A', strtotime($existing->end_time))
                          . ' (Booking #' . $existing->booking_number . ')';
                }
                wp_send_json_error(array('message' => $msg));
            }
        }
        
        $total_amount = ($duration / 60) * $hourly_rate;
        $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'booking_number' => $booking_number,
                'trainer_id' => $trainer_id,
                'parent_id' => $parent_id,
                'player_id' => $player_id,
                'session_date' => $session_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_minutes' => $duration,
                'location' => $location,
                'hourly_rate' => $hourly_rate,
                'total_amount' => $total_amount,
                'notes' => $notes,
                'status' => $status,
                'payment_status' => 'admin_created',
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id()
            )
        );
        
        if ($result) {
            wp_send_json_success(array('message' => 'Session created', 'booking_number' => $booking_number));
        } else {
            wp_send_json_error(array('message' => 'Database error'));
        }
    }
    
    public static function update_booking() {
        self::verify_admin();
        global $wpdb;
        
        $booking_id = intval($_POST['booking_id']);
        $data = array();
        
        $fields = array('session_date', 'start_time', 'end_time', 'location', 'notes', 'status');
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        if (isset($_POST['hourly_rate'])) {
            $data['hourly_rate'] = floatval($_POST['hourly_rate']);
        }
        
        // v223-fix: If date or time changed, check for overlapping bookings
        if ((isset($data['session_date']) || isset($data['start_time'])) && class_exists('PTP_Booking') && method_exists('PTP_Booking', 'has_conflict')) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d", $booking_id));
            if ($existing) {
                $check_date  = $data['session_date'] ?? $existing->session_date;
                $check_start = $data['start_time']   ?? $existing->start_time;
                $check_dur   = intval($existing->duration_minutes) ?: 60;
                // Recalculate duration if both start and end provided
                if (isset($data['start_time']) && isset($data['end_time'])) {
                    $check_dur = max(30, (strtotime($data['end_time']) - strtotime($data['start_time'])) / 60);
                }
                $conflict = PTP_Booking::has_conflict($existing->trainer_id, $check_date, $check_start, $check_dur, $booking_id);
                if ($conflict) {
                    wp_send_json_error(array('message' => 'Cannot update — conflicts with existing booking #' . $conflict));
                }
            }
        }
        
        $result = $wpdb->update($wpdb->prefix . 'ptp_bookings', $data, array('id' => $booking_id));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Booking updated'));
        } else {
            wp_send_json_error(array('message' => 'Update failed'));
        }
    }
    
    public static function get_booking() {
        self::verify_admin();
        global $wpdb;
        
        $booking_id = intval($_POST['booking_id']);
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if ($booking) {
            wp_send_json_success($booking);
        } else {
            wp_send_json_error(array('message' => 'Not found'));
        }
    }
    
    public static function delete_booking() {
        self::verify_admin();
        global $wpdb;
        
        $booking_id = intval($_POST['booking_id']);
        $result = $wpdb->delete($wpdb->prefix . 'ptp_bookings', array('id' => $booking_id));
        
        if ($result) {
            wp_send_json_success(array('message' => 'Deleted'));
        } else {
            wp_send_json_error(array('message' => 'Delete failed'));
        }
    }
    
    public static function get_trainer() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if ($trainer) {
            wp_send_json_success($trainer);
        } else {
            wp_send_json_error(array('message' => 'Not found'));
        }
    }
    
    public static function update_trainer() {
        self::verify_admin();
        global $wpdb;
        
        // v213: Strip WordPress magic quotes from POST data
        // Without this, JSON fields (like training_locations) get corrupted:
        // [{"name":"Wilson Farm Park"}] becomes [{\"name\":\"Wilson Farm Park\"}]
        // which fails json_decode() on the frontend
        $_POST = wp_unslash($_POST);
        
        $trainer_id = intval($_POST['trainer_id']);
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer ID'));
        }
        
        $data = array();
        
        // v194: All text fields from every tab
        $text_fields = array(
            // Profile
            'display_name', 'slug', 'email', 'phone', 'headline',
            // Experience
            'playing_level', 'position', 'college', 'team', 'specialties', 'lesson_lengths',
            // Pricing & Location
            'location', 'city', 'state',
            // Social
            'instagram', 'facebook', 'twitter',
            // Payments
            'payout_method', 'payout_venmo', 'payout_paypal', 'payout_zelle', 'payout_cashapp',
            // Admin
            'status',
        );
        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        
        // Textarea fields
        $textarea_fields = array('bio', 'coaching_why', 'training_philosophy', 'training_locations', 'gallery');
        foreach ($textarea_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_textarea_field($_POST[$field]);
            }
        }
        
        // URL fields
        $url_fields = array('photo_url', 'intro_video_url', 'cover_photo_url', 'safesport_doc_url', 'background_doc_url');
        foreach ($url_fields as $field) {
            if (isset($_POST[$field])) {
                $val = trim($_POST[$field]);
                $data[$field] = $val ? esc_url_raw($val) : '';
            }
        }
        
        // Numeric fields
        $numeric_fields = array(
            'hourly_rate' => 'float',
            'travel_radius' => 'int',
            'experience_years' => 'int',
            'years_coaching' => 'int',
            'max_participants' => 'int',
            'sort_order' => 'int',
        );
        foreach ($numeric_fields as $field => $type) {
            if (isset($_POST[$field])) {
                $data[$field] = ($type === 'float') ? floatval($_POST[$field]) : intval($_POST[$field]);
            }
        }
        
        // Lat/lng
        if (isset($_POST['latitude']) && $_POST['latitude'] !== '') {
            $data['latitude'] = floatval($_POST['latitude']);
        }
        if (isset($_POST['longitude']) && $_POST['longitude'] !== '') {
            $data['longitude'] = floatval($_POST['longitude']);
        }
        
        // Date fields
        if (isset($_POST['safesport_expiry']) && $_POST['safesport_expiry'] !== '') {
            $data['safesport_expiry'] = sanitize_text_field($_POST['safesport_expiry']);
        }
        
        // All checkboxes — explicitly handle 0/1
        $checkboxes = array(
            'safesport_verified', 'w9_submitted', 'background_verified',
            'contractor_agreement_signed', 'is_verified', 'is_featured', 'is_supercoach',
        );
        foreach ($checkboxes as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = intval($_POST[$field]) ? 1 : 0;
            }
        }
        
        // Auto-generate slug if display_name changed and slug empty
        if (!empty($data['display_name']) && empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['display_name']);
        }
        
        // Only update columns that actually exist in the table
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_trainers");
        $safe_data = array_intersect_key($data, array_flip($columns));
        
        if (empty($safe_data)) {
            wp_send_json_error(array('message' => 'No valid fields to update'));
        }
        
        $result = $wpdb->update($wpdb->prefix . 'ptp_trainers', $safe_data, array('id' => $trainer_id));
        
        if ($result !== false) {
            ptp_log('[PTP Admin] Trainer #' . $trainer_id . ' updated: ' . implode(', ', array_keys($safe_data)));
            wp_send_json_success(array('message' => 'Trainer updated', 'fields_updated' => count($safe_data)));
        } else {
            wp_send_json_error(array('message' => 'Update failed: ' . $wpdb->last_error));
        }
    }
    
    /**
     * Handle admin trainer photo upload
     */
    public static function upload_trainer_photo() {
        self::verify_admin();
        
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'No file uploaded or upload error'));
            return;
        }
        
        $trainer_id = intval($_POST['trainer_id']);
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer ID'));
            return;
        }
        
        // Validate file type
        $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        $file_type = wp_check_filetype($_FILES['photo']['name']);
        if (!in_array($_FILES['photo']['type'], $allowed_types)) {
            wp_send_json_error(array('message' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP'));
            return;
        }
        
        // Handle upload
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $attachment_id = media_handle_upload('photo', 0);
        
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('message' => 'Upload failed: ' . $attachment_id->get_error_message()));
            return;
        }
        
        $photo_url = wp_get_attachment_url($attachment_id);
        
        // Update trainer record
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('photo_url' => $photo_url),
            array('id' => $trainer_id)
        );
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Photo uploaded successfully',
                'photo_url' => $photo_url,
                'attachment_id' => $attachment_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to update trainer record'));
        }
    }
    
    public static function send_safesport_request() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer || !$trainer->user_email) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $result = self::send_compliance_email($trainer, 'safesport');
        
        if ($result) {
            $wpdb->update($wpdb->prefix . 'ptp_trainers', 
                array('safesport_requested_at' => current_time('mysql')), 
                array('id' => $trainer_id)
            );
            wp_send_json_success(array('message' => 'SafeSport request sent to ' . $trainer->user_email));
        } else {
            wp_send_json_error(array('message' => 'Failed to send email'));
        }
    }
    
    public static function send_w9_request() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer || !$trainer->user_email) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $result = self::send_compliance_email($trainer, 'w9');
        
        if ($result) {
            $wpdb->update($wpdb->prefix . 'ptp_trainers', 
                array('w9_requested_at' => current_time('mysql')), 
                array('id' => $trainer_id)
            );
            wp_send_json_success(array('message' => 'W9 request sent to ' . $trainer->user_email));
        } else {
            wp_send_json_error(array('message' => 'Failed to send email'));
        }
    }
    
    public static function send_background_check_request() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer || !$trainer->user_email) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $result = self::send_compliance_email($trainer, 'background');
        
        if ($result) {
            wp_send_json_success(array('message' => 'Background check request sent'));
        } else {
            wp_send_json_error(array('message' => 'Failed to send email'));
        }
    }
    
    public static function mark_trainer_verified() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $verified = intval($_POST['verified']);
        
        $data = array('is_verified' => $verified);
        
        if ($verified && isset($_POST['safesport'])) {
            $data['safesport_verified'] = 1;
        }
        if ($verified && isset($_POST['w9'])) {
            $data['w9_submitted'] = 1;
        }
        if ($verified && isset($_POST['background'])) {
            $data['background_verified'] = 1;
        }
        
        $result = $wpdb->update($wpdb->prefix . 'ptp_trainers', $data, array('id' => $trainer_id));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Verification updated'));
        } else {
            wp_send_json_error(array('message' => 'Update failed'));
        }
    }
    
    private static function send_compliance_email($trainer, $type) {
        $first_name = explode(' ', $trainer->display_name)[0];
        $dashboard_url = home_url('/trainer-dashboard/');
        
        $subjects = array(
            'safesport' => 'Action Required: SafeSport Certification - PTP Training',
            'w9' => 'Action Required: W-9 Tax Form - PTP Training',
            'background' => 'Action Required: Background Check - PTP Training'
        );
        
        $subject = isset($subjects[$type]) ? $subjects[$type] : 'Action Required - PTP Training';
        
        $body = self::get_compliance_email_body($type, $first_name, $dashboard_url);
        
        add_filter('wp_mail_content_type', array(__CLASS__, 'set_html_content_type'));
        $result = wp_mail($trainer->user_email, $subject, $body);
        remove_filter('wp_mail_content_type', array(__CLASS__, 'set_html_content_type'));
        
        return $result;
    }
    
    public static function set_html_content_type() {
        return 'text/html';
    }
    
    private static function get_compliance_email_body($type, $first_name, $dashboard_url) {
        $content = '';
        
        if ($type === 'safesport') {
            $content = '<h1 style="margin:0 0 8px;font-size:26px;font-weight:800;color:#0E0F11;">SafeSport Certification Required</h1>
            <p style="margin:0 0 24px;font-size:16px;color:#6B7280;">Hi ' . esc_html($first_name) . ', to complete your trainer profile, you need to complete SafeSport training.</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">SafeSport teaches how to recognize and prevent abuse in athletics. It\'s required for all PTP trainers.</p>
            <p style="margin:0 0 24px;font-size:14px;color:#374151;"><strong>Cost:</strong> Free | <strong>Duration:</strong> 90 min | <strong>Valid:</strong> 2 years</p>
            <p style="margin:0;"><a href="https://safesport.org" style="display:inline-block;background:#FCB900;color:#0E0F11;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:700;">Complete SafeSport Training</a></p>';
        } elseif ($type === 'w9') {
            $content = '<h1 style="margin:0 0 8px;font-size:26px;font-weight:800;color:#0E0F11;">W-9 Form Required</h1>
            <p style="margin:0 0 24px;font-size:16px;color:#6B7280;">Hi ' . esc_html($first_name) . ', as an independent contractor, we need your W-9 on file for tax purposes.</p>
            <p style="margin:0 0 24px;font-size:14px;color:#374151;">The IRS requires a W-9 for any contractor paid $600+ annually. This allows us to issue your 1099-NEC.</p>
            <p style="margin:0;"><a href="https://www.irs.gov/pub/irs-pdf/fw9.pdf" style="display:inline-block;background:#FCB900;color:#0E0F11;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:700;">Download W-9 Form</a></p>';
        } elseif ($type === 'background') {
            $content = '<h1 style="margin:0 0 8px;font-size:26px;font-weight:800;color:#0E0F11;">Background Check Required</h1>
            <p style="margin:0 0 24px;font-size:16px;color:#6B7280;">Hi ' . esc_html($first_name) . ', to ensure player safety, all PTP trainers must complete a background check.</p>
            <p style="margin:0 0 24px;font-size:14px;color:#374151;"><strong>Cost:</strong> Covered by PTP | <strong>Duration:</strong> 2-5 business days</p>
            <p style="margin:0;"><a href="' . esc_url($dashboard_url) . '" style="display:inline-block;background:#FCB900;color:#0E0F11;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:700;">Start Background Check</a></p>';
        }
        
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background:#0E0F11;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#0E0F11;">
        <tr><td align="center" style="padding:40px 20px;">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
        <tr><td align="center" style="padding-bottom:24px;"><img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" width="100"></td></tr>
        <tr><td style="background:#fff;border-radius:16px;padding:36px;">' . $content . '</td></tr>
        <tr><td style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">PTP Training - Elite 1-on-1 Training</td></tr>
        </table></td></tr></table></body></html>';
    }
    
    public static function process_payout() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $amount = floatval($_POST['amount']);
        $method = sanitize_text_field($_POST['method']);
        
        $result = $wpdb->insert($wpdb->prefix . 'ptp_payouts', array(
            'trainer_id' => $trainer_id,
            'amount' => $amount,
            'method' => $method,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        ));
        
        if ($result) {
            wp_send_json_success(array('message' => 'Payout created', 'payout_id' => $wpdb->insert_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to create payout'));
        }
    }
    
    public static function mark_payout_complete() {
        self::verify_admin();
        global $wpdb;
        
        $payout_id = intval($_POST['payout_id']);
        $transaction_id = sanitize_text_field($_POST['transaction_id']);
        
        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_payouts WHERE id = %d",
            $payout_id
        ));
        
        if (!$payout) {
            wp_send_json_error(array('message' => 'Payout not found'));
            return;
        }
        
        $wpdb->update($wpdb->prefix . 'ptp_payouts', array(
            'status' => 'completed',
            'transaction_id' => $transaction_id,
            'completed_at' => current_time('mysql')
        ), array('id' => $payout_id));
        
        wp_send_json_success(array('message' => 'Payout marked complete'));
    }
    
    public static function get_parent_players() {
        self::verify_admin();
        global $wpdb;
        
        $parent_id = intval($_POST['parent_id']);
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND is_active = 1",
            $parent_id
        ));
        
        wp_send_json_success($players);
    }

    /**
     * v200: Get full parent detail (players + recent bookings) for modal
     */
    public static function get_parent_detail() {
        self::verify_admin();
        global $wpdb;

        $parent_id = intval($_POST['parent_id']);
        if (!$parent_id) wp_send_json_error(['message' => 'Invalid parent']);

        // Players
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, first_name, last_name, age, skill_level, position
             FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND is_active = 1
             ORDER BY name ASC",
            $parent_id
        ));

        // Recent bookings (last 20)
        $bookings_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT b.session_date, b.total_amount, b.status, b.location,
                    COALESCE(t.display_name, 'Unknown') as trainer_name
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.parent_id = %d
             ORDER BY b.session_date DESC LIMIT 20",
            $parent_id
        ));

        $bookings = [];
        foreach ($bookings_raw as $b) {
            $bookings[] = [
                'date'    => $b->session_date ? date('M j, Y', strtotime($b->session_date)) : '-',
                'trainer' => $b->trainer_name,
                'amount'  => number_format(floatval($b->total_amount), 0),
                'status'  => $b->status,
                'location'=> $b->location ?: '',
            ];
        }

        wp_send_json_success([
            'players'  => $players,
            'bookings' => $bookings,
        ]);
    }

    /**
     * v200: Send SMS to parent from admin CRM via OpenPhone
     */
    public static function admin_send_sms() {
        self::verify_admin();
        global $wpdb;

        $parent_id = intval($_POST['parent_id']);
        $message   = sanitize_textarea_field($_POST['message'] ?? '');

        if (!$parent_id || empty($message)) {
            wp_send_json_error(['message' => 'Parent ID and message required']);
        }

        // Get parent phone
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT p.phone, p.display_name FROM {$wpdb->prefix}ptp_parents p WHERE p.id = %d",
            $parent_id
        ));

        if (!$parent || empty($parent->phone)) {
            wp_send_json_error(['message' => 'Parent has no phone number']);
        }

        // Send via PTP_SMS_V71 (OpenPhone)
        if (class_exists('PTP_SMS_V71')) {
            PTP_SMS_V71::init();
            $result = PTP_SMS_V71::send($parent->phone, $message);
        } elseif (class_exists('PTP_SMS')) {
            $result = PTP_SMS::send($parent->phone, $message);
        } else {
            wp_send_json_error(['message' => 'SMS system not available']);
            return;
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            // Log the SMS
            if (function_exists('error_log')) {
                ptp_log('[PTP Admin SMS] Sent to ' . $parent->display_name . ' (' . $parent->phone . '): ' . substr($message, 0, 50) . '...');
            }
            wp_send_json_success(['message' => 'SMS sent to ' . $parent->display_name]);
        }
    }

    /**
     * v216: Send Stripe Express Dashboard login link to trainer via email
     * Generates a one-time login link for the trainer's Stripe Express dashboard
     */
    public static function send_stripe_dashboard_link() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_send_stripe_dash')) {
            wp_send_json_error('Security check failed');
        }

        global $wpdb;
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        if (!$trainer_id) {
            wp_send_json_error('Missing trainer ID');
        }

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, COALESCE(u.user_email, t.email) as user_email 
             FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID 
             WHERE t.id = %d",
            $trainer_id
        ));

        if (!$trainer) {
            wp_send_json_error('Trainer not found');
        }
        if (empty($trainer->stripe_account_id)) {
            wp_send_json_error('Trainer has no Stripe Connect account');
        }
        if (empty($trainer->user_email)) {
            wp_send_json_error('No email address found for trainer');
        }

        // Generate Stripe Express login link
        if (!class_exists('PTP_Stripe')) {
            wp_send_json_error('Stripe class not available');
        }

        $login_link = PTP_Stripe::create_login_link($trainer->stripe_account_id);

        if (is_wp_error($login_link)) {
            wp_send_json_error('Stripe API error: ' . $login_link->get_error_message());
        }
        if (empty($login_link['url'])) {
            wp_send_json_error('Could not generate Stripe dashboard link');
        }

        $dashboard_url = $login_link['url'];
        $first_name = explode(' ', $trainer->display_name)[0];

        // Send branded email
        $subject = 'Your PTP Stripe Dashboard';
        $body = "
<div style='font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:600px;margin:0 auto;'>
    <div style='background:#0A0A0A;padding:32px 24px;text-align:center;'>
        <h1 style='color:#FCB900;font-family:Oswald,sans-serif;font-size:28px;margin:0;letter-spacing:1px;'>PTP TRAINING</h1>
    </div>
    <div style='padding:32px 24px;background:#ffffff;'>
        <p style='font-size:16px;color:#111;margin:0 0 16px;'>Hey {$first_name},</p>
        <p style='font-size:15px;color:#374151;line-height:1.6;margin:0 0 24px;'>
            Here's your link to access your Stripe Express dashboard. You can view your payouts, update your bank info, and see your transaction history.
        </p>
        <div style='text-align:center;margin:32px 0;'>
            <a href='{$dashboard_url}' style='background:#FCB900;color:#0A0A0A;padding:16px 40px;font-size:16px;font-weight:700;text-decoration:none;border-radius:6px;display:inline-block;font-family:Oswald,sans-serif;text-transform:uppercase;letter-spacing:0.5px;'>Open Stripe Dashboard</a>
        </div>
        <p style='font-size:13px;color:#9CA3AF;line-height:1.5;margin:24px 0 0;'>
            This link expires shortly for security. If it expires, just ask Luke to send you a new one.
        </p>
    </div>
    <div style='background:#F9FAFB;padding:20px 24px;text-align:center;border-top:1px solid #E5E7EB;'>
        <p style='font-size:12px;color:#9CA3AF;margin:0;'>PTP Soccer &middot; Players Teaching Players</p>
    </div>
</div>";

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: PTP Training <noreply@ptpsummercamps.com>',
        );

        $sent = wp_mail($trainer->user_email, $subject, $body, $headers);

        if ($sent) {
            ptp_log('[PTP Admin v216] Stripe dashboard link sent to ' . $trainer->display_name . ' (' . $trainer->user_email . ')');
            wp_send_json_success('Link sent to ' . $trainer->user_email);
        } else {
            wp_send_json_error('Email failed to send. Check server mail config.');
        }
    }
    
    /* =========================================================================
       v135: RESET TRAINER PASSWORD
       ========================================================================= */
    public static function reset_trainer_password() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $send_email = !empty($_POST['send_email']);
        $custom_password = sanitize_text_field($_POST['custom_password'] ?? '');
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email, u.user_login FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d", $trainer_id
        ));
        if (!$trainer || !$trainer->user_id) {
            wp_send_json_error(array('message' => 'Trainer has no WordPress account'));
        }
        
        $password = $custom_password ?: wp_generate_password(12, true, false);
        wp_set_password($password, $trainer->user_id);
        
        $message = 'Password reset to: ' . $password;
        
        if ($send_email) {
            $login_url = home_url('/trainer-login/');
            $subject = 'Your PTP Login Credentials Have Been Reset';
            $body = '<div style="font-family:-apple-system,sans-serif;max-width:500px;margin:0 auto;padding:30px 0">';
            $body .= '<div style="background:#0A0A0A;padding:20px 30px;border-radius:12px 12px 0 0;text-align:center"><h1 style="color:#FCB900;margin:0;font-size:22px">PTP Training</h1></div>';
            $body .= '<div style="background:#fff;padding:30px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px">';
            $body .= '<p>Hey ' . esc_html($trainer->display_name) . ',</p>';
            $body .= '<p>Your login credentials have been reset:</p>';
            $body .= '<div style="background:#f9fafb;padding:16px 20px;border-radius:8px;border:1px solid #e5e7eb;margin:16px 0;font-family:monospace">';
            $body .= '<div style="margin-bottom:8px"><strong>Username:</strong> ' . esc_html($trainer->user_login) . '</div>';
            $body .= '<div><strong>Password:</strong> ' . esc_html($password) . '</div>';
            $body .= '</div>';
            $body .= '<p><a href="' . esc_url($login_url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700">Log In Now</a></p>';
            $body .= '<p style="font-size:13px;color:#6b7280">We recommend changing your password after logging in.</p>';
            $body .= '</div></div>';
            
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: PTP Training <noreply@ptpsummercamps.com>');
            $sent = wp_mail($trainer->user_email, $subject, $body, $headers);
            $message .= $sent ? ' | Email sent to ' . $trainer->user_email : ' | Email FAILED to send';
        }
        
        ptp_log('[PTP Admin v135] Password reset for trainer #' . $trainer_id . ' (' . $trainer->display_name . ')');
        wp_send_json_success(array('message' => $message, 'password' => $password));
    }
    
    /* =========================================================================
       v135: UPDATE TRAINER EMAIL / USERNAME
       ========================================================================= */
    public static function update_trainer_email() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $new_email = sanitize_email($_POST['new_email'] ?? '');
        
        if (!is_email($new_email)) {
            wp_send_json_error(array('message' => 'Invalid email address'));
        }
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
        if (!$trainer || !$trainer->user_id) {
            wp_send_json_error(array('message' => 'Trainer has no WordPress account'));
        }
        
        // Check email not already taken by another user
        $existing = get_user_by('email', $new_email);
        if ($existing && $existing->ID != $trainer->user_id) {
            wp_send_json_error(array('message' => 'Email already used by another account'));
        }
        
        // Update WP user
        $result = wp_update_user(array('ID' => $trainer->user_id, 'user_email' => $new_email));
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Update trainer table too
        $wpdb->update($wpdb->prefix . 'ptp_trainers', array('email' => $new_email), array('id' => $trainer_id));
        
        ptp_log('[PTP Admin v135] Email updated for trainer #' . $trainer_id . ' to ' . $new_email);
        wp_send_json_success(array('message' => 'Email updated to ' . $new_email));
    }
    
    /* =========================================================================
       v135: NUDGE TRAINER (multi-type notification system)
       ========================================================================= */
    public static function nudge_trainer() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $nudge_type = sanitize_text_field($_POST['nudge_type'] ?? '');
        $custom_message = sanitize_textarea_field($_POST['custom_message'] ?? '');
        $send_sms = !empty($_POST['send_sms']);
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, COALESCE(u.user_email, t.email) as user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d", $trainer_id
        ));
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        $onboarding_url = home_url('/trainer-onboarding/');
        $dashboard_url = home_url('/trainer-dashboard/');
        $login_url = home_url('/trainer-login/');
        
        $nudge_configs = array(
            'onboarding' => array(
                'subject' => 'Complete Your PTP Profile',
                'heading' => 'Finish Setting Up Your Profile',
                'body' => 'Your PTP account has been approved, but your profile isn\'t complete yet. Parents can\'t find you until your profile is set up.',
                'cta_text' => 'Complete Profile Now',
                'cta_url' => $onboarding_url,
                'sms' => 'Hey {name}! Your PTP profile isn\'t finished yet. Complete it so families can book you: {url}',
            ),
            'photo' => array(
                'subject' => 'Add a Profile Photo to PTP',
                'heading' => 'Profiles With Photos Get 60% More Bookings',
                'body' => 'You\'re missing a profile photo. Parents want to see who\'ll be training their kids. A quality headshot or action shot makes a huge difference.',
                'cta_text' => 'Upload Photo',
                'cta_url' => $dashboard_url,
                'sms' => 'Hey {name}! Add a photo to your PTP profile - it gets you way more bookings: {url}',
            ),
            'safesport' => array(
                'subject' => 'SafeSport Certification Required',
                'heading' => 'Complete Your SafeSport Training',
                'body' => 'SafeSport certification is required for all PTP trainers. It\'s free and takes about 90 minutes online. Once done, upload your certificate in your dashboard.',
                'cta_text' => 'Start SafeSport Training',
                'cta_url' => 'https://safesport.org',
                'sms' => 'Hey {name}! Please complete SafeSport certification for PTP. Free online: safesport.org - Then upload cert to your dashboard.',
            ),
            'w9' => array(
                'subject' => 'W-9 Required for PTP Payments',
                'heading' => 'Submit Your W-9 Form',
                'body' => 'We need your W-9 on file before we can process any payouts. Please submit it through your trainer dashboard.',
                'cta_text' => 'Submit W-9',
                'cta_url' => $dashboard_url,
                'sms' => 'Hey {name}! We need your W-9 before we can pay you. Submit it in your PTP dashboard: {url}',
            ),
            'background' => array(
                'subject' => 'Background Check Needed',
                'heading' => 'Complete Your Background Check',
                'body' => 'A background check is required to maintain your active status on PTP. Please complete it at your earliest convenience.',
                'cta_text' => 'Start Background Check',
                'cta_url' => $dashboard_url,
                'sms' => 'Hey {name}! Please complete your background check for PTP. Details in your dashboard: {url}',
            ),
            'stripe' => array(
                'subject' => 'Set Up Stripe to Get Paid',
                'heading' => 'Connect Stripe for Payments',
                'body' => 'You need to connect your Stripe account so you can receive session payments automatically. It only takes 5 minutes.',
                'cta_text' => 'Connect Stripe',
                'cta_url' => $dashboard_url,
                'sms' => 'Hey {name}! Set up Stripe in your PTP dashboard so you can get paid for sessions: {url}',
            ),
            'availability' => array(
                'subject' => 'Set Your Availability on PTP',
                'heading' => 'Let Families Know When You\'re Free',
                'body' => 'You haven\'t set your availability yet. Parents can\'t book you until they know your schedule. Set your available days and times in your dashboard.',
                'cta_text' => 'Set Availability',
                'cta_url' => $dashboard_url,
                'sms' => 'Hey {name}! Set your availability on PTP so parents can book you: {url}',
            ),
            'custom' => array(
                'subject' => 'Message from PTP Training',
                'heading' => 'A Note from PTP',
                'body' => $custom_message,
                'cta_text' => 'Go to Dashboard',
                'cta_url' => $dashboard_url,
                'sms' => $custom_message ?: 'Hey {name}! Check your PTP dashboard: {url}',
            ),
        );
        
        if (!isset($nudge_configs[$nudge_type])) {
            wp_send_json_error(array('message' => 'Unknown nudge type: ' . $nudge_type));
        }
        
        $cfg = $nudge_configs[$nudge_type];
        $results = array();
        
        // Send Email
        if ($trainer->user_email) {
            $body = '<div style="font-family:-apple-system,sans-serif;max-width:500px;margin:0 auto;padding:30px 0">';
            $body .= '<div style="background:#0A0A0A;padding:20px 30px;border-radius:12px 12px 0 0;text-align:center"><h1 style="color:#FCB900;margin:0;font-size:22px">PTP Training</h1></div>';
            $body .= '<div style="background:#fff;padding:30px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px">';
            $body .= '<h2 style="margin:0 0 16px;font-size:20px;color:#0A0A0A">' . esc_html($cfg['heading']) . '</h2>';
            $body .= '<p>Hey ' . esc_html($trainer->display_name) . ',</p>';
            $body .= '<p>' . nl2br(esc_html($cfg['body'])) . '</p>';
            $body .= '<p style="margin:24px 0"><a href="' . esc_url($cfg['cta_url']) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px">' . esc_html($cfg['cta_text']) . '</a></p>';
            $body .= '<p style="font-size:13px;color:#9ca3af">- Luke & the PTP Team</p>';
            $body .= '</div></div>';
            
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: PTP Training <noreply@ptpsummercamps.com>');
            $sent = wp_mail($trainer->user_email, $cfg['subject'], $body, $headers);
            $results[] = $sent ? 'Email sent' : 'Email failed';
        } else {
            $results[] = 'No email on file';
        }
        
        // Send SMS
        if ($send_sms && !empty($trainer->phone) && class_exists('PTP_SMS')) {
            $sms_text = str_replace(
                array('{name}', '{url}'),
                array($trainer->display_name, $cfg['cta_url']),
                $cfg['sms']
            );
            $sms_sent = PTP_SMS::send($trainer->phone, $sms_text);
            $results[] = $sms_sent ? 'SMS sent' : 'SMS failed';
        } elseif ($send_sms && empty($trainer->phone)) {
            $results[] = 'No phone on file';
        }
        
        // Log the nudge
        $wpdb->update($wpdb->prefix . 'ptp_trainers', 
            array('last_nudge_at' => current_time('mysql'), 'last_nudge_type' => $nudge_type),
            array('id' => $trainer_id)
        );
        
        ptp_log('[PTP Admin v135] Nudge sent to trainer #' . $trainer_id . ' (' . $trainer->display_name . ') type=' . $nudge_type . ' results=' . implode(', ', $results));
        wp_send_json_success(array('message' => implode(' | ', $results)));
    }
    
    /* =========================================================================
       v135: IMPERSONATE / LOGIN-AS TRAINER
       ========================================================================= */
    public static function impersonate_trainer() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
        if (!$trainer || !$trainer->user_id) {
            wp_send_json_error(array('message' => 'Trainer has no WordPress account'));
        }
        
        // Generate a time-limited magic token
        $token = wp_generate_password(32, false);
        $expiry = time() + 300; // 5 minutes
        set_transient('ptp_impersonate_' . $token, array(
            'trainer_user_id' => $trainer->user_id,
            'admin_user_id' => get_current_user_id(),
            'expiry' => $expiry,
        ), 300);
        
        $login_url = add_query_arg(array(
            'ptp_impersonate' => $token,
        ), home_url('/trainer-dashboard/'));
        
        ptp_log('[PTP Admin v135] Impersonate link generated for trainer #' . $trainer_id . ' by admin #' . get_current_user_id());
        wp_send_json_success(array('url' => $login_url, 'expires_in' => '5 minutes'));
    }
}

// Initialize on plugins_loaded to ensure WordPress is ready
add_action('plugins_loaded', array('PTP_Admin_Ajax', 'init'));
