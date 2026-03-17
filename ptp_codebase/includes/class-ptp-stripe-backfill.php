<?php
/**
 * PTP Stripe Customer Backfill
 * v216.1: Scans all payment records across camp bookings, training bookings,
 * bundles, and unified orders. For each Stripe PaymentIntent:
 * - Attaches a Stripe Customer (find or create by email)
 * - Fills receipt_email
 * - Standardizes metadata (customer_email, customer_name, customer_phone)
 * 
 * Run via: WP Admin → PTP Settings → Stripe Backfill tab
 * Or via WP-CLI: wp eval "PTP_Stripe_Backfill::run_all();"
 */

if (!defined('ABSPATH')) exit;

class PTP_Stripe_Backfill {

    private static $batch_size = 25;
    private static $stripe_delay_ms = 200; // ms between Stripe calls to avoid rate limit
    private static $results = array(
        'scanned'  => 0,
        'updated'  => 0,
        'skipped'  => 0,
        'errors'   => array(),
        'no_pi'    => 0,
        'already_ok' => 0,
    );

    /**
     * Register admin hooks
     */
    public static function init() {
        add_action('wp_ajax_ptp_stripe_backfill_scan', array(__CLASS__, 'ajax_scan'));
        add_action('wp_ajax_ptp_stripe_backfill_run', array(__CLASS__, 'ajax_run_batch'));
    }

    // ================================================================
    // DATA COLLECTION: Gather all payment records from all tables
    // ================================================================

    /**
     * Get all payment records that need checking
     * Returns unified array: [{pi_id, email, name, phone, source, record_id}]
     */
    public static function get_all_payment_records($offset = 0, $limit = 100) {
        global $wpdb;
        $records = array();

        // 1. Camp bookings (ptp_camp_bookings) — camps plugin
        $camp_table = $wpdb->prefix . 'ptp_camp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$camp_table}'") === $camp_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, stripe_payment_id AS pi_id, customer_email AS email, 
                        customer_name AS name, customer_phone AS phone,
                        'camp_booking' AS source
                 FROM {$camp_table}
                 WHERE stripe_payment_id IS NOT NULL 
                   AND stripe_payment_id != ''
                   AND stripe_payment_id NOT LIKE '%%_%%'
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                $limit, $offset
            ), ARRAY_A);
            $records = array_merge($records, $rows ?: array());
        }

        // 2. Training bookings (ptp_bookings) — training platform
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$bookings_table}'") === $bookings_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT b.id, 
                        COALESCE(b.payment_intent_id, b.stripe_payment_intent) AS pi_id,
                        p.email, 
                        CONCAT(p.first_name, ' ', p.last_name) AS name,
                        p.phone,
                        'training_booking' AS source
                 FROM {$bookings_table} b
                 LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
                 WHERE (b.payment_intent_id IS NOT NULL AND b.payment_intent_id != '')
                    OR (b.stripe_payment_intent IS NOT NULL AND b.stripe_payment_intent != '')
                 ORDER BY b.id DESC
                 LIMIT %d OFFSET %d",
                $limit, $offset
            ), ARRAY_A);
            $records = array_merge($records, $rows ?: array());
        }

        // 3. Unified camp orders (ptp_unified_camp_orders)
        $orders_table = $wpdb->prefix . 'ptp_unified_camp_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$orders_table}'") === $orders_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, stripe_payment_intent_id AS pi_id, 
                        billing_email AS email,
                        CONCAT(billing_first_name, ' ', billing_last_name) AS name,
                        billing_phone AS phone,
                        'unified_order' AS source
                 FROM {$orders_table}
                 WHERE stripe_payment_intent_id IS NOT NULL 
                   AND stripe_payment_intent_id != ''
                 ORDER BY id DESC
                 LIMIT %d OFFSET %d",
                $limit, $offset
            ), ARRAY_A);
            $records = array_merge($records, $rows ?: array());
        }

        // 4. Bundles (ptp_bundles)
        $bundles_table = $wpdb->prefix . 'ptp_bundles';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$bundles_table}'") === $bundles_table) {
            // Bundles may not have direct email — join to user or parent
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT b.id, b.payment_intent_id AS pi_id,
                        COALESCE(u.user_email, p.email) AS email,
                        COALESCE(u.display_name, CONCAT(p.first_name, ' ', p.last_name)) AS name,
                        p.phone,
                        'bundle' AS source
                 FROM {$bundles_table} b
                 LEFT JOIN {$wpdb->users} u ON b.user_id = u.ID
                 LEFT JOIN {$wpdb->prefix}ptp_parents p ON u.user_email = p.email
                 WHERE b.payment_intent_id IS NOT NULL 
                   AND b.payment_intent_id != ''
                 ORDER BY b.id DESC
                 LIMIT %d OFFSET %d",
                $limit, $offset
            ), ARRAY_A);
            $records = array_merge($records, $rows ?: array());
        }

        // 5. Camp registrations (ptp_camp_registrations) — bulletproof checkout
        $reg_table = $wpdb->prefix . 'ptp_camp_registrations';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$reg_table}'") === $reg_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.id, r.stripe_payment_intent AS pi_id,
                        p.email,
                        CONCAT(p.first_name, ' ', p.last_name) AS name,
                        p.phone,
                        'camp_registration' AS source
                 FROM {$reg_table} r
                 LEFT JOIN {$wpdb->prefix}ptp_parents p ON r.parent_id = p.id
                 WHERE r.stripe_payment_intent IS NOT NULL
                   AND r.stripe_payment_intent != ''
                 ORDER BY r.id DESC
                 LIMIT %d OFFSET %d",
                $limit, $offset
            ), ARRAY_A);
            $records = array_merge($records, $rows ?: array());
        }

        // Deduplicate by PI ID (same PI may appear in multiple tables)
        $seen = array();
        $unique = array();
        foreach ($records as $rec) {
            $pi = $rec['pi_id'] ?? '';
            if (!$pi || isset($seen[$pi])) continue;
            // Prefer records with email
            if (empty($rec['email']) && isset($seen[$pi])) continue;
            $seen[$pi] = true;
            $unique[] = $rec;
        }

        return $unique;
    }

    /**
     * Count total records across all tables
     */
    public static function count_all_records() {
        global $wpdb;
        $total = 0;

        $tables = array(
            $wpdb->prefix . 'ptp_camp_bookings'        => "stripe_payment_id IS NOT NULL AND stripe_payment_id != ''",
            $wpdb->prefix . 'ptp_bookings'              => "(payment_intent_id IS NOT NULL AND payment_intent_id != '') OR (stripe_payment_intent IS NOT NULL AND stripe_payment_intent != '')",
            $wpdb->prefix . 'ptp_unified_camp_orders'   => "stripe_payment_intent_id IS NOT NULL AND stripe_payment_intent_id != ''",
            $wpdb->prefix . 'ptp_bundles'               => "payment_intent_id IS NOT NULL AND payment_intent_id != ''",
            $wpdb->prefix . 'ptp_camp_registrations'    => "stripe_payment_intent IS NOT NULL AND stripe_payment_intent != ''",
        );

        foreach ($tables as $table => $where) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
                $total += intval($count);
            }
        }

        return $total;
    }

    // ================================================================
    // STRIPE OPERATIONS
    // ================================================================

    /**
     * Get Stripe secret key
     */
    private static function get_secret_key() {
        $test_mode = get_option('ptp_stripe_test_mode', true);
        if ($test_mode) {
            return get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''));
        }
        return get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
    }

    /**
     * Retrieve a PaymentIntent from Stripe
     */
    private static function get_payment_intent($pi_id) {
        $sk = self::get_secret_key();
        if (!$sk) return null;

        $response = wp_remote_get("https://api.stripe.com/v1/payment_intents/{$pi_id}", array(
            'headers' => array('Authorization' => 'Bearer ' . $sk),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) return null;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['error'])) return null;

        return $body;
    }

    /**
     * Update a PaymentIntent: attach customer, receipt_email, metadata
     */
    private static function update_payment_intent($pi_id, $params) {
        $sk = self::get_secret_key();
        if (!$sk) return false;

        $response = wp_remote_post("https://api.stripe.com/v1/payment_intents/{$pi_id}", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $sk,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => $params,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) return false;

        $code = wp_remote_retrieve_response_code($response);
        return $code === 200;
    }

    /**
     * Find or create Stripe Customer by email
     */
    public static function find_or_create_customer($email, $name = '', $phone = '') {
        // Use PTP_Stripe helper if available
        if (class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'find_or_create_customer_by_email')) {
            return PTP_Stripe::find_or_create_customer_by_email($email, $name, $phone);
        }

        // Fallback: direct Stripe API
        $sk = self::get_secret_key();
        if (!$sk || !$email) return '';

        // Search existing
        $search = wp_remote_get('https://api.stripe.com/v1/customers/search?' . http_build_query(array(
            'query' => 'email:"' . $email . '"',
        )), array(
            'headers' => array('Authorization' => 'Bearer ' . $sk),
            'timeout' => 15,
        ));

        if (!is_wp_error($search)) {
            $body = json_decode(wp_remote_retrieve_body($search), true);
            if (!empty($body['data'][0]['id'])) {
                $cust_id = $body['data'][0]['id'];
                // Update name/phone if we have better data
                if ($name || $phone) {
                    wp_remote_post("https://api.stripe.com/v1/customers/{$cust_id}", array(
                        'headers' => array('Authorization' => 'Bearer ' . $sk),
                        'body'    => array_filter(array('name' => $name, 'phone' => $phone)),
                        'timeout' => 10,
                    ));
                }
                return $cust_id;
            }
        }

        // Create new
        $create = wp_remote_post('https://api.stripe.com/v1/customers', array(
            'headers' => array('Authorization' => 'Bearer ' . $sk),
            'body'    => array_filter(array(
                'email' => $email,
                'name'  => $name,
                'phone' => $phone,
                'metadata[source]' => 'ptp_backfill',
            )),
            'timeout' => 15,
        ));

        if (!is_wp_error($create)) {
            $body = json_decode(wp_remote_retrieve_body($create), true);
            if (!empty($body['id'])) {
                return $body['id'];
            }
        }

        return '';
    }

    /**
     * Update an existing Stripe Customer's name/phone if missing
     */
    private static function update_customer_details($customer_id, $name = '', $phone = '') {
        if (!$customer_id || (!$name && !$phone)) return;

        $sk = self::get_secret_key();
        if (!$sk) return;

        // Fetch current customer to check what's missing
        $response = wp_remote_get("https://api.stripe.com/v1/customers/{$customer_id}", array(
            'headers' => array('Authorization' => 'Bearer ' . $sk),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) return;

        $cust = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($cust['id'])) return;

        $updates = array();
        if ($name && empty($cust['name'])) $updates['name'] = $name;
        if ($phone && empty($cust['phone'])) $updates['phone'] = $phone;

        if (!empty($updates)) {
            wp_remote_post("https://api.stripe.com/v1/customers/{$customer_id}", array(
                'headers' => array('Authorization' => 'Bearer ' . $sk),
                'body'    => $updates,
                'timeout' => 10,
            ));
        }
    }

    // ================================================================
    // BATCH PROCESSING
    // ================================================================

    /**
     * Process a single record: check PI, attach customer, fill metadata
     */
    public static function process_record($record) {
        $pi_id = $record['pi_id'] ?? '';
        $email = trim($record['email'] ?? '');
        $name  = trim($record['name'] ?? '');
        $phone = trim($record['phone'] ?? '');

        self::$results['scanned']++;

        if (!$pi_id || strpos($pi_id, 'pi_') !== 0) {
            self::$results['no_pi']++;
            return array('status' => 'skip', 'reason' => 'invalid_pi_id');
        }

        if (!$email) {
            self::$results['skipped']++;
            return array('status' => 'skip', 'reason' => 'no_email');
        }

        // Fetch current PI state from Stripe
        $pi = self::get_payment_intent($pi_id);
        if (!$pi) {
            self::$results['errors'][] = "Failed to fetch PI {$pi_id}";
            return array('status' => 'error', 'reason' => 'fetch_failed');
        }

        // Check what needs updating
        $needs_update = false;
        $update_params = array();

        // 1. Customer attachment
        $has_customer = !empty($pi['customer']);
        if (!$has_customer) {
            $cust_id = self::find_or_create_customer($email, $name, $phone);
            if ($cust_id) {
                // Can only attach customer to PI if status allows it
                // succeeded/canceled PIs reject customer updates, but we still update metadata below
                $updatable_statuses = array('requires_payment_method', 'requires_confirmation', 'requires_action', 'processing');
                if (in_array($pi['status'], $updatable_statuses)) {
                    $update_params['customer'] = $cust_id;
                    $needs_update = true;
                }
            }
        } else {
            // Customer exists — still update their name/phone if we have better data
            $cust_id = $pi['customer'];
            if ($name || $phone) {
                self::update_customer_details($cust_id, $name, $phone);
            }
        }

        // 2. Receipt email
        if (empty($pi['receipt_email']) && $email) {
            $update_params['receipt_email'] = $email;
            $needs_update = true;
        }

        // 3. Metadata standardization
        $meta = $pi['metadata'] ?? array();
        $meta_updates = array();

        if (empty($meta['customer_email']) && $email) {
            $meta_updates['metadata[customer_email]'] = $email;
            $needs_update = true;
        }
        if (empty($meta['customer_name']) && $name) {
            $meta_updates['metadata[customer_name]'] = $name;
            $needs_update = true;
        }
        if (empty($meta['customer_phone']) && $phone) {
            $meta_updates['metadata[customer_phone]'] = $phone;
            $needs_update = true;
        }
        // Map camps plugin keys to standard keys
        if (!empty($meta['parent_email']) && empty($meta['customer_email'])) {
            $meta_updates['metadata[customer_email]'] = $meta['parent_email'];
            $needs_update = true;
        }
        if (!empty($meta['parent_name']) && empty($meta['customer_name'])) {
            $meta_updates['metadata[customer_name]'] = $meta['parent_name'];
            $needs_update = true;
        }

        if (!$needs_update) {
            self::$results['already_ok']++;
            return array('status' => 'ok', 'reason' => 'already_complete');
        }

        // Apply updates
        $all_params = array_merge($update_params, $meta_updates);
        $success = self::update_payment_intent($pi_id, $all_params);

        if ($success) {
            self::$results['updated']++;
            return array(
                'status'  => 'updated',
                'pi_id'   => $pi_id,
                'changes' => array_keys($all_params),
            );
        } else {
            self::$results['errors'][] = "Failed to update PI {$pi_id}";
            return array('status' => 'error', 'reason' => 'update_failed');
        }
    }

    /**
     * Process a batch of records
     */
    public static function process_batch($offset = 0, $batch_size = null) {
        $batch_size = $batch_size ?: self::$batch_size;
        $records = self::get_all_payment_records($offset, $batch_size);

        $batch_results = array();
        foreach ($records as $record) {
            $result = self::process_record($record);
            $batch_results[] = array_merge($record, $result);

            // Rate limit: pause between Stripe calls
            usleep(self::$stripe_delay_ms * 1000);
        }

        return array(
            'batch'   => $batch_results,
            'totals'  => self::$results,
            'has_more' => count($records) >= $batch_size,
        );
    }

    // ================================================================
    // AJAX HANDLERS
    // ================================================================

    /**
     * AJAX: Scan — count records and show preview
     */
    public static function ajax_scan() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $total = self::count_all_records();

        // Get a sample of 5 records for preview
        $sample = self::get_all_payment_records(0, 5);
        $preview = array();
        foreach ($sample as $rec) {
            $pi = self::get_payment_intent($rec['pi_id']);
            $preview[] = array(
                'pi_id'        => $rec['pi_id'],
                'email'        => $rec['email'],
                'name'         => $rec['name'],
                'source'       => $rec['source'],
                'has_customer' => !empty($pi['customer']),
                'has_receipt'  => !empty($pi['receipt_email']),
                'has_meta'     => !empty($pi['metadata']['customer_email']),
                'status'       => $pi['status'] ?? 'unknown',
            );
            usleep(self::$stripe_delay_ms * 1000);
        }

        wp_send_json_success(array(
            'total_records' => $total,
            'preview'       => $preview,
            'batch_size'    => self::$batch_size,
        ));
    }

    /**
     * AJAX: Run a batch
     */
    public static function ajax_run_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $offset = intval($_POST['offset'] ?? 0);
        $batch_size = intval($_POST['batch_size'] ?? self::$batch_size);
        $batch_size = min($batch_size, 50); // Safety cap

        $result = self::process_batch($offset, $batch_size);

        wp_send_json_success(array(
            'offset'    => $offset,
            'processed' => count($result['batch']),
            'has_more'  => $result['has_more'],
            'next_offset' => $offset + $batch_size,
            'totals'    => $result['totals'],
            'batch'     => $result['batch'],
        ));
    }

    // ================================================================
    // CLI / MANUAL RUN
    // ================================================================

    /**
     * Run all records (for WP-CLI or manual execution)
     * Usage: wp eval "PTP_Stripe_Backfill::run_all();"
     */
    public static function run_all() {
        $total = self::count_all_records();
        ptp_log("[PTP Backfill] Starting — {$total} total payment records");

        $offset = 0;
        $batch_num = 0;

        while (true) {
            $batch_num++;
            $result = self::process_batch($offset, self::$batch_size);

            $processed = count($result['batch']);
            ptp_log("[PTP Backfill] Batch {$batch_num}: processed {$processed}, updated {$result['totals']['updated']}, errors " . count($result['totals']['errors']));

            if (!$result['has_more'] || $processed === 0) {
                break;
            }

            $offset += self::$batch_size;

            // Safety: don't run forever
            if ($batch_num > 200) {
                ptp_log("[PTP Backfill] Safety cap reached at batch 200");
                break;
            }
        }

        $t = self::$results;
        ptp_log("[PTP Backfill] Complete — Scanned: {$t['scanned']}, Updated: {$t['updated']}, Already OK: {$t['already_ok']}, Skipped: {$t['skipped']}, No PI: {$t['no_pi']}, Errors: " . count($t['errors']));

        return self::$results;
    }
}

// Initialize
PTP_Stripe_Backfill::init();

/**
 * WP Cron: Auto-sync every 6 hours
 * Catches any PIs that slipped through without customer attachment
 */
add_action('init', function() {
    if (!wp_next_scheduled('ptp_stripe_backfill_cron')) {
        wp_schedule_event(time(), 'ptp_six_hours', 'ptp_stripe_backfill_cron');
    }
});

// Register custom interval
add_filter('cron_schedules', function($schedules) {
    $schedules['ptp_six_hours'] = array(
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => 'Every 6 Hours',
    );
    return $schedules;
});

// Cron handler: process recent PIs (last 24 hours only, not full history)
add_action('ptp_stripe_backfill_cron', function() {
    PTP_Stripe_Backfill_Auto::sync_recent();
});

/**
 * Auto-sync class: lightweight version that runs on cron and after each checkout
 */
class PTP_Stripe_Backfill_Auto {

    /**
     * After any camp booking is confirmed, ensure the PI has customer attached.
     * Hooks into ptp_camp_order_completed (runs after email wiring).
     */
    public static function init() {
        // Run AFTER email wiring (priority 20 vs email's 10)
        add_action('ptp_camp_order_completed', array(__CLASS__, 'ensure_pi_customer'), 20, 2);
        // Also catch training bookings
        add_action('ptp_booking_confirmed', array(__CLASS__, 'ensure_booking_pi_customer'), 20, 1);
    }

    /**
     * Post-checkout: verify & fix customer on this specific PI
     */
    public static function ensure_pi_customer($booking_id, $booking_data) {
        // Only process arrays (direct booking data)
        if (!is_array($booking_data)) return;

        $pi_id = $booking_data['stripe_payment_id'] ?? '';
        $email = $booking_data['customer_email'] ?? '';
        $name  = $booking_data['customer_name'] ?? '';
        $phone = $booking_data['customer_phone'] ?? '';

        if (!$pi_id || !$email) return;

        // Schedule async so we don't slow down checkout response
        wp_schedule_single_event(time() + 5, 'ptp_stripe_ensure_customer', array(
            $pi_id, $email, $name, $phone
        ));
    }

    /**
     * Post-checkout: verify & fix customer on training booking PI
     */
    public static function ensure_booking_pi_customer($booking_id) {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.payment_intent_id, p.email, CONCAT(p.first_name, ' ', p.last_name) AS name, p.phone
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking || !$booking->payment_intent_id || !$booking->email) return;

        wp_schedule_single_event(time() + 5, 'ptp_stripe_ensure_customer', array(
            $booking->payment_intent_id, $booking->email, $booking->name ?? '', $booking->phone ?? ''
        ));
    }

    /**
     * Cron: Process recent PIs from last 24 hours
     */
    public static function sync_recent() {
        $sk = self::get_secret_key();
        if (!$sk) return;

        $since = time() - (24 * 3600);
        $updated = 0;
        $checked = 0;
        $has_more = true;
        $starting_after = null;

        while ($has_more && $checked < 200) {
            $params = array(
                'limit' => 50,
                'created[gte]' => $since,
            );
            if ($starting_after) {
                $params['starting_after'] = $starting_after;
            }

            $response = wp_remote_get('https://api.stripe.com/v1/payment_intents?' . http_build_query($params), array(
                'headers' => array('Authorization' => 'Bearer ' . $sk),
                'timeout' => 30,
            ));

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) break;

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $intents = $body['data'] ?? array();
            $has_more = $body['has_more'] ?? false;

            if (empty($intents)) break;

            foreach ($intents as $pi) {
                $checked++;
                $starting_after = $pi['id'];

                // Skip if already has customer
                if (!empty($pi['customer'])) continue;

                // Get email from metadata or receipt_email
                $meta = $pi['metadata'] ?? array();
                $email = $meta['customer_email'] ?? $meta['parent_email'] ?? $pi['receipt_email'] ?? '';
                $name  = $meta['customer_name'] ?? $meta['parent_name'] ?? '';
                $phone = $meta['customer_phone'] ?? '';

                if (!$email) {
                    // Try to find email from our DB
                    $email = self::find_email_for_pi($pi['id']);
                }

                if (!$email) continue;

                // Attach customer
                $result = self::attach_customer_to_pi($pi['id'], $email, $name, $phone, $pi['status']);
                if ($result) $updated++;

                usleep(150000); // 150ms rate limit
            }
        }

        if ($updated > 0) {
            ptp_log("[PTP Stripe Auto-Sync] Checked {$checked} PIs, attached customer to {$updated}");
        }
    }

    /**
     * Single PI fix (called via wp_schedule_single_event)
     */
    public static function fix_single_pi($pi_id, $email, $name = '', $phone = '') {
        $sk = self::get_secret_key();
        if (!$sk || !$pi_id || !$email) return;

        // Fetch PI to check current state
        $response = wp_remote_get("https://api.stripe.com/v1/payment_intents/{$pi_id}", array(
            'headers' => array('Authorization' => 'Bearer ' . $sk),
            'timeout' => 15,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return;

        $pi = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($pi['id'])) return;

        // Already has customer? Just ensure metadata is complete
        if (!empty($pi['customer'])) {
            $meta = $pi['metadata'] ?? array();
            $updates = array();
            if (empty($meta['customer_email']) && $email) $updates['metadata[customer_email]'] = $email;
            if (empty($meta['customer_name']) && $name) $updates['metadata[customer_name]'] = $name;
            if (empty($meta['customer_phone']) && $phone) $updates['metadata[customer_phone]'] = $phone;
            
            if (!empty($updates)) {
                wp_remote_post("https://api.stripe.com/v1/payment_intents/{$pi_id}", array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $sk,
                        'Content-Type'  => 'application/x-www-form-urlencoded',
                    ),
                    'body' => $updates,
                    'timeout' => 15,
                ));
            }
            return;
        }

        self::attach_customer_to_pi($pi_id, $email, $name, $phone, $pi['status']);
    }

    /**
     * Core: find/create customer and attach to PI
     */
    private static function attach_customer_to_pi($pi_id, $email, $name, $phone, $pi_status) {
        // Find or create customer
        $cust_id = '';
        if (class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'find_or_create_customer_by_email')) {
            $cust_id = PTP_Stripe::find_or_create_customer_by_email($email, $name, $phone);
        } else {
            $cust_id = PTP_Stripe_Backfill::find_or_create_customer($email, $name, $phone);
        }

        if (!$cust_id) return false;

        $sk = self::get_secret_key();
        $update_params = array(
            'metadata[customer_email]' => $email,
        );
        if ($name) $update_params['metadata[customer_name]'] = $name;
        if ($phone) $update_params['metadata[customer_phone]'] = $phone;

        // Can only attach customer to non-terminal PIs
        $updatable = array('requires_payment_method', 'requires_confirmation', 'requires_action', 'processing');
        if (in_array($pi_status, $updatable)) {
            $update_params['customer'] = $cust_id;
        }

        // Always set receipt_email
        $update_params['receipt_email'] = $email;

        $response = wp_remote_post("https://api.stripe.com/v1/payment_intents/{$pi_id}", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $sk,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body' => $update_params,
            'timeout' => 15,
        ));

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Look up email for a PI from our local DB tables
     */
    private static function find_email_for_pi($pi_id) {
        global $wpdb;

        // Check camp bookings
        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT customer_email FROM {$wpdb->prefix}ptp_camp_bookings WHERE stripe_payment_id = %s LIMIT 1",
            $pi_id
        ));
        if ($email) return $email;

        // Check training bookings
        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT p.email FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             WHERE b.payment_intent_id = %s LIMIT 1",
            $pi_id
        ));
        if ($email) return $email;

        // Check unified orders
        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT billing_email FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE stripe_payment_intent_id = %s LIMIT 1",
            $pi_id
        ));

        return $email ?: '';
    }

    private static function get_secret_key() {
        $test_mode = get_option('ptp_stripe_test_mode', true);
        if ($test_mode) {
            return get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''));
        }
        return get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
    }
}

// Initialize auto-sync hooks
PTP_Stripe_Backfill_Auto::init();

// Register the single-event handler for post-checkout PI fixes
add_action('ptp_stripe_ensure_customer', array('PTP_Stripe_Backfill_Auto', 'fix_single_pi'), 10, 4);
