<?php
/**
 * PTP Unified Order Email v116
 * 
 * Sends a single combined confirmation email for orders containing:
 * - Camps/Clinics (PTP Native products)
 * - Training Sessions (via PTP_Training_Woo_Integration)
 * - Or both together
 * 
 * Hooks into the existing email wiring system but provides enhanced
 * content when orders contain multiple item types.
 * 
 * @since 116.0.0
 */

defined('ABSPATH') || exit;

class PTP_Unified_Order_Email {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hook into the existing email content filter with high priority
        // REMOVED: add_filter('ptp-native_mail_content', array($this, 'maybe_use_unified_email'), 5);
        
        // Override the render_order_email in PTP_PTP Native_Emails
        add_filter('ptp_render_order_email', array($this, 'render_unified_email'), 10, 2);
        
        // Also hook into the order email wiring
        add_action('ptp_before_send_order_email', array($this, 'prepare_unified_email'), 10, 2);
    }
    
    /**
     * Check if order needs unified email (has both camps and training)
     */
    public static function order_needs_unified_email($order) {
        if (!$order) {
            return false;
        }
        
        $has_training = false;
        $has_camp = false;
        
        foreach ($order->get_items() as $item) {
            // Check for training
            if ($item->get_meta('_ptp_training') === 'yes' || $item->get_meta('_ptp_item_type') === 'training') {
                $has_training = true;
            }
            
            // Check for camp
            $product_id = $item->get_product_id();
            if (get_post_meta($product_id, '_ptp_is_camp', true) === 'yes' ||
                has_term(array('camps', 'clinics', 'camp', 'summer-camps'), 'product_cat', $product_id)) {
                $has_camp = true;
            }
        }
        
        // Return true if order has both, or just training (since training needs special handling)
        return ($has_training && $has_camp) || $has_training;
    }
    
    /**
     * Maybe use unified email content
     */
    public function maybe_use_unified_email($content) {
        // This filter gets the current email content
        // We'll check if it's for an order with mixed items
        return $content;
    }
    
    /**
     * Prepare unified email before sending
     */
    public function prepare_unified_email($order, $email_body) {
        // Can be used to modify email before sending
        return $email_body;
    }
    
    /**
     * Render unified order confirmation email
     * 
     * @param string $existing_html Existing email HTML (if any)
     * @param PTP_Order $order The order object
     * @return string Complete email HTML
     */
    public function render_unified_email($existing_html, $order) {
        if (!$order) {
            return $existing_html;
        }
        
        // Get training and camp items
        $training_items = $this->get_training_items($order);
        $camp_items = $this->get_camp_items($order);
        
        // If no special items, return existing HTML
        if (empty($training_items) && empty($camp_items)) {
            return $existing_html;
        }
        
        // Build unified email
        return $this->build_unified_email_html($order, $training_items, $camp_items);
    }
    
    /**
     * Build the unified email HTML
     */
    private function build_unified_email_html($order, $training_items, $camp_items) {
        // Settings
        $logo = get_option('ptp_email_logo_url', '');
        if (empty($logo)) {
            $logo = get_option('ptp-native_email_header_image', '');
        }
        if (empty($logo)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo = wp_get_attachment_image_url($custom_logo_id, 'medium');
            }
        }
        
        $support_phone = get_option('ptp_email_support_phone', ptp_email_brand('support_phone'));
        $support_email = get_option('ptp_email_support_email', ptp_email_brand('support_email'));
        
        // Order data
        $customer_name = $order->get_billing_first_name();
        $order_number = $order->get_order_number();
        $order_total = $order->get_total();
        $order_date = $order->get_date_created()->format('F j, Y \a\t g:i A');
        
        // Determine email type
        $has_both = !empty($training_items) && !empty($camp_items);
        $training_only = !empty($training_items) && empty($camp_items);
        $camp_only = empty($training_items) && !empty($camp_items);
        
        // Set header based on content
        if ($has_both) {
            $header_title = 'Registration Confirmed!';
            $header_subtitle = 'Camp + Training Package';
            $header_bg = 'linear-gradient(135deg, #0E0F11 0%, #1a1a1a 100%)';
        } elseif ($training_only) {
            $header_title = 'Training Booked!';
            $header_subtitle = 'Private Training Session';
            $header_bg = 'linear-gradient(135deg, #0E0F11 0%, #1F2937 100%)';
        } else {
            $header_title = 'Registration Confirmed!';
            $header_subtitle = 'Camp Registration';
            $header_bg = 'linear-gradient(135deg, #0E0F11 0%, #1a1a1a 100%)';
        }
        
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <title><?php echo esc_html($header_title); ?></title>
    <style>
        body { margin: 0; padding: 0; width: 100%; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .wrapper { width: 100%; background-color: #f3f4f6; padding: 40px 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .card { background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { padding: 40px 32px; text-align: center; color: #ffffff; }
        .content { padding: 32px; }
        .section { margin-bottom: 32px; }
        .section-title { font-size: 14px; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 16px; padding-bottom: 8px; border-bottom: 2px solid #FCB900; }
        .item-card { background: #F9FAFB; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .item-card:last-child { margin-bottom: 0; }
        .trainer-photo { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid #FCB900; }
        .detail-row { display: flex; padding: 10px 0; border-bottom: 1px solid #E5E7EB; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: #6B7280; font-size: 13px; width: 100px; flex-shrink: 0; }
        .detail-value { color: #111827; font-size: 14px; font-weight: 600; }
        .cta-button { display: inline-block; background: #FCB900; color: #0E0F11; padding: 16px 40px; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 15px; text-transform: uppercase; }
        .footer { padding: 32px; text-align: center; color: #6B7280; font-size: 13px; }
        @media only screen and (max-width: 600px) {
            .content { padding: 24px 16px; }
            .header { padding: 32px 20px; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <!-- Logo -->
            <?php if ($logo): ?>
            <div style="text-align: center; padding: 24px 0;">
                <a href="<?php echo esc_url(home_url()); ?>">
                    <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr(ptp_email_brand('company')); ?>" style="max-width: 120px; height: auto;">
                </a>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <!-- Header -->
                <div class="header" style="background: <?php echo $header_bg; ?>;">
                    <div style="width: 64px; height: 64px; background: rgba(252, 185, 0, 0.2); border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                        <span style="font-size: 28px;">✓</span>
                    </div>
                    <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #ffffff;"><?php echo esc_html($header_title); ?></h1>
                    <p style="margin: 8px 0 0; color: #FCB900; font-size: 14px; font-weight: 600;"><?php echo esc_html($header_subtitle); ?></p>
                </div>
                
                <div class="content">
                    <!-- Greeting -->
                    <p style="margin: 0 0 24px; font-size: 16px; color: #374151;">
                        Hi <?php echo esc_html($customer_name); ?>, your registration is confirmed! Here's everything you need to know:
                    </p>
                    
                    <!-- Order Summary -->
                    <div style="background: #F9FAFB; border-radius: 8px; padding: 16px; margin-bottom: 32px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <p style="margin: 0; font-size: 12px; color: #6B7280; text-transform: uppercase;">Order #<?php echo esc_html($order_number); ?></p>
                                <p style="margin: 4px 0 0; font-size: 13px; color: #374151;"><?php echo esc_html($order_date); ?></p>
                            </div>
                            <div style="text-align: right;">
                                <p style="margin: 0; font-size: 12px; color: #6B7280; text-transform: uppercase;">Total</p>
                                <p style="margin: 4px 0 0; font-size: 20px; font-weight: 700; color: #059669;">$<?php echo number_format($order_total, 2); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($training_items)): ?>
                    <!-- Training Sessions Section -->
                    <div class="section">
                        <h3 class="section-title">🏃 Training Sessions</h3>
                        
                        <?php foreach ($training_items as $training): ?>
                        <div class="item-card">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="80" style="vertical-align: top; padding-right: 16px;">
                                        <?php if (!empty($training['trainer_photo'])): ?>
                                            <img src="<?php echo esc_url($training['trainer_photo']); ?>" alt="" class="trainer-photo">
                                        <?php else: ?>
                                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($training['trainer_name']); ?>&size=64&background=FCB900&color=0A0A0A&bold=true" alt="" class="trainer-photo">
                                        <?php endif; ?>
                                    </td>
                                    <td style="vertical-align: top;">
                                        <p style="margin: 0 0 4px; font-size: 16px; font-weight: 700; color: #0E0F11;">
                                            <?php echo esc_html($training['trainer_name']); ?>
                                        </p>
                                        <p style="margin: 0; font-size: 12px; color: #6B7280; text-transform: uppercase;"><?php echo esc_html(ptp_email_brand('company')); ?> Trainer</p>
                                    </td>
                                    <td style="text-align: right; vertical-align: top;">
                                        <p style="margin: 0; font-size: 18px; font-weight: 700; color: #059669;">
                                            $<?php echo number_format($training['price'], 2); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #E5E7EB;">
                                <?php if (!empty($training['player_name'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Player</span>
                                    <span class="detail-value">
                                        <?php echo esc_html($training['player_name']); ?>
                                        <?php if (!empty($training['player_age'])): ?>
                                            (Age <?php echo esc_html($training['player_age']); ?>)
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($training['session_date'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Date</span>
                                    <span class="detail-value"><?php echo date('l, F j, Y', strtotime($training['session_date'])); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($training['start_time'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Time</span>
                                    <span class="detail-value"><?php echo date('g:i A', strtotime($training['start_time'])); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($training['location'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Location</span>
                                    <span class="detail-value"><?php echo esc_html($training['location']); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($training['package_type']) && $training['package_type'] !== 'single'): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Package</span>
                                    <span class="detail-value"><?php echo esc_html($training['sessions']); ?> Sessions</span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (empty($training['session_date'])): ?>
                                <div style="background: #FEF3C7; border-radius: 6px; padding: 12px; margin-top: 12px;">
                                    <p style="margin: 0; font-size: 13px; color: #92400E;">
                                        <strong>📅 Schedule Your Session:</strong> Your trainer will reach out to confirm the date and time.
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($camp_items)): ?>
                    <!-- Camps Section -->
                    <div class="section">
                        <h3 class="section-title">⚽ Camp Registrations</h3>
                        
                        <?php foreach ($camp_items as $camp): ?>
                        <div class="item-card">
                            <div style="margin-bottom: 12px;">
                                <span style="display: inline-block; background: <?php echo $camp['camp_type'] === 'clinic' ? '#DBEAFE' : '#0E0F11'; ?>; color: <?php echo $camp['camp_type'] === 'clinic' ? '#1E40AF' : '#FCB900'; ?>; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 4px; text-transform: uppercase;">
                                    <?php echo $camp['camp_type'] === 'clinic' ? 'Skills Clinic' : 'Camp'; ?>
                                </span>
                            </div>
                            
                            <h4 style="margin: 0 0 12px; font-size: 18px; font-weight: 700; color: #0E0F11;">
                                <?php echo esc_html($camp['name']); ?>
                            </h4>
                            
                            <?php if (!empty($camp['player_name'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Camper</span>
                                <span class="detail-value">
                                    <?php echo esc_html($camp['player_name']); ?>
                                    <?php if (!empty($camp['player_age'])): ?>
                                        (Age <?php echo esc_html($camp['player_age']); ?>)
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($camp['start_date'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Dates</span>
                                <span class="detail-value">
                                    <?php 
                                    echo date('M j', strtotime($camp['start_date']));
                                    if (!empty($camp['end_date']) && $camp['end_date'] !== $camp['start_date']) {
                                        echo ' - ' . date('M j, Y', strtotime($camp['end_date']));
                                    } else {
                                        echo ', ' . date('Y', strtotime($camp['start_date']));
                                    }
                                    ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($camp['daily_start']) && !empty($camp['daily_end'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Time</span>
                                <span class="detail-value">
                                    <?php echo date('g:i A', strtotime($camp['daily_start'])); ?> - <?php echo date('g:i A', strtotime($camp['daily_end'])); ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($camp['location_name'])): ?>
                            <div class="detail-row">
                                <span class="detail-label">Location</span>
                                <span class="detail-value">
                                    <?php echo esc_html($camp['location_name']); ?>
                                    <?php if (!empty($camp['city']) && !empty($camp['state'])): ?>
                                        <br><span style="font-weight: 400; color: #6B7280;"><?php echo esc_html($camp['city'] . ', ' . $camp['state']); ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="detail-row" style="border-bottom: none; padding-top: 12px;">
                                <span class="detail-label">Price</span>
                                <span class="detail-value" style="color: #059669; font-size: 16px;">$<?php echo number_format($camp['price'], 2); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- What to Bring (if training) -->
                    <?php if (!empty($training_items)): ?>
                    <div class="section">
                        <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 12px; padding: 20px;">
                            <h4 style="margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #166534;">📝 Before Your Training Session</h4>
                            <ul style="margin: 0; padding: 0 0 0 20px; color: #166534; font-size: 14px; line-height: 1.8;">
                                <li>Bring a soccer ball, water, and cleats</li>
                                <li>Arrive 5 minutes early to warm up</li>
                                <li>Message your trainer if plans change</li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- CTA Button -->
                    <div style="text-align: center; margin: 32px 0;">
                        <a href="<?php echo esc_url(home_url('/parent-dashboard/')); ?>" class="cta-button">
                            View Order Details
                        </a>
                    </div>
                    
                    <!-- Help Section -->
                    <div style="text-align: center; padding: 24px 0; border-top: 1px solid #E5E7EB;">
                        <p style="margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #0E0F11;">Questions? We're here to help!</p>
                        <p style="margin: 0; font-size: 14px; color: #6B7280;">
                            Call or text <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $support_phone)); ?>" style="color: #FCB900; text-decoration: none; font-weight: 600;"><?php echo esc_html($support_phone); ?></a>
                            <br>
                            Email <a href="mailto:<?php echo esc_attr($support_email); ?>" style="color: #FCB900; text-decoration: none;"><?php echo esc_html($support_email); ?></a>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="footer">
                <p style="margin: 0 0 12px; font-size: 12px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 1px;">
                    PA · NJ · DE · MD · NY
                </p>
                <p style="margin: 0 0 12px; font-size: 12px;">
                    <a href="<?php echo esc_url(home_url()); ?>" style="color: #6B7280; text-decoration: none;"><?php echo esc_html(str_replace(array('https://', 'http://'), '', home_url())); ?></a>
                </p>
                <p style="margin: 0; font-size: 11px; color: #9CA3AF;">
                    &copy; <?php echo date('Y'); ?> <?php echo esc_html(ptp_email_brand('company')); ?>. All rights reserved.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get training items from order (uses integration class if available)
     */
    private function get_training_items($order) {
        if (class_exists('PTP_Training_Woo_Integration')) {
            return PTP_Training_Woo_Integration::get_training_items($order);
        }
        
        // Fallback implementation
        $items = array();
        global $wpdb;
        
        foreach ($order->get_items() as $item_id => $item) {
            if ($item->get_meta('_ptp_training') !== 'yes' && $item->get_meta('_ptp_item_type') !== 'training') {
                continue;
            }
            
            $trainer_id = $item->get_meta('_trainer_id');
            $trainer = null;
            if ($trainer_id) {
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $trainer_id
                ));
            }
            
            $items[] = array(
                'item_id' => $item_id,
                'trainer_name' => $item->get_meta('Trainer') ?: ($trainer ? $trainer->display_name : 'Trainer'),
                'trainer_photo' => $item->get_meta('_trainer_photo') ?: ($trainer ? $trainer->photo_url : ''),
                'player_name' => $item->get_meta('Player Name'),
                'player_age' => $item->get_meta('Player Age'),
                'session_date' => $item->get_meta('_session_date'),
                'start_time' => $item->get_meta('_start_time'),
                'location' => $item->get_meta('Location'),
                'package_type' => $item->get_meta('_package_type') ?: 'single',
                'sessions' => $item->get_meta('_sessions') ?: 1,
                'price' => $item->get_total(),
            );
        }
        
        return $items;
    }
    
    /**
     * Get camp items from order (uses integration class if available)
     */
    private function get_camp_items($order) {
        if (class_exists('PTP_Training_Woo_Integration')) {
            return PTP_Training_Woo_Integration::get_camp_items($order);
        }
        
        // Fallback implementation
        $items = array();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            
            // Skip training items
            if ($item->get_meta('_ptp_training') === 'yes') {
                continue;
            }
            
            $is_camp = get_post_meta($product_id, '_ptp_is_camp', true) === 'yes' ||
                       has_term(array('camps', 'clinics', 'camp', 'summer-camps'), 'product_cat', $product_id);
            
            if (!$is_camp) {
                continue;
            }
            
            $items[] = array(
                'item_id' => $item_id,
                'product_id' => $product_id,
                'name' => $item->get_name(),
                'price' => $item->get_total(),
                'camp_type' => get_post_meta($product_id, '_ptp_camp_type', true) ?: 'camp',
                'start_date' => get_post_meta($product_id, '_ptp_start_date', true),
                'end_date' => get_post_meta($product_id, '_ptp_end_date', true),
                'daily_start' => get_post_meta($product_id, '_ptp_daily_start', true),
                'daily_end' => get_post_meta($product_id, '_ptp_daily_end', true),
                'location_name' => get_post_meta($product_id, '_ptp_location_name', true),
                'city' => get_post_meta($product_id, '_ptp_city', true),
                'state' => get_post_meta($product_id, '_ptp_state', true),
                'player_name' => $item->get_meta('Player Name'),
                'player_age' => $item->get_meta('Player Age'),
            );
        }
        
        return $items;
    }
    
    /**
     * Static helper to render unified email
     */
    public static function render($order) {
        return self::instance()->render_unified_email('', $order);
    }
}

// Initialize
add_action('plugins_loaded', function() {
    if (false) {
        PTP_Unified_Order_Email::instance();
    }
}, 25);
