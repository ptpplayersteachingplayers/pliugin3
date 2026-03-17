<?php
/**
 * PTP Review AJAX Handlers – v216.2
 * 
 * Handles: trainer review replies, admin review moderation
 */
defined('ABSPATH') || exit;

class PTP_Review_Ajax {

    public static function init() {
        // Trainer actions
        add_action('wp_ajax_ptp_trainer_reply_review', array(__CLASS__, 'trainer_reply_review'));
        
        // Admin actions
        add_action('wp_ajax_ptp_admin_toggle_review', array(__CLASS__, 'admin_toggle_review'));
        add_action('wp_ajax_ptp_admin_delete_review', array(__CLASS__, 'admin_delete_review'));
        add_action('wp_ajax_ptp_admin_get_reviews',   array(__CLASS__, 'admin_get_reviews'));

        // Admin menu
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'), 25);
    }

    /**
     * Register admin submenu page
     */
    public static function register_admin_menu() {
        add_submenu_page(
            'ptp-dashboard',
            'Reviews',
            'Reviews',
            'manage_options',
            'ptp-reviews',
            array(__CLASS__, 'render_admin_page')
        );
    }

    /**
     * Render admin reviews page
     */
    public static function render_admin_page() {
        require_once PTP_PLUGIN_DIR . 'templates/admin/reviews.php';
    }

    /**
     * Trainer replies to a review
     */
    public static function trainer_reply_review() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error('Not logged in');

        $review_id = intval($_POST['review_id'] ?? 0);
        $reply = sanitize_textarea_field($_POST['reply'] ?? '');

        if (!$review_id || empty($reply)) {
            wp_send_json_error('Review ID and reply text are required');
        }

        if (strlen($reply) > 500) {
            wp_send_json_error('Reply must be under 500 characters');
        }

        // Get the trainer record for this user
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));

        if (!$trainer) wp_send_json_error('Trainer not found');

        // Verify this review belongs to this trainer
        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_reviews WHERE id = %d AND trainer_id = %d",
            $review_id, $trainer->id
        ));

        if (!$review) wp_send_json_error('Review not found or unauthorized');

        // Check if already replied (try both column names for compatibility)
        if (!empty($review->trainer_reply) || !empty($review->trainer_response)) {
            wp_send_json_error('You have already replied to this review');
        }

        // Update — try trainer_reply first (schema), fall back to trainer_response
        $cols_to_try = array(
            array('trainer_reply' => $reply, 'trainer_reply_at' => current_time('mysql')),
            array('trainer_response' => $reply, 'trainer_responded_at' => current_time('mysql')),
        );

        $updated = false;
        foreach ($cols_to_try as $cols) {
            $result = $wpdb->update(
                $wpdb->prefix . 'ptp_reviews',
                $cols,
                array('id' => $review_id)
            );
            if ($result !== false && empty($wpdb->last_error)) {
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            wp_send_json_error('Failed to save reply');
        }

        do_action('ptp_review_reply_submitted', $review_id, $trainer->id);

        wp_send_json_success(array('message' => 'Reply posted successfully'));
    }

    /**
     * Admin: Toggle review published status
     */
    public static function admin_toggle_review() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        global $wpdb;
        $review_id = intval($_POST['review_id'] ?? 0);
        if (!$review_id) wp_send_json_error('Invalid review ID');

        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_reviews WHERE id = %d", $review_id
        ));
        if (!$review) wp_send_json_error('Review not found');

        $new_status = $review->is_published ? 0 : 1;
        $wpdb->update(
            $wpdb->prefix . 'ptp_reviews',
            array('is_published' => $new_status),
            array('id' => $review_id)
        );

        // Update trainer stats
        if (class_exists('PTP_Trainer')) {
            PTP_Trainer::update_stats($review->trainer_id);
        }

        wp_send_json_success(array(
            'is_published' => $new_status,
            'message' => $new_status ? 'Review published' : 'Review hidden'
        ));
    }

    /**
     * Admin: Delete a review
     */
    public static function admin_delete_review() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        global $wpdb;
        $review_id = intval($_POST['review_id'] ?? 0);
        if (!$review_id) wp_send_json_error('Invalid review ID');

        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT trainer_id FROM {$wpdb->prefix}ptp_reviews WHERE id = %d", $review_id
        ));

        $wpdb->delete($wpdb->prefix . 'ptp_reviews', array('id' => $review_id));

        // Update trainer stats
        if ($review && class_exists('PTP_Trainer')) {
            PTP_Trainer::update_stats($review->trainer_id);
        }

        wp_send_json_success(array('message' => 'Review deleted'));
    }

    /**
     * Admin: Get paginated reviews (AJAX)
     */
    public static function admin_get_reviews() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        $filter = sanitize_text_field($_POST['filter'] ?? 'all');
        $search = sanitize_text_field($_POST['search'] ?? '');

        $where = "1=1";
        if ($filter === 'published') $where .= " AND r.is_published = 1";
        elseif ($filter === 'hidden') $where .= " AND r.is_published = 0";
        elseif ($filter === 'unreplied') $where .= " AND (r.trainer_reply IS NULL OR r.trainer_reply = '') AND (r.trainer_response IS NULL OR r.trainer_response = '')";
        elseif ($filter === 'low') $where .= " AND r.rating <= 3";
        elseif (is_numeric($filter)) $where .= $wpdb->prepare(" AND r.rating = %d", intval($filter));

        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(" AND (t.display_name LIKE %s OR u.display_name LIKE %s OR r.review_text LIKE %s)", $like, $like, $like);
        }

        $reviews = $wpdb->get_results(
            "SELECT r.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
                    COALESCE(u.display_name, 'Parent') as parent_name
             FROM {$wpdb->prefix}ptp_reviews r
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON r.trainer_id = t.id
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE {$where}
             ORDER BY r.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );

        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews r
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON r.trainer_id = t.id
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE {$where}"
        );

        wp_send_json_success(array(
            'reviews' => $reviews,
            'total' => intval($total),
            'pages' => ceil($total / $per_page),
            'page' => $page
        ));
    }
}
