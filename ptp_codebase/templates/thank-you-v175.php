<?php
/**
 * Thank You Page v175 - Complete Upsell Flow
 * 
 * FLOW:
 * 1. Hero + Order Confirmation
 * 2. PRIMARY: 1-on-1 Training Upsell (one-click purchase)
 * 3. SECONDARY: Add Another Camp (15% off, sibling or second week)
 * 4. What's Next steps
 * 5. TERTIARY: Referral program
 * 6. Footer actions
 * 
 * All upsells use one-click purchase with saved payment method
 */
defined('ABSPATH') || exit;

global $wpdb;

// ============================================
// GET ORDER DATA
// ============================================
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : (isset($_GET['order']) ? intval($_GET['order']) : 0);
$session_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : (isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '');
$payment_intent_id = isset($_GET['payment_intent']) ? sanitize_text_field($_GET['payment_intent']) : '';

$order = null;
$order_items = array();
$camper_name = '';
$camper_first = '';
$camp_name = '';
$camp_dates = '';
$camp_location = '';
$camp_time = '9AM - 3PM';
$camp_product_id = ''; // Stripe product ID
$total_amount = 0;
$parent_email = '';
$parent_phone = '';
$order_number = '';
$stripe_customer_id = '';

// Try to load order
if ($order_id) {
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d",
        $order_id
    ));
} elseif ($payment_intent_id) {
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE stripe_payment_intent_id = %s",
        $payment_intent_id
    ));
} elseif ($session_id) {
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE checkout_session_id = %s",
        $session_id
    ));
}

if ($order) {
    $order_id = $order->id;
    $order_number = $order->order_number ?: 'PTP-' . strtoupper(substr(md5($order_id), 0, 6));
    $total_amount = floatval($order->total_amount);
    $parent_email = $order->billing_email ?? '';
    $parent_phone = $order->billing_phone ?? '';
    $stripe_customer_id = $order->stripe_customer_id ?? '';
    
    // Get order items
    $order_items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = %d",
        $order_id
    ));
    
    if (!empty($order_items)) {
        $item = $order_items[0];
        $camper_first = $item->camper_first_name ?? '';
        $camper_name = trim(($item->camper_first_name ?? '') . ' ' . ($item->camper_last_name ?? ''));
        $camp_name = $item->camp_name ?? $item->product_name ?? 'PTP Soccer Camp';
        $camp_dates = $item->camp_dates ?? '';
        $camp_location = $item->camp_location ?? '';
        $camp_time = $item->camp_time ?? '9AM - 3PM';
        $camp_product_id = $item->stripe_product_id ?? ''; // Stripe product ID of purchased camp
    }
}

// ============================================
// v211: AUTO-CREATE TRAINING BOOKING IF PAYMENT SUCCEEDED
// The cart page's ptp_save_checkout saves training data to a transient.
// After Stripe redirects here, we create the actual ptp_bookings record.
// ============================================
$redirect_status = isset($_GET['redirect_status']) ? sanitize_text_field($_GET['redirect_status']) : '';
$booking = null;
$has_training = false;
$training_booking_id = 0;
$double_booking_error = '';

if ($redirect_status === 'succeeded' && $payment_intent_id && $session_id) {
    // Check if booking already exists for this payment
    $existing_booking = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s",
        $payment_intent_id
    ));
    
    $already_processed = get_transient('ptp_processed_' . $payment_intent_id);
    
    if (!$existing_booking && !$already_processed) {
        $checkout_data = get_transient('ptp_checkout_' . $session_id);
        if ($checkout_data && !empty($checkout_data['trainer_id']) && ($checkout_data['training_total'] ?? 0) > 0) {
            ptp_log('[PTP Thank You v211] Creating training booking for PI: ' . $payment_intent_id);
            ptp_log('[PTP Thank You v211] trainer_id: ' . $checkout_data['trainer_id'] . ', package: ' . ($checkout_data['training_package'] ?? 'single') . ', total: $' . $checkout_data['training_total']);
            
            // Verify payment with Stripe
            $stripe_verified = false;
            $intent = array();
            $secret_key = get_option('ptp_stripe_test_mode', true) 
                ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
                : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
            
            if (!empty($secret_key)) {
                $stripe_resp = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
                    'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                    'timeout' => 15,
                ));
                if (!is_wp_error($stripe_resp)) {
                    $intent = json_decode(wp_remote_retrieve_body($stripe_resp), true);
                    $stripe_verified = (($intent['status'] ?? '') === 'succeeded');
                }
            }
            
            if ($stripe_verified && class_exists('PTP_Unified_Checkout')) {
                $unified = PTP_Unified_Checkout::instance();
                $create_result = $unified->create_orders_from_session_public($checkout_data, $payment_intent_id, $intent);
                
                // v236: Handle double-booking conflict — refund + show message
                if (!empty($create_result['error']) && !empty($create_result['conflict_booking_id'])) {
                    $double_booking_error = $create_result['error'];
                    ptp_log('[PTP Thank You v236] Double booking detected for PI: ' . $payment_intent_id . ' — conflict with booking #' . $create_result['conflict_booking_id']);
                    
                    // Auto-refund the payment
                    $stripe_sk = get_option('ptp_stripe_test_mode', true) 
                        ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
                        : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
                    if (!empty($stripe_sk) && !empty($payment_intent_id)) {
                        $refund_resp = wp_remote_post('https://api.stripe.com/v1/refunds', array(
                            'headers' => array('Authorization' => 'Bearer ' . $stripe_sk, 'Content-Type' => 'application/x-www-form-urlencoded'),
                            'body' => array('payment_intent' => $payment_intent_id, 'reason' => 'duplicate'),
                            'timeout' => 15,
                        ));
                        $refund_ok = !is_wp_error($refund_resp);
                        $refund_body = $refund_ok ? json_decode(wp_remote_retrieve_body($refund_resp), true) : array();
                        if (!empty($refund_body['id'])) {
                            ptp_log('[PTP Thank You v236] Auto-refund created: ' . $refund_body['id']);
                        } else {
                            ptp_log('[PTP Thank You v236] Auto-refund FAILED for PI: ' . $payment_intent_id);
                        }
                    }
                    
                    // Mark as processed so webhook doesn't also try
                    set_transient('ptp_processed_' . $payment_intent_id, true, DAY_IN_SECONDS);
                    delete_transient('ptp_checkout_' . $session_id);
                }
                
                if (!empty($create_result['booking_id'])) {
                    $training_booking_id = $create_result['booking_id'];
                    ptp_log('[PTP Thank You v211] Training booking created: ' . $training_booking_id);
                }
                if (!empty($create_result['order_id']) && !$order_id) {
                    $order_id = $create_result['order_id'];
                    // Re-load order
                    $order = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d", $order_id
                    ));
                    if ($order) {
                        $order_items = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = %d", $order_id
                        ));
                        $total_amount = floatval($order->total_amount);
                        $parent_email = $order->billing_email ?? '';
                    }
                }
                
                // Mark as processed
                set_transient('ptp_processed_' . $payment_intent_id, true, DAY_IN_SECONDS);
                delete_transient('ptp_checkout_' . $session_id);
                
                // Set cookies
                if (!headers_sent()) {
                    if ($training_booking_id) setcookie('ptp_last_booking', $training_booking_id, time() + 3600, '/');
                    if ($order_id) setcookie('ptp_last_order', $order_id, time() + 3600, '/');
                }
            } else {
                ptp_log('[PTP Thank You v211] Payment verification failed for PI: ' . $payment_intent_id);
            }
        }
    } else if ($existing_booking) {
        $training_booking_id = intval($existing_booking);
    }
}

// v211: Also look up training booking by payment_intent even if not just created
if (!$training_booking_id && $payment_intent_id) {
    $training_booking_id = intval($wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s ORDER BY id DESC LIMIT 1",
        $payment_intent_id
    )) ?: 0);
}

// v134: Look up free session booking by ID from URL or by session transient
if (!$training_booking_id && !empty($_GET['booking'])) {
    $training_booking_id = intval($_GET['booking']);
}
if (!$training_booking_id && !empty($_GET['free']) && $session_id) {
    // Check if the free checkout left a processed marker
    $processed = get_transient('ptp_processed_' . $session_id);
    if ($processed) {
        // Find the most recent free_session booking
        $training_booking_id = intval($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_status = 'free_session' AND payment_intent_id = %s ORDER BY id DESC LIMIT 1",
            'free_session_' . $session_id
        )) ?: 0);
        // Fallback: look by checkout session in notes
        if (!$training_booking_id) {
            $training_booking_id = intval($wpdb->get_var(
                "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_status = 'free_session' ORDER BY id DESC LIMIT 1"
            ) ?: 0);
        }
    }
}

// v211: Load training booking data for display
if ($training_booking_id) {
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug
         FROM {$wpdb->prefix}ptp_bookings b
         LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
         WHERE b.id = %d",
        $training_booking_id
    ));
    if ($booking) {
        $has_training = true;
        $total_amount = $total_amount ?: floatval($booking->total_amount ?? $booking->amount_paid ?? 0);
        if (!$parent_email) {
            $parent_email = $booking->guest_email ?? '';
            if (!$parent_email && !empty($booking->parent_id)) {
                $parent_email = $wpdb->get_var($wpdb->prepare(
                    "SELECT email FROM {$wpdb->prefix}ptp_parents WHERE id = %d", $booking->parent_id
                ));
            }
        }
        // Set camper info from booking
        if (empty($camper_first) && !empty($booking->player_id)) {
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}ptp_players WHERE id = %d", $booking->player_id
            ));
            if ($player) {
                $camper_first = $player->first_name;
                $camper_name = trim($player->first_name . ' ' . $player->last_name);
            }
        }
        ptp_log('[PTP Thank You v211] Training booking loaded: #' . $training_booking_id . ' with trainer ' . ($booking->trainer_name ?? 'unknown'));
    }
}
$camper_display = $camper_first ?: 'Your Player';
$camper_upper = strtoupper($camper_first ?: 'YOUR PLAYER');

// Generate referral code
$referral_code = strtoupper(substr(preg_replace('/[^a-z]/i', '', $camper_first ?: 'PTP'), 0, 4)) . '-' . strtoupper(substr(md5($order_id . 'ptp2025'), 0, 4));

// ============================================
// UPSELL 1: TRAINING SESSION
// ============================================
$featured_trainer = $wpdb->get_row("
    SELECT id, display_name, slug, photo_url, tagline, credentials,
           COALESCE(hourly_rate, 75) as hourly_rate
    FROM {$wpdb->prefix}ptp_trainers 
    WHERE status = 'active' AND photo_url IS NOT NULL AND photo_url != ''
    ORDER BY RAND() 
    LIMIT 1
");

$training_regular = 75;
$training_sale = 45;
$training_savings = $training_regular - $training_sale;

// Check if training upsell already added
$training_added = get_transient('ptp_upsell_training_' . $order_id);

// Social proof
$training_count = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
") ?: 47;

// ============================================
// UPSELL 2: ANOTHER CAMP (15% OFF)
// ============================================
// Get upcoming camps from ptp_stripe_products table
// Exclude the camp they just purchased
$purchased_stripe_id = $camp_product_id ?: 'none';

$upcoming_camps = $wpdb->get_results($wpdb->prepare("
    SELECT 
        p.id,
        p.stripe_product_id,
        p.stripe_price_id,
        p.name as camp_name,
        p.price_cents,
        p.camp_dates,
        p.camp_location,
        p.camp_time,
        p.camp_capacity,
        p.camp_registered,
        (COALESCE(p.camp_capacity, 999) - COALESCE(p.camp_registered, 0)) as spots_remaining
    FROM {$wpdb->prefix}ptp_stripe_products p
    WHERE p.active = 1
    AND p.product_type = 'camp'
    AND p.stripe_product_id != %s
    AND p.price_cents > 0
    AND (p.camp_capacity IS NULL OR p.camp_capacity > COALESCE(p.camp_registered, 0))
    ORDER BY p.sort_order ASC, p.id DESC
    LIMIT 3
", $purchased_stripe_id));

// Camp discount
$camp_discount_percent = 15;

// Check if camp upsell already added
$camp_added = get_transient('ptp_upsell_camp_' . $order_id);

// Count of parents who added second camp
$second_camp_count = $wpdb->get_var("
    SELECT COUNT(DISTINCT parent_email) 
    FROM {$wpdb->prefix}ptp_unified_camp_orders 
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY parent_email
    HAVING COUNT(*) > 1
") ?: 23;

get_header();
?>

<!-- v216: Google Ads Purchase Conversion Tracking -->
<script async src="https://www.googletagmanager.com/gtag/js?id=AW-16949170445"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', 'AW-16949170445');
gtag('event', 'conversion', {
    'send_to': 'AW-16949170445/vxJCCNnsgfcbEI2i_5E_',
    'transaction_id': '<?php echo esc_js($payment_intent_id ?: ($order ? $order->id : '')); ?>'
});
</script>

<!-- v228: CSS extracted to external cacheable file -->
<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-tokens.css">
<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-thank-you.css">

<div class="ptp-ty">
    
    <!-- HERO -->
    <div class="ptp-ty-hero">
        <div class="ptp-ty-check">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h1 class="ptp-ty-title">
            <span class="gold"><?php echo esc_html($camper_upper); ?></span> IS IN!
        </h1>
        <p class="ptp-ty-subtitle">
            Confirmation sent to <strong><?php echo esc_html($parent_email ?: 'your email'); ?></strong>
        </p>
    </div>
    
    <?php if (!empty($double_booking_error)): ?>
    <!-- v236: Double booking conflict banner -->
    <div style="max-width:540px;margin:0 auto 20px;padding:16px 20px;background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;font-family:Inter,system-ui,sans-serif;color:#991B1B;font-size:14px;line-height:1.6">
        <strong style="display:block;font-size:16px;margin-bottom:6px">This time slot was just booked</strong>
        Another parent grabbed this time slot moments before your payment went through.
        Your card has been automatically refunded — you should see the refund within 5-10 business days.
        <br><br>
        <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" style="display:inline-block;padding:10px 20px;background:#FCB900;color:#0A0A0A;font-weight:700;border-radius:8px;text-decoration:none;font-size:14px;text-transform:uppercase;letter-spacing:0.5px">Pick a New Time</a>
    </div>
    <?php endif; ?>

    <div class="ptp-ty-container">
        
        <!-- ORDER SUMMARY -->
        <div class="ptp-ty-card">
            <div class="ptp-ty-card-header">
                <span class="ptp-ty-card-label"><?php 
                    if ($order_number) echo 'Order #' . esc_html($order_number);
                    elseif ($booking) echo 'Booking #' . esc_html($booking->booking_number ?? $training_booking_id);
                    else echo 'Confirmation';
                ?></span>
                <span class="ptp-ty-card-value">$<?php echo number_format($total_amount, 2); ?></span>
            </div>
            <div class="ptp-ty-card-body">
                <?php if ($order && !empty($order_items)): // Camp order items ?>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Camp</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html($camp_name); ?></span>
                </div>
                <?php if ($camp_dates): ?>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Dates</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html($camp_dates); ?></span>
                </div>
                <?php endif; ?>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Time</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html($camp_time); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($has_training && $booking): // Training booking ?>
                <?php 
                    $pkg_names = array('single' => 'Single Session', 'pack3' => '3-Session Pack', 'pack5' => '5-Session Pack');
                    $pkg_display = $pkg_names[$booking->package_type ?? ''] ?? ($booking->total_sessions ?? 1) . '-Session Package';
                ?>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Training</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html($pkg_display); ?></span>
                </div>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Trainer</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html($booking->trainer_name); ?></span>
                </div>
                <?php if ($booking->session_date && !preg_match('/^0000/', $booking->session_date) && strtotime($booking->session_date) > 0 && intval(date('Y', strtotime($booking->session_date))) >= 2020): ?>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Date</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html(date('l, M j, Y', strtotime($booking->session_date))); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($booking->start_time): ?>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Time</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html(date('g:i A', strtotime($booking->start_time))); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($booking->location): ?>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Location</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html($booking->location); ?><?php 
                        // v211: Show address from location_notes if available
                        if (!empty($booking->location_notes)) {
                            $notes = $booking->location_notes;
                            // Extract address (before GPS coordinates)
                            $addr = strpos($notes, ' | GPS:') !== false ? substr($notes, 0, strpos($notes, ' | GPS:')) : $notes;
                            if ($addr && $addr !== $booking->location) {
                                echo '<br><small style="opacity:.6;font-size:12px">' . esc_html($addr) . '</small>';
                            }
                        }
                    ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($camper_name): ?>
                <div class="ptp-ty-detail">
                    <span class="ptp-ty-detail-label">Player</span>
                    <span class="ptp-ty-detail-value"><?php echo esc_html($camper_name); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ============================================ -->
        <!-- UPSELL 1: TRAINING SESSION (PRIMARY) -->
        <!-- ============================================ -->
        <?php if (!$training_added && !$has_training && $featured_trainer): ?>
        <div class="ptp-ty-upsell" id="training-upsell">
            <div class="ptp-ty-upsell-badge"><?php echo intval($training_count); ?> parents added this week</div>
            
            <div class="ptp-ty-upsell-header">
                <img 
                    src="<?php echo esc_url($featured_trainer->photo_url); ?>" 
                    alt="<?php echo esc_attr($featured_trainer->display_name); ?>"
                    class="ptp-ty-upsell-photo"
                    onerror="this.src='https://via.placeholder.com/60?text=PTP'"
                >
                <div>
                    <div class="ptp-ty-upsell-tag">⚡ Camp Prep Session</div>
                    <h3 class="ptp-ty-upsell-title">1-on-1 Private Training</h3>
                    <div class="ptp-ty-upsell-subtitle">
                        with <?php echo esc_html($featured_trainer->display_name); ?>
                        <?php if ($featured_trainer->credentials): ?>
                         • <?php echo esc_html($featured_trainer->credentials); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="ptp-ty-upsell-body">
                <div class="ptp-ty-upsell-pitch">
                    <strong>Kids who add 1-on-1 training improve 3x faster.</strong><br>
                    Get <?php echo esc_html($camper_display); ?> extra coaching before camp starts.
                </div>
                
                <div class="ptp-ty-upsell-pricing">
                    <div>
                        <span class="ptp-ty-price-old">$<?php echo intval($training_regular); ?></span>
                        <span class="ptp-ty-price-new">$<?php echo intval($training_sale); ?></span>
                    </div>
                    <span class="ptp-ty-savings">SAVE $<?php echo intval($training_savings); ?></span>
                </div>
                
                <button 
                    type="button" 
                    class="ptp-ty-upsell-btn"
                    onclick="ptpAddTraining()"
                    id="training-btn"
                >
                    Add for $<?php echo intval($training_sale); ?> — One Click ⚡
                </button>
                <p class="ptp-ty-upsell-note">Uses your saved payment • No checkout required</p>
            </div>
        </div>
        <?php elseif ($training_added): ?>
        <div class="ptp-ty-success" id="training-success">
            <div class="ptp-ty-success-icon">✓</div>
            <div class="ptp-ty-success-title">Training Session Added!</div>
            <div class="ptp-ty-success-text">
                <?php echo esc_html($featured_trainer->display_name ?? 'Your trainer'); ?> will reach out within 24 hours.
            </div>
        </div>
        <?php elseif ($has_training && $booking): ?>
        <div class="ptp-ty-success" id="training-success">
            <div class="ptp-ty-success-icon">✓</div>
            <div class="ptp-ty-success-title">Training Booked with <?php echo esc_html($booking->trainer_name); ?>!</div>
            <div class="ptp-ty-success-text">
                Your trainer will reach out within 24 hours to confirm details.
                <?php if ($booking->session_date && !preg_match('/^0000/', $booking->session_date) && strtotime($booking->session_date) > 0 && intval(date('Y', strtotime($booking->session_date))) >= 2020): ?>
                    First session: <?php echo esc_html(date('l, M j', strtotime($booking->session_date))); ?>
                    <?php if ($booking->start_time): ?> at <?php echo esc_html(date('g:i A', strtotime($booking->start_time))); ?><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ============================================ -->
        <!-- UPSELL 2: ANOTHER CAMP (SECONDARY) -->
        <!-- ============================================ -->
        <?php if (!$camp_added && !empty($upcoming_camps)): ?>
        <div class="ptp-ty-upsell secondary" id="camp-upsell">
            <div class="ptp-ty-upsell-badge blue"><?php echo intval($second_camp_count); ?> families booked multiple weeks</div>
            
            <div class="ptp-ty-upsell-header">
                <div class="ptp-ty-upsell-icon">⚽</div>
                <div>
                    <div class="ptp-ty-upsell-tag blue">🎯 <?php echo intval($camp_discount_percent); ?>% Multi-Camp Discount</div>
                    <h3 class="ptp-ty-upsell-title">Add Another Week</h3>
                    <div class="ptp-ty-upsell-subtitle">
                        Same camper or sibling • Discount auto-applied
                    </div>
                </div>
            </div>
            
            <div class="ptp-ty-upsell-body">
                <div class="ptp-ty-upsell-pitch">
                    <strong class="blue">More weeks = More improvement.</strong><br>
                    Lock in another session before spots fill up.
                </div>
                
                <!-- Camp Selection -->
                <div class="ptp-ty-camp-options" id="camp-options">
                    <?php foreach ($upcoming_camps as $index => $camp): 
                        $original_price = floatval($camp->price_cents) / 100;
                        $discounted_price = round($original_price * (1 - $camp_discount_percent / 100), 2);
                        $spots = $camp->spots_remaining ?: 999;
                    ?>
                    <div 
                        class="ptp-ty-camp-option <?php echo $index === 0 ? 'selected' : ''; ?>"
                        data-camp-id="<?php echo intval($camp->id); ?>"
                        data-stripe-product="<?php echo esc_attr($camp->stripe_product_id); ?>"
                        data-stripe-price="<?php echo esc_attr($camp->stripe_price_id); ?>"
                        data-camp-name="<?php echo esc_attr($camp->camp_name); ?>"
                        data-price="<?php echo esc_attr($discounted_price); ?>"
                        data-original="<?php echo esc_attr($original_price); ?>"
                        onclick="ptpSelectCamp(this)"
                    >
                        <div class="ptp-ty-camp-option-header">
                            <div class="ptp-ty-camp-option-name"><?php echo esc_html($camp->camp_name); ?></div>
                            <div class="ptp-ty-camp-option-price">
                                <div class="old">$<?php echo number_format($original_price, 0); ?></div>
                                <div class="new">$<?php echo number_format($discounted_price, 0); ?></div>
                            </div>
                        </div>
                        <div class="ptp-ty-camp-option-meta">
                            <span>📅 <?php echo esc_html($camp->camp_dates); ?></span>
                            <?php if ($spots <= 10): ?>
                            <span style="color:#EF4444;">🔥 <?php echo intval($spots); ?> spots left</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <button 
                    type="button" 
                    class="ptp-ty-upsell-btn blue"
                    onclick="ptpAddCamp()"
                    id="camp-btn"
                >
                    Add Camp — One Click ⚡
                </button>
                <p class="ptp-ty-upsell-note">Same camper info • <?php echo intval($camp_discount_percent); ?>% discount auto-applied</p>
            </div>
        </div>
        <?php elseif ($camp_added): ?>
        <div class="ptp-ty-success" id="camp-success">
            <div class="ptp-ty-success-icon">✓</div>
            <div class="ptp-ty-success-title">Camp Added!</div>
            <div class="ptp-ty-success-text">
                Check your email for the updated confirmation.
            </div>
        </div>
        <?php endif; ?>
        
        <!-- WHAT'S NEXT -->
        <div class="ptp-ty-card">
            <div class="ptp-ty-card-header dark">
                <span class="ptp-ty-card-label">What's Next</span>
            </div>
            <div class="ptp-ty-steps">
                <div class="ptp-ty-step">
                    <div class="ptp-ty-step-num">1</div>
                    <div>
                        <div class="ptp-ty-step-title">Check Your Email</div>
                        <div class="ptp-ty-step-desc">Confirmation with all details sent to your inbox</div>
                    </div>
                </div>
                <div class="ptp-ty-step">
                    <div class="ptp-ty-step-num">2</div>
                    <div>
                        <div class="ptp-ty-step-title">Camp Packet (1 Week Before)</div>
                        <div class="ptp-ty-step-desc">Schedule, what to bring, coach assignments</div>
                    </div>
                </div>
                <div class="ptp-ty-step">
                    <div class="ptp-ty-step-num">3</div>
                    <div>
                        <div class="ptp-ty-step-title">Day 1 Check-In</div>
                        <div class="ptp-ty-step-desc">Arrive 15 min early • Water, cleats, shin guards</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- REFERRAL -->
        <div class="ptp-ty-card">
            <div class="ptp-ty-referral">
                <div class="ptp-ty-referral-title">Give $15, Earn Rewards</div>
                <p class="ptp-ty-referral-desc">Friends get $15 off. You earn $25 credit per referral — plus free training at 2 referrals!</p>
                <div class="ptp-ty-referral-code" onclick="ptpCopyCode(this)" title="Tap to copy">
                    <?php echo esc_html($referral_code); ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2"/>
                        <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                    </svg>
                </div>
            </div>
        </div>
        
        <!-- ACTION BUTTONS -->
        <div class="ptp-ty-btns">
            <a href="<?php echo home_url('/my-training/'); ?>" class="ptp-ty-btn ptp-ty-btn-gold">
                My Dashboard
            </a>
            <a href="<?php echo home_url('/ptp-find-a-camp/'); ?>" class="ptp-ty-btn ptp-ty-btn-outline">
                Browse All Camps
            </a>
        </div>
        
        <!-- FOOTER -->
        <div class="ptp-ty-footer">
            Questions? Text or call <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', function_exists('ptp_email_brand') ? ptp_email_brand('support_phone') : '')); ?>"><?php echo esc_html(function_exists('ptp_email_brand') ? ptp_email_brand('support_phone') : ''); ?></a>
            <div class="ptp-ty-footer-brand"><?php echo esc_html(function_exists('ptp_email_brand') ? ptp_email_brand('tagline') : 'Players Teaching Players'); ?></div>
        </div>
        
    </div>
</div>

<script>
(function() {
    // Config
    var config = {
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        orderId: <?php echo intval($order_id); ?>,
        trainerId: <?php echo intval($featured_trainer->id ?? 0); ?>,
        trainingPrice: <?php echo intval($training_sale); ?>,
        trainerName: '<?php echo esc_js($featured_trainer->display_name ?? ''); ?>',
        nonce: '<?php echo wp_create_nonce('ptp_thankyou_upsell'); ?>',
        camperFirst: '<?php echo esc_js($camper_first); ?>',
        camperName: '<?php echo esc_js($camper_name); ?>',
    };
    
    // Selected camp
    var selectedCamp = null;
    var campOptions = document.querySelectorAll('.ptp-ty-camp-option');
    if (campOptions.length > 0) {
        selectedCamp = {
            id: campOptions[0].dataset.campId,
            stripeProduct: campOptions[0].dataset.stripeProduct,
            stripePrice: campOptions[0].dataset.stripePrice,
            name: campOptions[0].dataset.campName,
            price: campOptions[0].dataset.price,
            original: campOptions[0].dataset.original
        };
    }
    
    // Select camp
    window.ptpSelectCamp = function(el) {
        document.querySelectorAll('.ptp-ty-camp-option').forEach(function(opt) {
            opt.classList.remove('selected');
        });
        el.classList.add('selected');
        selectedCamp = {
            id: el.dataset.campId,
            stripeProduct: el.dataset.stripeProduct,
            stripePrice: el.dataset.stripePrice,
            name: el.dataset.campName,
            price: el.dataset.price,
            original: el.dataset.original
        };
    };
    
    // Copy referral code
    window.ptpCopyCode = function(el) {
        var code = el.textContent.trim();
        navigator.clipboard.writeText(code).then(function() {
            var svg = el.querySelector('svg');
            var original = el.innerHTML;
            el.innerHTML = '✓ COPIED!';
            el.style.borderColor = '#FCB900';
            setTimeout(function() {
                el.innerHTML = original;
                el.style.borderColor = '#444';
            }, 2000);
        });
    };
    
    // Add Training Session
    window.ptpAddTraining = function() {
        var btn = document.getElementById('training-btn');
        var card = document.getElementById('training-upsell');
        
        btn.disabled = true;
        btn.innerHTML = '<span class="ptp-ty-spinner"></span>Processing...';
        
        var formData = new FormData();
        formData.append('action', 'ptp_thankyou_add_training');
        formData.append('order_id', config.orderId);
        formData.append('trainer_id', config.trainerId);
        formData.append('amount', config.trainingPrice);
        formData.append('camper_name', config.camperName);
        formData.append('nonce', config.nonce);
        
        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                card.outerHTML = 
                    '<div class="ptp-ty-success">' +
                        '<div class="ptp-ty-success-icon">✓</div>' +
                        '<div class="ptp-ty-success-title">Training Session Added!</div>' +
                        '<div class="ptp-ty-success-text">' + config.trainerName + ' will reach out within 24 hours.</div>' +
                    '</div>';
            } else {
                btn.disabled = false;
                btn.innerHTML = 'Add for $' + config.trainingPrice + ' — One Click ⚡';
                alert(data.data || 'Error adding session. Please try again.');
            }
        })
        .catch(function(err) {
            console.error(err);
            btn.disabled = false;
            btn.innerHTML = 'Add for $' + config.trainingPrice + ' — One Click ⚡';
            alert('Connection error. Please try again.');
        });
    };
    
    // Add Another Camp
    window.ptpAddCamp = function() {
        if (!selectedCamp) {
            alert('Please select a camp');
            return;
        }
        
        var btn = document.getElementById('camp-btn');
        var card = document.getElementById('camp-upsell');
        
        btn.disabled = true;
        btn.innerHTML = '<span class="ptp-ty-spinner"></span>Processing...';
        
        var formData = new FormData();
        formData.append('action', 'ptp_thankyou_add_camp');
        formData.append('order_id', config.orderId);
        formData.append('camp_id', selectedCamp.id);
        formData.append('stripe_product_id', selectedCamp.stripeProduct);
        formData.append('stripe_price_id', selectedCamp.stripePrice);
        formData.append('camp_name', selectedCamp.name);
        formData.append('amount', selectedCamp.price);
        formData.append('original_amount', selectedCamp.original);
        formData.append('camper_name', config.camperName);
        formData.append('nonce', config.nonce);
        
        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                card.outerHTML = 
                    '<div class="ptp-ty-success">' +
                        '<div class="ptp-ty-success-icon">✓</div>' +
                        '<div class="ptp-ty-success-title">Camp Added!</div>' +
                        '<div class="ptp-ty-success-text">Check your email for the updated confirmation.</div>' +
                    '</div>';
            } else {
                btn.disabled = false;
                btn.innerHTML = 'Add Camp — One Click ⚡';
                alert(data.data || 'Error adding camp. Please try again.');
            }
        })
        .catch(function(err) {
            console.error(err);
            btn.disabled = false;
            btn.innerHTML = 'Add Camp — One Click ⚡';
            alert('Connection error. Please try again.');
        });
    };
})();
</script>

<?php get_footer(); ?>
