<?php
/**
 * PTP Camp Lifecycle Cron — Fires camp_week_ended + midcamp_sms based on actual dates
 * 
 * THE MISSING LINK: Nobody was firing ptp_camp_week_ended or ptp_mentorship_midcamp_sms.
 * Pipeline, Touchpoints, and Notifications all had listeners but no trigger.
 * 
 * This class runs daily and:
 *   1. Finds camps that ended yesterday → fires ptp_camp_week_ended per trainer
 *   2. Finds camps on Day 3 → fires ptp_mentorship_midcamp_sms per parent
 *   3. Looks up trainers from ptp_camp_assignments (Coach Manager)
 *   4. Looks up parents from ptp_camp_bookings + ptp_unified_camp_orders
 * 
 * DATA FLOW:
 *   Camp post (_camp_end_date meta)
 *     → ptp_camp_assignments (camp_id → trainer_id)
 *       → ptp_camp_bookings (camp_id → customer_email, customer_phone, camper_name)
 *         → ptp_camp_week_ended hook → Pipeline → V2 Day 2/5/10 sequence
 * 
 * @since v138
 */
defined('ABSPATH') || exit;

class PTP_Camp_Lifecycle_Cron {

    const CRON_HOOK = 'ptp_camp_lifecycle_check';

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_daily_check'));

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Run at 8am ET daily (13:00 UTC)
            $next = strtotime('tomorrow 13:00:00 UTC');
            wp_schedule_event($next, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Daily check: find camps that just ended and camps at mid-point
     */
    public static function run_daily_check() {
        self::check_camps_ended();
        self::check_midcamp_day3();

        ptp_log('[PTP Camp Lifecycle] Daily check completed at ' . current_time('mysql'));
    }

    // ================================================================
    // 1. CAMPS THAT ENDED YESTERDAY → fire ptp_camp_week_ended
    // ================================================================
    private static function check_camps_ended() {
        // Find camp posts where _camp_end_date = yesterday
        // We check yesterday so emails go out the morning after camp ends
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $camp_ids = get_posts(array(
            'post_type'   => 'ptp_camp',
            'post_status' => 'publish',
            'fields'      => 'ids',
            'numberposts' => 50,
            'meta_query'  => array(
                array(
                    'key'     => '_camp_end_date',
                    'value'   => $yesterday,
                    'compare' => '=',
                    'type'    => 'DATE',
                ),
            ),
        ));

        // Fallback: also check 'camp' post type
        if (empty($camp_ids)) {
            $camp_ids = get_posts(array(
                'post_type'   => 'camp',
                'post_status' => 'publish',
                'fields'      => 'ids',
                'numberposts' => 50,
                'meta_query'  => array(
                    array(
                        'key'     => '_camp_end_date',
                        'value'   => $yesterday,
                        'compare' => '=',
                        'type'    => 'DATE',
                    ),
                ),
            ));
        }

        if (empty($camp_ids)) {
            ptp_log('[PTP Camp Lifecycle] No camps ended yesterday (' . $yesterday . ')');
            return;
        }

        foreach ($camp_ids as $camp_id) {
            // Dedup — don't fire twice for same camp
            $key = 'ptp_camp_ended_fired_' . $camp_id;
            if (get_transient($key)) continue;
            set_transient($key, 1, 30 * DAY_IN_SECONDS);

            $trainers = self::get_camp_trainers($camp_id);
            if (empty($trainers)) {
                ptp_log("[PTP Camp Lifecycle] Camp #{$camp_id} ended but has no assigned trainers");
                continue;
            }

            $camp_name = get_the_title($camp_id);
            $camp_location = get_post_meta($camp_id, '_camp_location', true);

            foreach ($trainers as $trainer) {
                $camp_data = array(
                    'trainer_id'    => $trainer->trainer_id,
                    'trainer_name'  => $trainer->trainer_name,
                    'camp_name'     => $camp_name,
                    'camp_location' => $camp_location,
                );

                /**
                 * Fire the hook that Pipeline has been listening for.
                 * Pipeline::trigger_post_camp_sequence() looks up parents from camp orders
                 * and fires ptp_mentorship_post_camp_outreach for each parent.
                 */
                do_action('ptp_camp_week_ended', $camp_id, $camp_data);

                ptp_log("[PTP Camp Lifecycle] Fired camp_week_ended for camp #{$camp_id} ({$camp_name}), trainer #{$trainer->trainer_id} ({$trainer->trainer_name})");
            }

            // Log to Command Center
            if (class_exists('CC_DB')) {
                CC_DB::log('camp_week_ended', 'camp', $camp_id,
                    "{$camp_name} ended. Trainers: " . implode(', ', array_column($trainers, 'trainer_name')),
                    'cron');
            }
        }
    }

    // ================================================================
    // 2. CAMPS ON DAY 3 → fire ptp_mentorship_midcamp_sms
    // ================================================================
    private static function check_midcamp_day3() {
        // Find camps that started 2 days ago (today = day 3 of 5-day camp)
        $start_target = date('Y-m-d', strtotime('-2 days'));

        $camp_ids = get_posts(array(
            'post_type'   => array('ptp_camp', 'camp'),
            'post_status' => 'publish',
            'fields'      => 'ids',
            'numberposts' => 50,
            'meta_query'  => array(
                array(
                    'key'     => '_camp_start_date',
                    'value'   => $start_target,
                    'compare' => '=',
                    'type'    => 'DATE',
                ),
            ),
        ));

        if (empty($camp_ids)) return;

        foreach ($camp_ids as $camp_id) {
            $key = 'ptp_midcamp_sms_fired_' . $camp_id;
            if (get_transient($key)) continue;
            set_transient($key, 1, 15 * DAY_IN_SECONDS);

            $trainers = self::get_camp_trainers($camp_id);
            if (empty($trainers)) continue;

            // Get parents who booked this camp
            $parents = self::get_camp_parents($camp_id);
            if (empty($parents)) continue;

            foreach ($parents as $parent) {
                if (empty($parent->phone)) continue;

                // Fire for each trainer assigned to the camp
                foreach ($trainers as $trainer) {
                    do_action('ptp_mentorship_midcamp_sms', array(
                        'phone'        => $parent->phone,
                        'parent_name'  => $parent->parent_name,
                        'player_name'  => $parent->camper_name,
                        'trainer_id'   => $trainer->trainer_id,
                        'trainer_name' => $trainer->trainer_name,
                        'camp_id'      => $camp_id,
                    ));
                }
            }

            ptp_log("[PTP Camp Lifecycle] Mid-camp SMS triggered for camp #{$camp_id}, " . count($parents) . " parents");
        }
    }

    // ================================================================
    // DATA LOOKUPS
    // ================================================================

    /**
     * Get trainers assigned to a camp via ptp_camp_assignments
     * Falls back to staff table if trainer_id is null but ptp_trainer_id exists on staff
     */
    private static function get_camp_trainers($camp_id) {
        global $wpdb;
        $assignments_table = $wpdb->prefix . 'ptp_camp_assignments';
        $trainers_table    = $wpdb->prefix . 'ptp_trainers';
        $staff_table       = $wpdb->prefix . 'ptp_camp_staff';

        // Check if assignments table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$assignments_table}'") !== $assignments_table) {
            ptp_log("[PTP Camp Lifecycle] ptp_camp_assignments table doesn't exist");
            return array();
        }

        // Direct trainer assignments
        $trainers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT ca.trainer_id, t.display_name AS trainer_name, t.mentorship_enabled
             FROM {$assignments_table} ca
             JOIN {$trainers_table} t ON ca.trainer_id = t.id
             WHERE ca.camp_id = %d
             AND ca.trainer_id IS NOT NULL
             AND ca.status NOT IN ('cancelled')
             AND t.mentorship_enabled = 1",
            $camp_id
        ));

        // Also check staff → trainer links
        if ($wpdb->get_var("SHOW TABLES LIKE '{$staff_table}'") === $staff_table) {
            $staff_trainers = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT s.ptp_trainer_id AS trainer_id, t.display_name AS trainer_name, t.mentorship_enabled
                 FROM {$assignments_table} ca
                 JOIN {$staff_table} s ON ca.staff_id = s.id
                 JOIN {$trainers_table} t ON s.ptp_trainer_id = t.id
                 WHERE ca.camp_id = %d
                 AND ca.trainer_id IS NULL
                 AND s.ptp_trainer_id IS NOT NULL
                 AND ca.status NOT IN ('cancelled')
                 AND t.mentorship_enabled = 1",
                $camp_id
            ));
            if ($staff_trainers) {
                $trainers = array_merge($trainers, $staff_trainers);
            }
        }

        // Deduplicate by trainer_id
        $seen = array();
        $unique = array();
        foreach ($trainers as $t) {
            if (!isset($seen[$t->trainer_id])) {
                $seen[$t->trainer_id] = true;
                $unique[] = $t;
            }
        }

        return $unique;
    }

    /**
     * Get parents who booked a specific camp
     * Checks ptp_camp_bookings (primary) and ptp_unified_camp_orders (fallback)
     */
    private static function get_camp_parents($camp_id) {
        global $wpdb;
        $parents = array();
        $seen_emails = array();

        // Source 1: ptp_camp_bookings (camps plugin)
        $bookings_table = $wpdb->prefix . 'ptp_camp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$bookings_table}'") === $bookings_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT customer_email AS email, customer_name AS parent_name, 
                        customer_phone AS phone, camper_name
                 FROM {$bookings_table}
                 WHERE camp_id = %d AND status IN ('confirmed','completed')
                 GROUP BY customer_email",
                $camp_id
            ));
            foreach ($rows as $r) {
                if (empty($r->email) || isset($seen_emails[$r->email])) continue;
                $seen_emails[$r->email] = true;
                $parents[] = $r;
            }
        }

        // Source 2: ptp_unified_camp_orders + order items (training platform)
        $orders_table = $wpdb->prefix . 'ptp_unified_camp_orders';
        $items_table  = $wpdb->prefix . 'ptp_camp_order_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$orders_table}'") === $orders_table
            && $wpdb->get_var("SHOW TABLES LIKE '{$items_table}'") === $items_table) {

            // Match by camp name since order items don't have camp_id
            $camp_name = get_the_title($camp_id);
            if ($camp_name) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT o.billing_email AS email,
                            CONCAT(o.billing_first_name, ' ', o.billing_last_name) AS parent_name,
                            o.billing_phone AS phone,
                            i.camper_first_name AS camper_name
                     FROM {$items_table} i
                     JOIN {$orders_table} o ON i.order_id = o.id
                     WHERE i.camp_name LIKE %s
                     AND o.payment_status IN ('completed','paid')
                     GROUP BY o.billing_email",
                    '%' . $wpdb->esc_like($camp_name) . '%'
                ));
                foreach ($rows as $r) {
                    if (empty($r->email) || isset($seen_emails[$r->email])) continue;
                    $seen_emails[$r->email] = true;
                    $parents[] = $r;
                }
            }
        }

        // Source 3: ptp_parents phone fallback (enrich missing phones)
        $parents_table = $wpdb->prefix . 'ptp_parents';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$parents_table}'") === $parents_table) {
            foreach ($parents as &$p) {
                if (!empty($p->phone)) continue;
                $phone = $wpdb->get_var($wpdb->prepare(
                    "SELECT phone FROM {$parents_table} WHERE email = %s AND phone != '' LIMIT 1",
                    $p->email
                ));
                if ($phone) $p->phone = $phone;
            }
            unset($p);
        }

        return $parents;
    }
}
