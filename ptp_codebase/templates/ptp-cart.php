<?php
/**
 * PTP Cart + Checkout v176 — Unified Single-Page Experience
 *
 * Everything happens on /ptp-cart/:
 *   • Review cart items (camps + training)
 *   • Apply coupons / referrals
 *   • Fill player & parent info
 *   • Pay with Stripe (card + Apple Pay / Google Pay)
 *
 * No redirect to /ptp-checkout/ needed.
 *
 * @version 176.0.0
 */
defined('ABSPATH') || exit;

global $wpdb;

/* ——— images ——— */
$default_camp_image    = '';
$default_training_image = '';
$logo                  = get_option('ptp_logo_url', '');
if (empty($logo)) $logo = function_exists('ptp_email_brand') ? ptp_email_brand('logo_url') : '';

/* ——— stripe keys ——— */
$stripe_test_mode = get_option('ptp_stripe_test_mode', false);
$stripe_mode = $stripe_test_mode ? 'test' : 'live';

// Primary: individual options (matches checkout page + admin settings)
$stripe_pk = get_option('ptp_stripe_' . $stripe_mode . '_publishable', '');
$stripe_sk = get_option('ptp_stripe_' . $stripe_mode . '_secret', '');

// Fallback 1: ptp_settings array (legacy)
if (!$stripe_pk || !$stripe_sk) {
    $settings = get_option('ptp_settings', array());
    $legacy_mode = ($settings['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test';
    if (!$stripe_pk) $stripe_pk = $settings['stripe_' . $legacy_mode . '_publishable_key'] ?? '';
    if (!$stripe_sk) $stripe_sk = $settings['stripe_' . $legacy_mode . '_secret_key'] ?? '';
}

// Fallback 2: generic key options
if (!$stripe_pk) $stripe_pk = get_option('ptp_stripe_publishable_key', '');
if (!$stripe_sk) $stripe_sk = get_option('ptp_stripe_secret_key', '');

/* ——— current user ——— */
$user      = wp_get_current_user();
$logged_in = is_user_logged_in();
$parent    = null;
$players   = array();

if ($logged_in && $wpdb) {
    $pt = $wpdb->prefix . 'ptp_parents';
    $pl = $wpdb->prefix . 'ptp_players';
    // v136: Cache table existence for 1 hour instead of SHOW TABLES every load
    $tables_ok = get_transient('ptp_cart_tables_ok');
    if ($tables_ok === false) {
        $tables_ok = ($wpdb->get_var("SHOW TABLES LIKE '{$pt}'") === $pt) ? 'yes' : 'no';
        set_transient('ptp_cart_tables_ok', $tables_ok, HOUR_IN_SECONDS);
    }
    if ($tables_ok === 'yes') {
        $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE user_id = %d", $user->ID));
        if ($parent) {
            $players = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$pl} WHERE parent_id = %d ORDER BY first_name", $parent->id));
        }
    }
}

/* ═══════════════════════════════════════════
   BUILD CART DATA
   ═══════════════════════════════════════════ */
$cart_data = array(
    'camp_items'          => array(),
    'training_items'      => array(),
    'subtotal'            => 0,
    'early_bird_discount' => 0,
    'multi_week_discount' => 0,
    'bundle_discount'     => 0,
    'processing_fee'      => 0,
    'coupon_discount'     => 0,
    'coupon_code'         => '',
    'before_care'         => 0,
    'after_care'          => 0,
    'extra_care_total'    => 0,
    'total'               => 0,
    'item_count'          => 0,
    'has_camps'           => false,
    'has_training'        => false,
);

$items_flat = array();
$native_training_data = null;

/* —— 1. Load from native cart —— */
$from_native_cart = false;
if (function_exists('ptp_cart')) { ptp_cart()->load_cart(); }

if (function_exists('ptp_cart') && ptp_cart()->get_cart_contents_count() > 0) {
    $native_items     = ptp_cart()->get_cart();
    $from_native_cart = true;

    foreach ($native_items as $cart_key => $cart_item) {
        $item_type = $cart_item['item_type'] ?? 'product';
        $metadata  = $cart_item['metadata']  ?? array();
        $line_total = floatval($cart_item['line_total'] ?? ($cart_item['price'] ?? 0));

        if ($item_type === 'camp') {
            $cart_data['has_camps'] = true;
            $camp_id   = $cart_item['item_id'] ?? 0;
            $camp_name = $metadata['name'] ?? $metadata['camp_name'] ?? 'Camp Registration';
            $camp_date = $metadata['date'] ?? $metadata['camp_date'] ?? $metadata['camp_dates'] ?? '';
            $camp_loc  = $metadata['location'] ?? $metadata['camp_location'] ?? '';
            $camp_time = $metadata['time'] ?? $metadata['camp_time'] ?? '9AM - 3PM';
            $camp_image = $default_camp_image;
            if ($camp_id && has_post_thumbnail($camp_id)) {
                $thumb = get_the_post_thumbnail_url($camp_id, 'medium');
                if ($thumb) $camp_image = $thumb;
            }
            $product_url = $camp_id ? get_permalink($camp_id) : '';

            $cart_data['camp_items'][$cart_key] = array(
                'key'      => $cart_key, 'id' => $camp_id, 'name' => $camp_name,
                'price'    => $line_total, 'dates' => $camp_date, 'location' => $camp_loc,
                'time'     => $camp_time, 'image' => $camp_image, 'url' => $product_url,
                'type'     => 'camp',
                'stripe_product' => $metadata['stripe_product'] ?? $metadata['stripe_product_id'] ?? '',
                'stripe_price'   => $metadata['stripe_price']   ?? $metadata['stripe_price_id']   ?? '',
                'early_bird_applied' => !empty($metadata['early_bird']) || !empty($metadata['early_bird_applied']),
            );
            $cart_data['subtotal'] += $line_total;
            $items_flat[] = array('name'=>$camp_name,'type'=>'camp','price'=>$line_total,'id'=>$camp_id,'cart_key'=>$cart_key,'image'=>$camp_image,'date'=>$camp_date,'location'=>$camp_loc,'time'=>$camp_time,'stripe_product'=>$metadata['stripe_product']??'','stripe_price'=>$metadata['stripe_price']??'');

        } elseif ($item_type === 'training') {
            $cart_data['has_training'] = true;
            $t_id = $metadata['trainer_id'] ?? $cart_item['item_id'] ?? 0;
            // v136: Use metadata first, only query DB if metadata missing (avoids N+1 queries)
            $t_name = $metadata['trainer_name'] ?? '';
            $t_photo = $metadata['trainer_photo'] ?? $metadata['trainer_image'] ?? '';
            if ($t_id && (!$t_name || !$t_photo)) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT display_name, photo_url FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $t_id));
                if ($row) { if (!$t_name) $t_name = $row->display_name; if (!$t_photo) $t_photo = $row->photo_url; }
            }
            if (!$t_name) $t_name = 'Trainer';

            $native_training_data = array(
                'trainer_id'=>$t_id,'trainer_name'=>$t_name,'trainer_photo'=>$t_photo,
                'package'=>$metadata['package']??'single','package_name'=>$metadata['package_name']??$metadata['name']??'Training Session',
                'sessions'=>$metadata['sessions']??1,'date'=>$metadata['session_date']??$metadata['date']??'',
                'time'=>$metadata['session_time']??$metadata['time']??'','location'=>$metadata['location']??$metadata['session_location']??'',
                'location_address'=>$metadata['location_address']??$metadata['session_location_address']??'','location_lat'=>$metadata['location_lat']??'','location_lng'=>$metadata['location_lng']??'',
                'group_size'=>$metadata['group_size']??1,'price'=>$line_total,
            );
            // v222: Fallback to trainer's location if session location is empty
            if (empty($native_training_data['location']) && $t_id && $wpdb) {
                $tr_loc = $wpdb->get_row($wpdb->prepare("SELECT location, city, state FROM {$wpdb->prefix}ptp_trainers WHERE id = %d", $t_id));
                if ($tr_loc) {
                    $native_training_data['location'] = !empty($tr_loc->location) ? $tr_loc->location : trim(($tr_loc->city ?? '') . ', ' . ($tr_loc->state ?? ''), ', ');
                }
            }
            $trainer_url = $t_id ? home_url('/trainer/' . $t_id . '/') : '';
            $cart_data['training_items'][$cart_key] = array(
                'key'=>$cart_key,'trainer_id'=>$t_id,'trainer_name'=>$t_name,'trainer_photo'=>$t_photo,
                'package'=>$metadata['package']??'single','package_name'=>$metadata['package_name']??$metadata['name']??'Training Session',
                'sessions'=>$metadata['sessions']??1,'date'=>$metadata['session_date']??$metadata['date']??'',
                'time'=>$metadata['session_time']??$metadata['time']??'','location'=>$native_training_data['location'],
                'price'=>$line_total,'url'=>$trainer_url,'type'=>'training',
            );
            $cart_data['subtotal'] += $line_total;
            $items_flat[] = array('name'=>$t_name.' - '.($metadata['package_name']??'Training'),'type'=>'training','price'=>$line_total,'id'=>$t_id,'cart_key'=>$cart_key,'image'=>$t_photo,'date'=>$metadata['session_date']??'','time'=>$metadata['session_time']??'','location'=>$native_training_data['location'],'trainer_id'=>$t_id,'trainer_name'=>$t_name);
        }
    }
}

/* —— 2. URL param trainer (from profile "Book Now") —— */
$url_trainer_id = intval($_GET['trainer_id'] ?? 0);
if ($url_trainer_id && $wpdb) {
    $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'", $url_trainer_id));
    if ($trainer) {
        $pkg_key    = sanitize_text_field($_GET['package'] ?? 'single');
        $group_size = max(1, intval($_GET['group_size'] ?? 1));
        $rate       = intval($trainer->hourly_rate ?: 60);
        $g_mult     = array(1=>1,2=>1.6,3=>2,4=>2.4,5=>2.8,6=>3.2,7=>3.5,8=>3.8,9=>4.0,10=>4.2);
        $mult       = $g_mult[$group_size] ?? (1 + ($group_size - 1) * 0.4);
        $pkgs       = array('single'=>array('n'=>'Single Session','s'=>1,'d'=>0),'pack3'=>array('n'=>'3-Pack','s'=>3,'d'=>10),'pack5'=>array('n'=>'5-Pack','s'=>5,'d'=>15),'5pack'=>array('n'=>'5-Pack','s'=>5,'d'=>15),'pack10'=>array('n'=>'10-Pack','s'=>10,'d'=>20),'10pack'=>array('n'=>'10-Pack','s'=>10,'d'=>20));
        $sel        = $pkgs[$pkg_key] ?? $pkgs['single'];
        $base       = $rate * $mult * $sel['s'];
        $price      = $base - round($base * $sel['d'] / 100);
        $tkey       = 'training_' . $url_trainer_id;

        // v211: Read ALL structured location fields from trainer profile
        $url_location         = sanitize_text_field($_GET['location'] ?? '');
        $url_location_address = sanitize_text_field($_GET['location_address'] ?? '');
        $url_location_lat     = sanitize_text_field($_GET['location_lat'] ?? '');
        $url_location_lng     = sanitize_text_field($_GET['location_lng'] ?? '');

        if (!isset($cart_data['training_items'][$tkey])) {
            $cart_data['training_items'][$tkey] = array(
                'key'=>$tkey,'trainer_id'=>$url_trainer_id,'trainer_name'=>$trainer->display_name,
                'trainer_photo'=>$trainer->photo_url??'','package'=>$pkg_key,'package_name'=>$sel['n'],
                'sessions'=>$sel['s'],'date'=>sanitize_text_field($_GET['date']??''),'time'=>sanitize_text_field($_GET['time']??''),
                'location'=>$url_location,'location_address'=>$url_location_address,
                'location_lat'=>$url_location_lat,'location_lng'=>$url_location_lng,
                'price'=>$price,
                'url'=>home_url('/trainer/'.$url_trainer_id.'/'),'type'=>'training','from_url'=>true,
            );
            $cart_data['has_training'] = true;
            $cart_data['subtotal'] += $price;
            if (!$native_training_data) {
                $native_training_data = array('trainer_id'=>$url_trainer_id,'trainer_name'=>$trainer->display_name,'trainer_photo'=>$trainer->photo_url??'','package'=>$pkg_key,'package_name'=>$sel['n'],'sessions'=>$sel['s'],'date'=>sanitize_text_field($_GET['date']??''),'time'=>sanitize_text_field($_GET['time']??''),'location'=>$url_location,'location_address'=>$url_location_address,'location_lat'=>$url_location_lat,'location_lng'=>$url_location_lng,'group_size'=>$group_size,'price'=>$price);
            }
            $items_flat[] = array('name'=>$trainer->display_name.' - '.$sel['n'],'type'=>'training','price'=>$price,'id'=>$url_trainer_id,'cart_key'=>$tkey,'image'=>$trainer->photo_url??'','date'=>sanitize_text_field($_GET['date']??''),'time'=>sanitize_text_field($_GET['time']??''),'location'=>$url_location,'location_address'=>$url_location_address,'trainer_id'=>$url_trainer_id,'trainer_name'=>$trainer->display_name);

            // v213: Persist training to native cart so /ptp-checkout/ fallback can find it
            if (function_exists('ptp_cart')) {
                ptp_cart()->add_to_cart('training', $url_trainer_id, 1, $price, array(
                    'trainer_id'        => $url_trainer_id,
                    'trainer_name'      => $trainer->display_name,
                    'trainer_photo'     => $trainer->photo_url ?? '',
                    'package'           => $pkg_key,
                    'package_name'      => $sel['n'],
                    'sessions'          => $sel['s'],
                    'session_date'      => sanitize_text_field($_GET['date'] ?? ''),
                    'date'              => sanitize_text_field($_GET['date'] ?? ''),
                    'session_time'      => sanitize_text_field($_GET['time'] ?? ''),
                    'time'              => sanitize_text_field($_GET['time'] ?? ''),
                    'location'          => $url_location,
                    'location_address'  => $url_location_address,
                    'location_lat'      => $url_location_lat,
                    'location_lng'      => $url_location_lng,
                    'group_size'        => $group_size,
                ));
                ptp_cart()->save_cart(true);
            }
        }
    }
}

/* —— 3. URL param camp (from camp listing "Register" links) —— */
$cart_group_size = intval($native_training_data['group_size'] ?? 1);
$url_camp_param = isset($_GET['camp']) ? sanitize_text_field($_GET['camp']) : '';
if (!empty($url_camp_param) && $wpdb) {
    // Look up camp by stripe_product_id
    $camp_product = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE stripe_product_id = %s AND active = 1",
        $url_camp_param
    ));
    if ($camp_product) {
        // Check if already in cart
        $already_in = false;
        foreach ($cart_data['camp_items'] as $ci) {
            if (($ci['stripe_product'] ?? '') === $url_camp_param || ($ci['stripe_price'] ?? '') === $url_camp_param) {
                $already_in = true;
                break;
            }
        }
        if (!$already_in) {
            $price = floatval($camp_product->price ?? $camp_product->unit_amount ?? 0);
            if ($price > 100) $price = $price / 100; // cents to dollars
            $camp_name = $camp_product->name ?? 'Summer Camp';
            $camp_dates = $camp_product->metadata ?? '';
            if (is_string($camp_dates)) {
                $meta_decoded = json_decode($camp_dates, true);
                $camp_dates = $meta_decoded['dates'] ?? $meta_decoded['camp_dates'] ?? '';
            }
            $camp_loc = '';
            if (!empty($meta_decoded['location'])) $camp_loc = $meta_decoded['location'];
            $camp_time = !empty($meta_decoded['time']) ? $meta_decoded['time'] : '9AM - 3PM';
            $ckey = 'camp_url_' . sanitize_title($url_camp_param);
            $camp_image = $default_camp_image;

            // Also try to add to native cart for persistence
            if (function_exists('ptp_cart')) {
                ptp_cart()->add_to_cart('camp', 0, 1, $price, array(
                    'name' => $camp_name,
                    'camp_name' => $camp_name,
                    'date' => $camp_dates,
                    'location' => $camp_loc,
                    'time' => $camp_time,
                    'stripe_product' => $camp_product->stripe_product_id,
                    'stripe_product_id' => $camp_product->stripe_product_id,
                    'stripe_price' => $camp_product->stripe_price_id ?? '',
                ));
                // v177: CRITICAL - must save immediately so /ptp-checkout can find it
                ptp_cart()->save_cart(true);
            }

            $cart_data['camp_items'][$ckey] = array(
                'key' => $ckey, 'id' => 0, 'name' => $camp_name,
                'price' => $price, 'dates' => $camp_dates, 'location' => $camp_loc,
                'time' => $camp_time, 'image' => $camp_image, 'url' => '',
                'type' => 'camp',
                'stripe_product' => $camp_product->stripe_product_id,
                'stripe_price' => $camp_product->stripe_price_id ?? '',
                'early_bird_applied' => false,
            );
            $cart_data['has_camps'] = true;
            $cart_data['subtotal'] += $price;
            $items_flat[] = array('name'=>$camp_name,'type'=>'camp','price'=>$price,'id'=>0,'cart_key'=>$ckey,'image'=>$camp_image,'date'=>$camp_dates,'location'=>$camp_loc,'time'=>$camp_time,'stripe_product'=>$camp_product->stripe_product_id,'stripe_price'=>$camp_product->stripe_price_id??'');
        }
    }
}

/* ═══════════════════════════════════════════
   CALCULATE TOTALS
   ═══════════════════════════════════════════ */
$camp_count     = count($cart_data['camp_items']);
$training_count = count($cart_data['training_items']);
$cart_data['item_count'] = $camp_count + $training_count;
$is_empty   = $cart_data['item_count'] === 0;
$has_bundle = $cart_data['has_camps'] && $cart_data['has_training'];

/* early bird */
$eb_enabled = get_option('ptp_early_bird_enabled', true);
$eb_end     = get_option('ptp_early_bird_end_date', '2026-02-16');
$is_eb      = $eb_enabled && (current_time('Y-m-d') <= $eb_end);

if ($is_eb && $camp_count > 0) {
    $eb_camps = 0;
    foreach ($cart_data['camp_items'] as $ci) { if (empty($ci['early_bird_applied'])) $eb_camps++; }
    $cart_data['early_bird_discount'] = $eb_camps * 50;
}

/* multi-week */
if ($camp_count >= 2) {
    $camp_sub = array_sum(array_column($cart_data['camp_items'], 'price'));
    $mw_pct   = $camp_count >= 3 ? 20 : 10;
    $cart_data['multi_week_discount'] = round($camp_sub * $mw_pct / 100, 2);
}

/* bundle */
if ($has_bundle) { $cart_data['bundle_discount'] = round($cart_data['subtotal'] * 0.05, 2); }

/* v200.2: Before/After care add-on — $50 for one option, $100 for both, per camp week */
$care_price_one  = 50;
$care_price_both = 100;
if ($camp_count > 0) {
    $cart_data['before_care'] = !empty($_GET['before_care']) ? 1 : 0;
    $cart_data['after_care']  = !empty($_GET['after_care']) ? 1 : 0;
    $care_options = $cart_data['before_care'] + $cart_data['after_care'];
    $care_per_week = $care_options === 2 ? $care_price_both : ($care_options === 1 ? $care_price_one : 0);
    $cart_data['extra_care_total'] = $care_per_week * $camp_count;
}

/* processing fee */
$net = max(0, $cart_data['subtotal'] + $cart_data['extra_care_total'] - $cart_data['early_bird_discount'] - $cart_data['multi_week_discount'] - $cart_data['bundle_discount'] - $cart_data['coupon_discount']);
$cart_data['processing_fee'] = ($net > 0) ? round(($net * 0.03) + 0.30, 2) : 0;
$cart_data['total'] = max(0, $net + $cart_data['processing_fee']);

/* ═══════════════════════════════════════════
   v136: PaymentIntent is now created via AJAX when user clicks "Proceed to Checkout"
   This removes 500-1500ms of blocking Stripe API call on every page load
   ═══════════════════════════════════════════ */
$client_secret      = ''; // Will be set via AJAX
$checkout_session_id = wp_generate_uuid4();
$pi_error           = '';
$cents              = intval(round($cart_data['total'] * 100));
$has_stripe         = !$is_empty && $cents >= 50 && !empty($stripe_pk);

$cart_nonce  = wp_create_nonce('ptp_cart_action');
$camps_nonce = wp_create_nonce('ptp_camps_nonce');
$coupon_nonce = wp_create_nonce('ptp_nonce');
$checkout_nonce_val = wp_create_nonce('ptp_checkout');
$ajax_url = admin_url('admin-ajax.php');
$thank_you_url = home_url('/thank-you/?session=' . $checkout_session_id);

get_header();
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet"></noscript>
<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-masterclass.css">

<style>
/* ═══════════════════════════════════════════
   PTP CART + CHECKOUT v177 - MASTERCLASS EDITION
   ═══════════════════════════════════════════ */
:root{--gold:#FCB900;--gold-dark:#E5A800;--black:#0A0A0A;--white:#FFF;--g50:#FAFAFA;--g100:#F5F5F5;--g200:#E5E5E5;--g300:#D4D4D4;--g400:#A3A3A3;--g500:#737373;--g600:#525252;--g700:#374151;--g800:#262626;--green:#22C55E;--red:#EF4444;--font-d:var(--mc-font-display,'Oswald',sans-serif);--font-b:var(--mc-font-sans,'Inter',-apple-system,sans-serif);--font-s:var(--font-b);--r:14px;--safe-b:env(safe-area-inset-bottom,0px)}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:0.01ms!important;animation-iteration-count:1!important;transition-duration:0.01ms!important;scroll-behavior:auto!important}}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g50)!important}
.cc-page{min-height:100vh;font-family:var(--font-b);-webkit-font-smoothing:antialiased;width:100%;max-width:100%;overflow-x:hidden}
h1,h2,h3{font-family:var(--font-d);font-weight:700;text-transform:uppercase;letter-spacing:-.01em}

/* full-bleed: bust out of ALL theme containers (content only — NOT header/footer) */
body.ptp-cart-active .site-content,body.ptp-cart-active .entry-content,body.ptp-cart-active .post-content,body.ptp-cart-active .page-content,body.ptp-cart-active main,body.ptp-cart-active main.site-main,body.ptp-cart-active article,body.ptp-cart-active #content,body.ptp-cart-active #main,body.ptp-cart-active #primary,body.ptp-cart-active .content-area,body.ptp-cart-active .site-main,body.ptp-cart-active .ast-container,body.ptp-cart-active .elementor-section-wrap,body.ptp-cart-active .elementor-section,body.ptp-cart-active .elementor-container,body.ptp-cart-active .elementor-column-wrap,body.ptp-cart-active .elementor-widget-wrap,body.ptp-cart-active .elementor-widget-theme-post-content,body.ptp-cart-active .elementor-element,body.ptp-cart-active .e-con,body.ptp-cart-active .e-con-inner,body.ptp-cart-active .elementor-location-single,body.ptp-cart-active .elementor-location-footer,body.ptp-cart-active .ast-single-post,body.ptp-cart-active .ast-article-single,body.ptp-cart-active .ast-separate-container,body.ptp-cart-active .ast-page-builder-template,body.ptp-cart-active .wp-block-post-content,body.ptp-cart-active .has-global-padding,body.ptp-cart-active .is-layout-constrained,body.ptp-cart-active .wp-block-group,body.ptp-cart-active [class*="wp-container"],body.ptp-cart-active .container,body.ptp-cart-active .wrapper,body.ptp-cart-active .inner-container,body.ptp-cart-active .content-container{width:100%!important;max-width:100%!important;padding-left:0!important;padding-right:0!important;margin-left:0!important;margin-right:0!important;box-sizing:border-box!important}
/* Astra sidebar layouts — force full width single column */
body.ptp-cart-active #secondary,body.ptp-cart-active .widget-area,body.ptp-cart-active aside.sidebar{display:none!important}
body.ptp-cart-active #primary{width:100%!important;float:none!important}
/* protect header.ph mobile nav */
body.ptp-cart-active header.ph{overflow:visible!important;z-index:200!important}
body.ptp-cart-active nav.ph-m{z-index:190!important}

/* ——— banner ——— */
.cc-banner{background:linear-gradient(135deg,var(--black),#1a1a1a);padding:48px 24px;text-align:center;width:100%;max-width:100%}
.cc-banner img{display:inline-block!important;height:32px!important;width:auto!important;max-width:180px!important;opacity:1!important;visibility:visible!important}
.cc-banner h1{font-size:clamp(28px,5vw,48px);color:var(--white);margin:0 0 8px}
.cc-banner p{color:var(--g400);font-size:15px;font-family:var(--font-s)}

/* ——— layout — FULL WIDTH ——— */
.cc-grid{width:100%;max-width:100%;margin:0 auto;padding:24px 16px 200px}
@media(min-width:480px){.cc-grid{padding:28px 20px 200px}}
@media(min-width:768px){.cc-grid{padding:32px 24px 200px}}
@media(min-width:1024px){.cc-grid{display:grid;grid-template-columns:1fr 420px;gap:48px;padding:40px 4vw 80px}}
@media(min-width:1400px){.cc-grid{grid-template-columns:1fr 480px;gap:56px;padding:48px 6vw 80px}}
@media(min-width:1800px){.cc-grid{grid-template-columns:1fr 520px;gap:64px;padding:48px 8vw 80px}}

/* ——— empty ——— */
.cc-empty{text-align:center;padding:64px 24px;background:var(--white);border-radius:20px;box-shadow:0 4px 24px rgba(0,0,0,.06);max-width:560px;margin:0 auto}
.cc-empty-icon{width:88px;height:88px;background:linear-gradient(135deg,var(--g100),var(--g200));border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 28px}
.cc-empty-icon svg{width:44px;height:44px;color:var(--g400)}
.cc-empty h2{font-size:26px;margin:0 0 10px}
.cc-empty p{color:var(--g500);font-size:15px;margin:0 0 28px}
.cc-empty-acts{display:flex;flex-direction:column;gap:14px;max-width:300px;margin:0 auto}
.cc-empty-btn{display:flex;align-items:center;justify-content:center;gap:10px;padding:16px 24px;font-family:var(--font-d);font-size:14px;font-weight:700;text-transform:uppercase;text-decoration:none;border-radius:12px;border:2px solid transparent;transition:all .2s}
.cc-empty-btn.pri{background:var(--gold);color:var(--black)}
.cc-empty-btn.pri:hover{background:var(--gold-dark);transform:translateY(-2px);box-shadow:0 4px 12px rgba(252,185,0,.3)}
.cc-empty-btn.sec{background:var(--white);color:var(--black);border-color:var(--g300)}
.cc-empty-btn.sec:hover{border-color:var(--gold)}

/* ——— items column ——— */
.cc-items{display:flex;flex-direction:column;gap:20px}
.cc-sec-hdr{display:flex;align-items:center;justify-content:space-between;padding:0 4px 14px;border-bottom:2px solid var(--g200);margin-bottom:16px}
.cc-sec-title{font-family:var(--font-d);font-size:16px;display:flex;align-items:center;gap:8px}
.cc-sec-title svg{width:20px;height:20px;color:var(--gold)}
.cc-sec-count{font-size:13px;color:var(--g500)}

/* product card */
.cc-prod{display:grid;grid-template-columns:100px 1fr auto;gap:16px;background:var(--white);border-radius:18px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,.04);transition:box-shadow .25s,transform .25s}
.cc-prod:hover{box-shadow:0 8px 28px rgba(0,0,0,.1);transform:translateY(-2px)}
@media(max-width:640px){.cc-prod{grid-template-columns:72px 1fr;gap:12px;padding:14px;box-shadow:none;border:1px solid var(--g200);border-radius:14px}.cc-prod:hover{box-shadow:none;transform:none}}
@media(min-width:1200px){.cc-prod{grid-template-columns:120px 1fr auto;gap:24px;padding:24px}}
.cc-prod-img{width:100px;height:100px;border-radius:12px;overflow:hidden;background:var(--g100);flex-shrink:0}
@media(max-width:640px){.cc-prod-img{width:72px;height:72px}}
@media(min-width:1200px){.cc-prod-img{width:120px;height:120px}}
.cc-prod-img img{width:100%;height:100%;object-fit:cover}
.cc-prod-img .ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--g100),var(--g200))}
.cc-prod-img .ph svg{width:36px;height:36px;color:var(--g400)}
.cc-prod-info{display:flex;flex-direction:column;justify-content:center;min-width:0}
.cc-badge{display:inline-flex;align-items:center;gap:5px;font-family:var(--font-d);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:4px 9px;border-radius:6px;margin-bottom:8px;width:fit-content}
.cc-badge.camp{background:var(--gold);color:var(--black)}
.cc-badge.training{background:var(--black);color:var(--gold)}
.cc-prod-name{font-family:var(--font-d);font-size:17px;color:var(--black);text-transform:uppercase;margin:0 0 6px;line-height:1.2}
@media(max-width:640px){.cc-prod-name{font-size:14px}}
.cc-prod-meta{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px}
.cc-prod-meta span{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--g600)}
.cc-prod-meta svg{width:13px;height:13px;color:var(--gold)}
.cc-prod-price{font-family:var(--font-d);font-size:22px;color:var(--black)}
@media(max-width:640px){.cc-prod-price{font-size:18px}}
.cc-prod-actions{display:flex;flex-direction:column;align-items:flex-end;justify-content:flex-end}
@media(max-width:640px){.cc-prod-actions{grid-column:1/-1;flex-direction:row;justify-content:flex-end;padding-top:12px;border-top:1px solid var(--g200);margin-top:4px}}
.cc-rm-btn{display:flex;align-items:center;gap:6px;background:none;border:none;color:var(--g500);font-size:12px;cursor:pointer;padding:10px 14px;border-radius:8px;transition:all .15s;touch-action:manipulation;-webkit-tap-highlight-color:transparent;min-height:44px}
.cc-rm-btn:hover{color:var(--red);background:rgba(239,68,68,.1)}
.cc-rm-btn svg{width:16px;height:16px}

/* bundle badge */
.cc-bundle{display:inline-flex;align-items:center;gap:10px;background:linear-gradient(135deg,#22C55E,#16A34A);color:#fff;padding:12px 20px;border-radius:12px;font-size:14px;font-weight:600;margin-bottom:20px}
.cc-bundle svg{width:18px;height:18px}

/* ——— checkout form ——— */
/* ——— checkout form ——— */
.cc-proceed{background:var(--white);border-radius:18px;padding:24px;box-shadow:0 4px 24px rgba(0,0,0,.08);margin-top:24px;border:2px solid var(--gold)}
.cc-proceed-summary{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.cc-proceed-count{font-size:14px;color:var(--g600)}
.cc-proceed-total{font-family:var(--font-d);font-size:22px;font-weight:700;color:var(--black)}
.cc-proceed-btn{width:100%;padding:18px 24px;background:var(--gold);color:var(--black);border:none;border-radius:14px;font-family:var(--font-d);font-size:17px;font-weight:700;text-transform:uppercase;letter-spacing:.02em;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;transition:all .15s;touch-action:manipulation;-webkit-tap-highlight-color:rgba(252,185,0,.25);min-height:56px;-webkit-appearance:none;user-select:none}
.cc-proceed-btn:active{background:var(--gold-dark);transform:scale(.97);transition:all .05s}
.cc-proceed-btn:hover{background:var(--gold-dark);transform:translateY(-2px);box-shadow:0 6px 20px rgba(252,185,0,.4)}
.cc-proceed-btn svg{width:18px;height:18px}
.cc-checkout-form{margin-top:28px}
.cc-form-section{background:var(--white);border:1px solid var(--g200);border-radius:var(--r);overflow:hidden;margin-bottom:16px}
.cc-form-hdr{display:flex;align-items:center;gap:12px;padding:14px 18px;background:var(--g50);border-bottom:1px solid var(--g200);cursor:pointer;user-select:none;transition:background .1s;touch-action:manipulation;-webkit-tap-highlight-color:transparent}
.cc-form-hdr:hover{background:var(--g100)}
.cc-form-num{width:26px;height:26px;border-radius:50%;background:var(--black);color:var(--white);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0}
.cc-form-hdr.done .cc-form-num{background:var(--green)}
.cc-form-lbl{flex:1;font-size:14px;font-weight:600;color:var(--g800)}
.cc-form-sub{font-size:11px;color:var(--g500);margin-top:2px;font-weight:400}
.cc-form-tog{width:20px;height:20px;color:var(--g400);transition:transform .3s}
.cc-form-hdr.open .cc-form-tog{transform:rotate(180deg)}
.cc-form-body{padding:18px;display:none}
.cc-form-body.open{display:block}

/* fields */
.f-row{margin-bottom:14px}
.f-grid{display:grid;gap:10px}
@media(min-width:450px){.f-grid{grid-template-columns:1fr 1fr}}
.f-label{display:block;font-size:11px;font-weight:600;color:var(--g600);margin-bottom:5px;text-transform:uppercase;letter-spacing:.03em}
.f-input,.f-select{width:100%;padding:12px 14px;font-size:16px;font-family:inherit;border:2px solid var(--g200);border-radius:10px;background:var(--white);transition:border-color .2s;-webkit-appearance:none;-webkit-tap-highlight-color:transparent;touch-action:manipulation;min-height:48px}
@media(min-width:600px){.f-input,.f-select{padding:11px 13px;font-size:14px;min-height:auto}}
.f-input:focus,.f-select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(252,185,0,.1)}

/* saved players */
.cc-players{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.cc-player{display:flex;align-items:center;gap:10px;padding:10px 14px;border:2px solid var(--g200);border-radius:10px;cursor:pointer;transition:all .15s;flex:1;min-width:130px;touch-action:manipulation;-webkit-tap-highlight-color:transparent;user-select:none}
.cc-player:hover{border-color:var(--g300)}
.cc-player.sel{border-color:var(--gold);background:rgba(252,185,0,.04)}
.cc-player-av{width:34px;height:34px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;color:var(--black)}
.cc-player-nm{font-size:13px;font-weight:600;color:var(--g800)}
.cc-player-age{font-size:11px;color:var(--g500)}
.cc-add-player{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 16px;border:2px dashed var(--g300);border-radius:10px;color:var(--g500);font-size:12px;cursor:pointer;transition:all .2s;min-width:120px}
.cc-add-player:hover{border-color:var(--gold);color:var(--gold)}

/* payment */
.cc-pay-section{background:var(--g50);border-radius:var(--r);padding:18px;margin-top:12px}
.cc-pay-title{font-size:13px;font-weight:600;color:var(--g800);margin-bottom:14px;display:flex;align-items:center;gap:8px}
#payment-element{min-height:80px}
.cc-pay-err{padding:10px 14px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;color:var(--red);font-size:13px;margin-top:10px;display:none}

/* waiver */
.cc-waiver{margin-top:16px;padding:14px;background:var(--g50);border-radius:10px}
.cc-waiver label{display:flex;gap:10px;align-items:flex-start;cursor:pointer;font-size:13px;color:var(--g600);line-height:1.5}
.cc-waiver input{width:18px;height:18px;margin-top:2px;accent-color:var(--gold);flex-shrink:0}
.cc-waiver a{color:var(--gold)}

/* submit */
.cc-submit-btn{width:100%;padding:18px 24px;margin-top:18px;background:var(--gold);color:var(--black);border:none;border-radius:14px;font-family:var(--font-d);font-size:17px;font-weight:700;text-transform:uppercase;letter-spacing:.02em;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .15s;touch-action:manipulation;-webkit-tap-highlight-color:rgba(252,185,0,.25);min-height:56px;-webkit-appearance:none;user-select:none}
.cc-submit-btn:hover:not(:disabled){background:var(--gold-dark);transform:translateY(-1px);box-shadow:0 6px 20px rgba(252,185,0,.4)}
.cc-submit-btn:active:not(:disabled){background:#d49a00;transform:scale(0.97);box-shadow:none;transition:all .05s}
.cc-submit-btn:disabled{opacity:.6;cursor:not-allowed}
.cc-submit-btn.loading::after{content:'';width:18px;height:18px;border:2px solid var(--black);border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.cc-trust{display:flex;justify-content:center;gap:14px;margin-top:14px;flex-wrap:wrap}
.cc-trust span{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--g500)}
.cc-trust svg{width:13px;height:13px;color:var(--green)}

/* ——— order summary sidebar ——— */
.cc-summary{position:sticky;top:100px;background:var(--white);border-radius:20px;overflow:hidden;box-shadow:0 1px 8px rgba(0,0,0,.06);height:fit-content;border:1px solid var(--g200);content-visibility:auto;contain-intrinsic-size:auto 400px}
@media(max-width:1023px){.cc-summary{display:none}}
.cc-sum-hdr{background:var(--black);padding:24px 28px}
.cc-sum-hdr h2{font-size:18px;color:var(--white);margin:0}
.cc-sum-body{padding:28px}
.cc-sum-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;font-size:14px;color:var(--g600)}
.cc-sum-row.disc{color:var(--green)}
.cc-sum-row.disc .cc-sum-val{color:var(--green);font-weight:600}
.cc-sum-row.fee{color:var(--g500);font-size:13px}
.cc-sum-row.total{border-top:2px solid var(--g200);margin-top:10px;padding-top:18px;font-size:16px;font-weight:700;color:var(--black)}
.cc-sum-row.total .cc-sum-val{font-family:var(--font-d);font-size:28px}
.cc-sum-secure{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:14px;color:var(--g500);font-size:12px}
.cc-sum-secure svg{width:14px;height:14px;color:var(--green)}

/* coupon */
.cc-coupon{margin-top:16px;padding-top:16px;border-top:1px solid var(--g200)}
.cc-coupon-form{display:flex;gap:6px}
.cc-coupon-inp{flex:1;padding:10px 14px;border:2px solid var(--g200);border-radius:10px;font-size:13px;transition:border-color .2s}
.cc-coupon-inp:focus{outline:none;border-color:var(--gold)}
.cc-coupon-btn{padding:10px 18px;background:var(--black);color:var(--white);border:none;border-radius:10px;font-size:12px;font-weight:700;text-transform:uppercase;cursor:pointer;transition:background .15s;touch-action:manipulation;-webkit-tap-highlight-color:transparent;min-height:44px;-webkit-appearance:none}
.cc-coupon-btn:hover{background:var(--g800)}

/* ——— before/after care add-on ——— */
.cc-care{margin-top:16px;padding:16px;background:linear-gradient(135deg,#FFFBEB,#FEF3C7);border:1px solid #FDE68A;border-radius:12px}
.cc-care-hdr{display:flex;align-items:center;gap:8px;margin-bottom:12px;font-weight:700;font-size:14px;color:#92400E}
.cc-care-hdr svg{width:18px;height:18px;flex-shrink:0}
.cc-care-price{margin-left:auto;font-size:12px;font-weight:600;color:#B45309;background:#FEF3C7;padding:2px 8px;border-radius:6px}
.cc-care-opts{display:flex;flex-direction:column;gap:8px}
.cc-care-opt{display:flex;align-items:center;gap:12px;padding:12px 14px;background:#fff;border:2px solid #E5E7EB;border-radius:10px;cursor:pointer;transition:all .15s;user-select:none;touch-action:manipulation;-webkit-tap-highlight-color:transparent}
.cc-care-opt:hover{border-color:#FDE68A;background:#FFFEF5}
.cc-care-opt.on{border-color:#F59E0B;background:#FFFEF5;box-shadow:0 0 0 3px rgba(245,158,11,.12)}
.cc-care-opt input{display:none}
.cc-care-check{width:22px;height:22px;border-radius:6px;border:2px solid #D1D5DB;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .2s}
.cc-care-check svg{width:14px;height:14px;opacity:0;transition:opacity .15s;stroke:#fff}
.cc-care-opt.on .cc-care-check{background:#F59E0B;border-color:#F59E0B}
.cc-care-opt.on .cc-care-check svg{opacity:1}
.cc-care-detail{flex:1;min-width:0}
.cc-care-name{font-weight:700;font-size:14px;color:#111}
.cc-care-time{font-size:12px;color:#6B7280;margin-top:1px}
.cc-care-amt{font-weight:700;font-size:14px;color:#92400E;white-space:nowrap}
.cc-care-note{margin-top:8px;font-size:11px;color:#B45309;text-align:center;font-weight:500}

/* ——— mobile sticky bar ——— */
.cc-mbar{display:none}
@media(max-width:1023px){
.cc-mbar{display:block;position:fixed;bottom:0;left:0;right:0;z-index:700;background:var(--white);border-radius:20px 20px 0 0;box-shadow:0 -4px 20px rgba(0,0,0,.12);padding-bottom:var(--safe-b);transform:translateZ(0);backface-visibility:hidden;will-change:transform}
.cc-mbar::before{content:'';position:absolute;top:7px;left:50%;transform:translateX(-50%);width:36px;height:4px;background:var(--g300);border-radius:2px}
.cc-mbar-inner{padding:16px 16px 14px}
.cc-mbar-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.cc-mbar-lbl{font-size:13px;color:var(--g500)}
.cc-mbar-total{font-family:var(--font-d);font-size:26px;font-weight:700;color:var(--black)}
.cc-mbar-items{font-size:12px;color:var(--g500)}
.cc-mbar-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:16px;background:var(--gold);color:var(--black);font-family:var(--font-d);font-size:15px;font-weight:700;text-transform:uppercase;border:none;border-radius:14px;cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:rgba(252,185,0,.25);min-height:52px;-webkit-appearance:none;user-select:none;transition:all .1s}
.cc-mbar-btn:active{transform:scale(.96);background:var(--gold-dark);transition:all .05s}
.cc-mbar-btn svg{width:16px;height:16px}
}

/* ——— loading / toast ——— */
.cc-loading{position:fixed;inset:0;background:rgba(255,255,255,.9);display:flex;align-items:center;justify-content:center;z-index:200;opacity:0;visibility:hidden;transition:all .15s}
.cc-loading.on{opacity:1;visibility:visible}
.cc-spinner{width:40px;height:40px;border:3px solid var(--g200);border-top-color:var(--gold);border-radius:50%;animation:spin .7s linear infinite}
.cc-toast{position:fixed;bottom:200px;left:50%;transform:translateX(-50%) translateY(16px);background:var(--black);color:var(--white);padding:12px 24px;border-radius:10px;font-size:13px;z-index:200;opacity:0;visibility:hidden;transition:all .15s}
.cc-toast.show{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0)}
@media(min-width:1024px){.cc-toast{bottom:40px}}

/* express checkout */
.cc-express{margin-bottom:20px}
.cc-express-title{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:12px;font-size:12px;font-weight:500;color:var(--g500);text-transform:uppercase;letter-spacing:.05em}
#express-buttons{min-height:48px}
.cc-express-divider{display:flex;align-items:center;gap:14px;margin:20px 0;font-size:11px;font-weight:500;color:var(--g400);text-transform:uppercase;letter-spacing:.04em}
.cc-express-divider::before,.cc-express-divider::after{content:'';flex:1;height:1px;background:var(--g200)}
/* v177: Hide chat widgets on mobile */
@media(max-width:1023px){#tidio-chat,#crisp-chatbox,.crisp-client,#intercom-container,#intercom-frame,.intercom-lightweight-app,#hubspot-messages-iframe-container,#tawk-bubble-container,#tawkchat-container,.tawk-min-container,#drift-widget-container,#fc_frame,.fb_dialog,[id*="chat-widget"],[class*="chat-widget"],[class*="chat-bubble"],iframe[title*="chat" i],[id*="whatsapp"],[class*="whatsapp-chat"],.joinchat,.wp-social-chat-container,#olark-wrapper{display:none!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important;height:0!important;width:0!important}}
</style>

<script>document.body.classList.add('ptp-cart-active');</script>

<div class="cc-page">
    <div class="cc-banner">
        <a href="<?php echo esc_url(home_url()); ?>"><img src="<?php echo esc_url($logo); ?>" alt="PTP Soccer" style="height:32px;margin-bottom:12px;"></a>
        <h1><?php echo $is_empty ? 'Your Cart' : 'Cart &amp; Checkout'; ?></h1>
        <p><?php echo $is_empty ? 'Your cart is empty' : $cart_data['item_count'] . ' item' . ($cart_data['item_count'] !== 1 ? 's' : '') . ' — complete your purchase below'; ?></p>
    </div>

    <div class="cc-grid">
    <?php if ($is_empty): ?>
        <div class="cc-empty">
            <div class="cc-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg></div>
            <h2>Your Cart is Empty</h2>
            <p>Browse our summer camps or find a private trainer to get started.</p>
            <div class="cc-empty-acts">
                <a href="<?php echo esc_url(home_url('/ptp-find-a-camp/')); ?>" class="cc-empty-btn pri">Browse Camps</a>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="cc-empty-btn pri">Browse Training</a>
                <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="cc-empty-btn sec">Find a Trainer</a>
            </div>
        </div>
    <?php else: ?>

        <!-- ═══ LEFT COLUMN: ITEMS + CHECKOUT FORM ═══ -->
        <div class="cc-items">

            <?php if ($has_bundle): ?>
            <div class="cc-bundle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Camp + Training Bundle — Save 5%</div>
            <?php endif; ?>

            <!-- CAMP ITEMS -->
            <?php if (!empty($cart_data['camp_items'])): ?>
            <div class="cc-sec">
                <div class="cc-sec-hdr"><h2 class="cc-sec-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/></svg> Summer Camps</h2><span class="cc-sec-count"><?php echo $camp_count; ?> week<?php echo $camp_count!==1?'s':''; ?></span></div>
                <?php foreach ($cart_data['camp_items'] as $key => $item): ?>
                <div class="cc-prod" data-key="<?php echo esc_attr($key); ?>">
                    <div class="cc-prod-img"><?php if (!empty($item['image'])): ?><img src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['name']); ?>"><?php else: ?><div class="ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="5"/></svg></div><?php endif; ?></div>
                    <div class="cc-prod-info">
                        <span class="cc-badge camp">Summer Camp</span>
                        <h3 class="cc-prod-name"><?php echo esc_html($item['name']); ?></h3>
                        <div class="cc-prod-meta">
                            <?php if ($item['dates']): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><?php echo esc_html($item['dates']); ?></span><?php endif; ?>
                            <?php if ($item['location']): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg><?php echo esc_html($item['location']); ?></span><?php endif; ?>
                        </div>
                        <div class="cc-prod-price">$<?php echo number_format($item['price'],0); ?></div>
                    </div>
                    <div class="cc-prod-actions"><button type="button" class="cc-rm-btn" onclick="ccRemove('<?php echo esc_js($key); ?>','camp')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>Remove</button></div>
                </div>
                <?php endforeach; ?>

                <!-- v200.2: Before & After Care Add-on — $50 one / $100 both -->
                <div class="cc-care" id="ccCare">
                    <div class="cc-care-hdr">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Extended Care</span>
                        <span class="cc-care-price">$<?php echo $care_price_one; ?>/one · $<?php echo $care_price_both; ?>/both per week</span>
                    </div>
                    <div class="cc-care-opts">
                        <label class="cc-care-opt<?php echo $cart_data['before_care'] ? ' on' : ''; ?>">
                            <input type="checkbox" id="ccBefore" <?php checked($cart_data['before_care']); ?> onchange="ccCareToggle()">
                            <div class="cc-care-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
                            <div class="cc-care-detail">
                                <div class="cc-care-name">Before Care</div>
                                <div class="cc-care-time">8:00 – 9:00 AM</div>
                            </div>
                            <div class="cc-care-amt" id="ccBeforeAmt">+$<?php echo $cart_data['after_care'] ? ($care_price_both - $care_price_one) : $care_price_one; ?></div>
                        </label>
                        <label class="cc-care-opt<?php echo $cart_data['after_care'] ? ' on' : ''; ?>">
                            <input type="checkbox" id="ccAfter" <?php checked($cart_data['after_care']); ?> onchange="ccCareToggle()">
                            <div class="cc-care-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
                            <div class="cc-care-detail">
                                <div class="cc-care-name">After Care</div>
                                <div class="cc-care-time">3:00 – 4:30 PM</div>
                            </div>
                            <div class="cc-care-amt" id="ccAfterAmt">+$<?php echo $cart_data['before_care'] ? ($care_price_both - $care_price_one) : $care_price_one; ?></div>
                        </label>
                    </div>
                    <div class="cc-care-note" id="ccCareNote">
                        <?php if ($care_options === 2): ?>
                            Both selected — $<?php echo $care_price_both; ?>/week<?php echo $camp_count > 1 ? ' × ' . $camp_count . ' weeks' : ''; ?> (save $<?php echo ($care_price_one * 2) - $care_price_both; ?>)
                        <?php elseif ($care_options === 1): ?>
                            Add both for just $<?php echo $care_price_both; ?>/week (save $<?php echo ($care_price_one * 2) - $care_price_both; ?>)
                        <?php else: ?>
                            $<?php echo $care_price_one; ?>/week for one · $<?php echo $care_price_both; ?>/week for both
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- TRAINING ITEMS -->
            <?php if (!empty($cart_data['training_items'])): ?>
            <div class="cc-sec" <?php if (!empty($cart_data['camp_items'])): ?>style="margin-top:24px"<?php endif; ?>>
                <div class="cc-sec-hdr"><h2 class="cc-sec-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="2"/></svg> Private Training</h2><span class="cc-sec-count"><?php echo $training_count; ?> session<?php echo $training_count!==1?'s':''; ?></span></div>
                <?php foreach ($cart_data['training_items'] as $key => $item): ?>
                <div class="cc-prod" data-key="<?php echo esc_attr($key); ?>">
                    <div class="cc-prod-img"><?php if (!empty($item['trainer_photo'])): ?><img src="<?php echo esc_url($item['trainer_photo']); ?>" alt="<?php echo esc_attr($item['trainer_name']); ?>"><?php else: ?><div class="ph"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><?php endif; ?></div>
                    <div class="cc-prod-info">
                        <span class="cc-badge training">Training</span><?php if ($cart_group_size > 1): ?><span class="cc-badge" style="background:#FEF3C7;color:#92400E;margin-left:4px"><?php echo $cart_group_size; ?> Players</span><?php endif; ?>
                        <h3 class="cc-prod-name"><?php echo esc_html($item['trainer_name']); ?></h3>
                        <div class="cc-prod-meta">
                            <?php if ($item['package_name']): ?><span><?php echo esc_html($item['package_name']); ?></span><?php endif; ?>
                            <?php if ($item['date']): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><?php echo esc_html(date('M j, Y', strtotime($item['date']))); ?></span><?php endif; ?>
                            <?php if (!empty($item['time'])): 
                                $t = $item['time'];
                                if (function_exists('ptp_normalize_session_time')) $t = ptp_normalize_session_time($t) ?: $t;
                                $tts = strtotime($t);
                                $t_display = $tts ? date('g:i A', $tts) : $t;
                            ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php echo esc_html($t_display); ?></span><?php endif; ?>
                            <?php if ($item['location']): ?><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg><?php echo esc_html($item['location']); ?><?php if (!empty($item['location_address']) && $item['location_address'] !== $item['location']): ?> <small style="opacity:.55;font-size:11px"><?php echo esc_html($item['location_address']); ?></small><?php endif; ?></span><?php endif; ?>
                        </div>
                        <div class="cc-prod-price">$<?php echo number_format($item['price'],0); ?></div>
                    </div>
                    <div class="cc-prod-actions"><button type="button" class="cc-rm-btn" onclick="ccRemove('<?php echo esc_js($key); ?>','training')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>Remove</button></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══ MENTORSHIP CROSS-SELL ═══ -->
            <?php
            // Show mentorship upsell if parent is buying training sessions and trainer offers mentorship
            $mentor_upsell_trainer = null;
            if ($training_count > 0 && !empty($cart_data['training_items'])) {
                $first_training = reset($cart_data['training_items']);
                $upsell_tid = intval($first_training['trainer_id'] ?? 0);
                if ($upsell_tid) {
                    $mentor_upsell_trainer = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, display_name, photo_url, slug, mentorship_enabled, mentorship_bio, mentorship_max_mentees
                         FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND mentorship_enabled = 1 AND status = 'active'",
                        $upsell_tid
                    ));
                    // Skip if parent already has mentorship with this trainer
                    if ($mentor_upsell_trainer && is_user_logged_in()) {
                        $already = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND parent_id = %d AND status NOT IN ('cancelled') LIMIT 1",
                            $upsell_tid, get_current_user_id()
                        ));
                        if ($already) $mentor_upsell_trainer = null;
                    }
                }
            }
            if ($mentor_upsell_trainer):
                $mu_first = explode(' ', $mentor_upsell_trainer->display_name)[0];
                $mu_signup = add_query_arg(array('trainer_id' => $mentor_upsell_trainer->id, 'package' => 'development', 'source' => 'cart_upsell'), home_url('/mentorship-signup/'));
            ?>
            <div style="background:linear-gradient(135deg,#0A0A0A,#1A1A1A);border:2px solid var(--gold);border-radius:16px;padding:18px;margin-bottom:16px;color:#fff">
                <div style="display:flex;gap:12px;align-items:center;margin-bottom:10px">
                    <?php if ($mentor_upsell_trainer->photo_url): ?>
                    <img src="<?php echo esc_url($mentor_upsell_trainer->photo_url); ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #FCB900" alt="">
                    <?php endif; ?>
                    <div>
                        <div style="font-family:Oswald,sans-serif;font-size:10px;text-transform:uppercase;letter-spacing:2px;color:#FCB900;margin-bottom:2px">Weekly Mentorship</div>
                        <div style="font-family:Oswald,sans-serif;font-size:16px;text-transform:uppercase;font-weight:700">Want <?php echo esc_html($mu_first); ?> Every Week?</div>
                    </div>
                </div>
                <p style="font-size:12px;color:#A3A3A3;line-height:1.5;margin-bottom:14px">
                    Turn this session into a real relationship. Weekly video calls, film review, goal tracking, and a parent summary after every session. From <strong style="color:#FCB900">$49/session</strong>.
                </p>
                <a href="<?php echo esc_url($mu_signup); ?>" style="display:block;text-align:center;padding:12px;background:#FCB900;color:#0A0A0A;font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;font-size:13px;border-radius:10px;text-decoration:none;letter-spacing:0.5px">Free Intro Call with <?php echo esc_html($mu_first); ?></a>
                <div style="text-align:center;font-size:10px;color:#525252;margin-top:6px">No payment until after your intro call. Cancel anytime.</div>
            </div>
            <?php endif; ?>

            <!-- ═══ PROCEED TO CHECKOUT CTA ═══ -->
            <div class="cc-proceed" id="ccProceed">
                <div class="cc-proceed-summary">
                    <div class="cc-proceed-count"><?php echo $cart_data['item_count']; ?> item<?php echo $cart_data['item_count'] !== 1 ? 's' : ''; ?> in cart</div>
                    <div class="cc-proceed-total">Total: $<?php echo number_format($cart_data['total'], 0); ?></div>
                </div>
                <button type="button" class="cc-proceed-btn" onclick="ccShowCheckout()">
                    Proceed to Checkout
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </div>

            <!-- ═══ CHECKOUT FORM ═══ -->
            <?php if ($has_stripe): ?>
            <div class="cc-checkout-form" id="ccCheckoutSection" style="display:none;">
                <h2 style="font-size:22px;margin-bottom:18px;padding-top:8px;border-top:3px solid var(--gold);">Complete Your Purchase</h2>

                <!-- Express Checkout -->
                <div class="cc-express">
                    <div class="cc-express-title"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg> Express Checkout</div>
                    <div id="express-buttons"></div>
                    <div class="cc-express-divider">or fill in below</div>
                </div>

                <form id="checkout-form" method="post" autocomplete="on">
                    <input type="hidden" name="action" value="ptp_save_checkout">
                    <?php wp_nonce_field('ptp_checkout', 'ptp_checkout_nonce'); ?>
                    <input type="hidden" name="checkout_session" value="<?php echo esc_attr($checkout_session_id); ?>">
                    <input type="hidden" name="cart_total" id="cartTotal" value="<?php echo $cart_data['total']; ?>">
                    <?php if ($native_training_data): ?>
                    <input type="hidden" name="has_training" value="1">
                    <input type="hidden" name="trainer_id" value="<?php echo esc_attr($native_training_data['trainer_id']); ?>">
                    <input type="hidden" name="trainer_name" value="<?php echo esc_attr($native_training_data['trainer_name']); ?>">
                    <input type="hidden" name="training_package" value="<?php echo esc_attr($native_training_data['package']); ?>">
                    <input type="hidden" name="training_sessions" value="<?php echo esc_attr($native_training_data['sessions']); ?>">
                    <input type="hidden" name="training_price" value="<?php echo esc_attr($native_training_data['price']); ?>">
                    <input type="hidden" name="training_total" value="<?php echo esc_attr($native_training_data['price']); ?>">
                    <input type="hidden" name="session_date" value="<?php echo esc_attr($native_training_data['date']); ?>">
                    <input type="hidden" name="session_time" value="<?php echo esc_attr($native_training_data['time']); ?>">
                    <input type="hidden" name="session_location" value="<?php echo esc_attr($native_training_data['location']); ?>">
                    <input type="hidden" name="session_location_address" value="<?php echo esc_attr($native_training_data['location_address'] ?? ''); ?>">
                    <input type="hidden" name="session_location_lat" value="<?php echo esc_attr($native_training_data['location_lat'] ?? ''); ?>">
                    <input type="hidden" name="session_location_lng" value="<?php echo esc_attr($native_training_data['location_lng'] ?? ''); ?>">
                    <input type="hidden" name="group_size" value="<?php echo esc_attr($native_training_data['group_size']); ?>">
                    <?php endif; ?>
                    <?php // Encode ALL training items with date/time/location for checkout processing
                    if (!empty($cart_data['training_items'])): ?>
                    <input type="hidden" name="all_training_items" value="<?php echo esc_attr(json_encode(array_values(array_map(function($t) {
                        return array(
                            'trainer_id' => $t['trainer_id'] ?? '',
                            'trainer_name' => $t['trainer_name'] ?? '',
                            'package' => $t['package'] ?? 'single',
                            'package_name' => $t['package_name'] ?? 'Training',
                            'sessions' => $t['sessions'] ?? 1,
                            'date' => $t['date'] ?? '',
                            'time' => $t['time'] ?? '',
                            'location' => $t['location'] ?? '',
                            'location_address' => $t['location_address'] ?? '',
                            'location_lat' => $t['location_lat'] ?? '',
                            'location_lng' => $t['location_lng'] ?? '',
                            'price' => $t['price'] ?? 0,
                        );
                    }, $cart_data['training_items'])))); ?>">
                    <?php endif; ?>
                    <?php // Encode ALL camp items for checkout processing
                    if (!empty($cart_data['camp_items'])): ?>
                    <input type="hidden" name="all_camp_items" value="<?php echo esc_attr(json_encode(array_values(array_map(function($c) {
                        return array(
                            'id' => $c['id'] ?? '',
                            'name' => $c['name'] ?? '',
                            'dates' => $c['dates'] ?? '',
                            'time' => $c['time'] ?? '',
                            'location' => $c['location'] ?? '',
                            'price' => $c['price'] ?? 0,
                        );
                    }, $cart_data['camp_items'])))); ?>">
                    <?php endif; ?>
                    <?php // v136: Always render care fields so client-side toggle can update them ?>
                    <input type="hidden" name="before_care" value="<?php echo $cart_data['before_care']; ?>">
                    <input type="hidden" name="after_care" value="<?php echo $cart_data['after_care']; ?>">
                    <input type="hidden" name="extra_care_total" value="<?php echo $cart_data['extra_care_total']; ?>">

                    <!-- SECTION 1: Player Info (v200: Multi-player group support) -->
                    <div class="cc-form-section" data-section="1">
                        <div class="cc-form-hdr open" onclick="ccToggle(this)">
                            <div class="cc-form-num">1</div>
                            <div class="cc-form-lbl">Player Information<div class="cc-form-sub"><?php echo $cart_group_size > 1 ? "Select {$cart_group_size} players for group training" : "Who's " . ($cart_data['has_training'] ? 'training' : 'attending camp') . '?'; ?></div></div>
                            <svg class="cc-form-tog" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                        </div>
                        <div class="cc-form-body open">
                            <?php if ($cart_group_size > 1): ?>
                            <!-- v200: Group training banner -->
                            <div style="background:linear-gradient(135deg,#FFF7ED,#FEF3C7);border:1px solid #FDE68A;border-radius:10px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
                                <div style="width:36px;height:36px;display:flex;align-items:center;justify-content:center"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#92400E" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 00-16 0"/><circle cx="18" cy="10" r="3" stroke-dasharray="2 2"/></svg></div>
                                <div>
                                    <div style="font-weight:700;font-size:13px;color:#92400E">Group Session — <?php echo $cart_group_size; ?> Players</div>
                                    <div style="font-size:11px;color:#B45309;margin-top:2px">Select or add <?php echo $cart_group_size; ?> players below. Siblings, friends, teammates — all welcome!</div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($players)): ?>
                            <div class="cc-players" id="ccPlayerList">
                                <?php foreach ($players as $p): ?>
                                <div class="cc-player" data-pid="<?php echo $p->id; ?>" data-fn="<?php echo esc_attr($p->first_name); ?>" data-ln="<?php echo esc_attr($p->last_name); ?>" data-dob="<?php echo esc_attr($p->dob); ?>" data-shirt="<?php echo esc_attr($p->shirt_size ?? ''); ?>" onclick="ccPickPlayer(this)">
                                    <div class="cc-player-av"><?php echo strtoupper(substr($p->first_name,0,1)); ?></div>
                                    <div><div class="cc-player-nm"><?php echo esc_html($p->first_name.' '.$p->last_name); ?></div><?php if ($p->dob): ?><div class="cc-player-age">Age <?php echo floor((time()-strtotime($p->dob))/31556952); ?></div><?php endif; ?></div>
                                    <?php if ($cart_group_size > 1): ?><div class="cc-player-check" style="margin-left:auto;width:22px;height:22px;border:2px solid #d1d5db;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .15s"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" style="display:none"><path d="M20 6L9 17l-5-5"/></svg></div><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <div class="cc-add-player" onclick="ccNewPlayer()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg><?php echo $cart_group_size > 1 ? 'Add Player' : 'New'; ?></div>
                            </div>
                            <?php if ($cart_group_size > 1): ?>
                            <div id="ccGroupCount" style="text-align:center;font-size:12px;font-weight:600;margin-top:8px;color:#6B7280;transition:color .2s">0 of <?php echo $cart_group_size; ?> players selected</div>
                            <?php endif; ?>
                            <?php endif; ?>

                            <!-- Hidden fields for player data -->
                            <input type="hidden" name="player_id" id="playerId" value="">
                            <?php if ($cart_group_size > 1): ?>
                            <input type="hidden" name="group_player_ids" id="groupPlayerIds" value="">
                            <input type="hidden" name="group_player_count" value="<?php echo $cart_group_size; ?>">
                            <?php endif; ?>

                            <!-- New player form (shared for single + group) -->
                            <div id="playerFields" style="<?php echo !empty($players)?'display:none;':''; ?>">
                                <?php if ($cart_group_size > 1): ?>
                                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:6px" id="newPlayerLabel">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 00-16 0"/></svg>
                                    <span>New Player Details</span>
                                </div>
                                <?php endif; ?>
                                <div class="f-row f-grid"><div><label class="f-label">First Name *</label><input type="text" name="player_first_name" id="pFN" class="f-input" required></div><div><label class="f-label">Last Name *</label><input type="text" name="player_last_name" id="pLN" class="f-input" required></div></div>
                                <div class="f-row f-grid"><div><label class="f-label">Date of Birth *</label><input type="date" name="player_dob" id="pDOB" class="f-input" required></div><div><label class="f-label">T-Shirt Size <?php echo $cart_data['has_camps'] ? '*' : '(Optional)'; ?></label><select name="player_shirt" id="pShirt" class="f-select" <?php echo $cart_data['has_camps'] ? 'required' : ''; ?>><option value="">Select</option><option value="YS">Youth S</option><option value="YM">Youth M</option><option value="YL">Youth L</option><option value="AS">Adult S</option><option value="AM">Adult M</option><option value="AL">Adult L</option><option value="AXL">Adult XL</option></select></div></div>
                                <div class="f-row"><label class="f-label">Team/Club (Optional)</label><input type="text" name="player_team" class="f-input" placeholder="e.g. FC Lightning U12"></div>
                                <div class="f-row"><label class="f-label">Medical Notes (Optional)</label><textarea name="player_medical" class="f-input" rows="2" placeholder="Allergies, medications, etc."></textarea></div>
                                <?php if ($cart_group_size > 1): ?>
                                <button type="button" onclick="ccAddNewPlayerToGroup()" style="width:100%;padding:10px;background:#FCB900;color:#0A0A0A;border:none;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer;margin-top:4px">Add This Player</button>
                                <div id="newPlayerMsg" style="display:none;text-align:center;font-size:12px;margin-top:8px;padding:8px;border-radius:6px"></div>
                                <?php endif; ?>
                            </div>

                            <?php if ($cart_group_size > 1): ?>
                            <!-- v200: Added players list (for new players not yet in system) -->
                            <div id="ccAddedPlayers" style="margin-top:10px"></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- SECTION 2: Parent / Guardian -->
                    <div class="cc-form-section" data-section="2">
                        <div class="cc-form-hdr" onclick="ccToggle(this)">
                            <div class="cc-form-num">2</div>
                            <div class="cc-form-lbl">Parent / Guardian<div class="cc-form-sub">Contact &amp; emergency info</div></div>
                            <svg class="cc-form-tog" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                        </div>
                        <div class="cc-form-body">
                            <?php if ($logged_in && $parent): ?>
                            <div style="background:var(--g50);border-radius:10px;padding:14px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
                                <div style="width:36px;height:36px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:600;color:var(--black)"><?php echo strtoupper(substr($parent->first_name ?: $user->display_name,0,1)); ?></div>
                                <div style="flex:1"><div style="font-weight:600;font-size:14px"><?php echo esc_html($parent->first_name.' '.$parent->last_name); ?></div><div style="font-size:12px;color:var(--g500)"><?php echo esc_html($user->user_email); ?></div></div>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                            </div>
                            <input type="hidden" name="parent_first_name" value="<?php echo esc_attr($parent->first_name); ?>">
                            <input type="hidden" name="parent_last_name" value="<?php echo esc_attr($parent->last_name); ?>">
                            <input type="hidden" name="parent_email" value="<?php echo esc_attr($user->user_email); ?>">
                            <input type="hidden" name="parent_phone" value="<?php echo esc_attr($parent->phone); ?>">
                            <?php else: ?>
                            <div class="f-row f-grid"><div><label class="f-label">First Name *</label><input type="text" name="parent_first_name" class="f-input" required autocomplete="given-name"></div><div><label class="f-label">Last Name *</label><input type="text" name="parent_last_name" class="f-input" required autocomplete="family-name"></div></div>
                            <div class="f-row f-grid"><div><label class="f-label">Email *</label><input type="email" name="parent_email" class="f-input" required autocomplete="email"></div><div><label class="f-label">Phone *</label><input type="tel" name="parent_phone" class="f-input" required autocomplete="tel" placeholder="(555) 123-4567"></div></div>
                            <?php endif; ?>
                            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--g200)">
                                <div style="font-size:12px;font-weight:600;color:var(--g700);margin-bottom:10px">EMERGENCY CONTACT</div>
                                <?php $em_name = $parent ? ($parent->emergency_name ?? '') : ''; $em_phone = $parent ? ($parent->emergency_phone ?? '') : ''; $em_rel = $parent ? ($parent->emergency_relation ?? '') : ''; ?>
                                <div class="f-row f-grid"><div><label class="f-label">Name *</label><input type="text" name="emergency_name" class="f-input" required value="<?php echo esc_attr($em_name); ?>"></div><div><label class="f-label">Phone *</label><input type="tel" name="emergency_phone" class="f-input" required value="<?php echo esc_attr($em_phone); ?>"></div></div>
                                <div class="f-row"><label class="f-label">Relationship *</label><select name="emergency_relation" class="f-select" required><option value="">Select</option><option value="Spouse" <?php selected($em_rel,'Spouse'); ?>>Spouse</option><option value="Grandparent" <?php selected($em_rel,'Grandparent'); ?>>Grandparent</option><option value="Aunt/Uncle" <?php selected($em_rel,'Aunt/Uncle'); ?>>Aunt/Uncle</option><option value="Family Friend" <?php selected($em_rel,'Family Friend'); ?>>Family Friend</option><option value="Other" <?php selected($em_rel,'Other'); ?>>Other</option></select></div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION 3: Payment -->
                    <div class="cc-form-section" data-section="3">
                        <div class="cc-form-hdr" onclick="ccToggle(this)">
                            <div class="cc-form-num">3</div>
                            <div class="cc-form-lbl">Payment<div class="cc-form-sub">Secure card payment via Stripe</div></div>
                            <svg class="cc-form-tog" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                        </div>
                        <div class="cc-form-body">
                            <div class="cc-pay-section">
                                <div class="cc-pay-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Card Details</div>
                                <div id="payment-element"></div>
                                <div class="cc-pay-err" id="payError"></div>
                            </div>
                            <div class="cc-waiver"><label><input type="checkbox" name="waiver" id="waiver" required> I accept the <a href="#" target="_blank">Liability Waiver</a> and <a href="#" target="_blank">Terms of Service</a>. I authorize PTP Soccer to photograph/video my child for promotional purposes.</label></div>
                            <button type="submit" class="cc-submit-btn" id="submitBtn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Complete Registration · $<span id="submitTotal"><?php echo number_format($cart_data['total'],2); ?></span></button>
                            <div class="cc-trust"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> SSL Secure</span><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg> 14-Day Refund</span><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Visa, MC, Amex</span></div>
                        </div>
                    </div>
                </form>
            </div>
            <?php elseif (!$is_empty && $cents < 50): ?>
            <!-- v216: Free checkout form — total is $0 after coupons/discounts -->
            <div class="cc-checkout-form" id="ccCheckoutSection" style="display:none;">
                <h2 style="font-size:22px;margin-bottom:18px;padding-top:8px;border-top:3px solid var(--gold);">Complete Your Free Registration</h2>
                <div style="padding:14px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.2);border-radius:12px;color:#065F46;font-size:14px;margin-bottom:20px;display:flex;align-items:center;gap:8px">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                    <span>Your order total is <strong>$0.00</strong> — no payment needed!</span>
                </div>
                <form id="checkout-form" method="post" autocomplete="on">
                    <input type="hidden" name="action" value="ptp_complete_free_checkout">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('ptp_free_checkout'); ?>">
                    <input type="hidden" name="checkout_session" value="<?php echo esc_attr($checkout_session_id); ?>">
                    <input type="hidden" name="cart_total" id="cartTotal" value="0">
                    <?php if ($native_training_data): ?>
                    <input type="hidden" name="has_training" value="1">
                    <input type="hidden" name="trainer_id" value="<?php echo esc_attr($native_training_data['trainer_id']); ?>">
                    <input type="hidden" name="trainer_name" value="<?php echo esc_attr($native_training_data['trainer_name']); ?>">
                    <input type="hidden" name="training_package" value="<?php echo esc_attr($native_training_data['package']); ?>">
                    <input type="hidden" name="training_sessions" value="<?php echo esc_attr($native_training_data['sessions']); ?>">
                    <input type="hidden" name="training_price" value="<?php echo esc_attr($native_training_data['price']); ?>">
                    <input type="hidden" name="session_date" value="<?php echo esc_attr($native_training_data['date']); ?>">
                    <input type="hidden" name="session_time" value="<?php echo esc_attr($native_training_data['time']); ?>">
                    <input type="hidden" name="session_location" value="<?php echo esc_attr($native_training_data['location']); ?>">
                    <input type="hidden" name="session_location_address" value="<?php echo esc_attr($native_training_data['location_address'] ?? ''); ?>">
                    <input type="hidden" name="group_size" value="<?php echo esc_attr($native_training_data['group_size']); ?>">
                    <?php endif; ?>
                    <?php if (!empty($cart_data['camp_items'])): ?>
                    <input type="hidden" name="all_camp_items" value="<?php echo esc_attr(json_encode(array_values(array_map(function($c) { return array('id' => $c['id'] ?? '', 'name' => $c['name'] ?? '', 'dates' => $c['dates'] ?? '', 'time' => $c['time'] ?? '', 'location' => $c['location'] ?? '', 'price' => $c['price'] ?? 0); }, $cart_data['camp_items'])))); ?>">
                    <?php endif; ?>

                    <!-- Player Info -->
                    <div class="cc-form-section" data-section="1">
                        <div class="cc-form-hdr" onclick="ccToggle(this)">
                            <div class="cc-form-num">1</div>
                            <div class="cc-form-lbl">Player Information<div class="cc-form-sub"><?php echo $cart_data['has_training'] ? "Who's training?" : "Who's attending camp?"; ?></div></div>
                            <svg class="cc-form-tog" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                        </div>
                        <div class="cc-form-body open">
                            <?php if (!empty($players)): ?>
                            <div class="cc-players">
                                <?php foreach ($players as $p): ?>
                                <div class="cc-player" data-pid="<?php echo $p->id; ?>" data-fn="<?php echo esc_attr($p->first_name); ?>" data-ln="<?php echo esc_attr($p->last_name); ?>" data-dob="<?php echo esc_attr($p->dob); ?>" data-shirt="<?php echo esc_attr($p->shirt_size); ?>" onclick="ccPickPlayer(this)">
                                    <div class="cc-player-av"><?php echo strtoupper(substr($p->first_name,0,1)); ?></div>
                                    <div><div class="cc-player-nm"><?php echo esc_html($p->first_name.' '.$p->last_name); ?></div><?php if($p->dob): ?><div class="cc-player-age">Age <?php echo floor((time()-strtotime($p->dob))/31556952); ?></div><?php endif; ?></div>
                                </div>
                                <?php endforeach; ?>
                                <div class="cc-player-add" onclick="ccNewPlayer()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> New Player</div>
                            </div>
                            <?php endif; ?>
                            <input type="hidden" name="player_id" id="playerId" value="">
                            <div id="playerFields" style="<?php echo !empty($players)?'display:none;':''; ?>">
                                <div class="f-row f-grid"><div><label class="f-label">First Name *</label><input type="text" name="player_first_name" id="pFN" class="f-input" required></div><div><label class="f-label">Last Name *</label><input type="text" name="player_last_name" id="pLN" class="f-input" required></div></div>
                                <div class="f-row f-grid"><div><label class="f-label">Date of Birth *</label><input type="date" name="player_dob" id="pDOB" class="f-input" required></div><div><label class="f-label">T-Shirt Size <?php echo $cart_data['has_camps'] ? '*' : '(Optional)'; ?></label><select name="player_shirt" id="pShirt" class="f-select" <?php echo $cart_data['has_camps'] ? 'required' : ''; ?>><option value="">Select</option><option value="YS">Youth S</option><option value="YM">Youth M</option><option value="YL">Youth L</option><option value="AS">Adult S</option><option value="AM">Adult M</option><option value="AL">Adult L</option><option value="AXL">Adult XL</option></select></div></div>
                                <div class="f-row"><label class="f-label">Team/Club (Optional)</label><input type="text" name="player_team" class="f-input" placeholder="e.g. FC Lightning U12"></div>
                                <div class="f-row"><label class="f-label">Medical Notes (Optional)</label><textarea name="player_medical" class="f-input" rows="2" placeholder="Allergies, medications, etc."></textarea></div>
                            </div>
                        </div>
                    </div>

                    <!-- Parent/Guardian -->
                    <div class="cc-form-section" data-section="2">
                        <div class="cc-form-hdr" onclick="ccToggle(this)">
                            <div class="cc-form-num">2</div>
                            <div class="cc-form-lbl">Parent / Guardian<div class="cc-form-sub">Contact &amp; emergency info</div></div>
                            <svg class="cc-form-tog" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                        </div>
                        <div class="cc-form-body">
                            <?php if ($logged_in && $parent): ?>
                            <div style="background:var(--g50);border-radius:10px;padding:14px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
                                <div style="width:36px;height:36px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:600;color:var(--black)"><?php echo strtoupper(substr($parent->first_name ?: $user->display_name,0,1)); ?></div>
                                <div style="flex:1"><div style="font-weight:600;font-size:14px"><?php echo esc_html($parent->first_name.' '.$parent->last_name); ?></div><div style="font-size:12px;color:var(--g500)"><?php echo esc_html($user->user_email); ?></div></div>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                            </div>
                            <input type="hidden" name="parent_first_name" value="<?php echo esc_attr($parent->first_name); ?>">
                            <input type="hidden" name="parent_last_name" value="<?php echo esc_attr($parent->last_name); ?>">
                            <input type="hidden" name="parent_email" value="<?php echo esc_attr($user->user_email); ?>">
                            <input type="hidden" name="parent_phone" value="<?php echo esc_attr($parent->phone); ?>">
                            <?php else: ?>
                            <div class="f-row f-grid"><div><label class="f-label">First Name *</label><input type="text" name="parent_first_name" class="f-input" required autocomplete="given-name"></div><div><label class="f-label">Last Name *</label><input type="text" name="parent_last_name" class="f-input" required autocomplete="family-name"></div></div>
                            <div class="f-row f-grid"><div><label class="f-label">Email *</label><input type="email" name="parent_email" class="f-input" required autocomplete="email"></div><div><label class="f-label">Phone *</label><input type="tel" name="parent_phone" class="f-input" required autocomplete="tel" placeholder="(555) 123-4567"></div></div>
                            <?php endif; ?>
                            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--g200)">
                                <div style="font-size:12px;font-weight:600;color:var(--g700);margin-bottom:10px">EMERGENCY CONTACT</div>
                                <?php $em_name2 = $parent ? ($parent->emergency_name ?? '') : ''; $em_phone2 = $parent ? ($parent->emergency_phone ?? '') : ''; $em_rel2 = $parent ? ($parent->emergency_relation ?? '') : ''; ?>
                                <div class="f-row f-grid"><div><label class="f-label">Name *</label><input type="text" name="emergency_name" class="f-input" required value="<?php echo esc_attr($em_name2); ?>"></div><div><label class="f-label">Phone *</label><input type="tel" name="emergency_phone" class="f-input" required value="<?php echo esc_attr($em_phone2); ?>"></div></div>
                                <div class="f-row"><label class="f-label">Relationship *</label><select name="emergency_relation" class="f-select" required><option value="">Select</option><option value="Spouse" <?php selected($em_rel2,'Spouse'); ?>>Spouse</option><option value="Grandparent" <?php selected($em_rel2,'Grandparent'); ?>>Grandparent</option><option value="Aunt/Uncle" <?php selected($em_rel2,'Aunt/Uncle'); ?>>Aunt/Uncle</option><option value="Family Friend" <?php selected($em_rel2,'Family Friend'); ?>>Family Friend</option><option value="Other" <?php selected($em_rel2,'Other'); ?>>Other</option></select></div>
                            </div>
                        </div>
                    </div>

                    <!-- Waiver & Submit -->
                    <div class="cc-form-section" data-section="3">
                        <div class="cc-form-hdr" onclick="ccToggle(this)">
                            <div class="cc-form-num">3</div>
                            <div class="cc-form-lbl">Waiver<div class="cc-form-sub">Review &amp; confirm</div></div>
                            <svg class="cc-form-tog" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                        </div>
                        <div class="cc-form-body">
                            <div class="cc-waiver"><label><input type="checkbox" name="waiver" id="waiver" required> I accept the <a href="#" target="_blank">Liability Waiver</a> and <a href="#" target="_blank">Terms of Service</a>. I authorize PTP Soccer to photograph/video my child for promotional purposes.</label></div>
                            <button type="submit" class="cc-submit-btn" id="submitBtn"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg> Complete Free Registration</button>
                            <div class="cc-pay-err" id="payError"></div>
                        </div>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="cc-checkout-form" id="ccCheckoutSection" style="display:none;margin-top:24px">
                <div style="padding:20px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:12px;color:var(--red);font-size:14px">Payment system unavailable — <a href="<?php echo esc_url(home_url('/ptp-cart/')); ?>" style="color:var(--gold);font-weight:600">Refresh</a> or try <a href="<?php
                    // v213: Carry training params to fallback checkout
                    $co_params = array_filter(array(
                        'trainer_id' => $_GET['trainer_id'] ?? '',
                        'package'    => $_GET['package'] ?? '',
                        'date'       => $_GET['date'] ?? '',
                        'time'       => $_GET['time'] ?? '',
                        'location'   => $_GET['location'] ?? '',
                        'location_address' => $_GET['location_address'] ?? '',
                        'location_lat' => $_GET['location_lat'] ?? '',
                        'location_lng' => $_GET['location_lng'] ?? '',
                        'group_size' => $_GET['group_size'] ?? '',
                        'camp'       => $_GET['camp'] ?? '',
                    ));
                    echo esc_url(home_url('/ptp-checkout/' . ($co_params ? '?' . http_build_query($co_params) : '')));
                ?>" style="color:var(--gold);font-weight:600">standard checkout</a></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ RIGHT COLUMN: ORDER SUMMARY ═══ -->
        <div class="cc-summary">
            <div class="cc-sum-hdr"><h2>Order Summary</h2></div>
            <div class="cc-sum-body">
                <div class="cc-sum-row"><span>Subtotal (<?php echo $cart_data['item_count']; ?> item<?php echo $cart_data['item_count']!==1?'s':''; ?>)</span><span class="cc-sum-val">$<?php echo number_format($cart_data['subtotal'],0); ?></span></div>
                <?php if ($cart_data['early_bird_discount'] > 0): ?><div class="cc-sum-row disc"><span><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-1px;margin-right:3px"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>Early Bird ($50/camp)</span><span class="cc-sum-val">-$<?php echo number_format($cart_data['early_bird_discount'],0); ?></span></div><?php endif; ?>
                <?php if ($cart_data['multi_week_discount'] > 0): ?><div class="cc-sum-row disc"><span><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-1px;margin-right:3px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>Multi-Week Discount</span><span class="cc-sum-val">-$<?php echo number_format($cart_data['multi_week_discount'],0); ?></span></div><?php endif; ?>
                <?php if ($cart_data['bundle_discount'] > 0): ?><div class="cc-sum-row disc"><span><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" stroke="none" style="vertical-align:-1px;margin-right:3px"><path d="M20 6h-2.18c.11-.31.18-.65.18-1 0-1.66-1.34-3-3-3-1.05 0-1.96.54-2.5 1.35l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2z"/></svg>Bundle Discount (5%)</span><span class="cc-sum-val">-$<?php echo number_format($cart_data['bundle_discount'],0); ?></span></div><?php endif; ?>
                <div class="cc-sum-row" id="ccCareSumRow" <?php if ($cart_data['extra_care_total'] <= 0): ?>style="display:none"<?php endif; ?>><span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2" style="vertical-align:-1px;margin-right:3px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php
                    $care_labels = [];
                    if ($cart_data['before_care']) $care_labels[] = 'Before';
                    if ($cart_data['after_care']) $care_labels[] = 'After';
                    echo implode(' + ', $care_labels) . ' Care';
                    if ($camp_count > 1) echo ' (' . $camp_count . ' wks)';
                ?></span><span class="cc-sum-val" style="color:#92400E">+$<?php echo number_format($cart_data['extra_care_total'], 0); ?></span></div>
                <?php if ($cart_data['processing_fee'] > 0): ?><div class="cc-sum-row fee"><span>Processing Fee</span><span>$<?php echo number_format($cart_data['processing_fee'],2); ?></span></div><?php endif; ?>
                <div class="cc-sum-row total"><span>Total</span><span class="cc-sum-val" id="sideTotal">$<?php echo number_format($cart_data['total'],0); ?></span></div>

                <!-- coupon -->
                <div class="cc-coupon">
                    <form class="cc-coupon-form" onsubmit="ccCoupon(event)"><input type="text" class="cc-coupon-inp" id="sideCode" placeholder="Coupon code"><button type="submit" class="cc-coupon-btn">Apply</button></form>
                </div>

                <div class="cc-sum-secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Secure checkout · 14-day refund guarantee</div>
            </div>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php if (!$is_empty): ?>
<!-- Mobile Sticky -->
<div class="cc-mbar" id="mbar">
    <div class="cc-mbar-inner">
        <div class="cc-mbar-row"><div><div class="cc-mbar-lbl">Total</div><div class="cc-mbar-items"><?php echo $cart_data['item_count']; ?> item<?php echo $cart_data['item_count']!==1?'s':''; ?></div></div><div class="cc-mbar-total" id="mbarTotal">$<?php echo number_format($cart_data['total'],0); ?></div></div>
        <button type="button" class="cc-mbar-btn" id="mbarBtn" onclick="ccMbarAction()">Proceed to Checkout <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></button>
    </div>
</div>
<?php endif; ?>

<div class="cc-loading" id="ccLoading"><div class="cc-spinner"></div></div>
<div class="cc-toast" id="ccToast"></div>

<?php if ($has_stripe): ?>
<!-- v136: Stripe.js lazy-loaded on checkout click, PI created via AJAX -->
<script>
window._ptpStripeLoaded=false;window._ptpStripeCallbacks=[];
window._ptpLoadStripe=function(cb){
    if(window._ptpStripeLoaded){if(cb)cb();return}
    if(cb)window._ptpStripeCallbacks.push(cb);
    if(document.getElementById('_ptpStripeJs'))return;
    var s=document.createElement('script');s.id='_ptpStripeJs';s.src='https://js.stripe.com/v3/';
    s.onload=function(){window._ptpStripeLoaded=true;window._ptpStripeCallbacks.forEach(function(fn){fn()});window._ptpStripeCallbacks=[]};
    document.head.appendChild(s);
};
</script>
<?php endif; ?>

<script>
(function(){
    var ajax='<?php echo esc_js($ajax_url); ?>',nonce='<?php echo esc_js($cart_nonce); ?>';

    /* v213: Build /ptp-checkout/ fallback URL preserving training params */
    var _coFallback = (function(){
        var u = new URL(window.location);
        var base = '<?php echo esc_url(home_url('/ptp-checkout/')); ?>';
        var keep = ['trainer_id','package','date','time','location','location_address','location_lat','location_lng','group_size','camp','before_care','after_care'];
        var params = new URLSearchParams();
        keep.forEach(function(k){ var v = u.searchParams.get(k); if (v) params.set(k, v); });
        var qs = params.toString();
        return qs ? base + '?' + qs : base;
    })();

    /* v136: Proceed to checkout — create PI via AJAX, then init Stripe */
    var _piCreating = false;
    var _piReady = false;
    function _ccCreatePiAndInit() {
        if (_piCreating || _piReady) return;
        _piCreating = true;
        var fd = new FormData();
        fd.append('action', 'ptp_cart_create_pi');
        fd.append('nonce', nonce);
        fd.append('checkout_session', '<?php echo esc_js($checkout_session_id); ?>');
        fd.append('before_care', document.getElementById('ccBefore') && document.getElementById('ccBefore').checked ? '1' : '0');
        fd.append('after_care', document.getElementById('ccAfter') && document.getElementById('ccAfter').checked ? '1' : '0');
        
        // Show loading state in payment section
        var payEl = document.getElementById('payment-element');
        if (payEl) payEl.innerHTML = '<div style="text-align:center;padding:20px;color:#737373"><div class="cc-spinner" style="margin:0 auto 12px;width:28px;height:28px"></div>Initializing payment...</div>';
        
        fetch(ajax, {method:'POST', body:fd, credentials:'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(res) {
                _piCreating = false;
                if (res.success && res.data.client_secret) {
                    _piReady = true;
                    window._ptpLoadStripe(function() { ccInitStripe(res.data.client_secret); });
                } else if (res.success && res.data.free) {
                    // Total became $0 — reload to show free checkout form
                    window.location.reload();
                } else {
                    var msg = (res.data && res.data.message) ? res.data.message : 'Payment initialization failed';
                    if (payEl) payEl.innerHTML = '<div style="padding:14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;color:#EF4444;font-size:13px">' + msg + ' — <a href="javascript:location.reload()" style="color:#FCB900;font-weight:600">Retry</a></div>';
                }
            })
            .catch(function() {
                _piCreating = false;
                if (payEl) payEl.innerHTML = '<div style="padding:14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;color:#EF4444;font-size:13px">Network error — <a href="javascript:location.reload()" style="color:#FCB900;font-weight:600">Retry</a></div>';
            });
    }

    window.ccShowCheckout=function(){
        var el=document.getElementById('ccCheckoutSection');
        if(el){el.style.display='block';el.scrollIntoView({behavior:'smooth',block:'start'});_ccCreatePiAndInit();}
        else{window.location.href=_coFallback;}
    };

    window.ccMbarAction=function(){
        var el=document.getElementById('ccCheckoutSection');
        if(el){el.style.display='block';el.scrollIntoView({behavior:'smooth',block:'start'});_ccCreatePiAndInit();}
        else{window.location.href=_coFallback;}
    };

    function loading(on){var e=document.getElementById('ccLoading');if(e)e.classList.toggle('on',on)}
    function toast(m,err){var t=document.getElementById('ccToast');if(!t)return;t.textContent=m;t.className='cc-toast show'+(err?' err':'');setTimeout(function(){t.classList.remove('show')},3000)}

    /* Remove item */
    window.ccRemove=function(key,type){
        if(!confirm(type==='camp'?'Remove this camp?':'Remove this training session?'))return;
        loading(true);
        var u=new URL(window.location),tid=u.searchParams.get('trainer_id');
        /* Training item stored only in URL params — just strip training params and reload */
        if(type==='training'&&tid&&key==='training_'+tid){u.searchParams.delete('trainer_id');u.searchParams.delete('package');u.searchParams.delete('date');u.searchParams.delete('time');u.searchParams.delete('location');u.searchParams.delete('location_address');u.searchParams.delete('location_lat');u.searchParams.delete('location_lng');u.searchParams.delete('group_size');window.location.href=u.toString();return}
        var fd=new FormData();fd.append('action','ptp_remove_cart_item');fd.append('cart_key',key);fd.append('item_type',type);fd.append('nonce',nonce);
        /* Always reload after removal attempt (strips URL params so item can't re-add) */
        var doReload=function(){var url=new URL(window.location);
            if(type==='camp'){url.searchParams.delete('camp');url.searchParams.delete('before_care');url.searchParams.delete('after_care')}
            else if(type==='training'){url.searchParams.delete('trainer_id');url.searchParams.delete('package');url.searchParams.delete('date');url.searchParams.delete('time');url.searchParams.delete('location');url.searchParams.delete('location_address');url.searchParams.delete('location_lat');url.searchParams.delete('location_lng');url.searchParams.delete('group_size')}
            window.location.href=url.toString()};
        fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(res){
            loading(false);
            if(res.success){toast('Item removed');var el=document.querySelector('[data-key="'+key+'"]');if(el){el.style.transition='opacity .3s,transform .3s';el.style.opacity='0';el.style.transform='translateX(-20px)'}}
            else{toast('Removing...')}
            setTimeout(doReload,400)
        }).catch(function(){loading(false);toast("Removing...");setTimeout(doReload,600)})
    };

    /* Before/After Care toggle — v136: client-side recalculation, no reload */
    var _careOnePrice = <?php echo $care_price_one; ?>;
    var _careBothPrice = <?php echo $care_price_both; ?>;
    var _campCount = <?php echo $camp_count; ?>;
    var _subtotal = <?php echo $cart_data['subtotal']; ?>;
    var _earlyBird = <?php echo $cart_data['early_bird_discount']; ?>;
    var _multiWeek = <?php echo $cart_data['multi_week_discount']; ?>;
    var _bundleDisc = <?php echo $cart_data['bundle_discount']; ?>;
    var _couponDisc = <?php echo $cart_data['coupon_discount']; ?>;
    
    window.ccCareToggle=function(){
        var b=document.getElementById('ccBefore'),a=document.getElementById('ccAfter');
        if(!b&&!a)return;
        var bc=b&&b.checked?1:0, ac=a&&a.checked?1:0;
        var opts=bc+ac;
        var carePerWeek = opts===2 ? _careBothPrice : (opts===1 ? _careOnePrice : 0);
        var careTotal = carePerWeek * _campCount;
        
        // Update URL params (for persistence on refresh) without reloading
        var url=new URL(window.location);
        if(bc){url.searchParams.set('before_care','1')}else{url.searchParams.delete('before_care')}
        if(ac){url.searchParams.set('after_care','1')}else{url.searchParams.delete('after_care')}
        history.replaceState(null,'',url.toString());
        
        // Toggle visual state
        [b,a].forEach(function(cb){
            if(!cb)return;
            var opt=cb.closest('.cc-care-opt');
            if(cb.checked){opt.classList.add('on')}else{opt.classList.remove('on')}
        });
        
        // Update care price labels
        var bAmt=document.getElementById('ccBeforeAmt'),aAmt=document.getElementById('ccAfterAmt');
        if(bAmt) bAmt.textContent='+$'+(ac ? (_careBothPrice-_careOnePrice) : _careOnePrice);
        if(aAmt) aAmt.textContent='+$'+(bc ? (_careBothPrice-_careOnePrice) : _careOnePrice);
        
        // Update care note
        var note=document.getElementById('ccCareNote');
        if(note){
            if(opts===2) note.innerHTML='Both selected — $'+_careBothPrice+'/week'+(_campCount>1?' × '+_campCount+' weeks':'')+' (save $'+((_careOnePrice*2)-_careBothPrice)+')';
            else if(opts===1) note.innerHTML='Add both for just $'+_careBothPrice+'/week (save $'+((_careOnePrice*2)-_careBothPrice)+')';
            else note.innerHTML='$'+_careOnePrice+'/week for one · $'+_careBothPrice+'/week for both';
        }
        
        // Recalculate totals
        var net=Math.max(0,_subtotal+careTotal-_earlyBird-_multiWeek-_bundleDisc-_couponDisc);
        var fee=(net>0)?Math.round((net*0.03+0.30)*100)/100:0;
        var total=Math.max(0,net+fee);
        
        // Update hidden form fields
        var ct=document.getElementById('cartTotal');if(ct)ct.value=total.toFixed(2);
        var bc_h=document.querySelector('input[name="before_care"]');if(bc_h)bc_h.value=bc;
        var ac_h=document.querySelector('input[name="after_care"]');if(ac_h)ac_h.value=ac;
        var ec_h=document.querySelector('input[name="extra_care_total"]');if(ec_h)ec_h.value=careTotal.toFixed(2);
        
        // Update care summary row
        var careRow=document.getElementById('ccCareSumRow');
        if(careRow){
            if(careTotal>0){
                careRow.style.display='flex';
                var labels=[];if(bc)labels.push('Before');if(ac)labels.push('After');
                careRow.querySelector('span:first-child').innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2" style="vertical-align:-1px;margin-right:3px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'+labels.join(' + ')+' Care'+(_campCount>1?' ('+_campCount+' wks)':'');
                careRow.querySelector('.cc-sum-val').textContent='+$'+Math.round(careTotal);
            } else { careRow.style.display='none'; }
        }
        
        // Update all total displays
        var fmt=function(n){return'$'+Math.round(n).toLocaleString()};
        var fmtDec=function(n){return n.toFixed(2)};
        ['sideTotal','mbarTotal'].forEach(function(id){var el=document.getElementById(id);if(el)el.textContent=fmt(total)});
        var st=document.getElementById('submitTotal');if(st)st.textContent=fmtDec(total);
        var pt=document.querySelector('.cc-proceed-total');if(pt)pt.textContent='Total: '+fmt(total);
        
        // Update fee display
        var feeRow=document.querySelector('.cc-sum-row.fee .cc-sum-val, .cc-sum-row.fee span:last-child');
        if(feeRow)feeRow.textContent='$'+fee.toFixed(2);
        
        // Reset PI since amount changed — will be recreated on checkout click
        if(window._stripeInitDone){window._stripeInitDone=false;_piReady=false;}
    };

    /* Toggle form sections */
    window.ccToggle=function(hdr){
        var body=hdr.nextElementSibling,isOpen=body.classList.contains('open');
        document.querySelectorAll('.cc-form-body').forEach(function(b){b.classList.remove('open')});
        document.querySelectorAll('.cc-form-hdr').forEach(function(h){h.classList.remove('open')});
        if(!isOpen){hdr.classList.add('open');body.classList.add('open')}
    };

    /* Saved players — v200: Group multi-select support */
    var ccGroupSize = <?php echo intval($cart_group_size); ?>;
    var ccSelectedIds = [];
    var ccNewPlayers = []; // Players added inline (not yet in DB)

    window.ccPickPlayer=function(el){
        if (ccGroupSize <= 1) {
            // Single player mode (original behavior)
            document.querySelectorAll('.cc-player').forEach(function(p){p.classList.remove('sel')});
            el.classList.add('sel');
            document.getElementById('playerId').value=el.dataset.pid;
            var pf=document.getElementById('playerFields');pf.style.display='none';
            pf.querySelectorAll('[required]').forEach(function(f){f.removeAttribute('required');f.dataset.wasReq='1'});
        } else {
            // Multi-player mode: toggle selection
            var pid = el.dataset.pid;
            var idx = ccSelectedIds.indexOf(pid);
            if (idx > -1) {
                // Deselect
                ccSelectedIds.splice(idx, 1);
                el.classList.remove('sel');
                var chk = el.querySelector('.cc-player-check');
                if (chk) { chk.style.background=''; chk.style.borderColor='#d1d5db'; chk.querySelector('svg').style.display='none'; }
            } else {
                // Check limit
                var totalSelected = ccSelectedIds.length + ccNewPlayers.length;
                if (totalSelected >= ccGroupSize) {
                    ccToast('Maximum ' + ccGroupSize + ' players for this group session', true);
                    return;
                }
                ccSelectedIds.push(pid);
                el.classList.add('sel');
                var chk = el.querySelector('.cc-player-check');
                if (chk) { chk.style.background='#FCB900'; chk.style.borderColor='#FCB900'; chk.querySelector('svg').style.display='block'; }
            }
            // Update hidden field and counter
            document.getElementById('groupPlayerIds').value = JSON.stringify(ccSelectedIds);
            document.getElementById('playerId').value = ccSelectedIds[0] || '';
            ccUpdateGroupCount();
        }
    };

    window.ccNewPlayer=function(){
        if (ccGroupSize <= 1) {
            // Single mode: show form
            document.querySelectorAll('.cc-player').forEach(function(p){p.classList.remove('sel')});
            document.getElementById('playerId').value='';
            var pf=document.getElementById('playerFields');pf.style.display='block';
            pf.querySelectorAll('[data-was-req]').forEach(function(f){f.setAttribute('required','')});
        } else {
            // Multi mode: check limit first
            var totalSelected = ccSelectedIds.length + ccNewPlayers.length;
            if (totalSelected >= ccGroupSize) {
                ccToast('Maximum ' + ccGroupSize + ' players for this group session', true);
                return;
            }
            // Show form
            var pf=document.getElementById('playerFields');pf.style.display='block';
            pf.querySelectorAll('[data-was-req]').forEach(function(f){f.setAttribute('required','')});
            // Clear form
            document.getElementById('pFN').value='';
            document.getElementById('pLN').value='';
            document.getElementById('pDOB').value='';
            document.getElementById('pShirt').value='';
            pf.querySelector('[name=player_team]').value='';
            pf.querySelector('[name=player_medical]').value='';
            pf.scrollIntoView({behavior:'smooth',block:'center'});
        }
    };

    // v200: Add new player to group (inline)
    window.ccAddNewPlayerToGroup=function(){
        var fn=document.getElementById('pFN').value.trim();
        var ln=document.getElementById('pLN').value.trim();
        var dob=document.getElementById('pDOB').value;
        var shirt=document.getElementById('pShirt').value;
        var msgEl=document.getElementById('newPlayerMsg');

        if(!fn||!ln){
            msgEl.style.display='block';msgEl.style.background='#FEF2F2';msgEl.style.color='#DC2626';
            msgEl.textContent='Please enter first and last name';return;
        }

        var totalSelected = ccSelectedIds.length + ccNewPlayers.length;
        if (totalSelected >= ccGroupSize) {
            msgEl.style.display='block';msgEl.style.background='#FEF2F2';msgEl.style.color='#DC2626';
            msgEl.textContent='Maximum ' + ccGroupSize + ' players reached';return;
        }

        // Add to new players list
        var newP = {
            id: 'new_' + Date.now(),
            first_name: fn,
            last_name: ln,
            dob: dob,
            shirt_size: shirt,
            team: document.querySelector('[name=player_team]').value.trim(),
            medical: document.querySelector('[name=player_medical]').value.trim()
        };
        ccNewPlayers.push(newP);

        // Show success
        msgEl.style.display='block';msgEl.style.background='#ECFDF5';msgEl.style.color='#065F46';
        msgEl.textContent=fn + ' added!';
        setTimeout(function(){ msgEl.style.display='none'; }, 2000);

        // Add card to the added players list
        var container = document.getElementById('ccAddedPlayers');
        var card = document.createElement('div');
        card.className = 'cc-player sel';
        card.dataset.newIdx = ccNewPlayers.length - 1;
        card.style.border = '2px solid #FCB900';
        card.style.background = 'rgba(252,185,0,0.06)';
        card.innerHTML = '<div class="cc-player-av" style="background:#FCB900;color:#0A0A0A">' + fn.charAt(0).toUpperCase() + '</div>'
            + '<div><div class="cc-player-nm">' + fn + ' ' + ln + '</div><div class="cc-player-age" style="color:#059669">Just added</div></div>'
            + '<div onclick="ccRemoveNewPlayer(' + (ccNewPlayers.length - 1) + ',this.parentElement)" style="margin-left:auto;cursor:pointer;padding:4px;color:#9CA3AF;font-size:18px">&times;</div>';
        container.appendChild(card);

        // Store new players data as hidden inputs
        ccSyncNewPlayerInputs();

        // Hide form, update count
        var pf=document.getElementById('playerFields');pf.style.display='none';
        pf.querySelectorAll('[required]').forEach(function(f){f.removeAttribute('required');f.dataset.wasReq='1'});
        ccUpdateGroupCount();
    };

    // v200: Remove a newly added player
    window.ccRemoveNewPlayer=function(idx, el){
        ccNewPlayers.splice(idx, 1);
        if(el) el.remove();
        // Re-index remaining cards
        var cards = document.querySelectorAll('#ccAddedPlayers .cc-player');
        cards.forEach(function(c,i){ c.dataset.newIdx = i; });
        ccSyncNewPlayerInputs();
        ccUpdateGroupCount();
    };

    // v200: Sync new player data to hidden inputs for form submission
    function ccSyncNewPlayerInputs(){
        // Remove old hidden inputs
        document.querySelectorAll('.cc-new-player-input').forEach(function(el){el.remove()});
        var form = document.getElementById('checkout-form');
        if(!form) return;
        // New players get indices AFTER existing selected players
        var offset = ccSelectedIds.length;
        ccNewPlayers.forEach(function(p,i){
            var idx = offset + i;
            var fields = {first_name:p.first_name, last_name:p.last_name, dob:p.dob, shirt_size:p.shirt_size, team:p.team||'', medical:p.medical||'', player_id:'0'};
            Object.keys(fields).forEach(function(k){
                var inp = document.createElement('input');
                inp.type='hidden'; inp.name='players['+idx+']['+k+']'; inp.value=fields[k];
                inp.className='cc-new-player-input';
                form.appendChild(inp);
            });
        });
    }

    // v200: Before submit, build complete players[] array (existing selected + new)
    function ccSyncAllPlayersForSubmit(){
        // Remove all previous player inputs
        document.querySelectorAll('.cc-group-player-input').forEach(function(el){el.remove()});
        var form = document.getElementById('checkout-form');
        if(!form) return;

        // 1. Existing selected players (from ccSelectedIds — pull data from DOM)
        ccSelectedIds.forEach(function(pid, i){
            var el = document.querySelector('.cc-player[data-pid="'+pid+'"]');
            if(!el) return;
            var fields = {
                player_id: pid,
                first_name: el.dataset.fn || '',
                last_name: el.dataset.ln || '',
                dob: el.dataset.dob || '',
                shirt_size: el.dataset.shirt || '',
                team: '', medical: ''
            };
            Object.keys(fields).forEach(function(k){
                var inp = document.createElement('input');
                inp.type='hidden'; inp.name='players['+i+']['+k+']'; inp.value=fields[k];
                inp.className='cc-group-player-input';
                form.appendChild(inp);
            });
        });

        // 2. New players (from ccNewPlayers — already have their data)
        var offset = ccSelectedIds.length;
        ccNewPlayers.forEach(function(p, i){
            var idx = offset + i;
            var fields = {
                player_id: '0',
                first_name: p.first_name,
                last_name: p.last_name,
                dob: p.dob || '',
                shirt_size: p.shirt_size || '',
                team: p.team || '', medical: p.medical || ''
            };
            Object.keys(fields).forEach(function(k){
                var inp = document.createElement('input');
                inp.type='hidden'; inp.name='players['+idx+']['+k+']'; inp.value=fields[k];
                inp.className='cc-group-player-input';
                form.appendChild(inp);
            });
        });
    }

    // v200: Update group count display
    function ccUpdateGroupCount(){
        var el = document.getElementById('ccGroupCount');
        if(!el) return;
        var total = ccSelectedIds.length + ccNewPlayers.length;
        el.textContent = total + ' of ' + ccGroupSize + ' players selected';
        if(total >= ccGroupSize){
            el.style.color='#059669'; el.style.fontWeight='700';
        } else if(total > 0){
            el.style.color='#D97706'; el.style.fontWeight='600';
        } else {
            el.style.color='#6B7280'; el.style.fontWeight='600';
        }
    }

    function ccToast(msg, isErr){
        var t=document.createElement('div');
        t.style.cssText='position:fixed;bottom:80px;left:50%;transform:translateX(-50%);padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:700;animation:fadeIn .2s;max-width:90vw;text-align:center;'
            + (isErr?'background:#FEF2F2;color:#DC2626;border:1px solid #FECACA':'background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0');
        t.textContent=msg;document.body.appendChild(t);
        setTimeout(function(){t.style.opacity='0';t.style.transition='opacity .3s';setTimeout(function(){t.remove()},300)},2500);
    }

    /* Coupon */
    window.ccCoupon=function(e){
        e.preventDefault();var code=document.getElementById('sideCode').value.trim();
        if(!code){toast('Enter a code',true);return}
        loading(true);
        var fd=new FormData();fd.append('action','ptp_apply_coupon');fd.append('coupon_code',code);fd.append('nonce','<?php echo esc_js($coupon_nonce); ?>');
        fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(res){loading(false);if(res.success){toast('Coupon applied!');setTimeout(function(){window.location.reload()},500)}else toast('Invalid coupon',true)}).catch(function(){loading(false);toast('Error',true)})
    };

    /* Init: hide required on hidden player fields */
    var pf=document.getElementById('playerFields');
    if(pf&&pf.style.display==='none'){pf.querySelectorAll('[required]').forEach(function(f){f.removeAttribute('required');f.dataset.wasReq='1'})}
    /* v200: In group mode, always hide the required on player fields since players are added via button */
    if(ccGroupSize>1&&pf){pf.style.display='none';pf.querySelectorAll('[required]').forEach(function(f){f.removeAttribute('required');f.dataset.wasReq='1'})}
})();

/* ═══ STRIPE ═══ */
<?php if ($has_stripe): ?>
window.ccInitStripe=function(secret){
    if(!secret){return;}
    if(typeof Stripe==='undefined'){return;}
    if(window._stripeInitDone){return;}
    window._stripeInitDone=true;
    var stripe=Stripe('<?php echo esc_js($stripe_pk); ?>');
    var sessId='<?php echo esc_js($checkout_session_id); ?>';
    window.paymentIntentId=secret.split('_secret')[0];

    var elements=stripe.elements({clientSecret:secret,appearance:{theme:'stripe',variables:{colorPrimary:'#FCB900',colorBackground:'#ffffff',colorText:'#1f2937',colorDanger:'#ef4444',fontFamily:'Inter,system-ui,sans-serif',borderRadius:'10px'}}});

    var payEl=elements.create('payment',{layout:'tabs',wallets:{applePay:'auto',googlePay:'auto'}});
    payEl.mount('#payment-element');

    var expEl=elements.create('expressCheckout',{buttonType:{applePay:'buy',googlePay:'buy'},buttonTheme:{applePay:'black',googlePay:'black'},buttonHeight:48});
    expEl.mount('#express-buttons');

    expEl.on('confirm',async function(ev){
        /* v200: Validate group player count for express checkout */
        if(typeof ccGroupSize!=='undefined'&&ccGroupSize>1){
            var totalPlayers=(typeof ccSelectedIds!=='undefined'?ccSelectedIds.length:0)+(typeof ccNewPlayers!=='undefined'?ccNewPlayers.length:0);
            if(totalPlayers<ccGroupSize){showErr('Please select '+ccGroupSize+' players for your group session');return}
            ccSyncAllPlayersForSubmit();
        }
        var fd=new FormData(document.getElementById('checkout-form'));fd.append('checkout_session',sessId);
        var saveRes=await fetch('<?php echo esc_js($ajax_url); ?>',{method:'POST',body:fd}).then(function(r){return r.json()});
        if(!saveRes.success){showErr(saveRes.data&&saveRes.data.message?saveRes.data.message:'Please fill in all required fields.');return}
        var{error}=await stripe.confirmPayment({elements:elements,confirmParams:{return_url:'<?php echo esc_js($thank_you_url); ?>'}});
        if(error)showErr(error.message);
    });

    document.getElementById('checkout-form').onsubmit=async function(e){
        e.preventDefault();var btn=document.getElementById('submitBtn');btn.disabled=true;btn.classList.add('loading');hideErr();
        /* v200: Validate group player count */
        if(typeof ccGroupSize!=='undefined'&&ccGroupSize>1){
            var totalPlayers=(typeof ccSelectedIds!=='undefined'?ccSelectedIds.length:0)+(typeof ccNewPlayers!=='undefined'?ccNewPlayers.length:0);
            if(totalPlayers<ccGroupSize){showErr('Please select '+ccGroupSize+' players for your group session ('+totalPlayers+' selected)');btn.disabled=false;btn.classList.remove('loading');return}
            /* v200: Inject existing selected players into players[] for unified checkout */
            ccSyncAllPlayersForSubmit();
        }
        if(!document.getElementById('waiver').checked){showErr('Please accept the waiver');btn.disabled=false;btn.classList.remove('loading');return}
        try{
            var fd=new FormData(this);fd.append('checkout_session',sessId);
            var saveRes=await fetch('<?php echo esc_js($ajax_url); ?>',{method:'POST',body:fd}).then(function(r){return r.json()});
            if(!saveRes.success){showErr(saveRes.data&&saveRes.data.message?saveRes.data.message:'Please fill in all required fields.');btn.disabled=false;btn.classList.remove('loading');return}

            /* v216: Free checkout — skip Stripe when total is $0 */
            var currentTotal=parseFloat(document.getElementById('cartTotal').value)||0;
            if(currentTotal<=0){
                var freeData=new FormData(this);
                freeData.set('action','ptp_complete_free_checkout');
                freeData.append('checkout_session',sessId);
                freeData.append('nonce','<?php echo wp_create_nonce("ptp_free_checkout"); ?>');
                var codeEl=document.getElementById('sideCode');
                freeData.append('app_code',codeEl?codeEl.value.trim().toUpperCase():'');
                try{
                    var freeRaw=await fetch('<?php echo esc_js($ajax_url); ?>',{method:'POST',body:freeData});
                    var freeTxt=await freeRaw.text();
                    var freeRes;
                    try{freeRes=JSON.parse(freeTxt)}catch(pe){console.error('Free checkout non-JSON:',freeTxt.substring(0,500));showErr('Booking failed — server error. Please try again.');btn.disabled=false;btn.classList.remove('loading');return}
                    if(freeRes.success){
                        var rUrl='<?php echo esc_js($thank_you_url); ?>'+'&free=1';
                        if(freeRes.data&&freeRes.data.booking_id)rUrl+='&booking='+freeRes.data.booking_id;
                        window.location.href=rUrl;
                    }else{
                        showErr(freeRes.data&&freeRes.data.message?freeRes.data.message:'Could not complete free booking');
                        btn.disabled=false;btn.classList.remove('loading');
                    }
                }catch(fe){console.error('Free checkout error:',fe);showErr('Network error. Please try again.');btn.disabled=false;btn.classList.remove('loading')}
                return;
            }

            var{error}=await stripe.confirmPayment({elements:elements,confirmParams:{return_url:'<?php echo esc_js($thank_you_url); ?>'}});
            if(error){showErr(error.message);btn.disabled=false;btn.classList.remove('loading')}
        }catch(err){showErr('An error occurred. Please try again.');btn.disabled=false;btn.classList.remove('loading')}
    };

    function showErr(m){var e=document.getElementById('payError');e.textContent=m;e.style.display='block'}
    function hideErr(){document.getElementById('payError').style.display='none'}
    window.stripeElements=elements;
};
/* v136: Stripe init is now called from _ccCreatePiAndInit after AJAX returns client_secret */
<?php endif; ?>

<?php if (!$is_empty && $cents < 50): ?>
/* v216: Free checkout handler — no Stripe needed */
(function(){
    var form=document.getElementById('checkout-form');
    if(!form)return;
    var ajax='<?php echo esc_js($ajax_url); ?>';
    var sessId='<?php echo esc_js($checkout_session_id); ?>';

    form.onsubmit=async function(e){
        e.preventDefault();
        var btn=document.getElementById('submitBtn');
        btn.disabled=true;btn.classList.add('loading');
        var errEl=document.getElementById('payError');
        if(errEl)errEl.style.display='none';

        if(!document.getElementById('waiver').checked){
            if(errEl){errEl.textContent='Please accept the waiver';errEl.style.display='block'}
            btn.disabled=false;btn.classList.remove('loading');return;
        }
        try{
            /* First save checkout data (using ptp_save_checkout) */
            var saveData=new FormData(form);
            saveData.set('action','ptp_save_checkout');
            saveData.set('nonce','');
            /* Include proper checkout nonce */
            saveData.append('ptp_checkout_nonce','<?php echo wp_create_nonce("ptp_checkout"); ?>');
            saveData.append('checkout_session',sessId);
            var saveRes=await fetch(ajax,{method:'POST',body:saveData}).then(function(r){return r.json()});
            if(!saveRes.success){
                if(errEl){errEl.textContent=saveRes.data&&saveRes.data.message?saveRes.data.message:'Please fill in all required fields.';errEl.style.display='block'}
                btn.disabled=false;btn.classList.remove('loading');return;
            }

            /* Now complete as free booking */
            var freeData=new FormData(form);
            freeData.set('action','ptp_complete_free_checkout');
            freeData.append('nonce','<?php echo wp_create_nonce("ptp_free_checkout"); ?>');
            freeData.append('checkout_session',sessId);
            var freeRaw2=await fetch(ajax,{method:'POST',body:freeData});
            var freeTxt2=await freeRaw2.text();
            var freeRes;
            try{freeRes=JSON.parse(freeTxt2)}catch(pe){console.error('Free checkout non-JSON:',freeTxt2.substring(0,500));if(errEl){errEl.textContent='Booking failed — server error. Please try again.';errEl.style.display='block'}btn.disabled=false;btn.classList.remove('loading');return}
            if(freeRes.success){
                var rUrl='<?php echo esc_js($thank_you_url); ?>'+'&free=1';
                if(freeRes.data&&freeRes.data.booking_id)rUrl+='&booking='+freeRes.data.booking_id;
                window.location.href=rUrl;
            }else{
                if(errEl){errEl.textContent=freeRes.data&&freeRes.data.message?freeRes.data.message:'Could not complete free booking';errEl.style.display='block'}
                btn.disabled=false;btn.classList.remove('loading');
            }
        }catch(err){
            if(errEl){errEl.textContent='An error occurred. Please try again.';errEl.style.display='block'}
            btn.disabled=false;btn.classList.remove('loading');
        }
    };
})();
<?php endif; ?>
</script>

<?php get_footer(); ?>
