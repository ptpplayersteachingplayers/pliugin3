<?php
/**
 * PTP Checkout v157 - Professional Mobile-First Redesign
 * - Apple Pay / Google Pay prominent at top
 * - Progress stepper
 * - Collapsible sections
 * - Streamlined form
 * - v157: Fixed cart persistence with robust loading
 */
defined('ABSPATH') || exit;

global $wpdb;

// ── Handle abandoned cart recovery link click ──
if (!empty($_GET['recover'])) {
    $recover_id = sanitize_text_field($_GET['recover']);
    $recover_source = sanitize_text_field($_GET['recover_source'] ?? '');
    $ac_table = $wpdb->prefix . 'ptp_camp_abandoned_carts';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$ac_table}'") === $ac_table) {
        // Mark cart as recovered
        if (is_numeric($recover_id)) {
            $wpdb->update($ac_table,
                array('status' => 'recovered', 'updated_at' => current_time('mysql')),
                array('id' => intval($recover_id), 'status' => 'abandoned')
            );
        }
    }
    // Also check training abandoned carts table
    $at_table = $wpdb->prefix . 'ptp_abandoned_carts';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$at_table}'") === $at_table && is_numeric($recover_id)) {
        $wpdb->update($at_table, array('recovered' => 1), array('id' => intval($recover_id), 'recovered' => 0));
    }
}

// Config
$logo = get_option('ptp_logo_url', '');
if (empty($logo)) $logo = 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png';
$stripe_mode = get_option('ptp_stripe_test_mode', false) ? 'test' : 'live';

$stripe_pk = get_option('ptp_stripe_' . $stripe_mode . '_publishable', '');
$stripe_sk = get_option('ptp_stripe_' . $stripe_mode . '_secret', '');

// Fallback 1: ptp_settings array (legacy — matches ptp-cart.php)
if (!$stripe_pk || !$stripe_sk) {
    $settings = get_option('ptp_settings', array());
    $legacy_mode = ($settings['stripe_mode'] ?? 'test') === 'live' ? 'live' : 'test';
    if (!$stripe_pk) $stripe_pk = $settings['stripe_' . $legacy_mode . '_publishable_key'] ?? '';
    if (!$stripe_sk) $stripe_sk = $settings['stripe_' . $legacy_mode . '_secret_key'] ?? '';
}

// Fallback 2: generic key options
if (!$stripe_pk) $stripe_pk = get_option('ptp_stripe_publishable_key', '');
if (!$stripe_sk) $stripe_sk = get_option('ptp_stripe_secret_key', '');

// User
$user = wp_get_current_user();
$logged_in = is_user_logged_in();

// Saved data
$parent = null;
$players = array();
if ($logged_in && $wpdb) {
    $parents_table = $wpdb->prefix . 'ptp_parents';
    $players_table = $wpdb->prefix . 'ptp_players';
    
    $parents_exists = $wpdb->get_var("SHOW TABLES LIKE '{$parents_table}'") === $parents_table;
    $players_exists = $wpdb->get_var("SHOW TABLES LIKE '{$players_table}'") === $players_table;
    
    if ($parents_exists) {
        $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$parents_table} WHERE user_id = %d", $user->ID));
        if ($parent && $players_exists) {
            // v236: Deduplicate players — keep the record with the most data for each name
            $raw_players = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$players_table} WHERE parent_id = %d ORDER BY first_name, id DESC", $parent->id));
            $seen = array();
            $players = array();
            foreach ($raw_players as $p) {
                $key = strtolower(trim($p->first_name) . '|' . trim($p->last_name ?? ''));
                if (isset($seen[$key])) {
                    // Keep whichever has a DOB / more data
                    $existing = $seen[$key];
                    if (empty($existing->dob) && !empty($p->dob)) {
                        $seen[$key] = $p; // replace with the one that has DOB
                    }
                    continue;
                }
                $seen[$key] = $p;
            }
            $players = array_values($seen);
        }
    }
}

// Cart
$items = array();
$subtotal = 0;
$has_camps = false;
$multiweek_discount_pct = 0;
$multiweek_discount_amount = 0;
$early_bird_discount_amount = 0;

// Early bird settings - v154: $50 flat per camp (no longer percentage-based)
$early_bird_enabled = get_option('ptp_early_bird_enabled', false);
$early_bird_per_camp = 50;
$early_bird_end_date = get_option('ptp_early_bird_end_date', '2026-02-16');
$is_early_bird_active = $early_bird_enabled && (current_time('Y-m-d') <= $early_bird_end_date);

// ============================================
// v164: Handle ?camp= URL parameter (direct links to checkout)
// Auto-add camp to cart if not already present
// ============================================
$camp_param = isset($_GET['camp']) ? sanitize_text_field($_GET['camp']) : '';
if (!empty($camp_param) && function_exists('ptp_cart')) {
    // Look up camp by stripe_product_id
    $camp_product = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE stripe_product_id = %s AND active = 1",
        $camp_param
    ));
    
    if ($camp_product) {
        // Check if already in cart
        $already_in_cart = false;
        $existing_cart = ptp_cart()->get_cart();
        foreach ($existing_cart as $cart_item) {
            $item_stripe_product = $cart_item['metadata']['stripe_product'] ?? $cart_item['metadata']['stripe_product_id'] ?? '';
            if ($item_stripe_product === $camp_param) {
                $already_in_cart = true;
                break;
            }
        }
        
        if (!$already_in_cart) {
            // Add to cart
            $price = $camp_product->price_cents ? ($camp_product->price_cents / 100) : floatval($camp_product->price);
            
            // v177: Use add_to_cart with correct signature (not add_item which has different params)
            ptp_cart()->add_to_cart('camp', intval($camp_product->id), 1, $price, array(
                'name' => $camp_product->name,
                'camp_name' => $camp_product->name,
                'date' => $camp_product->camp_dates ?? '',
                'camp_dates' => $camp_product->camp_dates ?? '',
                'location' => $camp_product->camp_location ?? '',
                'camp_location' => $camp_product->camp_location ?? '',
                'time' => $camp_product->camp_time ?? '9AM - 3PM',
                'camp_time' => $camp_product->camp_time ?? '9AM - 3PM',
                'stripe_product' => $camp_product->stripe_product_id,
                'stripe_product_id' => $camp_product->stripe_product_id,
                'stripe_price' => $camp_product->stripe_price_id ?? '',
                'stripe_price_id' => $camp_product->stripe_price_id ?? '',
                'image_url' => $camp_product->image_url ?? '',
            ));
            ptp_cart()->save_cart(true);
            
            ptp_log('[PTP Checkout v177] Auto-added camp from URL param: ' . $camp_product->name . ' ($' . $price . ')');
        }
    } else {
        ptp_log('[PTP Checkout v164] Camp not found for stripe_product_id: ' . $camp_param);
    }
}

// ============================================
// v157: FIRST load from native cart (if items exist)
// This handles camps added via AJAX from product pages
// Force load the cart to ensure we have latest data
// ============================================
$from_native_cart = false;
$native_training_data = null; // Store training data from native cart

// Force cart to load from database
if (function_exists('ptp_cart')) {
    ptp_cart()->load_cart();
    
    // v225: Clear stale training items if trainer_id OR package differs from URL
    // Previously only checked trainer_id, so same-trainer package changes (single→pack5) kept the old price
    if (!empty($_GET['trainer_id']) && ptp_cart()->get_cart_contents_count() > 0) {
        $url_tid = intval($_GET['trainer_id']);
        $url_pkg = sanitize_text_field($_GET['package'] ?? '');
        $stale_training_keys = array();
        foreach (ptp_cart()->get_cart() as $ck => $ci) {
            if (($ci['item_type'] ?? '') === 'training') {
                $existing_tid = intval($ci['metadata']['trainer_id'] ?? $ci['item_id'] ?? 0);
                $existing_pkg = $ci['metadata']['package'] ?? 'single';
                // Remove if different trainer OR same trainer but different package/date/time
                if ($existing_tid !== $url_tid) {
                    $stale_training_keys[] = $ck;
                } elseif ($url_pkg && $existing_pkg !== $url_pkg) {
                    $stale_training_keys[] = $ck;
                    ptp_log('[PTP Checkout v225] Package changed: cart has "' . $existing_pkg . '" but URL has "' . $url_pkg . '" — clearing stale entry');
                }
            }
        }
        foreach ($stale_training_keys as $stk) {
            ptp_cart()->remove_cart_item($stk);
        }
        if (!empty($stale_training_keys)) {
            ptp_cart()->save_cart(true);
            ptp_log('[PTP Checkout v225] Cleared ' . count($stale_training_keys) . ' stale training items');
        }
    }
}

if (function_exists('ptp_cart') && ptp_cart()->get_cart_contents_count() > 0) {
    $native_items = ptp_cart()->get_cart();
    $camp_count_from_cart = 0;
    
    ptp_log('[PTP Checkout v157] Cart loaded, item count: ' . ptp_cart()->get_cart_contents_count());

        foreach ($native_items as $cart_key => $cart_item) {
        $item_type = $cart_item['item_type'] ?? 'product';
        $metadata = $cart_item['metadata'] ?? array();
        // v16: line_total comes from price * quantity in native cart
        $line_total = floatval($cart_item['line_total'] ?? ($cart_item['price'] ?? 0));
        
        if ($item_type === 'camp') {
            $has_camps = true;
            $from_native_cart = true;
            $camp_count_from_cart++;
            
            // v16: Support both key formats from different sources
            $camp_date = $metadata['date'] ?? $metadata['camp_date'] ?? $metadata['camp_dates'] ?? '';
            $camp_location = $metadata['location'] ?? $metadata['camp_location'] ?? '';
            $camp_time = $metadata['time'] ?? $metadata['camp_time'] ?? '9AM - 3PM';
            $stripe_product = $metadata['stripe_product'] ?? $metadata['stripe_product_id'] ?? '';
            $stripe_price = $metadata['stripe_price'] ?? $metadata['stripe_price_id'] ?? '';
            $camp_name = $metadata['name'] ?? $metadata['camp_name'] ?? 'Camp Registration';
            
            // v168.1: Track if early bird was already applied at cart-add time
            $early_bird_already_applied = !empty($metadata['early_bird']) || !empty($metadata['early_bird_applied']);
            
            $items[] = array(
                'id' => $cart_item['item_id'],
                'cart_key' => $cart_key,
                'name' => $camp_name,
                'type' => 'camp',
                'price' => $line_total,
                'date' => $camp_date,
                'location' => $camp_location,
                'time' => $camp_time,
                'stripe_product' => $stripe_product,
                'stripe_price' => $stripe_price,
                'early_bird_applied' => $early_bird_already_applied,
            );
            $subtotal += $line_total;
        } elseif ($item_type === 'training') {
            $from_native_cart = true;
            
            $t_id = $metadata['trainer_id'] ?? $cart_item['item_id'];
            
            // ALWAYS get trainer data from ptp_trainers table
            $t_name = '';
            $t_photo = '';
            if ($t_id) {
                $trainers_table = $wpdb->prefix . 'ptp_trainers';
                $trainer_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT display_name, photo_url FROM {$trainers_table} WHERE id = %d",
                    $t_id
                ));
                if ($trainer_row) {
                    $t_name = $trainer_row->display_name;
                    $t_photo = $trainer_row->photo_url;
                }
            }
            
            // Fallback to metadata only if DB lookup failed
            if (empty($t_name)) {
                $t_name = $metadata['trainer_name'] ?? 'Trainer';
            }
            if (empty($t_photo)) {
                $t_photo = $metadata['trainer_photo'] ?? '';
            }
            
            // Store training data for hidden fields
            $native_training_data = array(
                'trainer_id' => $t_id,
                'trainer_name' => $t_name,
                'trainer_photo' => $t_photo,
                'package' => $metadata['package'] ?? 'single',
                'package_name' => $metadata['package_name'] ?? 'Single Session',
                'sessions' => $metadata['sessions'] ?? 1,
                'date' => $metadata['date'] ?? $metadata['session_date'] ?? '',
                'time' => $metadata['time'] ?? $metadata['session_time'] ?? '',
                'location' => $metadata['location'] ?? '',
                'location_address' => $metadata['location_address'] ?? '',
                'location_lat' => $metadata['location_lat'] ?? '',
                'location_lng' => $metadata['location_lng'] ?? '',
                'group_size' => $metadata['group_size'] ?? 1,
                'price' => $line_total,
            );
            
            $items[] = array(
                'id' => $cart_item['item_id'],
                'cart_key' => $cart_key,
                'name' => $t_name . ' - ' . ($metadata['package_name'] ?? 'Training'),
                'type' => 'training',
                'price' => $line_total,
                'date' => $metadata['date'] ?? $metadata['session_date'] ?? '',
                'time' => $metadata['time'] ?? $metadata['session_time'] ?? '',
                'loc' => $metadata['location'] ?? '',
                'img' => $t_photo,
                'trainer_id' => $t_id,
                'trainer_name' => $t_name,
            );
            $subtotal += $line_total;
        } elseif ($item_type === 'addon') {
            $from_native_cart = true;
            
            $items[] = array(
                'id' => 0,
                'cart_key' => $cart_key,
                'name' => $metadata['name'] ?? 'Add-on',
                'type' => 'addon',
                'price' => $line_total,
            );
            $subtotal += $line_total;
        }
    }
    
    // Calculate multi-week discount for native cart camps
    if ($camp_count_from_cart >= 3) {
        $multiweek_discount_pct = 20;
    } elseif ($camp_count_from_cart >= 2) {
        $multiweek_discount_pct = 10;
    }
    
    if ($multiweek_discount_pct > 0 && $subtotal > 0) {
        // Calculate discount on camp items only
        $camp_subtotal = 0;
        foreach ($items as $item) {
            if ($item['type'] === 'camp') {
                $camp_subtotal += $item['price'];
            }
        }
        $multiweek_discount_amount = round($camp_subtotal * $multiweek_discount_pct / 100, 2);
    }
}

// ============================================
// FALLBACK: Load camps from URL if not from native cart
// ============================================
$camp_ids_from_url = isset($_GET['camps']) ? array_map('intval', explode(',', $_GET['camps'])) : array();
$camp_ids_from_url = array_filter($camp_ids_from_url);

if (!$from_native_cart && !empty($camp_ids_from_url)) {
    $has_camps = true;
    $camp_count = count($camp_ids_from_url);
    
    // Multi-week discount
    if ($camp_count >= 3) {
        $multiweek_discount_pct = 20;
    } elseif ($camp_count >= 2) {
        $multiweek_discount_pct = 10;
    }
    
    foreach ($camp_ids_from_url as $camp_id) {
        $camp_post = get_post($camp_id);
        if (!$camp_post) continue;
        
        $price = (float) get_post_meta($camp_id, '_camp_price', true);
        if (!$price) $price = (float) get_post_meta($camp_id, '_price', true);
        if (!$price) $price = 525;
        
        $sale_price = (float) get_post_meta($camp_id, '_camp_sale_price', true);
        if (!$sale_price) $sale_price = (float) get_post_meta($camp_id, '_sale_price', true);
        
        $final_price = ($sale_price > 0) ? $sale_price : $price;
        if ($final_price <= 0) $final_price = 420;
        
        $items[] = array(
            'id' => $camp_id,
            'cart_key' => 'camp_url_' . $camp_id,
            'name' => $camp_post->post_title,
            'type' => 'camp',
            'price' => $final_price,
            'date' => get_post_meta($camp_id, '_camp_date', true) ?: '',
            'location' => get_post_meta($camp_id, '_camp_location', true) ?: '',
            'time' => get_post_meta($camp_id, '_camp_time', true) ?: '9AM - 3PM',
        );
        $subtotal += $final_price;
        
        // v228: Persist URL-param camps to native cart so AJAX removal works
        if (function_exists('ptp_cart')) {
            $already_in = false;
            foreach (ptp_cart()->get_cart() as $_ck => $_ci) {
                if (($_ci['item_type'] ?? '') === 'camp' && intval($_ci['item_id'] ?? 0) === $camp_id) {
                    $already_in = true;
                    break;
                }
            }
            if (!$already_in) {
                ptp_cart()->add_to_cart('camp', $camp_id, 1, $final_price, array(
                    'name'          => $camp_post->post_title,
                    'camp_name'     => $camp_post->post_title,
                    'camp_dates'    => get_post_meta($camp_id, '_camp_date', true) ?: '',
                    'camp_location' => get_post_meta($camp_id, '_camp_location', true) ?: '',
                    'camp_time'     => get_post_meta($camp_id, '_camp_time', true) ?: '9AM - 3PM',
                ));
                ptp_cart()->save_cart(true);
            }
        }
    }
    
    if ($multiweek_discount_pct > 0 && $subtotal > 0) {
        $multiweek_discount_amount = round($subtotal * $multiweek_discount_pct / 100, 2);
    }
}

// ============================================
// TRAINING SESSIONS - v151.1 restored from v141
// Handles: ?trainer_id=X&package=Y&date=Z&time=T&location=L&group_size=N
// v16: Skip if already loaded training from native cart
// ============================================
$has_training_from_native = false;
foreach ($items as $item) {
    if ($item['type'] === 'training') {
        $has_training_from_native = true;
        break;
    }
}

$has_training = $has_training_from_native;
$trainer = null;
$trainer_id = intval($_GET['trainer_id'] ?? 0);
$session_date = sanitize_text_field($_GET['date'] ?? '');
$session_time = sanitize_text_field($_GET['time'] ?? '');
$session_loc = sanitize_text_field($_GET['location'] ?? '');
$session_loc_address = sanitize_text_field($_GET['location_address'] ?? '');
$session_loc_lat = sanitize_text_field($_GET['location_lat'] ?? '');
$session_loc_lng = sanitize_text_field($_GET['location_lng'] ?? '');
$pkg_key = sanitize_text_field($_GET['package'] ?? 'single');
$group_size = intval($_GET['group_size'] ?? $_GET['spots'] ?? 1);
$group_size = max(1, min(10, $group_size)); // Clamp between 1-10 for group sessions

// v236: If training already loaded from native cart but URL has a specific location,
// patch the items array and native_training_data so the order summary shows the real location
if ($has_training_from_native && !empty($session_loc)) {
    foreach ($items as &$_item) {
        if ($_item['type'] === 'training') {
            $stale = $_item['loc'] ?? '';
            if ($stale !== $session_loc) {
                $_item['loc'] = $session_loc;
                ptp_log('[PTP Checkout v236] Patched stale item loc "' . $stale . '" → "' . $session_loc . '"');
            }
        }
    }
    unset($_item);
    if (isset($native_training_data) && is_array($native_training_data)) {
        $native_training_data['location'] = $session_loc;
        $native_training_data['location_address'] = $session_loc_address;
        $native_training_data['location_lat'] = $session_loc_lat;
        $native_training_data['location_lng'] = $session_loc_lng;
    }
    // v236: Persist the corrected location back to native cart so it sticks on refresh
    if (function_exists('ptp_cart') && $trainer_id) {
        foreach (ptp_cart()->get_cart() as $_ck => $_ci) {
            if (($_ci['item_type'] ?? '') === 'training' && intval($_ci['metadata']['trainer_id'] ?? $_ci['item_id'] ?? 0) === $trainer_id) {
                $cart_loc = $_ci['metadata']['location'] ?? '';
                if ($cart_loc !== $session_loc) {
                    ptp_cart()->update_item_metadata($_ck, 'location', $session_loc);
                    ptp_cart()->update_item_metadata($_ck, 'location_address', $session_loc_address);
                    ptp_cart()->update_item_metadata($_ck, 'location_lat', $session_loc_lat);
                    ptp_cart()->update_item_metadata($_ck, 'location_lng', $session_loc_lng);
                    ptp_cart()->save_cart(true);
                    ptp_log('[PTP Checkout v236] Persisted corrected location to native cart: "' . $session_loc . '"');
                }
                break;
            }
        }
    }
}

// Only load training from URL if not already from native cart
$group_session = null;
$group_session_id = 0;
if (!$has_training_from_native && $trainer_id) {

// v131: Load group session if specified
$group_session_id = intval($_GET['group_session_id'] ?? 0);
if ($group_session_id && $wpdb) {
    $group_table = $wpdb->prefix . 'ptp_group_sessions';
    $group_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$group_table}'") === $group_table;
    if ($group_table_exists) {
        $group_session = $wpdb->get_row($wpdb->prepare(
            "SELECT g.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.hourly_rate, t.playing_level, t.slug as trainer_slug
             FROM {$group_table} g
             JOIN {$wpdb->prefix}ptp_trainers t ON g.trainer_id = t.id
             WHERE g.id = %d AND g.status IN ('open', 'confirmed')",
            $group_session_id
        ));
        if ($group_session) {
            $trainer_id = $group_session->trainer_id;
            $session_date = $group_session->session_date;
            $session_time = date('H:i', strtotime($group_session->start_time));
            $session_loc = $group_session->location;
            $available_spots = $group_session->max_players - $group_session->current_players;
            if ($group_size > $available_spots) {
                $group_size = max(1, $available_spots);
            }
        }
    }
}

// Group size multipliers (for private group training pricing)
$group_mult = class_exists('PTP_Packages') ? PTP_Packages::group_multiplier($group_size) : (array(1 => 1, 2 => 1.6, 3 => 2, 4 => 2.4, 5 => 2.8, 6 => 3.2, 7 => 3.5, 8 => 3.8, 9 => 4.0, 10 => 4.2)[$group_size] ?? (1 + ($group_size - 1) * 0.4));
$group_labels = array(1 => 'Solo', 2 => 'Duo', 3 => 'Trio', 4 => 'Quad', 5 => '5 Players', 6 => '6 Players', 7 => '7 Players', 8 => '8 Players', 9 => '9 Players', 10 => '10 Players');

// Determine if this is a multi-player checkout (group session)
$is_multi_player = $group_size > 1 || $group_session;

// Load trainer from database
if ($trainer_id && $wpdb) {
    $trainers_table = $wpdb->prefix . 'ptp_trainers';
    $trainers_exists = $wpdb->get_var("SHOW TABLES LIKE '{$trainers_table}'") === $trainers_table;
    if ($trainers_exists) {
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$trainers_table} WHERE id = %d AND status = 'active'", $trainer_id));
    }
}

// Package definitions — sourced from PTP_Packages (single source of truth)
$pkgs = array();
if (class_exists('PTP_Packages')) {
    $pkg_key = PTP_Packages::resolve_key($pkg_key);
    foreach (PTP_Packages::all() as $k => $def) {
        $pkgs[$k] = array('n' => $def['name'], 's' => $def['sessions'], 'd' => $def['discount']);
    }
    // Backwards compat aliases
    foreach (PTP_Packages::LEGACY_MAP as $legacy => $canonical) {
        if (!isset($pkgs[$legacy]) && isset($pkgs[$canonical])) {
            $pkgs[$legacy] = $pkgs[$canonical];
        }
    }
} else {
    // Fallback if class not loaded
    $pkgs = array(
        'single' => array('n' => 'Single Session', 's' => 1, 'd' => 0),
        'pack5'  => array('n' => '5-Pack', 's' => 5, 'd' => 15),
        'pack10' => array('n' => '10-Pack', 's' => 10, 'd' => 20),
        'pack3'  => array('n' => '3-Pack', 's' => 3, 'd' => 10),
        '5pack'  => array('n' => '5-Pack', 's' => 5, 'd' => 15),
        '10pack' => array('n' => '10-Pack', 's' => 10, 'd' => 20),
    );
}

// Build training item if trainer found
$training_price = 0;
$sel = null;
if ($trainer) {
    $has_training = true;
    // v236: Enforce minimum $60 rate — $0 rate breaks PI creation ($cents < 50 threshold)
    $rate = max(60, intval($trainer->hourly_rate ?: 60));
    
    // v131: If this is a trainer group session, use price_per_player pricing
    if ($group_session) {
        $price_per_player = floatval($group_session->price_per_player);
        $training_price = intval($price_per_player * $group_size);
        $group_rate = $training_price;
        
        // Update packages for group session (just single option)
        $pkgs = array(
            'single' => array('n' => $group_session->title ?: 'Group Session', 's' => 1, 'd' => 0, 'p' => $training_price, 'v' => 0),
        );
        $pkg_key = 'single';
        $sel = $pkgs['single'];
    } else {
        // Standard private training pricing with group multiplier
        $group_rate = intval($rate * $group_mult);
        
        foreach ($pkgs as $k => &$p) {
            $p['p'] = $k === 'single' ? $group_rate : intval($group_rate * $p['s'] * (1 - $p['d']/100));
            $p['v'] = $k === 'single' ? 0 : ($group_rate * $p['s']) - $p['p'];
        }
        unset($p);
        
        $sel = $pkgs[$pkg_key] ?? $pkgs['single'];
        $training_price = $sel['p'];
    }
    
    // Format time for display
    $time_display = '';
    if ($session_time) {
        // v236: Parse full time including minutes (was hardcoding :00)
        $time_parts = explode(':', $session_time);
        $h = intval($time_parts[0]);
        $m = intval($time_parts[1] ?? 0);
        $time_display = ($h > 12 ? $h - 12 : ($h ?: 12)) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ' ' . ($h >= 12 ? 'PM' : 'AM');
    }
    
    // Build name with group size
    $group_label = $group_size > 1 ? ' (' . $group_labels[$group_size] . ' - ' . $group_size . ' players)' : '';
    $item_name = $group_session ? $group_session->title . $group_label : $trainer->display_name . ' - ' . $sel['n'] . $group_label;
    
    $items[] = array(
        'id' => $trainer->id,
        'cart_key' => 'training_url_' . $trainer_id,
        'name' => $item_name,
        'type' => $group_session ? 'group_session' : 'training',
        'price' => $training_price,
        'qty' => $sel['s'],
        'img' => $trainer->photo_url,
        'date' => $session_date,
        'time' => $time_display,
        'loc' => $session_loc,
        'pkg' => $pkg_key,
        'rate' => $rate,
        'group_size' => $group_size,
        'group_session_id' => $group_session_id,
        'price_per_player' => $group_session ? floatval($group_session->price_per_player) : ($group_size > 1 ? intval($group_rate / $group_size) : $rate),
        'trainer_id' => $trainer_id,
        'trainer_name' => $trainer->display_name,
        'trainer_photo' => $trainer->photo_url,
    );
    $subtotal += $training_price;

    // v225: Persist URL-based training to native cart so /ptp-cart/ can find it
    // Check if this exact training (same trainer + package) is already in native cart
    if (function_exists('ptp_cart')) {
        $already_persisted = false;
        $stale_pkg_key = null;
        $matching_cart_key = null;
        foreach (ptp_cart()->get_cart() as $ck => $ci) {
            if (($ci['item_type'] ?? '') === 'training' && intval($ci['metadata']['trainer_id'] ?? $ci['item_id'] ?? 0) === $trainer_id) {
                $existing_pkg = $ci['metadata']['package'] ?? 'single';
                if ($existing_pkg === $pkg_key) {
                    $already_persisted = true;
                    $matching_cart_key = $ck;
                } else {
                    // Same trainer, different package — remove stale entry so we can add the new one
                    $stale_pkg_key = $ck;
                }
                break;
            }
        }
        // Remove stale package entry before adding new one
        if ($stale_pkg_key) {
            ptp_cart()->remove_cart_item($stale_pkg_key);
            ptp_log('[PTP Checkout v225] Removed stale training package from cart: ' . $stale_pkg_key);
        }
        // v236: If item exists but URL has a specific location, force-update the cart metadata
        if ($already_persisted && $matching_cart_key && !empty($session_loc)) {
            $cart_ref = ptp_cart()->get_cart();
            $existing_loc = $cart_ref[$matching_cart_key]['metadata']['location'] ?? '';
            if ($existing_loc !== $session_loc) {
                ptp_cart()->update_item_metadata($matching_cart_key, 'location', $session_loc);
                ptp_cart()->update_item_metadata($matching_cart_key, 'location_address', $session_loc_address);
                ptp_cart()->update_item_metadata($matching_cart_key, 'location_lat', $session_loc_lat);
                ptp_cart()->update_item_metadata($matching_cart_key, 'location_lng', $session_loc_lng);
                ptp_cart()->save_cart(true);
                ptp_log('[PTP Checkout v236] Updated stale cart location from "' . $existing_loc . '" to "' . $session_loc . '"');
            }
        }
        if (!$already_persisted) {
            ptp_cart()->add_to_cart('training', $trainer_id, 1, $training_price, array(
                'trainer_id'        => $trainer_id,
                'trainer_name'      => $trainer->display_name,
                'trainer_photo'     => $trainer->photo_url ?? '',
                'package'           => $pkg_key,
                'package_name'      => $sel['n'],
                'name'              => $sel['n'],
                'sessions'          => $sel['s'],
                'session_date'      => $session_date,
                'date'              => $session_date,
                'session_time'      => $session_time,
                'time'              => $session_time,
                'location'          => $session_loc,
                'location_address'  => $session_loc_address,
                'location_lat'      => $session_loc_lat,
                'location_lng'      => $session_loc_lng,
                'group_size'        => $group_size,
                'group_session_id'  => $group_session_id,
            ));
            ptp_cart()->save_cart(true);
            ptp_log('[PTP Checkout v225] Persisted URL training to native cart: trainer=' . $trainer_id . ' pkg=' . $pkg_key);
        }
    }
}

} // End of !$has_training_from_native check

// v216: Package selector flag — show for standard private training from URL params (not group sessions, not native cart)
$show_package_selector = ($has_training && $trainer && !$group_session && !$has_training_from_native);

// ============================================
// v16: BUNDLE DISCOUNT - camps + training together
// ============================================
$bundle_discount_amount = 0;
$has_bundle = $has_camps && ($has_training || $has_training_from_native);
if ($has_bundle) {
    // 5% off when both camps and training are in cart
    $bundle_discount_amount = round($subtotal * 0.05, 2);
}

// ============================================
// v16.1: EARLY BIRD DISCOUNT - applies to camps only
// v168.1: Skip camps that already have early bird applied at cart-add time
// ============================================
if ($is_early_bird_active && $has_camps) {
    // Early bird: $50 off per camp registration
    $early_bird_per_camp = 50;
    $camp_count_for_discount = 0;
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'camp') {
            // v168.1: Only count camps that DON'T already have early bird applied
            if (empty($item['early_bird_applied'])) {
                $camp_count_for_discount++;
            }
        }
    }
    if ($camp_count_for_discount > 0) {
        $early_bird_discount_amount = $camp_count_for_discount * $early_bird_per_camp;
    }
}

// Calculate totals
$total_discount = $multiweek_discount_amount + $bundle_discount_amount + $early_bird_discount_amount;
$subtotal_after_discount = $subtotal - $total_discount;
$processing_fee = round(($subtotal_after_discount * 0.03) + 0.30, 2);
$total = $subtotal_after_discount + $processing_fee;
$empty = empty($items);
$cents = intval(round($total * 100));

// Create PaymentIntent
$client_secret = '';
$checkout_session_id = wp_generate_uuid4();
$pi_error = '';

if (!$empty && $cents >= 50 && !empty($stripe_sk)) {
    $item_names = array_column($items, 'name');
    $stripe_description = implode(', ', array_slice($item_names, 0, 3));
    if (count($items) > 3) $stripe_description .= ' +' . (count($items) - 3) . ' more';
    
    // Build metadata
    $pi_metadata = array(
        'metadata[checkout_session]' => $checkout_session_id,
        'metadata[source]' => 'ptp_checkout_v152',
        'metadata[subtotal]' => number_format($subtotal, 2),
        'metadata[processing_fee]' => number_format($processing_fee, 2),
        'metadata[total]' => number_format($total, 2),
        'metadata[item_count]' => count($items),
    );
    
    // Add training metadata if applicable
    if ($has_training && $trainer) {
        $pi_metadata['metadata[trainer_id]'] = $trainer_id;
        $pi_metadata['metadata[trainer_name]'] = $trainer->display_name;
        $pi_metadata['metadata[package]'] = $pkg_key;
        $pi_metadata['metadata[session_date]'] = $session_date;
        $pi_metadata['metadata[session_time]'] = $session_time;
        $pi_metadata['metadata[session_location]'] = $session_loc;
        if ($session_loc_address) $pi_metadata['metadata[session_location_address]'] = $session_loc_address;
        $pi_metadata['metadata[group_size]'] = $group_size;
        if ($group_session_id) {
            $pi_metadata['metadata[group_session_id]'] = $group_session_id;
        }
    } elseif ($has_training_from_native && $native_training_data) {
        // Training from native cart
        $pi_metadata['metadata[trainer_id]'] = $native_training_data['trainer_id'];
        $pi_metadata['metadata[trainer_name]'] = $native_training_data['trainer_name'];
        $pi_metadata['metadata[package]'] = $native_training_data['package'];
        $pi_metadata['metadata[session_date]'] = $native_training_data['date'];
        $pi_metadata['metadata[session_time]'] = $native_training_data['time'];
        $pi_metadata['metadata[session_location]'] = $native_training_data['location'];
        if (!empty($native_training_data['location_address'])) $pi_metadata['metadata[session_location_address]'] = $native_training_data['location_address'];
        $pi_metadata['metadata[group_size]'] = $native_training_data['group_size'];
        $pi_metadata['metadata[training_sessions]'] = $native_training_data['sessions'];
    }
    
    // Add camp metadata if applicable - from URL or native cart
    $all_camp_ids = array();
    $all_stripe_products = array();
    $all_stripe_prices = array();
    
    // Get from URL params
    if (!empty($camp_ids_from_url)) {
        $all_camp_ids = array_merge($all_camp_ids, $camp_ids_from_url);
    }
    
    // Get from items (which came from native cart)
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'camp') {
            if (!empty($item['id']) && !in_array($item['id'], $all_camp_ids)) {
                $all_camp_ids[] = $item['id'];
            }
            if (!empty($item['stripe_product'])) {
                $all_stripe_products[] = $item['stripe_product'];
            }
            if (!empty($item['stripe_price'])) {
                $all_stripe_prices[] = $item['stripe_price'];
            }
        }
    }
    
    if ($has_camps && !empty($all_camp_ids)) {
        $pi_metadata['metadata[camp_ids]'] = implode(',', $all_camp_ids);
        $pi_metadata['metadata[camp_count]'] = count($all_camp_ids);
        if (!empty($all_stripe_products)) {
            $pi_metadata['metadata[stripe_products]'] = implode(',', array_filter($all_stripe_products));
        }
        if (!empty($all_stripe_prices)) {
            $pi_metadata['metadata[stripe_prices]'] = implode(',', array_filter($all_stripe_prices));
        }
        // Add discount info
        if ($multiweek_discount_pct > 0) {
            $pi_metadata['metadata[multiweek_discount_pct]'] = $multiweek_discount_pct;
            $pi_metadata['metadata[multiweek_discount_amount]'] = $multiweek_discount_amount;
        }
        if ($bundle_discount_amount > 0) {
            $pi_metadata['metadata[bundle_discount_amount]'] = $bundle_discount_amount;
        }
        if ($early_bird_discount_amount > 0) {
            $pi_metadata['metadata[early_bird_discount]'] = $early_bird_discount_amount;
            $pi_metadata['metadata[early_bird_per_camp]'] = 50;
        }
        
        // v175: Coupon and referral metadata will be added via JS when codes are applied
        // The actual coupon/referral data is passed as hidden fields and updated via AJAX
    }
    
    $pi_response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $stripe_sk,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'body' => array_merge(array(
            'amount' => $cents,
            'currency' => 'usd',
            'payment_method_types[]' => 'card',
            'description' => $stripe_description,
        ), $pi_metadata),
        'timeout' => 30,
    ));
    
    if (!is_wp_error($pi_response)) {
        $pi_body = json_decode(wp_remote_retrieve_body($pi_response), true);
        if (!empty($pi_body['client_secret'])) {
            $client_secret = $pi_body['client_secret'];
            
            // Attach Stripe Customer to PI
            $pi_id = $pi_body['id'] ?? '';
            $cust_email = $logged_in ? $user->user_email : '';
            $cust_name = ($parent ? trim($parent->first_name . ' ' . $parent->last_name) : '') ?: ($logged_in ? $user->display_name : '');
            $cust_phone = $parent ? ($parent->phone ?? '') : '';
            
            if ($cust_email && $pi_id && class_exists('PTP_Stripe')) {
                $stripe_cust_id = PTP_Stripe::find_or_create_customer_by_email($cust_email, $cust_name, $cust_phone);
                if ($stripe_cust_id) {
                    wp_remote_post('https://api.stripe.com/v1/payment_intents/' . $pi_id, array(
                        'headers' => array('Authorization' => 'Bearer ' . $stripe_sk, 'Content-Type' => 'application/x-www-form-urlencoded'),
                        'body' => array(
                            'customer' => $stripe_cust_id,
                            'receipt_email' => $cust_email,
                            'metadata[customer_email]' => $cust_email,
                            'metadata[customer_name]' => $cust_name,
                            'metadata[customer_phone]' => $cust_phone,
                        ),
                        'timeout' => 10,
                    ));
                }
            }
        } else {
            $pi_error = $pi_body['error']['message'] ?? 'Payment initialization failed';
        }
    } else {
        $pi_error = 'Network error - please refresh';
    }
}

// Camp price for sibling calculation - use full subtotal for all camps
$camp_price = !empty($items) ? $items[0]['price'] : 420;
$sibling_subtotal = $subtotal; // Total for all camps in cart
$camp_count = count($items);
$sibling_price_after_discount = $sibling_subtotal * 0.9; // 10% off
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover">
<meta name="theme-color" content="#0A0A0A">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Secure Checkout - PTP Soccer</title>
<meta name="robots" content="noindex, nofollow">
<script>
// v240: Comprehensive booking data recovery for mobile
// Reads: sessionStorage → cookie → URL params
// Handles: Safari private browsing, URL truncation, back-button navigation, complete param loss
(function(){
    try {
        var p = new URLSearchParams(window.location.search);
        var tid = p.get('trainer_id');
        var pkg = p.get('package');
        var loc = p.get('location');
        var dt = p.get('date');
        var tm = p.get('time');
        
        // Try to load saved booking data from sessionStorage or cookie
        var saved = null;
        try {
            var raw = sessionStorage.getItem('ptp_booking');
            if (raw) saved = JSON.parse(raw);
        } catch(e) {}
        
        // v240: Cookie fallback (Safari private browsing blocks sessionStorage)
        if (!saved) {
            try {
                var cookies = document.cookie.split(';');
                for (var i = 0; i < cookies.length; i++) {
                    var c = cookies[i].trim();
                    if (c.indexOf('ptp_booking=') === 0) {
                        saved = JSON.parse(decodeURIComponent(c.substring(12)));
                        break;
                    }
                }
            } catch(e2) {}
        }
        
        if (!saved) return; // No backup data at all — nothing to recover
        
        // v240: Recover even when trainer_id is missing from URL
        // (e.g. user typed /ptp-checkout/ directly after booking, or URL was completely stripped)
        var savedTid = saved.trainer_id ? String(saved.trainer_id) : '';
        
        // If URL has a trainer_id, it must match saved data
        if (tid && savedTid && tid !== savedTid) return;
        
        // Determine what's missing
        var hasTid = !!tid;
        var hasAllParams = hasTid && pkg && loc && dt && tm;
        var pkgOverridden = (pkg === 'single' && saved.package && saved.package !== 'single');
        
        // Build the set of params that need filling
        var needsRedirect = false;
        var merged = {};
        var keys = ['trainer_id','package','date','time','location','location_address','location_lat','location_lng','group_size','free_code'];
        
        keys.forEach(function(k) {
            var urlVal = p.get(k) || '';
            var savedVal = saved[k] || '';
            // URL takes priority, fill gaps from saved
            merged[k] = urlVal || savedVal;
            // Track if we're adding anything the URL didn't have
            if (!urlVal && savedVal) needsRedirect = true;
        });
        
        // Prefer saved package if URL defaulted to 'single' but saved has a real selection
        if (pkgOverridden) {
            merged.package = saved.package;
            needsRedirect = true;
        }
        
        // Also preserve any non-booking URL params (e.g. camps=, recover=, utm_*)
        p.forEach(function(val, key) {
            if (keys.indexOf(key) === -1) {
                merged[key] = val;
            }
        });
        
        if (needsRedirect && merged.trainer_id) {
            // v240: Show loading overlay during redirect so user doesn't see empty cart flash
            var overlay = document.createElement('div');
            overlay.id = 'ptp-recovery-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;font-family:Inter,-apple-system,sans-serif';
            overlay.innerHTML = '<div style="width:32px;height:32px;border:3px solid #e5e7eb;border-top-color:#FCB900;border-radius:50%;animation:ptpSpin .6s linear infinite"></div>'
                + '<div style="font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#666">Loading your booking...</div>'
                + '<style>@keyframes ptpSpin{to{transform:rotate(360deg)}}</style>';
            document.documentElement.appendChild(overlay);
            
            // Strip empty values from merged params
            var cleanParams = new URLSearchParams();
            for (var k in merged) {
                if (merged[k] !== '' && merged[k] !== null && merged[k] !== undefined) {
                    cleanParams.set(k, merged[k]);
                }
            }
            window.location.replace(window.location.pathname + '?' + cleanParams.toString());
            return;
        }
        
        // v240: DON'T clear sessionStorage here — only clear after successful payment
        // (moved to confirmPayment return_url handler)
        // This prevents data loss on back-button navigation or page refresh
        
    } catch(e) { console.warn('PTP booking recovery:', e); }
})();
</script>
<link rel="preconnect" href="https://js.stripe.com">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-masterclass.css">
<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-tokens.css">
<link rel="stylesheet" href="<?php echo PTP_PLUGIN_URL; ?>assets/css/ptp-checkout-inline.css">
</head>
<body>

<?php if ($empty): ?>
<div class="checkout-page">
    <div class="checkout-header">
        <a href="<?php echo home_url(); ?>"><img src="<?php echo esc_url($logo); ?>" class="checkout-logo" alt="PTP"></a>
        <div class="checkout-secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Secure</div>
    </div>
    <h1 style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0">Secure Checkout — PTP Soccer</h1>
    <div class="checkout-main">
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
            <h2>Your Cart is Empty</h2>
            <p>Browse our summer camps or find a private trainer to get started.</p>
            <div style="display: flex; gap: 12px; flex-wrap: wrap; justify-content: center;">
                <a href="<?php echo home_url('/ptp-find-a-camp/'); ?>">Browse Camps</a>
                <a href="<?php echo home_url('/find-trainers/'); ?>" style="background: transparent; border: 2px solid var(--gold); color: var(--gold);">Find Trainers</a>
            </div>
        </div>
    </div>
</div>
<?php else: ?>

<div class="checkout-page">
    <!-- Mobile Header -->
    <div class="checkout-header">
        <a href="<?php echo home_url('/ptp-cart/'); ?>" class="header-back-link">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        </a>
        <a href="<?php echo home_url(); ?>"><img src="<?php echo esc_url($logo); ?>" class="checkout-logo" alt="PTP"></a>
        <div class="checkout-secure"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Secure</div>
    </div>
    
    <!-- Main Form Area -->
    <div class="checkout-main">

        <!-- v241: MOBILE ORDER SUMMARY — always visible on mobile, inline delete buttons -->
        <div id="ptpMobileOrder" style="display:none;margin-bottom:16px">
            <div style="font-family:Oswald,sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#111;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between">
                <span>Your Order (<?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?>)</span>
                <span style="font-family:Oswald,sans-serif;font-size:18px;color:#111">$<?php echo number_format($total, 2); ?></span>
            </div>
            <?php foreach ($items as $item):
                $item_image = '';
                if ($item['type'] === 'camp') {
                    $thumb_id = get_post_thumbnail_id($item['id']);
                    if ($thumb_id) $item_image = wp_get_attachment_image_url($thumb_id, 'thumbnail');
                } elseif ($item['type'] === 'training' || $item['type'] === 'group_session') {
                    $item_image = !empty($item['img']) ? $item['img'] : (!empty($item['trainer_photo']) ? $item['trainer_photo'] : '');
                }
                if (!$item_image) $item_image = 'https://ptpsummercamps.com/wp-content/uploads/2026/01/high-five.jpg';
                $m_meta = array();
                if (!empty($item['date'])) { $ds = strtotime($item['date']); $m_meta[] = $ds ? date('D, M j', $ds) : $item['date']; }
                if (!empty($item['time'])) { $ts = strtotime($item['time']); $m_meta[] = $ts ? date('g:i A', $ts) : $item['time']; }
                if (!empty($item['loc']) || !empty($item['location'])) $m_meta[] = !empty($item['loc']) ? $item['loc'] : $item['location'];
                $type_label = $item['type'] === 'group_session' ? 'Group Session' : ($item['type'] === 'training' ? 'Training' : ucfirst($item['type']));
            ?>
            <div id="ptp-mo-<?php echo esc_attr($item['cart_key'] ?? ''); ?>" style="display:flex;gap:12px;padding:12px;background:#fafafa;border:2px solid #e4e4e7;border-radius:4px;margin-bottom:8px;position:relative">
                <div style="width:50px;height:50px;border-radius:4px;background-image:url('<?php echo esc_url($item_image); ?>');background-size:cover;background-position:center;flex-shrink:0"></div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:10px;color:#FCB900;font-weight:700;text-transform:uppercase;letter-spacing:.5px"><?php echo esc_html(strtoupper($type_label)); ?></div>
                    <div style="font-size:13px;font-weight:600;color:#111;margin:2px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html($item['name']); ?></div>
                    <?php if ($m_meta): ?><div style="font-size:11px;color:#a1a1aa;line-height:1.3"><?php echo esc_html(implode(' · ', $m_meta)); ?></div><?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0">
                    <div style="font-size:14px;font-weight:700;color:#111">$<?php echo number_format($item['price'], 2); ?></div>
                    <?php if (!empty($item['cart_key'])): ?>
                    <button type="button" onclick="ptpRemoveItem('<?php echo esc_js($item['cart_key']); ?>','<?php echo esc_js($item['type']); ?>','ptp-mo-<?php echo esc_js($item['cart_key']); ?>')" style="width:28px;height:28px;border:none;background:#f4f4f5;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#a1a1aa">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="12" height="12"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($multiweek_discount_amount > 0 || $bundle_discount_amount > 0 || $early_bird_discount_amount > 0): ?>
            <div style="padding:8px 12px;background:rgba(22,163,74,0.06);border:1px solid rgba(22,163,74,0.15);border-radius:4px;margin-top:4px">
                <?php if ($multiweek_discount_amount > 0): ?><div style="display:flex;justify-content:space-between;font-size:12px;color:#16a34a;padding:2px 0"><span>Multi-Week (<?php echo $multiweek_discount_pct; ?>%)</span><span>-$<?php echo number_format($multiweek_discount_amount, 2); ?></span></div><?php endif; ?>
                <?php if ($bundle_discount_amount > 0): ?><div style="display:flex;justify-content:space-between;font-size:12px;color:#16a34a;padding:2px 0"><span>Camp + Training Bundle</span><span>-$<?php echo number_format($bundle_discount_amount, 2); ?></span></div><?php endif; ?>
                <?php if ($early_bird_discount_amount > 0): ?><div style="display:flex;justify-content:space-between;font-size:12px;color:#16a34a;padding:2px 0"><span>Early Bird ($50/camp)</span><span>-$<?php echo number_format($early_bird_discount_amount, 2); ?></span></div><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <script>
        // Show mobile order summary on mobile only
        (function(){ if(window.innerWidth<1024){ var el=document.getElementById('ptpMobileOrder'); if(el)el.style.display='block'; } })();
        // Remove item handler
        function ptpRemoveItem(cartKey,itemType,elId) {
            if(!confirm(itemType==='camp'?'Remove this camp?':'Remove this training session?'))return;
            var el=document.getElementById(elId);
            if(el){el.style.transition='all 0.3s';el.style.opacity='0';el.style.transform='translateX(-20px)';el.style.maxHeight='0';el.style.padding='0';el.style.margin='0';el.style.overflow='hidden';}
            // Also call the existing removeCheckoutItem if available
            setTimeout(function(){
                if(typeof removeCheckoutItem==='function'){removeCheckoutItem(cartKey,itemType);}
                else{
                    // Direct AJAX removal + redirect
                    var fd=new FormData();
                    fd.append('action','ptp_remove_cart_item');
                    fd.append('cart_key',cartKey);
                    fd.append('item_type',itemType);
                    fd.append('nonce','<?php echo wp_create_nonce('ptp_cart_action'); ?>');
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',body:fd,credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        var url=new URL(window.location);
                        if(itemType==='training'){
                            ['trainer_id','package','date','time','location','location_address','location_lat','location_lng','group_size'].forEach(function(k){url.searchParams.delete(k);});
                        }
                        // Check if anything remains
                        var remaining=res.data?res.data.cart_count:0;
                        if(remaining===0&&!url.searchParams.has('trainer_id')&&!url.searchParams.has('camps')){
                            window.location.href='<?php echo home_url('/ptp-cart/'); ?>';
                        }else{
                            window.location.href=url.toString();
                        }
                    }).catch(function(){window.location.reload();});
                }
            },350);
        }
        </script>

        <!-- Back to Cart - desktop only (mobile is in header) -->
        <div class="back-link-wrapper">
            <a href="<?php echo home_url('/ptp-cart/'); ?>" class="back-to-cart">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                Back to Cart
            </a>
        </div>
        
        <!-- Progress Stepper -->
        <?php if ($has_training && $trainer && (empty($session_date) || empty($session_time))): ?>
        <div style="padding:14px 18px;margin-bottom:16px;background:rgba(252,185,0,0.08);border:1px solid rgba(252,185,0,0.3);border-radius:12px;display:flex;align-items:center;gap:12px">
            <svg width="20" height="20" fill="none" stroke="#d49a00" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div>
                <div style="font-size:14px;font-weight:600;color:#92400e">Missing <?php echo empty($session_date) ? 'date' : ''; ?><?php echo empty($session_date) && empty($session_time) ? ' and ' : ''; ?><?php echo empty($session_time) ? 'time' : ''; ?></div>
                <div style="font-size:12px;color:#92400e;opacity:.8;margin-top:2px">Please <a href="<?php echo esc_url(home_url('/trainer/' . esc_attr($trainer->slug) . '/')); ?>" style="color:#92400e;font-weight:600;text-decoration:underline">go back to the trainer profile</a> and pick a date & time.</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="progress-stepper">
            <div class="progress-steps">
                <div class="progress-step active" data-step="1">
                    <div class="step-dot">1</div>
                    <div class="step-label">Player</div>
                </div>
                <div class="progress-step" data-step="2">
                    <div class="step-dot">2</div>
                    <div class="step-label">Parent</div>
                </div>
                <div class="progress-step" data-step="3">
                    <div class="step-dot">3</div>
                    <div class="step-label">Payment</div>
                </div>
            </div>
        </div>
        
        <?php if ($stripe_pk && $client_secret): ?>
        <!-- Express Checkout (Apple Pay / Google Pay) -->
        <div class="express-checkout">
            <div class="express-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                Express Checkout
            </div>
            <div class="express-badges">
                <div class="express-badge">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09l.01-.01zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                    Apple Pay
                </div>
                <div class="express-badge">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                    Google Pay
                </div>
            </div>
            <div id="express-buttons"></div>
            <div class="express-divider">or continue below</div>
        </div>
        <?php endif; ?>
        
        <?php if ($show_package_selector): ?>
        <!-- v216: Package Selector -->
        <div class="pkg-selector" id="pkgSelector">
            <div class="pkg-selector-hdr">
                <?php if (!empty($trainer->photo_url)): ?>
                <img src="<?php echo esc_url($trainer->photo_url); ?>" class="pkg-selector-photo" alt="<?php echo esc_attr($trainer->display_name); ?>">
                <?php endif; ?>
                <div class="pkg-selector-info">
                    <div class="pkg-selector-name"><?php echo esc_html($trainer->display_name); ?></div>
                    <div class="pkg-selector-meta"><?php echo esc_html($session_date ? date('D, M j', strtotime($session_date)) : ''); ?><?php if ($time_display): ?> &middot; <?php echo esc_html($time_display); ?><?php endif; ?><?php if ($group_size > 1): ?> &middot; <?php echo intval($group_size); ?> players<?php endif; ?></div>
                </div>
            </div>
            <div class="pkg-selector-body">
                <div class="pkg-selector-label">Choose Package</div>
                <div class="pkg-options">
                    <?php 
                    // v216: Updated to Single / 5-Pack / 10-Pack
                    $display_pkgs = array('single', 'pack5', 'pack10');
                    foreach ($display_pkgs as $dk): 
                        if (!isset($pkgs[$dk])) continue;
                        $dp = $pkgs[$dk];
                    ?>
                    <div class="pkg-opt<?php echo $dk === $pkg_key ? ' sel' : ''; ?>" data-pkg="<?php echo esc_attr($dk); ?>" data-price="<?php echo intval($dp['p']); ?>" data-sessions="<?php echo intval($dp['s']); ?>" data-name="<?php echo esc_attr($dp['n']); ?>" data-save="<?php echo intval($dp['v'] ?? 0); ?>">
                        <?php if (($dp['d'] ?? 0) >= 20): ?><div class="pkg-opt-badge">Best Value</div><?php endif; ?>
                        <div class="pkg-opt-name"><?php echo esc_html($dp['n']); ?></div>
                        <div class="pkg-opt-price">$<?php echo number_format($dp['p'], 0); ?></div>
                        <?php if (($dp['v'] ?? 0) > 0): ?><div class="pkg-opt-save">Save $<?php echo intval($dp['v']); ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="pkg-selector-total">
                    <span class="pkg-selector-total-label">Session Total</span>
                    <span class="pkg-selector-total-price" id="pkgTotalPrice">$<?php echo number_format($training_price, 0); ?><span class="pkg-selector-total-per"><?php echo ($sel['s'] ?? 1) > 1 ? ' (' . intval($sel['s']) . ' sessions)' : ''; ?></span></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <form id="checkout-form" method="post" autocomplete="on" novalidate>
            <input type="hidden" name="action" value="ptp_save_checkout">
            <?php wp_nonce_field('ptp_checkout', 'ptp_checkout_nonce'); ?>
            <input type="hidden" name="checkout_session" value="<?php echo esc_attr($checkout_session_id); ?>">
            <input type="hidden" name="cart_total" id="cartTotal" value="<?php echo $total; ?>">
            <input type="hidden" name="camp_ids" value="<?php echo esc_attr(implode(',', $camp_ids_from_url)); ?>">
            
            <?php if ($has_training && $trainer): ?>
            <!-- Training Session Data (from URL params) -->
            <input type="hidden" name="has_training" value="1">
            <input type="hidden" name="trainer_id" value="<?php echo esc_attr($trainer_id); ?>">
            <input type="hidden" name="trainer_name" value="<?php echo esc_attr($trainer->display_name); ?>">
            <input type="hidden" name="training_package" value="<?php echo esc_attr($pkg_key); ?>">
            <input type="hidden" name="training_sessions" value="<?php echo esc_attr($sel['s'] ?? 1); ?>">
            <input type="hidden" name="training_price" value="<?php echo esc_attr($training_price); ?>">
            <input type="hidden" name="training_total" value="<?php echo esc_attr($training_price); ?>">
            <input type="hidden" name="session_date" value="<?php echo esc_attr($session_date); ?>">
            <input type="hidden" name="session_time" value="<?php echo esc_attr($session_time); ?>">
            <input type="hidden" name="session_location" value="<?php echo esc_attr($session_loc); ?>">
            <input type="hidden" name="session_location_address" value="<?php echo esc_attr($session_loc_address); ?>">
            <input type="hidden" name="session_location_lat" value="<?php echo esc_attr($session_loc_lat); ?>">
            <input type="hidden" name="session_location_lng" value="<?php echo esc_attr($session_loc_lng); ?>">
            <input type="hidden" name="group_size" value="<?php echo esc_attr($group_size); ?>">
            <?php if ($group_session_id): ?>
            <input type="hidden" name="group_session_id" value="<?php echo esc_attr($group_session_id); ?>">
            <?php endif; ?>
            <?php elseif ($has_training_from_native && $native_training_data): ?>
            <!-- Training Session Data (from native cart) -->
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
            
            <!-- Section 1: Player Information -->
            <div class="form-section" data-section="1">
                <div class="section-header open" onclick="toggleSection(this)">
                    <div class="section-number">1</div>
                    <div class="section-title">
                        Player Information
                        <div class="section-summary"><?php echo $has_training ? "Who's training?" : "Who's attending camp?"; ?></div>
                    </div>
                    <div class="section-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></div>
                </div>
                <div class="section-body open">
                    <?php if (!empty($players)): ?>
                    <div class="saved-players">
                        <?php foreach ($players as $p): 
                            // v236: Skip blank player records (no first name)
                            if (empty(trim($p->first_name ?? ''))) continue;
                        ?>
                        <div class="saved-player" data-player-id="<?php echo $p->id; ?>" data-fn="<?php echo esc_attr($p->first_name); ?>" data-ln="<?php echo esc_attr($p->last_name); ?>" data-dob="<?php echo esc_attr($p->dob); ?>" data-shirt="<?php echo esc_attr($p->shirt_size); ?>" data-instagram="<?php echo esc_attr($p->instagram_handle ?? ''); ?>" data-photo="<?php echo esc_attr($p->player_photo_url ?? ''); ?>" onclick="selectPlayer(this)">
                            <?php if (!empty($p->player_photo_url)): ?>
                            <img src="<?php echo esc_url($p->player_photo_url); ?>" alt="" class="saved-player-avatar" style="width:40px;height:40px;border-radius:50%;object-fit:cover">
                            <?php else: ?>
                            <div class="saved-player-avatar"><?php echo esc_html(strtoupper(substr($p->first_name, 0, 1))); ?></div>
                            <?php endif; ?>
                            <div class="saved-player-info">
                                <div class="saved-player-name"><?php echo esc_html($p->first_name . ' ' . $p->last_name); ?></div>
                                <?php 
                                $player_age = 0;
                                if (!empty($p->dob) && $p->dob !== '0000-00-00' && strtotime($p->dob) > 0) {
                                    $player_age = (int) floor((time() - strtotime($p->dob)) / 31556952);
                                }
                                if ($player_age > 0 && $player_age < 25): ?>
                                <div class="saved-player-age">Age <?php echo $player_age; ?><?php if (!empty($p->instagram_handle)): ?> &middot; <?php echo esc_html($p->instagram_handle); ?><?php endif; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="add-new-btn" onclick="addNewPlayer()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            New Player
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <input type="hidden" name="player_id" id="playerId" value="">
                    
                    <div id="playerFields" style="<?php echo !empty($players) ? 'display:none;' : ''; ?>">
                        <div class="field-row field-grid">
                            <div>
                                <label class="field-label">First Name *</label>
                                <input type="text" name="player_first_name" id="playerFirstName" class="field-input" required autocomplete="given-name">
                            </div>
                            <div>
                                <label class="field-label">Last Name *</label>
                                <input type="text" name="player_last_name" id="playerLastName" class="field-input" required autocomplete="family-name">
                            </div>
                        </div>
                        <div class="field-row field-grid">
                            <div>
                                <label class="field-label">Date of Birth *</label>
                                <input type="date" name="player_dob" id="playerDob" class="field-input" required>
                            </div>
                            <div>
                                <label class="field-label">T-Shirt Size <?php echo $has_camps ? '*' : '(Optional)'; ?></label>
                                <select name="player_shirt" id="playerShirt" class="field-select" <?php echo $has_camps ? 'required' : ''; ?>>
                                    <option value="">Select size</option>
                                    <option value="YS">Youth Small</option>
                                    <option value="YM">Youth Medium</option>
                                    <option value="YL">Youth Large</option>
                                    <option value="AS">Adult Small</option>
                                    <option value="AM">Adult Medium</option>
                                    <option value="AL">Adult Large</option>
                                    <option value="AXL">Adult XL</option>
                                </select>
                            </div>
                        </div>
                        <div class="field-row">
                            <label class="field-label">Team/Club (Optional)</label>
                            <input type="text" name="player_team" id="playerTeam" class="field-input" placeholder="e.g., FC Lightning U12">
                        </div>
                        <div class="field-row">
                            <label class="field-label">Medical/Allergy Notes (Optional)</label>
                            <textarea name="player_medical" id="playerMedical" class="field-input" rows="2" placeholder="Any allergies, medications, or conditions we should know about"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Section 2: Parent/Guardian -->
            <div class="form-section" data-section="2">
                <div class="section-header" onclick="toggleSection(this)">
                    <div class="section-number">2</div>
                    <div class="section-title">
                        Parent/Guardian
                        <div class="section-summary">Contact & emergency info</div>
                    </div>
                    <div class="section-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></div>
                </div>
                <div class="section-body">
                    <?php if ($logged_in && $parent): ?>
                    <div style="background:var(--gray-50);border-radius:10px;padding:16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:600;color:var(--black);">
                            <?php echo esc_html(strtoupper(substr($parent->first_name ?: $user->display_name, 0, 1))); ?>
                        </div>
                        <div style="flex:1;">
                            <div style="font-weight:600;font-size:14px;"><?php echo esc_html($parent->first_name . ' ' . $parent->last_name); ?></div>
                            <div style="font-size:12px;color:var(--gray-500);"><?php echo esc_html($user->user_email); ?></div>
                        </div>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                    </div>
                    <input type="hidden" name="parent_first_name" value="<?php echo esc_attr($parent->first_name); ?>">
                    <input type="hidden" name="parent_last_name" value="<?php echo esc_attr($parent->last_name); ?>">
                    <input type="hidden" name="parent_email" value="<?php echo esc_attr($user->user_email); ?>">
                    <input type="hidden" name="parent_phone" value="<?php echo esc_attr($parent->phone); ?>">
                    <?php else: ?>
                    <div class="field-row field-grid">
                        <div>
                            <label class="field-label">First Name *</label>
                            <input type="text" name="parent_first_name" class="field-input" required autocomplete="given-name">
                        </div>
                        <div>
                            <label class="field-label">Last Name *</label>
                            <input type="text" name="parent_last_name" class="field-input" required autocomplete="family-name">
                        </div>
                    </div>
                    <div class="field-row field-grid">
                        <div>
                            <label class="field-label">Email *</label>
                            <input type="email" name="parent_email" class="field-input" required autocomplete="email">
                        </div>
                        <div>
                            <label class="field-label">Phone *</label>
                            <input type="tel" name="parent_phone" class="field-input" required autocomplete="tel" placeholder="(555) 123-4567">
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--gray-200);">
                        <?php $em_name = $parent ? ($parent->emergency_name ?? '') : ''; $em_phone = $parent ? ($parent->emergency_phone ?? '') : ''; $em_rel = $parent ? ($parent->emergency_relation ?? '') : ''; ?>
                        <div style="font-size:13px;font-weight:600;color:var(--gray-700);margin-bottom:12px;">Emergency Contact</div>
                        <div class="field-row field-grid">
                            <div>
                                <label class="field-label">Name *</label>
                                <input type="text" name="emergency_name" class="field-input" required value="<?php echo esc_attr($em_name); ?>">
                            </div>
                            <div>
                                <label class="field-label">Phone *</label>
                                <input type="tel" name="emergency_phone" class="field-input" required value="<?php echo esc_attr($em_phone); ?>">
                            </div>
                        </div>
                        <div class="field-row">
                            <label class="field-label">Relationship *</label>
                            <select name="emergency_relation" class="field-select" required>
                                <option value="">Select relationship</option>
                                <option value="Spouse" <?php selected($em_rel, 'Spouse'); ?>>Spouse</option>
                                <option value="Grandparent" <?php selected($em_rel, 'Grandparent'); ?>>Grandparent</option>
                                <option value="Aunt/Uncle" <?php selected($em_rel, 'Aunt/Uncle'); ?>>Aunt/Uncle</option>
                                <option value="Family Friend" <?php selected($em_rel, 'Family Friend'); ?>>Family Friend</option>
                                <option value="Other" <?php selected($em_rel, 'Other'); ?>>Other</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- How Did You Find Us -->
            <div class="form-section" data-section="how-found">
                <div class="section-header" onclick="toggleSection(this)">
                    <div class="section-number" style="background:var(--gold);color:var(--black);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    </div>
                    <div class="section-title">
                        How Did You Find Us?
                        <div class="section-summary">Help us serve more families like yours</div>
                    </div>
                    <div class="section-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></div>
                </div>
                <div class="section-body">
                    <div class="field-row">
                        <select name="how_found_us" id="howFoundUs" class="field-select">
                            <option value="">Select one...</option>
                            <option value="instagram">Instagram (@ptp.training)</option>
                            <option value="facebook">Facebook / Meta Ad</option>
                            <option value="google">Google Search</option>
                            <option value="friend_referral">Friend / Word of Mouth</option>
                            <option value="team_coach">Team Coach</option>
                            <option value="school">School / Flyer</option>
                            <option value="returning">Returning Family</option>
                            <option value="saw_camp">Saw a PTP Camp in Person</option>
                            <option value="tiktok">TikTok</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="field-row" id="howFoundOtherWrap" style="display:none;margin-top:12px;">
                        <label class="field-label">Tell us more</label>
                        <input type="text" name="how_found_us_other" id="howFoundOther" class="field-input" placeholder="How did you hear about PTP?">
                    </div>
                    <script>
                    document.getElementById('howFoundUs').addEventListener('change', function(){
                        document.getElementById('howFoundOtherWrap').style.display = this.value === 'other' ? 'block' : 'none';
                    });
                    </script>
                </div>
            </div>
            
            <!-- Section 3: Discounts & Add-ons -->
            <?php if ($has_camps): ?>
            <div class="form-section" data-section="discounts">
                <div class="section-header" onclick="toggleSection(this)">
                    <div class="section-number" style="background:var(--gold);color:var(--black);">%</div>
                    <div class="section-title">
                        Discounts & Add-ons
                        <div class="section-summary">Save more on your registration</div>
                    </div>
                    <div class="section-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></div>
                </div>
                <div class="section-body">
                    <?php if ($multiweek_discount_pct > 0): ?>
                    <div class="discount-applied" style="margin-bottom:12px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                        <span>Multi-Week Discount Applied: <?php echo $multiweek_discount_pct; ?>% off (-$<?php echo number_format($multiweek_discount_amount, 2); ?>)</span>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Sibling Discount -->
                    <div class="discount-box">
                        <div class="discount-header" onclick="toggleDiscount(this)">
                            <div class="discount-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                            <div class="discount-info">
                                <div class="discount-title">Add Sibling</div>
                                <div class="discount-desc">Save 10% on sibling registration<?php echo $camp_count > 1 ? ' for all ' . $camp_count . ' weeks' : ''; ?></div>
                            </div>
                            <div class="discount-badge">Save 10%</div>
                        </div>
                        <div class="discount-content" id="siblingContent">
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:16px;">
                                <input type="checkbox" name="add_sibling" id="addSibling" style="width:20px;height:20px;accent-color:var(--gold);">
                                <span style="font-size:14px;">
                                    <?php if ($camp_count > 1): ?>
                                    Yes, register a sibling for all <?php echo $camp_count; ?> weeks (+$<?php echo number_format($sibling_price_after_discount, 2); ?>)
                                    <?php else: ?>
                                    Yes, register a sibling (+$<?php echo number_format($sibling_price_after_discount, 2); ?>)
                                    <?php endif; ?>
                                </span>
                            </label>
                            <?php if ($camp_count > 1): ?>
                            <div style="font-size:12px;color:var(--gray-500);margin-bottom:16px;padding:10px;background:var(--gray-50);border-radius:6px;">
                                <strong>Sibling will be registered for:</strong>
                                <ul style="margin:8px 0 0 16px;">
                                    <?php foreach ($items as $item): ?>
                                    <li><?php echo esc_html($item['name']); ?> - <?php echo esc_html($item['date']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                            <div id="siblingFields" style="display:none;">
                                <div class="field-row field-grid">
                                    <div>
                                        <label class="field-label">Sibling First Name *</label>
                                        <input type="text" name="sibling_first_name" class="field-input">
                                    </div>
                                    <div>
                                        <label class="field-label">Sibling Last Name *</label>
                                        <input type="text" name="sibling_last_name" class="field-input">
                                    </div>
                                </div>
                                <div class="field-row field-grid">
                                    <div>
                                        <label class="field-label">Date of Birth *</label>
                                        <input type="date" name="sibling_dob" class="field-input">
                                    </div>
                                    <div>
                                        <label class="field-label">T-Shirt Size *</label>
                                        <select name="sibling_shirt" class="field-select">
                                            <option value="">Select size</option>
                                            <option value="YS">Youth Small</option>
                                            <option value="YM">Youth Medium</option>
                                            <option value="YL">Youth Large</option>
                                            <option value="AS">Adult Small</option>
                                            <option value="AM">Adult Medium</option>
                                            <option value="AL">Adult Large</option>
                                            <option value="AXL">Adult XL</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Promo Code -->
                    <div class="discount-box">
                        <div class="discount-header" onclick="toggleDiscount(this)">
                            <div class="discount-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></div>
                            <div class="discount-info">
                                <div class="discount-title">Promo Code</div>
                                <div class="discount-desc">Have a discount code?</div>
                            </div>
                        </div>
                        <div class="discount-content" id="promoContent">
                            <div class="discount-input-row">
                                <input type="text" name="coupon_code" id="couponCode" class="field-input discount-input" placeholder="Enter code" style="text-transform:uppercase;">
                                <button type="button" class="discount-btn" id="applyCoupon">Apply</button>
                            </div>
                            <input type="hidden" name="coupon_discount" id="couponDiscount" value="0">
                            <input type="hidden" name="coupon_id" id="couponId" value="0">
                            <input type="hidden" name="free_code_id" id="freeCodeId" value="0">
                            <div id="couponApplied" class="discount-applied" style="display:none;margin-top:12px;"></div>
                            <div id="couponError" style="display:none;margin-top:8px;font-size:12px;color:var(--red);"></div>
                        </div>
                    </div>
                    
                    <!-- Referral Code -->
                    <div class="discount-box">
                        <div class="discount-header" onclick="toggleDiscount(this)">
                            <div class="discount-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2"/></svg></div>
                            <div class="discount-info">
                                <div class="discount-title">Referral Code</div>
                                <div class="discount-desc">Get $15 off from a friend</div>
                            </div>
                            <div class="discount-badge">$15 Off</div>
                        </div>
                        <div class="discount-content" id="referralContent">
                            <div class="discount-input-row">
                                <input type="text" name="referral_code" id="referralCode" class="field-input discount-input" placeholder="Enter code" style="text-transform:uppercase;">
                                <button type="button" class="discount-btn" id="applyReferral">Apply</button>
                            </div>
                            <input type="hidden" name="referral_discount" id="referralDiscount" value="0">
                            <input type="hidden" name="referral_id" id="referralId" value="0">
                            <div id="referralApplied" class="discount-applied" style="display:none;margin-top:12px;"></div>
                            <div id="referralError" style="display:none;margin-top:8px;font-size:12px;color:var(--red);"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- v175: Discount Section for Training-Only Checkouts -->
            <?php if ($has_training && !$has_camps): ?>
            <div class="form-section" data-section="discounts">
                <div class="section-header" onclick="toggleSection(this)">
                    <div class="section-number" style="background:var(--gold);color:var(--black);">%</div>
                    <div class="section-title">
                        Discounts
                        <div class="section-summary">Have a promo or referral code?</div>
                    </div>
                    <div class="section-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></div>
                </div>
                <div class="section-body">
                    
                    <!-- Promo Code -->
                    <div class="discount-box">
                        <div class="discount-header" onclick="toggleDiscount(this)">
                            <div class="discount-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></div>
                            <div class="discount-info">
                                <div class="discount-title">Promo Code</div>
                                <div class="discount-desc">Have a discount code?</div>
                            </div>
                        </div>
                        <div class="discount-content" id="promoContentTraining">
                            <div class="discount-input-row">
                                <input type="text" name="coupon_code" id="couponCode" class="field-input discount-input" placeholder="Enter code" style="text-transform:uppercase;">
                                <button type="button" class="discount-btn" id="applyCoupon">Apply</button>
                            </div>
                            <input type="hidden" name="coupon_discount" id="couponDiscount" value="0">
                            <input type="hidden" name="coupon_id" id="couponId" value="0">
                            <input type="hidden" name="free_code_id" id="freeCodeId" value="0">
                            <div id="couponApplied" class="discount-applied" style="display:none;margin-top:12px;"></div>
                            <div id="couponError" style="display:none;margin-top:8px;font-size:12px;color:var(--red);"></div>
                        </div>
                    </div>
                    
                    <!-- Referral Code -->
                    <div class="discount-box">
                        <div class="discount-header" onclick="toggleDiscount(this)">
                            <div class="discount-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2"/></svg></div>
                            <div class="discount-info">
                                <div class="discount-title">Referral Code</div>
                                <div class="discount-desc">Get $15 off from a friend</div>
                            </div>
                            <div class="discount-badge">$15 Off</div>
                        </div>
                        <div class="discount-content" id="referralContentTraining">
                            <div class="discount-input-row">
                                <input type="text" name="referral_code" id="referralCode" class="field-input discount-input" placeholder="Enter code" style="text-transform:uppercase;">
                                <button type="button" class="discount-btn" id="applyReferral">Apply</button>
                            </div>
                            <input type="hidden" name="referral_discount" id="referralDiscount" value="0">
                            <input type="hidden" name="referral_id" id="referralId" value="0">
                            <div id="referralApplied" class="discount-applied" style="display:none;margin-top:12px;"></div>
                            <div id="referralError" style="display:none;margin-top:8px;font-size:12px;color:var(--red);"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- v168: Section - Instagram Photo Upload (Optional, camps only) -->
            <?php if ($has_camps): ?>
            <div class="form-section" data-section="instagram">
                <div class="section-header" onclick="toggleSection(this)">
                    <div class="section-number" style="background:linear-gradient(135deg,#833AB4,#E1306C,#FCAF45);color:#fff;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="18" cy="6" r="1.5" fill="currentColor"/></svg>
                    </div>
                    <div class="section-title">
                        Instagram Feature
                        <div class="section-summary">Optional: Share your camper's attendance</div>
                    </div>
                    <div class="section-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></div>
                </div>
                <div class="section-body">
                    <div style="background:linear-gradient(135deg,rgba(131,58,180,0.08),rgba(225,48,108,0.08));border-radius:12px;padding:16px;margin-bottom:16px;">
                        <p style="font-size:14px;color:var(--gray-700);margin:0;line-height:1.5;">
                            <strong>Want us to announce your child's attendance at camp on our Instagram?</strong><br>
                            Upload a photo of your child below and include your Instagram handle. We'll feature them on <a href="https://instagram.com/ptp.training" target="_blank" style="color:#E1306C;font-weight:600;">@ptp.training</a>!
                        </p>
                    </div>
                    
                    <div class="ptp-camp-photo-uploader">
                        <!-- Photo Drop Zone -->
                        <div class="instagram-photo-zone" id="instagramPhotoZone" tabindex="0">
                            <img class="instagram-photo-preview" id="instagramPhotoPreview" src="" alt="">
                            <div class="instagram-photo-placeholder">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
                                    <circle cx="12" cy="13" r="4"/>
                                </svg>
                                <span>Drop photo here or click to upload</span>
                                <span style="font-size:11px;color:var(--gray-400);">JPG, PNG, or WebP • Max 5MB</span>
                            </div>
                            <div class="instagram-photo-overlay">
                                <span>Change Photo</span>
                            </div>
                            <div class="instagram-photo-progress" id="instagramPhotoProgress">
                                <svg class="progress-ring" viewBox="0 0 44 44">
                                    <circle class="bg" cx="22" cy="22" r="20"/>
                                    <circle class="progress" cx="22" cy="22" r="20" stroke-dasharray="125.6" stroke-dashoffset="125.6"/>
                                </svg>
                                <span class="progress-text">0%</span>
                            </div>
                        </div>
                        <input type="file" id="instagramPhotoInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" style="display:none;">
                        <input type="hidden" name="announcement_photo_url" id="announcementPhotoUrl" value="">
                        
                        <button type="button" class="instagram-photo-remove" id="instagramPhotoRemove" style="display:none;" onclick="ptpRemoveInstagramPhoto()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Remove Photo
                        </button>
                    </div>
                    
                    <div class="field-row" style="margin-top:16px;">
                        <label class="field-label">Instagram Handle</label>
                        <input type="text" name="instagram_handle" id="instagramHandle" class="field-input" placeholder="@yourhandle" style="max-width:280px;">
                    </div>
                    
                    <div class="instagram-consent" style="margin-top:16px;">
                        <label style="display:flex;gap:12px;align-items:flex-start;cursor:pointer;">
                            <input type="checkbox" name="photo_consent" id="photoConsent" value="1" style="margin-top:3px;width:18px;height:18px;accent-color:#E1306C;">
                            <span style="font-size:13px;color:var(--gray-600);line-height:1.5;">
                                I give permission for PTP Soccer Camps to post my child's photo on their Instagram page (@ptp.training).
                            </span>
                        </label>
                    </div>
                </div>
            </div>
            
            <style>
            .ptp-camp-photo-uploader{margin-bottom:8px}
            .instagram-photo-zone{position:relative;width:100%;max-width:280px;aspect-ratio:1;border-radius:12px;border:3px dashed var(--gray-300);background:var(--gray-50);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;overflow:hidden}
            .instagram-photo-zone:hover,.instagram-photo-zone.dragging{border-color:#E1306C;background:rgba(225,48,108,0.05)}
            .instagram-photo-zone.has-photo{border-style:solid;border-color:#E1306C}
            .instagram-photo-zone.uploading{pointer-events:none}
            .instagram-photo-preview{width:100%;height:100%;object-fit:cover;display:none}
            .instagram-photo-zone.has-photo .instagram-photo-preview{display:block}
            .instagram-photo-zone.has-photo .instagram-photo-placeholder{display:none}
            .instagram-photo-placeholder{display:flex;flex-direction:column;align-items:center;gap:8px;color:var(--gray-500);padding:20px;text-align:center;font-size:13px}
            .instagram-photo-placeholder svg{color:var(--gray-400)}
            .instagram-photo-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;border-radius:9px;color:#fff;font-size:13px;font-weight:600}
            .instagram-photo-zone:hover .instagram-photo-overlay,.instagram-photo-zone:focus-within .instagram-photo-overlay{opacity:1}
            .instagram-photo-zone.uploading .instagram-photo-overlay{opacity:1;background:rgba(0,0,0,0.8)}
            .instagram-photo-zone.uploading .instagram-photo-overlay>span{display:none}
            .instagram-photo-progress{display:none;flex-direction:column;align-items:center;gap:8px}
            .instagram-photo-zone.uploading .instagram-photo-progress{display:flex}
            .instagram-photo-progress .progress-ring{width:48px;height:48px}
            .instagram-photo-progress .progress-ring circle{fill:none;stroke-width:4}
            .instagram-photo-progress .progress-ring .bg{stroke:rgba(255,255,255,0.2)}
            .instagram-photo-progress .progress-ring .progress{stroke:#E1306C;stroke-linecap:round;transform:rotate(-90deg);transform-origin:center;transition:stroke-dashoffset .3s}
            .instagram-photo-progress .progress-text{color:#fff;font-size:12px;font-weight:600}
            .instagram-photo-remove{display:flex;align-items:center;gap:6px;padding:8px 12px;margin-top:12px;background:transparent;border:1px solid var(--gray-300);border-radius:6px;color:var(--gray-600);font-size:12px;cursor:pointer;transition:all .2s}
            .instagram-photo-remove:hover{border-color:var(--red);color:var(--red)}
            </style>
            
            <script>
            (function() {
                var zone = document.getElementById('instagramPhotoZone');
                var input = document.getElementById('instagramPhotoInput');
                var preview = document.getElementById('instagramPhotoPreview');
                var removeBtn = document.getElementById('instagramPhotoRemove');
                var urlField = document.getElementById('announcementPhotoUrl');
                var progressEl = document.getElementById('instagramPhotoProgress');
                var progressCircle = progressEl ? progressEl.querySelector('.progress') : null;
                var progressText = progressEl ? progressEl.querySelector('.progress-text') : null;
                var consentBox = document.getElementById('photoConsent');
                var nonce = '<?php echo wp_create_nonce('ptp_camp_photo_upload'); ?>';
                
                if (!zone || !input) return;
                
                // Click to upload
                zone.addEventListener('click', function() { input.click(); });
                
                // Drag events
                ['dragenter', 'dragover'].forEach(function(evt) {
                    zone.addEventListener(evt, function(e) {
                        e.preventDefault();
                        zone.classList.add('dragging');
                    });
                });
                ['dragleave', 'drop'].forEach(function(evt) {
                    zone.addEventListener(evt, function(e) {
                        e.preventDefault();
                        zone.classList.remove('dragging');
                    });
                });
                zone.addEventListener('drop', function(e) {
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                        handleFile(e.dataTransfer.files[0]);
                    }
                });
                
                // File input change
                input.addEventListener('change', function() {
                    if (input.files && input.files[0]) {
                        handleFile(input.files[0]);
                    }
                });
                
                // Keyboard support
                zone.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        input.click();
                    }
                });
                
                // Remove photo
                window.ptpRemoveInstagramPhoto = function() {
                    preview.src = '';
                    zone.classList.remove('has-photo');
                    removeBtn.style.display = 'none';
                    urlField.value = '';
                    consentBox.checked = false;
                };
                
                function handleFile(file) {
                    // Validate type
                    if (!file.type.match(/^image\/(jpeg|png|webp)$/)) {
                        alert('Please upload a JPG, PNG, or WebP image');
                        return;
                    }
                    // Validate size
                    if (file.size > 5 * 1024 * 1024) {
                        alert('Image must be under 5MB');
                        return;
                    }
                    
                    // Show preview immediately
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        preview.src = e.target.result;
                        zone.classList.add('has-photo');
                        removeBtn.style.display = '';
                    };
                    reader.readAsDataURL(file);
                    
                    // Upload
                    uploadPhoto(file);
                }
                
                function uploadPhoto(file) {
                    zone.classList.add('uploading');
                    setProgress(0);
                    
                    var formData = new FormData();
                    formData.append('action', 'ptp_upload_camp_announcement_photo');
                    formData.append('photo', file);
                    formData.append('nonce', nonce);
                    
                    var xhr = new XMLHttpRequest();
                    
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            var percent = Math.round((e.loaded / e.total) * 100);
                            setProgress(percent);
                        }
                    });
                    
                    xhr.addEventListener('load', function() {
                        zone.classList.remove('uploading');
                        
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                urlField.value = response.data.url;
                                if (response.data.url) {
                                    preview.src = response.data.url;
                                }
                                // v193: Auto-check consent when photo uploads successfully
                                consentBox.checked = true;
                            } else {
                                alert(response.data?.message || 'Upload failed');
                                preview.src = '';
                                zone.classList.remove('has-photo');
                                removeBtn.style.display = 'none';
                            }
                        } catch (e) {
                            alert('Upload failed. Please try again.');
                        }
                    });
                    
                    xhr.addEventListener('error', function() {
                        zone.classList.remove('uploading');
                        alert('Network error. Please try again.');
                    });
                    
                    xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
                    xhr.send(formData);
                }
                
                function setProgress(percent) {
                    if (!progressCircle || !progressText) return;
                    var circumference = 125.6;
                    var offset = circumference - (percent / 100 * circumference);
                    progressCircle.style.strokeDashoffset = offset;
                    progressText.textContent = percent + '%';
                }
            })();
            </script>
            <?php endif; ?>
            
            <!-- Section 4: Payment -->
            <div class="form-section" data-section="3">
                <div class="section-header" onclick="toggleSection(this)">
                    <div class="section-number">3</div>
                    <div class="section-title">
                        Payment
                        <div class="section-summary">Secure card payment</div>
                    </div>
                    <div class="section-toggle"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></div>
                </div>
                <div class="section-body">
                    <div class="payment-section">
                        <div class="payment-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            Card Details
                        </div>
                        <?php if (!empty($pi_error)): ?>
                        <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:14px 16px;margin-bottom:12px;color:#991B1B;font-size:14px;line-height:1.5;font-family:Inter,system-ui,sans-serif">
                            <strong style="display:block;margin-bottom:4px">Payment could not be initialized</strong>
                            <?php echo esc_html($pi_error); ?>
                            <br><a href="javascript:location.reload()" style="color:#DC2626;font-weight:600;text-decoration:underline;margin-top:4px;display:inline-block">Refresh to try again</a>
                        </div>
                        <?php elseif (empty($client_secret)): ?>
                        <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:14px 16px;margin-bottom:12px;color:#92400E;font-size:14px;line-height:1.5;font-family:Inter,system-ui,sans-serif">
                            <strong style="display:block;margin-bottom:4px">Payment unavailable</strong>
                            We couldn't set up the payment form. Please go back and try again, or <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" style="color:#B45309;font-weight:600;text-decoration:underline">contact us</a> if this continues.
                        </div>
                        <?php endif; ?>
                        <div id="payment-element"></div>
                        <div class="payment-error" id="paymentError"></div>
                    </div>
                    
                    <!-- Liability Waiver -->
                    <div class="waiver-section">
                        <div class="waiver-box" id="waiverBox">
                            <h3>Liability Waiver &amp; Release of Claims</h3>
                            <h4>PTP-PLAYERSTEACHINGPLAYERS LLC</h4>
                            <p>This Liability Waiver and Release of Claims ("Waiver") is entered into by the parent or legal guardian ("Parent") of the participating minor ("Participant") and PTP-PLAYERSTEACHINGPLAYERS LLC ("PTP"), a Pennsylvania limited liability company.</p>

                            <h4>1. Assumption of Risk</h4>
                            <p>Parent acknowledges that participation in soccer camps, training sessions, and related athletic activities organized by PTP involves inherent risks, including but not limited to: physical contact with other participants, coaches, or equipment; sprains, strains, fractures, concussions, and other bodily injuries; heat-related illness; exposure to outdoor elements; and, in rare cases, serious injury or death. Parent voluntarily assumes all such risks on behalf of the Participant.</p>

                            <h4>2. Release and Waiver of Liability</h4>
                            <p>In consideration of Participant being permitted to participate in PTP activities, Parent, on behalf of Parent and Participant, hereby releases, waives, discharges, and covenants not to sue PTP-PLAYERSTEACHINGPLAYERS LLC, its owners, members, managers, officers, directors, employees, coaches, trainers, independent contractors, volunteers, agents, affiliates, and representatives (collectively, "Released Parties") from any and all liability, claims, demands, actions, causes of action, suits, costs, expenses (including attorney's fees), and damages of any kind arising out of or related to Participant's participation in any PTP activity, whether caused by negligence of the Released Parties or otherwise.</p>

                            <h4>3. Medical Authorization</h4>
                            <p>In the event of an emergency, Parent authorizes PTP staff to secure medical treatment for the Participant, including but not limited to first aid, CPR, transportation to a medical facility, and emergency medical services. Parent agrees to be financially responsible for all costs of medical treatment. Parent confirms that Participant is physically fit to participate and has disclosed any relevant medical conditions, allergies, or limitations.</p>

                            <h4>4. Media Release</h4>
                            <p>Parent grants PTP-PLAYERSTEACHINGPLAYERS LLC the irrevocable right and permission to photograph, video record, and otherwise capture the Participant's image, likeness, voice, and performance during PTP activities, and to use, reproduce, distribute, display, and publish such media in any format or medium, including but not limited to websites, social media, advertisements, print materials, and promotional content, without further consent or compensation.</p>

                            <h4>5. Code of Conduct</h4>
                            <p>Parent agrees that Participant shall follow all rules, instructions, and directions given by PTP coaches and staff. PTP reserves the right to dismiss any Participant whose behavior is deemed unsafe, disruptive, or inappropriate, without refund.</p>

                            <h4>6. Refund &amp; Cancellation Policy</h4>
                            <p>Camp registrations may be cancelled for a full refund up to 14 days before the camp start date. Cancellations within 14 days of the camp start date are eligible for credit toward a future PTP event. Private training sessions cancelled with less than 24 hours' notice are non-refundable. PTP reserves the right to cancel or reschedule events due to weather, facility issues, or insufficient enrollment, in which case a full refund or credit will be issued.</p>

                            <h4>7. Indemnification</h4>
                            <p>Parent agrees to indemnify, defend, and hold harmless the Released Parties from and against any and all claims, liabilities, damages, losses, costs, and expenses (including reasonable attorney's fees) arising out of or related to Participant's participation in PTP activities or any breach of this Waiver.</p>

                            <h4>8. Governing Law</h4>
                            <p>This Waiver shall be governed by and construed in accordance with the laws of the Commonwealth of Pennsylvania. Any disputes arising under this Waiver shall be subject to the exclusive jurisdiction of the courts of Delaware County, Pennsylvania.</p>

                            <h4>9. Severability</h4>
                            <p>If any provision of this Waiver is found to be invalid or unenforceable, the remaining provisions shall remain in full force and effect.</p>

                            <h4>10. Acknowledgment</h4>
                            <p>By checking the box below and completing this registration, Parent acknowledges that they have read, understand, and voluntarily agree to all terms of this Liability Waiver and Release of Claims on behalf of themselves and the Participant. Parent confirms they are the legal guardian of the Participant and have the authority to enter into this agreement.</p>
                        </div>
                        <div class="waiver-check">
                            <input type="checkbox" name="waiver" id="waiver">
                            <div class="waiver-text">
                                I have read and agree to the <strong>Liability Waiver &amp; Release of Claims</strong> for PTP-PLAYERSTEACHINGPLAYERS LLC. I confirm I am the parent or legal guardian of the participant.
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit -->
                    <div class="submit-section">
                        <button type="submit" class="submit-btn" id="submitBtn"<?php if (empty($client_secret) && $cents >= 50): ?> disabled style="opacity:0.5;cursor:not-allowed" title="Payment could not be initialized — please refresh"<?php endif; ?>>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                            Complete Registration · $<span class="submit-total" id="submitTotal"><?php echo number_format($total, 2); ?></span>
                        </button>
                        
                        <div class="trust-badges">
                            <div class="trust-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Secure SSL</div>
                            <div class="trust-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg> 14-Day Refund</div>
                            <div class="trust-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Visa, MC, Amex</div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Sidebar - Order Summary (Desktop) -->
    <!-- v158: Restructured for proper scrolling with fixed header/footer -->
    <div class="checkout-sidebar">
        <div class="sidebar-inner">
            <!-- Fixed Header -->
            <div class="sidebar-header">
                <img src="<?php echo esc_url($logo); ?>" class="sidebar-logo" alt="PTP Soccer">
            </div>
            
            <div class="sidebar-title">Order Summary</div>
            
            <!-- Scrollable Items Area -->
            <div class="order-items-scroll">
                <div class="order-items">
                <?php foreach ($items as $item): 
                    $item_image = '';
                    if ($item['type'] === 'camp') {
                        $thumb_id = get_post_thumbnail_id($item['id']);
                        if ($thumb_id) {
                            $item_image = wp_get_attachment_image_url($thumb_id, 'thumbnail');
                        }
                    } elseif ($item['type'] === 'training' || $item['type'] === 'group_session') {
                        // Use trainer photo for training sessions
                        $item_image = !empty($item['img']) ? $item['img'] : (!empty($item['trainer_photo']) ? $item['trainer_photo'] : '');
                    }
                    if (!$item_image) {
                        $item_image = 'https://ptpsummercamps.com/wp-content/uploads/2026/01/high-five.jpg';
                    }
                    
                    // Build meta info - date, time, location
                    $meta_parts = array();
                    // v216: Show session count for multi-session packages
                    if (!empty($item['qty']) && intval($item['qty']) > 1) {
                        $meta_parts[] = intval($item['qty']) . ' sessions';
                    }
                    if (!empty($item['date'])) {
                        $date_str = $item['date'];
                        if (strtotime($date_str)) {
                            $date_str = date('D, M j', strtotime($date_str));
                        }
                        $meta_parts[] = $date_str . (!empty($item['qty']) && intval($item['qty']) > 1 ? ' (1st session)' : '');
                    }
                    if (!empty($item['time'])) {
                        $t = $item['time'];
                        if (function_exists('ptp_normalize_session_time')) $t = ptp_normalize_session_time($t) ?: $t;
                        $tts = strtotime($t);
                        $meta_parts[] = $tts ? date('g:i A', $tts) : $t;
                    }
                    if (!empty($item['loc']) || !empty($item['location'])) {
                        $meta_parts[] = !empty($item['loc']) ? $item['loc'] : $item['location'];
                    }
                    $meta_string = implode(' • ', $meta_parts);
                    
                    // Format type label
                    $type_label = $item['type'];
                    if ($type_label === 'group_session') $type_label = 'Group Session';
                    elseif ($type_label === 'training') $type_label = 'Training';
                ?>
                <div class="order-item" data-cart-key="<?php echo esc_attr($item['cart_key'] ?? ''); ?>" data-item-type="<?php echo esc_attr($item['type'] ?? 'product'); ?>" data-item-name="<?php echo esc_attr($item['name'] ?? ''); ?>" data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>" data-item-price="<?php echo esc_attr($item['price'] ?? 0); ?>" data-stripe-product="<?php echo esc_attr($item['stripe_product'] ?? ($item['metadata']['stripe_product'] ?? '')); ?>" data-trainer-id="<?php echo esc_attr($item['trainer_id'] ?? ''); ?>" data-item-date="<?php echo esc_attr($item['date'] ?? ''); ?>" data-item-location="<?php echo esc_attr($item['loc'] ?? ($item['location'] ?? '')); ?>" data-item-time="<?php echo esc_attr($item['time'] ?? ''); ?>">
                    <div class="order-item-img" style="<?php if ($item_image): ?>background-image:url('<?php echo esc_url($item_image); ?>');background-size:cover;background-position:center;<?php endif; ?>">
                        <?php if (!$item_image): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="order-item-info">
                        <div class="order-item-type"><?php echo esc_html(strtoupper($type_label)); ?></div>
                        <div class="order-item-name"><?php echo esc_html($item['name']); ?></div>
                        <?php if ($meta_string): ?>
                        <div class="order-item-meta"><?php echo esc_html($meta_string); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="order-item-actions">
                        <div class="order-item-price">$<?php echo number_format($item['price'], 2); ?></div>
                        <?php if (!empty($item['cart_key'])): ?>
                        <button type="button" class="order-item-remove" onclick="removeCheckoutItem('<?php echo esc_js($item['cart_key']); ?>', '<?php echo esc_js($item['type']); ?>')" title="Remove item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div><!-- .order-items -->
            </div><!-- .order-items-scroll (scrollable area) -->
            
            <!-- v158: Fixed Footer with totals -->
            <div class="sidebar-footer">
            <div class="order-totals">
                <div class="order-line">
                    <span>Subtotal</span>
                    <span id="orderSubtotal">$<?php echo number_format($subtotal, 2); ?></span>
                </div>
                <?php if ($multiweek_discount_amount > 0): ?>
                <div class="order-line discount">
                    <span>Multi-Week Discount (<?php echo $multiweek_discount_pct; ?>%)</span>
                    <span>-$<?php echo number_format($multiweek_discount_amount, 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($bundle_discount_amount > 0): ?>
                <div class="order-line discount" id="bundleLine">
                    <span>Camp + Training Bundle (5% off)</span>
                    <span>-$<?php echo number_format($bundle_discount_amount, 2); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($early_bird_discount_amount > 0): ?>
                <div class="order-line discount">
                    <span>⚡ Early Bird Savings ($50/camp)</span>
                    <span>-$<?php echo number_format($early_bird_discount_amount, 2); ?></span>
                </div>
                <?php endif; ?>
                <div class="order-line discount" id="couponLine" style="display:none;">
                    <span id="couponLabel">Promo Code</span>
                    <span id="orderCoupon">-$0.00</span>
                </div>
                <div class="order-line discount" id="referralLine" style="display:none;">
                    <span>Referral Discount</span>
                    <span id="orderReferral">-$25.00</span>
                </div>
                <div class="order-line" id="siblingLine" style="display:none;">
                    <span>Sibling Registration (10% off)</span>
                    <span id="orderSibling">+$0.00</span>
                </div>
                <div class="order-line">
                    <span>Processing Fee</span>
                    <span id="orderFee">$<?php echo number_format($processing_fee, 2); ?></span>
                </div>
                <div class="order-line total">
                    <span>Total</span>
                    <span class="order-amount" id="orderTotal">$<?php echo number_format($total, 2); ?></span>
                </div>
            </div>
            
            <div class="order-guarantee">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <div class="order-guarantee-title">Satisfaction Guaranteed</div>
                <div class="order-guarantee-text">Full refund up to 14 days before camp starts</div>
            </div>
            </div><!-- .sidebar-footer -->
        </div>
    </div>
</div>

<!-- Mobile Cart Overlay -->
<div class="mobile-cart-overlay" id="mobileCartOverlay" onclick="closeMobileCart()"></div>

<!-- Mobile Cart Drawer -->
<div class="mobile-cart-drawer" id="mobileCartDrawer">
    <div class="mobile-cart-header">
        <span class="mobile-cart-title">Your Order</span>
        <button type="button" class="mobile-cart-close" onclick="closeMobileCart()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="mobile-cart-items">
        <?php foreach ($items as $item): 
            $item_image = '';
            if (($item['type'] ?? '') === 'camp') {
                $item_image = !empty($item['img']) ? $item['img'] : (!empty($item['image']) ? $item['image'] : 'https://ptpsummercamps.com/wp-content/uploads/2026/01/high-five.jpg');
            } elseif ($item['type'] === 'training' || $item['type'] === 'group_session') {
                $item_image = !empty($item['img']) ? $item['img'] : (!empty($item['trainer_photo']) ? $item['trainer_photo'] : '');
            }
            $meta_parts = array();
            // v216: Show session count for multi-session packages
            if (!empty($item['qty']) && intval($item['qty']) > 1) {
                $meta_parts[] = intval($item['qty']) . ' sessions';
            }
            if (!empty($item['date'])) {
                $dstr = $item['date'];
                if (strtotime($dstr)) $dstr = date('D, M j', strtotime($dstr));
                $meta_parts[] = $dstr;
            }
            if (!empty($item['time'])) {
                $t = $item['time'];
                if (function_exists('ptp_normalize_session_time')) $t = ptp_normalize_session_time($t) ?: $t;
                $tts = strtotime($t);
                $meta_parts[] = $tts ? date('g:i A', $tts) : $t;
            }
            if (!empty($item['loc']) || !empty($item['location'])) $meta_parts[] = !empty($item['loc']) ? $item['loc'] : $item['location'];
            $type_label = $item['type'];
            if ($type_label === 'group_session') $type_label = 'Group';
            elseif ($type_label === 'training') $type_label = 'Training';
        ?>
        <div class="mobile-cart-item" data-cart-key="<?php echo esc_attr($item['cart_key'] ?? ''); ?>" data-item-type="<?php echo esc_attr($item['type'] ?? 'product'); ?>" data-item-name="<?php echo esc_attr($item['name'] ?? ''); ?>" data-item-id="<?php echo esc_attr($item['id'] ?? ''); ?>" data-item-price="<?php echo esc_attr($item['price'] ?? 0); ?>" data-stripe-product="<?php echo esc_attr($item['stripe_product'] ?? ($item['metadata']['stripe_product'] ?? '')); ?>" data-trainer-id="<?php echo esc_attr($item['trainer_id'] ?? ''); ?>" data-item-date="<?php echo esc_attr($item['date'] ?? ''); ?>" data-item-location="<?php echo esc_attr($item['loc'] ?? ($item['location'] ?? '')); ?>" data-item-time="<?php echo esc_attr($item['time'] ?? ''); ?>">
            <div class="mobile-cart-img" style="<?php if ($item_image): ?>background-image:url('<?php echo esc_url($item_image); ?>');<?php endif; ?>"></div>
            <div class="mobile-cart-item-info">
                <div class="mobile-cart-item-type"><?php echo esc_html(strtoupper($type_label)); ?></div>
                <div class="mobile-cart-item-name"><?php echo esc_html($item['name']); ?></div>
                <?php if ($meta_parts): ?>
                <div class="mobile-cart-item-meta"><?php echo esc_html(implode(' • ', $meta_parts)); ?></div>
                <?php endif; ?>
            </div>
            <div class="mobile-cart-item-actions">
                <div class="mobile-cart-item-price">$<?php echo number_format($item['price'], 2); ?></div>
                <?php if (!empty($item['cart_key'])): ?>
                <button type="button" class="mobile-cart-item-remove" onclick="removeCheckoutItem('<?php echo esc_js($item['cart_key']); ?>', '<?php echo esc_js($item['type']); ?>')" title="Remove">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="mobile-cart-totals">
        <div class="mobile-cart-line">
            <span>Subtotal</span>
            <span>$<?php echo number_format($subtotal, 2); ?></span>
        </div>
        <?php if ($multiweek_discount_amount > 0): ?>
        <div class="mobile-cart-line discount">
            <span>Multi-Week Discount</span>
            <span>-$<?php echo number_format($multiweek_discount_amount, 2); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($bundle_discount_amount > 0): ?>
        <div class="mobile-cart-line discount" id="mobileBundleLine">
            <span>Bundle Discount</span>
            <span>-$<?php echo number_format($bundle_discount_amount, 2); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($early_bird_discount_amount > 0): ?>
        <div class="mobile-cart-line discount">
            <span>Early Bird Savings ($50/camp)</span>
            <span>-$<?php echo number_format($early_bird_discount_amount, 2); ?></span>
        </div>
        <?php endif; ?>
        <div class="mobile-cart-line">
            <span>Processing Fee</span>
            <span>$<?php echo number_format($processing_fee, 2); ?></span>
        </div>
        <div class="mobile-cart-line total">
            <span>Total</span>
            <span>$<?php echo number_format($total, 2); ?></span>
        </div>
    </div>
</div>

<!-- Mobile Summary Bar -->
<div class="mobile-summary" id="mobileSummary">
    <div class="mobile-summary-info" onclick="toggleMobileCart()">
        <div class="mobile-summary-items" id="mobileItemsLabel">
            <?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg>
        </div>
        <div class="mobile-summary-total">$<span id="mobileTotal"><?php echo number_format($total, 2); ?></span></div>
    </div>
    <button type="button" class="mobile-summary-btn" onclick="document.getElementById('submitBtn').click()">Pay Now</button>
</div>

<script>
function toggleMobileCart() {
    var drawer = document.getElementById('mobileCartDrawer');
    var overlay = document.getElementById('mobileCartOverlay');
    var label = document.getElementById('mobileItemsLabel');
    if (!drawer || !overlay) return;
    var isOpen = drawer.classList.contains('open');
    if (isOpen) {
        closeMobileCart();
    } else {
        drawer.classList.add('open');
        overlay.classList.add('open');
        if (label) label.classList.add('expanded');
        document.body.style.overflow = 'hidden';
    }
}
function closeMobileCart() {
    var drawer = document.getElementById('mobileCartDrawer');
    var overlay = document.getElementById('mobileCartOverlay');
    var label = document.getElementById('mobileItemsLabel');
    if (drawer) drawer.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    if (label) label.classList.remove('expanded');
    document.body.style.overflow = '';
}
// Safety: clear scroll lock when returning from external page (e.g. back button, tab switch)
window.addEventListener('pageshow', function(e) { if (e.persisted) document.body.style.overflow = ''; });
document.addEventListener('visibilitychange', function() { if (!document.hidden) document.body.style.overflow = ''; });

// v157: Initialize - if player fields are hidden on load, remove required attributes
(function() {
    var playerFields = document.getElementById('playerFields');
    if (playerFields && playerFields.style.display === 'none') {
        playerFields.querySelectorAll('[required]').forEach(function(field) {
            field.removeAttribute('required');
            field.dataset.wasRequired = 'true';
        });
    }
})();
</script>

<?php if ($stripe_pk && $client_secret): ?>
<script src="https://js.stripe.com/v3/"></script>
<script>
(function(){
    var stripe = Stripe('<?php echo esc_js($stripe_pk); ?>');
    var clientSecret = '<?php echo esc_js($client_secret); ?>';
    var checkoutSession = '<?php echo esc_js($checkout_session_id); ?>';
    
    // v175: Extract PaymentIntent ID for updating amount when coupons applied
    window.paymentIntentId = clientSecret.split('_secret')[0];
    
    // Initialize Elements
    var elements = stripe.elements({
        clientSecret: clientSecret,
        appearance: {
            theme: 'stripe',
            variables: {
                colorPrimary: '#FCB900',
                colorBackground: '#ffffff',
                colorText: '#1f2937',
                colorDanger: '#ef4444',
                fontFamily: 'Inter, system-ui, sans-serif',
                spacingUnit: '4px',
                borderRadius: '10px'
            }
        }
    });
    
    // Payment Element
    var paymentElement = elements.create('payment', {
        layout: 'tabs',
        wallets: {applePay: 'auto', googlePay: 'auto'}
    });
    paymentElement.mount('#payment-element');
    
    // v240: Clean up booking backup data on successful payment redirect
    // Called right before Stripe redirects to /thank-you/
    function ptpClearBookingBackup() {
        try { sessionStorage.removeItem('ptp_booking'); } catch(e) {}
        try { document.cookie = 'ptp_booking=;path=/ptp-checkout;max-age=0;SameSite=Lax'; } catch(e) {}
    }
    
    // Express Checkout (Apple Pay / Google Pay)
    var expressElement = elements.create('expressCheckout', {
        buttonType: {applePay: 'buy', googlePay: 'buy'},
        buttonTheme: {applePay: 'black', googlePay: 'black'},
        buttonHeight: 48
    });
    expressElement.mount('#express-buttons');
    
    expressElement.on('confirm', async function(event) {
        // v193: Auto-check Instagram consent if photo was uploaded (can't prompt during Express Pay)
        var igPhoto = document.getElementById('announcementPhotoUrl');
        var igConsent = document.getElementById('photoConsent');
        if (igPhoto && igPhoto.value && igConsent && !igConsent.checked) {
            igConsent.checked = true;
        }
        // Save form data first
        var formData = new FormData(document.getElementById('checkout-form'));
        formData.append('checkout_session', checkoutSession);
        var saveRes = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method: 'POST', body: formData}).then(function(r){return r.json()});
        if (!saveRes.success) { showError(saveRes.data && saveRes.data.message ? saveRes.data.message : 'Please fill in all required fields.'); return; }
        
        ptpClearBookingBackup();
        var {error} = await stripe.confirmPayment({
            elements: elements,
            confirmParams: {return_url: '<?php echo home_url('/thank-you/?session='); ?>' + checkoutSession}
        });
        if (error) showError(error.message);
    });
    
    // Form submission
    document.getElementById('checkout-form').onsubmit = async function(e) {
        e.preventDefault();
        
        var btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.classList.add('loading');
        hideError();
        
        if (!document.getElementById('waiver').checked) {
            showError('Please accept the waiver to continue');
            btn.disabled = false;
            btn.classList.remove('loading');
            return;
        }
        
        // v193: If parent uploaded Instagram photo but unchecked consent, confirm intent
        var igPhoto = document.getElementById('announcementPhotoUrl');
        var igConsent = document.getElementById('photoConsent');
        if (igPhoto && igPhoto.value && igConsent && !igConsent.checked) {
            if (confirm('You uploaded a photo for our Instagram feature but haven\'t checked the consent box. Would you like us to feature your child? Press OK to give consent, or Cancel to skip.')) {
                igConsent.checked = true;
            }
        }
        
        try {
            // Save form data
            var formData = new FormData(this);
            formData.append('checkout_session', checkoutSession);
            var saveRes = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method: 'POST', body: formData}).then(function(r){return r.json()});
            if (!saveRes.success) { showError(saveRes.data && saveRes.data.message ? saveRes.data.message : 'Please fill in all required fields.'); btn.disabled = false; btn.classList.remove('loading'); return; }
            
            // v134: Free session — skip Stripe when total is $0
            var currentTotal = discountState.subtotal - discountState.couponDiscount - discountState.referralDiscount;
            if (currentTotal <= 0 && discountState.couponDiscount > 0) {
                // Complete as free booking — no Stripe payment needed
                var freeData = new FormData(this);
                freeData.set('action', 'ptp_complete_free_checkout');
                freeData.append('checkout_session', checkoutSession);
                freeData.append('nonce', '<?php echo wp_create_nonce("ptp_free_checkout"); ?>');
                freeData.append('app_code', (document.getElementById('freeSessionCode') && document.getElementById('freeSessionCode').value.trim()) ? document.getElementById('freeSessionCode').value.trim().toUpperCase() : (document.getElementById('couponCode') ? document.getElementById('couponCode').value.trim().toUpperCase() : ''));
                freeData.append('free_code_id', document.getElementById('freeCodeId') ? document.getElementById('freeCodeId').value : '0');
                freeData.append('coupon_id', document.getElementById('couponId') ? document.getElementById('couponId').value : '0');
                try {
                    var freeRaw = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method: 'POST', body: freeData});
                    var freeText = await freeRaw.text();
                    var freeRes;
                    try { freeRes = JSON.parse(freeText); } catch(pe) {
                        console.error('Free checkout non-JSON response:', freeText.substring(0, 500));
                        showError('Booking failed — server error. Please try again or contact us.');
                        btn.disabled = false; btn.classList.remove('loading');
                        return;
                    }
                    if (freeRes.success) {
                        ptpClearBookingBackup();
                        var redirectUrl = '<?php echo home_url('/thank-you/?session='); ?>' + checkoutSession + '&free=1';
                        if (freeRes.data && freeRes.data.booking_id) redirectUrl += '&booking=' + freeRes.data.booking_id;
                        window.location.href = redirectUrl;
                    } else {
                        showError(freeRes.data && freeRes.data.message ? freeRes.data.message : 'Could not complete free booking');
                        btn.disabled = false; btn.classList.remove('loading');
                    }
                } catch(freeErr) {
                    console.error('Free checkout fetch error:', freeErr);
                    showError('Network error during booking. Please check your connection and try again.');
                    btn.disabled = false; btn.classList.remove('loading');
                }
                return;
            }
            
            // Confirm payment
            ptpClearBookingBackup();
            var {error} = await stripe.confirmPayment({
                elements: elements,
                confirmParams: {return_url: '<?php echo home_url('/thank-you/?session='); ?>' + checkoutSession}
            });
            
            if (error) {
                showError(error.message);
                btn.disabled = false;
                btn.classList.remove('loading');
            }
        } catch (err) {
            showError('An error occurred. Please try again.');
            btn.disabled = false;
            btn.classList.remove('loading');
        }
    };
    
    function showError(msg) {
        var el = document.getElementById('paymentError');
        el.textContent = msg;
        el.style.display = 'block';
    }
    
    function hideError() {
        document.getElementById('paymentError').style.display = 'none';
    }
    
    // Make elements available globally for total updates
    window.stripeElements = elements;
})();

// UI Functions
function toggleSection(header) {
    var body = header.nextElementSibling;
    var isOpen = body.classList.contains('open');
    
    // Close all sections
    document.querySelectorAll('.section-body').forEach(function(b) {
        b.classList.remove('open');
    });
    document.querySelectorAll('.section-header').forEach(function(h) {
        h.classList.remove('open');
    });
    
    // Open clicked section
    if (!isOpen) {
        header.classList.add('open');
        body.classList.add('open');
    }
}

function toggleDiscount(header) {
    var content = header.nextElementSibling;
    content.classList.toggle('open');
}

function selectPlayer(el) {
    document.querySelectorAll('.saved-player').forEach(function(p) {
        p.classList.remove('selected');
    });
    el.classList.add('selected');
    
    document.getElementById('playerId').value = el.dataset.playerId;
    document.getElementById('playerFields').style.display = 'none';
    
    // v235: Pre-fill Instagram section from saved player data
    var igHandle = el.dataset.instagram || '';
    var igPhoto = el.dataset.photo || '';
    var handleInput = document.getElementById('instagramHandle');
    var photoInput = document.getElementById('announcementPhotoUrl');
    var photoPreview = document.getElementById('instagramPhotoPreview');
    var photoPlaceholder = document.getElementById('instagramPhotoPrompt');
    var photoZone = document.getElementById('instagramPhotoZone');
    var photoRemoveBtn = document.getElementById('instagramPhotoRemove');
    
    if (handleInput && igHandle) {
        handleInput.value = igHandle;
    }
    if (photoInput && igPhoto) {
        photoInput.value = igPhoto;
        if (photoPreview) {
            photoPreview.src = igPhoto;
            photoPreview.style.display = 'block';
        }
        if (photoPlaceholder) photoPlaceholder.style.display = 'none';
        if (photoZone) photoZone.classList.add('has-photo');
        if (photoRemoveBtn) photoRemoveBtn.style.display = '';
    }
    
    // v157: Remove required from hidden fields to prevent validation errors
    var playerFields = document.getElementById('playerFields');
    playerFields.querySelectorAll('[required]').forEach(function(field) {
        field.removeAttribute('required');
        field.dataset.wasRequired = 'true';
    });
    
    // Mark section as completed
    var section = el.closest('.form-section');
    section.querySelector('.section-header').classList.add('completed');
    updateProgress();
}

function addNewPlayer() {
    document.querySelectorAll('.saved-player').forEach(function(p) {
        p.classList.remove('selected');
    });
    document.getElementById('playerId').value = '';
    document.getElementById('playerFields').style.display = 'block';
    
    // v235: Clear instagram section for new player
    var handleInput = document.getElementById('instagramHandle');
    var photoInput = document.getElementById('announcementPhotoUrl');
    if (handleInput) handleInput.value = '';
    if (photoInput) photoInput.value = '';
    if (typeof ptpRemoveInstagramPhoto === 'function') {
        try { ptpRemoveInstagramPhoto(); } catch(e) {}
    }
    
    // v157: Restore required attributes when showing fields
    var playerFields = document.getElementById('playerFields');
    playerFields.querySelectorAll('[data-was-required]').forEach(function(field) {
        field.setAttribute('required', '');
    });
}

function updateProgress() {
    var steps = document.querySelectorAll('.progress-step');
    var completedSections = document.querySelectorAll('.section-header.completed').length;
    
    steps.forEach(function(step, index) {
        step.classList.remove('active', 'completed');
        if (index < completedSections) {
            step.classList.add('completed');
        } else if (index === completedSections) {
            step.classList.add('active');
        }
    });
}

// Sibling toggle
var siblingCheck = document.getElementById('addSibling');
if (siblingCheck) {
    siblingCheck.onchange = function() {
        document.getElementById('siblingFields').style.display = this.checked ? 'block' : 'none';
        updateTotals();
    };
}

// Discount state
var discountState = {
    subtotal: <?php echo $subtotal; ?>,
    multiweekDiscount: <?php echo $multiweek_discount_amount; ?>,
    bundleDiscount: <?php echo $bundle_discount_amount; ?>,
    earlyBirdDiscount: <?php echo $early_bird_discount_amount; ?>,
    siblingAmount: 0,
    siblingDiscount: 0,
    couponDiscount: 0,
    referralDiscount: 0,
    campPrice: <?php echo $camp_price; ?>,
    siblingSubtotal: <?php echo $sibling_subtotal; ?>, // Full subtotal for all camps
    campCount: <?php echo $camp_count; ?>,
    hasBundle: <?php echo $has_bundle ? 'true' : 'false'; ?>
};

function updateTotals() {
    // Sibling calculation - uses full subtotal for all camps in cart
    var sibling = document.getElementById('addSibling');
    if (sibling && sibling.checked) {
        discountState.siblingAmount = discountState.siblingSubtotal; // All camps
        discountState.siblingDiscount = discountState.siblingSubtotal * 0.1; // 10% off
    } else {
        discountState.siblingAmount = 0;
        discountState.siblingDiscount = 0;
    }
    
    var sub = discountState.subtotal + discountState.siblingAmount;
    
    // v216: Recalculate bundle discount when subtotal changes (5% of subtotal when camps + training)
    if (discountState.hasBundle) {
        discountState.bundleDiscount = Math.round(discountState.subtotal * 0.05 * 100) / 100;
        var bundleLine = document.getElementById('bundleLine');
        if (bundleLine) {
            var bundleAmountEl = bundleLine.querySelector('span:last-child');
            if (bundleAmountEl) bundleAmountEl.textContent = '-$' + discountState.bundleDiscount.toFixed(2);
        }
        // Also update mobile bundle line
        var mobileBundleLine = document.getElementById('mobileBundleLine');
        if (mobileBundleLine) {
            var mobileBundleAmountEl = mobileBundleLine.querySelector('span:last-child');
            if (mobileBundleAmountEl) mobileBundleAmountEl.textContent = '-$' + discountState.bundleDiscount.toFixed(2);
        }
    }
    
    var totalDiscount = discountState.multiweekDiscount + discountState.bundleDiscount + discountState.earlyBirdDiscount + discountState.siblingDiscount + discountState.couponDiscount + discountState.referralDiscount;
    var afterDiscount = sub - totalDiscount;
    if (afterDiscount < 0) afterDiscount = 0;
    var fee = afterDiscount > 0 ? Math.round((afterDiscount * 0.03 + 0.30) * 100) / 100 : 0;
    var total = afterDiscount + fee;
    
    // Update displays
    document.getElementById('orderSubtotal').textContent = '$' + sub.toFixed(2);
    document.getElementById('orderFee').textContent = '$' + fee.toFixed(2);
    document.getElementById('orderTotal').textContent = '$' + total.toFixed(2);
    document.getElementById('submitTotal').textContent = total.toFixed(2);
    document.getElementById('mobileTotal').textContent = total.toFixed(2);
    document.getElementById('cartTotal').value = total.toFixed(2);
    
    // Sibling line - show price after 10% discount
    var siblingLine = document.getElementById('siblingLine');
    if (siblingLine) {
        siblingLine.style.display = discountState.siblingAmount > 0 ? 'flex' : 'none';
        var siblingLabel = discountState.campCount > 1 ? 
            'Sibling (' + discountState.campCount + ' weeks, 10% off)' : 
            'Sibling Registration (10% off)';
        siblingLine.querySelector('span:first-child').textContent = siblingLabel;
        document.getElementById('orderSibling').textContent = '+$' + (discountState.siblingAmount - discountState.siblingDiscount).toFixed(2);
    }
    
    // Coupon line
    var couponLine = document.getElementById('couponLine');
    if (couponLine) {
        couponLine.style.display = discountState.couponDiscount > 0 ? 'flex' : 'none';
        document.getElementById('orderCoupon').textContent = '-$' + discountState.couponDiscount.toFixed(2);
    }
    
    // Referral line
    var referralLine = document.getElementById('referralLine');
    if (referralLine) {
        referralLine.style.display = discountState.referralDiscount > 0 ? 'flex' : 'none';
        document.getElementById('orderReferral').textContent = '-$' + discountState.referralDiscount.toFixed(2);
    }
    
    // Update Stripe amount - need to update PaymentIntent server-side
    if (window.stripeElements && window.paymentIntentId) {
        // v134: If total is $0 from free code, skip Stripe update and change button
        if (total <= 0 && discountState.couponDiscount > 0) {
            var payEl = document.getElementById('payment-element');
            if (payEl) payEl.style.display = 'none';
            var expressEl = document.getElementById('express-buttons');
            if (expressEl) expressEl.style.display = 'none';
            var submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                var btnText = submitBtn.querySelector('.btn-text') || submitBtn;
                btnText.textContent = 'Complete Free Booking';
            }
            // Show a "free session" message where payment was
            if (payEl && !document.getElementById('freeSessionMsg')) {
                var fMsg = document.createElement('div');
                fMsg.id = 'freeSessionMsg';
                fMsg.style.cssText = 'background:rgba(34,197,94,.08);border:2px solid rgba(34,197,94,.3);border-radius:8px;padding:20px;text-align:center;margin:12px 0;';
                fMsg.innerHTML = '<div style="font-size:24px;margin-bottom:8px">&#10003;</div><div style="font-weight:700;font-size:15px;color:#111">Free Session — No Payment Required</div><div style="font-size:13px;color:#666;margin-top:4px">Click below to confirm your booking</div>';
                payEl.parentNode.insertBefore(fMsg, payEl);
            }
            return;
        } else {
            // Restore payment elements if code was removed
            var payEl = document.getElementById('payment-element');
            if (payEl) payEl.style.display = '';
            var expressEl = document.getElementById('express-buttons');
            if (expressEl) expressEl.style.display = '';
            var fMsg = document.getElementById('freeSessionMsg');
            if (fMsg) fMsg.remove();
        }
        
        // Update local Elements display — amount already updating server-side via PI
        // (elements.update({amount}) only works in deferred/mode setup, not clientSecret)
        
        // v175: Update PaymentIntent amount server-side
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=ptp_update_payment_intent&payment_intent=' + encodeURIComponent(window.paymentIntentId) + '&amount=' + Math.round(total * 100) + '&coupon_discount=' + discountState.couponDiscount + '&referral_discount=' + discountState.referralDiscount + '&nonce=<?php echo wp_create_nonce('ptp_checkout'); ?>'
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.success) {
                console.log('[PTP Checkout] PaymentIntent updated to $' + total.toFixed(2));
            }
        }).catch(function(err) {
            console.error('[PTP Checkout] Failed to update PaymentIntent:', err);
        });
    }
}

// v216: Package Selector Logic
<?php if ($show_package_selector): ?>
(function() {
    var pkgOpts = document.querySelectorAll('.pkg-opt');
    if (!pkgOpts.length) return;
    
    var baseRate = <?php echo intval($rate); ?>;
    var groupMult = <?php echo floatval($group_mult); ?>;
    var groupRate = Math.round(baseRate * groupMult);
    var groupSize = <?php echo intval($group_size); ?>;
    var campSubtotal = <?php echo floatval($camp_price ?? 0); ?>;
    var trainerName = <?php echo json_encode($trainer->display_name); ?>;
    var currentPkg = '<?php echo esc_js($pkg_key); ?>';
    
    // Non-training subtotal (camps etc) 
    var nonTrainingSubtotal = discountState.subtotal - <?php echo floatval($training_price); ?>;
    
    pkgOpts.forEach(function(opt) {
        opt.addEventListener('click', function() {
            var pkg = opt.dataset.pkg;
            var price = parseInt(opt.dataset.price);
            var sessions = parseInt(opt.dataset.sessions);
            var pkgName = opt.dataset.name;
            var save = parseInt(opt.dataset.save);
            
            // Update selection UI
            pkgOpts.forEach(function(o) { o.classList.remove('sel'); });
            opt.classList.add('sel');
            currentPkg = pkg;
            
            // Build item name
            var groupLabel = '';
            <?php if ($group_size > 1): ?>
            var groupLabels = {1:'Solo',2:'Duo',3:'Trio',4:'Quad',5:'5 Players'};
            groupLabel = ' (' + (groupLabels[groupSize] || groupSize + ' Players') + ' - ' + groupSize + ' players)';
            <?php endif; ?>
            var itemName = trainerName + ' - ' + pkgName + groupLabel;
            
            // Update total display in package selector
            var totalEl = document.getElementById('pkgTotalPrice');
            if (totalEl) {
                totalEl.innerHTML = '$' + price + '<span class="pkg-selector-total-per">' + (sessions > 1 ? ' (' + sessions + ' sessions)' : '') + '</span>';
            }
            
            // Update hidden form fields
            var pkgField = document.querySelector('input[name="training_package"]');
            var sessField = document.querySelector('input[name="training_sessions"]');
            var priceField = document.querySelector('input[name="training_price"]');
            var totalField = document.querySelector('input[name="training_total"]');
            if (pkgField) pkgField.value = pkg;
            if (sessField) sessField.value = sessions;
            if (priceField) priceField.value = price;
            if (totalField) totalField.value = price;
            
            // Update order summary — desktop
            var desktopItems = document.querySelectorAll('.order-item');
            desktopItems.forEach(function(item) {
                var typeEl = item.querySelector('.order-item-type');
                if (typeEl && typeEl.textContent.trim() === 'TRAINING') {
                    var nameEl = item.querySelector('.order-item-name');
                    var priceEl = item.querySelector('.order-item-price');
                    if (nameEl) nameEl.textContent = itemName;
                    if (priceEl) priceEl.textContent = '$' + price.toFixed(2);
                }
            });
            
            // Update order summary — mobile drawer
            var mobileItems = document.querySelectorAll('.mobile-cart-item');
            mobileItems.forEach(function(item) {
                var typeEl = item.querySelector('.mobile-cart-item-type');
                if (typeEl && typeEl.textContent.trim() === 'TRAINING') {
                    var nameEl = item.querySelector('.mobile-cart-item-name');
                    var priceEl = item.querySelector('.mobile-cart-item-price');
                    if (nameEl) nameEl.textContent = itemName;
                    if (priceEl) priceEl.textContent = '$' + price.toFixed(2);
                }
            });
            
            // Update discountState subtotal and recalculate everything
            discountState.subtotal = nonTrainingSubtotal + price;
            
            updateTotals();
            
            console.log('[PTP Checkout] Package changed to:', pkg, '— $' + price);
        });
    });
})();
<?php endif; ?>

// Coupon validation (only if camps in cart)
var applyCouponBtn = document.getElementById('applyCoupon');
if (applyCouponBtn) {
    applyCouponBtn.onclick = function() {
        var code = document.getElementById('couponCode').value.trim().toUpperCase();
        if (!code) return;
        
        this.textContent = '...';
        this.disabled = true;
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=ptp_validate_coupon&code=' + encodeURIComponent(code) + '&amount=' + discountState.subtotal + '&item_type=training&nonce=<?php echo wp_create_nonce('ptp_checkout'); ?>'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data.valid) {
                discountState.couponDiscount = parseFloat(data.data.discount);
                document.getElementById('couponDiscount').value = discountState.couponDiscount;
                // v175: Store coupon_id and free_code_id for tracking
                if (data.data.coupon_id) {
                    document.getElementById('couponId').value = data.data.coupon_id;
                }
                if (data.data.free_code_id) {
                    var freeCodeEl = document.getElementById('freeCodeId');
                    if (freeCodeEl) freeCodeEl.value = data.data.free_code_id;
                }
                document.getElementById('couponApplied').innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M20 6L9 17l-5-5"/></svg>' + data.data.message;
                document.getElementById('couponApplied').style.display = 'flex';
                document.getElementById('couponError').style.display = 'none';
                document.getElementById('couponCode').disabled = true;
                document.getElementById('applyCoupon').style.display = 'none';
                var couponLabel = document.getElementById('couponLabel');
                if (couponLabel) couponLabel.textContent = 'Promo: ' + code;
                updateTotals();
            } else {
                document.getElementById('couponError').textContent = data.data.message || 'Invalid code';
                document.getElementById('couponError').style.display = 'block';
                document.getElementById('applyCoupon').textContent = 'Apply';
                document.getElementById('applyCoupon').disabled = false;
            }
        });
    };
}

// v175: Free session code validation (training only)
var applyFreeSessionBtn = document.getElementById('applyFreeSession');
if (applyFreeSessionBtn) {
    applyFreeSessionBtn.onclick = function() {
        var code = document.getElementById('freeSessionCode').value.trim().toUpperCase();
        if (!code) return;
        
        this.textContent = '...';
        this.disabled = true;
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=ptp_validate_coupon&code=' + encodeURIComponent(code) + '&amount=' + discountState.subtotal + '&item_type=training&nonce=<?php echo wp_create_nonce('ptp_checkout'); ?>'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data.valid && data.data.type === 'free_training') {
                discountState.couponDiscount = parseFloat(data.data.discount);
                document.getElementById('couponDiscount').value = discountState.couponDiscount;
                var freeCodeEl = document.getElementById('freeCodeId');
                if (freeCodeEl && data.data.free_code_id) {
                    freeCodeEl.value = data.data.free_code_id;
                }
                // v215: Also store coupon_id for ptp_coupons-based free codes
                if (data.data.coupon_id) {
                    var couponIdEl = document.getElementById('couponId');
                    if (couponIdEl) couponIdEl.value = data.data.coupon_id;
                }
                document.getElementById('freeSessionApplied').innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M20 6L9 17l-5-5"/></svg>' + data.data.message;
                document.getElementById('freeSessionApplied').style.display = 'flex';
                document.getElementById('freeSessionError').style.display = 'none';
                document.getElementById('freeSessionCode').disabled = true;
                document.getElementById('applyFreeSession').style.display = 'none';
                // Hide the other discount boxes since free session covers it
                var promoContent = document.getElementById('promoContentTraining');
                if (promoContent) promoContent.closest('.discount-box').style.display = 'none';
                var couponLabel = document.getElementById('couponLabel');
                if (couponLabel) couponLabel.textContent = 'Free Session: ' + code;
                updateTotals();
            } else if (data.success && data.data.valid) {
                // Valid coupon but not a free session - apply as regular coupon
                discountState.couponDiscount = parseFloat(data.data.discount);
                document.getElementById('couponDiscount').value = discountState.couponDiscount;
                document.getElementById('freeSessionApplied').innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M20 6L9 17l-5-5"/></svg>' + data.data.message;
                document.getElementById('freeSessionApplied').style.display = 'flex';
                document.getElementById('freeSessionError').style.display = 'none';
                document.getElementById('freeSessionCode').disabled = true;
                document.getElementById('applyFreeSession').style.display = 'none';
                updateTotals();
            } else {
                document.getElementById('freeSessionError').textContent = data.data.message || 'Invalid code';
                document.getElementById('freeSessionError').style.display = 'block';
                document.getElementById('applyFreeSession').textContent = 'Apply';
                document.getElementById('applyFreeSession').disabled = false;
            }
        });
    };
}

// Referral validation
var applyReferralBtn = document.getElementById('applyReferral');
if (applyReferralBtn) {
    applyReferralBtn.onclick = function() {
        var code = document.getElementById('referralCode').value.trim().toUpperCase();
        if (!code) return;
        
        this.textContent = '...';
        this.disabled = true;
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=ptp_validate_referral&code=' + encodeURIComponent(code) + '&nonce=<?php echo wp_create_nonce('ptp_checkout'); ?>'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.data.valid) {
                discountState.referralDiscount = parseFloat(data.data.discount);
                document.getElementById('referralDiscount').value = discountState.referralDiscount;
                // v175: Store referral_id for tracking
                if (data.data.referral_id) {
                    document.getElementById('referralId').value = data.data.referral_id;
                }
                // v193: Add hidden backup field so referral_code submits (disabled fields don't submit)
                var rcHidden = document.getElementById('referralCodeHidden');
                if (!rcHidden) {
                    rcHidden = document.createElement('input');
                    rcHidden.type = 'hidden';
                    rcHidden.name = 'referral_validated';
                    rcHidden.id = 'referralCodeHidden';
                    document.getElementById('referralCode').parentNode.appendChild(rcHidden);
                }
                rcHidden.value = code;
                document.getElementById('referralApplied').innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M20 6L9 17l-5-5"/></svg>' + data.data.message;
                document.getElementById('referralApplied').style.display = 'flex';
                document.getElementById('referralError').style.display = 'none';
                document.getElementById('referralCode').readOnly = true;
                document.getElementById('referralCode').style.opacity = '0.6';
                document.getElementById('applyReferral').style.display = 'none';
                updateTotals();
            } else {
                document.getElementById('referralError').textContent = data.data.message || 'Invalid code';
                document.getElementById('referralError').style.display = 'block';
                document.getElementById('applyReferral').textContent = 'Apply';
                document.getElementById('applyReferral').disabled = false;
            }
        });
    };
}

// v134: Auto-apply free session code from URL (?free_code=PTP-XXXXXX)
(function() {
    var urlParams = new URLSearchParams(window.location.search);
    var freeCode = urlParams.get('free_code');
    if (!freeCode) return;
    
    freeCode = freeCode.trim().toUpperCase();
    var codeField = document.getElementById('freeSessionCode');
    var applyBtn = document.getElementById('applyFreeSession');
    
    if (codeField && applyBtn) {
        // Fill and auto-click with slight delay to ensure DOM is ready
        codeField.value = freeCode;
        setTimeout(function() {
            applyBtn.click();
        }, 500);
    } else {
        // Free session field might not exist (camp-only checkout)
        // Try the regular coupon field instead
        var couponField = document.getElementById('couponCode');
        var couponBtn = document.getElementById('applyCoupon');
        if (couponField && couponBtn) {
            couponField.value = freeCode;
            setTimeout(function() {
                couponBtn.click();
            }, 500);
        }
    }
})();

/**
 * Remove item from cart during checkout
 * v168: Allows users to remove items from checkout order summary
 */
function removeCheckoutItem(cartKey, itemType) {
    if (!cartKey) return;
    
    var confirmMsg = itemType === 'camp' 
        ? 'Remove this camp from your order?' 
        : 'Remove this training session from your order?';
    
    if (!confirm(confirmMsg)) return;
    
    // v228: Helper — check if URL still has training params (training survives even if native cart is empty)
    function urlHasTraining(u) {
        return u.searchParams.has('trainer_id') && u.searchParams.has('package');
    }
    
    // v228: Helper — strip camp-related URL params (handles both ?camp= and ?camps= with comma lists)
    function stripCampFromUrl(u, campId) {
        u.searchParams.delete('camp');
        // Handle ?camps=X,Y,Z — remove just this camp from the list
        var campsParam = u.searchParams.get('camps');
        if (campsParam) {
            var campIds = campsParam.split(',').filter(function(id) { return id && id !== String(campId); });
            if (campIds.length > 0) {
                u.searchParams.set('camps', campIds.join(','));
            } else {
                u.searchParams.delete('camps');
            }
        }
        return u;
    }
    
    // v218: Handle URL-param training items (key starts with 'training_url_')
    if (cartKey.indexOf('training_url_') === 0) {
        var url = new URL(window.location);
        url.searchParams.delete('trainer_id');
        url.searchParams.delete('package');
        url.searchParams.delete('date');
        url.searchParams.delete('time');
        url.searchParams.delete('location');
        url.searchParams.delete('location_address');
        url.searchParams.delete('location_lat');
        url.searchParams.delete('location_lng');
        url.searchParams.delete('group_size');
        url.searchParams.delete('group_session_id');
        // If no other items remain (no camp param, no native cart), go to cart page
        var hasCampParam = url.searchParams.has('camp') || url.searchParams.has('camps');
        // Animate out
        var itemEl = document.querySelector('[data-cart-key="' + cartKey + '"]');
        if (itemEl) { itemEl.style.transition = 'all 0.3s'; itemEl.style.opacity = '0'; itemEl.style.transform = 'translateX(-20px)'; }
        setTimeout(function() {
            // Also remove from native cart via AJAX if it was persisted there
            var fd = new FormData();
            fd.append('action', 'ptp_remove_cart_item');
            fd.append('cart_key', 'training_' + cartKey.replace('training_url_', ''));
            fd.append('item_type', 'training');
            fd.append('nonce', '<?php echo wp_create_nonce('ptp_cart_action'); ?>');
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
            window.location.href = hasCampParam ? url.toString() : '<?php echo home_url('/ptp-cart/'); ?>';
        }, 300);
        return;
    }
    
    // v228: Handle URL-param camp items (key starts with 'camp_url_')
    if (cartKey.indexOf('camp_url_') === 0) {
        var campId = cartKey.replace('camp_url_', '');
        var url = new URL(window.location);
        url = stripCampFromUrl(url, campId);
        // Animate out
        var itemEl = document.querySelector('[data-cart-key="' + cartKey + '"]');
        if (itemEl) { itemEl.style.transition = 'all 0.3s'; itemEl.style.opacity = '0'; itemEl.style.transform = 'translateX(-20px)'; }
        setTimeout(function() {
            // Also remove from native cart if it was persisted
            var fd = new FormData();
            fd.append('action', 'ptp_remove_cart_item');
            fd.append('cart_key', cartKey);
            fd.append('item_type', 'camp');
            fd.append('nonce', '<?php echo wp_create_nonce('ptp_cart_action'); ?>');
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
            // v228: Check if training still in URL — don't go to empty cart if it is
            if (urlHasTraining(url) || url.searchParams.has('camps')) {
                window.location.href = url.toString();
            } else {
                window.location.href = '<?php echo home_url('/ptp-cart/'); ?>';
            }
        }, 300);
        return;
    }
    
    // Show loading state
    var itemEl = document.querySelector('[data-cart-key="' + cartKey + '"]');
    if (itemEl) {
        itemEl.style.opacity = '0.5';
        itemEl.style.pointerEvents = 'none';
    }
    
    var formData = new FormData();
    formData.append('action', 'ptp_remove_cart_item');
    formData.append('cart_key', cartKey);
    formData.append('item_type', itemType);
    formData.append('nonce', '<?php echo wp_create_nonce('ptp_cart_action'); ?>');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(res) {
        if (res.success) {
            // Animate out the item
            if (itemEl) {
                itemEl.style.transition = 'all 0.3s ease';
                itemEl.style.opacity = '0';
                itemEl.style.transform = 'translateX(-20px)';
                itemEl.style.maxHeight = '0';
                itemEl.style.padding = '0';
                itemEl.style.margin = '0';
                itemEl.style.overflow = 'hidden';
            }
            
            // Check if cart is now empty or redirect to cart page
            setTimeout(function() {
                var remaining = res.data.cart_count || 0;
                // v228: Also strip URL params for the removed type
                var url = new URL(window.location);
                if (itemType === 'training') {
                    url.searchParams.delete('trainer_id');
                    url.searchParams.delete('package');
                    url.searchParams.delete('date');
                    url.searchParams.delete('time');
                    url.searchParams.delete('location');
                    url.searchParams.delete('location_address');
                    url.searchParams.delete('location_lat');
                    url.searchParams.delete('location_lng');
                    url.searchParams.delete('group_size');
                } else if (itemType === 'camp') {
                    // v228: Strip both camp and camps params; for camps=X,Y remove just this item
                    var removedId = (document.querySelector('[data-cart-key="' + cartKey + '"]') || {}).dataset;
                    var campItemId = removedId ? (removedId.itemId || '') : '';
                    url = stripCampFromUrl(url, campItemId);
                }
                // v228: Don't redirect to empty cart if training still exists via URL params
                if (remaining === 0 && !urlHasTraining(url) && !url.searchParams.has('camps')) {
                    window.location.href = '<?php echo home_url('/ptp-cart/'); ?>';
                } else {
                    window.location.href = url.toString();
                }
            }, 400);
        } else {
            if (itemEl) {
                itemEl.style.opacity = '1';
                itemEl.style.pointerEvents = '';
            }
            alert('Could not remove item: ' + (res.data && res.data.message ? res.data.message : 'Please try again'));
        }
    })
    .catch(function(err) {
        if (itemEl) {
            itemEl.style.opacity = '1';
            itemEl.style.pointerEvents = '';
        }
        alert('Connection error. Please try again.');
    });
}
</script>
<?php endif; ?>
<?php endif; ?>

<script>
// Abandoned cart capture for training checkout
(function(){
    var captured = false;
    var emailField = document.querySelector('input[name="parent_email"]');
    if (!emailField) return;

    function captureAbandonedCart() {
        if (captured) return;
        var email = emailField.value.trim();
        if (!email || email.indexOf('@') === -1) return;

        var fd = new FormData();
        fd.append('action', 'ptp_camps_capture_email');
        fd.append('email', email);
        fd.append('first_name', (document.querySelector('input[name="parent_first_name"]') || {}).value || '');
        fd.append('last_name', (document.querySelector('input[name="parent_last_name"]') || {}).value || '');
        fd.append('phone', (document.querySelector('input[name="parent_phone"]') || {}).value || '');
        // Build cart data from items — check both desktop (.order-item) and mobile (.mobile-cart-item)
        var cartItems = [];
        document.querySelectorAll('.order-item[data-item-type], .mobile-cart-item[data-item-type]').forEach(function(el) {
            var itemType = el.dataset.itemType || 'product';
            // Don't duplicate — mobile and desktop show same items
            var name = el.dataset.itemName || (el.querySelector('.order-item-name, .mobile-cart-item-name, h4') || {}).textContent || '';
            if (name && !cartItems.some(function(ci) { return ci.name === name; })) {
                cartItems.push({
                    id: el.dataset.itemId || el.dataset.cartKey || '',
                    type: itemType,
                    item_type: itemType,
                    name: name,
                    price: el.dataset.itemPrice || '',
                    metadata: {
                        stripe_product: el.dataset.stripeProduct || '',
                        stripe_product_id: el.dataset.stripeProduct || '',
                        trainer_id: el.dataset.trainerId || '',
                        date: el.dataset.itemDate || '',
                        location: el.dataset.itemLocation || '',
                        time: el.dataset.itemTime || ''
                    }
                });
            }
        });
        // If no items found via data attributes, build from PHP-known data
        if (!cartItems.length) {
            <?php
            $capture_items = array();
            foreach (($all_items ?? array()) as $ci) {
                $meta = $ci['metadata'] ?? array();
                $capture_items[] = array(
                    'id'        => $ci['id'] ?? ($ci['item_id'] ?? ($ci['cart_key'] ?? '')),
                    'type'      => $ci['type'] ?? 'product',
                    'item_type' => $ci['type'] ?? 'product',
                    'name'      => $ci['name'] ?? '',
                    'price'     => $ci['price'] ?? ($ci['line_total'] ?? 0),
                    'metadata'  => array(
                        'stripe_product'    => $meta['stripe_product'] ?? ($meta['stripe_product_id'] ?? ''),
                        'stripe_product_id' => $meta['stripe_product'] ?? ($meta['stripe_product_id'] ?? ''),
                        'trainer_id'        => $meta['trainer_id'] ?? ($ci['trainer_id'] ?? ''),
                        'date'              => $meta['camp_dates'] ?? ($ci['date'] ?? ''),
                        'location'          => $meta['camp_location'] ?? ($ci['location'] ?? ''),
                        'time'              => $meta['camp_time'] ?? ($ci['time'] ?? ''),
                    ),
                );
            }
            ?>
            cartItems = <?php echo wp_json_encode($capture_items); ?>;
        }
        fd.append('cart_data', JSON.stringify({items: cartItems}));
        fd.append('cart_total', '<?php echo esc_js($total); ?>');
        fd.append('source', cartItems.some(function(i){ return i.type === 'training' || i.type === 'session'; }) ? 'training' : 'camps');

        fetch('<?php echo admin_url("admin-ajax.php"); ?>', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d.success) captured = true; })
            .catch(function(){});
    }

    // For logged-in users with hidden email field, fire immediately
    if (emailField.type === 'hidden' && emailField.value) {
        captureAbandonedCart();
    } else {
        emailField.addEventListener('blur', captureAbandonedCart);
        var phoneField = document.querySelector('input[name="parent_phone"]');
        if (phoneField) phoneField.addEventListener('focus', captureAbandonedCart);
    }
})();
</script>

</body>
</html>
