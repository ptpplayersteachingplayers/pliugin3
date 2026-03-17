<?php
/**
 * PTP Camp Admin - WordPress admin management for camps
 * 
 * Handles admin UI for camp orders, products, and settings.
 * Replaces PTP Native product/order admin.
 * 
 * @version 146.0.0
 * @since 146.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Admin {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Admin menus
        add_action('admin_menu', array($this, 'add_admin_menus'));
        
        // Admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        // Note: ptp_sync_stripe_products is handled by PTP_Stripe_Products class
        add_action('wp_ajax_ptp_update_camp_order_status', array($this, 'ajax_update_order_status'));
        add_action('wp_ajax_ptp_refund_camp_order', array($this, 'ajax_refund_order'));
        add_action('wp_ajax_ptp_export_camp_orders', array($this, 'ajax_export_orders'));
        add_action('wp_ajax_ptp_get_camp_stats', array($this, 'ajax_get_stats'));
    }
    
    /**
     * Add admin menus
     */
    public function add_admin_menus() {
        // Camp Orders submenu under PTP
        add_submenu_page(
            'ptp-dashboard',
            'Camp Orders',
            'Camp Orders',
            'manage_options',
            'ptp-camp-orders',
            array($this, 'render_orders_page')
        );
        
        // Camp Products/Settings
        add_submenu_page(
            'ptp-dashboard',
            'Camp Products',
            'Camp Products',
            'manage_options',
            'ptp-camp-products',
            array($this, 'render_products_page')
        );
        
        // Referral Codes
        add_submenu_page(
            'ptp-dashboard',
            'Referral Codes',
            'Referral Codes',
            'manage_options',
            'ptp-referrals',
            array($this, 'render_referrals_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'ptp-camp') === false && strpos($hook, 'ptp-referrals') === false) {
            return;
        }
        
        wp_enqueue_style('ptp-camp-admin', PTP_PLUGIN_URL . 'assets/css/camp-admin.css', array(), PTP_VERSION);
        wp_enqueue_script('ptp-camp-admin', PTP_PLUGIN_URL . 'assets/js/camp-admin.js', array('jquery'), PTP_VERSION, true);
        
        wp_localize_script('ptp-camp-admin', 'ptpCampAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_admin_nonce'),
        ));
    }
    
    /**
     * Render orders page
     */
    public function render_orders_page() {
        global $wpdb;
        
        // Get filter params
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;
        
        // Build query
        $where = array('1=1');
        if ($status) {
            $where[] = $wpdb->prepare("status = %s", $status);
        }
        if ($search) {
            $where[] = $wpdb->prepare(
                "(order_number LIKE %s OR billing_email LIKE %s OR billing_first_name LIKE %s OR billing_last_name LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        $where_sql = implode(' AND ', $where);
        
        // Get orders
        $orders = $wpdb->get_results($wpdb->prepare("
            SELECT o.*, 
                   (SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = o.id) as item_count,
                   (SELECT GROUP_CONCAT(CONCAT(camper_first_name, ' ', camper_last_name) SEPARATOR ', ') 
                    FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = o.id) as campers
            FROM {$wpdb->prefix}ptp_unified_camp_orders o
            WHERE {$where_sql}
            ORDER BY o.created_at DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE $where_sql");
        $total_pages = ceil($total / $per_page);
        
        // Get stats
        $stats = $this->get_order_stats();
        
        include PTP_PLUGIN_DIR . 'admin/views/camp-orders.php';
    }
    
    /**
     * Render products page
     */
    public function render_products_page() {
        global $wpdb;
        
        $products = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ptp_stripe_products
            WHERE product_type IN ('camp', 'clinic')
            ORDER BY sort_order ASC, name ASC
        ");
        
        include PTP_PLUGIN_DIR . 'admin/views/camp-products.php';
    }
    
    /**
     * Render referrals page
     */
    public function render_referrals_page() {
        global $wpdb;
        
        $referrals = $wpdb->get_results("
            SELECT r.*, o.billing_first_name, o.billing_last_name, o.billing_email as order_email
            FROM {$wpdb->prefix}ptp_camp_referrals r
            LEFT JOIN {$wpdb->prefix}ptp_unified_camp_orders o ON r.order_id = o.id
            ORDER BY r.created_at DESC
            LIMIT 100
        ");
        
        include PTP_PLUGIN_DIR . 'admin/views/camp-referrals.php';
    }
    
    /**
     * Get order stats
     */
    private function get_order_stats() {
        global $wpdb;
        
        $today = date('Y-m-d');
        $this_month = date('Y-m-01');
        $this_year = date('Y-01-01');
        
        return array(
            'total_orders' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE status = 'completed'"),
            'total_revenue' => $wpdb->get_var("SELECT SUM(total_amount) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE status = 'completed'") ?: 0,
            'today_orders' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE status = 'completed' AND DATE(created_at) = %s",
                $today
            )),
            'today_revenue' => $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(total_amount) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE status = 'completed' AND DATE(created_at) = %s",
                $today
            )) ?: 0,
            'month_orders' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE status = 'completed' AND created_at >= %s",
                $this_month
            )),
            'month_revenue' => $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(total_amount) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE status = 'completed' AND created_at >= %s",
                $this_month
            )) ?: 0,
            'pending_orders' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE status = 'pending'"),
            'total_campers' => $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_order_items oi
                JOIN {$wpdb->prefix}ptp_unified_camp_orders o ON oi.order_id = o.id
                WHERE o.status = 'completed'
            "),
        );
    }
    
    /**
     * AJAX: Sync products from Stripe
     */
    public function ajax_sync_products() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $result = PTP_Camp_Checkout::sync_products_from_stripe();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => "Successfully synced {$result['synced']} products from Stripe",
            'synced' => $result['synced'],
        ));
    }
    
    /**
     * AJAX: Update order status
     */
    public function ajax_update_order_status() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        
        if (!$order_id || !$status) {
            wp_send_json_error(array('message' => 'Missing required fields'));
        }
        
        $valid_statuses = array('pending', 'processing', 'completed', 'cancelled', 'refunded');
        if (!in_array($status, $valid_statuses)) {
            wp_send_json_error(array('message' => 'Invalid status'));
        }
        
        PTP_Camp_Orders::update_order_status($order_id, $status);
        
        wp_send_json_success(array('message' => 'Order status updated'));
    }
    
    /**
     * AJAX: Refund order
     */
    public function ajax_refund_order() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Missing order ID'));
        }
        
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
        }
        
        if (empty($order->stripe_payment_intent_id)) {
            wp_send_json_error(array('message' => 'No payment found for this order'));
        }
        
        // Process refund via Stripe
        $refund_amount = $amount ?: $order->total_amount;
        $refund = PTP_Stripe::create_refund($order->stripe_payment_intent_id, $refund_amount * 100, $reason ?: 'requested_by_customer');
        
        if (is_wp_error($refund)) {
            wp_send_json_error(array('message' => $refund->get_error_message()));
        }
        
        // Update order
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_unified_camp_orders',
            array(
                'status' => $refund_amount >= $order->total_amount ? 'refunded' : 'completed',
                'payment_status' => $refund_amount >= $order->total_amount ? 'refunded' : 'partially_refunded',
                'refund_amount' => $refund_amount,
                'refund_reason' => $reason,
                'refunded_at' => current_time('mysql'),
            ),
            array('id' => $order_id)
        );
        
        // Send cancellation email
        PTP_Camp_Emails::send_cancellation_email($order_id, $refund_amount);
        
        wp_send_json_success(array(
            'message' => 'Refund processed successfully',
            'refund_amount' => $refund_amount,
        ));
    }
    
    /**
     * AJAX: Export orders
     */
    public function ajax_export_orders() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        global $wpdb;
        
        $format = sanitize_text_field($_POST['format'] ?? 'csv');
        $status = sanitize_text_field($_POST['status'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        
        $where = array('1=1');
        if ($status) {
            $where[] = $wpdb->prepare("o.status = %s", $status);
        }
        if ($date_from) {
            $where[] = $wpdb->prepare("o.created_at >= %s", $date_from . ' 00:00:00');
        }
        if ($date_to) {
            $where[] = $wpdb->prepare("o.created_at <= %s", $date_to . ' 23:59:59');
        }
        $where_sql = implode(' AND ', $where);
        
        $orders = $wpdb->get_results("
            SELECT o.*, oi.*
            FROM {$wpdb->prefix}ptp_unified_camp_orders o
            JOIN {$wpdb->prefix}ptp_camp_order_items oi ON o.id = oi.order_id
            WHERE $where_sql
            ORDER BY o.created_at DESC
        ");
        
        // Build CSV
        $csv_data = array();
        $csv_data[] = array(
            'Order Number', 'Order Date', 'Status', 'Parent Name', 'Email', 'Phone',
            'Camper First Name', 'Camper Last Name', 'Camper Age', 'Camp Name', 'Camp Dates',
            'Shirt Size', 'Skill Level', 'Team', 'Medical Conditions',
            'Base Price', 'Final Price', 'Order Total'
        );
        
        foreach ($orders as $row) {
            $csv_data[] = array(
                $row->order_number,
                $row->created_at,
                $row->status,
                $row->billing_first_name . ' ' . $row->billing_last_name,
                $row->billing_email,
                $row->billing_phone,
                $row->camper_first_name,
                $row->camper_last_name,
                $row->camper_age,
                $row->camp_name,
                $row->camp_dates,
                $row->camper_shirt_size,
                $row->camper_skill_level,
                $row->camper_team,
                $row->medical_conditions,
                $row->base_price,
                $row->final_price,
                $row->total_amount,
            );
        }
        
        // Generate CSV string
        $output = fopen('php://temp', 'r+');
        foreach ($csv_data as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv_string = stream_get_contents($output);
        fclose($output);
        
        wp_send_json_success(array(
            'csv' => $csv_string,
            'filename' => 'ptp-camp-orders-' . date('Y-m-d') . '.csv',
        ));
    }
    
    /**
     * AJAX: Get stats for dashboard
     */
    public function ajax_get_stats() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $stats = $this->get_order_stats();
        wp_send_json_success($stats);
    }
}

// Initialize
PTP_Camp_Admin::instance();
