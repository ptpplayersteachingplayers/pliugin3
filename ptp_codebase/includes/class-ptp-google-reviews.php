<?php
/**
 * PTP Google Reviews Integration v2.0.0
 * 
 * Maps platform training reviews to Google Reviews:
 * - After 4-5 star platform review, prompts user to leave Google review
 * - Fetches and displays Google Business reviews
 * - Tracks Google review requests and clicks
 * - Admin settings for Place ID configuration
 * 
 * @since 125.0.0
 */

defined('ABSPATH') || exit;

class PTP_Google_Reviews {
    
    const CACHE_KEY = 'ptp_google_reviews';
    const CACHE_DURATION = 86400; // 24 hours
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook into review creation to trigger Google review prompt
        add_action('ptp_review_created', array($this, 'maybe_prompt_google_review'), 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_ptp_track_google_review_click', array($this, 'track_google_click'));
        add_action('wp_ajax_ptp_dismiss_google_prompt', array($this, 'dismiss_google_prompt'));
        
        // Admin settings
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_page'));
        
        // Enqueue scripts for review prompt
        add_action('wp_footer', array($this, 'render_google_prompt_modal'));
    }
    
    /**
     * Get Google Review URL
     */
    public static function get_google_review_url() {
        $place_id = get_option('ptp_google_place_id');
        if (empty($place_id)) {
            return '';
        }
        return 'https://search.google.com/local/writereview?placeid=' . urlencode($place_id);
    }
    
    /**
     * Get Google Reviews page URL (for viewing)
     */
    public static function get_google_reviews_page_url() {
        $place_id = get_option('ptp_google_place_id');
        if (empty($place_id)) {
            return '';
        }
        return 'https://search.google.com/local/reviews?placeid=' . urlencode($place_id);
    }
    
    /**
     * Check if user should see Google review prompt
     */
    public function maybe_prompt_google_review($review_id, $rating) {
        // Only prompt for 4-5 star reviews
        if ($rating < 4) {
            return;
        }
        
        $parent_id = get_current_user_id();
        if (!$parent_id) {
            return;
        }
        
        // Check if they've already been prompted recently (last 30 days)
        $last_prompt = get_user_meta($parent_id, 'ptp_google_review_last_prompt', true);
        if ($last_prompt && (time() - $last_prompt) < 30 * DAY_IN_SECONDS) {
            return;
        }
        
        // Check if they've already clicked through to Google
        $clicked = get_user_meta($parent_id, 'ptp_google_review_clicked', true);
        if ($clicked) {
            return;
        }
        
        // Set flag to show prompt
        update_user_meta($parent_id, 'ptp_show_google_review_prompt', 1);
        update_user_meta($parent_id, 'ptp_google_review_last_prompt', time());
        
        // Log the prompt
        self::log_review_request($parent_id, $review_id);
    }
    
    /**
     * Log review request for tracking
     */
    private static function log_review_request($parent_id, $review_id) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_google_review_requests',
            array(
                'parent_id' => $parent_id,
                'platform_review_id' => $review_id,
                'requested_at' => current_time('mysql'),
                'status' => 'prompted',
            ),
            array('%d', '%d', '%s', '%s')
        );
    }
    
    /**
     * Track when user clicks through to Google
     */
    public function track_google_click() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $parent_id = get_current_user_id();
        if (!$parent_id) {
            wp_send_json_error('Not logged in');
        }
        
        // Mark as clicked
        update_user_meta($parent_id, 'ptp_google_review_clicked', time());
        delete_user_meta($parent_id, 'ptp_show_google_review_prompt');
        
        // Update log
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_google_review_requests',
            array(
                'status' => 'clicked',
                'clicked_at' => current_time('mysql'),
            ),
            array('parent_id' => $parent_id, 'status' => 'prompted'),
            array('%s', '%s'),
            array('%d', '%s')
        );
        
        wp_send_json_success(array(
            'url' => self::get_google_review_url()
        ));
    }
    
    /**
     * Dismiss Google prompt
     */
    public function dismiss_google_prompt() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $parent_id = get_current_user_id();
        if (!$parent_id) {
            wp_send_json_error('Not logged in');
        }
        
        delete_user_meta($parent_id, 'ptp_show_google_review_prompt');
        
        // Update log
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_google_review_requests',
            array('status' => 'dismissed'),
            array('parent_id' => $parent_id, 'status' => 'prompted'),
            array('%s'),
            array('%d', '%s')
        );
        
        wp_send_json_success();
    }
    
    /**
     * Render the Google review prompt modal
     */
    public function render_google_prompt_modal() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $parent_id = get_current_user_id();
        $show_prompt = get_user_meta($parent_id, 'ptp_show_google_review_prompt', true);
        
        if (!$show_prompt) {
            return;
        }
        
        $place_id = get_option('ptp_google_place_id');
        if (empty($place_id)) {
            return;
        }
        
        $review_url = self::get_google_review_url();
        ?>
        <div id="ptp-google-review-prompt" class="ptp-gr-modal">
            <div class="ptp-gr-modal-content">
                <button type="button" class="ptp-gr-modal-close" onclick="ptpDismissGooglePrompt()">&times;</button>
                
                <div class="ptp-gr-modal-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                </div>
                
                <h3 class="ptp-gr-modal-title">Thanks for your review!</h3>
                <p class="ptp-gr-modal-text">Would you mind sharing your experience on Google? It helps other families find us and means a lot to our coaches.</p>
                
                <div class="ptp-gr-modal-stars">
                    <svg viewBox="0 0 24 24" fill="#FCB900"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg viewBox="0 0 24 24" fill="#FCB900"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg viewBox="0 0 24 24" fill="#FCB900"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg viewBox="0 0 24 24" fill="#FCB900"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <svg viewBox="0 0 24 24" fill="#FCB900"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
                
                <div class="ptp-gr-modal-buttons">
                    <button type="button" class="ptp-gr-btn-primary" onclick="ptpGoToGoogleReview()">
                        Leave a Google Review
                    </button>
                    <button type="button" class="ptp-gr-btn-secondary" onclick="ptpDismissGooglePrompt()">
                        Maybe Later
                    </button>
                </div>
            </div>
        </div>
        
        <style>
        .ptp-gr-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            padding: 20px;
            animation: ptpFadeIn 0.3s ease;
        }
        @keyframes ptpFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .ptp-gr-modal-content {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            max-width: 400px;
            width: 100%;
            text-align: center;
            position: relative;
            animation: ptpSlideUp 0.3s ease;
        }
        @keyframes ptpSlideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .ptp-gr-modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: none;
            border: none;
            font-size: 24px;
            color: #9CA3AF;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .ptp-gr-modal-close:hover {
            background: #F3F4F6;
            color: #374151;
        }
        .ptp-gr-modal-icon {
            margin-bottom: 16px;
        }
        .ptp-gr-modal-title {
            font-family: 'Oswald', sans-serif;
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 12px;
            color: #111;
        }
        .ptp-gr-modal-text {
            font-size: 15px;
            color: #6B7280;
            margin: 0 0 20px;
            line-height: 1.5;
        }
        .ptp-gr-modal-stars {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-bottom: 24px;
        }
        .ptp-gr-modal-stars svg {
            width: 28px;
            height: 28px;
        }
        .ptp-gr-modal-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .ptp-gr-btn-primary {
            background: #FCB900;
            color: #000;
            border: none;
            padding: 14px 24px;
            font-family: 'Oswald', sans-serif;
            font-size: 16px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .ptp-gr-btn-primary:hover {
            background: #E5A800;
            transform: translateY(-1px);
        }
        .ptp-gr-btn-secondary {
            background: transparent;
            color: #6B7280;
            border: none;
            padding: 10px;
            font-size: 14px;
            cursor: pointer;
        }
        .ptp-gr-btn-secondary:hover {
            color: #374151;
        }
        </style>
        
        <script>
        function ptpGoToGoogleReview() {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=ptp_track_google_review_click&nonce=<?php echo wp_create_nonce('ptp_nonce'); ?>'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.open(data.data.url, '_blank');
                    document.getElementById('ptp-google-review-prompt').remove();
                }
            });
        }
        
        function ptpDismissGooglePrompt() {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=ptp_dismiss_google_prompt&nonce=<?php echo wp_create_nonce('ptp_nonce'); ?>'
            });
            document.getElementById('ptp-google-review-prompt').remove();
        }
        </script>
        <?php
    }
    
    /**
     * Fetch reviews from Google Places API
     */
    public static function fetch_reviews() {
        $place_id = get_option('ptp_google_place_id');
        $api_key = get_option('ptp_google_maps_api_key', '') ?: get_option('ptp_google_maps_key', '');
        
        if (empty($place_id) || empty($api_key)) {
            return array();
        }
        
        // Check cache first
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }
        
        $url = add_query_arg(array(
            'place_id' => $place_id,
            'fields' => 'reviews,rating,user_ratings_total',
            'key' => $api_key,
        ), 'https://maps.googleapis.com/maps/api/place/details/json');
        
        $response = wp_remote_get($url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($data['result']['reviews'])) {
            return array();
        }
        
        $reviews = array(
            'overall_rating' => $data['result']['rating'] ?? 0,
            'total_reviews' => $data['result']['user_ratings_total'] ?? 0,
            'reviews' => array_slice($data['result']['reviews'], 0, 5), // Top 5
        );
        
        // Cache the results
        set_transient(self::CACHE_KEY, $reviews, self::CACHE_DURATION);
        
        return $reviews;
    }
    
    /**
     * Render reviews widget
     */
    public static function render_widget($args = array()) {
        $defaults = array(
            'title' => 'What Parents Say',
            'show_google_badge' => true,
            'max_reviews' => 3,
            'min_rating' => 4,
        );
        $opts = array_merge($defaults, $args);
        
        $reviews_data = self::fetch_reviews();
        
        // If no Google reviews, show platform reviews
        if (empty($reviews_data['reviews'])) {
            self::render_platform_reviews($opts);
            return;
        }
        
        $reviews = $reviews_data['reviews'];
        $overall = $reviews_data['overall_rating'];
        $total = $reviews_data['total_reviews'];
        
        // Filter by min rating
        $reviews = array_filter($reviews, function($r) use ($opts) {
            return $r['rating'] >= $opts['min_rating'];
        });
        
        $reviews = array_slice($reviews, 0, $opts['max_reviews']);
        
        if (empty($reviews)) return;
        ?>
        <div class="ptp-google-reviews">
            <div class="ptp-gr-header">
                <h3 class="ptp-gr-title"><?php echo esc_html($opts['title']); ?></h3>
                <?php if ($opts['show_google_badge']): ?>
                <div class="ptp-gr-badge">
                    <svg width="16" height="16" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                    <span><?php echo number_format($overall, 1); ?> ★ (<?php echo $total; ?> reviews)</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="ptp-gr-list">
                <?php foreach ($reviews as $review): ?>
                <div class="ptp-gr-review">
                    <div class="ptp-gr-author">
                        <img src="<?php echo esc_url($review['profile_photo_url'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($review['author_name'])); ?>" alt="" class="ptp-gr-avatar">
                        <div class="ptp-gr-author-info">
                            <span class="ptp-gr-author-name"><?php echo esc_html($review['author_name']); ?></span>
                            <span class="ptp-gr-author-time"><?php echo esc_html($review['relative_time_description']); ?></span>
                        </div>
                        <div class="ptp-gr-rating">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="<?php echo $i < $review['rating'] ? '#FCB900' : '#E5E7EB'; ?>"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <p class="ptp-gr-text"><?php echo esc_html(wp_trim_words($review['text'], 30)); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php 
            $place_id = get_option('ptp_google_place_id');
            if ($place_id): 
            ?>
            <a href="https://search.google.com/local/reviews?placeid=<?php echo esc_attr($place_id); ?>" target="_blank" rel="noopener" class="ptp-gr-more">
                View all reviews on Google →
            </a>
            <?php endif; ?>
        </div>
        
        <style>
        .ptp-google-reviews {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            margin: 24px 0;
        }
        .ptp-gr-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .ptp-gr-title {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: #111;
        }
        .ptp-gr-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #6B7280;
        }
        .ptp-gr-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .ptp-gr-review {
            padding-bottom: 16px;
            border-bottom: 1px solid #F3F4F6;
        }
        .ptp-gr-review:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .ptp-gr-author {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .ptp-gr-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }
        .ptp-gr-author-info {
            flex: 1;
        }
        .ptp-gr-author-name {
            display: block;
            font-weight: 600;
            font-size: 14px;
            color: #111;
        }
        .ptp-gr-author-time {
            font-size: 12px;
            color: #9CA3AF;
        }
        .ptp-gr-rating {
            display: flex;
            gap: 2px;
        }
        .ptp-gr-text {
            margin: 0;
            font-size: 14px;
            color: #374151;
            line-height: 1.5;
        }
        .ptp-gr-more {
            display: inline-block;
            margin-top: 16px;
            font-size: 14px;
            font-weight: 600;
            color: #4285F4;
            text-decoration: none;
        }
        .ptp-gr-more:hover {
            text-decoration: underline;
        }
        </style>
        <?php
    }
    
    /**
     * Fallback: Show platform reviews
     */
    private static function render_platform_reviews($opts) {
        global $wpdb;
        
        $reviews = $wpdb->get_results($wpdb->prepare("
            SELECT r.*, p.display_name as parent_name, t.display_name as trainer_name
            FROM {$wpdb->prefix}ptp_reviews r
            JOIN {$wpdb->prefix}ptp_parents p ON r.parent_id = p.id
            JOIN {$wpdb->prefix}ptp_trainers t ON r.trainer_id = t.id
            WHERE r.is_published = 1 AND r.rating >= %d
            ORDER BY r.created_at DESC
            LIMIT %d
        ", $opts['min_rating'], $opts['max_reviews']));
        
        if (empty($reviews)) return;
        
        // Get overall stats
        $stats = $wpdb->get_row("
            SELECT AVG(rating) as avg_rating, COUNT(*) as total
            FROM {$wpdb->prefix}ptp_reviews
            WHERE is_published = 1
        ");
        ?>
        <div class="ptp-google-reviews">
            <div class="ptp-gr-header">
                <h3 class="ptp-gr-title"><?php echo esc_html($opts['title']); ?></h3>
                <div class="ptp-gr-badge">
                    <span><?php echo number_format($stats->avg_rating, 1); ?> ★ (<?php echo $stats->total; ?> reviews)</span>
                </div>
            </div>
            
            <div class="ptp-gr-list">
                <?php foreach ($reviews as $review): ?>
                <div class="ptp-gr-review">
                    <div class="ptp-gr-author">
                        <div class="ptp-gr-avatar" style="background:#F3F4F6;display:flex;align-items:center;justify-content:center;font-weight:600;color:#6B7280">
                            <?php echo strtoupper(substr($review->parent_name, 0, 1)); ?>
                        </div>
                        <div class="ptp-gr-author-info">
                            <span class="ptp-gr-author-name"><?php echo esc_html($review->parent_name); ?></span>
                            <span class="ptp-gr-author-time">trained with <?php echo esc_html($review->trainer_name); ?></span>
                        </div>
                        <div class="ptp-gr-rating">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="<?php echo $i < $review->rating ? '#FCB900' : '#E5E7EB'; ?>"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php if ($review->review): ?>
                    <p class="ptp-gr-text"><?php echo esc_html(wp_trim_words($review->review, 30)); ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ptp_google_reviews', 'ptp_google_place_id');
        register_setting('ptp_google_reviews', 'ptp_google_maps_api_key');
        register_setting('ptp_google_reviews', 'ptp_google_review_prompt_enabled', array(
            'default' => '1',
            'sanitize_callback' => 'absint',
        ));
    }
    
    /**
     * Add admin page
     */
    public function add_admin_page() {
        add_submenu_page(
            'ptp-settings',
            'Google Reviews',
            'Google Reviews',
            'manage_options',
            'ptp-google-reviews',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Get stats
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_google_review_requests';
        
        $stats = array(
            'total_prompted' => 0,
            'clicked' => 0,
            'dismissed' => 0,
        );
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table) {
            $stats = array(
                'total_prompted' => $wpdb->get_var("SELECT COUNT(*) FROM $table"),
                'clicked' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'clicked'"),
                'dismissed' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'dismissed'"),
            );
        }
        
        $click_rate = $stats['total_prompted'] > 0 
            ? round(($stats['clicked'] / $stats['total_prompted']) * 100, 1) 
            : 0;
        ?>
        <div class="wrap">
            <h1>Google Reviews Integration</h1>
            
            <div style="display: grid; grid-template-columns: 1fr 300px; gap: 24px; margin-top: 20px;">
                <div>
                    <div class="card" style="max-width: 600px; padding: 20px;">
                        <h2 style="margin-top: 0;">Settings</h2>
                        <form method="post" action="options.php">
                            <?php settings_fields('ptp_google_reviews'); ?>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Google Place ID</th>
                                    <td>
                                        <input type="text" name="ptp_google_place_id" 
                                               value="<?php echo esc_attr(get_option('ptp_google_place_id')); ?>" 
                                               class="regular-text" placeholder="ChIJ...">
                                        <p class="description">
                                            Find your Place ID at <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank">Google's Place ID Finder</a>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Google Maps API Key</th>
                                    <td>
                                        <input type="text" name="ptp_google_maps_api_key" 
                                               value="<?php echo esc_attr(get_option('ptp_google_maps_api_key')); ?>" 
                                               class="regular-text" placeholder="AIza...">
                                        <p class="description">Required to fetch and display Google reviews on your site</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Auto-Prompt for Reviews</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="ptp_google_review_prompt_enabled" value="1" 
                                                   <?php checked(get_option('ptp_google_review_prompt_enabled', '1'), '1'); ?>>
                                            Show Google review prompt after 4-5 star platform reviews
                                        </label>
                                        <p class="description">Parents who leave positive reviews will see a popup asking them to also review on Google</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <?php submit_button('Save Settings'); ?>
                        </form>
                    </div>
                    
                    <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                        <h2 style="margin-top: 0;">Your Google Review Link</h2>
                        <?php 
                        $review_url = self::get_google_review_url();
                        if ($review_url): 
                        ?>
                        <p>Share this link to get more Google reviews:</p>
                        <input type="text" value="<?php echo esc_url($review_url); ?>" class="large-text" readonly onclick="this.select();" style="font-family: monospace;">
                        <p style="margin-top: 10px;">
                            <a href="<?php echo esc_url($review_url); ?>" target="_blank" class="button">Test Link</a>
                        </p>
                        <?php else: ?>
                        <p style="color: #dc2626;">Please enter your Google Place ID above to generate your review link.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                        <h2 style="margin-top: 0;">How It Works</h2>
                        <ol>
                            <li>Parent completes a training session</li>
                            <li>Parent leaves a 4-5 star review on PTP</li>
                            <li>Popup appears asking them to also leave a Google review</li>
                            <li>Click tracked and they're taken to Google to write review</li>
                        </ol>
                        <p><strong>Note:</strong> Google prohibits "review gating" (only asking happy customers for reviews). This system asks ALL customers who leave positive platform reviews - we're not filtering based on sentiment before they write anything.</p>
                    </div>
                </div>
                
                <div>
                    <div class="card" style="padding: 20px;">
                        <h3 style="margin-top: 0;">Review Request Stats</h3>
                        <table class="widefat" style="margin-top: 12px;">
                            <tr>
                                <td>Total Prompted</td>
                                <td style="text-align: right; font-weight: 600;"><?php echo number_format($stats['total_prompted']); ?></td>
                            </tr>
                            <tr>
                                <td>Clicked to Google</td>
                                <td style="text-align: right; font-weight: 600; color: #059669;"><?php echo number_format($stats['clicked']); ?></td>
                            </tr>
                            <tr>
                                <td>Dismissed</td>
                                <td style="text-align: right; font-weight: 600; color: #9CA3AF;"><?php echo number_format($stats['dismissed']); ?></td>
                            </tr>
                            <tr style="background: #F9FAFB;">
                                <td><strong>Click Rate</strong></td>
                                <td style="text-align: right; font-weight: 700; color: #FCB900;"><?php echo $click_rate; ?>%</td>
                            </tr>
                        </table>
                    </div>
                    
                    <?php 
                    $google_data = self::fetch_reviews();
                    if (!empty($google_data['overall_rating'])): 
                    ?>
                    <div class="card" style="padding: 20px; margin-top: 20px;">
                        <h3 style="margin-top: 0;">Current Google Rating</h3>
                        <div style="text-align: center; padding: 20px 0;">
                            <div style="font-size: 48px; font-weight: 700; color: #FCB900;">
                                <?php echo number_format($google_data['overall_rating'], 1); ?>
                            </div>
                            <div style="color: #6B7280; margin-top: 4px;">
                                <?php echo number_format($google_data['total_reviews']); ?> reviews
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Create tracking table on plugin activation
     */
    public static function create_tables() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_google_review_requests';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) unsigned NOT NULL,
            platform_review_id bigint(20) unsigned DEFAULT NULL,
            requested_at datetime NOT NULL,
            clicked_at datetime DEFAULT NULL,
            status enum('prompted','clicked','dismissed') DEFAULT 'prompted',
            PRIMARY KEY (id),
            KEY parent_id (parent_id),
            KEY status (status)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_Google_Reviews', 'instance'));

// Create tables on activation
register_activation_hook(PTP_PLUGIN_FILE, array('PTP_Google_Reviews', 'create_tables'));

/**
 * Shortcode for reviews
 */
add_shortcode('ptp_reviews', function($atts) {
    $atts = shortcode_atts(array(
        'title' => 'What Parents Say',
        'max' => 3,
        'min_rating' => 4,
    ), $atts);

    ob_start();
    PTP_Google_Reviews::render_widget(array(
        'title' => $atts['title'],
        'max_reviews' => intval($atts['max']),
        'min_rating' => intval($atts['min_rating']),
    ));
    return ob_get_clean();
});
