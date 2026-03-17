<?php
/**
 * PTP Packages v181 — Single Source of Truth
 *
 * All package tiers, group multipliers, and pricing logic live here.
 * Every other file (trainer profile, cart, checkout, dashboard) references this class.
 *
 * Packages:
 *   single  — 1 session, 0% off
 *   pack3   — 3 sessions, 10% off
 *   pack5   — 5 sessions, 15% off
 *   pack10  — 10 sessions, 20% off
 *
 * Group sizes: 1-5 players per session
 *   1 player  = 1.0x base rate
 *   2 players = 1.6x
 *   3 players = 2.0x
 *   4 players = 2.4x
 *   5 players = 2.8x
 *
 * @since 181.0.0
 */

defined('ABSPATH') || exit;

class PTP_Packages {

    /**
     * Package definitions — the ONE place these are defined
     */
    const PACKAGES = array(
        'single' => array(
            'name'     => '1 Session',
            'sessions' => 1,
            'discount' => 0,
            'slug'     => 'single',
        ),
        'pack3' => array(
            'name'     => '3-Pack',
            'sessions' => 3,
            'discount' => 10,
            'slug'     => 'pack3',
        ),
        'pack5' => array(
            'name'     => '5-Pack',
            'sessions' => 5,
            'discount' => 15,
            'slug'     => 'pack5',
        ),
        'pack10' => array(
            'name'     => '10-Pack',
            'sessions' => 10,
            'discount' => 20,
            'slug'     => 'pack10',
        ),
    );

    /**
     * Group size multipliers
     * Each additional player adds 0.4x to base rate (diminishing per-player cost)
     */
    const GROUP_MULTIPLIERS = array(
        1 => 1.0,
        2 => 1.6,
        3 => 2.0,
        4 => 2.4,
        5 => 2.8,
    );

    /**
     * Legacy key mapping — old keys → canonical keys
     */
    const LEGACY_MAP = array(
        '5pack'      => 'pack5',
        '10pack'     => 'pack10',
        'package_5'  => 'pack5',
        'package_10' => 'pack10',
    );

    /**
     * Resolve a package key (handles legacy keys)
     */
    public static function resolve_key($key) {
        $key = strtolower(trim($key));
        if (isset(self::LEGACY_MAP[$key])) {
            return self::LEGACY_MAP[$key];
        }
        return isset(self::PACKAGES[$key]) ? $key : 'single';
    }

    /**
     * Get a package definition
     */
    public static function get($key) {
        $key = self::resolve_key($key);
        return self::PACKAGES[$key] ?? self::PACKAGES['single'];
    }

    /**
     * Get all packages (for rendering UI)
     */
    public static function all() {
        return self::PACKAGES;
    }

    /**
     * Get group multiplier for a given group size
     */
    public static function group_multiplier($group_size) {
        $group_size = max(1, min(5, intval($group_size)));
        if (isset(self::GROUP_MULTIPLIERS[$group_size])) {
            return self::GROUP_MULTIPLIERS[$group_size];
        }
        // Extrapolate: 1 + (n-1) * 0.4
        return 1 + ($group_size - 1) * 0.4;
    }

    /**
     * Calculate total price for a package + group size
     *
     * @param float $hourly_rate  Trainer's base hourly rate
     * @param string $package_key Package key (single, pack3, pack5, pack10)
     * @param int $group_size     Number of players (1-5)
     * @return array              Pricing breakdown
     */
    public static function calculate_price($hourly_rate, $package_key = 'single', $group_size = 1) {
        $pkg       = self::get($package_key);
        $mult      = self::group_multiplier($group_size);
        $sessions  = $pkg['sessions'];
        $discount  = $pkg['discount'];

        $base_per_session = round($hourly_rate * $mult, 2);
        $base_total       = round($base_per_session * $sessions, 2);
        $discount_amount  = round($base_total * ($discount / 100), 2);
        $total            = round($base_total - $discount_amount, 2);
        $per_session      = $sessions > 0 ? round($total / $sessions, 2) : $total;

        return array(
            'package'          => $pkg,
            'package_key'      => self::resolve_key($package_key),
            'hourly_rate'      => $hourly_rate,
            'group_size'       => $group_size,
            'group_multiplier' => $mult,
            'sessions'         => $sessions,
            'discount_pct'     => $discount,
            'base_per_session' => $base_per_session,
            'base_total'       => $base_total,
            'discount_amount'  => $discount_amount,
            'total'            => $total,
            'per_session'      => $per_session,
            'per_player_per_session' => $group_size > 0 ? round($per_session / $group_size, 2) : $per_session,
            'save'             => $discount_amount,
            'name'             => $pkg['name'],
        );
    }

    /**
     * Build package options array for a given trainer rate (for rendering UI)
     *
     * @param float $rate       Trainer hourly rate
     * @param int $group_size   Group size
     * @return array            Keyed array of package options with pricing
     */
    public static function build_options($rate, $group_size = 1) {
        $options = array();
        foreach (self::PACKAGES as $key => $pkg) {
            $pricing = self::calculate_price($rate, $key, $group_size);
            $options[$key] = array(
                'name'     => $pkg['name'],
                'count'    => $pkg['sessions'],
                'price'    => intval($pricing['total']),
                'per'      => intval($pricing['per_session']),
                'save'     => intval($pricing['save']),
                'discount' => $pkg['discount'],
            );
        }
        return $options;
    }

    /**
     * Create ptp_package_credits table if not exists
     * Called on init, creates the table the parent dashboard reads from
     *
     * v182: Added any_trainer column — when 1, credits can be used with any active trainer
     */
    public static function maybe_create_table() {
        global $wpdb;

        $current_ver = '182'; // bump when schema changes
        if (get_option('ptp_package_credits_table_ver') === $current_ver) {
            return;
        }

        $table   = $wpdb->prefix . 'ptp_package_credits';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED DEFAULT NULL,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            package_key varchar(20) NOT NULL DEFAULT 'single',
            total int NOT NULL DEFAULT 1,
            remaining int NOT NULL DEFAULT 1,
            group_size int NOT NULL DEFAULT 1,
            price_paid decimal(10,2) DEFAULT 0,
            payment_intent_id varchar(255) DEFAULT '',
            any_trainer tinyint(1) NOT NULL DEFAULT 1,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY parent_id (parent_id),
            KEY trainer_id (trainer_id),
            KEY status_remaining (status, remaining)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Migration: add any_trainer column to existing tables
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!in_array('any_trainer', $cols)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN any_trainer tinyint(1) NOT NULL DEFAULT 1 AFTER payment_intent_id");
            // Backfill: mark all existing multi-session credits as any_trainer
            $wpdb->query("UPDATE {$table} SET any_trainer = 1 WHERE total > 1");
        }

        update_option('ptp_package_credits_table_ver', $current_ver);
    }

    /**
     * Create a package credit record after successful payment
     *
     * @param int $parent_id      Parent record ID
     * @param int $trainer_id     Trainer record ID (original purchase trainer)
     * @param int $booking_id     Booking record ID
     * @param string $package_key Package key
     * @param int $group_size     Group size
     * @param float $price_paid   Amount charged
     * @param string $pi_id       Stripe PaymentIntent ID
     * @param bool $any_trainer   If true, credits can be used with any active trainer (default: true)
     * @return int|false          Credit record ID or false
     */
    public static function create_credit($parent_id, $trainer_id, $booking_id, $package_key, $group_size, $price_paid, $pi_id = '', $any_trainer = true) {
        global $wpdb;

        self::maybe_create_table();

        $pkg = self::get($package_key);

        // For single sessions, first session is the booking itself — remaining = 0
        // For packs, first session is scheduled — remaining = total - 1
        $total     = $pkg['sessions'];
        $remaining = max(0, $total - 1);

        $wpdb->insert(
            $wpdb->prefix . 'ptp_package_credits',
            array(
                'parent_id'         => $parent_id,
                'trainer_id'        => $trainer_id,
                'booking_id'        => $booking_id,
                'package_key'       => self::resolve_key($package_key),
                'total'             => $total,
                'remaining'         => $remaining,
                'group_size'        => $group_size,
                'price_paid'        => $price_paid,
                'payment_intent_id' => $pi_id,
                'any_trainer'       => $any_trainer ? 1 : 0,
                'status'            => $remaining > 0 ? 'active' : 'used',
                'expires_at'        => date('Y-m-d H:i:s', strtotime('+6 months')),
            ),
            array('%d', '%d', '%d', '%s', '%d', '%d', '%d', '%f', '%s', '%d', '%s', '%s')
        );

        return $wpdb->insert_id ?: false;
    }

    /**
     * Consume one credit from a package
     *
     * @param int $credit_id   Package credit record ID
     * @return bool            Success
     */
    public static function use_credit($credit_id) {
        global $wpdb;

        $credit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_package_credits WHERE id = %d AND status = 'active' AND remaining > 0",
            $credit_id
        ));

        if (!$credit) {
            return false;
        }

        $new_remaining = max(0, $credit->remaining - 1);

        $wpdb->update(
            $wpdb->prefix . 'ptp_package_credits',
            array(
                'remaining' => $new_remaining,
                'status'    => $new_remaining > 0 ? 'active' : 'used',
            ),
            array('id' => $credit_id),
            array('%d', '%s'),
            array('%d')
        );

        return true;
    }

    /**
     * Get active credits for a parent
     * Returns any_trainer flag so UI can show trainer picker when booking
     */
    public static function get_parent_credits($parent_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'ptp_package_credits';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT pc.*, 
                    t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
                    COALESCE(pc.any_trainer, 0) as any_trainer
             FROM {$table} pc
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON pc.trainer_id = t.id
             WHERE pc.parent_id = %d AND pc.status = 'active' AND pc.remaining > 0
             AND (pc.expires_at IS NULL OR pc.expires_at > NOW())
             ORDER BY pc.expires_at ASC",
            $parent_id
        ));
    }
}

// Create table on init
add_action('init', array('PTP_Packages', 'maybe_create_table'), 20);

// AJAX: Get active trainers for credit booking trainer picker
// v182: Powers the "Choose a trainer" dropdown when using any-trainer credits
add_action('wp_ajax_ptp_get_trainers_for_credits', 'ptp_ajax_get_trainers_for_credits');
function ptp_ajax_get_trainers_for_credits() {
    global $wpdb;

    // v223.1: Added nonce verification for security
    check_ajax_referer('ptp_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in'));
    }
    
    $trainers = $wpdb->get_results(
        "SELECT id, display_name, slug, photo_url, city, state, hourly_rate
         FROM {$wpdb->prefix}ptp_trainers
         WHERE status = 'active'
         ORDER BY display_name ASC"
    );
    
    $formatted = array();
    foreach ($trainers as $t) {
        $formatted[] = array(
            'id'       => intval($t->id),
            'name'     => $t->display_name,
            'slug'     => $t->slug,
            'photo'    => $t->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->display_name) . '&size=96&background=FCB900&color=0A0A0A&bold=true',
            'location' => trim(($t->city ?: '') . ($t->city && $t->state ? ', ' : '') . ($t->state ?: '')),
            'rate'     => floatval($t->hourly_rate ?: 0),
        );
    }
    
    wp_send_json_success(array('trainers' => $formatted));
}

// AJAX handler: Book a session using package credits
// v182: Supports booking with ANY active trainer when credit.any_trainer = 1
add_action('wp_ajax_ptp_book_with_credits', 'ptp_ajax_book_with_credits');
function ptp_ajax_book_with_credits() {
    global $wpdb;
    
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_nonce')) {
        wp_send_json_error(array('message' => 'Invalid security token'));
    }
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in'));
    }
    
    $credit_id    = intval($_POST['credit_id'] ?? 0);
    $trainer_id   = intval($_POST['trainer_id'] ?? 0); // v182: optional — pick any trainer
    $player_id    = intval($_POST['player_id'] ?? 0);
    $session_date = sanitize_text_field($_POST['session_date'] ?? '');
    $session_time = sanitize_text_field($_POST['session_time'] ?? '');
    $location     = sanitize_text_field($_POST['location'] ?? '');
    
    if (!$credit_id) {
        wp_send_json_error(array('message' => 'Missing credit ID'));
    }
    
    if (!$session_date) {
        wp_send_json_error(array('message' => 'Please select a date'));
    }
    
    // Get credit record
    $credit = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_package_credits WHERE id = %d AND status = 'active' AND remaining > 0",
        $credit_id
    ));
    
    if (!$credit) {
        wp_send_json_error(array('message' => 'No sessions remaining or credit expired'));
    }
    
    // Verify ownership
    $user_id = get_current_user_id();
    $parent = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
        $user_id
    ));
    
    if (!$parent || intval($parent->id) !== intval($credit->parent_id)) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }
    
    // ── v182: Resolve which trainer to book with ──
    $any_trainer = intval($credit->any_trainer ?? 0);
    
    if ($trainer_id && $any_trainer) {
        // Parent chose a different trainer — validate they exist and are active
        $chosen_trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, status, stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        if (!$chosen_trainer || $chosen_trainer->status !== 'active') {
            wp_send_json_error(array('message' => 'Selected trainer is not available. Please choose another.'));
        }
        $booking_trainer_id = $trainer_id;
    } elseif ($trainer_id && !$any_trainer) {
        // Credit is locked to original trainer — enforce it
        if ($trainer_id !== intval($credit->trainer_id)) {
            wp_send_json_error(array('message' => 'This credit can only be used with the original trainer.'));
        }
        $booking_trainer_id = intval($credit->trainer_id);
    } else {
        // No trainer_id passed — default to original trainer
        $booking_trainer_id = intval($credit->trainer_id);
    }
    
    // Check slot availability with the resolved trainer
    if ($session_time && class_exists('PTP_Booking')) {
        if (PTP_Booking::is_slot_booked($booking_trainer_id, $session_date, $session_time)) {
            wp_send_json_error(array('message' => 'This time slot is already booked. Please choose another.'));
        }
    }
    
    // Create booking from credit
    $booking_number = 'PTP-C-' . strtoupper(wp_generate_password(6, false, false));
    
    $group_info = '';
    if ($credit->group_size > 1) {
        $group_info = json_encode(array('group_size' => $credit->group_size));
    }
    
    $wpdb->insert(
        $wpdb->prefix . 'ptp_bookings',
        array(
            'booking_number'     => $booking_number,
            'trainer_id'         => $booking_trainer_id,
            'parent_id'          => $parent->id,
            'player_id'          => $player_id,
            'session_date'       => $session_date,
            'start_time'         => $session_time,
            'location'           => $location,
            'total_amount'       => 0, // paid via package
            'session_type'       => 'credit',
            'session_count'      => 1,
            'sessions_remaining' => 0,
            'group_players'      => $group_info,
            'payment_status'     => 'paid',
            'status'             => 'confirmed',
            'package_credit_id'  => $credit_id,
            'notes'              => 'Booked using package credit #' . $credit_id . ($booking_trainer_id !== intval($credit->trainer_id) ? ' (cross-trainer)' : ''),
            'created_at'         => current_time('mysql'),
        ),
        array('%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
    );
    
    $booking_id = $wpdb->insert_id;
    
    if (!$booking_id) {
        wp_send_json_error(array('message' => 'Failed to create booking'));
    }
    
    // Consume one credit
    PTP_Packages::use_credit($credit_id);
    
    // Send notifications
    try {
        if (class_exists('PTP_Notifications')) {
            PTP_Notifications::booking_created($booking_id);
        }
    } catch (Exception $e) {
        ptp_log('[PTP Credits] Notification error: ' . $e->getMessage());
    }
    
    do_action('ptp_booking_completed', $booking_id);
    
    wp_send_json_success(array(
        'booking_id' => $booking_id,
        'message' => 'Session booked!',
        'redirect' => home_url('/parent-dashboard/'),
    ));
}
