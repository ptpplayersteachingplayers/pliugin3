<?php
/**
 * PTP Abandoned Cart Recovery v220
 * 
 * Unified system for both camp and training abandoned carts.
 * - Detects cart_type from cart_data items (camp/training/mixed)
 * - Sends branded, mobile-optimized HTML emails (3-email sequence)
 * - Anti-spam: max 3 emails per cart, 14-day global cooldown per email, batch limits
 * - Cross-table dedup: single cooldown across camp + training sequences
 * - Booking-exists guard: auto-recovers carts when customer has confirmed booking
 * - Marks carts recovered on ptp_booking_completed, ptp_checkout_completed, ptp_order_created
 * - Logs all sends to ptp_email_logs for admin visibility
 * - Admin tab with recovery metrics and email log
 * 
 * Replaces: camps plugin process_abandonment_emails() plain-text emails
 * Replaces: PTP_Email_Automation abandoned cart emails (training)
 */

defined('ABSPATH') || exit;

class PTP_Abandoned_Cart_Recovery {

    private static $instance = null;

    // Email sequence timing
    const EMAIL_1_DELAY = 30;     // minutes after abandon
    const EMAIL_2_DELAY = 180;    // minutes (3 hours)
    const EMAIL_3_DELAY = 1440;   // minutes (24 hours)
    const MAX_CART_AGE  = 4320;   // minutes (72 hours) — stop emailing after this
    const MAX_EMAILS    = 3;
    const GLOBAL_COOLDOWN_DAYS = 14; // Don't email same address more than once per 14 days across carts
    const BATCH_LIMIT   = 15;     // Max emails per cron run

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        // Take over cron from camps plugin
        add_action('ptp_camps_abandonment_cron', array($this, 'process_all_abandoned_carts'));
        
        // Also hook the training platform cron if it fires
        add_action('ptp_send_abandoned_cart_emails', array($this, 'process_all_abandoned_carts'));

        // Schedule if not already
        if (!wp_next_scheduled('ptp_camps_abandonment_cron')) {
            wp_schedule_event(time(), 'every_5_minutes', 'ptp_camps_abandonment_cron');
        }

        // AJAX for admin tab
        add_action('wp_ajax_ptp_abandoned_cart_stats', array($this, 'ajax_stats'));
        add_action('wp_ajax_ptp_abandoned_cart_log', array($this, 'ajax_log'));

        // AJAX: Capture abandoned cart from checkout (both logged-in and guest)
        add_action('wp_ajax_ptp_camps_capture_email', array($this, 'ajax_capture_email'));
        add_action('wp_ajax_nopriv_ptp_camps_capture_email', array($this, 'ajax_capture_email'));

        // Ensure phone column exists on abandoned cart tables
        add_action('admin_init', array($this, 'maybe_add_phone_column'), 999);
        
        // v220: Mark abandoned carts as recovered when checkout completes
        // This prevents "still thinking?" emails from going to parents who already booked
        add_action('ptp_booking_completed', array($this, 'on_booking_completed'), 5, 1);
        add_action('ptp_checkout_completed', array($this, 'on_checkout_completed'), 5, 2);
        add_action('ptp_order_created', array($this, 'on_order_created'), 5, 1);
    }

    /**
     * Add phone column to abandoned cart tables if missing.
     * Runs once, stores a version flag in options.
     */
    public function maybe_add_phone_column() {
        if (get_option('ptp_ac_phone_col_v1')) return;
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'ptp_camp_abandoned_carts',
            $wpdb->prefix . 'ptp_abandoned_carts',
        );

        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) continue;
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
            if (!in_array('phone', $cols)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN phone varchar(20) DEFAULT NULL AFTER email");
            }
        }

        update_option('ptp_ac_phone_col_v1', 1);
    }

    // ================================================================
    // v220: MARK CARTS RECOVERED ON SUCCESSFUL CHECKOUT
    // ================================================================

    /**
     * Hook: ptp_booking_completed — training booking created
     */
    public function on_booking_completed($booking_id) {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.guest_email, p.email as parent_email
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             WHERE b.id = %d",
            $booking_id
        ));
        if (!$booking) return;
        
        $email = $booking->parent_email ?: $booking->guest_email;
        if ($email) {
            $this->mark_carts_recovered($email);
        }
    }

    /**
     * Hook: ptp_checkout_completed — unified checkout finished
     */
    public function on_checkout_completed($data, $result = array()) {
        $email = '';
        if (is_array($data)) {
            $email = $data['parent_data']['email'] ?? ($data['email'] ?? '');
        }
        if ($email) {
            $this->mark_carts_recovered($email);
        }
    }

    /**
     * Hook: ptp_order_created — camp/product order created
     */
    public function on_order_created($order_id) {
        global $wpdb;
        // Try native orders table
        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT customer_email FROM {$wpdb->prefix}ptp_orders WHERE id = %d",
            $order_id
        ));
        // Fallback: WooCommerce order meta
        if (!$email && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) $email = $order->get_billing_email();
        }
        if ($email) {
            $this->mark_carts_recovered($email);
        }
    }

    /**
     * Mark ALL abandoned carts for this email as recovered across BOTH tables.
     * Callable from anywhere: PTP_Abandoned_Cart_Recovery::instance()->mark_carts_recovered($email)
     */
    public function mark_carts_recovered($email) {
        if (empty($email)) return;
        global $wpdb;
        
        // 1. Training abandoned carts (recovered = 1)
        $t_table = $wpdb->prefix . 'ptp_abandoned_carts';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$t_table}'") === $t_table) {
            $wpdb->update($t_table, array('recovered' => 1), array('email' => $email, 'recovered' => 0));
        }
        
        // 2. Camp abandoned carts (status = 'recovered')
        $c_table = $wpdb->prefix . 'ptp_camp_abandoned_carts';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$c_table}'") === $c_table) {
            $wpdb->update($c_table, array('status' => 'recovered', 'updated_at' => current_time('mysql')), array('email' => $email, 'status' => 'abandoned'));
        }
        
        ptp_log("[PTP Cart Recovery v220] Marked carts recovered for: {$email}");
    }

    /**
     * Check if this email has a confirmed booking created in the last 72 hours.
     * Prevents sending "still thinking?" to someone who already booked.
     */
    private function has_recent_booking($email) {
        global $wpdb;
        
        // Check training bookings
        $b_table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$b_table}'") === $b_table) {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$b_table} b
                 LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
                 WHERE (p.email = %s OR b.guest_email = %s)
                 AND b.status IN ('confirmed', 'pending', 'completed')
                 AND b.created_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)",
                $email, $email
            ));
            if ($found > 0) return true;
        }
        
        // Check camp orders
        $o_table = $wpdb->prefix . 'ptp_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$o_table}'") === $o_table) {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$o_table}
                 WHERE customer_email = %s
                 AND status IN ('completed', 'processing', 'pending')
                 AND created_at > DATE_SUB(NOW(), INTERVAL 72 HOUR)",
                $email
            ));
            if ($found > 0) return true;
        }
        
        return false;
    }

    /**
     * v220: Cross-table cooldown check.
     * Returns true if this email has received ANY abandoned cart email (camp OR training) recently.
     */
    private function is_in_global_cooldown($email) {
        global $wpdb;
        $log_table = $wpdb->prefix . 'ptp_email_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$log_table}'") !== $log_table) return false;

        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} 
             WHERE email = %s 
             AND email_type LIKE '%%abandoned%%'
             AND sent_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $email, self::GLOBAL_COOLDOWN_DAYS
        ));

        return intval($recent) >= self::MAX_EMAILS;
    }

    // ================================================================
    // CART TYPE DETECTION
    // ================================================================

    /**
     * Determine cart type from cart_data JSON
     * Returns: 'camp', 'training', 'mixed', or 'unknown'
     */
    private function detect_cart_type($cart_data_json) {
        $data = is_string($cart_data_json) ? json_decode($cart_data_json, true) : $cart_data_json;
        if (empty($data) || !is_array($data)) return 'unknown';

        $items = $data['items'] ?? array();
        if (empty($items)) return 'unknown';

        $has_camp = false;
        $has_training = false;

        foreach ($items as $item) {
            $type = $item['item_type'] ?? $item['type'] ?? '';
            $name = strtolower($item['name'] ?? $item['title'] ?? '');
            
            if ($type === 'camp' || strpos($name, 'camp') !== false || strpos($name, 'soccer camp') !== false) {
                $has_camp = true;
            } elseif ($type === 'training' || $type === 'session' || strpos($name, 'session') !== false || strpos($name, 'training') !== false) {
                $has_training = true;
            }
        }

        if ($has_camp && $has_training) return 'mixed';
        if ($has_camp) return 'camp';
        if ($has_training) return 'training';
        
        // Fallback: if cart has camp_names field, it's a camp cart
        if (!empty($data['camp_names']) || !empty($data['camp_ids'])) return 'camp';
        if (!empty($data['trainer_id'])) return 'training';

        return 'unknown';
    }

    /**
     * Extract item details for email display
     */
    private function extract_items($cart_data_json) {
        $data = is_string($cart_data_json) ? json_decode($cart_data_json, true) : $cart_data_json;
        $items = array();
        
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $metadata = $item['metadata'] ?? array();
                $items[] = array(
                    'name'              => $item['name'] ?? $item['title'] ?? 'Item',
                    'price'             => floatval($item['price'] ?? $item['line_total'] ?? 0),
                    'type'              => $item['item_type'] ?? $item['type'] ?? 'camp',
                    'date'              => $metadata['date'] ?? $metadata['camp_dates'] ?? $item['date'] ?? '',
                    'location'          => $metadata['location'] ?? $metadata['camp_location'] ?? $item['location'] ?? '',
                    'time'              => $metadata['time'] ?? $metadata['camp_time'] ?? $item['time'] ?? '',
                    'id'                => $item['id'] ?? $item['item_id'] ?? 0,
                    'stripe_product_id' => $metadata['stripe_product'] ?? $metadata['stripe_product_id'] ?? '',
                    'trainer_id'        => $metadata['trainer_id'] ?? '',
                    'package'           => $metadata['package'] ?? '',
                    'group_size'        => $metadata['group_size'] ?? 1,
                );
            }
        }

        return $items;
    }

    // ================================================================
    // MAIN PROCESSOR
    // ================================================================

    /**
     * Process all abandoned carts from both tables
     */
    public function process_all_abandoned_carts() {
        $sent_count = 0;

        // Source 1: ptp_camp_abandoned_carts (camps plugin)
        $sent_count += $this->process_camp_carts($sent_count);

        // Source 2: ptp_abandoned_carts (training platform)
        $sent_count += $this->process_training_carts($sent_count);

        if ($sent_count > 0) {
            ptp_log("[PTP Cart Recovery] Sent {$sent_count} abandoned cart emails this run");
        }
    }

    private function process_camp_carts($already_sent) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_camp_abandoned_carts';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return 0;

        $now = current_time('mysql');
        $sent = 0;

        // Get all eligible carts (abandoned, not unsubscribed, within age window)
        $carts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status = 'abandoned' 
             AND unsubscribed = 0 
             AND emails_sent < %d
             AND created_at > DATE_SUB(%s, INTERVAL %d MINUTE)
             ORDER BY emails_sent ASC, created_at ASC
             LIMIT %d",
            self::MAX_EMAILS, $now, self::MAX_CART_AGE, self::BATCH_LIMIT
        ));

        foreach ($carts as $cart) {
            if (($sent + $already_sent) >= self::BATCH_LIMIT) break;

            $email_num = intval($cart->emails_sent) + 1;
            $minutes_since = (strtotime($now) - strtotime($cart->created_at)) / 60;

            // Check timing for this email number
            if (!$this->should_send_email($email_num, $minutes_since)) continue;

            // Global cooldown check (per-table)
            if ($this->is_in_cooldown($cart->email, 'camp_abandoned')) continue;
            
            // v220: Cross-table cooldown — don't spam if training sequence is also running
            if ($this->is_in_global_cooldown($cart->email)) continue;
            
            // v220: Skip if this person already completed a booking/order
            if ($this->has_recent_booking($cart->email)) {
                // Auto-recover so we don't check again next cron
                $wpdb->update($table, array('status' => 'recovered', 'updated_at' => current_time('mysql')), array('id' => $cart->id));
                continue;
            }

            $cart_type = $this->detect_cart_type($cart->cart_data);
            if ($cart_type === 'unknown') $cart_type = 'camp'; // default for camps table

            $items = $this->extract_items($cart->cart_data);
            $first_name = trim(explode(' ', $cart->name)[0]) ?: 'there';

            // Build recovery URL based on cart type
            $data = json_decode($cart->cart_data, true);
            $recovery_url = '';
            if ($cart_type === 'training' || $cart_type === 'mixed') {
                // Training items → checkout with trainer params
                $recovery_url = home_url('/ptp-checkout/?' . http_build_query(array(
                    'recover' => $cart->id,
                    'recover_source' => 'camp_abandoned',
                )));
            } else {
                // Camp items → checkout with ?camp= (stripe product) or ?camps= (post IDs)
                $recovery_url = $this->build_camp_recovery_url($items, $data, $cart->id);
            }
            $unsub_url = home_url('/?ptp_unsub=' . base64_encode($cart->email));

            // Also send SMS on first email if phone available
            if ($email_num === 1 && !empty($cart->phone)) {
                $this->send_recovery_sms($cart->phone, $first_name, $cart_type, $recovery_url, $item_names ?? '');
            }

            $result = $this->send_recovery_email(array(
                'to'           => $cart->email,
                'first_name'   => $first_name,
                'email_num'    => $email_num,
                'cart_type'    => $cart_type,
                'items'        => $items,
                'total'        => floatval($cart->cart_total),
                'recovery_url' => $recovery_url,
                'unsub_url'    => $unsub_url,
                'item_names'   => $cart->camp_names ?: '',
                'source'       => 'camp_abandoned_carts',
                'cart_id'      => $cart->id,
            ));

            if ($result) {
                $wpdb->update($table, array(
                    'emails_sent'   => $email_num,
                    'last_email_at' => current_time('mysql'),
                    'updated_at'    => current_time('mysql'),
                ), array('id' => $cart->id));
                $sent++;
            }
        }

        return $sent;
    }

    private function process_training_carts($already_sent) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_abandoned_carts';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return 0;

        $now = current_time('mysql');
        $sent = 0;

        // Training table uses reminder_X_sent columns instead of emails_sent counter
        // Process each tier
        for ($email_num = 1; $email_num <= self::MAX_EMAILS; $email_num++) {
            if (($sent + $already_sent) >= self::BATCH_LIMIT) break;

            $delay = $this->get_delay_for_email($email_num);
            $prev_col = ($email_num > 1) ? "reminder_" . ($email_num - 1) . "_sent" : null;
            $curr_col = "reminder_{$email_num}_sent";

            $where = "recovered = 0 AND {$curr_col} IS NULL";
            $where .= " AND created_at < DATE_SUB('{$now}', INTERVAL {$delay} MINUTE)";
            $where .= " AND created_at > DATE_SUB('{$now}', INTERVAL " . self::MAX_CART_AGE . " MINUTE)";
            if ($prev_col) $where .= " AND {$prev_col} IS NOT NULL";

            $carts = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY created_at ASC LIMIT " . self::BATCH_LIMIT);

            foreach ($carts as $cart) {
                if (($sent + $already_sent) >= self::BATCH_LIMIT) break;
                if ($this->is_in_cooldown($cart->email, 'training_abandoned')) continue;
                
                // v220: Cross-table cooldown — don't spam if camp sequence is also running
                if ($this->is_in_global_cooldown($cart->email)) continue;
                
                // v220: Skip if this person already completed a booking/order
                if ($this->has_recent_booking($cart->email)) {
                    // Auto-recover so we don't check again next cron
                    $wpdb->update($table, array('recovered' => 1), array('id' => $cart->id));
                    continue;
                }

                $trainer = null;
                if ($cart->trainer_id) {
                    $trainer = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $cart->trainer_id
                    ));
                }

                $first_name = '';
                $user = get_user_by('email', $cart->email);
                if ($user && $user->first_name) $first_name = $user->first_name;
                if (!$first_name) $first_name = explode('@', $cart->email)[0];

                $recovery_url = home_url('/ptp-checkout/?' . http_build_query(array_filter(array(
                    'trainer_id' => $cart->trainer_id,
                    'date' => $cart->session_date,
                    'time' => $cart->session_time,
                    'package' => $cart->package_type,
                    'recover' => $cart->id,
                ))));
                $unsub_url = home_url('/?ptp_unsub=' . base64_encode($cart->email));

                // Send SMS on first email if phone available
                $phone = $cart->phone ?? '';
                if (!$phone && $user) {
                    $phone = get_user_meta($user->ID, 'phone', true) ?: get_user_meta($user->ID, 'billing_phone', true);
                }
                if ($email_num === 1 && !empty($phone)) {
                    $trainer_first = $trainer ? explode(' ', $trainer->display_name)[0] : 'your trainer';
                    $this->send_recovery_sms($phone, $first_name, 'training', $recovery_url, $trainer_first);
                }

                $items = array();
                if ($trainer) {
                    $items[] = array(
                        'name' => ($cart->package_type === 'single' ? 'Single Session' : ucfirst($cart->package_type) . ' Package') . ' with ' . $trainer->display_name,
                        'price' => 0,
                        'type' => 'training',
                        'date' => $cart->session_date ? date('M j, Y', strtotime($cart->session_date)) : '',
                        'location' => '',
                        'time' => $cart->session_time ?? '',
                    );
                }

                $result = $this->send_recovery_email(array(
                    'to'           => $cart->email,
                    'first_name'   => $first_name,
                    'email_num'    => $email_num,
                    'cart_type'    => 'training',
                    'items'        => $items,
                    'total'        => 0,
                    'recovery_url' => $recovery_url,
                    'unsub_url'    => $unsub_url,
                    'item_names'   => $trainer ? $trainer->display_name : 'Training Session',
                    'trainer'      => $trainer,
                    'source'       => 'training_abandoned_carts',
                    'cart_id'      => $cart->id,
                ));

                if ($result) {
                    $wpdb->update($table, array(
                        $curr_col => current_time('mysql'),
                    ), array('id' => $cart->id));
                    $sent++;
                }
            }
        }

        return $sent;
    }

    // ================================================================
    // ANTI-SPAM
    // ================================================================

    private function should_send_email($email_num, $minutes_since_abandon) {
        $delay = $this->get_delay_for_email($email_num);
        return $minutes_since_abandon >= $delay;
    }

    private function get_delay_for_email($num) {
        switch ($num) {
            case 1: return self::EMAIL_1_DELAY;
            case 2: return self::EMAIL_2_DELAY;
            case 3: return self::EMAIL_3_DELAY;
            default: return 99999;
        }
    }

    /**
     * Check if this email address has been sent an abandoned cart email recently
     */
    private function is_in_cooldown($email, $type_prefix) {
        global $wpdb;
        $log_table = $wpdb->prefix . 'ptp_email_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$log_table}'") !== $log_table) return false;

        $recent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} 
             WHERE email = %s 
             AND email_type LIKE %s
             AND sent_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $email, $type_prefix . '%', self::GLOBAL_COOLDOWN_DAYS
        ));

        // Allow up to MAX_EMAILS within the cooldown window (for the same cart sequence)
        return intval($recent) >= self::MAX_EMAILS;
    }

    // ================================================================
    // EMAIL SENDING + LOGGING
    // ================================================================

    private function send_recovery_email($args) {
        $to         = $args['to'];
        $first_name = $args['first_name'];
        $email_num  = $args['email_num'];
        $cart_type  = $args['cart_type'];
        $items      = $args['items'];
        $total      = $args['total'];
        $recovery_url = $args['recovery_url'];
        $unsub_url  = $args['unsub_url'];
        $item_names = $args['item_names'];
        $trainer    = $args['trainer'] ?? null;

        // Build subject + content based on cart_type and email_num
        $subject = $this->get_subject($email_num, $cart_type, $first_name, $item_names, $trainer);
        $html = $this->build_html_email($email_num, $cart_type, $first_name, $items, $total, $recovery_url, $unsub_url, $item_names, $trainer);

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
            'Reply-To: ' . ptp_email_brand('from_email'),
        );

        $sent = wp_mail($to, $subject, $html, $headers);

        // Log to ptp_email_logs
        if ($sent) {
            $this->log_email($to, $subject, $email_num, $cart_type, $args['source'], $args['cart_id']);
        }

        return $sent;
    }

    private function log_email($email, $subject, $email_num, $cart_type, $source, $cart_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_email_logs';

        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            PTP_Email_Automation::create_tables();
        }

        $wpdb->insert($table, array(
            'email'      => $email,
            'email_type' => $cart_type . '_abandoned_' . $email_num,
            'subject'    => $subject,
            'metadata'   => json_encode(array(
                'cart_type' => $cart_type,
                'email_num' => $email_num,
                'source'    => $source,
                'cart_id'   => $cart_id,
            )),
            'sent_at'    => current_time('mysql'),
        ));
    }

    // ================================================================
    // SUBJECT LINES
    // ================================================================

    private function get_subject($num, $type, $first_name, $item_names, $trainer = null) {
        $name = $first_name ?: 'there';
        $short_items = strlen($item_names) > 40 ? substr($item_names, 0, 37) . '...' : $item_names;

        if ($type === 'camp' || $type === 'mixed') {
            switch ($num) {
                case 1: return "Still thinking about camp, {$name}?";
                case 2: return "{$short_items} — spots are filling up";
                case 3: return "Last chance — your camp cart expires soon";
            }
        } else {
            $trainer_name = $trainer ? $trainer->display_name : 'your trainer';
            $trainer_first = $trainer ? explode(' ', $trainer->display_name)[0] : 'your trainer';
            switch ($num) {
                case 1: return "Still thinking about training with {$trainer_first}?";
                case 2: return "{$trainer_first}'s schedule is filling up";
                case 3: return "Last chance to book with {$trainer_first}";
            }
        }
        return "Your PTP cart is waiting";
    }

    // ================================================================
    // HTML EMAIL BUILDER
    // ================================================================

    private function build_html_email($num, $type, $first_name, $items, $total, $recovery_url, $unsub_url, $item_names, $trainer = null) {
        $logo_url = 'https://ptpsummercamps.com/wp-content/uploads/2024/ptp-logo-gold.png';
        if (class_exists('PTP_Images') && method_exists('PTP_Images', 'logo')) {
            $logo_url = PTP_Images::logo();
        }

        $name = esc_html($first_name ?: 'there');
        $items_html = $this->build_items_html($items, $type);
        $total_html = $total > 0 ? '$' . number_format($total, 2) : '';

        // Email-specific hero + body
        switch ($num) {
            case 1: $content = $this->email_1_content($name, $type, $items_html, $total_html, $recovery_url, $item_names, $trainer); break;
            case 2: $content = $this->email_2_content($name, $type, $items_html, $total_html, $recovery_url, $item_names, $trainer); break;
            case 3: $content = $this->email_3_content($name, $type, $items_html, $total_html, $recovery_url, $item_names, $trainer); break;
            default: $content = '';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>PTP Soccer</title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;-webkit-text-size-adjust:100%;">
<div style="max-width:600px;margin:0 auto;padding:16px;">

<!-- Header -->
<div style="text-align:center;padding:24px 0 16px;">
<img src="' . esc_url($logo_url) . '" alt="PTP Soccer" style="height:36px;width:auto;" />
</div>

<!-- Card -->
<div style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">
' . $content . '
</div>

<!-- Footer -->
<div style="text-align:center;padding:24px 16px;font-size:12px;color:#9ca3af;line-height:1.5;">
<p style="margin:0 0 4px;">PTP Soccer Camps &middot; Players Teaching Players</p>
<p style="margin:0 0 12px;"><a href="' . esc_url(home_url()) . '" style="color:#9ca3af;">' . esc_html(str_replace(array('https://', 'http://'), '', home_url())) . '</a> &middot; <a href="mailto:' . esc_attr(ptp_email_brand('support_email')) . '" style="color:#9ca3af;">' . esc_html(ptp_email_brand('support_email')) . '</a></p>
<p style="margin:0;"><a href="' . esc_url($unsub_url) . '" style="color:#d1d5db;font-size:11px;">Unsubscribe from these reminders</a></p>
</div>

</div>
</body></html>';
    }

    // ================================================================
    // EMAIL 1: Gentle Reminder (30 min)
    // ================================================================

    private function email_1_content($name, $type, $items_html, $total_html, $url, $item_names, $trainer) {
        $trainer_photo = ($trainer && !empty($trainer->photo_url))
            ? '<img src="' . esc_url($trainer->photo_url) . '" style="width:64px;height:64px;border-radius:50%;border:2px solid #FCB900;object-fit:cover;margin-bottom:12px;" />'
            : '';

        $intro = ($type === 'training' && $trainer)
            ? "You were checking out a session with <strong>" . esc_html($trainer->display_name) . "</strong> — your booking is still saved."
            : "You were checking out <strong>" . esc_html($item_names ?: 'PTP Summer Camp') . "</strong> — your cart is still saved.";

        $differentiator = ($type === 'training')
            ? "Our trainers are current MLS players and D1 athletes who provide 1-on-1 mentorship — the individual attention team coaches don't have time to give."
            : "Our coaches are current MLS players and D1 athletes who actually PLAY with the kids. 8:1 ratio, no lines, no cones — just real soccer with real pros.";

        return '
<!-- Hero -->
<div style="background:#0A0A0A;padding:20px 24px;text-align:center;">
<p style="margin:0;color:#FCB900;font-size:13px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">YOUR CART IS SAVED</p>
</div>
<div style="padding:28px 24px;text-align:center;">
' . $trainer_photo . '
<h1 style="margin:0 0 12px;font-size:22px;color:#0A0A0A;font-weight:700;">Hi ' . $name . '!</h1>
<p style="margin:0 0 20px;color:#6b7280;font-size:15px;line-height:1.5;">' . $intro . '</p>

' . $items_html . '

' . ($total_html ? '<p style="margin:16px 0 0;font-size:14px;color:#6b7280;">Cart total: <strong style="color:#0A0A0A;">' . $total_html . '</strong></p>' : '') . '

<p style="margin:20px 0;color:#6b7280;font-size:14px;line-height:1.5;">' . $differentiator . '</p>

<a href="' . esc_url($url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 36px;border-radius:6px;text-decoration:none;font-weight:700;font-size:15px;letter-spacing:0.3px;">Complete Your Booking</a>

<p style="margin:20px 0 0;color:#9ca3af;font-size:13px;">Questions? Just reply — I read every email.<br><strong>- Luke, PTP Founder</strong></p>
</div>';
    }

    // ================================================================
    // EMAIL 2: Urgency (3 hours)
    // ================================================================

    private function email_2_content($name, $type, $items_html, $total_html, $url, $item_names, $trainer) {
        $headline = ($type === 'training' && $trainer)
            ? esc_html(explode(' ', $trainer->display_name)[0]) . "'s schedule is filling up"
            : "Spots are filling up for " . esc_html($item_names ?: 'PTP Camp');

        $body_text = ($type === 'training')
            ? "Other parents are booking sessions. We keep trainer rosters small so every kid gets real attention — don't miss your preferred time."
            : "We keep groups small on purpose (8 kids per pro coach). Once a week is full, it's full. Other families are signing up right now.";

        return '
<div style="background:linear-gradient(135deg,#0A0A0A 0%,#1a1a2e 100%);padding:28px 24px;text-align:center;">
<p style="margin:0 0 8px;color:#FCB900;font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">SPOTS FILLING UP</p>
<h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">' . $headline . '</h1>
</div>
<div style="padding:28px 24px;text-align:center;">
<p style="margin:0 0 20px;color:#6b7280;font-size:15px;line-height:1.5;">' . $body_text . '</p>

' . $items_html . '

' . ($total_html ? '<p style="margin:16px 0 0;font-size:14px;color:#6b7280;">Your saved total: <strong style="color:#0A0A0A;">' . $total_html . '</strong></p>' : '') . '

<a href="' . esc_url($url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 36px;border-radius:6px;text-decoration:none;font-weight:700;font-size:15px;margin-top:20px;">Grab Your Spot</a>

<p style="margin:20px 0 0;color:#9ca3af;font-size:13px;">Ran into an issue at checkout? Reply and I\'ll help personally.<br><strong>- Luke</strong></p>
</div>';
    }

    // ================================================================
    // EMAIL 3: Last Call (24 hours)
    // ================================================================

    private function email_3_content($name, $type, $items_html, $total_html, $url, $item_names, $trainer) {
        $browse_url = ($type === 'training') ? home_url('/find-trainers/') : home_url('/summer-camps/');
        $browse_label = ($type === 'training') ? 'browse all trainers' : 'see all available weeks';

        return '
<div style="background:#FCB900;padding:20px 24px;text-align:center;">
<p style="margin:0;color:#0A0A0A;font-size:18px;font-weight:700;">Last Chance, ' . $name . '</p>
</div>
<div style="padding:28px 24px;text-align:center;">
<p style="margin:0 0 20px;color:#6b7280;font-size:15px;line-height:1.5;">This is my last note about your saved cart. We\'ve had a lot of sign-ups and I wanted to make sure you didn\'t miss out.</p>

' . $items_html . '

' . ($total_html ? '<p style="margin:16px 0 20px;font-size:14px;color:#6b7280;">Your total: <strong style="color:#0A0A0A;">' . $total_html . '</strong></p>' : '') . '

<a href="' . esc_url($url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 36px;border-radius:6px;text-decoration:none;font-weight:700;font-size:15px;">Complete Booking</a>

<p style="margin:24px 0 0;color:#9ca3af;font-size:13px;line-height:1.5;">If the timing isn\'t right, no worries. You can always <a href="' . esc_url($browse_url) . '" style="color:#FCB900;">' . $browse_label . '</a>.</p>
<p style="margin:8px 0 0;color:#9ca3af;font-size:13px;">Hope to see your player on the field.<br><strong>- Luke, PTP Founder</strong></p>
</div>';
    }

    // ================================================================
    // ITEM CARDS (shared across all emails)
    // ================================================================

    private function build_items_html($items, $type) {
        if (empty($items)) return '';

        $html = '<div style="text-align:left;margin:0 auto;max-width:400px;">';
        foreach ($items as $item) {
            $icon = ($item['type'] === 'training') ? '&#9917;' : '&#9917;';
            $meta_parts = array_filter(array($item['date'], $item['location'], $item['time']));
            $meta = !empty($meta_parts) ? '<span style="display:block;font-size:12px;color:#9ca3af;margin-top:2px;">' . esc_html(implode(' &middot; ', $meta_parts)) . '</span>' : '';
            $price = $item['price'] > 0 ? '<span style="font-weight:700;color:#0A0A0A;white-space:nowrap;">$' . number_format($item['price'], 2) . '</span>' : '';

            $html .= '<div style="display:flex;align-items:flex-start;gap:12px;padding:12px 16px;background:#f9fafb;border-radius:8px;margin-bottom:8px;">';
            $html .= '<div style="flex:1;"><span style="font-size:14px;font-weight:600;color:#0A0A0A;">' . esc_html($item['name']) . '</span>' . $meta . '</div>';
            if ($price) $html .= '<div style="padding-top:2px;">' . $price . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    // ================================================================
    // AJAX: Capture abandoned cart from checkout page
    // ================================================================

    /**
     * Saves cart data when parent enters email on checkout.
     * Called by JS in ptp-checkout.php via action: ptp_camps_capture_email
     */
    public function ajax_capture_email() {
        global $wpdb;

        $email      = sanitize_email($_POST['email'] ?? '');
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
        $phone      = sanitize_text_field($_POST['phone'] ?? '');
        $cart_data  = sanitize_text_field($_POST['cart_data'] ?? '{}');
        $cart_total = floatval($_POST['cart_total'] ?? 0);
        $source     = sanitize_text_field($_POST['source'] ?? 'camps');

        // v220: Proper WordPress email validation
        if (empty($email) || !is_email($email)) {
            wp_send_json_error('Invalid email');
        }
        
        // v220: Don't capture if this email already has a recent booking
        if ($this->has_recent_booking($email)) {
            wp_send_json_success(array('skipped' => true, 'reason' => 'already_booked'));
            return;
        }

        $name = trim("{$first_name} {$last_name}");

        // Detect what's in the cart
        $data = json_decode($cart_data, true);
        $cart_type = $this->detect_cart_type($cart_data);

        // Extract item names for display
        $items = $data['items'] ?? array();
        $item_names = array();
        foreach ($items as $item) {
            $n = $item['name'] ?? $item['title'] ?? '';
            if ($n) $item_names[] = $n;
        }
        $camp_names = implode(', ', $item_names);

        // Route to correct table based on cart type
        if ($source === 'training' || $cart_type === 'training') {
            // Training → ptp_abandoned_carts table
            $table = $wpdb->prefix . 'ptp_abandoned_carts';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $trainer_id = 0;
                foreach ($items as $item) {
                    $tid = $item['trainer_id'] ?? ($item['metadata']['trainer_id'] ?? 0);
                    if ($tid) { $trainer_id = intval($tid); break; }
                }
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE email = %s AND recovered = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                    $email
                ));
                if ($existing) {
                    $wpdb->update($table, array(
                        'cart_data' => $cart_data,
                        'phone'    => $phone,
                    ), array('id' => $existing));
                } else {
                    $wpdb->insert($table, array(
                        'user_id'    => get_current_user_id() ?: null,
                        'email'      => $email,
                        'trainer_id' => $trainer_id,
                        'cart_data'  => $cart_data,
                        'phone'      => $phone,
                    ));
                }
            }
        } else {
            // Camps → ptp_camp_abandoned_carts table
            $table = $wpdb->prefix . 'ptp_camp_abandoned_carts';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE email = %s AND status = 'abandoned' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                    $email
                ));
                if ($existing) {
                    $wpdb->update($table, array(
                        'name'       => $name,
                        'phone'      => $phone,
                        'cart_data'  => $cart_data,
                        'cart_total' => $cart_total,
                        'camp_names' => $camp_names,
                        'updated_at' => current_time('mysql'),
                    ), array('id' => $existing));
                } else {
                    $wpdb->insert($table, array(
                        'email'       => $email,
                        'name'        => $name,
                        'phone'       => $phone,
                        'cart_data'   => $cart_data,
                        'cart_total'  => $cart_total,
                        'camp_names'  => $camp_names,
                        'status'      => 'abandoned',
                        'emails_sent' => 0,
                        'created_at'  => current_time('mysql'),
                        'updated_at'  => current_time('mysql'),
                    ));
                }
            }
        }

        wp_send_json_success();
    }

    // ================================================================
    // CAMP RECOVERY URL BUILDER
    // ================================================================

    /**
     * Build proper recovery URL for camp items.
     * Tries stripe_product_id first (?camp=X), falls back to WP post IDs (?camps=X,Y).
     */
    private function build_camp_recovery_url($items, $data, $cart_id) {
        // Strategy 1: If items have stripe_product_id, use ?camp= for single or cart rebuild for multi
        $stripe_ids = array();
        $post_ids   = array();

        foreach ($items as $item) {
            if (!empty($item['stripe_product_id'])) {
                $stripe_ids[] = $item['stripe_product_id'];
            }
            // Try to get WP post ID from item_id or id
            $item_id = intval($item['id'] ?? 0);
            if ($item_id > 0) {
                // Check if this looks like a post ID (not a cart_key string)
                $post_ids[] = $item_id;
            }
        }

        // Also check raw data for camp_ids
        if (empty($post_ids) && !empty($data['camp_ids'])) {
            $post_ids = array_map('intval', (array)$data['camp_ids']);
        }

        // Single camp with stripe ID → best path
        if (count($stripe_ids) === 1) {
            return home_url('/ptp-checkout/?' . http_build_query(array(
                'camp'    => $stripe_ids[0],
                'recover' => $cart_id,
            )));
        }

        // Multiple camps with post IDs → use ?camps= param
        $post_ids = array_filter($post_ids, function($id) { return $id > 0; });
        if (!empty($post_ids)) {
            return home_url('/ptp-checkout/?' . http_build_query(array(
                'camps'   => implode(',', $post_ids),
                'recover' => $cart_id,
            )));
        }

        // Multiple stripe IDs → add first via ?camp=, rest will need cart
        if (!empty($stripe_ids)) {
            return home_url('/ptp-checkout/?' . http_build_query(array(
                'camp'    => $stripe_ids[0],
                'recover' => $cart_id,
            )));
        }

        // Fallback: just go to summer camps browse page
        return home_url('/summer-camps/');
    }

    // ================================================================
    // SMS RECOVERY (via OpenPhone)
    // ================================================================

    /**
     * Send SMS recovery message alongside email #1.
     * Uses PTP_SMS (OpenPhone) to send a friendly text.
     */
    private function send_recovery_sms($phone, $first_name, $cart_type, $recovery_url, $item_name = '') {
        if (empty($phone)) return false;

        // Clean phone
        $phone = preg_replace('/\D/', '', $phone);
        if (strlen($phone) === 10) $phone = '1' . $phone;
        if (strlen($phone) !== 11) return false;
        $phone = '+' . $phone;

        $name = $first_name ?: 'there';

        if ($cart_type === 'training') {
            $message = "Hey {$name}! Your session with {$item_name} is still waiting. Spots fill fast — finish booking here: {$recovery_url}\n\n- Luke, PTP Soccer";
        } else {
            $short_name = $item_name;
            if (strlen($short_name) > 50) $short_name = substr($short_name, 0, 47) . '...';
            $message = "Hey {$name}! Your PTP camp spot is still saved" . ($short_name ? " ({$short_name})" : '') . ". Complete checkout before it fills: {$recovery_url}\n\n- Luke, PTP Soccer";
        }

        // Use PTP_SMS if available (OpenPhone integration)
        if (class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send')) {
            return PTP_SMS::send($phone, $message);
        }

        return false;
    }

    // ================================================================
    // ADMIN: Stats + Log
    // ================================================================

    public function ajax_stats() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        check_ajax_referer('ptp_admin_nonce');

        global $wpdb;
        $stats = array(
            'camp_abandoned' => 0,
            'camp_recovered' => 0,
            'camp_emails_sent' => 0,
            'training_abandoned' => 0,
            'training_recovered' => 0,
            'training_emails_sent' => 0,
            'recovery_rate' => 0,
            'recovered_revenue' => 0,
        );

        // Camps table
        $ct = $wpdb->prefix . 'ptp_camp_abandoned_carts';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$ct}'") === $ct) {
            $r = $wpdb->get_row("SELECT COUNT(*) cnt, SUM(CASE WHEN status='recovered' THEN 1 ELSE 0 END) rec, SUM(emails_sent) es, SUM(CASE WHEN status='recovered' THEN cart_total ELSE 0 END) rrev FROM {$ct}");
            $stats['camp_abandoned'] = intval($r->cnt);
            $stats['camp_recovered'] = intval($r->rec);
            $stats['camp_emails_sent'] = intval($r->es);
            $stats['recovered_revenue'] += floatval($r->rrev);
        }

        // Training table
        $tt = $wpdb->prefix . 'ptp_abandoned_carts';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tt}'") === $tt) {
            $r = $wpdb->get_row("SELECT COUNT(*) cnt, SUM(recovered) rec, SUM(CASE WHEN reminder_1_sent IS NOT NULL THEN 1 ELSE 0 END) + SUM(CASE WHEN reminder_2_sent IS NOT NULL THEN 1 ELSE 0 END) + SUM(CASE WHEN reminder_3_sent IS NOT NULL THEN 1 ELSE 0 END) es FROM {$tt}");
            $stats['training_abandoned'] = intval($r->cnt);
            $stats['training_recovered'] = intval($r->rec);
            $stats['training_emails_sent'] = intval($r->es);
        }

        $total_abandoned = $stats['camp_abandoned'] + $stats['training_abandoned'];
        $total_recovered = $stats['camp_recovered'] + $stats['training_recovered'];
        $stats['recovery_rate'] = $total_abandoned > 0 ? round(($total_recovered / $total_abandoned) * 100, 1) : 0;

        wp_send_json_success($stats);
    }

    public function ajax_log() {
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        check_ajax_referer('ptp_admin_nonce');

        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = 25;
        $offset = ($page - 1) * $per_page;

        global $wpdb;
        $log_table = $wpdb->prefix . 'ptp_email_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$log_table}'") !== $log_table) {
            wp_send_json_success(array('logs' => array(), 'total' => 0));
            return;
        }

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$log_table} WHERE email_type LIKE '%abandoned%' ORDER BY sent_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$log_table} WHERE email_type LIKE '%abandoned%'");

        wp_send_json_success(array('logs' => $logs, 'total' => intval($total), 'page' => $page, 'per_page' => $per_page));
    }

    // ================================================================
    // ADMIN TAB RENDER (called by Camp Orders Admin)
    // ================================================================

    public static function render_admin_tab() {
        ?>
        <div id="ptp-ac-tab" style="padding:16px 0;">
            <div class="ptp-sr" id="ptp-ac-stats" style="margin-bottom:16px;">
                <div class="ptp-sc"><span class="v" id="ac-total">—</span><span class="l">Total Abandoned</span></div>
                <div class="ptp-sc"><span class="v" id="ac-recovered">—</span><span class="l">Recovered</span></div>
                <div class="ptp-sc"><span class="v" id="ac-rate">—</span><span class="l">Recovery Rate</span></div>
                <div class="ptp-sc"><span class="v" id="ac-revenue">—</span><span class="l">Recovered Rev</span></div>
                <div class="ptp-sc"><span class="v" id="ac-emails">—</span><span class="l">Emails Sent</span></div>
            </div>

            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;">
                    <h3 style="margin:0 0 8px;font-size:14px;color:#0A0A0A;">Camp Carts</h3>
                    <p style="margin:0;font-size:13px;color:#888;">Abandoned: <strong id="ac-camp-a">—</strong> &middot; Recovered: <strong id="ac-camp-r">—</strong> &middot; Emails: <strong id="ac-camp-e">—</strong></p>
                </div>
                <div style="flex:1;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;">
                    <h3 style="margin:0 0 8px;font-size:14px;color:#0A0A0A;">Training Carts</h3>
                    <p style="margin:0;font-size:13px;color:#888;">Abandoned: <strong id="ac-train-a">—</strong> &middot; Recovered: <strong id="ac-train-r">—</strong> &middot; Emails: <strong id="ac-train-e">—</strong></p>
                </div>
            </div>

            <h3 style="margin:0 0 8px;">Email Log</h3>
            <table class="wp-list-table widefat striped" style="font-size:13px;">
                <thead><tr><th>Date</th><th>Email</th><th>Type</th><th>Subject</th></tr></thead>
                <tbody id="ptp-ac-log-body"><tr><td colspan="4" style="text-align:center;padding:24px;color:#999;">Loading...</td></tr></tbody>
            </table>
            <div id="ptp-ac-log-nav" style="margin-top:8px;"></div>
        </div>

        <script>
        jQuery(function($){
            function loadStats(){
                $.post(ptpAdminCamp.ajaxUrl,{action:'ptp_abandoned_cart_stats',_ajax_nonce:ptpAdminCamp.nonce},function(r){
                    if(!r.success)return;
                    var d=r.data;
                    $('#ac-total').text((d.camp_abandoned||0)+(d.training_abandoned||0));
                    $('#ac-recovered').text((d.camp_recovered||0)+(d.training_recovered||0));
                    $('#ac-rate').text((d.recovery_rate||0)+'%');
                    $('#ac-revenue').text('$'+Math.round(d.recovered_revenue||0).toLocaleString());
                    $('#ac-emails').text((d.camp_emails_sent||0)+(d.training_emails_sent||0));
                    $('#ac-camp-a').text(d.camp_abandoned||0);$('#ac-camp-r').text(d.camp_recovered||0);$('#ac-camp-e').text(d.camp_emails_sent||0);
                    $('#ac-train-a').text(d.training_abandoned||0);$('#ac-train-r').text(d.training_recovered||0);$('#ac-train-e').text(d.training_emails_sent||0);
                });
            }
            function loadLog(pg){
                $.post(ptpAdminCamp.ajaxUrl,{action:'ptp_abandoned_cart_log',_ajax_nonce:ptpAdminCamp.nonce,page:pg||1},function(r){
                    if(!r.success)return;
                    var d=r.data,logs=d.logs||[],html='';
                    if(!logs.length){html='<tr><td colspan="4" style="text-align:center;padding:24px;color:#999;">No emails sent yet.</td></tr>';}
                    else{
                        logs.forEach(function(l){
                            var meta=JSON.parse(l.metadata||'{}');
                            var badge=meta.cart_type==='camp'?'<span style="background:#3b82f6;color:#fff;padding:2px 6px;border-radius:3px;font-size:10px;">Camp</span>':'<span style="background:#8b5cf6;color:#fff;padding:2px 6px;border-radius:3px;font-size:10px;">Training</span>';
                            html+='<tr><td>'+l.sent_at+'</td><td>'+l.email+'</td><td>'+badge+' #'+(meta.email_num||'?')+'</td><td>'+l.subject+'</td></tr>';
                        });
                    }
                    $('#ptp-ac-log-body').html(html);
                    var pages=Math.ceil((d.total||0)/(d.per_page||25)),nav='';
                    for(var i=1;i<=Math.min(pages,10);i++){nav+=(i===d.page?'<strong>'+i+'</strong>':'<a href="#" class="ac-page" data-p="'+i+'">'+i+'</a>')+' ';}
                    $('#ptp-ac-log-nav').html(nav);
                });
            }
            $(document).on('click','.ac-page',function(e){e.preventDefault();loadLog($(this).data('p'));});
            loadStats();loadLog(1);
        });
        </script>
        <?php
    }
}
