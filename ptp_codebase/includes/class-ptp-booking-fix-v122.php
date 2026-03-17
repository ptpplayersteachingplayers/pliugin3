<?php
/**
 * PTP Booking Fix v122
 * 
 * Fixes critical issues with training bookings:
 * 1. Schema mismatch between ptp_bookings table and INSERT statements
 * 2. NOT NULL columns blocking inserts
 * 3. Missing columns (package_type vs session_type, etc.)
 * 4. Emails not sending due to failed booking creation
 * 
 * This fix updates the table schema to support both old and new insert patterns.
 */

defined('ABSPATH') || exit;

class PTP_Booking_Fix_V122 {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Run schema fix on init
        add_action('init', array($this, 'fix_bookings_schema'), 5);
        
        // Override the insert in create_orders_from_session
        add_filter('ptp_booking_insert_data', array($this, 'fix_booking_insert_data'), 10, 2);
        
        // Hook into order creation to ensure bookings work
        add_action('ptp_before_training_booking_insert', array($this, 'ensure_schema_ready'), 5);
        
        // Debug logging
        add_action('ptp_booking_insert_failed', array($this, 'log_insert_failure'), 10, 2);
    }
    
    /**
     * Fix the ptp_bookings table schema to support all insert patterns
     */
    public function fix_bookings_schema() {
        // Only run once per day
        $last_run = get_option('ptp_booking_schema_fix_v122');
        if ($last_run && (time() - $last_run) < 86400) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_bookings';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
        if (!$table_exists) {
            $this->create_bookings_table();
            update_option('ptp_booking_schema_fix_v122', time());
            return;
        }
        
        // Get current columns
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        $col_names = array_column($columns, 'Field');
        
        ptp_log('[PTP Fix v122] Current columns: ' . implode(', ', $col_names));
        
        // Required columns and their defaults
        $required_columns = array(
            'booking_number' => "ALTER TABLE {$table} ADD COLUMN booking_number varchar(50) DEFAULT NULL AFTER id",
            'package_type' => "ALTER TABLE {$table} ADD COLUMN package_type varchar(50) DEFAULT 'single'",
            'total_sessions' => "ALTER TABLE {$table} ADD COLUMN total_sessions int DEFAULT 1",
            'sessions_remaining' => "ALTER TABLE {$table} ADD COLUMN sessions_remaining int DEFAULT 1",
            'sessions_completed' => "ALTER TABLE {$table} ADD COLUMN sessions_completed int DEFAULT 0",
            'total_amount' => "ALTER TABLE {$table} ADD COLUMN total_amount decimal(10,2) DEFAULT 0",
            'amount_paid' => "ALTER TABLE {$table} ADD COLUMN amount_paid decimal(10,2) DEFAULT 0",
            'platform_fee' => "ALTER TABLE {$table} ADD COLUMN platform_fee decimal(10,2) DEFAULT 0",
            'guest_email' => "ALTER TABLE {$table} ADD COLUMN guest_email varchar(255) DEFAULT NULL",
            'start_time' => "ALTER TABLE {$table} ADD COLUMN start_time time DEFAULT NULL",
            'escrow_id' => "ALTER TABLE {$table} ADD COLUMN escrow_id bigint(20) DEFAULT NULL",
            'funds_held' => "ALTER TABLE {$table} ADD COLUMN funds_held tinyint(1) DEFAULT 0",
            'package_credit_id' => "ALTER TABLE {$table} ADD COLUMN package_credit_id bigint(20) DEFAULT NULL",
            'completed_at' => "ALTER TABLE {$table} ADD COLUMN completed_at datetime DEFAULT NULL",
        );
        
        // Add missing columns
        foreach ($required_columns as $col => $sql) {
            if (!in_array($col, $col_names)) {
                $result = $wpdb->query($sql);
                ptp_log("[PTP Fix v122] Added column {$col}: " . ($result !== false ? 'SUCCESS' : 'FAILED - ' . $wpdb->last_error));
            }
        }
        
        // Fix NOT NULL constraints that block inserts
        $nullable_fixes = array(
            "ALTER TABLE {$table} MODIFY COLUMN session_date date DEFAULT NULL",
            "ALTER TABLE {$table} MODIFY COLUMN start_time time DEFAULT NULL",
            "ALTER TABLE {$table} MODIFY COLUMN end_time time DEFAULT NULL",
            "ALTER TABLE {$table} MODIFY COLUMN hourly_rate decimal(10,2) DEFAULT 0",
            "ALTER TABLE {$table} MODIFY COLUMN total_amount decimal(10,2) DEFAULT 0",
            "ALTER TABLE {$table} MODIFY COLUMN trainer_payout decimal(10,2) DEFAULT 0",
            "ALTER TABLE {$table} MODIFY COLUMN player_id bigint(20) UNSIGNED DEFAULT NULL",
            "ALTER TABLE {$table} MODIFY COLUMN booking_number varchar(50) DEFAULT NULL",
        );
        
        foreach ($nullable_fixes as $sql) {
            $wpdb->query($sql);
        }
        
        // Add index for payment_intent_id if not exists
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}");
        $index_names = array_column($indexes, 'Key_name');
        if (!in_array('payment_intent_id', $index_names)) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX payment_intent_id (payment_intent_id(191))");
        }
        
        update_option('ptp_booking_schema_fix_v122', time());
        ptp_log('[PTP Fix v122] Schema fix completed');
    }
    
    /**
     * Create bookings table with proper schema
     */
    private function create_bookings_table() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'ptp_bookings';
        
        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_number varchar(50) DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED DEFAULT NULL,
            guest_email varchar(255) DEFAULT NULL,
            session_date date DEFAULT NULL,
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            duration_minutes int(11) DEFAULT 60,
            location varchar(255) DEFAULT NULL,
            location_notes text,
            package_type varchar(50) DEFAULT 'single',
            total_sessions int DEFAULT 1,
            sessions_remaining int DEFAULT 1,
            sessions_completed int DEFAULT 0,
            hourly_rate decimal(10,2) DEFAULT 0,
            total_amount decimal(10,2) DEFAULT 0,
            amount_paid decimal(10,2) DEFAULT 0,
            platform_fee decimal(10,2) DEFAULT 0,
            trainer_payout decimal(10,2) DEFAULT 0,
            status varchar(50) DEFAULT 'pending',
            parent_confirmed tinyint(1) DEFAULT 0,
            trainer_confirmed tinyint(1) DEFAULT 0,
            payment_status varchar(50) DEFAULT 'pending',
            payment_intent_id varchar(255) DEFAULT NULL,
            stripe_payment_id varchar(255) DEFAULT NULL,
            payout_status varchar(50) DEFAULT 'pending',
            escrow_id bigint(20) DEFAULT NULL,
            funds_held tinyint(1) DEFAULT 0,
            package_credit_id bigint(20) DEFAULT NULL,
            notes text,
            session_type varchar(20) DEFAULT 'single',
            session_count int(11) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id),
            KEY player_id (player_id),
            KEY status (status),
            KEY payment_intent_id (payment_intent_id(191))
        ) {$charset}";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        ptp_log('[PTP Fix v122] Created bookings table');
    }
    
    /**
     * Fix booking insert data to match schema
     */
    public function fix_booking_insert_data($data, $context) {
        // Ensure booking_number exists
        if (empty($data['booking_number'])) {
            $data['booking_number'] = 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        }
        
        // Map session_time to start_time if needed
        if (isset($data['session_time']) && !isset($data['start_time'])) {
            $data['start_time'] = $data['session_time'];
            unset($data['session_time']);
        }
        
        // Calculate end_time if not set
        if (!empty($data['start_time']) && empty($data['end_time'])) {
            $duration = $data['duration_minutes'] ?? 60;
            $start = strtotime($data['start_time']);
            if ($start) {
                $data['end_time'] = date('H:i:s', $start + ($duration * 60));
            }
        }
        
        // Ensure hourly_rate is set
        if (!isset($data['hourly_rate']) && isset($data['total_amount'])) {
            $sessions = $data['total_sessions'] ?? 1;
            $data['hourly_rate'] = round($data['total_amount'] / $sessions, 2);
        }
        
        // Map total_sessions to session_count for old schema
        if (isset($data['total_sessions']) && !isset($data['session_count'])) {
            $data['session_count'] = $data['total_sessions'];
        }
        
        // Map package_type to session_type for old schema
        if (isset($data['package_type']) && !isset($data['session_type'])) {
            $data['session_type'] = $data['package_type'];
        }
        
        return $data;
    }
    
    /**
     * Ensure schema is ready before insert
     */
    public function ensure_schema_ready() {
        $this->fix_bookings_schema();
    }
    
    /**
     * Log insert failures for debugging
     */
    public function log_insert_failure($data, $error) {
        ptp_log('[PTP Fix v122] Booking insert failed!');
        ptp_log('[PTP Fix v122] Data: ' . json_encode($data));
        ptp_log('[PTP Fix v122] Error: ' . $error);
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Booking_Fix_V122::instance();
}, 1); // Priority 1 to run early
