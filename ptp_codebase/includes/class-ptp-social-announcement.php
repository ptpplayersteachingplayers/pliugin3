<?php
/**
 * PTP Social Announcement System v100
 * 
 * Handles Instagram announcement opt-ins from thank you page
 * Creates queue for VA/automation to post announcements
 * 
 * Features:
 * - Save opt-in preferences to order meta
 * - Queue announcements in database table
 * - Admin dashboard to view/manage queue
 * - Integration with thank you page
 * 
 * @since 100.0.0
 */

defined('ABSPATH') || exit;

class PTP_Social_Announcement {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Create tables
        add_action('init', array($this, 'maybe_create_tables'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_save_social_announcement', array($this, 'ajax_save_announcement'));
        add_action('wp_ajax_nopriv_ptp_save_social_announcement', array($this, 'ajax_save_announcement'));
        
        // Admin - priority 20 to run after main PTP admin menu (priority 10)
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('wp_ajax_ptp_update_announcement_status', array($this, 'ajax_update_status'));
    }
    
    /**
     * Create database tables
     */
    public function maybe_create_tables() {
        if (get_option('ptp_social_announcement_table_version') === '1.0') {
            return;
        }
        
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $table_name = $wpdb->prefix . 'ptp_social_announcements';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            instagram_handle varchar(100) NOT NULL,
            camper_name varchar(100) NOT NULL,
            camp_name varchar(200) DEFAULT NULL,
            camp_location varchar(200) DEFAULT NULL,
            camp_dates varchar(100) DEFAULT NULL,
            parent_email varchar(255) DEFAULT NULL,
            photo_url varchar(500) DEFAULT NULL,
            status enum('pending','posted','skipped') DEFAULT 'pending',
            post_content text DEFAULT NULL,
            posted_at datetime DEFAULT NULL,
            posted_url varchar(500) DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY booking_id (booking_id),
            KEY status (status),
            KEY instagram_handle (instagram_handle)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        update_option('ptp_social_announcement_table_version', '1.0');
    }
    
    /**
     * AJAX: Save announcement opt-in
     */
    public function ajax_save_announcement() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_thankyou_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $ig_handle = sanitize_text_field($_POST['ig_handle'] ?? '');
        $opt_in = ($_POST['opt_in'] ?? '0') === '1';
        $camper_name = sanitize_text_field($_POST['camper_name'] ?? '');
        $camp_name = sanitize_text_field($_POST['camp_name'] ?? '');
        
        if (!$order_id && !$booking_id) {
            wp_send_json_error(array('message' => 'Missing order or booking ID.'));
        }
        
        if (empty($ig_handle)) {
            wp_send_json_error(array('message' => 'Instagram handle is required.'));
        }
        
        // Clean up handle
        $ig_handle = ltrim($ig_handle, '@');
        $ig_handle = '@' . preg_replace('/[^a-zA-Z0-9._]/', '', $ig_handle);
        
        // Handle photo upload
        $photo_url = '';
        if (!empty($_FILES['camper_photo']) && $_FILES['camper_photo']['error'] === UPLOAD_ERR_OK) {
            $uploaded = $this->handle_photo_upload($_FILES['camper_photo'], $order_id ?: $booking_id);
            if (is_wp_error($uploaded)) {
                // Log but don't fail - photo is optional
                ptp_log('[PTP Announcement] Photo upload failed: ' . $uploaded->get_error_message());
            } else {
                $photo_url = $uploaded;
            }
        }
        
        global $wpdb;
        
        // Get additional info from order
        $parent_email = '';
        $camp_location = '';
        $camp_dates = '';
        
        if ($order_id && false) {
            $order = PTP_Native_Order_Manager::get_order($order_id);
            if ($order) {
                $parent_email = $order->get_billing_email();
                
                // Save to order meta
                $order->update_meta_data('_ptp_ig_handle', $ig_handle);
                $order->update_meta_data('_ptp_announce_optin', $opt_in ? 'yes' : 'no');
                $order->update_meta_data('_ptp_announce_submitted_at', current_time('mysql'));
                $order->save();
                
                // Try to get camp details from order items
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if ($product) {
                        $pid = $product->get_id();
                        $is_camp = get_post_meta($pid, '_ptp_is_camp', true) === 'yes';
                        if (!$is_camp) {
                            $name = strtolower($product->get_name());
                            $is_camp = strpos($name, 'camp') !== false;
                        }
                        
                        if ($is_camp) {
                            if (empty($camp_name)) {
                                $camp_name = $item->get_name();
                            }
                            $camp_location = get_post_meta($pid, '_ptp_location_name', true);
                            $start = get_post_meta($pid, '_ptp_start_date', true);
                            $end = get_post_meta($pid, '_ptp_end_date', true);
                            if ($start) {
                                $camp_dates = date('M j', strtotime($start));
                                if ($end) {
                                    $camp_dates .= ' - ' . date('j, Y', strtotime($end));
                                }
                            }
                            
                            // Get camper name from item meta if not provided
                            if (empty($camper_name)) {
                                $camper_name = $item->get_meta('Camper Name') ?: $item->get_meta('camper_name') ?: '';
                            }
                            break;
                        }
                    }
                }
                
                // Fallback camper name
                if (empty($camper_name)) {
                    $camper_name = $order->get_billing_first_name();
                }
            }
        }
        
        // Only save to queue if opted in
        if ($opt_in) {
            $table = $wpdb->prefix . 'ptp_social_announcements';
            
            // Check if already exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE (order_id = %d OR booking_id = %d) AND (order_id > 0 OR booking_id > 0)",
                $order_id, $booking_id
            ));
            
            if ($existing) {
                // Update existing
                $update_data = array(
                    'instagram_handle' => $ig_handle,
                    'camper_name' => strtoupper($camper_name),
                    'camp_name' => $camp_name,
                    'camp_location' => $camp_location,
                    'camp_dates' => $camp_dates,
                    'parent_email' => $parent_email,
                    'status' => 'pending',
                );
                $update_format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s');
                
                // Only update photo if new one provided
                if ($photo_url) {
                    $update_data['photo_url'] = $photo_url;
                    $update_format[] = '%s';
                }
                
                $wpdb->update(
                    $table,
                    $update_data,
                    array('id' => $existing),
                    $update_format,
                    array('%d')
                );
            } else {
                // Insert new
                $wpdb->insert(
                    $table,
                    array(
                        'order_id' => $order_id ?: null,
                        'booking_id' => $booking_id ?: null,
                        'instagram_handle' => $ig_handle,
                        'camper_name' => strtoupper($camper_name),
                        'camp_name' => $camp_name,
                        'camp_location' => $camp_location,
                        'camp_dates' => $camp_dates,
                        'parent_email' => $parent_email,
                        'photo_url' => $photo_url ?: null,
                        'status' => 'pending',
                        'created_at' => current_time('mysql'),
                    ),
                    array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
                );
            }
            
            // Trigger notification to admin/VA (optional)
            do_action('ptp_new_social_announcement', array(
                'order_id' => $order_id,
                'booking_id' => $booking_id,
                'ig_handle' => $ig_handle,
                'camper_name' => $camper_name,
                'camp_name' => $camp_name,
            ));
        }
        
        wp_send_json_success(array(
            'message' => 'Announcement saved!',
            'ig_handle' => $ig_handle,
            'photo_url' => $photo_url,
        ));
    }
    
    /**
     * Handle photo upload
     * 
     * @param array $file $_FILES array element
     * @param int $reference_id Order or booking ID for naming
     * @return string|WP_Error URL on success, WP_Error on failure
     */
    private function handle_photo_upload($file, $reference_id) {
        // Check file type - be lenient for mobile uploads
        $allowed_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif');
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif');
        
        // Check by extension if MIME type is wrong (common with mobile)
        $is_valid = in_array($file['type'], $allowed_types) || in_array($file_ext, $allowed_exts);
        if (!$is_valid) {
            return new WP_Error('invalid_type', 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.');
        }
        
        // Check file size (max 10MB - frontend compresses before upload)
        if ($file['size'] > 10 * 1024 * 1024) {
            return new WP_Error('file_too_large', 'File is too large. Maximum size is 10MB.');
        }
        
        // Include required files
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        if (!function_exists('media_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }
        
        // Set up upload overrides
        $upload_overrides = array(
            'test_form' => false,
            'unique_filename_callback' => function($dir, $name, $ext) use ($reference_id) {
                return 'ptp-announcement-' . $reference_id . '-' . time() . $ext;
            }
        );
        
        // Move uploaded file
        $movefile = wp_handle_upload($file, $upload_overrides);
        
        if ($movefile && !isset($movefile['error'])) {
            // Create attachment
            $attachment = array(
                'post_mime_type' => $movefile['type'],
                'post_title' => 'PTP Announcement Photo - ' . $reference_id,
                'post_content' => '',
                'post_status' => 'inherit'
            );
            
            $attach_id = wp_insert_attachment($attachment, $movefile['file']);
            
            if (!is_wp_error($attach_id)) {
                // Generate metadata
                $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
                wp_update_attachment_metadata($attach_id, $attach_data);
                
                return $movefile['url'];
            }
            
            return $movefile['url']; // Return URL even if attachment creation fails
        }
        
        return new WP_Error('upload_failed', $movefile['error'] ?? 'Upload failed.');
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'ptp-dashboard',
            'Social Announcements',
            '📸 Announcements',
            'manage_options',
            'ptp-announcements',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * AJAX: Update announcement status
     */
    public function ajax_update_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $id = intval($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        $posted_url = esc_url_raw($_POST['posted_url'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        if (!$id || !in_array($status, array('pending', 'posted', 'skipped'))) {
            wp_send_json_error(array('message' => 'Invalid data'));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_social_announcements';
        
        $data = array(
            'status' => $status,
            'notes' => $notes,
        );
        
        if ($status === 'posted') {
            $data['posted_at'] = current_time('mysql');
            if ($posted_url) {
                $data['posted_url'] = $posted_url;
            }
        }
        
        $wpdb->update($table, $data, array('id' => $id));
        
        wp_send_json_success(array('message' => 'Status updated'));
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_social_announcements';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        
        // Get stats
        $pending = $table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'") : 0;
        $posted = $table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'posted'") : 0;
        $total = $table_exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM $table") : 0;
        
        // Get announcements
        $announcements = array();
        if ($table_exists) {
            $announcements = $wpdb->get_results(
                "SELECT * FROM $table ORDER BY 
                 CASE status WHEN 'pending' THEN 0 WHEN 'posted' THEN 1 ELSE 2 END,
                 created_at DESC
                 LIMIT 50"
            );
        }
        
        ?>
        <div class="ptp-admin-wrap">
            <div class="ptp-admin-header">
                <div class="ptp-admin-header-content">
                    <div class="ptp-admin-logo">
                        <span class="dashicons dashicons-universal-access"></span>
                    </div>
                    <div class="ptp-admin-title-wrap">
                        <h1 class="ptp-admin-title">PTP <span>Training</span></h1>
                        <p class="ptp-admin-subtitle">Instagram announcement queue</p>
                    </div>
                </div>
                <div class="ptp-admin-header-actions">
                    <a href="https://instagram.com/ptp.training" class="ptp-admin-header-btn" target="_blank">
                        Open Instagram
                    </a>
                </div>
            </div>
            
            <?php 
            // Render navigation if PTP_Admin is available
            if (class_exists('PTP_Admin')) {
                PTP_Admin::instance()->render_nav('announcements');
            }
            ?>
            
            <div class="ptp-page-header">
                <h2 class="ptp-page-title">📸 Social Announcements</h2>
                <p class="ptp-page-subtitle">Parents opt-in to have their camper announced on Instagram. Copy, post, and tag!</p>
            </div>
            
            <!-- Stats -->
            <div class="ptp-stats-grid" style="margin-bottom:30px;">
                <div class="ptp-stat-card">
                    <div class="ptp-stat-value" style="color:#FCB900;"><?php echo $pending; ?></div>
                    <div class="ptp-stat-label">Pending</div>
                </div>
                <div class="ptp-stat-card">
                    <div class="ptp-stat-value" style="color:#22C55E;"><?php echo $posted; ?></div>
                    <div class="ptp-stat-label">Posted</div>
                </div>
                <div class="ptp-stat-card">
                    <div class="ptp-stat-value" style="color:#6B7280;"><?php echo $total; ?></div>
                    <div class="ptp-stat-label">Total Opt-ins</div>
                </div>
            </div>
            
            <!-- Post Template -->
            <div class="ptp-card" style="background:#0A0A0A;color:#fff;margin-bottom:30px;">
                <div class="ptp-card-body">
                    <h3 style="font-family:'Oswald',sans-serif;margin:0 0 12px;color:#FCB900;">📋 Post Template</h3>
                    <div style="background:#1a1a1a;padding:16px;border-radius:8px;font-family:monospace;font-size:13px;line-height:1.6;">
                        🔥 <strong>[CAMPER NAME]</strong> is locked in for [CAMP NAME]!<br><br>
                        Training with pro coaches this [DATES] in [LOCATION].<br><br>
                        Spots filling up → link in bio<br><br>
                        @[PARENT_HANDLE]
                    </div>
                </div>
            </div>
            
            <!-- Announcements Table -->
            <div class="ptp-card">
                <div class="ptp-card-header">
                    <h3 class="ptp-card-title">Announcement Queue</h3>
                </div>
                <div class="ptp-card-body" style="padding:0;">
                    <table class="ptp-table">
                        <thead>
                            <tr>
                                <th style="width:80px;">Status</th>
                                <th style="width:60px;">Photo</th>
                                <th>Camper</th>
                                <th>Camp</th>
                                <th>Instagram</th>
                                <th>Date</th>
                                <th style="width:220px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($announcements)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center;padding:60px 40px;color:#6B7280;">
                                    <span class="dashicons dashicons-instagram" style="font-size:48px;width:48px;height:48px;opacity:0.3;display:block;margin:0 auto 16px;"></span>
                                    <strong style="display:block;margin-bottom:8px;">No announcements yet</strong>
                                    They'll appear here when parents opt-in on the thank you page.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($announcements as $a): ?>
                            <tr id="announcement-<?php echo $a->id; ?>">
                                <td>
                                    <?php if ($a->status === 'pending'): ?>
                                        <span class="ptp-badge ptp-badge-warning">Pending</span>
                                    <?php elseif ($a->status === 'posted'): ?>
                                        <span class="ptp-badge ptp-badge-success">Posted</span>
                                    <?php else: ?>
                                        <span class="ptp-badge">Skipped</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($a->photo_url)): ?>
                                    <a href="<?php echo esc_url($a->photo_url); ?>" target="_blank" title="View full photo">
                                        <img src="<?php echo esc_url($a->photo_url); ?>" alt="Camper photo" style="width:50px;height:50px;object-fit:cover;border-radius:4px;border:2px solid #FCB900;">
                                    </a>
                                    <?php else: ?>
                                    <span style="color:#6B7280;font-size:11px;">No photo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="font-family:'Oswald',sans-serif;"><?php echo esc_html($a->camper_name); ?></strong>
                                </td>
                                <td>
                                    <?php echo esc_html($a->camp_name ?: '-'); ?>
                                    <?php if ($a->camp_location): ?>
                                    <br><small style="color:#6B7280;"><?php echo esc_html($a->camp_location); ?></small>
                                    <?php endif; ?>
                                    <?php if ($a->camp_dates): ?>
                                    <br><small style="color:#6B7280;"><?php echo esc_html($a->camp_dates); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="https://instagram.com/<?php echo esc_attr(ltrim($a->instagram_handle, '@')); ?>" target="_blank" style="color:#E1306C;font-weight:600;">
                                        <?php echo esc_html($a->instagram_handle); ?>
                                    </a>
                                    <?php if ($a->parent_email): ?>
                                    <br><small style="color:#6B7280;"><?php echo esc_html($a->parent_email); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($a->created_at)); ?>
                                    <?php if ($a->posted_at): ?>
                                    <br><small style="color:#22C55E;">Posted: <?php echo date('M j', strtotime($a->posted_at)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a->status === 'pending'): ?>
                                    <button onclick="copyPost(<?php echo $a->id; ?>, '<?php echo esc_js($a->camper_name); ?>', '<?php echo esc_js($a->camp_name); ?>', '<?php echo esc_js($a->camp_dates); ?>', '<?php echo esc_js($a->camp_location); ?>', '<?php echo esc_js($a->instagram_handle); ?>')" 
                                            class="ptp-btn ptp-btn-sm ptp-btn-primary">
                                        📋 Copy
                                    </button>
                                    <button onclick="markPosted(<?php echo $a->id; ?>)" 
                                            class="ptp-btn ptp-btn-sm ptp-btn-success">
                                        ✓ Posted
                                    </button>
                                    <button onclick="markSkipped(<?php echo $a->id; ?>)" 
                                            class="ptp-btn ptp-btn-sm ptp-btn-secondary">
                                        Skip
                                    </button>
                                    <?php elseif ($a->posted_url): ?>
                                    <a href="<?php echo esc_url($a->posted_url); ?>" target="_blank" class="ptp-btn ptp-btn-sm" style="color:#E1306C;">View Post →</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <script>
        function copyPost(id, camper, camp, dates, location, handle) {
            const text = `🔥 ${camper} is locked in for ${camp || 'PTP Soccer Camp'}!

Training with pro coaches${dates ? ' this ' + dates : ''}${location ? ' in ' + location : ''}.

Spots filling up → link in bio

${handle}`;
            
            navigator.clipboard.writeText(text).then(() => {
                alert('Post copied to clipboard! Paste it into Instagram.');
            });
        }
        
        function markPosted(id) {
            const url = prompt('Paste the Instagram post URL (optional):');
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ptp_update_announcement_status',
                    id: id,
                    status: 'posted',
                    posted_url: url || ''
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        function markSkipped(id) {
            if (!confirm('Skip this announcement?')) return;
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'ptp_update_announcement_status',
                    id: id,
                    status: 'skipped'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        </script>
        <?php
    }
    
    /**
     * Get pending count for admin menu badge
     */
    public static function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_social_announcements';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return 0;
        }
        
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
    }
}

// Initialize immediately when file is loaded
// (Loader handles timing via plugins_loaded)
if (class_exists('PTP_Social_Announcement')) {
    PTP_Social_Announcement::instance();
}
