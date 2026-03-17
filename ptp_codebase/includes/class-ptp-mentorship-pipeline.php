<?php
/**
 * PTP Mentorship Pipeline — Camp→Mentorship funnel hooks & URL rewrites
 * 
 * Handles:
 *  - /mentorship/ URL rewrite and standalone page serving
 *  - Camp purchase → mentorship CTA injection
 *  - Post-camp week → outreach sequence trigger
 *  - Training session (2nd+) → mentorship suggestion
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Pipeline {

    private static $rewrite_version = '2.1';

    public static function init() {
        // Rewrite rules — serve /mentorship/ as standalone page
        add_action('init', array(__CLASS__, 'register_rewrites'), 10);
        add_action('init', array(__CLASS__, 'maybe_flush_rewrites'), 20);
        add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
        add_action('template_redirect', array(__CLASS__, 'serve_standalone_page'), 4);

        // Camp pipeline hooks
        add_action('ptp_camp_order_completed', array(__CLASS__, 'inject_camp_touchpoint'), 10, 2);
        add_action('ptp_camp_week_ended', array(__CLASS__, 'trigger_post_camp_sequence'), 10, 2);
        // FIXED: was ptp_training_session_completed (never fires)
        add_action('ptp_session_completed', array(__CLASS__, 'inject_training_touchpoint'), 10, 2);
    }

    // ================================================================
    // REWRITE RULES
    // ================================================================
    public static function register_rewrites() {
        add_rewrite_rule('^mentorship/?$', 'index.php?ptp_mentorship_page=1', 'top');
    }

    public static function maybe_flush_rewrites() {
        if (get_option('ptp_mentorship_rewrite_ver') !== self::$rewrite_version) {
            flush_rewrite_rules(false);
            update_option('ptp_mentorship_rewrite_ver', self::$rewrite_version, false);
        }
    }

    public static function add_query_vars($vars) {
        $vars[] = 'ptp_mentorship_page';
        return $vars;
    }

    public static function serve_standalone_page() {
        $is_mentorship = get_query_var('ptp_mentorship_page');
        if (!$is_mentorship) {
            $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
            $is_mentorship = ($path === 'mentorship');
        }
        if (!$is_mentorship) return;

        nocache_headers();

        // Logged-in parents with active mentorship → dashboard
        if (is_user_logged_in()) {
            global $wpdb;
            $has_pair = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE parent_id = %d AND status IN ('active','paused') LIMIT 1",
                get_current_user_id()
            ));
            if ($has_pair) {
                wp_redirect(home_url('/parent-dashboard/#mentorship'));
                exit;
            }
        }

        // Everyone else → landing page
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/mentorship-landing.php';
        $html = ob_get_clean();
        echo $html;
        exit;
    }

    // ================================================================
    // CAMP PIPELINE HOOKS
    // ================================================================

    /**
     * After camp purchase — inject mentorship CTA on thank-you page
     * Fires on: ptp_camp_order_completed
     */
    public static function inject_camp_touchpoint($order_id, $order_data) {
        $trainer_id = $order_data['trainer_id'] ?? 0;
        if (!$trainer_id) return;

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, mentorship_enabled FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
        if (!$trainer || empty($trainer->mentorship_enabled)) return;

        set_transient('ptp_mentorship_cta_' . $order_id, array(
            'trainer_id'   => $trainer->id,
            'trainer_name' => $trainer->display_name,
            'order_id'     => $order_id,
            'source'       => 'camp_upsell',
        ), HOUR_IN_SECONDS);
    }

    /**
     * After camp week ends — trigger post-camp email/SMS sequence
     * Fires on: ptp_camp_week_ended (now triggered by PTP_Camp_Lifecycle_Cron)
     */
    public static function trigger_post_camp_sequence($camp_id, $camp_data) {
        $trainer_id = $camp_data['trainer_id'] ?? 0;
        if (!$trainer_id) return;

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, mentorship_enabled FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
        if (!$trainer || empty($trainer->mentorship_enabled)) return;

        // Collect parents from all available camp order tables
        $parents = array();
        $seen = array();

        // Source 1: ptp_camp_bookings (camps plugin — most reliable, has camp_id FK)
        $bookings_table = $wpdb->prefix . 'ptp_camp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$bookings_table}'") === $bookings_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT cb.customer_email, cb.customer_name, cb.camper_name, cb.customer_phone
                 FROM {$bookings_table} cb
                 WHERE cb.camp_id = %d AND cb.status IN ('confirmed','completed')
                 AND cb.customer_email NOT IN (
                    SELECT parent_email FROM {$wpdb->prefix}ptp_mentorship_pairs 
                    WHERE trainer_id = %d AND status NOT IN ('cancelled')
                 )",
                $camp_id, $trainer->id
            ));
            foreach ($rows as $r) {
                if (empty($r->customer_email) || isset($seen[$r->customer_email])) continue;
                $seen[$r->customer_email] = true;
                $parents[] = (object) array(
                    'parent_email'     => $r->customer_email,
                    'parent_name'      => $r->customer_name,
                    'camper_first_name'=> explode(' ', trim($r->camper_name))[0],
                    'phone'            => $r->customer_phone,
                );
            }
        }

        // Source 2: ptp_unified_camp_orders + items (TP — match by camp name)
        $orders_table = $wpdb->prefix . 'ptp_unified_camp_orders';
        $items_table  = $wpdb->prefix . 'ptp_camp_order_items';
        $camp_name = get_the_title($camp_id);
        if ($camp_name
            && $wpdb->get_var("SHOW TABLES LIKE '{$orders_table}'") === $orders_table
            && $wpdb->get_var("SHOW TABLES LIKE '{$items_table}'") === $items_table) {

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT o.billing_email, 
                        CONCAT(o.billing_first_name, ' ', o.billing_last_name) AS parent_name,
                        i.camper_first_name, o.billing_phone
                 FROM {$items_table} i
                 JOIN {$orders_table} o ON i.order_id = o.id
                 WHERE i.camp_name LIKE %s
                 AND o.payment_status IN ('completed','paid')
                 AND o.billing_email NOT IN (
                    SELECT parent_email FROM {$wpdb->prefix}ptp_mentorship_pairs 
                    WHERE trainer_id = %d AND status NOT IN ('cancelled')
                 )",
                '%' . $wpdb->esc_like($camp_name) . '%', $trainer->id
            ));
            foreach ($rows as $r) {
                if (empty($r->billing_email) || isset($seen[$r->billing_email])) continue;
                $seen[$r->billing_email] = true;
                $parents[] = (object) array(
                    'parent_email'      => $r->billing_email,
                    'parent_name'       => $r->parent_name,
                    'camper_first_name' => $r->camper_first_name,
                    'phone'             => $r->billing_phone,
                );
            }
        }

        foreach ($parents as $parent) {
            do_action('ptp_mentorship_post_camp_outreach', array(
                'parent_email' => $parent->parent_email,
                'parent_name'  => $parent->parent_name,
                'player_name'  => $parent->camper_first_name,
                'trainer_id'   => $trainer->id,
                'trainer_name' => $trainer->display_name,
                'camp_id'      => $camp_id,
                'source'       => 'camp_followup',
            ));
        }

        ptp_log("[PTP Pipeline] Post-camp sequence triggered for camp #{$camp_id}: " . count($parents) . " parents, trainer #{$trainer->id}");
    }

    /**
     * After 1:1 training session — suggest mentorship if not enrolled
     * Fires on: ptp_session_completed
     */
    public static function inject_training_touchpoint($booking_id, $booking_data) {
        // Handle both object (from $wpdb->get_row) and array
        if (is_object($booking_data)) {
            $trainer_id = intval($booking_data->trainer_id ?? 0);
            $parent_id  = intval($booking_data->parent_id ?? 0);
        } else {
            $trainer_id = intval($booking_data['trainer_id'] ?? 0);
            $parent_id  = intval($booking_data['parent_id'] ?? 0);
        }
        if (!$trainer_id || !$parent_id) return;

        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, mentorship_enabled FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $trainer_id
        ));
        if (!$trainer || empty($trainer->mentorship_enabled)) return;

        // Already enrolled?
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs
             WHERE trainer_id = %d AND parent_id = %d AND status NOT IN ('cancelled','completed')",
            $trainer->id, $parent_id
        ));
        if ($existing > 0) return;

        // Pitch after 2nd session
        $session_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
             WHERE trainer_id = %d AND parent_id = %d AND status = 'completed'",
            $trainer->id, $parent_id
        ));
        if ($session_count < 2) return;

        do_action('ptp_mentorship_training_upsell', array(
            'parent_id'  => $parent_id,
            'trainer_id' => $trainer->id,
            'source'     => 'training_upsell',
            'sessions'   => $session_count,
        ));
    }
}
