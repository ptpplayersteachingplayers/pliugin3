<?php
/**
 * PTP Announcement AJAX Handler
 * 
 * Handles Instagram announcement submissions from thank-you page.
 * 
 * @version 170.0.0
 */

defined('ABSPATH') || exit;

class PTP_Announcement_Handler {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_ajax_ptp_submit_announcement', array($this, 'submit_announcement'));
        add_action('wp_ajax_nopriv_ptp_submit_announcement', array($this, 'submit_announcement'));
    }
    
    /**
     * Handle announcement submission
     */
    public function submit_announcement() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_announcement')) {
            wp_send_json_error('Invalid request');
            return;
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $ig_handle = sanitize_text_field($_POST['ig_handle'] ?? '');
        $camper_name = sanitize_text_field($_POST['camper_name'] ?? '');
        $camp_name = sanitize_text_field($_POST['camp_name'] ?? '');
        
        if (!$order_id) {
            wp_send_json_error('Missing order ID');
            return;
        }
        
        // Clean up Instagram handle
        $ig_handle = ltrim($ig_handle, '@');
        
        // Store the announcement request
        global $wpdb;
        
        // Check if announcements table exists, create if not
        $table = $wpdb->prefix . 'ptp_announcements';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                order_id bigint(20) NOT NULL,
                camper_name varchar(255) DEFAULT '',
                camp_name varchar(255) DEFAULT '',
                instagram_handle varchar(100) DEFAULT '',
                status varchar(20) DEFAULT 'pending',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                posted_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY order_id (order_id),
                KEY status (status)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        // Insert announcement
        $inserted = $wpdb->insert($table, array(
            'order_id' => $order_id,
            'camper_name' => $camper_name,
            'camp_name' => $camp_name,
            'instagram_handle' => $ig_handle,
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ), array('%d', '%s', '%s', '%s', '%s', '%s'));
        
        if (!$inserted) {
            wp_send_json_error('Failed to save announcement');
            return;
        }
        
        // Set transient so we don't show form again
        set_transient('ptp_announce_' . $order_id, true, 30 * DAY_IN_SECONDS);
        
        // Send notification to admin
        $this->notify_admin($order_id, $camper_name, $camp_name, $ig_handle);
        
        // Log it
        ptp_log("[PTP Announcement] New submission - Order #{$order_id}, Camper: {$camper_name}, IG: @{$ig_handle}");
        
        wp_send_json_success(array(
            'message' => 'Announcement submitted',
            'handle' => $ig_handle
        ));
    }
    
    /**
     * Send admin notification about new announcement request
     */
    private function notify_admin($order_id, $camper_name, $camp_name, $ig_handle) {
        $admin_email = get_option('admin_email');
        
        $subject = "[PTP] New Instagram Feature Request - {$camper_name}";
        
        $message = "New Instagram announcement request:\n\n";
        $message .= "Camper: {$camper_name}\n";
        $message .= "Camp: {$camp_name}\n";
        $message .= "Instagram: @{$ig_handle}\n";
        $message .= "Order ID: #{$order_id}\n\n";
        $message .= "View pending announcements in WordPress admin.";
        
        wp_mail($admin_email, $subject, $message);
    }
}

// Initialize
PTP_Announcement_Handler::instance();
