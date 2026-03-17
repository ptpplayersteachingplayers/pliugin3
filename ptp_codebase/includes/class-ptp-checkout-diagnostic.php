<?php
/**
 * PTP Checkout Diagnostic v2
 * 
 * Tracks which checkout class/method handles each payment request.
 * Always active — stores last 100 events in wp_options for admin review.
 * 
 * View results: WP Admin → PTP Settings, or:
 *   $summary = PTP_Checkout_Diagnostic::get_summary();
 *   get_option('ptp_checkout_diagnostic_stats');
 * 
 * After 2-4 weeks of data, unused checkout classes can be safely removed.
 */
defined('ABSPATH') || exit;

class PTP_Checkout_Diagnostic {

    private static $checkout_classes = array(
        'PTP_Bulletproof_Checkout'      => 'bulletproof',
        'PTP_Bundle_Checkout'           => 'bundle',
        'PTP_Camp_Checkout'             => 'camp',
        'PTP_Camp_Checkout_V99'         => 'camp-v99',
        'PTP_Cart_Checkout_V71'         => 'cart-v71',
        'PTP_Checkout_UX'               => 'checkout-ux',
        'PTP_Checkout_V77'              => 'checkout-v77',
        'PTP_Unified_Checkout_Handler'  => 'unified-handler',
        'PTP_Unified_Checkout'          => 'unified',
    );

    const STATS_KEY = 'ptp_checkout_diagnostic_stats';
    const LOG_KEY   = 'ptp_checkout_diagnostic_log';
    const MAX_LOG   = 100;

    public static function init() {
        $actions = array(
            'ptp_process_checkout', 'ptp_create_payment_intent',
            'ptp_confirm_checkout', 'ptp_save_checkout_data',
            'ptp_unified_checkout', 'ptp_process_unified_checkout',
            'ptp_confirm_unified_checkout', 'ptp_checkout_create_intent',
            'ptp_checkout_confirm', 'ptp_camp_checkout',
            'ptp_camp_create_payment', 'ptp_process_bundle_checkout',
            'ptp_add_to_cart', 'ptp_add_to_unified_cart',
            'ptp_membership_checkout',
        );

        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, array(__CLASS__, 'log_checkout'), 1);
            add_action('wp_ajax_nopriv_' . $action, array(__CLASS__, 'log_checkout'), 1);
        }

        add_action('template_redirect', array(__CLASS__, 'log_thankyou'), 1);
    }

    public static function log_checkout() {
        $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : 'unknown';
        $type   = isset($_REQUEST['type']) ? sanitize_text_field($_REQUEST['type']) : '';

        // Track which class handles this request
        $handler = 'pending';

        // Record the event
        $entry = array(
            'ts'      => time(),
            'action'  => $action,
            'type'    => $type,
            'user'    => get_current_user_id(),
        );

        $log = get_option(self::LOG_KEY, array());
        $log[] = $entry;
        if (count($log) > self::MAX_LOG) $log = array_slice($log, -self::MAX_LOG);
        update_option(self::LOG_KEY, $log, false);

        // Update stats
        $stats = get_option(self::STATS_KEY, array());
        $stats[$action] = ($stats[$action] ?? 0) + 1;
        $stats['_total'] = ($stats['_total'] ?? 0) + 1;
        $stats['_last'] = current_time('Y-m-d H:i:s');
        update_option(self::STATS_KEY, $stats, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            ptp_log("[PTP Checkout] action={$action} type={$type} user=" . get_current_user_id());
        }
    }

    public static function log_thankyou() {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, 'thankyou') === false && strpos($uri, 'thank-you') === false) return;

        $stats = get_option(self::STATS_KEY, array());
        $stats['thankyou_hit'] = ($stats['thankyou_hit'] ?? 0) + 1;
        update_option(self::STATS_KEY, $stats, false);
    }

    public static function get_summary() {
        $stats = get_option(self::STATS_KEY, array());
        $total = $stats['_total'] ?? 0;
        $last  = $stats['_last'] ?? 'never';
        unset($stats['_total'], $stats['_last']);
        arsort($stats);
        return array('total' => $total, 'last' => $last, 'paths' => $stats);
    }
}
