<?php
/**
 * PTP Cross-Sell & Upsell Engine v1.0.0
 * 
 * Smart, contextual cross-selling between camps and 1-on-1 training
 * Mobile-first, conversion-optimized
 * 
 * Features:
 * - Intelligent product recommendations
 * - Bundle pricing with automatic discounts
 * - Cart abandonment recovery
 * - Post-purchase upsells
 * - Session package upgrades
 * - Trainer-specific camp promotions
 * 
 * @since 57.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTP_Crosssell_Engine {
    
    private static $instance = null;
    
    // Discount tiers
    const BUNDLE_DISCOUNT = 5;        // 5% off training + camp bundle
    const PACKAGE_3_DISCOUNT = 10;     // 10% off 3-session pack
    const PACKAGE_5_DISCOUNT = 15;     // 15% off 5-session pack
    const PACKAGE_10_DISCOUNT = 20;    // 20% off 10-session pack
    const SIBLING_DISCOUNT = 15;       // 15% off additional child
    const REFERRAL_DISCOUNT = 10;      // 10% off for referrals
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Core hooks
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));

        // Cross-sell injection points - Training checkout (WC-independent)
        add_action('ptp_after_booking_confirm', array($this, 'show_post_booking_upsells'), 10, 2);
        add_action('ptp_training_checkout_after_form', array($this, 'show_training_checkout_upsells'), 10, 2);

        // v148: PTP Native hooks only if WC active
        if (false) {
            // Cross-sell injection points - PTP Native checkout (camps)
            // REMOVED: add_action('ptp-native_thankyou', array($this, 'show_training_cta_after_camp'), 5);
            // REMOVED: add_action('ptp-native_after_cart', array($this, 'show_cart_crosssells'));
            // REMOVED: add_action('ptp-native_before_checkout_form', array($this, 'show_checkout_crosssells'), 5);
            // REMOVED: add_action('ptp-native_checkout_before_order_review', array($this, 'show_training_upsell_on_woo_checkout'), 10);

            // PTP Native cart modifications
            // REMOVED: add_action('ptp-native_cart_calculate_fees', array($this, 'apply_bundle_discounts'));
            // REMOVED: add_filter('ptp-native_cart_item_name', array($this, 'add_bundle_badge'), 10, 3);
        } else {
            // Native PTP hooks for checkout
            add_action('ptp_thankyou', array($this, 'show_training_cta_after_camp'), 5);
            add_action('ptp_after_cart', array($this, 'show_cart_crosssells'));
            add_action('ptp_before_checkout_form', array($this, 'show_checkout_crosssells'), 5);
        }

        // Trainer profile cross-sells (WC-independent)
        add_action('ptp_trainer_profile_after_booking', array($this, 'show_trainer_camps'));
        add_action('ptp_trainer_profile_sidebar', array($this, 'show_package_upgrades'));

        // AJAX handlers (WC-independent)
        add_action('wp_ajax_ptp_get_recommendations', array($this, 'ajax_get_recommendations'));
        add_action('wp_ajax_nopriv_ptp_get_recommendations', array($this, 'ajax_get_recommendations'));
        add_action('wp_ajax_ptp_apply_package_upgrade', array($this, 'ajax_apply_package_upgrade'));
        add_action('wp_ajax_nopriv_ptp_apply_package_upgrade', array($this, 'ajax_apply_package_upgrade'));
        add_action('wp_ajax_ptp_create_bundle', array($this, 'ajax_create_bundle'));
        add_action('wp_ajax_nopriv_ptp_create_bundle', array($this, 'ajax_create_bundle'));
        add_action('wp_ajax_ptp_track_crosssell_click', array($this, 'ajax_track_click'));
        add_action('wp_ajax_nopriv_ptp_track_crosssell_click', array($this, 'ajax_track_click'));

        // Shortcodes (WC-independent)
        add_shortcode('ptp_smart_crosssell', array($this, 'shortcode_smart_crosssell'));
        add_shortcode('ptp_package_builder', array($this, 'shortcode_package_builder'));
        add_shortcode('ptp_bundle_cta', array($this, 'shortcode_bundle_cta'));

        // Footer modals (WC-independent)
        add_action('wp_footer', array($this, 'render_crosssell_modal'));
        add_action('wp_footer', array($this, 'render_package_builder_modal'));
    }
    
    /**
     * Initialize
     */
    public function init() {
        // Create tracking table if needed
        $this->maybe_create_tables();
    }
    
    /**
     * Create database tables
     */
    private function maybe_create_tables() {
        if (get_option('ptp_crosssell_tables_version') === '1.0') {
            return;
        }
        
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_crosssell_clicks (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            session_id varchar(64) NOT NULL,
            source_type varchar(32) NOT NULL,
            source_id bigint(20) UNSIGNED DEFAULT NULL,
            target_type varchar(32) NOT NULL,
            target_id bigint(20) UNSIGNED DEFAULT NULL,
            context varchar(64) DEFAULT NULL,
            converted tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            converted_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY context (context)
        ) $charset;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_bundles (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            bundle_code varchar(32) NOT NULL,
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            camp_product_id bigint(20) UNSIGNED DEFAULT NULL,
            training_sessions int DEFAULT 1,
            discount_percent decimal(5,2) DEFAULT 0,
            discount_amount decimal(10,2) DEFAULT 0,
            status varchar(20) DEFAULT 'active',
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bundle_code (bundle_code),
            KEY user_id (user_id)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        update_option('ptp_crosssell_tables_version', '1.0');
    }
    
    /**
     * Enqueue assets
     */
    public function enqueue_assets() {
        if (!$this->should_load_assets()) {
            return;
        }
        
        wp_enqueue_style(
            'ptp-crosssell',
            PTP_PLUGIN_URL . 'assets/css/crosssell.css',
            array(),
            PTP_VERSION
        );
        
        wp_enqueue_script(
            'ptp-crosssell',
            PTP_PLUGIN_URL . 'assets/js/crosssell.js',
            array('jquery'),
            PTP_VERSION,
            true
        );
        
        wp_localize_script('ptp-crosssell', 'ptpCrosssell', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_crosssell'),
            'discounts' => array(
                'bundle' => self::BUNDLE_DISCOUNT,
                'package3' => self::PACKAGE_3_DISCOUNT,
                'package5' => self::PACKAGE_5_DISCOUNT,
                'package10' => self::PACKAGE_10_DISCOUNT,
            ),
            'i18n' => array(
                'addToCart' => __('Add to Cart', 'ptp-training'),
                'bundleSave' => __('Save %s%% with Bundle', 'ptp-training'),
                'limitedSpots' => __('Only %s spots left!', 'ptp-training'),
            ),
        ));
    }
    
    /**
     * Should load assets on this page?
     */
    private function should_load_assets() {
        // PTP pages where crosssell should load
        $ptp_pages = array(
            'trainer', 'book-session', 'book', 'my-training', 
            'training', 'camps', 'booking-confirmation'
        );
        
        // PTP Native pages (separate from PTP)
        $woo_pages = array(
            'checkout', 'cart', 'shop'
        );
        
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Check PTP pages
        foreach ($ptp_pages as $page) {
            if (stripos($uri, '/' . $page) !== false) {
                return true;
            }
        }
        
        // Check PTP Native pages
        foreach ($woo_pages as $page) {
            if (stripos($uri, '/' . $page) !== false) {
                return true;
            }
        }
        
        // PTP Native function checks
        if (function_exists('is_cart') && is_page('ptp-cart')) return true;
        if (is_page('ptp-checkout')) return true;
        
        return false;
    }
    
    /**
     * Get smart recommendations based on context
     */
    public function get_recommendations($context = array()) {
        $defaults = array(
            'user_id' => get_current_user_id(),
            'trainer_id' => null,
            'location' => null,
            'player_age' => null,
            'just_booked' => null, // 'training' or 'camp'
            'limit' => 4,
            'exclude' => array(),
        );
        $ctx = wp_parse_args($context, $defaults);
        
        $recommendations = array();
        
        // If just booked training, recommend camps
        if ($ctx['just_booked'] === 'training') {
            $recommendations = array_merge(
                $recommendations,
                $this->get_camp_recommendations($ctx)
            );
        }
        
        // If just booked camp, recommend training
        if ($ctx['just_booked'] === 'camp') {
            $recommendations = array_merge(
                $recommendations,
                $this->get_training_recommendations($ctx)
            );
        }
        
        // If on trainer profile, show their camps + packages
        if ($ctx['trainer_id']) {
            $recommendations = array_merge(
                $recommendations,
                $this->get_trainer_related_products($ctx['trainer_id']),
                $this->get_package_upgrades($ctx['trainer_id'])
            );
        }
        
        // Add personalized recommendations
        if ($ctx['user_id']) {
            $recommendations = array_merge(
                $recommendations,
                $this->get_personalized_recommendations($ctx['user_id'], $ctx)
            );
        }
        
        // Filter excluded and dedupe
        $seen = array();
        $filtered = array();
        foreach ($recommendations as $rec) {
            $key = $rec['type'] . '_' . $rec['id'];
            if (!in_array($key, $seen) && !in_array($rec['id'], $ctx['exclude'])) {
                $seen[] = $key;
                $filtered[] = $rec;
            }
        }
        
        return array_slice($filtered, 0, $ctx['limit']);
    }
    
    /**
     * Get camp recommendations
     */
    private function get_camp_recommendations($ctx) {
        // v223.1: Removed dead if(!false) guard
        
        $camps = array();
        
        // Get upcoming camps
        $products = ptp_get_camp_products(array(
            'status' => 'publish',
            'limit' => 10,
            'category' => array('camps', 'clinics', 'camp', 'clinic'),
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        foreach ($products as $product) {
            if (!$product->is_in_stock()) continue;
            
            $camps[] = array(
                'type' => 'camp',
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'formatted_price' => $product->get_price_html(),
                'image' => wp_get_attachment_url($product->get_image_id()),
                'url' => $product->get_permalink(),
                'stock' => $product->get_stock_quantity(),
                'badge' => $this->get_camp_badge($product),
                'discount_text' => sprintf(__('Save %d%% when bundled with training', 'ptp-training'), self::BUNDLE_DISCOUNT),
            );
        }
        
        return $camps;
    }
    
    /**
     * Get training recommendations after camp purchase
     */
    private function get_training_recommendations($ctx) {
        global $wpdb;
        
        $trainers = array();
        
        // Get featured trainers near location if available
        $query = "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'";
        $params = array();
        
        if (!empty($ctx['location'])) {
            $query .= " AND (location LIKE %s OR training_locations LIKE %s)";
            $pattern = '%' . $wpdb->esc_like($ctx['location']) . '%';
            $params[] = $pattern;
            $params[] = $pattern;
        }
        
        $query .= " ORDER BY is_featured DESC, average_rating DESC, total_sessions DESC LIMIT 6";
        
        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($query, $params));
        } else {
            $results = $wpdb->get_results($query);
        }
        
        foreach ($results as $trainer) {
            $trainers[] = array(
                'type' => 'trainer',
                'id' => $trainer->id,
                'name' => $trainer->display_name,
                'headline' => $trainer->headline,
                'college' => $trainer->college,
                'price' => floatval($trainer->hourly_rate),
                'formatted_price' => '$' . number_format($trainer->hourly_rate, 0) . '/hr',
                'image' => $trainer->photo_url,
                'url' => home_url('/trainer/' . $trainer->slug),
                'rating' => floatval($trainer->average_rating),
                'reviews' => intval($trainer->review_count),
                'discount_text' => sprintf(__('Save %d%% with 5-session package', 'ptp-training'), self::PACKAGE_5_DISCOUNT),
            );
        }
        
        return $trainers;
    }
    
    /**
     * Get products related to specific trainer
     */
    private function get_trainer_related_products($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return array();
        
        $products = array();
        
        // Look for camps in trainer's area
        if (false && !empty($trainer->location)) {
            $all_camps = ptp_get_camp_products(array(
                'status' => 'publish',
                'limit' => 20,
                'category' => array('camps', 'clinics'),
            ));
            
            foreach ($all_camps as $camp) {
                $camp_location = get_post_meta($camp->get_id(), '_event_location', true);
                $camp_title = strtolower($camp->get_name());
                $trainer_location = strtolower($trainer->location);
                
                // Check if camp is in trainer's area
                $location_match = (
                    stripos($camp_location, $trainer->location) !== false ||
                    stripos($camp_title, explode(',', $trainer_location)[0]) !== false
                );
                
                if ($location_match && $camp->is_in_stock()) {
                    $products[] = array(
                        'type' => 'camp',
                        'id' => $camp->get_id(),
                        'name' => $camp->get_name(),
                        'price' => $camp->get_price(),
                        'formatted_price' => $camp->get_price_html(),
                        'image' => wp_get_attachment_url($camp->get_image_id()),
                        'url' => $camp->get_permalink(),
                        'relation' => 'nearby',
                        'badge' => 'Near ' . $trainer->display_name,
                    );
                }
                
                if (count($products) >= 3) break;
            }
        }
        
        return $products;
    }
    
    /**
     * Get package upgrade options
     */
    private function get_package_upgrades($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return array();
        
        $rate = floatval($trainer->hourly_rate ?: 80);
        
        return array(
            array(
                'type' => 'package',
                'id' => 'pkg_3',
                'name' => '3-Session Package',
                'sessions' => 3,
                'original_price' => $rate * 3,
                'price' => round($rate * 3 * (1 - self::PACKAGE_3_DISCOUNT / 100), 2),
                'formatted_price' => '$' . number_format(round($rate * 3 * (1 - self::PACKAGE_3_DISCOUNT / 100)), 0),
                'discount' => self::PACKAGE_3_DISCOUNT,
                'savings' => round($rate * 3 * self::PACKAGE_3_DISCOUNT / 100, 2),
                'per_session' => round($rate * (1 - self::PACKAGE_3_DISCOUNT / 100), 2),
                'badge' => 'Save ' . self::PACKAGE_3_DISCOUNT . '%',
                'popular' => false,
            ),
            array(
                'type' => 'package',
                'id' => 'pkg_5',
                'name' => '5-Session Package',
                'sessions' => 5,
                'original_price' => $rate * 5,
                'price' => round($rate * 5 * (1 - self::PACKAGE_5_DISCOUNT / 100), 2),
                'formatted_price' => '$' . number_format(round($rate * 5 * (1 - self::PACKAGE_5_DISCOUNT / 100)), 0),
                'discount' => self::PACKAGE_5_DISCOUNT,
                'savings' => round($rate * 5 * self::PACKAGE_5_DISCOUNT / 100, 2),
                'per_session' => round($rate * (1 - self::PACKAGE_5_DISCOUNT / 100), 2),
                'badge' => 'Most Popular',
                'popular' => true,
            ),
            array(
                'type' => 'package',
                'id' => 'pkg_10',
                'name' => '10-Session Package',
                'sessions' => 10,
                'original_price' => $rate * 10,
                'price' => round($rate * 10 * (1 - self::PACKAGE_10_DISCOUNT / 100), 2),
                'formatted_price' => '$' . number_format(round($rate * 10 * (1 - self::PACKAGE_10_DISCOUNT / 100)), 0),
                'discount' => self::PACKAGE_10_DISCOUNT,
                'savings' => round($rate * 10 * self::PACKAGE_10_DISCOUNT / 100, 2),
                'per_session' => round($rate * (1 - self::PACKAGE_10_DISCOUNT / 100), 2),
                'badge' => 'Best Value',
                'popular' => false,
            ),
        );
    }
    
    /**
     * Get personalized recommendations based on user history
     */
    private function get_personalized_recommendations($user_id, $ctx) {
        global $wpdb;
        
        $recommendations = array();
        
        // Get user's past bookings
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if (!$parent) return array();
        
        // Get trainers they've booked with
        $past_trainers = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT trainer_id FROM {$wpdb->prefix}ptp_bookings 
             WHERE parent_id = %d AND status = 'completed'
             ORDER BY created_at DESC LIMIT 5",
            $parent->id
        ));
        
        // Recommend other trainers in similar locations
        if (!empty($past_trainers)) {
            $first_trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT location FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $past_trainers[0]
            ));
            
            if ($first_trainer && !empty($first_trainer->location)) {
                $location_parts = explode(',', $first_trainer->location);
                $area = trim($location_parts[0]);
                
                $similar_trainers = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_trainers 
                     WHERE status = 'active' 
                     AND id NOT IN (" . implode(',', array_map('intval', $past_trainers)) . ")
                     AND location LIKE %s
                     ORDER BY average_rating DESC LIMIT 3",
                    '%' . $wpdb->esc_like($area) . '%'
                ));
                
                foreach ($similar_trainers as $t) {
                    $recommendations[] = array(
                        'type' => 'trainer',
                        'id' => $t->id,
                        'name' => $t->display_name,
                        'headline' => $t->headline,
                        'price' => floatval($t->hourly_rate),
                        'formatted_price' => '$' . number_format($t->hourly_rate, 0) . '/hr',
                        'image' => $t->photo_url,
                        'url' => home_url('/trainer/' . $t->slug),
                        'rating' => floatval($t->average_rating),
                        'badge' => 'Recommended for You',
                    );
                }
            }
        }
        
        // Check for unfinished packages
        $incomplete_packages = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, t.display_name, t.slug 
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.parent_id = %d 
             AND b.session_type = 'package'
             AND b.sessions_remaining > 0
             AND b.status != 'cancelled'",
            $parent->id
        ));
        
        foreach ($incomplete_packages as $pkg) {
            $recommendations[] = array(
                'type' => 'continue_package',
                'id' => $pkg->id,
                'name' => 'Continue with ' . $pkg->display_name,
                'sessions_remaining' => $pkg->sessions_remaining,
                'url' => home_url('/book/' . $pkg->slug),
                'badge' => $pkg->sessions_remaining . ' sessions left',
                'priority' => 'high',
            );
        }
        
        return $recommendations;
    }
    
    /**
     * Get badge for camp
     */
    private function get_camp_badge($product) {
        $stock = $product->get_stock_quantity();
        
        if ($stock !== null && $stock <= 5 && $stock > 0) {
            return 'Only ' . $stock . ' spots!';
        }
        
        if ($product->is_on_sale()) {
            return 'Sale';
        }
        
        // Check if recently added (within 7 days)
        $created = get_the_date('U', $product->get_id());
        if ((time() - $created) < 7 * DAY_IN_SECONDS) {
            return 'New';
        }
        
        return '';
    }
    
    /**
     * AJAX: Get recommendations
     */
    public function ajax_get_recommendations() {
        check_ajax_referer('ptp_crosssell', 'nonce');
        
        $context = array(
            'trainer_id' => isset($_POST['trainer_id']) ? absint($_POST['trainer_id']) : null,
            'location' => isset($_POST['location']) ? sanitize_text_field($_POST['location']) : null,
            'just_booked' => isset($_POST['just_booked']) ? sanitize_text_field($_POST['just_booked']) : null,
            'limit' => isset($_POST['limit']) ? min(absint($_POST['limit']), 12) : 4,
        );
        
        $recommendations = $this->get_recommendations($context);
        
        wp_send_json_success(array(
            'recommendations' => $recommendations,
            'count' => count($recommendations),
        ));
    }
    
    /**
     * AJAX: Apply package upgrade
     */
    public function ajax_apply_package_upgrade() {
        check_ajax_referer('ptp_crosssell', 'nonce');
        
        $trainer_id = absint($_POST['trainer_id'] ?? 0);
        $package = sanitize_text_field($_POST['package'] ?? '');
        
        if (!$trainer_id || !in_array($package, array('pkg_3', 'pkg_5', 'pkg_10'))) {
            wp_send_json_error('Invalid parameters');
        }
        
        $sessions = array('pkg_3' => 3, 'pkg_5' => 5, 'pkg_10' => 10);
        $session_count = $sessions[$package];
        
        // Store in session for checkout
        ptp_session()->set('ptp_package_upgrade', array(
            'trainer_id' => $trainer_id,
            'sessions' => $session_count,
            'package' => $package,
        ));
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d-session package applied!', 'ptp-training'), $session_count),
            'redirect' => home_url('/book/' . $this->get_trainer_slug($trainer_id) . '?package=' . $session_count),
        ));
    }
    
    /**
     * AJAX: Create bundle (camp + training)
     */
    public function ajax_create_bundle() {
        check_ajax_referer('ptp_crosssell', 'nonce');
        
        global $wpdb;
        
        $trainer_id = absint($_POST['trainer_id'] ?? 0);
        $camp_id = absint($_POST['camp_id'] ?? 0);
        $sessions = absint($_POST['sessions'] ?? 1);
        
        if (!$trainer_id || !$camp_id) {
            wp_send_json_error('Invalid parameters');
        }
        
        // Generate unique bundle code
        $bundle_code = 'BND-' . strtoupper(substr(md5(uniqid(wp_rand(), true)), 0, 8));
        
        // Calculate discount
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT hourly_rate FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        $camp = ptp_get_camp_product($camp_id);
        if (!$trainer || !$camp) {
            wp_send_json_error('Products not found');
        }
        
        $training_total = floatval($trainer->hourly_rate) * $sessions;
        $camp_price = floatval($camp->get_price());
        $bundle_total = $training_total + $camp_price;
        $discount_amount = round($bundle_total * self::BUNDLE_DISCOUNT / 100, 2);
        
        // Save bundle
        $wpdb->insert(
            $wpdb->prefix . 'ptp_bundles',
            array(
                'user_id' => get_current_user_id() ?: null,
                'bundle_code' => $bundle_code,
                'trainer_id' => $trainer_id,
                'camp_product_id' => $camp_id,
                'training_sessions' => $sessions,
                'discount_percent' => self::BUNDLE_DISCOUNT,
                'discount_amount' => $discount_amount,
                'status' => 'active',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                'created_at' => current_time('mysql'),
            )
        );
        
        // Store in session
        ptp_session()->set('ptp_active_bundle', $bundle_code);
        
        // Add camp to cart
        ptp_cart()->add_item($camp_id);
        
        wp_send_json_success(array(
            'bundle_code' => $bundle_code,
            'discount' => self::BUNDLE_DISCOUNT,
            'savings' => $discount_amount,
            'message' => sprintf(__('Bundle created! Save $%s', 'ptp-training'), number_format($discount_amount, 2)),
            'redirect' => home_url('/book/' . $this->get_trainer_slug($trainer_id) . '?bundle=' . $bundle_code),
        ));
    }
    
    /**
     * AJAX: Track cross-sell click
     */
    public function ajax_track_click() {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_crosssell_clicks',
            array(
                'user_id' => get_current_user_id() ?: null,
                'session_id' => $this->get_session_id(),
                'source_type' => sanitize_text_field($_POST['source_type'] ?? ''),
                'source_id' => absint($_POST['source_id'] ?? 0),
                'target_type' => sanitize_text_field($_POST['target_type'] ?? ''),
                'target_id' => absint($_POST['target_id'] ?? 0),
                'context' => sanitize_text_field($_POST['context'] ?? ''),
            )
        );
        
        wp_send_json_success();
    }
    
    /**
     * Apply bundle discounts to PTP Native cart
     */
    public function apply_bundle_discounts($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        $bundle_code = ptp_session()->get('ptp_active_bundle');
        if (!$bundle_code) {
            return;
        }
        
        global $wpdb;
        $bundle = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bundles 
             WHERE bundle_code = %s AND status = 'active' AND expires_at > NOW()",
            $bundle_code
        ));
        
        if ($bundle && $bundle->discount_amount > 0) {
            $cart->add_fee(
                sprintf(__('Bundle Discount (%d%% off)', 'ptp-training'), $bundle->discount_percent),
                -$bundle->discount_amount,
                false
            );
        }
    }
    
    /**
     * Add bundle badge to cart item
     */
    public function add_bundle_badge($name, $cart_item, $cart_item_key) {
        $bundle_code = ptp_session()->get('ptp_active_bundle');
        if (!$bundle_code) {
            return $name;
        }
        
        global $wpdb;
        $bundle = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bundles WHERE bundle_code = %s",
            $bundle_code
        ));
        
        if ($bundle && $cart_item['product_id'] == $bundle->camp_product_id) {
            $name .= '<span class="ptp-bundle-badge" style="background:#FCB900;color:#0A0A0A;padding:2px 8px;border-radius:3px;font-size:11px;margin-left:8px;font-weight:700;">BUNDLE</span>';
        }
        
        return $name;
    }
    
    /**
     * Show post-booking upsells
     */
    public function show_post_booking_upsells($booking_id, $booking) {
        $recommendations = $this->get_recommendations(array(
            'trainer_id' => $booking->trainer_id,
            'just_booked' => 'training',
            'limit' => 3,
        ));
        
        if (empty($recommendations)) {
            return;
        }
        
        $this->render_recommendation_cards($recommendations, 'post_booking');
    }
    
    /**
     * Show training CTA after camp purchase
     */
    public function show_training_cta_after_camp($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return;
        
        $has_camp = false;
        foreach ($order->get_items() as $item) {
            $cats = wp_get_post_terms($item->get_product_id(), 'product_cat', array('fields' => 'slugs'));
            if (array_intersect($cats, array('camps', 'clinics', 'camp', 'clinic'))) {
                $has_camp = true;
                break;
            }
        }
        
        if (!$has_camp) return;
        
        $recommendations = $this->get_recommendations(array(
            'just_booked' => 'camp',
            'limit' => 4,
        ));
        
        if (empty($recommendations)) return;
        
        ?>
        <div class="ptp-thankyou-crosssell">
            <h3 style="font-family:'Oswald',sans-serif;font-size:20px;text-transform:uppercase;margin:24px 0 16px;">
                ⚽ Maximize Your Camp Experience
            </h3>
            <p style="color:#666;margin-bottom:16px;">Add 1-on-1 training to accelerate your progress</p>
            <?php $this->render_recommendation_cards($recommendations, 'thankyou'); ?>
        </div>
        <?php
    }
    
    /**
     * Show cart cross-sells
     */
    public function show_cart_crosssells() {
        $cart_has_camp = false;
        $cart_has_training = ptp_session()->get('ptp_pending_booking');
        
        foreach (ptp_cart()->get_items() as $item) {
            $cats = wp_get_post_terms($item['product_id'], 'product_cat', array('fields' => 'slugs'));
            if (array_intersect($cats, array('camps', 'clinics'))) {
                $cart_has_camp = true;
                break;
            }
        }
        
        if (!$cart_has_camp && !$cart_has_training) return;
        
        $recommendations = $this->get_recommendations(array(
            'just_booked' => $cart_has_camp ? 'camp' : 'training',
            'limit' => 3,
        ));
        
        if (empty($recommendations)) return;
        
        ?>
        <div class="ptp-cart-crosssell" style="margin-top:32px;">
            <h3 style="font-family:'Oswald',sans-serif;font-size:18px;text-transform:uppercase;margin-bottom:16px;">
                🔥 Complete Your Training Package
            </h3>
            <?php $this->render_recommendation_cards($recommendations, 'cart'); ?>
        </div>
        <?php
    }
    
    /**
     * Show checkout cross-sells
     */
    public function show_checkout_crosssells() {
        // Show bundle opportunity if applicable
        $pending_training = ptp_session()->get('ptp_pending_booking');
        $active_bundle = ptp_session()->get('ptp_active_bundle');
        
        if ($pending_training && !$active_bundle) {
            $camp_in_cart = false;
            foreach (ptp_cart()->get_items() as $item) {
                $cats = wp_get_post_terms($item['product_id'], 'product_cat', array('fields' => 'slugs'));
                if (array_intersect($cats, array('camps', 'clinics'))) {
                    $camp_in_cart = true;
                    break;
                }
            }
            
            if ($camp_in_cart) {
                ?>
                <div class="ptp-bundle-offer" style="background:#FEF3C7;border:2px solid #FCB900;border-radius:8px;padding:16px;margin-bottom:24px;">
                    <strong style="font-family:'Oswald',sans-serif;font-size:16px;">🎉 Bundle & Save <?php echo self::BUNDLE_DISCOUNT; ?>%!</strong>
                    <p style="margin:8px 0;font-size:14px;">You have both a camp and training in your cart. Bundle them to save!</p>
                    <button type="button" class="button" onclick="ptpCrosssell.createBundle()" 
                            style="background:#FCB900;color:#0A0A0A;border:none;font-weight:700;">
                        Apply Bundle Discount
                    </button>
                </div>
                <?php
            }
        }
    }
    
    /**
     * Show upsells on training checkout page
     */
    public function show_training_checkout_upsells($trainer_id, $trainer) {
        // Additional upsells for training checkout - referral + package upgrades
        if (!is_user_logged_in()) return;
        
        $user_id = get_current_user_id();
        
        // Show referral prompt
        if (class_exists('PTP_Viral_Engine')) {
            $viral = PTP_Viral_Engine::instance();
            $referral_code = $viral->get_user_referral_code($user_id);
            $referral_link = home_url('?ref=' . $referral_code);
            ?>
            <div style="max-width: 900px; margin: 0 auto 24px; padding: 0 16px;">
                <div style="background: #0A0A0A; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                    <div style="font-size: 24px;">💰</div>
                    <div style="flex: 1; min-width: 200px;">
                        <div style="font-family: 'Oswald', sans-serif; color: #FCB900; font-size: 14px; text-transform: uppercase;">Refer a Friend</div>
                        <div style="color: #9CA3AF; font-size: 13px;">Earn $25 for every friend who books</div>
                    </div>
                    <button onclick="navigator.clipboard.writeText('<?php echo esc_js($referral_link); ?>');this.textContent='Copied!';" 
                            style="background: #FCB900; color: #0A0A0A; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 13px;">
                        Copy Link
                    </button>
                </div>
            </div>
            <?php
        }
    }
    
    /**
     * Show training upsell on PTP Native checkout (when buying camps)
     */
    public function show_training_upsell_on_woo_checkout() {
        // Check if cart has camps
        $has_camp = false;
        if (!ptp_cart()) return;
        
        foreach (ptp_cart()->get_items() as $item) {
            $cats = wp_get_post_terms($item['product_id'], 'product_cat', array('fields' => 'slugs'));
            if (array_intersect($cats, array('camps', 'clinics', 'camp', 'clinic', 'summer-camps', 'winter-clinics'))) {
                $has_camp = true;
                break;
            }
        }
        
        if (!$has_camp) return;
        
        // Get featured trainers
        global $wpdb;
        $trainers = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ptp_trainers 
            WHERE status = 'active' 
            ORDER BY average_rating DESC, total_sessions DESC 
            LIMIT 3
        ");
        
        if (empty($trainers)) return;
        
        ?>
        <div style="background: linear-gradient(135deg, #EDE9FE 0%, #F3E8FF 100%); border: 2px solid #8B5CF6; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                <div style="width: 44px; height: 44px; background: #8B5CF6; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px;">🎯</div>
                <div>
                    <h3 style="font-family: 'Oswald', sans-serif; font-size: 16px; color: #0A0A0A; margin: 0; text-transform: uppercase;">Maximize Your Camp</h3>
                    <p style="font-size: 13px; color: #666; margin: 4px 0 0;">Add 1-on-1 training & save 5% on both!</p>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px;">
                <?php foreach ($trainers as $t): 
                    $photo = $t->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->display_name) . '&background=8B5CF6&color=fff';
                ?>
                <a href="<?php echo esc_url(home_url('/trainer/' . $t->slug . '/')); ?>" 
                   style="display: block; background: #fff; border-radius: 10px; padding: 12px; text-decoration: none; color: inherit; text-align: center; transition: transform 0.2s;">
                    <img src="<?php echo esc_url($photo); ?>" alt="" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover; margin-bottom: 8px;">
                    <div style="font-weight: 600; font-size: 13px; color: #0A0A0A; margin-bottom: 2px;"><?php echo esc_html($t->display_name); ?></div>
                    <div style="font-size: 12px; color: #666;">$<?php echo esc_html($t->hourly_rate ?: 80); ?>/hr • <?php echo esc_html(number_format($t->average_rating ?: 5, 1)); ?>⭐</div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <div style="text-align: center; margin-top: 16px;">
                <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" style="color: #8B5CF6; font-weight: 600; font-size: 13px; text-decoration: none;">
                    View All Trainers →
                </a>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show trainer's camps on profile
     */
    public function show_trainer_camps($trainer_id) {
        $products = $this->get_trainer_related_products($trainer_id);
        if (empty($products)) return;
        
        ?>
        <div class="ptp-trainer-camps" style="margin-top:32px;">
            <h3 style="font-family:'Oswald',sans-serif;font-size:18px;text-transform:uppercase;margin-bottom:16px;">
                📅 Upcoming Camps Nearby
            </h3>
            <?php $this->render_recommendation_cards($products, 'trainer_profile'); ?>
        </div>
        <?php
    }
    
    /**
     * Show package upgrades on profile
     */
    public function show_package_upgrades($trainer_id) {
        $packages = $this->get_package_upgrades($trainer_id);
        if (empty($packages)) return;
        
        ?>
        <div class="ptp-package-options" style="margin-top:24px;">
            <h4 style="font-family:'Oswald',sans-serif;font-size:14px;text-transform:uppercase;color:#666;margin-bottom:12px;">
                💰 Save with Packages
            </h4>
            <div class="ptp-packages-mini">
                <?php foreach ($packages as $pkg): ?>
                <button type="button" 
                        class="ptp-package-btn <?php echo $pkg['popular'] ? 'popular' : ''; ?>"
                        data-package="<?php echo esc_attr($pkg['id']); ?>"
                        data-trainer="<?php echo esc_attr($trainer_id); ?>"
                        onclick="ptpCrosssell.selectPackage('<?php echo esc_attr($pkg['id']); ?>', <?php echo esc_attr($trainer_id); ?>)"
                        style="display:block;width:100%;text-align:left;padding:12px;margin-bottom:8px;
                               background:<?php echo $pkg['popular'] ? '#FCB900' : '#f5f5f5'; ?>;
                               color:<?php echo $pkg['popular'] ? '#0A0A0A' : '#333'; ?>;
                               border:2px solid <?php echo $pkg['popular'] ? '#FCB900' : '#e5e5e5'; ?>;
                               border-radius:8px;cursor:pointer;transition:all 0.2s;">
                    <span style="font-weight:700;"><?php echo esc_html($pkg['sessions']); ?> Sessions</span>
                    <span style="float:right;font-weight:700;"><?php echo esc_html($pkg['formatted_price']); ?></span>
                    <small style="display:block;font-size:12px;color:<?php echo $pkg['popular'] ? '#333' : '#666'; ?>;">
                        $<?php echo number_format($pkg['per_session'], 0); ?>/session • Save $<?php echo number_format($pkg['savings'], 0); ?>
                    </small>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render recommendation cards
     */
    private function render_recommendation_cards($recommendations, $context = '') {
        ?>
        <div class="ptp-recommendations" data-context="<?php echo esc_attr($context); ?>" 
             style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
            <?php foreach ($recommendations as $rec): ?>
            <a href="<?php echo esc_url($rec['url']); ?>" 
               class="ptp-rec-card"
               data-type="<?php echo esc_attr($rec['type']); ?>"
               data-id="<?php echo esc_attr($rec['id']); ?>"
               onclick="ptpCrosssell.trackClick('<?php echo esc_attr($rec['type']); ?>', <?php echo esc_attr($rec['id']); ?>, '<?php echo esc_attr($context); ?>')"
               style="display:block;background:#fff;border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;
                      box-shadow:0 2px 8px rgba(0,0,0,0.1);transition:transform 0.2s,box-shadow 0.2s;">
                <?php if (!empty($rec['image'])): ?>
                <div style="height:120px;overflow:hidden;">
                    <img src="<?php echo esc_url($rec['image']); ?>" alt="" 
                         style="width:100%;height:100%;object-fit:cover;">
                </div>
                <?php endif; ?>
                <div style="padding:12px;">
                    <?php if (!empty($rec['badge'])): ?>
                    <span style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:2px 8px;border-radius:3px;
                                 font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:8px;">
                        <?php echo esc_html($rec['badge']); ?>
                    </span>
                    <?php endif; ?>
                    <h4 style="font-family:'Oswald',sans-serif;font-size:14px;margin:0 0 4px;line-height:1.3;">
                        <?php echo esc_html($rec['name']); ?>
                    </h4>
                    <?php if (!empty($rec['headline'])): ?>
                    <p style="font-size:12px;color:#666;margin:0 0 8px;line-height:1.3;">
                        <?php echo esc_html(wp_trim_words($rec['headline'], 10)); ?>
                    </p>
                    <?php endif; ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-weight:700;color:#0A0A0A;">
                            <?php echo $rec['formatted_price']; ?>
                        </span>
                        <?php if (!empty($rec['rating']) && $rec['rating'] > 0): ?>
                        <span style="font-size:12px;color:#666;">
                            ⭐ <?php echo number_format($rec['rating'], 1); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <style>
        .ptp-rec-card:hover { transform:translateY(-4px);box-shadow:0 8px 24px rgba(0,0,0,0.15); }
        @media (max-width:600px) {
            .ptp-recommendations { grid-template-columns:1fr 1fr; gap:12px; }
            .ptp-rec-card img { height:100px; }
        }
        </style>
        <?php
    }
    
    /**
     * Render cross-sell modal
     */
    public function render_crosssell_modal() {
        ?>
        <div id="ptp-crosssell-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:99999;
                                              align-items:center;justify-content:center;padding:20px;">
            <div style="background:#fff;border-radius:16px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;">
                <div style="padding:24px;">
                    <button onclick="document.getElementById('ptp-crosssell-modal').style.display='none'" 
                            style="float:right;background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>
                    <h3 id="crosssell-modal-title" style="font-family:'Oswald',sans-serif;font-size:22px;margin:0 0 8px;text-transform:uppercase;"></h3>
                    <p id="crosssell-modal-subtitle" style="color:#666;margin:0 0 20px;"></p>
                    <div id="crosssell-modal-content"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render package builder modal
     */
    public function render_package_builder_modal() {
        ?>
        <div id="ptp-package-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:99999;
                                            align-items:center;justify-content:center;padding:20px;">
            <div style="background:#fff;border-radius:16px;max-width:450px;width:100%;overflow:hidden;">
                <div style="background:#0A0A0A;color:#fff;padding:20px 24px;">
                    <button onclick="document.getElementById('ptp-package-modal').style.display='none'" 
                            style="float:right;background:none;border:none;font-size:24px;cursor:pointer;color:#fff;">&times;</button>
                    <h3 style="font-family:'Oswald',sans-serif;font-size:20px;margin:0;text-transform:uppercase;">
                        💰 Choose Your Package
                    </h3>
                </div>
                <div id="package-modal-content" style="padding:24px;">
                    <!-- Filled by JS -->
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Shortcode: Smart cross-sell
     */
    public function shortcode_smart_crosssell($atts) {
        $atts = shortcode_atts(array(
            'context' => '',
            'trainer_id' => '',
            'limit' => 4,
        ), $atts);
        
        $recommendations = $this->get_recommendations(array(
            'trainer_id' => absint($atts['trainer_id']) ?: null,
            'limit' => absint($atts['limit']),
        ));
        
        if (empty($recommendations)) {
            return '';
        }
        
        ob_start();
        $this->render_recommendation_cards($recommendations, $atts['context']);
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Package builder
     */
    public function shortcode_package_builder($atts) {
        $atts = shortcode_atts(array(
            'trainer_id' => '',
        ), $atts);
        
        $trainer_id = absint($atts['trainer_id']);
        if (!$trainer_id) return '';
        
        ob_start();
        $this->show_package_upgrades($trainer_id);
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Bundle CTA
     */
    public function shortcode_bundle_cta($atts) {
        $atts = shortcode_atts(array(
            'trainer_id' => '',
            'camp_id' => '',
        ), $atts);
        
        if (!$atts['trainer_id'] || !$atts['camp_id']) return '';
        
        ob_start();
        ?>
        <div class="ptp-bundle-cta" style="background:#FEF3C7;border:2px solid #FCB900;border-radius:12px;padding:20px;text-align:center;">
            <h3 style="font-family:'Oswald',sans-serif;margin:0 0 8px;font-size:18px;">
                🎉 BUNDLE & SAVE <?php echo self::BUNDLE_DISCOUNT; ?>%
            </h3>
            <p style="margin:0 0 16px;color:#666;">Combine training + camp for maximum improvement</p>
            <button onclick="ptpCrosssell.createBundle(<?php echo esc_attr($atts['trainer_id']); ?>, <?php echo esc_attr($atts['camp_id']); ?>)"
                    style="background:#FCB900;color:#0A0A0A;border:none;padding:12px 32px;border-radius:8px;
                           font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;cursor:pointer;">
                CREATE BUNDLE
            </button>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Helper: Get trainer slug by ID
     */
    private function get_trainer_slug($trainer_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
    }
    
    /**
     * Helper: Get session ID
     */
    private function get_session_id() {
        if (!isset($_COOKIE['ptp_session'])) {
            $session_id = wp_generate_uuid4();
            setcookie('ptp_session', $session_id, time() + 30 * DAY_IN_SECONDS, '/');
            return $session_id;
        }
        return sanitize_text_field($_COOKIE['ptp_session']);
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Crosssell_Engine::instance();
}, 15);
