<?php
/**
 * PTP Camp CSV Import
 * Imports WooCommerce camp products into ptp_stripe_products table
 * 
 * @version 150.3.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_CSV_Import {
    
    public static function import_from_csv($file_path) {
        global $wpdb;
        
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'CSV file not found');
        }
        
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        // Ensure table exists
        self::ensure_table_exists();
        
        // Read CSV
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new WP_Error('file_open_error', 'Could not open CSV file');
        }
        
        // Get headers
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return new WP_Error('invalid_csv', 'Could not read CSV headers');
        }
        
        // Clean BOM from first header if present
        $headers[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $headers[0]);
        
        // Find column indices
        $col_map = array_flip($headers);
        
        $imported = 0;
        $skipped = 0;
        $errors = array();
        
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            
            // Skip non-camp products
            $title = $data['post_title'] ?? '';
            $status = $data['post_status'] ?? '';
            $category = $data['tax:product_cat'] ?? '';
            
            // Only import published Summer Camps
            if ($status !== 'publish') {
                $skipped++;
                continue;
            }
            
            // Check if it's a camp (has "Camp" in title or "Summer Camps" in category)
            $is_camp = (stripos($title, 'Camp') !== false || stripos($category, 'Camp') !== false) 
                       && stripos($title, 'All-Access') === false
                       && stripos($title, 'Private Training') === false;
            
            if (!$is_camp) {
                $skipped++;
                continue;
            }
            
            // Parse camp details from title
            $parsed = self::parse_camp_title($title);
            
            // Get prices
            $regular_price = floatval($data['regular_price'] ?? 525);
            $sale_price = floatval($data['sale_price'] ?? 0);
            $price = ($sale_price > 0) ? $sale_price : $regular_price;
            
            // Get stock/capacity
            $capacity = intval($data['stock'] ?? 60);
            
            // Get WooCommerce product ID
            $woo_id = intval($data['ID'] ?? 0);
            
            // Get SKU
            $sku = $data['sku'] ?? '';
            
            // Get image
            $images = $data['images'] ?? '';
            $image_url = '';
            if ($images) {
                // Parse WooCommerce image format
                $image_parts = explode(' ! ', $images);
                $image_url = trim($image_parts[0] ?? '');
            }
            
            // Check if already exists by WooCommerce ID or SKU
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE woo_product_id = %d OR sku = %s",
                $woo_id, $sku
            ));
            
            // Prepare data for insert/update
            $camp_data = array(
                'name' => $title,
                'product_type' => 'camp',
                'price_cents' => intval($price * 100),
                'camp_dates' => $parsed['dates'],
                'camp_location' => $parsed['location'],
                'camp_time' => $parsed['time'],
                'camp_capacity' => $capacity,
                'camp_registered' => 0,
                'image_url' => $image_url,
                'sort_order' => $imported,
                'active' => 1,
                'woo_product_id' => $woo_id,
                'sku' => $sku,
                'stripe_product_id' => 'woo_' . $woo_id, // Placeholder until Stripe sync
                'stripe_price_id' => '',
            );
            
            if ($existing) {
                // Update existing
                $wpdb->update($table, $camp_data, array('id' => $existing));
            } else {
                // Insert new
                $wpdb->insert($table, $camp_data);
            }
            
            $imported++;
        }
        
        fclose($handle);
        
        return array(
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        );
    }
    
    /**
     * Parse camp title to extract location and dates
     * Example: "Soccer Camp Wayne PA – Wilson Farm Park – June 15-19, 2026"
     */
    private static function parse_camp_title($title) {
        $result = array(
            'location' => '',
            'dates' => '',
            'time' => '9AM - 3PM', // Default
        );
        
        // Check for half day
        if (stripos($title, 'Half Day') !== false) {
            $result['time'] = '9AM - 12PM';
        }
        
        // Split by common delimiters
        $parts = preg_split('/\s*[–—-]\s*/', $title);
        
        foreach ($parts as $part) {
            $part = trim($part);
            
            // Check if it's a date (contains month name and numbers)
            if (preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d/i', $part)) {
                // Clean up the date
                $result['dates'] = preg_replace('/,?\s*\d{4}$/', '', $part); // Remove year
                $result['dates'] = trim($result['dates']);
            }
            // Check if it's a location (contains Park, Complex, USTC, etc.)
            elseif (preg_match('/(Park|Complex|USTC|Field|Center|Memorial)/i', $part)) {
                $result['location'] = $part;
            }
        }
        
        // If no location found, try to extract city/state
        if (empty($result['location'])) {
            if (preg_match('/Camp\s+(.+?)\s+(PA|NJ)\b/i', $title, $matches)) {
                $result['location'] = $matches[1] . ', ' . strtoupper($matches[2]);
            }
        }
        
        return $result;
    }
    
    /**
     * Ensure the ptp_stripe_products table exists
     */
    private static function ensure_table_exists() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_product_id varchar(255) NOT NULL,
            stripe_price_id varchar(255) DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED DEFAULT 0,
            name varchar(255) NOT NULL,
            description text,
            price_cents int(11) DEFAULT 0,
            product_type varchar(50) DEFAULT 'camp',
            camp_dates varchar(100) DEFAULT NULL,
            camp_location varchar(255) DEFAULT NULL,
            camp_time varchar(100) DEFAULT NULL,
            camp_age_min int(11) DEFAULT NULL,
            camp_age_max int(11) DEFAULT NULL,
            camp_capacity int(11) DEFAULT NULL,
            camp_registered int(11) DEFAULT 0,
            image_url varchar(500) DEFAULT NULL,
            sort_order int(11) DEFAULT 0,
            is_featured tinyint(1) DEFAULT 0,
            active tinyint(1) DEFAULT 1,
            woo_product_id bigint(20) UNSIGNED DEFAULT NULL,
            sku varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY stripe_product_id (stripe_product_id),
            KEY stripe_price_id (stripe_price_id),
            KEY product_type (product_type),
            KEY active (active),
            KEY woo_product_id (woo_product_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Import from WooCommerce products directly (if in WordPress context)
     */
    public static function import_from_woocommerce() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_stripe_products';
        self::ensure_table_exists();
        
        // Get all published products in camp categories
        $products = get_posts(array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => array('summer-camps', 'camps', 'soccer-camps'),
                ),
            ),
        ));
        
        $imported = 0;
        
        foreach ($products as $product_post) {
            $product = wc_get_product($product_post->ID);
            if (!$product) continue;
            
            $title = $product->get_name();
            
            // Skip non-camp products
            if (stripos($title, 'All-Access') !== false || stripos($title, 'Private Training') !== false) {
                continue;
            }
            
            $parsed = self::parse_camp_title($title);
            
            $price = $product->get_sale_price() ?: $product->get_regular_price();
            $stock = $product->get_stock_quantity() ?: 60;
            
            // Check if exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE woo_product_id = %d",
                $product_post->ID
            ));
            
            $camp_data = array(
                'name' => $title,
                'product_type' => 'camp',
                'price_cents' => intval(floatval($price) * 100),
                'camp_dates' => $parsed['dates'],
                'camp_location' => $parsed['location'],
                'camp_time' => $parsed['time'],
                'camp_capacity' => $stock,
                'camp_registered' => 0,
                'image_url' => wp_get_attachment_url($product->get_image_id()),
                'sort_order' => $imported,
                'active' => 1,
                'woo_product_id' => $product_post->ID,
                'sku' => $product->get_sku(),
                'stripe_product_id' => 'woo_' . $product_post->ID,
                'stripe_price_id' => '',
            );
            
            if ($existing) {
                $wpdb->update($table, $camp_data, array('id' => $existing));
            } else {
                $wpdb->insert($table, $camp_data);
            }
            
            $imported++;
        }
        
        return array(
            'success' => true,
            'imported' => $imported,
        );
    }
}
