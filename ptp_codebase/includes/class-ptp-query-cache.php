<?php
/**
 * PTP Query Cache v227
 * 
 * 1. Table existence cache — eliminates ~213 SHOW TABLES queries per request
 * 2. Trainer data transients — caches expensive queries for 5-15 min
 * 3. Review aggregation cache — per-trainer review stats
 */
defined('ABSPATH') || exit;

class PTP_Query_Cache {

    /** @var array In-memory cache of table existence checks (per-request) */
    private static $table_cache = array();

    /** @var bool Whether tables have been bulk-loaded */
    private static $tables_loaded = false;

    /**
     * Initialize
     */
    public static function init() {
        // Bust trainer caches when trainer data changes
        add_action('ptp_trainer_updated', array(__CLASS__, 'bust_trainer_cache'), 10, 1);
        add_action('ptp_booking_created', array(__CLASS__, 'bust_trainer_cache'), 10, 1);
        add_action('ptp_review_created', array(__CLASS__, 'bust_trainer_cache'), 10, 1);
        add_action('ptp_trainer_saved', array(__CLASS__, 'bust_trainer_cache'), 10, 1);
    }

    // ─── TABLE EXISTENCE CACHE ──────────────────────────────────

    /**
     * Check if a table exists (cached per-request, bulk-loaded on first call)
     * 
     * Replaces: $wpdb->get_var("SHOW TABLES LIKE '{$table}'")
     * Usage:    PTP_Query_Cache::table_exists('ptp_trainers')
     *           PTP_Query_Cache::table_exists($wpdb->prefix . 'ptp_trainers')
     */
    public static function table_exists($table_name) {
        global $wpdb;

        // Normalize: strip prefix if provided, then add it back
        $prefix = $wpdb->prefix;
        if (strpos($table_name, $prefix) === 0) {
            $bare = substr($table_name, strlen($prefix));
        } else {
            $bare = $table_name;
        }
        $full = $prefix . $bare;

        // Check in-memory cache
        if (isset(self::$table_cache[$full])) {
            return self::$table_cache[$full];
        }

        // Bulk-load all tables on first check
        if (!self::$tables_loaded) {
            self::load_all_tables();
        }

        return self::$table_cache[$full] ?? false;
    }

    /**
     * Bulk-load all table names into cache (one query instead of 213)
     */
    private static function load_all_tables() {
        global $wpdb;
        self::$tables_loaded = true;

        $tables = $wpdb->get_col("SHOW TABLES");
        if (is_array($tables)) {
            foreach ($tables as $t) {
                self::$table_cache[$t] = true;
            }
        }
    }

    /**
     * Invalidate table cache (call after CREATE TABLE)
     */
    public static function flush_table_cache() {
        self::$table_cache = array();
        self::$tables_loaded = false;
    }

    // ─── TRAINER DATA CACHE ─────────────────────────────────────

    /**
     * Get active trainers for listing pages (cached 10 min)
     * 
     * @param string $order_by  SQL ORDER BY clause
     * @param int    $limit     Max results
     * @return array
     */
    public static function get_active_trainers($order_by = 'is_featured DESC, average_rating DESC, total_sessions DESC', $limit = 50) {
        $cache_key = 'ptp_trainers_' . md5($order_by . $limit);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_trainers';
        if (!self::table_exists($table)) return array();

        // Sanitize order_by (only allow known columns)
        $allowed_cols = array('is_featured', 'average_rating', 'total_sessions', 'created_at', 'display_name', 'hourly_rate');
        $order_parts = array_map('trim', explode(',', $order_by));
        $safe_order = array();
        foreach ($order_parts as $part) {
            foreach ($allowed_cols as $col) {
                if (strpos($part, $col) === 0) {
                    $safe_order[] = $part;
                    break;
                }
            }
        }
        $safe_order_str = !empty($safe_order) ? implode(', ', $safe_order) : 'is_featured DESC, average_rating DESC';

        $results = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'active' ORDER BY {$safe_order_str} LIMIT " . intval($limit)
        );

        set_transient($cache_key, $results ?: array(), 10 * MINUTE_IN_SECONDS);
        return $results ?: array();
    }

    /**
     * Get trainer count (cached 15 min)
     */
    public static function get_trainer_count() {
        $cached = get_transient('ptp_active_trainer_count');
        if ($cached !== false) return (int) $cached;

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_trainers';
        if (!self::table_exists($table)) return 0;

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
        set_transient('ptp_active_trainer_count', $count, 15 * MINUTE_IN_SECONDS);
        return $count;
    }

    /**
     * Get trainer by slug (cached 5 min)
     */
    public static function get_trainer_by_slug($slug) {
        $cache_key = 'ptp_trainer_' . sanitize_key($slug);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached === 'none' ? null : $cached;

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_trainers';
        if (!self::table_exists($table)) return null;

        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE slug = %s AND status = 'active'",
            $slug
        ));

        set_transient($cache_key, $trainer ?: 'none', 5 * MINUTE_IN_SECONDS);
        return $trainer;
    }

    /**
     * Get trainer reviews (cached 10 min)
     */
    public static function get_trainer_reviews($trainer_id, $limit = 20) {
        $cache_key = 'ptp_reviews_' . intval($trainer_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_reviews';
        if (!self::table_exists($table)) return array();

        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT r.rating, r.review_text, r.created_at, r.is_verified, r.trainer_response, r.trainer_responded_at,
                    p.first_name as reviewer_name
             FROM {$table} r
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON r.parent_id = p.user_id
             WHERE r.trainer_id = %d AND r.status = 'approved'
             ORDER BY r.created_at DESC
             LIMIT %d",
            $trainer_id, $limit
        ));

        set_transient($cache_key, $reviews ?: array(), 10 * MINUTE_IN_SECONDS);
        return $reviews ?: array();
    }

    /**
     * Get trainer availability (cached 2 min — changes more often)
     */
    public static function get_trainer_availability($trainer_id) {
        $cache_key = 'ptp_avail_' . intval($trainer_id);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        global $wpdb;
        $table = $wpdb->prefix . 'ptp_availability';
        if (!self::table_exists($table)) return array();

        $availability = $wpdb->get_results($wpdb->prepare(
            "SELECT day_of_week, start_time, end_time FROM {$table} WHERE trainer_id = %d AND is_active = 1",
            $trainer_id
        ));

        set_transient($cache_key, $availability ?: array(), 2 * MINUTE_IN_SECONDS);
        return $availability ?: array();
    }

    // ─── CACHE BUSTING ──────────────────────────────────────────

    /**
     * Bust trainer-related caches
     */
    public static function bust_trainer_cache($trainer_id = null) {
        // Global caches
        delete_transient('ptp_active_trainer_count');
        
        // Delete all trainer list caches (we don't know which order_by combos exist)
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ptp_trainers_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ptp_trainers_%'");
        
        // Per-trainer caches
        if ($trainer_id) {
            // Get trainer slug
            $slug = $wpdb->get_var($wpdb->prepare(
                "SELECT slug FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
            ));
            if ($slug) {
                delete_transient('ptp_trainer_' . sanitize_key($slug));
            }
            delete_transient('ptp_reviews_' . intval($trainer_id));
            delete_transient('ptp_avail_' . intval($trainer_id));
        }
    }

    /**
     * Bust all PTP caches
     */
    public static function flush_all() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ptp_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ptp_%'");
        self::flush_table_cache();
    }
}
