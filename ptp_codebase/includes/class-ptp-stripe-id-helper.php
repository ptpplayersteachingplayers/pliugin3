<?php
/**
 * PTP Stripe ID Helper v1.0.0
 * 
 * ISSUE #3 FIX: Inconsistent Stripe ID Storage
 * 
 * Provides a standardized interface for accessing Stripe IDs across different tables
 * that use different column naming conventions:
 * 
 * | Table | Payment Intent | Charge ID | Product ID | Price ID |
 * |-------|----------------|-----------|------------|----------|
 * | ptp_bookings | payment_intent_id | stripe_payment_id | - | - |
 * | ptp_native_orders | stripe_payment_intent | stripe_charge_id | - | - |
 * | ptp_camp_orders | stripe_payment_intent | stripe_charge_id | - | - |
 * | ptp_camp_order_items | - | - | stripe_product_id | stripe_price_id |
 * | ptp_bundles | payment_intent_id | - | - | - |
 * 
 * This helper provides a unified interface that abstracts these differences.
 * 
 * @since 160.0.0
 */

defined('ABSPATH') || exit;

class PTP_Stripe_ID_Helper {
    
    /**
     * Table column mappings for Stripe IDs
     */
    private static $column_maps = array(
        'ptp_bookings' => array(
            'payment_intent' => 'payment_intent_id',
            'charge' => 'stripe_payment_id',
            'transfer' => 'stripe_transfer_id',
            'refund' => 'stripe_refund_id',
        ),
        'ptp_native_orders' => array(
            'payment_intent' => 'stripe_payment_intent',
            'charge' => 'stripe_charge_id',
        ),
        'ptp_camp_orders' => array(
            'payment_intent' => 'stripe_payment_intent',
            'charge' => 'stripe_charge_id',
            'customer' => 'stripe_customer_id',
        ),
        'ptp_camp_order_items' => array(
            'product' => 'stripe_product_id',
            'price' => 'stripe_price_id',
        ),
        'ptp_bundles' => array(
            'payment_intent' => 'payment_intent_id',
            'session' => 'stripe_session_id',
        ),
        'ptp_escrow' => array(
            'payment_intent' => 'payment_intent_id',
            'transfer' => 'stripe_transfer_id',
        ),
        'ptp_stripe_products' => array(
            'product' => 'stripe_product_id',
            'price' => 'stripe_price_id',
        ),
    );
    
    /**
     * Get the column name for a Stripe ID type in a specific table
     * 
     * @param string $table Table name (without prefix)
     * @param string $id_type Type of Stripe ID: 'payment_intent', 'charge', 'product', 'price', etc.
     * @return string|null Column name or null if not mapped
     */
    public static function get_column_name($table, $id_type) {
        $table = str_replace(array($GLOBALS['wpdb']->prefix, 'wp_'), '', $table);
        
        if (isset(self::$column_maps[$table][$id_type])) {
            return self::$column_maps[$table][$id_type];
        }
        
        return null;
    }
    
    /**
     * Get a Stripe ID from a record object
     * 
     * @param object $record Database record object
     * @param string $id_type Type of Stripe ID to get
     * @param string $table Optional table name hint
     * @return string|null Stripe ID or null
     */
    public static function get_stripe_id($record, $id_type, $table = '') {
        if (!is_object($record)) {
            return null;
        }
        
        // If table is provided, use the mapping
        if ($table) {
            $column = self::get_column_name($table, $id_type);
            if ($column && isset($record->{$column})) {
                return $record->{$column};
            }
        }
        
        // Try common column names for this ID type
        $possible_columns = self::get_possible_columns($id_type);
        
        foreach ($possible_columns as $column) {
            if (isset($record->{$column}) && !empty($record->{$column})) {
                return $record->{$column};
            }
        }
        
        return null;
    }
    
    /**
     * Get possible column names for a Stripe ID type
     * 
     * @param string $id_type Type of Stripe ID
     * @return array Possible column names
     */
    private static function get_possible_columns($id_type) {
        $columns = array();
        
        switch ($id_type) {
            case 'payment_intent':
                $columns = array(
                    'payment_intent_id',
                    'stripe_payment_intent',
                    'stripe_payment_intent_id',
                    'paymentIntentId',
                );
                break;
                
            case 'charge':
                $columns = array(
                    'stripe_charge_id',
                    'stripe_payment_id',
                    'charge_id',
                    'chargeId',
                );
                break;
                
            case 'product':
                $columns = array(
                    'stripe_product_id',
                    'product_id',
                    'productId',
                );
                break;
                
            case 'price':
                $columns = array(
                    'stripe_price_id',
                    'price_id',
                    'priceId',
                );
                break;
                
            case 'customer':
                $columns = array(
                    'stripe_customer_id',
                    'customer_id',
                    'customerId',
                );
                break;
                
            case 'transfer':
                $columns = array(
                    'stripe_transfer_id',
                    'transfer_id',
                    'transferId',
                );
                break;
                
            case 'refund':
                $columns = array(
                    'stripe_refund_id',
                    'refund_id',
                    'refundId',
                );
                break;
                
            case 'session':
                $columns = array(
                    'stripe_session_id',
                    'checkout_session_id',
                    'session_id',
                );
                break;
        }
        
        return $columns;
    }
    
    /**
     * Update a Stripe ID in the database
     * 
     * @param string $table Table name (with or without prefix)
     * @param int $record_id Record ID
     * @param string $id_type Type of Stripe ID
     * @param string $stripe_id The Stripe ID value
     * @return bool Success
     */
    public static function update_stripe_id($table, $record_id, $id_type, $stripe_id) {
        global $wpdb;
        
        // Ensure table has prefix
        if (strpos($table, $wpdb->prefix) !== 0) {
            $table = $wpdb->prefix . $table;
        }
        
        $column = self::get_column_name(str_replace($wpdb->prefix, '', $table), $id_type);
        
        if (!$column) {
            ptp_log("[PTP Stripe ID Helper] No column mapping for {$id_type} in {$table}");
            return false;
        }
        
        $result = $wpdb->update(
            $table,
            array($column => $stripe_id),
            array('id' => $record_id),
            array('%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Find a record by Stripe ID
     * 
     * @param string $table Table name (with or without prefix)
     * @param string $id_type Type of Stripe ID
     * @param string $stripe_id The Stripe ID to search for
     * @return object|null Record or null
     */
    public static function find_by_stripe_id($table, $id_type, $stripe_id) {
        global $wpdb;
        
        // Ensure table has prefix
        if (strpos($table, $wpdb->prefix) !== 0) {
            $table = $wpdb->prefix . $table;
        }
        
        $column = self::get_column_name(str_replace($wpdb->prefix, '', $table), $id_type);
        
        if (!$column) {
            // Try all possible columns
            $columns = self::get_possible_columns($id_type);
            foreach ($columns as $col) {
                // Check if column exists in table
                $exists = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
                if ($exists) {
                    $column = $col;
                    break;
                }
            }
        }
        
        if (!$column) {
            return null;
        }
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$column} = %s LIMIT 1",
            $stripe_id
        ));
    }
    
    /**
     * Find records across multiple tables by payment intent ID
     * Useful for finding all related records for a payment
     * 
     * @param string $payment_intent_id Stripe payment intent ID
     * @return array Array of results keyed by table name
     */
    public static function find_all_by_payment_intent($payment_intent_id) {
        $results = array();
        
        $tables_to_check = array(
            'ptp_bookings',
            'ptp_native_orders',
            'ptp_camp_orders',
            'ptp_bundles',
            'ptp_escrow',
        );
        
        foreach ($tables_to_check as $table) {
            $record = self::find_by_stripe_id($table, 'payment_intent', $payment_intent_id);
            if ($record) {
                $results[$table] = $record;
            }
        }
        
        return $results;
    }
    
    /**
     * Validate a Stripe ID format
     * 
     * @param string $stripe_id The Stripe ID to validate
     * @param string $id_type Expected type (optional)
     * @return bool True if valid format
     */
    public static function is_valid_stripe_id($stripe_id, $id_type = '') {
        if (empty($stripe_id) || !is_string($stripe_id)) {
            return false;
        }
        
        // Stripe ID patterns
        $patterns = array(
            'payment_intent' => '/^pi_[a-zA-Z0-9]{24,}$/',
            'charge' => '/^ch_[a-zA-Z0-9]{24,}$/',
            'product' => '/^prod_[a-zA-Z0-9]{14,}$/',
            'price' => '/^price_[a-zA-Z0-9]{24,}$/',
            'customer' => '/^cus_[a-zA-Z0-9]{14,}$/',
            'transfer' => '/^tr_[a-zA-Z0-9]{24,}$/',
            'refund' => '/^re_[a-zA-Z0-9]{24,}$/',
            'session' => '/^cs_(test_|live_)?[a-zA-Z0-9]{40,}$/',
            'account' => '/^acct_[a-zA-Z0-9]{16,}$/',
            'payout' => '/^po_[a-zA-Z0-9]{24,}$/',
        );
        
        if ($id_type && isset($patterns[$id_type])) {
            return (bool) preg_match($patterns[$id_type], $stripe_id);
        }
        
        // Try to auto-detect type and validate
        $prefixes = array(
            'pi_' => 'payment_intent',
            'ch_' => 'charge',
            'prod_' => 'product',
            'price_' => 'price',
            'cus_' => 'customer',
            'tr_' => 'transfer',
            're_' => 'refund',
            'cs_' => 'session',
            'acct_' => 'account',
            'po_' => 'payout',
        );
        
        foreach ($prefixes as $prefix => $type) {
            if (strpos($stripe_id, $prefix) === 0) {
                return (bool) preg_match($patterns[$type], $stripe_id);
            }
        }
        
        return false;
    }
    
    /**
     * Detect the type of a Stripe ID from its prefix
     * 
     * @param string $stripe_id The Stripe ID
     * @return string|null Detected type or null
     */
    public static function detect_stripe_id_type($stripe_id) {
        $prefixes = array(
            'pi_' => 'payment_intent',
            'ch_' => 'charge',
            'prod_' => 'product',
            'price_' => 'price',
            'cus_' => 'customer',
            'tr_' => 'transfer',
            're_' => 'refund',
            'cs_' => 'session',
            'acct_' => 'account',
            'po_' => 'payout',
            'sub_' => 'subscription',
            'in_' => 'invoice',
        );
        
        foreach ($prefixes as $prefix => $type) {
            if (strpos($stripe_id, $prefix) === 0) {
                return $type;
            }
        }
        
        return null;
    }
    
    /**
     * Get standardized Stripe data from an order/booking
     * Returns all Stripe IDs in a consistent format
     * 
     * @param object $record Database record
     * @param string $table Table name
     * @return array Standardized Stripe data
     */
    public static function get_standardized_stripe_data($record, $table = '') {
        return array(
            'payment_intent_id' => self::get_stripe_id($record, 'payment_intent', $table),
            'charge_id' => self::get_stripe_id($record, 'charge', $table),
            'customer_id' => self::get_stripe_id($record, 'customer', $table),
            'transfer_id' => self::get_stripe_id($record, 'transfer', $table),
            'refund_id' => self::get_stripe_id($record, 'refund', $table),
            'product_id' => self::get_stripe_id($record, 'product', $table),
            'price_id' => self::get_stripe_id($record, 'price', $table),
        );
    }
}

// Make helper functions globally available
function ptp_get_stripe_id($record, $id_type, $table = '') {
    return PTP_Stripe_ID_Helper::get_stripe_id($record, $id_type, $table);
}

function ptp_find_by_stripe_id($table, $id_type, $stripe_id) {
    return PTP_Stripe_ID_Helper::find_by_stripe_id($table, $id_type, $stripe_id);
}

function ptp_is_valid_stripe_id($stripe_id, $id_type = '') {
    return PTP_Stripe_ID_Helper::is_valid_stripe_id($stripe_id, $id_type);
}
