<?php
/**
 * PTP Camp System Loader - Main Driver for Camp Functionality
 * 
 * V167: This file contains ALL camp-related business logic.
 * PTP Camps plugin v14+ only provides CPT and templates - it delegates all
 * cart, checkout, orders, emails, and payments to this platform.
 * 
 * Classes loaded:
 * - PTP_Camp_Orders: Order creation, management, Stripe webhooks
 * - PTP_Camp_Checkout: Checkout session creation, payment processing
 * - PTP_Camp_Emails: Confirmation, reminder, and notification emails
 * 
 * @version 167.0.0
 * @since 146.0.0
 */

defined('ABSPATH') || exit;

/**
 * Load all camp system classes
 */
function ptp_load_camp_system() {
    // Core camp classes
    require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camp-orders.php';
    require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camp-checkout.php';
    require_once PTP_PLUGIN_DIR . 'includes/class-ptp-camp-emails.php';
    
    // Admin classes
    if (is_admin()) {
        require_once PTP_PLUGIN_DIR . 'admin/class-ptp-camp-orders-admin.php';
    }
}

// Load camp system early
add_action('plugins_loaded', 'ptp_load_camp_system', 5);

/**
 * Handle Stripe webhooks for camp checkout sessions
 */
function ptp_handle_camp_stripe_webhooks($event) {
    $event_type = $event['type'] ?? '';
    
    switch ($event_type) {
        case 'checkout.session.completed':
            do_action('ptp_stripe_webhook_checkout.session.completed', $event);
            break;
            
        case 'checkout.session.expired':
            do_action('ptp_stripe_webhook_checkout.session.expired', $event);
            break;
    }
}
add_action('ptp_stripe_webhook_received', 'ptp_handle_camp_stripe_webhooks', 10, 1);

/**
 * Create camp pages on plugin activation
 */
function ptp_create_camp_pages() {
    // Camp Checkout page
    if (!get_page_by_path('camp-checkout')) {
        wp_insert_post(array(
            'post_title' => 'Camp Checkout',
            'post_name' => 'camp-checkout',
            'post_content' => '[ptp_camp_checkout]',
            'post_status' => 'publish',
            'post_type' => 'page',
        ));
    }
    
    // Camp Thank You page
    if (!get_page_by_path('camp-thank-you')) {
        wp_insert_post(array(
            'post_title' => 'Registration Complete',
            'post_name' => 'camp-thank-you',
            'post_content' => '[ptp_camp_thank_you]',
            'post_status' => 'publish',
            'post_type' => 'page',
        ));
    }
}
register_activation_hook(PTP_PLUGIN_FILE, 'ptp_create_camp_pages');

/**
 * Add camp system database tables
 */
function ptp_create_camp_tables() {
    if (class_exists('PTP_Camp_Orders')) {
        PTP_Camp_Orders::create_tables();
    }
}
register_activation_hook(PTP_PLUGIN_FILE, 'ptp_create_camp_tables');

/**
 * AJAX handler for getting order details in admin
 */
function ptp_ajax_get_camp_order_details() {
    check_ajax_referer('ptp_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    $order_id = intval($_GET['order_id'] ?? 0);
    $order = PTP_Camp_Orders::get_order($order_id);
    
    if (!$order) {
        wp_send_json_error(array('message' => 'Order not found'));
    }
    
    // Build HTML for modal
    ob_start();
    ?>
    <div class="order-detail">
        <div class="order-header">
            <div class="order-meta">
                <p><strong>Order Number:</strong> <?php echo esc_html($order->order_number); ?></p>
                <p><strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($order->created_at)); ?></p>
                <p><strong>Status:</strong> <span class="status-badge status-<?php echo esc_attr($order->status); ?>"><?php echo ucfirst($order->status); ?></span></p>
                <p><strong>Payment:</strong> <?php echo ucfirst($order->payment_status); ?></p>
            </div>
        </div>
        
        <h3>Customer Information</h3>
        <table class="widefat" style="margin-bottom: 20px;">
            <tr>
                <td><strong>Name:</strong></td>
                <td><?php echo esc_html($order->billing_first_name . ' ' . $order->billing_last_name); ?></td>
            </tr>
            <tr>
                <td><strong>Email:</strong></td>
                <td><a href="mailto:<?php echo esc_attr($order->billing_email); ?>"><?php echo esc_html($order->billing_email); ?></a></td>
            </tr>
            <tr>
                <td><strong>Phone:</strong></td>
                <td><?php echo esc_html($order->billing_phone); ?></td>
            </tr>
            <tr>
                <td><strong>Emergency Contact:</strong></td>
                <td><?php echo esc_html($order->emergency_name . ' - ' . $order->emergency_phone . ' (' . $order->emergency_relation . ')'); ?></td>
            </tr>
        </table>
        
        <h3>Registered Campers</h3>
        <?php foreach ($order->items as $item): ?>
            <div style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border-radius: 4px;">
                <h4 style="margin: 0 0 10px 0;"><?php echo esc_html($item->camper_first_name . ' ' . $item->camper_last_name); ?></h4>
                <table class="widefat">
                    <tr>
                        <td><strong>Camp:</strong></td>
                        <td><?php echo esc_html($item->camp_name); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Dates:</strong></td>
                        <td><?php echo esc_html($item->camp_dates); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Age:</strong></td>
                        <td><?php echo esc_html($item->camper_age); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Shirt Size:</strong></td>
                        <td><?php echo esc_html($item->camper_shirt_size); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Skill Level:</strong></td>
                        <td><?php echo esc_html($item->camper_skill_level); ?></td>
                    </tr>
                    <?php if ($item->camper_team): ?>
                        <tr>
                            <td><strong>Team:</strong></td>
                            <td><?php echo esc_html($item->camper_team); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($item->medical_conditions): ?>
                        <tr>
                            <td><strong>Medical:</strong></td>
                            <td><?php echo esc_html($item->medical_conditions); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong>Add-ons:</strong></td>
                        <td>
                            <?php 
                            $addons = array();
                            if ($item->care_bundle) $addons[] = 'Before + After Care';
                            if ($item->jersey) $addons[] = 'Jersey';
                            echo $addons ? implode(', ', $addons) : 'None';
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Price:</strong></td>
                        <td>
                            $<?php echo number_format($item->final_price, 2); ?>
                            <?php if ($item->is_sibling): ?>
                                <small style="color: #10b981;">(Sibling discount applied)</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endforeach; ?>
        
        <h3>Order Totals</h3>
        <table class="widefat">
            <tr>
                <td><strong>Subtotal:</strong></td>
                <td>$<?php echo number_format($order->subtotal, 2); ?></td>
            </tr>
            <?php if ($order->discount_amount > 0): ?>
                <tr style="color: #10b981;">
                    <td><strong>Discounts:</strong></td>
                    <td>-$<?php echo number_format($order->discount_amount, 2); ?></td>
                </tr>
            <?php endif; ?>
            <?php if ($order->care_bundle_total > 0): ?>
                <tr>
                    <td><strong>Care Bundle:</strong></td>
                    <td>$<?php echo number_format($order->care_bundle_total, 2); ?></td>
                </tr>
            <?php endif; ?>
            <?php if ($order->jersey_total > 0): ?>
                <tr>
                    <td><strong>Jerseys:</strong></td>
                    <td>$<?php echo number_format($order->jersey_total, 2); ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td><strong>Processing Fee:</strong></td>
                <td>$<?php echo number_format($order->processing_fee, 2); ?></td>
            </tr>
            <tr style="font-size: 18px; font-weight: bold;">
                <td><strong>Total:</strong></td>
                <td>$<?php echo number_format($order->total_amount, 2); ?></td>
            </tr>
        </table>
        
        <?php if ($order->stripe_payment_intent_id): ?>
            <p style="margin-top: 20px;">
                <a href="https://dashboard.stripe.com/payments/<?php echo esc_attr($order->stripe_payment_intent_id); ?>" target="_blank" class="button">
                    View in Stripe →
                </a>
            </p>
        <?php endif; ?>
        
        <?php if ($order->referral_code_used): ?>
            <p><strong>Referral Code Used:</strong> <?php echo esc_html($order->referral_code_used); ?></p>
        <?php endif; ?>
        
        <?php if ($order->referral_code_generated): ?>
            <p><strong>Referral Code Generated:</strong> <code><?php echo esc_html($order->referral_code_generated); ?></code></p>
        <?php endif; ?>
    </div>
    <?php
    $html = ob_get_clean();
    
    wp_send_json_success(array('html' => $html));
}
add_action('wp_ajax_ptp_get_camp_order_details', 'ptp_ajax_get_camp_order_details');

/**
 * Add camp revenue to analytics dashboard
 */
function ptp_add_camp_revenue_to_analytics($stats) {
    global $wpdb;
    
    // Get camp revenue
    $camp_revenue = $wpdb->get_var("
        SELECT SUM(total_amount) 
        FROM {$wpdb->prefix}ptp_unified_camp_orders 
        WHERE status = 'completed'
    ") ?: 0;
    
    $stats['camps_revenue'] = floatval($camp_revenue);
    $stats['revenue'] = floatval($stats['revenue'] ?? 0) + floatval($camp_revenue);
    
    return $stats;
}
add_filter('ptp_analytics_stats', 'ptp_add_camp_revenue_to_analytics');

/**
 * v164: Redirect /camp-checkout to /ptp-checkout (unified checkout)
 */
function ptp_redirect_camp_checkout_to_unified() {
    if (is_page('camp-checkout')) {
        // Preserve any URL parameters
        $redirect_url = home_url('/ptp-checkout/');
        
        // Carry over query string
        if (!empty($_SERVER['QUERY_STRING'])) {
            $redirect_url .= '?' . $_SERVER['QUERY_STRING'];
        }
        
        wp_redirect($redirect_url, 301);
        exit;
    }
}
add_action('template_redirect', 'ptp_redirect_camp_checkout_to_unified', 1);

/**
 * Enqueue camp checkout assets on relevant pages
 */
function ptp_enqueue_camp_assets() {
    if (is_page(array('camp-checkout', 'camps', 'register-camp', 'camp-thank-you', 'ptp-checkout'))) {
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
    }
}
add_action('wp_enqueue_scripts', 'ptp_enqueue_camp_assets');

/**
 * REST API endpoint for getting camps (for frontend/mobile)
 */
function ptp_register_camps_rest_routes() {
    register_rest_route('ptp/v1', '/camps', array(
        'methods' => 'GET',
        'callback' => function($request) {
            $camps = PTP_Camp_Checkout::get_camp_products(array(
                'active' => true,
                'type' => $request->get_param('type') ?: 'camp',
            ));
            return rest_ensure_response(array('camps' => $camps));
        },
        'permission_callback' => '__return_true',
    ));
    
    register_rest_route('ptp/v1', '/camps/(?P<id>[a-zA-Z0-9_]+)', array(
        'methods' => 'GET',
        'callback' => function($request) {
            global $wpdb;
            $id = $request->get_param('id');
            
            $camp = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}ptp_stripe_products
                WHERE stripe_product_id = %s OR id = %d
            ", $id, intval($id)));
            
            if (!$camp) {
                return new WP_Error('not_found', 'Camp not found', array('status' => 404));
            }
            
            $camp->price = $camp->price_cents ? ($camp->price_cents / 100) : 0;
            
            return rest_ensure_response($camp);
        },
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'ptp_register_camps_rest_routes');

/**
 * Register [ptp_camps] shortcode for camps listing
 */
function ptp_camps_listing_shortcode($atts) {
    ob_start();
    include PTP_PLUGIN_DIR . 'templates/camp/camps-listing.php';
    return ob_get_clean();
}
add_shortcode('ptp_camps', 'ptp_camps_listing_shortcode');

/**
 * Cron job to send camp reminders
 */
function ptp_schedule_camp_reminders() {
    if (!wp_next_scheduled('ptp_send_camp_reminders')) {
        wp_schedule_event(time(), 'daily', 'ptp_send_camp_reminders');
    }
}
add_action('wp', 'ptp_schedule_camp_reminders');

function ptp_send_camp_reminders_cron() {
    global $wpdb;
    
    // Find orders with camps starting in 7 days
    $seven_days = date('Y-m-d', strtotime('+7 days'));
    
    $orders = $wpdb->get_results($wpdb->prepare("
        SELECT DISTINCT o.id 
        FROM {$wpdb->prefix}ptp_unified_camp_orders o
        JOIN {$wpdb->prefix}ptp_camp_order_items oi ON o.id = oi.order_id
        WHERE o.status = 'completed'
        AND oi.camp_dates LIKE %s
        AND o.id NOT IN (
            SELECT order_id FROM {$wpdb->prefix}ptp_camp_order_meta 
            WHERE meta_key = 'reminder_7day_sent'
        )
    ", '%' . $seven_days . '%'));
    
    foreach ($orders as $order) {
        PTP_Camp_Emails::send_camp_reminder($order->id, 7);
        
        // Mark as sent
        $wpdb->insert($wpdb->prefix . 'ptp_camp_order_meta', array(
            'order_id' => $order->id,
            'meta_key' => 'reminder_7day_sent',
            'meta_value' => current_time('mysql'),
        ));
    }
}
add_action('ptp_send_camp_reminders', 'ptp_send_camp_reminders_cron');

/**
 * Load custom template for summer-camps product tag
 */
function ptp_summer_camps_template($template) {
    // Check if this is the summer-camps product tag archive
    if (is_tax('product_tag', 'summer-camps') || 
        (is_page() && get_query_var('pagename') === 'summer-camps') ||
        (is_page() && get_post_field('post_name', get_the_ID()) === 'summer-camps')) {
        
        $custom_template = PTP_PLUGIN_DIR . 'templates/archive-summer-camps.php';
        if (file_exists($custom_template)) {
            return $custom_template;
        }
    }
    
    // Also check for camps page
    if (is_page('camps') || is_page('summer-camps')) {
        $custom_template = PTP_PLUGIN_DIR . 'templates/archive-summer-camps.php';
        if (file_exists($custom_template)) {
            return $custom_template;
        }
    }
    
    return $template;
}
add_filter('template_include', 'ptp_summer_camps_template', 99);

/**
 * Register page template for summer camps
 */
function ptp_register_summer_camps_page_template($templates) {
    $templates['templates/archive-summer-camps.php'] = 'PTP Summer Camps Archive';
    return $templates;
}
add_filter('theme_page_templates', 'ptp_register_summer_camps_page_template');

/**
 * Load the seeder file
 */
require_once PTP_PLUGIN_DIR . 'includes/ptp-camp-products-seeder.php';

/**
 * Register single camp product page template
 * Note: Disabled - camp templates now handled by ptp-camps plugin and theme files
 */
// add_filter('theme_page_templates', function($templates) {
//     $templates['single-camp-product.php'] = 'PTP Camp Product Page';
//     return $templates;
// });

/**
 * Load single camp product template
 * 
 * Note: Disabled - camp templates now handled by ptp-camps plugin 
 * which uses theme files (ptp-camp-template.php, ptp-camp.css)
 */
add_filter('template_include', function($template) {
    // Camp templates are handled by ptp-camps plugin
    // No action needed here
    return $template;;
}, 100);
