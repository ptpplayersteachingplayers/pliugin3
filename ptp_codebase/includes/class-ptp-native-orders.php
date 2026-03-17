<?php
/**
 * PTP Native Order Manager
 *
 * Replaces PTP Native orders with native implementation.
 * Handles training session orders and camp registrations.
 *
 * @version 1.0.0
 * @since 148.0.0
 */

defined('ABSPATH') || exit;

class PTP_Native_Order_Manager {

    /** @var string */
    private $table_orders;
    private $table_items;
    private $table_meta;

    /** @var PTP_Native_Order_Manager */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_orders = $wpdb->prefix . 'ptp_native_orders';
        $this->table_items = $wpdb->prefix . 'ptp_native_order_items';
        $this->table_meta = $wpdb->prefix . 'ptp_native_order_meta';
    }

    /**
     * Create new order
     * Replaces PTP_Native_Order_Manager::create_order()
     *
     * @param array $args Order arguments
     * @return PTP_Native_Order|WP_Error
     */
    public function create_order($args = array()) {
        global $wpdb;

        $defaults = array(
            'user_id' => get_current_user_id(),
            'parent_id' => 0,
            'status' => 'pending',
            'payment_method' => 'stripe',
            'billing_first_name' => '',
            'billing_last_name' => '',
            'billing_email' => '',
            'billing_phone' => '',
            'billing_address' => '',
            'notes' => '',
            'referral_code' => '',
        );

        $args = wp_parse_args($args, $defaults);

        // Generate order number
        $order_number = $this->generate_order_number();

        $result = $wpdb->insert(
            $this->table_orders,
            array(
                'order_number' => $order_number,
                'user_id' => $args['user_id'],
                'parent_id' => $args['parent_id'],
                'status' => $args['status'],
                'payment_method' => $args['payment_method'],
                'billing_first_name' => $args['billing_first_name'],
                'billing_last_name' => $args['billing_last_name'],
                'billing_email' => $args['billing_email'],
                'billing_phone' => $args['billing_phone'],
                'billing_address' => $args['billing_address'],
                'notes' => $args['notes'],
                'referral_code' => $args['referral_code'],
                'ip_address' => $this->get_client_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('order_creation_failed', 'Failed to create order: ' . $wpdb->last_error);
        }

        $order_id = $wpdb->insert_id;

        do_action('ptp_order_created', $order_id, $args);

        return new PTP_Native_Order($order_id);
    }

    /**
     * Create order from cart
     * Common use case - creates order with all cart items
     */
    public function create_order_from_cart($billing_data = array()) {
        if (!function_exists('ptp_cart')) {
            return new WP_Error('cart_not_available', 'Cart system is not available');
        }

        $cart = ptp_cart();

        if ($cart->is_empty()) {
            return new WP_Error('empty_cart', 'Cannot create order from empty cart');
        }

        // Create order
        $order = $this->create_order($billing_data);

        if (is_wp_error($order)) {
            return $order;
        }

        // Add cart items to order
        foreach ($cart->get_cart() as $cart_key => $item) {
            $order->add_item(array(
                'item_type' => $item['item_type'],
                'item_reference_id' => $item['item_id'],
                'name' => $this->get_item_name($item),
                'quantity' => $item['quantity'],
                'unit_price' => $item['price'],
                'total' => $item['line_total'],
                'metadata' => $item['metadata'],
            ));
        }

        // Set totals
        $order->set_subtotal($cart->get_subtotal());
        $order->set_total($cart->get_total('edit'));

        // Save
        $order->save();

        return $order;
    }

    /**
     * Get item name from cart item
     */
    private function get_item_name($item) {
        switch ($item['item_type']) {
            case 'training':
                $trainer_id = $item['item_id'];
                global $wpdb;
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d OR id = %d",
                    $trainer_id, $trainer_id
                ));
                $date = $item['metadata']['session_date'] ?? '';
                return 'Training with ' . ($trainer ? $trainer->display_name : 'Trainer') . ($date ? ' on ' . $date : '');

            case 'camp':
                return get_the_title($item['item_id']) ?: 'Camp Registration';

            case 'package':
                return $item['metadata']['name'] ?? 'Training Package';

            case 'addon':
                return $item['metadata']['name'] ?? 'Add-on';

            case 'product':
                return $item['metadata']['name'] ?? get_the_title($item['item_id']) ?: 'Product';

            default:
                return 'Order Item';
        }
    }

    /**
     * Get order by ID
     * Replaces PTP_Native_Order_Manager::get_order()
     *
     * @param int $order_id
     * @return PTP_Native_Order|false
     */
    public function get_order($order_id) {
        global $wpdb;

        // Handle string order numbers
        if (!is_numeric($order_id)) {
            return $this->get_order_by_number($order_id);
        }

        // Check if order exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$this->table_orders} WHERE order_id = %d",
            $order_id
        ));

        if (!$exists) {
            return false;
        }

        return new PTP_Native_Order($order_id);
    }

    /**
     * Get order by order number
     */
    public function get_order_by_number($order_number) {
        global $wpdb;

        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$this->table_orders} WHERE order_number = %s",
            $order_number
        ));

        if (!$order_id) {
            return false;
        }

        return new PTP_Native_Order($order_id);
    }

    /**
     * Get orders with filters
     * Replaces PTP_Native_Order_Manager::get_orders()
     */
    public function get_orders($args = array()) {
        global $wpdb;

        $defaults = array(
            'user_id' => 0,
            'customer_id' => 0, // Alias for user_id (WC compat)
            'parent_id' => 0,
            'status' => '',
            'payment_status' => '',
            'limit' => 20,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'return' => 'objects', // 'objects' or 'ids'
        );

        $args = wp_parse_args($args, $defaults);

        // WC compatibility - customer_id maps to user_id
        if ($args['customer_id'] && !$args['user_id']) {
            $args['user_id'] = $args['customer_id'];
        }

        $where = array('1=1');
        $values = array();

        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }

        if ($args['parent_id']) {
            $where[] = 'parent_id = %d';
            $values[] = $args['parent_id'];
        }

        if ($args['status']) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "status IN ($placeholders)";
                $values = array_merge($values, $args['status']);
            } else {
                $where[] = 'status = %s';
                $values[] = $args['status'];
            }
        }

        if ($args['payment_status']) {
            $where[] = 'payment_status = %s';
            $values[] = $args['payment_status'];
        }

        $where_sql = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']) ?: 'created_at DESC';

        $sql = "SELECT order_id FROM {$this->table_orders} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $values[] = $args['limit'];
        $values[] = $args['offset'];

        if (!empty($values)) {
            $order_ids = $wpdb->get_col($wpdb->prepare($sql, $values));
        } else {
            $order_ids = $wpdb->get_col($sql);
        }

        if ($args['return'] === 'ids') {
            return $order_ids;
        }

        $orders = array();
        foreach ($order_ids as $order_id) {
            $orders[] = new PTP_Native_Order($order_id);
        }

        return $orders;
    }

    /**
     * Get customer orders (WC compatibility wrapper)
     * 
     * @param array $args Arguments including 'customer' for user ID
     * @return array Array of order objects
     */
    public function get_customer_orders($args = array()) {
        // Map WC-style 'customer' param to our 'user_id'
        if (isset($args['customer'])) {
            $args['user_id'] = $args['customer'];
            unset($args['customer']);
        }
        return $this->get_orders($args);
    }

    /**
     * Generate unique order number
     */
    private function generate_order_number() {
        $prefix = 'PTP';
        $timestamp = time();
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));

        return $prefix . '-' . $timestamp . '-' . $random;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip = '';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return sanitize_text_field($ip);
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Orders table
        $sql_orders = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_orders (
            order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(32) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            parent_id BIGINT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'pending',
            subtotal DECIMAL(10,2) DEFAULT 0,
            discount DECIMAL(10,2) DEFAULT 0,
            tax DECIMAL(10,2) DEFAULT 0,
            total DECIMAL(10,2) DEFAULT 0,
            payment_method VARCHAR(50),
            payment_status VARCHAR(20) DEFAULT 'pending',
            stripe_payment_intent VARCHAR(100),
            stripe_charge_id VARCHAR(100),
            billing_first_name VARCHAR(100),
            billing_last_name VARCHAR(100),
            billing_email VARCHAR(200),
            billing_phone VARCHAR(50),
            billing_address TEXT,
            notes TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            referral_code VARCHAR(50),
            metadata JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME,
            UNIQUE KEY order_number (order_number),
            KEY user_id (user_id),
            KEY parent_id (parent_id),
            KEY status (status),
            KEY payment_status (payment_status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Order items table
        $sql_items = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_order_items (
            item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            item_reference_id BIGINT UNSIGNED,
            name VARCHAR(255) NOT NULL,
            quantity INT UNSIGNED DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL,
            total DECIMAL(10,2) NOT NULL,
            metadata JSON,
            KEY order_id (order_id),
            KEY item_type (item_type)
        ) {$charset_collate};";

        // Order meta table
        $sql_meta = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_native_order_meta (
            meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT,
            KEY order_id (order_id),
            KEY order_key (order_id, meta_key)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_orders);
        dbDelta($sql_items);
        dbDelta($sql_meta);
    }
}

/**
 * PTP Native Order Object
 * Replaces PTP_Order
 */
class PTP_Native_Order {

    /** @var int */
    private $id;

    /** @var object */
    private $data;

    /** @var array */
    private $items = null;

    /** @var array */
    private $changes = array();

    /** @var bool */
    private $object_read = false;

    /** @var string */
    private $table_orders;
    private $table_items;
    private $table_meta;

    /**
     * Constructor
     */
    public function __construct($order_id = 0) {
        global $wpdb;
        $this->table_orders = $wpdb->prefix . 'ptp_native_orders';
        $this->table_items = $wpdb->prefix . 'ptp_native_order_items';
        $this->table_meta = $wpdb->prefix . 'ptp_native_order_meta';

        $this->id = (int) $order_id;

        if ($this->id > 0) {
            $this->read();
        }
    }

    /**
     * Read order from database
     */
    private function read() {
        global $wpdb;

        $this->data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_orders} WHERE order_id = %d",
            $this->id
        ));

        $this->object_read = ($this->data !== null);
    }

    /**
     * Check if order exists
     */
    public function exists() {
        return $this->object_read && $this->data !== null;
    }

    /**
     * Get order ID
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get order number
     */
    public function get_order_number() {
        return $this->data->order_number ?? '';
    }

    /**
     * Get status
     */
    public function get_status() {
        $status = $this->data->status ?? 'pending';
        // Remove 'wc-' prefix if present (WC compatibility)
        return str_replace('wc-', '', $status);
    }

    /**
     * Set status
     */
    public function set_status($status) {
        // Remove 'wc-' prefix if present
        $status = str_replace('wc-', '', $status);
        $this->changes['status'] = $status;
        return $this;
    }

    /**
     * Get total
     */
    public function get_total() {
        return (float) ($this->data->total ?? 0);
    }

    /**
     * Set total
     */
    public function set_total($total) {
        $this->changes['total'] = (float) $total;
        return $this;
    }

    /**
     * Get subtotal
     */
    public function get_subtotal() {
        return (float) ($this->data->subtotal ?? 0);
    }

    /**
     * Set subtotal
     */
    public function set_subtotal($subtotal) {
        $this->changes['subtotal'] = (float) $subtotal;
        return $this;
    }

    /**
     * Get discount
     */
    public function get_discount_total() {
        return (float) ($this->data->discount ?? 0);
    }

    /**
     * Set discount
     */
    public function set_discount_total($discount) {
        $this->changes['discount'] = (float) $discount;
        return $this;
    }

    // Billing getters
    public function get_billing_first_name() { return $this->data->billing_first_name ?? ''; }
    public function get_billing_last_name() { return $this->data->billing_last_name ?? ''; }
    public function get_billing_email() { return $this->data->billing_email ?? ''; }
    public function get_billing_phone() { return $this->data->billing_phone ?? ''; }
    public function get_billing_address_1() { return $this->data->billing_address ?? ''; }
    public function get_billing_address_2() { return ''; }
    public function get_billing_city() { return ''; }
    public function get_billing_state() { return ''; }
    public function get_billing_postcode() { return ''; }
    public function get_billing_country() { return 'US'; }

    // Billing setters
    public function set_billing_first_name($value) { $this->changes['billing_first_name'] = sanitize_text_field($value); return $this; }
    public function set_billing_last_name($value) { $this->changes['billing_last_name'] = sanitize_text_field($value); return $this; }
    public function set_billing_email($value) { $this->changes['billing_email'] = sanitize_email($value); return $this; }
    public function set_billing_phone($value) { $this->changes['billing_phone'] = sanitize_text_field($value); return $this; }
    public function set_billing_address_1($value) { $this->changes['billing_address'] = sanitize_text_field($value); return $this; }
    public function set_billing_address_2($value) { return $this; }
    public function set_billing_city($value) { return $this; }
    public function set_billing_state($value) { return $this; }
    public function set_billing_postcode($value) { return $this; }
    public function set_billing_country($value) { return $this; }

    // Payment getters/setters
    public function get_payment_method() { return $this->data->payment_method ?? ''; }
    public function get_payment_method_title() { return ucfirst($this->get_payment_method()); }
    public function get_transaction_id() { return $this->data->stripe_payment_intent ?? ''; }

    public function set_payment_method($value) { $this->changes['payment_method'] = $value; return $this; }
    public function set_payment_method_title($value) { return $this; }
    public function set_transaction_id($value) { $this->changes['stripe_payment_intent'] = $value; return $this; }

    /**
     * Get customer/user ID
     */
    public function get_user_id() {
        return (int) ($this->data->user_id ?? 0);
    }

    public function get_customer_id() {
        return $this->get_user_id();
    }

    public function set_customer_id($value) {
        $this->changes['user_id'] = (int) $value;
        return $this;
    }

    /**
     * Get formatted billing name
     */
    public function get_formatted_billing_full_name() {
        return trim($this->get_billing_first_name() . ' ' . $this->get_billing_last_name());
    }

    /**
     * Get order items
     */
    public function get_items($type = '') {
        if ($this->items === null) {
            global $wpdb;

            $sql = "SELECT * FROM {$this->table_items} WHERE order_id = %d";
            $params = array($this->id);

            if ($type) {
                $sql .= " AND item_type = %s";
                $params[] = $type;
            }

            $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

            $this->items = array();
            foreach ($rows as $row) {
                $this->items[$row->item_id] = (object) array(
                    'item_id' => $row->item_id,
                    'order_id' => $row->order_id,
                    'item_type' => $row->item_type,
                    'item_reference_id' => $row->item_reference_id,
                    'name' => $row->name,
                    'quantity' => (int) $row->quantity,
                    'unit_price' => (float) $row->unit_price,
                    'total' => (float) $row->total,
                    'metadata' => json_decode($row->metadata, true) ?: array(),
                );
            }
        }

        return $this->items;
    }

    /**
     * Add item to order
     */
    public function add_item($item_data) {
        global $wpdb;

        $wpdb->insert(
            $this->table_items,
            array(
                'order_id' => $this->id,
                'item_type' => $item_data['item_type'] ?? 'training',
                'item_reference_id' => $item_data['item_reference_id'] ?? 0,
                'name' => $item_data['name'] ?? '',
                'quantity' => $item_data['quantity'] ?? 1,
                'unit_price' => $item_data['unit_price'] ?? 0,
                'total' => $item_data['total'] ?? 0,
                'metadata' => json_encode($item_data['metadata'] ?? array()),
            ),
            array('%d', '%s', '%d', '%s', '%d', '%f', '%f', '%s')
        );

        $this->items = null; // Reset cache

        return $wpdb->insert_id;
    }

    /**
     * Add product (WC compatibility)
     */
    public function add_product($product, $quantity = 1, $args = array()) {
        // This is for WC compatibility - convert to our format
        if (is_object($product) && method_exists($product, 'get_id')) {
            $product_id = $product->get_id();
            $price = method_exists($product, 'get_price') ? $product->get_price() : 0;
            $name = method_exists($product, 'get_name') ? $product->get_name() : '';
        } else {
            $product_id = (int) $product;
            $price = (float) get_post_meta($product_id, '_price', true);
            $name = get_the_title($product_id);
        }

        $total = (float) ($args['total'] ?? ($price * $quantity));

        return $this->add_item(array(
            'item_type' => 'product',
            'item_reference_id' => $product_id,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price' => $price,
            'total' => $total,
            'metadata' => $args,
        ));
    }

    /**
     * Calculate totals
     */
    public function calculate_totals($and_taxes = false) {
        $items = $this->get_items();
        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += (float) $item->total;
        }

        $this->set_subtotal($subtotal);
        $this->set_total($subtotal - $this->get_discount_total());

        return $this;
    }

    /**
     * Mark payment complete
     */
    public function payment_complete($transaction_id = '') {
        if ($transaction_id) {
            $this->set_transaction_id($transaction_id);
        }

        $this->changes['payment_status'] = 'paid';
        $this->changes['status'] = 'completed';
        $this->changes['completed_at'] = current_time('mysql');

        $this->save();

        do_action('ptp_order_payment_complete', $this->id, $transaction_id);
        do_action('ptp_payment_complete', $this->id);

        return true;
    }

    /**
     * Update status
     */
    public function update_status($new_status, $note = '') {
        $old_status = $this->get_status();
        $this->set_status($new_status);

        if ($note) {
            $this->add_order_note($note);
        }

        $this->save();

        do_action('ptp_order_status_changed', $this->id, $old_status, $new_status);

        return true;
    }

    /**
     * Add order note
     */
    public function add_order_note($note, $is_customer_note = false, $added_by_user = false) {
        global $wpdb;

        $notes = $this->get_meta('_order_notes');
        if (!is_array($notes)) {
            $notes = array();
        }

        $notes[] = array(
            'note' => $note,
            'customer_note' => $is_customer_note,
            'date' => current_time('mysql'),
            'added_by' => $added_by_user ? get_current_user_id() : 0,
        );

        $this->update_meta_data('_order_notes', $notes);

        return true;
    }

    /**
     * Get meta value
     */
    public function get_meta($key, $single = true) {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$this->table_meta}
             WHERE order_id = %d AND meta_key = %s",
            $this->id, $key
        ));

        return $result ? maybe_unserialize($result) : ($single ? '' : array());
    }

    /**
     * Add/update meta
     */
    public function update_meta_data($key, $value) {
        global $wpdb;

        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$this->table_meta}
             WHERE order_id = %d AND meta_key = %s",
            $this->id, $key
        ));

        if ($existing) {
            $wpdb->update(
                $this->table_meta,
                array('meta_value' => maybe_serialize($value)),
                array('meta_id' => $existing),
                array('%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $this->table_meta,
                array(
                    'order_id' => $this->id,
                    'meta_key' => $key,
                    'meta_value' => maybe_serialize($value),
                ),
                array('%d', '%s', '%s')
            );
        }

        return true;
    }

    /**
     * Alias for update_meta_data
     */
    public function add_meta_data($key, $value, $unique = true) {
        return $this->update_meta_data($key, $value);
    }

    /**
     * Delete meta
     */
    public function delete_meta_data($key) {
        global $wpdb;

        return $wpdb->delete(
            $this->table_meta,
            array('order_id' => $this->id, 'meta_key' => $key),
            array('%d', '%s')
        );
    }

    /**
     * Save order changes
     */
    public function save() {
        if (empty($this->changes) && $this->id > 0) {
            return true;
        }

        global $wpdb;

        $this->changes['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $this->table_orders,
            $this->changes,
            array('order_id' => $this->id),
            null,
            array('%d')
        );

        // Merge changes into data
        if ($this->data) {
            foreach ($this->changes as $key => $value) {
                $this->data->$key = $value;
            }
        }

        $this->changes = array();

        do_action('ptp_order_saved', $this->id);

        return $result !== false;
    }

    /**
     * Get checkout order received URL
     */
    public function get_checkout_order_received_url() {
        $thank_you_page = get_page_by_path('thank-you');

        if ($thank_you_page) {
            return add_query_arg(array(
                'order' => $this->get_order_number(),
                'key' => wp_create_nonce('ptp_order_' . $this->id),
            ), get_permalink($thank_you_page));
        }

        return home_url('/thank-you/?order=' . $this->get_order_number());
    }

    /**
     * Get view order URL
     */
    public function get_view_order_url() {
        return $this->get_checkout_order_received_url();
    }

    /**
     * Get date created
     */
    public function get_date_created() {
        $date = $this->data->created_at ?? '';
        if ($date) {
            return new DateTime($date);
        }
        return null;
    }

    /**
     * Get formatted date
     */
    public function get_date_created_formatted() {
        $date = $this->get_date_created();
        return $date ? $date->format('F j, Y g:i A') : '';
    }

    /**
     * Check if order is paid
     */
    public function is_paid() {
        return in_array($this->data->payment_status ?? '', array('paid', 'completed'));
    }

    /**
     * Check if order needs payment
     */
    public function needs_payment() {
        return $this->get_total() > 0 && !$this->is_paid() && in_array($this->get_status(), array('pending', 'on-hold', 'failed'));
    }

    /**
     * Get currency
     */
    public function get_currency() {
        return 'USD';
    }

    /**
     * Get formatted total
     */
    public function get_formatted_order_total() {
        return ptp_format_price($this->get_total());
    }
}

/**
 * Global helper functions
 */
function ptp_orders() {
    return PTP_Native_Order_Manager::instance();
}

function ptp_create_order($args = array()) {
    return PTP_Native_Order_Manager::instance()->create_order($args);
}

function ptp_get_order($order_id) {
    return PTP_Native_Order_Manager::instance()->get_order($order_id);
}

function ptp_get_orders($args = array()) {
    return PTP_Native_Order_Manager::instance()->get_orders($args);
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Native_Order_Manager::instance();
}, 7);
