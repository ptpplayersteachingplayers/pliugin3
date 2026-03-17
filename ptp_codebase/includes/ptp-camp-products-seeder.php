<?php
/**
 * PTP Camp Products Seeder
 * 
 * Seeds the ptp_stripe_products table with camp data from PTP Native export.
 * Run this once to populate the database with existing camps.
 * 
 * Usage: Add ?ptp_seed_camps=1 to any admin page URL, or run via WP-CLI
 * 
 * @version 146.0.0
 */

defined('ABSPATH') || exit;

/**
 * Seed camp products from PTP Native data
 */
function ptp_seed_camp_products() {
    global $wpdb;
    
    $table = $wpdb->prefix . 'ptp_stripe_products';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        // Create table if it doesn't exist
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stripe_product_id varchar(100) NOT NULL,
            stripe_price_id varchar(100) DEFAULT NULL,
            name varchar(255) NOT NULL,
            description text,
            price_cents int(11) NOT NULL DEFAULT 0,
            product_type varchar(50) NOT NULL DEFAULT 'camp',
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
            woo_product_id bigint(20) unsigned DEFAULT NULL,
            sku varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY stripe_product_id (stripe_product_id),
            KEY product_type (product_type),
            KEY active (active),
            KEY woo_product_id (woo_product_id)
        ) $charset_collate;";
        
        dbDelta($sql);
    }
    
    // Camp products from PTP Native export
    $camps = array(
        array(
            'stripe_product_id' => 'prod_ptp_wayne_jun15',
            'name' => 'Soccer Camp Wayne PA – Wilson Farm Park – June 15-19, 2026',
            'description' => 'Elite youth soccer camp in Wayne PA at Wilson Farm Park. June 15-19 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 42000, // $420 early bird
            'product_type' => 'camp',
            'camp_dates' => 'June 15-19, 2026',
            'camp_location' => 'Wilson Farm Park, Wayne PA',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 4,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 1,
            'woo_product_id' => 5388,
            'sku' => 'PTP-SC-WAYNE-JUN15-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_wayne_jul6',
            'name' => 'Soccer Camp Wayne PA – Wilson Farm Park – July 6-10, 2026',
            'description' => 'Elite youth soccer camp in Wayne PA at Wilson Farm Park. July 6-10 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 42000,
            'product_type' => 'camp',
            'camp_dates' => 'July 6-10, 2026',
            'camp_location' => 'Wilson Farm Park, Wayne PA',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 6,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 2,
            'woo_product_id' => 5389,
            'sku' => 'PTP-SC-WAYNE-JUL6-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_wayne_jul27',
            'name' => 'Soccer Camp Wayne PA – Wilson Farm Park – July 27-31, 2026',
            'description' => 'Elite youth soccer camp in Wayne PA at Wilson Farm Park. July 27-31 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 42000,
            'product_type' => 'camp',
            'camp_dates' => 'July 27-31, 2026',
            'camp_location' => 'Wilson Farm Park, Wayne PA',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 2,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 3,
            'woo_product_id' => 5390,
            'sku' => 'PTP-SC-WAYNE-JUL27-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_media_jun29',
            'name' => 'Soccer Camp Media PA – Sleighton Park – June 29 - July 3, 2026',
            'description' => 'Elite youth soccer camp in Media PA at Sleighton Park. June 29 - July 3 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 42000,
            'product_type' => 'camp',
            'camp_dates' => 'June 29 - July 3, 2026',
            'camp_location' => 'Sleighton Park, Media PA',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 0,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 4,
            'woo_product_id' => 5391,
            'sku' => 'PTP-SC-MEDIA-JUN29-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_media_jul20',
            'name' => 'Soccer Camp Media PA – Sleighton Park – July 20-24, 2026',
            'description' => 'Elite youth soccer camp in Media PA at Sleighton Park. July 20-24 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 42000,
            'product_type' => 'camp',
            'camp_dates' => 'July 20-24, 2026',
            'camp_location' => 'Sleighton Park, Media PA',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 0,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 5,
            'woo_product_id' => 5392,
            'sku' => 'PTP-SC-MEDIA-JUL20-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_downingtown_jul13',
            'name' => 'Soccer Camp Downingtown PA – USTC – July 13-17, 2026 - Half Day',
            'description' => 'Elite youth soccer camp in Downingtown PA at USTC Indoor. July 13-17 2026 half-day. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 32000, // $320 early bird (half day)
            'product_type' => 'camp',
            'camp_dates' => 'July 13-17, 2026',
            'camp_location' => 'USTC Indoor, Downingtown PA',
            'camp_time' => '9:00 AM - 12:00 PM (Half Day)',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 3,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 6,
            'woo_product_id' => 5393,
            'sku' => 'PTP-SC-DOWNINGTOWN-JUL13-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_newtownsq_jul6',
            'name' => 'Soccer Camp Newtown Square PA – Gable Park – July 6-10, 2026',
            'description' => 'Elite youth soccer camp in Newtown Square PA at Gable Park. July 6-10 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 42000,
            'product_type' => 'camp',
            'camp_dates' => 'July 6-10, 2026',
            'camp_location' => 'Gable Park, Newtown Square PA',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 0,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 7,
            'woo_product_id' => 5394,
            'sku' => 'PTP-SC-NEWTOWNSQ-JUL6-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_cherryhill_jun22',
            'name' => 'Soccer Camp Cherry Hill NJ – DeCou Soccer Complex – June 22-26, 2026',
            'description' => 'Elite youth soccer camp in Cherry Hill NJ at DeCou Soccer Complex. June 22-26 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 42000,
            'product_type' => 'camp',
            'camp_dates' => 'June 22-26, 2026',
            'camp_location' => 'DeCou Soccer Complex, Cherry Hill NJ',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 0,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 8,
            'woo_product_id' => 5395,
            'sku' => 'PTP-SC-CHERRYHILL-JUN22-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_cherryhill_jul20',
            'name' => 'Soccer Camp Cherry Hill NJ – DeCou Soccer Complex – July 20-24, 2026',
            'description' => 'Elite youth soccer camp in Cherry Hill NJ at DeCou Soccer Complex. July 20-24 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 42000,
            'product_type' => 'camp',
            'camp_dates' => 'July 20-24, 2026',
            'camp_location' => 'DeCou Soccer Complex, Cherry Hill NJ',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 1,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 9,
            'woo_product_id' => 5396,
            'sku' => 'PTP-SC-CHERRYHILL-JUL20-2026',
        ),
        array(
            'stripe_product_id' => 'prod_ptp_villanova_jul20',
            'name' => 'Soccer Camp Villanova PA – Radnor Memorial Park – July 20-24, 2026',
            'description' => 'Elite youth soccer camp in Villanova PA at Radnor Memorial Park. July 20-24 2026. Train with NCAA D1 athletes. Jersey and player card included. Ages 6-14.',
            'price_cents' => 45000, // $450
            'product_type' => 'camp',
            'camp_dates' => 'July 20-24, 2026',
            'camp_location' => 'Radnor Memorial Park, Villanova PA',
            'camp_time' => '9:00 AM - 3:00 PM',
            'camp_age_min' => 6,
            'camp_age_max' => 14,
            'camp_capacity' => 60,
            'camp_registered' => 1,
            'image_url' => 'https://ptpsummercamps.com/wp-content/uploads/2026/01/GROUP-PHOTO.jpg',
            'sort_order' => 10,
            'is_featured' => 1,
            'woo_product_id' => 5906,
            'sku' => 'PTP-SC-VILLANOVA-JUL20-2026',
        ),
    );
    
    $inserted = 0;
    $updated = 0;
    
    foreach ($camps as $camp) {
        // Check if already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE stripe_product_id = %s OR woo_product_id = %d",
            $camp['stripe_product_id'],
            $camp['woo_product_id']
        ));
        
        if ($existing) {
            // Update existing
            $wpdb->update($table, $camp, array('id' => $existing));
            $updated++;
        } else {
            // Insert new
            $camp['active'] = 1;
            $camp['created_at'] = current_time('mysql');
            $wpdb->insert($table, $camp);
            $inserted++;
        }
    }
    
    return array(
        'inserted' => $inserted,
        'updated' => $updated,
        'total' => count($camps),
    );
}

/**
 * Admin trigger for seeding
 */
add_action('admin_init', function() {
    if (!isset($_GET['ptp_seed_camps']) || !current_user_can('manage_options')) {
        return;
    }
    
    $result = ptp_seed_camp_products();
    
    add_action('admin_notices', function() use ($result) {
        echo '<div class="notice notice-success"><p>';
        echo 'Camp products seeded: ' . $result['inserted'] . ' inserted, ' . $result['updated'] . ' updated (total: ' . $result['total'] . ')';
        echo '</p></div>';
    });
});

/**
 * WP-CLI command
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('ptp seed-camps', function() {
        $result = ptp_seed_camp_products();
        WP_CLI::success("Seeded {$result['inserted']} new camps, updated {$result['updated']} existing (total: {$result['total']})");
    });
}

/**
 * Auto-seed on plugin activation if table is empty
 */
add_action('plugins_loaded', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'ptp_stripe_products';
    
    // Only run once
    if (get_option('ptp_camps_seeded')) {
        return;
    }
    
    // Check if table exists and is empty
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE product_type = 'camp'");
    
    if ($count === null || intval($count) === 0) {
        $result = ptp_seed_camp_products();
        if ($result['inserted'] > 0) {
            update_option('ptp_camps_seeded', time());
            ptp_log("[PTP] Auto-seeded {$result['inserted']} camp products");
        }
    }
}, 20);
