<?php
/**
 * Plugin Name: PTP Training Platform
 * Plugin URI: https://ptpsummercamps.com
 * Description: Complete soccer training platform — camps, 1-on-1 training marketplace, booking, payments, dashboards. Unified build with MasterClass v176 integration.
 * Version: 242.0
 * Author: PTP Soccer Camps
 * Author URI: https://ptpsummercamps.com
 * Text Domain: ptp-training
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * V235.9 Changes (Trainer UX):
 * - ONBOARDING: Welcome text now says "Just 3 things: photo, rate, schedule. Takes under 5 minutes."
 * - ONBOARDING: Availability presets — "Weekday Evenings", "Weekends Only", "Every Day 4-8pm" one-tap buttons
 * - DASHBOARD: Availability presets added to Schedule tab too (same 3 options + "hit Save" reminder)
 * - DASHBOARD: Session recap nudge on Home tab — "X Sessions Need a Training Plan" with 30-second AI pitch
 * - DASHBOARD: Mentorship now surfaces in bottom nav (5th slot) when trainer has active mentees or pipeline
 * - DASHBOARD: Gear icon in header gives access to More menu when mentorship takes the nav slot
 * - DASHBOARD: Mentorship hidden from More sheet when already in bottom nav (no duplicate)
 *
 * V235.8 Changes (Mobile Performance):
 * - SPEED: Google Maps API deferred — loads via IntersectionObserver when map scrolls near viewport (saves ~200KB on initial load)
 * - SPEED: Inline critical CSS for above-fold (hero, nav, stats) — instant first paint without external CSS
 * - SPEED: Font preconnect/preload hints at wp_head priority 0
 * - SPEED: CSS containment on hero, stats, layout, booking panel — reduces repaint scope
 * - SPEED: will-change:auto by default, only activates during touch interaction on booking panel
 * - SPEED: Hero image has width/height attributes (prevents layout shift) + mobile-optimized sizes
 * - SPEED: Touch hover effects removed on mobile (no onmouseover/onmouseout)
 * - SPEED: Booking bar uses backdrop-filter blur for premium feel + lighter repaint
 * - SPEED: Map section has placeholder background while loading
 * - SPEED: Hero height reduces to 260px on very short screens (max-height:600px)
 * - UX: Touch targets minimum 44px on all interactive elements (nav, packages, groups, chips, CTAs)
 * - UX: Active state feedback on tap (.85 opacity on press)
 * - UX: Overscroll containment on booking panel (no rubber-band)
 * - UX: Trainer name clamped to 2 lines on mobile (prevents overflow)
 * - UX: prefers-reduced-motion disables all transitions
 *
 * V235.7 Changes (Mentorship Flow + Safety):
 * - FIX: "View Your Mentorship" CTA now links to /parent-dashboard/#mentorship (was /dashboard/)
 * - FIX: Mentorship package prices now show /session (was incorrectly showing /wk)
 * - FIX: Single session package now displays on trainer profile (was filtered out)
 * - FIX: Package cards show session length (30/45/60 min) and "TRY IT" badge for single
 * - FIX: Intro schedule handler accepts 'interest' OR 'intro_scheduled' status (was rejecting re-clicks)
 * - FIX: Complete intro handler accepts broader status range (idempotent)
 * - FIX: Both handlers check if mentorship tables exist before querying (create if missing)
 * - SAFETY: Parent consent form with 3 checkboxes (participate, supervise under-13, terms)
 * - SAFETY: Age validation 6-18 client + server side
 * - SAFETY: parent_consent_at timestamp stored on mentorship pair record
 * - SAFETY: Session scheduling capped at max 3 per mentee per week
 * - SAFETY: Session duration capped at 90 minutes
 * - SAFETY: Cannot schedule sessions in the past
 * - SAFETY: Signup footer notes all sessions recorded, coaches background-checked
 *
 * V235.6 Changes (Package/Group/Email Completeness):
 * - NEW: group_size column added to ptp_bookings table (schema + migration)
 * - FIX: Unified checkout now writes group_size to booking row
 * - FIX: Training thankyou now writes group_size + package_type + total_sessions to booking row
 * - FIX: Cart remove handles camp_url_X and training_url_X key formats
 * - EMAIL: Parent confirmation shows package name + session count for multi-session packages
 * - EMAIL: Payment section shows per-session price breakdown
 * - EMAIL: Trainer notification includes package_name, total_sessions, group_size
 *
 * V235.3 Changes (Mobile Profile Fix):
 * - FIX: Removed bare .nav-links, .entry-meta, .sidebar, #secondary, #comments from Astra kill CSS
 *   These generic selectors were hiding trainer profile content on mobile
 * - FIX: JS cleanup now checks el.closest('.tp210-wrap') before removing — won't touch profile content
 * - FIX: LiteSpeed Cache bypass headers sent from both handler and template
 *   Hostinger was caching separate mobile/desktop versions, mobile had stale broken page
 * - FIX: Added X-LiteSpeed-Cache-Control: no-cache + LSCACHE_NO_CACHE constant
 * - FIX: Added Vary: User-Agent header and no-cache meta tags in <head>
 *
 * V235.2 Changes (Trainer Profile Fix + Mobile Speed):
 * - FIX: Astra header/chevron/footer removed via remove_all_actions on 12 Astra hooks before get_header()
 * - FIX: Inline CSS injected at wp_head priority 1 hides Astra elements before external CSS loads
 * - FIX: JS cleanup removes any Astra elements that survive PHP removal
 * - FIX: Post navigation filters return empty string (previous_post_link, next_post_link)
 * - FIX: Version change auto-flushes rewrite rules + all trainer caches
 * - NEW: Admin Tools > "Regenerate All Trainer Profiles" — re-slugs, flushes caches, flushes rewrites
 * - SPEED: Dequeue 5 unused global CSS files on trainer profile (v216, responsive, mobile, universal, google-fonts)
 * - SPEED: DM Sans font request trimmed (removed italic/optical-size variants)
 * - SPEED: Cache-busted CSS/JS URLs via PTP_VERSION query string
 *
 * V235.1 Changes (Stability Audit + Webhook Recovery):
 * - FIX: checkout-v71.php template include guarded with file_exists + fallback to ptp-checkout.php
 * - FIX: thank-you.php fallback in unified-checkout-handler guarded with file_exists + inline fallback
 * - AUDIT: All 251 PHP files pass syntax check (PHP 8.3)
 * - AUDIT: All 175 include files verified present
 * - AUDIT: All AJAX handlers verified with nonce checks
 * - AUDIT: All shortcode-to-page mappings verified matching
 * - AUDIT: All 42 template references verified present
 * - AUDIT: Booking flow has transactions, conflict detection, auto-repair
 * - AUDIT: Stripe webhook verification, customer lookup, PI creation all guarded
 * - AUDIT: Checkout pre-fills parent data + saved players for logged-in users
 * - FIX: PI metadata now includes customer_email/customer_name/customer_phone for webhook recovery
 * - FIX: Webhook tier-3 backup now creates parent record from PI customer data (booking no longer orphaned)
 *
 * V223 Changes (Money Model Wiring — CAC Paydown Engine):
 * - LOADED: class-ptp-camp-conversion.php (thank-you mentorship CTA + post-camp 3-touch sequence)
 * - INITIALIZED: PTP_Camp_Conversion — camp→mentorship conversion at thank-you + Day 2/5/10 drip
 * - INITIALIZED: PTP_Viral_Engine — referral tracking, share prompts, social proof, $25/$20% rewards
 * - INITIALIZED: PTP_Crosssell_Engine — bundle discounts, package upgrades, post-booking upsells
 * - INITIALIZED: PTP_Camp_Crosssell_Everywhere — camp offers at every training touchpoint
 * - INITIALIZED: PTP_Referral_System — referral codes, conversion tracking, credit rewards
 * - INITIALIZED: PTP_Abandoned_Cart_Recovery — cart abandonment email recovery sequences
 * - INITIALIZED: PTP_Growth — bundle/sibling discounts, waitlists, social proof
 * - All 9 classes initialized in BOTH immediate and deferred tier2 paths
 * - Webhook handlers already self-discriminate (no changes needed)
 *
 * V222 Changes (Date Normalization + Upgrade Safety):
 * - ptp_normalize_session_date() helper: handles display formats (February 22, 2026) → Y-m-d for DB
 * - Checkout date normalization: cart display dates now properly stored in DB format
 * - Transient TTL extended from 5min/15min to 2hrs for thank-you page reliability
 * - Safe upgrade path: all trainers, databases, and settings preserved from v221
 *
 * V221.0 Changes (Security Hardening + Cleanup):
 * - SQL injection fix: LIMIT/OFFSET now use prepare() in camp-orders, camp-admin, camp-checkout
 * - Added uninstall.php for clean plugin removal (tables, options, cron, roles, transients, user meta)
 * - Archived dead templates to templates/deprecated/ (parent-dashboard-v117, trainer-profile-v2, thank-you)
 * - Updated template version mappings in admin-tools and fixes to match active templates
 * - Updated readme.txt changelog
 *
 * V220.0 Changes (Abandoned Cart + Thank-You + Free Checkout Fixes):
 * - Abandoned cart recovery: mark carts recovered on successful booking/checkout/order
 * - Abandoned cart recovery: cross-table email dedup (camp + training sequences)
 * - Abandoned cart recovery: booking-exists guard prevents emails to confirmed customers
 * - Abandoned cart recovery: proper is_email() validation on capture
 * - Thank-you page: find_booking_id() now reads ?booking= param (free checkout)
 * - Thank-you page: 3-tier data fallback (booking → thankyou transient → checkout transient)
 * - Free checkout: POST field enrichment for missing session_date/time/trainer
 * - Free checkout: transient preserved for thank-you page instead of deleted
 * - Free checkout: explicit email sending (parent + trainer confirmation)
 * - Trainer email: null-safe date/time formatting (no more Jan 1, 1970)
 * - Coupon tracker: hardcoded FREETRAINING fallback + self-healing seed
 * - Coupon tracker: regex fix for FREE- vs FREETRAINING usage tracking
 *
 * V177.0.1 Changes (Security Hardening):
 * - Payment verification hardening (defensive restrictions)
 * - Public PII exposure removed via allowlists
 * - Webhooks fail-closed when secrets missing
 * - Stripe Smart API restricted to admins
 * - Tracking endpoints gated by nonce and rate limiting
 * - Apple Sign-In disabled until full signature verification is implemented
 * - Added class-ptp-security.php and class-ptp-logger.php for enhanced security
 *
 * V176.0.0 Changes (Unified MasterClass Build):
 * - Merged ptp-masterclass-v176 into ptp-training-v173 (single plugin)
 * - v176 trainer profile, training landing, apply, shortcodes, database, AJAX, fixes
 * - Completely rewritten /ptp-cart with integrated Stripe checkout
 * - Cart page now handles full checkout flow (no separate checkout page needed)
 * - Apple Pay / Google Pay express checkout on cart page
 * - Security patches, redirect-loop fixes, auth guards, nav-bar additions
 * - Database auto-repair on activation
 */

defined('ABSPATH') || exit;

// Suppress PHP notices/warnings during REST API requests to prevent JSON corruption
// This fixes Action Scheduler and similar plugins that output notices too early
// NOTE: Suppressed errors are still captured by PTP_Monitor when available
if (defined('REST_REQUEST') || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false)) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        @error_reporting(E_ERROR | E_PARSE);
    }
}

// v200.1: Suppress Action Scheduler "called too early" notices on ALL pages
// These are timing-related warnings from WooCommerce/Action Scheduler that don't affect functionality
// Previously only suppressed on thank-you pages (v168.5), but also fires on trainer profiles etc.
add_filter('doing_it_wrong_trigger_error', function($trigger, $function) {
    if (strpos($function, 'as_') === 0) {
        return false; // Suppress Action Scheduler timing warnings
    }
    return $trigger;
}, 10, 2);

// Plugin constants - check if already defined to avoid conflicts
if (!defined('PTP_VERSION')) {
    define('PTP_VERSION', '242.1');
}
if (!defined('PTP_DEBUG')) {
    define('PTP_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
}
if (!defined('PTP_PLUGIN_FILE')) {
    define('PTP_PLUGIN_FILE', __FILE__);
}
if (!defined('PTP_PLUGIN_DIR')) {
    define('PTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('PTP_PLUGIN_PATH')) {
    define('PTP_PLUGIN_PATH', plugin_dir_path(__FILE__));
}
if (!defined('PTP_PLUGIN_URL')) {
    define('PTP_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('PTP_PLUGIN_BASENAME')) {
    define('PTP_PLUGIN_BASENAME', plugin_basename(__FILE__));
}
if (!defined('PTP_PLATFORM_FEE_PERCENT_DEFAULT')) {
    define('PTP_PLATFORM_FEE_PERCENT_DEFAULT', 0.20);
}

/**
 * v223.1: Centralized platform fee helper.
 * Returns the admin-configurable fee as a decimal (e.g. 0.20 for 20%).
 *
 * FEE REFERENCE (all expressed as decimals):
 *   Context              | Source                               | Rate
 *   ---------------------|--------------------------------------|------
 *   1-on-1 bookings      | ptp_get_platform_fee() (this)        | 0.20  (admin option ptp_platform_fee)
 *   Payments (legacy)    | PTP_Payments::PLATFORM_FEE_PERCENT   | 25    (NOTE: divided by 100 at usage site)
 *   Subscriptions        | PTP_Subscriptions::PLATFORM_FEE_PERCENT | 0.15
 *   Mentorship           | PTP_Mentorship::PLATFORM_FEE         | 0.20
 *   Escrow (graduated)   | PTP_Escrow::FEE_SCHEDULE             | 0.50→0.25→0.15
 *
 * TODO: Migrate PTP_Payments::PLATFORM_FEE_PERCENT from 25 (int) to 0.25 (decimal)
 *       for consistency, then update calculate_split() to remove the /100 division.
 */
if (!function_exists('ptp_get_platform_fee')) {
    function ptp_get_platform_fee() {
        return floatval(get_option('ptp_platform_fee', 20)) / 100;
    }
}

// v200.1: Sync Google Maps API key between the two option names
// Admin saves to ptp_google_maps_key, Google Reviews saves to ptp_google_maps_api_key
add_action('updated_option', function($option, $old, $new) {
    if ($option === 'ptp_google_maps_key' && $new) {
        update_option('ptp_google_maps_api_key', $new);
    } elseif ($option === 'ptp_google_maps_api_key' && $new) {
        update_option('ptp_google_maps_key', $new);
    }
}, 10, 3);

/**
 * Get platform fee as percentage (e.g., 20 for 20%)
 */
if (!function_exists('ptp_get_platform_fee_percent')) {
    function ptp_get_platform_fee_percent() {
        return floatval(get_option('ptp_platform_fee', 20));
    }
}

/**
 * Get currency code
 */
if (!function_exists('ptp_get_currency')) {
    function ptp_get_currency() {
        return strtolower(get_option('ptp_currency', 'usd'));
    }
}

/**
 * v220: Normalize a session time to 24-hour H:i:s format.
 * Handles: "1:00 PM", "13:00", "1:00", "1", "1pm", "13:00:00", etc.
 * Training sessions are 7AM-9PM, so bare hours 1-6 are assumed PM.
 */
if (!function_exists('ptp_normalize_session_time')) {
    function ptp_normalize_session_time($time) {
        if (empty($time)) return '';
        $time = trim($time);
        
        // Already valid 24hr H:i:s
        if (preg_match('/^([01]?\d|2[0-3]):\d{2}:\d{2}$/', $time)) {
            $h = intval(explode(':', $time)[0]);
            // Training doesn't happen at 1-6 AM — assume PM if bare 24hr looks wrong
            // But only if it's truly ambiguous (01:00:00 could be 1 AM or 1 PM)
            if ($h >= 1 && $h <= 6) {
                return sprintf('%02d:%s', $h + 12, substr($time, strpos($time, ':') + 1));
            }
            return $time;
        }
        
        // Has AM/PM indicator — use strtotime
        if (preg_match('/[aApP][mM]/', $time)) {
            $ts = strtotime($time);
            if ($ts !== false) return date('H:i:s', $ts);
        }
        
        // H:i format (e.g. "13:00" or "1:00")
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            $h = intval($m[1]);
            $min = $m[2];
            // Hours 1-6 without AM/PM are almost certainly PM for training
            if ($h >= 1 && $h <= 6) $h += 12;
            return sprintf('%02d:%s:00', $h, $min);
        }
        
        // Bare number (e.g. "1", "13")
        if (preg_match('/^(\d{1,2})$/', $time, $m)) {
            $h = intval($m[1]);
            if ($h >= 1 && $h <= 6) $h += 12;
            return sprintf('%02d:00:00', $h);
        }
        
        // Fallback: try strtotime
        $ts = strtotime($time);
        if ($ts !== false) return date('H:i:s', $ts);
        
        return '';
    }
}

/**
 * Normalize session date to Y-m-d format for DB storage.
 * Handles display formats like "February 22, 2026" → "2026-02-22"
 * as well as raw dates that are already in Y-m-d.
 *
 * @since 222
 * @param string $date The date string in any recognizable format.
 * @return string Date in Y-m-d format, or empty string on failure.
 */
if (!function_exists('ptp_normalize_session_date')) {
    function ptp_normalize_session_date($date) {
        if (empty($date)) return '';
        $date = trim($date);
        
        // Already valid Y-m-d
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        // Try common display formats
        $formats = array('F j, Y', 'M j, Y', 'l, F j, Y', 'D, M j, Y', 'n/j/Y', 'm/d/Y');
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $date);
            if ($dt) {
                return $dt->format('Y-m-d');
            }
        }
        
        // Fallback: strtotime
        $ts = strtotime($date);
        if ($ts && $ts > 0 && intval(date('Y', $ts)) >= 2020) {
            return date('Y-m-d', $ts);
        }
        
        return '';
    }
}

/**
 * Get centralized brand/contact values for emails.
 * All email templates should call this instead of hardcoding.
 *
 * @since 221.0
 * @param string|null $key  Optional specific key to return. Null returns full array.
 * @return mixed
 */
if (!function_exists('ptp_email_brand')) {
    function ptp_email_brand($key = null) {
        static $brand = null;
        if ($brand === null) {
            $site_url      = home_url();
            $from_email    = get_option('ptp_from_email', get_option('admin_email'));
            $support_email = get_option('ptp_email_support_email', $from_email);
            $support_phone = get_option('ptp_email_support_phone', get_option('ptp_contact_phone', ''));
            $company       = get_option('ptp_company_name', 'PTP Soccer');
            $tagline       = get_option('ptp_tagline', 'Players Teaching Players');
            $logo_url      = get_option('ptp_email_logo_url', get_option('ptp_email_logo', ''));
            if (empty($logo_url)) {
                $custom_logo_id = get_theme_mod('custom_logo');
                if ($custom_logo_id) {
                    $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
                }
            }
            $instagram     = get_option('ptp_instagram_handle', get_option('ptp_instagram', 'ptp.training'));
            $instagram     = ltrim($instagram, '@');

            $brand = array(
                'company'        => $company,
                'tagline'        => $tagline,
                'site_url'       => $site_url,
                'logo_url'       => $logo_url,
                'from_email'     => $from_email,
                'support_email'  => $support_email,
                'support_phone'  => $support_phone,
                'instagram'      => $instagram,
                'from_training'  => "{$company} <{$from_email}>",
                'from_camps'     => "{$company} Camps <{$from_email}>",
                'year'           => date('Y'),
            );
        }
        if ($key !== null) {
            return $brand[$key] ?? '';
        }
        return $brand;
    }
}

/**
 * Get currency symbol
 */
if (!function_exists('ptp_get_currency_symbol')) {
    function ptp_get_currency_symbol() {
        $symbols = array(
            'usd' => '$', 'eur' => '€', 'gbp' => '£',
            'cad' => 'C$', 'aud' => 'A$', 'jpy' => '¥'
        );
        return $symbols[ptp_get_currency()] ?? '$';
    }
}

/**
 * Debug logger — only writes when PTP_DEBUG is on.
 * Drop-in replacement for error_log() across the plugin.
 */
if (!function_exists('ptp_log')) {
    function ptp_log($message) {
        if (defined('PTP_DEBUG') && PTP_DEBUG) {
            error_log($message);
        }
    }
}

/**
 * Format price with currency
 */
if (!function_exists('ptp_format_price')) {
    function ptp_format_price($amount) {
        return ptp_get_currency_symbol() . number_format(floatval($amount), 2);
    }
}

/**
 * Get cart instance
 */
if (!function_exists('ptp_cart')) {
    function ptp_cart() {
        return PTP_Native_Cart::instance();
    }
}

/**
 * Get session instance
 */
if (!function_exists('ptp_session')) {
    function ptp_session() {
        return PTP_Native_Session::instance();
    }
}

/**
 * Check if running in native mode (always true now - WC removed)
 */
if (!function_exists('ptp_is_native_mode')) {
    function ptp_is_native_mode() {
        return true;
    }
}

/**
 * Get camp product by ID
 * Returns a stdClass with product-like properties
 */
if (!function_exists('ptp_get_camp_product')) {
    function ptp_get_camp_product($product_id) {
        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'ptp_camp_product') {
            return null;
        }
        
        $product = new stdClass();
        $product->ID = $post->ID;
        $product->id = $post->ID;
        $product->name = $post->post_title;
        $product->description = $post->post_content;
        $product->price = floatval(get_post_meta($product_id, '_price', true));
        $product->regular_price = floatval(get_post_meta($product_id, '_regular_price', true));
        $product->sale_price = get_post_meta($product_id, '_sale_price', true);
        $product->sku = get_post_meta($product_id, '_sku', true);
        $product->stock_status = get_post_meta($product_id, '_stock_status', true) ?: 'instock';
        $product->image_id = get_post_thumbnail_id($product_id);
        
        // Methods as closures
        $product->get_id = function() use ($product) { return $product->ID; };
        $product->get_name = function() use ($product) { return $product->name; };
        $product->get_price = function() use ($product) { return $product->price; };
        $product->get_regular_price = function() use ($product) { return $product->regular_price; };
        $product->get_price_html = function() use ($product) { 
            return ptp_format_price($product->price); 
        };
        $product->is_in_stock = function() use ($product) { 
            return $product->stock_status === 'instock'; 
        };
        
        return $product;
    }
}

/**
 * Get multiple camp products
 */
if (!function_exists('ptp_get_camp_products')) {
    function ptp_get_camp_products($args = array()) {
        $defaults = array(
            'post_type' => 'ptp_camp_product',
            'posts_per_page' => isset($args['limit']) ? $args['limit'] : 10,
            'post_status' => 'publish',
        );
        
        $query_args = array_merge($defaults, $args);
        $posts = get_posts($query_args);
        
        $products = array();
        foreach ($posts as $post) {
            $products[] = ptp_get_camp_product($post->ID);
        }
        
        return $products;
    }
}

/**
 * Get featured camp product IDs
 */
if (!function_exists('ptp_get_featured_camp_ids')) {
    function ptp_get_featured_camp_ids() {
        $args = array(
            'post_type' => 'ptp_camp_product',
            'posts_per_page' => 10,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_featured',
                    'value' => 'yes',
                ),
            ),
            'fields' => 'ids',
        );
        return get_posts($args);
    }
}

/**
 * Create order fee object (simplified)
 */
if (!function_exists('ptp_create_order_fee')) {
    function ptp_create_order_fee() {
        $fee = new stdClass();
        $fee->name = '';
        $fee->amount = 0;
        $fee->total = 0;
        $fee->set_name = function($name) use ($fee) { $fee->name = $name; };
        $fee->set_amount = function($amount) use ($fee) { $fee->amount = $amount; };
        $fee->set_total = function($total) use ($fee) { $fee->total = $total; };
        return $fee;
    }
}

/**
 * Add order item meta (stores in ptp_native_order_meta)
 */
if (!function_exists('ptp_add_order_item_meta')) {
    function ptp_add_order_item_meta($item_id, $key, $value) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_native_order_meta';
        return $wpdb->insert($table, array(
            'order_id' => $item_id,
            'meta_key' => $key,
            'meta_value' => maybe_serialize($value),
        ));
    }
}

/**
 * Update order item meta
 */
if (!function_exists('ptp_update_order_item_meta')) {
    function ptp_update_order_item_meta($item_id, $key, $value) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_native_order_meta';
        return $wpdb->update(
            $table,
            array('meta_value' => maybe_serialize($value)),
            array('order_id' => $item_id, 'meta_key' => $key)
        );
    }
}

/**
 * Add notice (simple session-based notices)
 */
if (!function_exists('ptp_add_notice')) {
    function ptp_add_notice($message, $type = 'success') {
        $notices = ptp_session()->get('ptp_notices') ?: array();
        $notices[] = array('message' => $message, 'type' => $type);
        ptp_session()->set('ptp_notices', $notices);
    }
}

/**
 * Check if has notices
 */
if (!function_exists('ptp_has_notice')) {
    function ptp_has_notice($type = '') {
        $notices = ptp_session()->get('ptp_notices') ?: array();
        if (empty($type)) {
            return !empty($notices);
        }
        foreach ($notices as $notice) {
            if ($notice['type'] === $type) return true;
        }
        return false;
    }
}

/**
 * Print and clear notices
 */
if (!function_exists('ptp_print_notices')) {
    function ptp_print_notices() {
        $notices = ptp_session()->get('ptp_notices') ?: array();
        if (empty($notices)) return;
        
        foreach ($notices as $notice) {
            $class = $notice['type'] === 'error' ? 'ptp-notice-error' : 'ptp-notice-success';
            echo '<div class="ptp-notice ' . esc_attr($class) . '">' . esc_html($notice['message']) . '</div>';
        }
        
        ptp_session()->set('ptp_notices', array());
    }
}

/**
 * Get product ID by SKU
 */
if (!function_exists('ptp_get_product_id_by_sku')) {
    function ptp_get_product_id_by_sku($sku) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
            $sku
        ));
    }
}

/**
 * Get coupon ID by code (placeholder)
 */
if (!function_exists('ptp_get_coupon_id_by_code')) {
    function ptp_get_coupon_id_by_code($code) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ptp_coupon' AND post_title = %s",
            $code
        ));
    }
}

/**
 * Create coupon (placeholder)
 */
if (!function_exists('ptp_create_coupon')) {
    function ptp_create_coupon() {
        $coupon = new stdClass();
        $coupon->id = 0;
        $coupon->code = '';
        $coupon->amount = 0;
        $coupon->set_code = function($code) use ($coupon) { $coupon->code = $code; };
        $coupon->set_amount = function($amount) use ($coupon) { $coupon->amount = $amount; };
        $coupon->save = function() use ($coupon) {
            $post_id = wp_insert_post(array(
                'post_type' => 'ptp_coupon',
                'post_title' => $coupon->code,
                'post_status' => 'publish',
            ));
            if ($post_id) {
                update_post_meta($post_id, '_amount', $coupon->amount);
                $coupon->id = $post_id;
            }
            return $coupon->id;
        };
        return $coupon;
    }
}

/**
 * Create product (placeholder for dynamic products)
 */
if (!function_exists('ptp_create_product')) {
    function ptp_create_product() {
        $product = new stdClass();
        $product->id = 0;
        $product->name = '';
        $product->price = 0;
        $product->set_name = function($name) use ($product) { $product->name = $name; };
        $product->set_regular_price = function($price) use ($product) { $product->price = $price; };
        $product->save = function() use ($product) {
            $post_id = wp_insert_post(array(
                'post_type' => 'ptp_camp_product',
                'post_title' => $product->name,
                'post_status' => 'publish',
            ));
            if ($post_id) {
                update_post_meta($post_id, '_price', $product->price);
                update_post_meta($post_id, '_regular_price', $product->price);
                $product->id = $post_id;
            }
            return $product->id;
        };
        return $product;
    }
}

/**
 * Main Plugin Class
 */
final class PTP_Training_Platform {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_core();
        $this->includes();
        $this->init_hooks();
    }

    private function load_core() {
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-packages.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-native-session.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-native-cart.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-native-orders.php';
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-cart-ajax.php';

    }

    private function includes() {
        $dir = PTP_PLUGIN_DIR;
        $is_ajax = wp_doing_ajax();
        $is_rest = defined('REST_REQUEST') && REST_REQUEST;
        $is_admin_page = is_admin() && !$is_ajax;

        // ── TIER 1: CORE (always load ~1.2MB) ─────────────────────
        // Data models, security, database, session, cart, core hooks
        require_once $dir . 'admin/includes/class-ptp-security.php';
        PTP_Security::init_nopriv_rate_limits();
        require_once $dir . 'includes/class-ptp-monitor.php';
        require_once $dir . 'includes/class-ptp-migrations.php';
        require_once $dir . 'includes/class-ptp-database.php';
        require_once $dir . 'includes/class-ptp-query-cache.php';
        PTP_Query_Cache::init();
        require_once $dir . 'includes/class-ptp-images.php';
        require_once $dir . 'includes/class-ptp-user.php';
        require_once $dir . 'includes/class-ptp-trainer.php';
        require_once $dir . 'includes/class-ptp-trainer-profile.php';
        require_once $dir . 'includes/class-ptp-parent.php';
        require_once $dir . 'includes/class-ptp-player.php';
        require_once $dir . 'includes/class-ptp-booking.php';
        require_once $dir . 'includes/class-ptp-availability.php';
        require_once $dir . 'includes/class-ptp-trainer-schedule.php';
        require_once $dir . 'includes/class-ptp-reviews.php';
        require_once $dir . 'includes/class-ptp-review-ajax.php';
        require_once $dir . 'includes/class-ptp-payments.php';
        require_once $dir . 'includes/class-ptp-trainer-payouts.php';
        require_once $dir . 'includes/class-ptp-notifications.php';
        require_once $dir . 'includes/class-ptp-session-ops.php';
        require_once $dir . 'includes/class-ptp-ajax.php';
        require_once $dir . 'includes/class-ptp-shortcodes.php';
        require_once $dir . 'includes/class-ptp-templates.php';
        require_once $dir . 'includes/class-ptp-performance.php';

        if (file_exists($dir . 'templates/components/ptp-header.php')) {
            require_once $dir . 'templates/components/ptp-header.php';
        }
        if (file_exists($dir . 'includes/ptp-elementor-header-fix.php')) {
            require_once $dir . 'includes/ptp-elementor-header-fix.php';
        }

        // Stripe core (webhooks need to fire on any request)
        require_once $dir . 'includes/class-ptp-stripe.php';
        require_once $dir . 'includes/class-ptp-stripe-products.php';
        require_once $dir . 'includes/class-ptp-stripe-product-sync.php';
        require_once $dir . 'includes/class-ptp-stripe-smart-api.php';
        require_once $dir . 'includes/class-ptp-escrow.php';
        require_once $dir . 'includes/class-ptp-session-confirmation.php';
        require_once $dir . 'includes/class-ptp-session-confirm-page.php';
        PTP_Session_Confirm_Page::init();
        require_once $dir . 'includes/class-ptp-cron.php';
        require_once $dir . 'includes/class-ptp-onboarding-reminders.php';

        // Email (needed by notifications on any request)
        require_once $dir . 'includes/class-ptp-email.php';
        require_once $dir . 'includes/class-ptp-email-templates.php';

        // Cart system (needed early for session/cart AJAX)
        require_once $dir . 'includes/class-ptp-cart-helper.php';
        // class-ptp-cart-ajax.php loaded in load_core()
        require_once $dir . 'includes/class-ptp-cart-checkout-v71.php';
        require_once $dir . 'includes/class-ptp-stripe-connect-v71.php';
        require_once $dir . 'includes/class-ptp-stripe-id-helper.php';

        // Dashboard AJAX (trainer/parent tabs, messaging)
        require_once $dir . 'includes/class-ptp-ajax-v71.php';
        require_once $dir . 'includes/class-ptp-spa-dashboard.php';
        require_once $dir . 'includes/class-ptp-messaging.php';
        require_once $dir . 'includes/class-ptp-instant-pay.php';
        require_once $dir . 'includes/class-ptp-session-recap-enhanced.php';
        require_once $dir . 'includes/class-ptp-ai-assistant.php';
        require_once $dir . 'includes/class-ptp-trainer-insights.php';

        // Availability (AJAX calls from booking panel)
        require_once $dir . 'includes/class-ptp-availability-v71.php';
        require_once $dir . 'includes/class-ptp-google-calendar-v71.php';
        require_once $dir . 'includes/class-ptp-availability-gcal-bridge.php';
        require_once $dir . 'includes/class-ptp-schedule-calendar-v2.php';

        // Profile / player management
        require_once $dir . 'includes/class-ptp-photo-upload.php';
        require_once $dir . 'includes/class-ptp-quick-profile-editor.php';
        require_once $dir . 'includes/class-ptp-announcement-handler.php';
        require_once $dir . 'includes/class-ptp-geocoding.php';
        require_once $dir . 'includes/class-ptp-maps.php';
        require_once $dir . 'includes/class-ptp-coupon-tracker.php';

        // SEO core (rewrites register on init)
        require_once $dir . 'includes/class-ptp-seo.php';
        require_once $dir . 'includes/class-ptp-seo-locations.php';
        require_once $dir . 'includes/class-ptp-seo-sitemap.php';
        require_once $dir . 'includes/class-ptp-seo-content.php';
        require_once $dir . 'includes/class-ptp-seo-titles.php';
        require_once $dir . 'includes/class-ptp-social.php';

        // Tracking/pixels (light, needed on frontend)
        require_once $dir . 'includes/class-ptp-pixels.php';
        require_once $dir . 'includes/class-ptp-meta-conversions-api.php';
        require_once $dir . 'includes/class-ptp-analytics.php';

        // Fixes (hooks fire globally)
        require_once $dir . 'includes/class-ptp-trainer-profile-fixes.php';
        require_once $dir . 'includes/class-ptp-booking-fix-v121.php';
        require_once $dir . 'includes/class-ptp-booking-fix-v122.php';
        require_once $dir . 'includes/class-ptp-fixes-v72.php';
        require_once $dir . 'includes/class-ptp-fixes-v129.php';
        require_once $dir . 'includes/class-ptp-order-integration-v71.php';

        // Camp system loader
        require_once $dir . 'includes/ptp-camp-system-loader.php';
        require_once $dir . 'includes/class-ptp-camp-admin.php';

        // Login
        require_once $dir . 'includes/class-ptp-google-web-login.php';

        // class-ptp-packages.php loaded in load_core()

        // Autoloader for any remaining classes
        require_once $dir . 'includes/class-ptp-autoloader.php';

        // Push notifications (light)
        require_once $dir . 'includes/class-ptp-push-notifications.php';

        // Image / mobile optimization (frontend hooks)
        require_once $dir . 'includes/class-ptp-image-optimizer.php';
        require_once $dir . 'includes/class-ptp-mobile-optimization.php';
        require_once $dir . 'includes/class-ptp-mobile-speed.php';

        // Lightweight features (no AJAX, small files)
        require_once $dir . 'includes/class-ptp-camps-crosssell.php';
        require_once $dir . 'includes/class-ptp-checkout-ux.php';
        require_once $dir . 'includes/class-ptp-stripe-product-ensure.php';
        require_once $dir . 'includes/class-ptp-faq.php';
        require_once $dir . 'includes/class-ptp-packages-display.php';

        // Optional
        if (file_exists($dir . 'includes/class-ptp-chatbot-api.php')) {
            require_once $dir . 'includes/class-ptp-chatbot-api.php';
        }
        if (file_exists($dir . 'includes/class-ptp-sms.php')) {
            require_once $dir . 'includes/class-ptp-sms.php';
        }
        if (file_exists($dir . 'includes/class-ptp-openphone-bridge.php')) {
            require_once $dir . 'includes/class-ptp-openphone-bridge.php';
            PTP_OpenPhone_Bridge::init();
        }

        // ── TIER 2: FEATURE FILES ────────────────────────────────────
        // Only load on PTP-related requests (dashboards, checkout, profiles, AJAX, REST, cron, admin)
        // Regular blog posts, archives, etc. skip these ~90 files entirely
        $load_tier2 = $is_ajax || $is_rest || $is_admin_page || wp_doing_cron();
        
        if (!$load_tier2) {
            // Check frontend URL for PTP patterns
            $uri = isset($_SERVER['REQUEST_URI']) ? strtolower($_SERVER['REQUEST_URI']) : '';
            $ptp_patterns = array(
                '/trainer/', '/ptp-checkout/', '/ptp-cart/', '/my-account/', '/dashboard/',
                '/find-trainers/', '/ptp-find-a-camp/', '/messages/', '/confirm-session/',
                '/ptp-thankyou/', '/thank-you/', '/trainer-onboarding/', '/booking-wizard/',
                '/ptp-apply/', '/ptp-login/', '/ptp-register/', '/ptp-forgot-password/',
                '/ptp-reset-password/', '/ptp-logout/', '/ptp-gift-card/', '/ptp-referral/',
                '/ptp-all-access/', '/ptp-subscribe/', '/ptp-mentorship/',
                '/mentorship/', // v223: Lead magnet + mentorship standalone pages
            );
            foreach ($ptp_patterns as $pattern) {
                if (strpos($uri, $pattern) !== false) {
                    $load_tier2 = true;
                    break;
                }
            }
        }
        
        if (!$load_tier2) {
            // Deferred: load Tier 2 at 'wp' hook if page has PTP shortcodes
            add_action('wp', array($this, 'maybe_load_tier2_deferred'), 1);
            return; // Skip Tier 2, 3, and 4 for now (REST already checked above)
        }
        
        $this->load_tier2();

        // ── TIER 3: ADMIN ONLY ────────────────────────────────────
        // Only load on admin pages or when the AJAX action is admin-specific
        $is_admin_ajax = $is_ajax && isset($_REQUEST['action']) && (
            strpos($_REQUEST['action'], 'ptp_admin_') === 0 ||
            strpos($_REQUEST['action'], 'ptp_toggle_') === 0 ||
            strpos($_REQUEST['action'], 'ptp_bulk_') === 0 ||
            strpos($_REQUEST['action'], 'ptp_delete_record') === 0 ||
            strpos($_REQUEST['action'], 'ptp_save_trainer_order') === 0 ||
            strpos($_REQUEST['action'], 'ptp_get_realtime_stats') === 0 ||
            strpos($_REQUEST['action'], 'ptp_get_players') === 0 ||
            strpos($_REQUEST['action'], 'ptp_send_stripe_dashboard_link') === 0 ||
            strpos($_REQUEST['action'], 'ptp_upload_app_asset') === 0 ||
            strpos($_REQUEST['action'], 'ptp_save_app_onboarding') === 0 ||
            strpos($_REQUEST['action'], 'ptp_send_push_notification') === 0 ||
            strpos($_REQUEST['action'], 'ptp_run_repair') === 0 ||
            strpos($_REQUEST['action'], 'ptp_resend_stripe_link') === 0
        );
        if ($is_admin_page || $is_admin_ajax) {
            require_once $dir . 'admin/class-ptp-admin.php';
            require_once $dir . 'admin/class-ptp-admin-ajax.php';
            require_once $dir . 'admin/class-ptp-app-control.php';
            require_once $dir . 'admin/class-ptp-admin-tools-v72.php';
            require_once $dir . 'includes/class-ptp-admin-payouts-v3.php';
            require_once $dir . 'includes/class-ptp-admin-settings-v71.php';
            require_once $dir . 'admin/class-ptp-camp-orders-admin.php';
        }

        if ($is_admin_page) {
            if (file_exists($dir . 'admin/class-ptp-analytics-dashboard.php')) {
                require_once $dir . 'admin/class-ptp-analytics-dashboard.php';
            }
            if (file_exists($dir . 'admin/class-ptp-thankyou-admin.php')) {
                require_once $dir . 'admin/class-ptp-thankyou-admin.php';
            }
            if (file_exists($dir . 'includes/class-ptp-page-creator.php')) {
                require_once $dir . 'includes/class-ptp-page-creator.php';
            }
            if (file_exists($dir . 'includes/class-ptp-email-test-endpoint.php')) {
                require_once $dir . 'includes/class-ptp-email-test-endpoint.php';
            }
            if (file_exists($dir . 'tests/class-ptp-test-runner.php')) {
                require_once $dir . 'tests/class-ptp-test-runner.php';
                PTP_Test_Runner::init();
            }
        }

        // ── TIER 4: REST API ONLY ─────────────────────────────────
        if ($is_rest || (!$is_ajax && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false)) {
            require_once $dir . 'includes/class-ptp-rest-api.php';
            require_once $dir . 'includes/class-ptp-flow-engine.php';
            require_once $dir . 'includes/class-ptp-n8n-endpoints.php';
            require_once $dir . 'includes/class-ptp-supabase-bridge.php';
            require_once $dir . 'includes/class-ptp-app-config.php';
            require_once $dir . 'includes/class-ptp-social-login.php';
            require_once $dir . 'includes/class-ptp-camps-api.php';
        }
    }

    private $tier2_loaded = false;

    /**
     * Load Tier 2 feature files (shortcodes, webhooks, cron, pipeline).
     * Called immediately for PTP pages/AJAX/REST/cron/admin, or deferred via 'wp' hook for unknown pages.
     */
    public function load_tier2() {
        if ($this->tier2_loaded) return;
        $this->tier2_loaded = true;

        $dir = PTP_PLUGIN_DIR;

        // Mentorship
        require_once $dir . 'includes/class-ptp-mentorship.php';
        require_once $dir . 'includes/class-ptp-mentorship-database.php';
        require_once $dir . 'includes/class-ptp-mentorship-pipeline.php';
        require_once $dir . 'includes/class-ptp-mentorship-interest.php';
        require_once $dir . 'includes/class-ptp-mentorship-sessions.php';
        require_once $dir . 'includes/class-ptp-mentorship-billing.php';
        require_once $dir . 'includes/class-ptp-mentorship-content.php';
        require_once $dir . 'includes/class-ptp-mentorship-admin.php';
        require_once $dir . 'includes/class-ptp-mentorship-touchpoints.php';
        require_once $dir . 'includes/class-ptp-mentorship-notifications-v2.php';
        require_once $dir . 'includes/class-ptp-mentorship-ajax.php';
        require_once $dir . 'includes/class-ptp-mentorship-recurring.php';
        require_once $dir . 'includes/class-ptp-camp-lifecycle-cron.php';
        require_once $dir . 'includes/class-ptp-camp-conversion.php'; // v223: Camp→Mentorship thank-you CTA + post-camp 3-touch sequence
        require_once $dir . 'includes/class-ptp-unified-player.php';

        // Checkout/Cart
        require_once $dir . 'includes/class-ptp-unified-cart.php';
        require_once $dir . 'includes/class-ptp-unified-checkout.php';
        require_once $dir . 'includes/class-ptp-unified-checkout-handler.php';
        require_once $dir . 'includes/class-ptp-unified-session-bridge.php';
        require_once $dir . 'includes/class-ptp-stripe-backfill.php';
        require_once $dir . 'includes/class-ptp-abandoned-cart-recovery.php';
        if (file_exists($dir . 'includes/class-ptp-bulletproof-checkout.php')) {
            require_once $dir . 'includes/class-ptp-bulletproof-checkout.php';
        }
        require_once $dir . 'includes/class-ptp-bundle-checkout.php';
        require_once $dir . 'includes/class-ptp-checkout-v77.php';

        // Email/Order pipeline
        require_once $dir . 'includes/class-ptp-email-automation.php';
        require_once $dir . 'includes/class-ptp-camp-orders.php';
        require_once $dir . 'includes/class-ptp-camp-emails.php';
        require_once $dir . 'includes/class-ptp-unified-order-email.php';
        require_once $dir . 'includes/class-ptp-order-email-wiring.php';
        require_once $dir . 'includes/class-ptp-email-flow-fix.php';
        require_once $dir . 'includes/class-ptp-ai-coach.php';
        require_once $dir . 'includes/class-ptp-ai-training-plan.php';
        require_once $dir . 'includes/class-ptp-ai-insights-admin.php';

        // Camp system
        require_once $dir . 'includes/class-ptp-camp-checkout.php';
        require_once $dir . 'includes/class-ptp-camp-checkout-v99.php';
        require_once $dir . 'includes/class-ptp-camp-crosssell-everywhere.php';

        // Growth & Crosssell
        require_once $dir . 'includes/class-ptp-viral-engine.php';
        require_once $dir . 'includes/class-ptp-viral-enhancements.php';
        require_once $dir . 'includes/class-ptp-crosssell-engine.php';
        require_once $dir . 'includes/class-ptp-growth.php';
        require_once $dir . 'includes/class-ptp-quality-control.php';
        require_once $dir . 'includes/class-ptp-platform-protection.php';
        PTP_Platform_Protection::init();
        require_once $dir . 'includes/class-ptp-google-reviews.php';
        require_once $dir . 'includes/class-ptp-calendar-enhancements.php';
        require_once $dir . 'includes/class-ptp-trainer-loyalty.php';
        require_once $dir . 'includes/class-ptp-trainer-referrals.php';

        // SEO
        require_once $dir . 'includes/class-ptp-seo-locations-v85.php';

        // Trainer matching
        require_once $dir . 'includes/class-ptp-trainer-matching.php';

        // Advanced features
        require_once $dir . 'includes/class-ptp-booking-wizard.php';
        require_once $dir . 'includes/class-ptp-all-access-pass.php';
        require_once $dir . 'includes/class-ptp-subscriptions.php';
        require_once $dir . 'includes/class-ptp-recurring.php';
        require_once $dir . 'includes/class-ptp-groups.php';
        require_once $dir . 'includes/class-ptp-calendar-sync.php';
        require_once $dir . 'includes/class-ptp-training-plans.php';
        require_once $dir . 'includes/class-ptp-tax-reporting.php';
        require_once $dir . 'includes/class-ptp-happy-score.php';
        require_once $dir . 'includes/class-ptp-gift-cards.php';
        require_once $dir . 'includes/class-ptp-referral-system.php';
        require_once $dir . 'includes/class-ptp-popups.php';
        require_once $dir . 'includes/class-ptp-social-announcement.php';

        // Thank You pages
        require_once $dir . 'includes/class-ptp-thankyou-handler.php';
        require_once $dir . 'includes/class-ptp-thankyou-ajax.php';
        require_once $dir . 'includes/class-ptp-training-thankyou.php';
        require_once $dir . 'includes/ptp-thankyou-v100-loader.php';
        require_once $dir . 'includes/class-ptp-thankyou-upsell-handler.php';

        // Checkout diagnostic — always tracks which checkout paths are used
        // View: PTP_Checkout_Diagnostic::get_summary() or get_option('ptp_checkout_diagnostic_stats')
        require_once $dir . 'includes/class-ptp-checkout-diagnostic.php';
        PTP_Checkout_Diagnostic::init();
    }

    /**
     * Initialize Tier 2 class hooks (called after load_tier2).
     */
    private function init_tier2_hooks() {
        if (class_exists('PTP_Mentorship')) PTP_Mentorship::init();
        if (class_exists('PTP_Mentorship_Ajax')) PTP_Mentorship_Ajax::init();
        if (class_exists('PTP_Mentorship_Recurring')) PTP_Mentorship_Recurring::init();
        if (class_exists('PTP_Unified_Player')) PTP_Unified_Player::init();
        if (class_exists('PTP_Mentorship_Touchpoints') && !class_exists('PTP_Mentorship_Notifications_V2')) PTP_Mentorship_Touchpoints::init();
        if (class_exists('PTP_Mentorship_Notifications_V2')) PTP_Mentorship_Notifications_V2::init();
        if (class_exists('PTP_Camp_Lifecycle_Cron')) PTP_Camp_Lifecycle_Cron::init();

        // v223: Money Model — camp→mentorship conversion + growth engines (deferred path)
        if (class_exists('PTP_Camp_Conversion')) PTP_Camp_Conversion::init();
        if (class_exists('PTP_Viral_Engine')) PTP_Viral_Engine::instance();
        if (class_exists('PTP_Crosssell_Engine')) PTP_Crosssell_Engine::instance();
        if (class_exists('PTP_Camp_Crosssell_Everywhere')) PTP_Camp_Crosssell_Everywhere::instance();
        if (class_exists('PTP_Referral_System')) PTP_Referral_System::instance();
        if (class_exists('PTP_Abandoned_Cart_Recovery')) PTP_Abandoned_Cart_Recovery::instance();
        if (class_exists('PTP_Growth')) PTP_Growth::instance();
    }

    /**
     * Deferred Tier 2 loader: fires at 'wp' hook (priority 1) for frontend pages
     * that weren't caught by the URL pattern check. Loads if the post has PTP shortcodes.
     */
    public function maybe_load_tier2_deferred() {
        if ($this->tier2_loaded) return;

        // Check if current post content has any PTP shortcodes
        $load = false;
        $post = get_queried_object();
        if ($post && is_a($post, 'WP_Post') && !empty($post->post_content)) {
            if (strpos($post->post_content, '[ptp_') !== false || strpos($post->post_content, 'ptp-') !== false) {
                $load = true;
            }
        }

        // Also load if a PTP cookie/session exists (logged-in parent/trainer)
        if (!$load && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $roles = wp_get_current_user()->roles;
            if (array_intersect($roles, array('ptp_trainer', 'ptp_parent', 'administrator'))) {
                $load = true;
            }
        }

        if ($load) {
            $this->load_tier2();
            $this->init_tier2_hooks();
        }
    }

    private function init_hooks() {
        register_activation_hook(PTP_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(PTP_PLUGIN_FILE, array($this, 'deactivate'));

        add_action('init', array($this, 'init'), 5);
        add_action('init', array($this, 'register_post_types'), 5);
        add_action('init', array($this, 'add_rewrite_rules'), 10);
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 20);
        add_action('init', array($this, 'ensure_roles_exist'));
        add_action('admin_init', array($this, 'maybe_create_pages'));
        add_action('init', array($this, 'maybe_create_pages_frontend'), 15);
        add_action('admin_init', array($this, 'maybe_rebuild_database'));

        // v216: /training/ is NOT a WP page. Homepage serves training-hub directly
        // via PTP_Shortcodes::intercept_training_page() at template_redirect priority 0.
        add_action('init', array($this, 'cleanup_training_page'), 25);

        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('request', array($this, 'filter_trainer_request'), 1);
        add_action('template_redirect', array($this, 'handle_trainer_profile'));
        add_action('template_redirect', array($this, 'handle_trainer_onboarding'));
        add_action('template_redirect', array($this, 'redirect_old_trainer_urls'));
        add_action('template_redirect', array($this, 'handle_impersonate_login'), 1);

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 99);
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

        add_filter('body_class', array($this, 'add_body_classes'));
        
        // v135: Redirect WP password reset to PTP custom page
        add_filter('retrieve_password_message', array($this, 'custom_reset_password_email'), 10, 4);
        add_filter('lostpassword_redirect', array($this, 'lostpassword_redirect'));
        add_filter('login_url', array($this, 'custom_login_url'), 10, 3);
    }
    
    /**
     * URL trigger for database rebuild: ?ptp_rebuild_db=1
     */
    public function maybe_rebuild_database() {
        if (!current_user_can('manage_options')) return;
        
        // ?ptp_rebuild_db=1 — recreate tables
        if (isset($_GET['ptp_rebuild_db']) && $_GET['ptp_rebuild_db'] == '1') {
            if (class_exists('PTP_Database')) PTP_Database::create_tables();
            if (class_exists('PTP_Native_Session')) PTP_Native_Session::create_table();
            if (class_exists('PTP_Native_Cart')) PTP_Native_Cart::create_table();
            if (class_exists('PTP_Native_Order_Manager')) PTP_Native_Order_Manager::create_tables();
            if (class_exists('PTP_SMS')) PTP_SMS::create_table();
            if (class_exists('PTP_Social')) PTP_Social::create_table();
            if (class_exists('PTP_Geocoding')) PTP_Geocoding::create_table();
            if (class_exists('PTP_Recurring')) PTP_Recurring::create_tables();
            if (class_exists('PTP_Groups')) PTP_Groups::create_tables();
            if (class_exists('PTP_Calendar_Sync')) PTP_Calendar_Sync::create_tables();
            if (class_exists('PTP_Training_Plans')) PTP_Training_Plans::create_tables();
            if (class_exists('PTP_Fixes_V72')) PTP_Fixes_V72::instance()->comprehensive_table_repair();
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>All PTP database tables have been recreated.</p></div>';
            });
        }
        
        // ?ptp_recover_trainers=1 — force re-run trainer recovery from WP users
        if (isset($_GET['ptp_recover_trainers']) && $_GET['ptp_recover_trainers'] == '1') {
            // Clear the recovery flag so it re-runs
            delete_option('ptp_trainers_recovered_version');
            // Ensure tables exist first
            if (class_exists('PTP_Database')) PTP_Database::create_tables();
            if (class_exists('PTP_Fixes_V72')) PTP_Fixes_V72::instance()->comprehensive_table_repair();
            // Run recovery
            $this->maybe_recover_trainers();
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Trainer recovery has been re-run. Check the trainers list.</p></div>';
            });
        }
        
        if (!isset($_GET['ptp_rebuild_db']) && !isset($_GET['ptp_recover_trainers'])) return;
    }

    public function init() {
        // v187.3: Clear caches on version upgrade (handles FTP updates without activation)
        $stored_version = get_option('ptp_plugin_version', '0');
        if (version_compare($stored_version, PTP_VERSION, '<')) {
            delete_transient('ptp_active_trainers');
            delete_transient('ptp_trainer_count');
            flush_rewrite_rules();
            
            // v222: Safe upgrade — ensure all tables exist without dropping data
            if (class_exists('PTP_Database')) PTP_Database::create_tables();
            if (class_exists('PTP_Mentorship')) PTP_Mentorship::create_tables();
            if (class_exists('PTP_Native_Session')) PTP_Native_Session::create_table();
            if (class_exists('PTP_Native_Cart')) PTP_Native_Cart::create_table();
            if (class_exists('PTP_Native_Order_Manager')) PTP_Native_Order_Manager::create_tables();
            if (class_exists('PTP_Fixes_V72')) PTP_Fixes_V72::instance()->comprehensive_table_repair();
            
            // v222: Run any pending migrations
            if (class_exists('PTP_Migrations')) PTP_Migrations::init();
            
            // v222: Log upgrade for debugging
            ptp_log('[PTP] Upgraded from v' . $stored_version . ' to v' . PTP_VERSION . ' — all trainers and tables preserved');
            
            update_option('ptp_plugin_version', PTP_VERSION);
        }
        
        // v222: Auto-recovery — if tables are missing or trainers were wiped, rebuild from WP users
        $this->maybe_recover_trainers();
        
        // Error monitoring & alerting (must be first)
        if (class_exists('PTP_Monitor')) PTP_Monitor::init();
        
        // Run pending database migrations
        if (class_exists('PTP_Migrations')) {
            PTP_Migrations::init();
            add_action('admin_menu', array('PTP_Migrations', 'register_admin_page'), 99);
        }
        
        if (class_exists('PTP_Native_Session')) PTP_Native_Session::init();
        if (class_exists('PTP_Native_Cart')) PTP_Native_Cart::init();
        
        // Core functionality
        if (class_exists('PTP_Ajax')) PTP_Ajax::init();
        if (class_exists('PTP_Review_Ajax')) PTP_Review_Ajax::init();
        if (class_exists('PTP_Mentorship')) PTP_Mentorship::init();
        if (class_exists('PTP_Unified_Player')) PTP_Unified_Player::init();
        // v233 M8: Only init Touchpoints if V2 is NOT loaded (V2 replaces all Touchpoints hooks)
        if (class_exists('PTP_Mentorship_Touchpoints') && !class_exists('PTP_Mentorship_Notifications_V2')) PTP_Mentorship_Touchpoints::init();
        if (class_exists('PTP_Mentorship_Notifications_V2')) PTP_Mentorship_Notifications_V2::init();
        if (class_exists('PTP_Camp_Lifecycle_Cron')) PTP_Camp_Lifecycle_Cron::init();
        if (class_exists('PTP_Shortcodes')) PTP_Shortcodes::init();
        if (class_exists('PTP_Templates')) PTP_Templates::init();
        if (class_exists('PTP_Availability')) PTP_Availability::init();
        if (class_exists('PTP_Trainer_Schedule')) PTP_Trainer_Schedule::init();
        if (class_exists('PTP_Trainer_Payouts')) PTP_Trainer_Payouts::init();
        if (class_exists('PTP_Admin_Payouts_V3')) PTP_Admin_Payouts_V3::init(); // v130.3: Redesigned admin payments page
        if (class_exists('PTP_Admin_Ajax')) PTP_Admin_Ajax::init(); // v197.1: Trainer edit, compliance emails, booking CRUD
        
        // Integrations
        if (class_exists('PTP_SMS')) PTP_SMS::init();
        if (class_exists('PTP_Email')) PTP_Email::init();
        if (class_exists('PTP_Stripe')) PTP_Stripe::init();
        if (class_exists('PTP_SEO')) PTP_SEO::init();
        if (class_exists('PTP_Social')) PTP_Social::init();
        if (class_exists('PTP_Geocoding')) PTP_Geocoding::init();
        if (class_exists('PTP_Cron')) PTP_Cron::init();
        if (class_exists('PTP_Session_Ops')) PTP_Session_Ops::init();
        if (class_exists('PTP_Trainer_Insights')) PTP_Trainer_Insights::init();
        if (class_exists('PTP_Onboarding_Reminders')) PTP_Onboarding_Reminders::init();
        if (class_exists('PTP_Order_Email_Wiring')) PTP_Order_Email_Wiring::instance();
        if (class_exists('PTP_Unified_Checkout')) PTP_Unified_Checkout::instance();
        if (class_exists('PTP_Cart_Ajax')) PTP_Cart_Ajax::instance();
        if (class_exists('PTP_Social_Announcement')) PTP_Social_Announcement::instance();
        if (class_exists('PTP_Coupon_Tracker')) PTP_Coupon_Tracker::instance();
        
        // Hook for sending booking emails asynchronously
        add_action('ptp_send_booking_emails', array('PTP_Ajax', 'do_send_booking_emails'));
        
        // v166: Debug panel test payment intent handler
        add_action('wp_ajax_ptp_create_test_payment_intent', array($this, 'ajax_create_test_payment_intent'));
        add_action('wp_ajax_ptp_test_webhook', array($this, 'ajax_test_webhook'));
        
        // Advanced features
        if (class_exists('PTP_Recurring')) PTP_Recurring::init();
        if (class_exists('PTP_Groups')) PTP_Groups::init();
        if (class_exists('PTP_Calendar_Sync')) PTP_Calendar_Sync::init();
        if (class_exists('PTP_Training_Plans')) PTP_Training_Plans::init();
        if (class_exists('PTP_Tax_Reporting')) PTP_Tax_Reporting::init();
        if (class_exists('PTP_REST_API')) PTP_REST_API::init();
        if (class_exists('PTP_Push_Notifications')) PTP_Push_Notifications::init();

        // v223: Money Model — camp→mentorship conversion + growth engines
        // These files were loaded but never initialized. They power the back-end
        // revenue loop that pays down camp CAC through mentorship/training.
        if (class_exists('PTP_Camp_Conversion')) PTP_Camp_Conversion::init();
        if (class_exists('PTP_Viral_Engine')) PTP_Viral_Engine::instance();
        if (class_exists('PTP_Crosssell_Engine')) PTP_Crosssell_Engine::instance();
        if (class_exists('PTP_Camp_Crosssell_Everywhere')) PTP_Camp_Crosssell_Everywhere::instance();
        if (class_exists('PTP_Referral_System')) PTP_Referral_System::instance();
        if (class_exists('PTP_Abandoned_Cart_Recovery')) PTP_Abandoned_Cart_Recovery::instance();
        if (class_exists('PTP_Growth')) PTP_Growth::instance();
    }
    
    /**
     * v166: Test Stripe API connectivity from debug panel
     */
    public function ajax_create_test_payment_intent() {
        // Admin only
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        
        $amount = intval($_POST['amount'] ?? 100);
        if ($amount < 50) $amount = 100; // Minimum $1.00
        
        // Get Stripe secret key
        $test_mode = get_option('ptp_stripe_test_mode', true);
        if ($test_mode) {
            $secret_key = get_option('ptp_stripe_test_secret', '');
        } else {
            $secret_key = get_option('ptp_stripe_live_secret', '');
        }
        
        // Fallback to old option names
        if (empty($secret_key)) {
            $secret_key = get_option('ptp_stripe_secret_key', '');
        }
        
        if (empty($secret_key)) {
            wp_send_json_error(array('message' => 'Stripe secret key not configured'));
            return;
        }
        
        // Create test payment intent via Stripe API
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'amount' => $amount,
                'currency' => 'usd',
                'metadata[test]' => 'true',
                'metadata[source]' => 'ptp_debug_panel',
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200 || isset($body['error'])) {
            $error_msg = $body['error']['message'] ?? 'Unknown Stripe error';
            wp_send_json_error(array('message' => $error_msg, 'code' => $code));
            return;
        }
        
        // Success - return intent ID
        wp_send_json_success(array(
            'intent_id' => $body['id'] ?? '',
            'client_secret' => substr($body['client_secret'] ?? '', 0, 20) . '...',
            'amount' => $body['amount'] ?? 0,
            'status' => $body['status'] ?? '',
        ));
    }
    
    /**
     * v166: Test webhook endpoint connectivity
     */
    public function ajax_test_webhook() {
        // Admin only
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        
        // Check if webhook endpoint exists
        $webhook_url = rest_url('ptp-camps/v1/webhook');
        $response = wp_remote_get($webhook_url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Webhook endpoint unreachable',
                'error' => $response->get_error_message(),
                'url' => $webhook_url,
            ));
            return;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // 400 with "Invalid payload" means endpoint is working (expects POST with valid Stripe payload)
        $body_decoded = json_decode($body, true);
        $is_working = ($code === 400 && isset($body_decoded['error'])) || $code === 200;
        
        if ($is_working) {
            wp_send_json_success(array(
                'message' => 'Webhook endpoint is active',
                'url' => $webhook_url,
                'response_code' => $code,
                'webhook_secret_set' => !empty(get_option('ptp_stripe_webhook_secret', '')),
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Webhook endpoint returned unexpected response',
                'url' => $webhook_url,
                'code' => $code,
                'body' => substr($body, 0, 200),
            ));
        }
    }

    public function register_post_types() {
        // Camp Products post type is registered by PTP Camps plugin
        // Training platform only handles trainer-related functionality
    }

    public function add_rewrite_rules() {
        // v216: No rewrite rule for /training — homepage (/) serves training-hub directly.
        // /training requests are 301-redirected to / by PTP_Shortcodes::intercept_training_page().
        
        // Trainer profiles: /trainer/john-smith/
        add_rewrite_rule('^trainer/([^/]+)/?$', 'index.php?pagename=trainer&trainer_slug=$matches[1]', 'top');
        add_rewrite_tag('%trainer_slug%', '([^&]+)');
        
        // SEO location pages: /find-trainers/philadelphia/
        add_rewrite_rule('^find-trainers/([^/]+)/?$', 'index.php?pagename=find-trainers&trainer_location=$matches[1]', 'top');
        add_rewrite_tag('%trainer_location%', '([^&]+)');
    }

    public function add_query_vars($vars) {
        $vars[] = 'ptp_page';
        $vars[] = 'trainer_slug';
        $vars[] = 'trainer_id';
        $vars[] = 'trainer_location';
        return $vars;
    }

    /**
     * v177: Auto-flush rewrite rules when plugin version changes
     * This ensures /trainer/slug/ routes work after file-based updates
     */
    public function maybe_flush_rewrite_rules() {
        $stored_version = get_option('ptp_plugin_version', '');
        if ($stored_version !== PTP_VERSION) {
            flush_rewrite_rules();
            // v242.1: Clear ALL trainer profile caches on version change
            delete_transient('ptp_active_trainers');
            delete_transient('ptp_trainers_grid');
            global $wpdb;
            $table = $wpdb->prefix . 'ptp_trainers';
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $ids = $wpdb->get_col("SELECT id FROM $table");
                foreach ($ids as $tid) {
                    delete_transient('ptp_profile_reviews_' . $tid);
                    delete_transient('ptp_profile_booked_' . $tid);
                    delete_transient('ptp_profile_avail_dates_' . $tid);
                    delete_transient('ptp_cover_srcset_' . $tid);
                }
            }
            update_option('ptp_plugin_version', PTP_VERSION);
            ptp_log('[PTP v242.1] Flushed rewrite rules + all profile caches: ' . $stored_version . ' → ' . PTP_VERSION);
        }
    }

    /**
     * v177: Create essential pages on frontend if they don't exist
     * Normally runs on admin_init, but user may never visit admin after update
     */
    public function maybe_create_pages_frontend() {
        // Only run once per version
        if (get_option('ptp_pages_created_version', '') === PTP_VERSION) {
            return;
        }
        // Only create the trainer page on frontend (most critical for routing)
        if (!get_page_by_path('trainer')) {
            wp_insert_post(array(
                'post_title' => 'Trainer Profile',
                'post_name' => 'trainer',
                'post_content' => '[ptp_trainer_profile]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ));
            flush_rewrite_rules();
            ptp_log('[PTP v177] Auto-created trainer page on frontend');
        }
        
        // v227: Fix pages created with wrong shortcodes (one-time migration)
        if (!get_option('ptp_pages_shortcode_fix_227')) {
            $fixes = array(
                'ptp-cart'     => '[ptp_cart]',
                'ptp-checkout' => '[ptp_checkout]',
                'summer-camps' => '[ptp_camps]',
            );
            foreach ($fixes as $slug => $correct_sc) {
                $page = get_page_by_path($slug);
                if ($page && strpos($page->post_content, $correct_sc) === false) {
                    wp_update_post(array('ID' => $page->ID, 'post_content' => $correct_sc));
                    ptp_log("[PTP v227] Fixed shortcode on /{$slug}/ → {$correct_sc}");
                }
            }
            update_option('ptp_pages_shortcode_fix_227', '1');
        }
        
        update_option('ptp_pages_created_version', PTP_VERSION);
    }

    // v216: Removed unset_training_as_front_page(), block_training_as_front_page(),
    // fix_training_homepage_conflict() — training IS the homepage now. The
    // /training WP page no longer exists. Homepage served by
    // PTP_Shortcodes::intercept_training_page() at template_redirect priority 0.

    /**
     * v216: One-time cleanup — trash the /training WP page if it exists.
     * The homepage now serves training-hub directly. The /training slug must
     * not be registered as a WP page (causes routing conflicts).
     * Runs once per version upgrade.
     */
    /**
     * v218: No longer trashes /training/ page — homepage is a normal WP page with [ptp_homepage] shortcode.
     */
    public function cleanup_training_page() {
        // No-op — kept for backwards compatibility
        return;
    }

    /**
     * Filter request to handle trainer URLs (fallback if rewrite rules don't work)
     */
    public function filter_trainer_request($query_vars) {
        // Only intercept if we don't already have the trainer_slug set
        if (!empty($query_vars['trainer_slug'])) {
            return $query_vars;
        }
        
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = trim(parse_url($request_uri, PHP_URL_PATH) ?? '', '/');
        
        // Remove site subdirectory if present
        $home_path = trim(parse_url(home_url(), PHP_URL_PATH) ?? '', '/');
        if ($home_path && strpos($path, $home_path) === 0) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }
        
        if (preg_match('#^trainer/([^/]+)/?$#', $path, $matches)) {
            $trainer_slug = sanitize_text_field($matches[1]);
            
            // Skip if this looks like a static file
            if (strpos($trainer_slug, '.') !== false) {
                return $query_vars;
            }
            
            // Ensure the trainer page exists - create it if missing
            $trainer_page = get_page_by_path('trainer');
            if (!$trainer_page) {
                $page_id = wp_insert_post(array(
                    'post_title' => 'Trainer Profile',
                    'post_name' => 'trainer',
                    'post_content' => '[ptp_trainer_profile]',
                    'post_status' => 'publish',
                    'post_type' => 'page',
                ));
                
                if ($page_id && !is_wp_error($page_id)) {
                    flush_rewrite_rules();
                }
            }
            
            // Set up query vars to load the trainer page
            $query_vars['pagename'] = 'trainer';
            $query_vars['trainer_slug'] = $trainer_slug;
            
            // Remove any 404 indicators
            unset($query_vars['error']);
            unset($query_vars['name']);
        }
        
        return $query_vars;
    }

    public function add_body_classes($classes) {
        $classes[] = 'ptp-site';
        
        // Dashboard pages
        if (is_page('trainer-dashboard') || is_page('dashboard')) {
            $classes[] = 'ptp-dashboard ptp-trainer-dashboard ptp-standalone-page ptp-dashboard-page';
        }
        if (is_page('parent-dashboard')) {
            $classes[] = 'ptp-dashboard ptp-parent-dashboard ptp-standalone-page ptp-dashboard-page';
        }
        
        // Checkout pages
        // v166: Unified checkout page detection
        if (is_page(array('ptp-checkout', 'checkout'))) {
            $classes[] = 'ptp-checkout-page ptp-standalone-page';
        }
        
        // Cart page
        if (is_page(array('ptp-cart', 'cart'))) {
            $classes[] = 'ptp-cart-page ptp-standalone-page';
        }
        
        // Thank you pages
        if (is_page(array('thank-you', 'ptp-thank-you', 'training-thank-you', 'camp-thank-you'))) {
            $classes[] = 'ptp-thank-you-page ptp-standalone-page';
        }
        
        // Camp product pages
        if (is_singular('ptp_camp_product')) {
            $classes[] = 'ptp-camp-page ptp-standalone-page';
        }
        
        // Trainer profile pages (virtual)
        if (get_query_var('trainer_slug')) {
            $classes[] = 'ptp-trainer-profile ptp-standalone-page tp210';
        }
        
        // Training landing — only /training page (v219: homepage is NOT affected)
        $req_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        if ($req_path !== '' && !is_front_page()) {
            if ($req_path === 'training' || is_page(array('training', 'ptp-training', 'private-training', 'training-landing')) || get_query_var('ptp_page') === 'training') {
                $classes[] = 'ptp-training-landing ptp-standalone-page';
            }
        }
        
        // Onboarding pages
        if (is_page(array('trainer-onboarding', 'apply', 'become-a-trainer'))) {
            $classes[] = 'ptp-onboarding ptp-standalone-page';
        }
        
        // All access pages
        if (is_page(array('all-access', 'all-access-pass', 'membership'))) {
            $classes[] = 'ptp-all-access ptp-standalone-page';
        }
        
        // Messaging
        if (is_page(array('messages', 'messaging'))) {
            $classes[] = 'ptp-messaging-page ptp-standalone-page';
        }
        
        // Account pages
        if (is_page(array('account', 'my-account', 'login', 'register'))) {
            $classes[] = 'ptp-account-page';
        }
        
        // Summer camps archive
        if (is_post_type_archive('ptp_camp_product') || is_page('summer-camps')) {
            $classes[] = 'ptp-camps-archive';
        }
        
        return $classes;
    }

    /**
     * v135: Replace WP default password reset URL with PTP custom page
     * WordPress sends: wp-login.php?action=rp&key=xxx&login=yyy
     * We need: /reset-password/?key=xxx&login=yyy
     */
    public function custom_reset_password_email($message, $key, $user_login, $user_data) {
        $reset_url = add_query_arg(array(
            'key' => $key,
            'login' => rawurlencode($user_login),
        ), home_url('/reset-password/'));
        
        $first_name = get_user_meta($user_data->ID, 'first_name', true) ?: $user_login;
        
        // Replace the entire message with PTP-branded version
        $message = "Hi {$first_name},\n\n";
        $message .= "Someone requested a password reset for your PTP Training account.\n\n";
        $message .= "Click here to reset your password:\n";
        $message .= $reset_url . "\n\n";
        $message .= "This link will expire in 24 hours.\n\n";
        $message .= "If you didn't request this, you can safely ignore this email.\n\n";
        $message .= "— The PTP Team\n";
        
        return $message;
    }
    
    /**
     * v135: Redirect lostpassword back to PTP login page
     */
    public function lostpassword_redirect($redirect) {
        return home_url('/login/?reset=sent');
    }
    
    /**
     * v135: Override WP login URL to use PTP login page
     */
    public function custom_login_url($login_url, $redirect, $force_reauth) {
        $ptp_login = home_url('/login/');
        if ($redirect) {
            $ptp_login = add_query_arg('redirect_to', urlencode($redirect), $ptp_login);
        }
        return $ptp_login;
    }

    /**
     * Handle trainer profile URLs - loads trainer-profile-v3.php template
     * Uses ptp_trainers database table (not WordPress users)
     * v151.2: Fixed to use correct trainer data source
     */
    public function handle_trainer_profile() {
        global $wpdb, $wp_query;
        
        $trainer_slug = get_query_var('trainer_slug');
        if (empty($trainer_slug)) return;
        
        // v187.3: Use PTP_Trainer::get_by_slug() for robust matching
        // (exact, case-insensitive, display_name fallback)
        $trainer = null;
        if (class_exists('PTP_Trainer')) {
            // Try by slug first (handles multiple matching strategies)
            $trainer = PTP_Trainer::get_by_slug($trainer_slug);
            
            // Also try sanitized version
            if (!$trainer) {
                $trainer = PTP_Trainer::get_by_slug(sanitize_title($trainer_slug));
            }
            
            // Try numeric ID
            if (!$trainer && is_numeric($trainer_slug)) {
                $trainer = PTP_Trainer::get(intval($trainer_slug));
            }
        }
        
        // Fallback: direct DB query with flexible matching
        if (!$trainer) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s",
                $trainer_slug
            ));
        }
        if (!$trainer) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE LOWER(slug) = LOWER(%s)",
                $trainer_slug
            ));
        }
        if (!$trainer) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers 
                 WHERE LOWER(REPLACE(REPLACE(display_name, ' ', '-'), '.', '')) = LOWER(%s)",
                $trainer_slug
            ));
        }
        
        // v187.3: If trainer not found or inactive, let the shortcode handle
        // the error display instead of a hard WordPress 404
        if (!$trainer || $trainer->status !== 'active') {
            return;
        }
        
        // Store trainer data globally for template access
        $GLOBALS['ptp_trainer'] = $trainer;
        $GLOBALS['ptp_trainer_id'] = $trainer->id;
        $GLOBALS['ptp_current_trainer'] = $trainer; // v177: template reads this name
        
        // Set up virtual page for proper WP query state
        $trainer_page = new stdClass();
        $trainer_page->ID = -1;
        $trainer_page->post_title = $trainer->display_name;
        $trainer_page->post_name = $trainer_slug;
        $trainer_page->post_content = '';
        $trainer_page->post_status = 'publish';
        $trainer_page->post_type = 'page';
        $trainer_page->comment_status = 'closed';
        $trainer_page->ping_status = 'closed';
        $trainer_page->post_parent = 0;
        $trainer_page->guid = home_url('/trainer/' . $trainer_slug . '/');
        $trainer_page->menu_order = 0;
        $trainer_page->post_date = current_time('mysql');
        $trainer_page->post_date_gmt = current_time('mysql', 1);
        
        $wp_query->is_page = true;
        $wp_query->is_singular = true;
        $wp_query->is_home = false;
        $wp_query->is_archive = false;
        $wp_query->is_404 = false;
        $wp_query->posts = array($trainer_page);
        $wp_query->post_count = 1;
        $wp_query->found_posts = 1;
        
        status_header(200);
        
        // Load the trainer profile template directly
        include PTP_PLUGIN_DIR . 'templates/trainer-profile-v177.php';
        exit;
    }

    /**
     * v213: Handle trainer onboarding at template_redirect
     * Previously ran as a shortcode which caused nested <!DOCTYPE html> and broken DOM.
     * Now intercepts before get_header() so the full-page template renders cleanly.
     */
    public function handle_trainer_onboarding() {
        if (!is_page('trainer-onboarding')) return;

        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login/'));
            exit;
        }

        $current_user_id = get_current_user_id();
        $current_user = wp_get_current_user();

        // Get trainer record
        $trainer = null;
        if (class_exists('PTP_Trainer')) {
            $trainer = PTP_Trainer::get_by_user_id($current_user_id);
        }

        // If no trainer found, check by email
        if (!$trainer) {
            global $wpdb;
            $user_email = $current_user->user_email;
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE email = %s",
                $user_email
            ));

            if ($trainer && (empty($trainer->user_id) || $trainer->user_id == 0)) {
                $is_approved_trainer = in_array('ptp_trainer', (array) $current_user->roles);
                $is_admin = current_user_can('manage_options');
                if ($is_approved_trainer || $is_admin) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_trainers',
                        array('user_id' => $current_user_id),
                        array('id' => $trainer->id)
                    );
                    $trainer->user_id = $current_user_id;
                } else {
                    $trainer = null;
                }
            }
        }

        if (!$trainer) {
            wp_redirect(home_url('/apply/'));
            exit;
        }

        if (class_exists('PTP_Trainer') && empty($trainer->slug)) {
            $trainer = PTP_Trainer::ensure_slug($trainer);
        }

        $has_basics = !empty($trainer->display_name) && !empty($trainer->hourly_rate) && $trainer->hourly_rate > 0;
        $onboarding_complete = get_user_meta($trainer->user_id, 'ptp_onboarding_completed', true);
        if ($has_basics && !empty($onboarding_complete) && !isset($_GET['edit'])) {
            wp_redirect(home_url('/trainer-dashboard/'));
            exit;
        }

        // Load full-page template directly (no theme header/footer wrapper)
        include PTP_PLUGIN_DIR . 'templates/trainer-onboarding-v133.php';
        exit;
    }

    public function redirect_old_trainer_urls() {
        if (is_page('trainer-profile') && !empty($_GET['trainer'])) {
            wp_redirect(home_url('/trainer/' . sanitize_text_field($_GET['trainer']) . '/'), 301);
            exit;
        }
    }

    /**
     * v135: Handle admin impersonate/login-as trainer via magic link
     */
    public function handle_impersonate_login() {
        if (empty($_GET['ptp_impersonate'])) return;
        
        $token = sanitize_text_field($_GET['ptp_impersonate']);
        $data = get_transient('ptp_impersonate_' . $token);
        
        if (!$data || empty($data['trainer_user_id'])) {
            wp_die('This login link has expired or is invalid. Please generate a new one from the admin panel.', 'Link Expired', array('response' => 403));
        }
        
        // Delete transient so it can only be used once
        delete_transient('ptp_impersonate_' . $token);
        
        // Store admin ID for "return to admin" functionality
        $admin_id = $data['admin_user_id'];
        
        // Log out current session and log in as trainer
        wp_clear_auth_cookie();
        wp_set_current_user($data['trainer_user_id']);
        wp_set_auth_cookie($data['trainer_user_id'], false);
        
        // Store admin user ID in a cookie so we can offer "return to admin"
        setcookie('ptp_admin_return', $admin_id, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        
        ptp_log('[PTP v135] Admin #' . $admin_id . ' impersonating trainer user #' . $data['trainer_user_id']);
        
        // Redirect to trainer dashboard (strip the token from URL)
        wp_redirect(home_url('/trainer-dashboard/'));
        exit;
    }

    public function ensure_roles_exist() {
        if (!get_role('ptp_trainer')) add_role('ptp_trainer', 'PTP Trainer', array('read' => true, 'upload_files' => true));
        if (!get_role('ptp_parent')) add_role('ptp_parent', 'PTP Parent', array('read' => true));
    }

    public function maybe_create_pages() {
        // v227: Each page maps to slug => array(title, shortcode)
        // Shortcodes must match what's actually registered in the plugin
        $pages = array(
            'find-trainers'       => array('Find Trainers',             '[ptp_find_trainers]'),
            'ptp-cart'            => array('Cart',                       '[ptp_cart]'),
            'ptp-checkout'        => array('Checkout',                   '[ptp_checkout]'),
            'thank-you'           => array('Thank You',                  '[ptp_thank_you]'),
            'login'               => array('Login',                      '[ptp_login]'),
            'register'            => array('Register',                   '[ptp_register]'),
            'apply'               => array('Apply to Coach',             '[ptp_apply]'),
            'trainer-dashboard'   => array('Trainer Dashboard',          '[ptp_trainer_dashboard]'),
            'dashboard'           => array('Dashboard',                  '[ptp_trainer_dashboard]'),
            'parent-dashboard'    => array('My Training',                '[ptp_parent_dashboard]'),
            'trainer-onboarding'  => array('Complete Your Profile',      '[ptp_trainer_onboarding]'),
            'account'             => array('Account Settings',           '[ptp_account]'),
            'forgot-password'     => array('Forgot Password',            '[ptp_forgot_password]'),
            'reset-password'      => array('Reset Password',             '[ptp_reset_password]'),
            'logout'              => array('Logout',                     '[ptp_logout]'),
            'summer-camps'        => array('Summer Camps',               '[ptp_camps]'),
            'training'            => array('Private Soccer Training',    '[ptp_training]'),
            'mentorship-signup'   => array('Mentorship Signup',          '[ptp_mentorship_signup]'),
            'mentorship-interest' => array('Mentorship Interest',        '[ptp_mentorship_interest]'),
            'confirm-session'     => array('Confirm Session',            '[ptp_confirm_session]'),
            'mentorship-checkout' => array('Mentorship Checkout',        '[ptp_mentorship_checkout]'),
            'mentorship'          => array('Mentorship',                 '[ptp_mentorship_hub]'),
        );
        foreach ($pages as $slug => $info) {
            if (!get_page_by_path($slug)) {
                wp_insert_post(array(
                    'post_title'   => $info[0],
                    'post_name'    => $slug,
                    'post_content' => $info[1],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ));
            }
        }
    }

    public function enqueue_scripts() {
        // v216: Preconnect to Google Fonts for faster font loading
        echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        
        // Google Fonts — Oswald + Inter + Playfair Display (used via --mc-font-serif)
        wp_enqueue_style('ptp-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600&display=swap', array(), null);
        
        // v228: Design tokens — single source of truth for colors, spacing, z-index scale
        wp_enqueue_style('ptp-tokens', PTP_PLUGIN_URL . 'assets/css/ptp-tokens.css', array(), PTP_VERSION);
        
        // v223: Base minified CSS (core variables, resets, homepage styles)
        wp_enqueue_style('ptp-v216', PTP_PLUGIN_URL . 'assets/css/ptp-v216.min.css', array('ptp-tokens'), PTP_VERSION);
        
        // v223: Global CSS — responsive + mobile (loaded on ALL pages, min.css bundle is stale/incomplete)
        wp_enqueue_style('ptp-responsive-v142', PTP_PLUGIN_URL . 'assets/css/ptp-responsive-v142.css', array('ptp-v216'), PTP_VERSION);
        wp_enqueue_style('ptp-mobile-v85', PTP_PLUGIN_URL . 'assets/css/ptp-mobile-v85.css', array('ptp-v216'), PTP_VERSION);
        wp_enqueue_style('ptp-universal-mobile', PTP_PLUGIN_URL . 'assets/css/ptp-universal-mobile.css', array('ptp-v216'), PTP_VERSION);
        
        // v216: Single combined+minified JS (was 2 files)
        wp_enqueue_script('ptp-v216', PTP_PLUGIN_URL . 'assets/js/ptp-v216.min.js', array('jquery'), PTP_VERSION, true);

        // ── v223: Page-specific CSS + JS ──────────────────────────────────────
        // Trainer profile (virtual page via /trainer/{slug}/)
        if (get_query_var('trainer_slug')) {
            // v235.8: Profile is fully standalone — dequeue ALL unused global assets
            // CSS: profile has its own complete stylesheet
            wp_dequeue_style('ptp-v216');
            wp_dequeue_style('ptp-responsive-v142');
            wp_dequeue_style('ptp-mobile-v85');
            wp_dequeue_style('ptp-universal-mobile');
            wp_dequeue_style('ptp-google-fonts');
            // JS: profile JS is vanilla — zero jQuery or ptp-v216 dependencies
            wp_dequeue_script('ptp-v216');
            wp_dequeue_script('jquery');
            wp_dequeue_script('jquery-core');
            wp_dequeue_script('jquery-migrate');
            // Astra theme assets
            wp_dequeue_style('astra-theme-css');
            wp_dequeue_style('astra-addon-css');
            wp_dequeue_script('astra-addon-js');
            
            // Only load: tokens (3KB) + DM Sans/Serif font + profile CSS (41KB)
            // v242: JS loaded directly in template (after TP_CONFIG) — not via wp_enqueue to avoid double-init
            wp_enqueue_style('ptp-tp-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap', array(), null);
            wp_enqueue_style('ptp-trainer-profile', PTP_PLUGIN_URL . 'assets/css/trainer-profile-v177.css', array('ptp-tokens'), PTP_VERSION);
            
            // v235.8: Make Google Fonts non-render-blocking (media=print → swap to all on load)
            add_filter('style_loader_tag', function($tag, $handle) {
                if ($handle === 'ptp-tp-fonts') {
                    return str_replace("media='all'", "media='print' onload=\"this.media='all'\"", $tag);
                }
                return $tag;
            }, 10, 2);
        }

        // Trainer dashboard
        if (is_page(array('trainer-dashboard', 'dashboard'))) {
            wp_enqueue_style('ptp-trainer-dashboard', PTP_PLUGIN_URL . 'assets/css/trainer-dashboard-v200.css', array(), PTP_VERSION);
            wp_enqueue_script('ptp-trainer-dashboard', PTP_PLUGIN_URL . 'assets/js/trainer-dashboard-v200.js', array('jquery'), PTP_VERSION, true);
        }

        // Parent dashboard
        if (is_page('parent-dashboard')) {
            wp_enqueue_style('ptp-parent-dashboard', PTP_PLUGIN_URL . 'assets/css/parent-dashboard-v200.css', array(), PTP_VERSION);
            wp_enqueue_script('ptp-parent-dashboard', PTP_PLUGIN_URL . 'assets/js/parent-dashboard-v200.js', array('jquery'), PTP_VERSION, true);
        }

        // Masterclass / training landing / find-trainers / cart pages
        $mc_pages = array('training', 'ptp-training', 'private-training', 'training-landing', 'find-trainers', 'ptp-cart', 'cart');
        $is_mc_page = false;
        foreach ($mc_pages as $pg) {
            if (is_page($pg)) { $is_mc_page = true; break; }
        }
        if ($is_mc_page || get_query_var('ptp_page') === 'training') {
            wp_enqueue_style('ptp-masterclass', PTP_PLUGIN_URL . 'assets/css/ptp-masterclass.css', array('ptp-v216'), PTP_VERSION);
        }

        // All Access pages
        if (is_page(array('all-access', 'all-access-pass', 'membership', 'ptp-subscribe', 'ptp-mentorship'))) {
            wp_enqueue_style('ptp-all-access', PTP_PLUGIN_URL . 'assets/css/ptp-all-access.css', array('ptp-v216'), PTP_VERSION);
            wp_enqueue_script('ptp-all-access', PTP_PLUGIN_URL . 'assets/js/ptp-all-access.js', array('jquery'), PTP_VERSION, true);
        }

        // Messaging page
        if (is_page(array('messages', 'messaging'))) {
            wp_enqueue_script('ptp-messaging', PTP_PLUGIN_URL . 'assets/js/messaging.js', array('jquery'), PTP_VERSION, true);
        }

        // v177: Hide chat widgets on mobile for PTP standalone pages
        if (wp_is_mobile() || true) { // Always add, CSS handles the media query
            $is_standalone = false;
            // v200.4: Don't treat homepage as standalone even if training page is front page
            if (!is_front_page()) {
                $standalone_pages = array('ptp-checkout', 'checkout', 'ptp-cart', 'cart', 'trainer-dashboard', 'dashboard', 'parent-dashboard', 'trainer-onboarding', 'thank-you');
                foreach ($standalone_pages as $pg) {
                    if (is_page($pg)) { $is_standalone = true; break; }
                }
            }
            if ($is_standalone || get_query_var('trainer_slug') || get_query_var('ptp_page') === 'training') {
                wp_add_inline_script('ptp-v216', '
(function(){if(window.innerWidth>1023)return;var sels=["#tidio-chat","#crisp-chatbox",".crisp-client","#intercom-container","#intercom-frame",".intercom-lightweight-app","#hubspot-messages-iframe-container","#tawk-bubble-container","#tawkchat-container",".tawk-min-container","#drift-widget-container","#fc_frame","#fc_widget",".fb_dialog",".fb_iframe_widget","#livechat-eye-catcher","#livechat-compact-container","#chat-widget-container",".joinchat",".wp-social-chat-container","#olark-wrapper",".olark-launch-button","[id*=whatsapp]","[class*=whatsapp-chat]","[class*=chat-bubble]","[class*=chat-widget]","[id*=chat-widget]","[id*=chatWidget]"];function h(){sels.forEach(function(s){try{document.querySelectorAll(s).forEach(function(e){e.style.cssText="display:none!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important;height:0!important;width:0!important;overflow:hidden!important"})}catch(x){}})}h();setTimeout(h,1000);setTimeout(h,3000);setTimeout(h,5000);if(window.MutationObserver){var o=new MutationObserver(function(){h()});o.observe(document.body,{childList:true,subtree:true})}})();
                ');
            }
        }

        wp_localize_script('ptp-v216', 'ptp_ajax', array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_ajax_nonce'),
            'home_url' => home_url(),
            'cart_url' => home_url('/ptp-cart/'),
            'checkout_url' => home_url('/ptp-checkout/'),
            'currency' => ptp_get_currency(),
            'currency_symbol' => ptp_get_currency_symbol(),
        ));

        // Camp product styling now handled by ptp-camps plugin and theme files

        // v177: Stripe needed on checkout AND cart pages (cart has unified checkout form)
        if (is_page(array('ptp-checkout', 'checkout', 'ptp-cart'))) {
            wp_enqueue_style('ptp-checkout', PTP_PLUGIN_URL . 'assets/css/ptp-checkout.css', array(), PTP_VERSION);
            wp_enqueue_style('ptp-checkout-mobile', PTP_PLUGIN_URL . 'assets/css/checkout-mobile-v158.css', array('ptp-checkout'), PTP_VERSION);
            // v228: Extracted inline CSS to external file (was 380 lines in template)
            wp_enqueue_style('ptp-checkout-inline', PTP_PLUGIN_URL . 'assets/css/ptp-checkout-inline.css', array('ptp-tokens'), PTP_VERSION);
        }
        
        // v228: Extracted inline CSS from templates → external cacheable files
        if (is_page(array('find-trainers'))) {
            wp_enqueue_style('ptp-trainers-grid', PTP_PLUGIN_URL . 'assets/css/ptp-trainers-grid.css', array('ptp-tokens'), PTP_VERSION);
        }
        if (is_page(array('training', 'ptp-training', 'private-training'))) {
            wp_enqueue_style('ptp-training-hub', PTP_PLUGIN_URL . 'assets/css/ptp-training-hub.css', array('ptp-tokens'), PTP_VERSION);
        }
        if (is_page(array('thank-you', 'ptp-thank-you'))) {
            wp_enqueue_style('ptp-thank-you', PTP_PLUGIN_URL . 'assets/css/ptp-thank-you.css', array('ptp-tokens'), PTP_VERSION);
        }
        if (is_page(array('trainer-onboarding', 'apply', 'become-a-trainer'))) {
            wp_enqueue_style('ptp-onboarding', PTP_PLUGIN_URL . 'assets/css/ptp-onboarding.css', array('ptp-tokens'), PTP_VERSION);
        }
    }

    public function admin_scripts($hook) {
        if (strpos($hook, 'ptp') !== false) {
            wp_enqueue_style('ptp-admin', PTP_PLUGIN_URL . 'assets/css/admin.css', array(), PTP_VERSION);
            wp_enqueue_script('ptp-admin', PTP_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), PTP_VERSION, true);
            // v213: Media library needed for trainer gallery image picker
            if (strpos($hook, 'ptp-trainers') !== false) {
                wp_enqueue_media();
            }
        }
    }

    /**
     * v222: Auto-recover trainers if tables were wiped (e.g. by uninstall.php during bad upgrade)
     * Recovery sources (in priority order):
     *   1. WP users with ptp_trainer role (always survives uninstall)
     *   2. ptp_applications table (if it was recreated)
     */
    public function maybe_recover_trainers() {
        // Only run once per request, only in admin or on find-trainers page
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        
        // Skip if already recovered this version
        $recovered = get_option('ptp_trainers_recovered_version', '');
        if ($recovered === PTP_VERSION) return;
        
        global $wpdb;
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        
        // Step 1: Ensure tables exist
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$trainers_table}'") === $trainers_table;
        if (!$table_exists) {
            ptp_log('[PTP v222] Trainers table missing — recreating all tables');
            if (class_exists('PTP_Database')) PTP_Database::create_tables();
            if (class_exists('PTP_Mentorship')) PTP_Mentorship::create_tables();
            if (class_exists('PTP_Native_Session')) PTP_Native_Session::create_table();
            if (class_exists('PTP_Native_Cart')) PTP_Native_Cart::create_table();
            if (class_exists('PTP_Native_Order_Manager')) PTP_Native_Order_Manager::create_tables();
            if (class_exists('PTP_Fixes_V72')) PTP_Fixes_V72::instance()->comprehensive_table_repair();
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$trainers_table}'") === $trainers_table;
            if (!$table_exists) {
                ptp_log('[PTP v222] CRITICAL: Failed to create trainers table');
                return;
            }
        }
        
        // Step 2: Check if trainers table has data
        $trainer_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$trainers_table}");
        if ($trainer_count > 0) {
            // Trainers exist — no recovery needed
            update_option('ptp_trainers_recovered_version', PTP_VERSION);
            return;
        }
        
        // Step 3: Table is empty — find WP users with ptp_trainer role
        ptp_log('[PTP v222] Trainers table is EMPTY — starting auto-recovery from WP users');
        
        $trainer_users = get_users(array(
            'role' => 'ptp_trainer',
            'number' => 100,
        ));
        
        // Also check users with role in capabilities even if role definition was removed
        if (empty($trainer_users)) {
            $trainer_users = $wpdb->get_results("
                SELECT u.ID, u.user_email, u.display_name, u.user_registered
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
                WHERE um.meta_key = '{$wpdb->prefix}capabilities'
                AND um.meta_value LIKE '%ptp_trainer%'
                LIMIT 100
            ");
        }
        
        if (empty($trainer_users)) {
            ptp_log('[PTP v222] No WP users with ptp_trainer role found — cannot auto-recover');
            // Still mark as checked so we don't run on every page load
            update_option('ptp_trainers_recovered_version', PTP_VERSION);
            
            // Show admin notice
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>PTP Training Platform:</strong> The trainers table is empty and no trainer users were found. ';
                echo 'Please restore your database from a backup, or re-approve trainers from Applications.</p></div>';
            });
            return;
        }
        
        ptp_log('[PTP v222] Found ' . count($trainer_users) . ' WP users with ptp_trainer role — rebuilding records');
        $recovered_count = 0;
        
        // Ensure roles exist
        $this->ensure_roles_exist();
        
        foreach ($trainer_users as $tu) {
            $user_id = is_object($tu) && isset($tu->ID) ? $tu->ID : $tu->ID;
            $user = get_user_by('ID', $user_id);
            if (!$user) continue;
            
            // Check if record already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$trainers_table} WHERE user_id = %d", $user_id
            ));
            if ($exists) continue;
            
            // Gather data from user meta (some may have survived or been re-added)
            $phone = get_user_meta($user_id, 'ptp_phone', true) ?: '';
            $city = get_user_meta($user_id, 'ptp_city', true) ?: '';
            $state = get_user_meta($user_id, 'ptp_state', true) ?: '';
            $location = ($city && $state) ? "{$city}, {$state}" : ($city ?: $state);
            $hourly_rate = get_user_meta($user_id, 'ptp_hourly_rate', true) ?: 75;
            $photo = get_user_meta($user_id, 'ptp_photo_url', true) ?: '';
            $bio = get_user_meta($user_id, 'ptp_bio', true) ?: '';
            $college = get_user_meta($user_id, 'ptp_college', true) ?: '';
            $team = get_user_meta($user_id, 'ptp_team', true) ?: '';
            $playing_level = get_user_meta($user_id, 'ptp_playing_level', true) ?: '';
            $specialties = get_user_meta($user_id, 'ptp_specialties', true) ?: '';
            $instagram = get_user_meta($user_id, 'ptp_instagram', true) ?: '';
            $headline = get_user_meta($user_id, 'ptp_headline', true) ?: '';
            $stripe_acct = get_user_meta($user_id, 'ptp_stripe_account_id', true) ?: '';
            
            // Also check for application data
            $app_table = $wpdb->prefix . 'ptp_applications';
            $app_exists = $wpdb->get_var("SHOW TABLES LIKE '{$app_table}'") === $app_table;
            $app = null;
            if ($app_exists) {
                $app = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$app_table} WHERE user_id = %d OR email = %s ORDER BY id DESC LIMIT 1",
                    $user_id, $user->user_email
                ));
            }
            
            // Build slug
            $base_slug = sanitize_title($user->display_name ?: 'trainer');
            $slug = $base_slug . '-' . $user_id;
            
            // Merge app data if available (app data is richer)
            $insert = array(
                'user_id'          => $user_id,
                'display_name'     => $user->display_name ?: ($app ? $app->name : 'Trainer'),
                'slug'             => $slug,
                'email'            => $user->user_email,
                'phone'            => $phone ?: ($app ? ($app->phone ?? '') : ''),
                'status'           => 'active',
                'hourly_rate'      => $hourly_rate ?: ($app ? (floatval($app->hourly_rate ?? 75)) : 75),
                'location'         => $location ?: ($app ? ($app->location ?? '') : ''),
                'city'             => $city ?: ($app ? ($app->city ?? '') : ''),
                'state'            => $state ?: ($app ? ($app->state ?? '') : ''),
                'college'          => $college ?: ($app ? ($app->college ?? '') : ''),
                'team'             => $team ?: ($app ? ($app->team ?? '') : ''),
                'playing_level'    => $playing_level ?: ($app ? ($app->playing_level ?? '') : ''),
                'specialties'      => $specialties ?: ($app ? ($app->specialties ?? '') : ''),
                'instagram'        => $instagram ?: ($app ? ($app->instagram ?? '') : ''),
                'headline'         => $headline ?: ($app ? ($app->headline ?? '') : ''),
                'bio'              => $bio ?: ($app ? ($app->bio ?? '') : ''),
                'photo_url'        => $photo,
                'stripe_account_id'=> $stripe_acct,
                'approved_at'      => $user->user_registered,
                'is_featured'      => 0,
                'average_rating'   => 5.0,
                'review_count'     => 0,
                'total_sessions'   => 0,
            );
            
            $result = $wpdb->insert($trainers_table, $insert);
            if ($result) {
                $recovered_count++;
                $tid = $wpdb->insert_id;
                ptp_log("[PTP v222] Recovered trainer: {$insert['display_name']} (ID:{$tid}, user:{$user_id})");
            } else {
                ptp_log("[PTP v222] FAILED to recover trainer for user {$user_id}: " . $wpdb->last_error);
            }
        }
        
        // Clear caches
        delete_transient('ptp_active_trainers');
        delete_transient('ptp_trainer_count');
        wp_cache_flush();
        
        ptp_log("[PTP v222] Recovery complete: {$recovered_count} trainers restored from WP users");
        update_option('ptp_trainers_recovered_version', PTP_VERSION);
        
        if ($recovered_count > 0) {
            add_action('admin_notices', function() use ($recovered_count) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>PTP Training Platform:</strong> ';
                echo "Auto-recovered {$recovered_count} trainer(s) from WordPress user accounts. ";
                echo 'Please review trainer profiles in the Trainers admin to verify all data is correct.</p></div>';
            });
        }
    }

    public function activate() {
        if (class_exists('PTP_Database')) PTP_Database::create_tables();
        if (class_exists('PTP_Mentorship')) PTP_Mentorship::create_tables();
        if (class_exists('PTP_Native_Session')) PTP_Native_Session::create_table();
        if (class_exists('PTP_Native_Cart')) PTP_Native_Cart::create_table();
        if (class_exists('PTP_Native_Order_Manager')) PTP_Native_Order_Manager::create_tables();
        // v176: Auto-repair from masterclass integration
        if (class_exists('PTP_Fixes_V72')) PTP_Fixes_V72::instance()->comprehensive_table_repair();
        $this->ensure_roles_exist();
        
        // Use the comprehensive page creator
        if (class_exists('PTP_Page_Creator')) {
            PTP_Page_Creator::activate();
        } else {
            $this->maybe_create_pages(); // Fallback
        }
        
        if (get_option('ptp_platform_fee') === false) update_option('ptp_platform_fee', 20);
        if (get_option('ptp_currency') === false) update_option('ptp_currency', 'usd');
        
        // v177: Register rewrite rules BEFORE flushing so /trainer/slug/ works immediately
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        
        // v187.3: Clear stale trainer caches on activation/update
        delete_transient('ptp_active_trainers');
        delete_transient('ptp_trainer_count');
        wp_cache_flush();
        
        update_option('ptp_plugin_version', PTP_VERSION);
        update_option('ptp_version', PTP_VERSION);
    }

    public function deactivate() {
        wp_clear_scheduled_hook('ptp_cleanup_native_sessions');
        wp_clear_scheduled_hook('ptp_cron_hourly');
        wp_clear_scheduled_hook('ptp_cron_daily');
        wp_clear_scheduled_hook('ptp_process_escrow_releases');
        wp_clear_scheduled_hook('ptp_send_review_prompts');
        wp_clear_scheduled_hook('ptp_check_missing_notes');
        wp_clear_scheduled_hook('ptp_update_response_times');
        flush_rewrite_rules();
    }
}

/**
 * v165: Cache Invalidation System
 * 
 * Clears transient caches when relevant data changes.
 * Improves performance by caching expensive queries while
 * ensuring data freshness when bookings/trainers are updated.
 */
class PTP_Cache_Invalidation {
    
    public static function init() {
        // Booking changes
        add_action('ptp_booking_completed', array(__CLASS__, 'clear_dashboard_caches'), 10, 2);
        add_action('ptp_booking_created', array(__CLASS__, 'clear_dashboard_caches'), 10, 2);
        add_action('ptp_booking_cancelled', array(__CLASS__, 'clear_dashboard_caches'), 10, 2);
        
        // Trainer changes
        add_action('ptp_trainer_updated', array(__CLASS__, 'clear_trainer_caches'));
        add_action('profile_update', array(__CLASS__, 'maybe_clear_trainer_cache'));
        
        // Admin actions
        add_action('save_post', array(__CLASS__, 'maybe_clear_on_post_save'), 10, 2);
    }
    
    /**
     * Clear dashboard caches when booking changes
     */
    public static function clear_dashboard_caches($booking_id, $booking = null) {
        global $wpdb;
        
        // Get booking if not provided
        if (!$booking) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, t.user_id as trainer_user_id, p.user_id as parent_user_id
                 FROM {$wpdb->prefix}ptp_bookings b
                 LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
                 WHERE b.id = %d",
                $booking_id
            ));
        }
        
        if (!$booking) return;
        
        // Clear trainer dashboard cache
        if (!empty($booking->trainer_id)) {
            delete_transient('ptp_trainer_dash_' . $booking->trainer_id);
        }
        
        // Clear parent dashboard cache
        if (!empty($booking->parent_id)) {
            delete_transient('ptp_parent_dash_' . $booking->parent_id);
        }
        
        ptp_log('[PTP Cache v165] Cleared dashboard caches for booking #' . $booking_id);
    }
    
    /**
     * Clear trainer-related caches
     */
    public static function clear_trainer_caches($trainer_id = null) {
        // Clear all trainer list caches
        delete_transient('ptp_active_trainers');
        delete_transient('ptp_active_trainer_count');
        delete_transient('ptp_featured_trainers_landing');
        
        // Clear specific trainer dashboard if ID provided
        if ($trainer_id) {
            delete_transient('ptp_trainer_dash_' . $trainer_id);
        }
        
        ptp_log('[PTP Cache v165] Cleared trainer caches' . ($trainer_id ? ' for trainer #' . $trainer_id : ''));
    }
    
    /**
     * Check if user is trainer and clear cache
     */
    public static function maybe_clear_trainer_cache($user_id) {
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if ($trainer) {
            self::clear_trainer_caches($trainer->id);
        }
    }
    
    /**
     * Clear caches when relevant post types are saved
     */
    public static function maybe_clear_on_post_save($post_id, $post) {
        if (wp_is_post_revision($post_id)) return;
        
        // Camp products
        if ($post->post_type === 'ptp_camp_product') {
            delete_transient('ptp_camp_products');
        }
    }
}

// Initialize cache invalidation
add_action('init', array('PTP_Cache_Invalidation', 'init'), 99);

function PTP() { return PTP_Training_Platform::instance(); }
add_action('plugins_loaded', 'PTP', 10);

// v187.1: Auto-migrate missing columns on existing installs
add_action('plugins_loaded', function() {
    if (class_exists('PTP_Database')) {
        PTP_Database::maybe_migrate();
    }
}, 20);

/**
 * v131: Server-side login rate limiter
 * Increments on actual failed login attempts, not URL params
 */
add_action('wp_login_failed', function($username) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    $key = 'ptp_login_attempts_' . md5($ip);
    $attempts = get_transient($key) ?: 0;
    set_transient($key, $attempts + 1, 15 * MINUTE_IN_SECONDS);

    // v198: Redirect failed logins back to custom login page (not default wp-login.php)
    $redirect_url = home_url('/login/?login=failed');
    if (!empty($_REQUEST['redirect_to'])) {
        $redirect_url = add_query_arg('redirect_to', urlencode($_REQUEST['redirect_to']), $redirect_url);
    }
    wp_safe_redirect($redirect_url);
    exit;
});

add_filter('authenticate', function($user, $username, $password) {
    if (empty($username) || empty($password)) return $user;
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    $key = 'ptp_login_attempts_' . md5($ip);
    $attempts = get_transient($key) ?: 0;
    if ($attempts >= 5) {
        return new WP_Error('too_many_attempts', 'Too many failed login attempts. Please try again in 15 minutes.');
    }
    return $user;
}, 30, 3);

// Clear login attempts on successful login
add_action('wp_login', function($username) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    delete_transient('ptp_login_attempts_' . md5($ip));
});

/**
 * v198: Route users to correct dashboard after login via wp-login.php
 * Uses PTP_User::get_dashboard_url() for consistent trainer/parent/admin detection
 */
add_filter('login_redirect', function($redirect_to, $requested_redirect_to, $user) {
    if (is_wp_error($user) || !($user instanceof WP_User)) {
        return $redirect_to;
    }
    
    // If user explicitly specified a redirect (e.g. from a ?redirect_to= param), honor it
    // unless it's the generic parent-dashboard default
    $parent_dash = home_url('/parent-dashboard/');
    if (!empty($requested_redirect_to) && $requested_redirect_to !== $parent_dash && $requested_redirect_to !== admin_url()) {
        return $redirect_to;
    }
    
    // Use unified dashboard routing
    if (class_exists('PTP_User')) {
        return PTP_User::get_dashboard_url($user->ID);
    }
    
    // Fallback: check roles directly
    $roles = (array) $user->roles;
    if (in_array('ptp_trainer', $roles) || in_array('trainer', $roles)) {
        return home_url('/trainer-dashboard/');
    }
    
    return $redirect_to;
}, 10, 3);

/**
 * v198: Redirect wp-login.php GET requests to custom /login/ page
 * Prevents users from seeing the default WordPress login form.
 * POST requests are allowed through for authentication processing.
 */
add_action('login_init', function() {
    // Allow POST (form submission) and AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST') return;
    // Allow logout action
    if (isset($_GET['action']) && in_array($_GET['action'], array('logout', 'confirmaction'))) return;
    // Allow interim login (in-iframe re-auth)
    if (isset($_GET['interim-login'])) return;
    // Allow wp-cli / cron
    if (defined('WP_CLI') || defined('DOING_CRON')) return;
    
    // v214: Redirect lostpassword to custom page
    if (isset($_GET['action']) && $_GET['action'] === 'lostpassword') {
        wp_safe_redirect(home_url('/forgot-password/'));
        exit;
    }
    
    // v214: Redirect resetpass/rp to custom page (preserve key and login params)
    if (isset($_GET['action']) && in_array($_GET['action'], array('rp', 'resetpass'))) {
        $reset_url = home_url('/reset-password/');
        if (!empty($_GET['key'])) $reset_url = add_query_arg('key', $_GET['key'], $reset_url);
        if (!empty($_GET['login'])) $reset_url = add_query_arg('login', rawurlencode($_GET['login']), $reset_url);
        wp_safe_redirect($reset_url);
        exit;
    }
    
    // v214: Allow register action through (WP handles it)
    if (isset($_GET['action']) && $_GET['action'] === 'register') {
        wp_safe_redirect(home_url('/register/'));
        exit;
    }

    $login_url = home_url('/login/');
    if (!empty($_GET['redirect_to'])) {
        $login_url = add_query_arg('redirect_to', urlencode($_GET['redirect_to']), $login_url);
    }
    wp_safe_redirect($login_url);
    exit;
});

/**
 * v214: Override password reset email to use custom reset page URL
 */
add_filter('retrieve_password_message', function($message, $key, $user_login, $user_data) {
    // Replace wp-login.php reset URL with our custom page
    $old_url = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user_login), 'login');
    $new_url = home_url('/reset-password/') . '?key=' . $key . '&login=' . rawurlencode($user_login);
    $message = str_replace($old_url, $new_url, $message);
    
    // Also catch any other format WP might use
    $message = preg_replace(
        '#https?://[^\s]+wp-login\.php\?action=rp[^\s]*#',
        $new_url,
        $message
    );
    
    return $message;
}, 10, 4);

/**
 * v214: Override lostpassword_url to point to custom page
 */
add_filter('lostpassword_url', function($url, $redirect) {
    $custom_url = home_url('/forgot-password/');
    if (!empty($redirect)) {
        $custom_url = add_query_arg('redirect_to', urlencode($redirect), $custom_url);
    }
    return $custom_url;
}, 10, 2);

/**
 * v180: AJAX handler to update Stripe PaymentIntent amount
 * Called when coupon/referral codes change the checkout total
 */
add_action('wp_ajax_ptp_update_payment_intent', 'ptp_ajax_update_payment_intent');
add_action('wp_ajax_nopriv_ptp_update_payment_intent', 'ptp_ajax_update_payment_intent');

function ptp_ajax_update_payment_intent() {
    check_ajax_referer('ptp_checkout', 'nonce');
    
    $payment_intent = sanitize_text_field($_POST['payment_intent'] ?? '');
    $amount = intval($_POST['amount'] ?? 0);
    $coupon_discount = floatval($_POST['coupon_discount'] ?? 0);
    $referral_discount = floatval($_POST['referral_discount'] ?? 0);
    
    if (empty($payment_intent) || !preg_match('/^pi_/', $payment_intent)) {
        wp_send_json_error(array('message' => 'Invalid payment intent'));
    }
    
    if ($amount < 50) {
        if ($amount == 0) {
            wp_send_json_success(array('message' => 'Free session — payment will be skipped', 'free' => true));
        }
        wp_send_json_error(array('message' => 'Amount too low'));
    }
    
    // Get Stripe secret key
    $stripe_mode = get_option('ptp_stripe_mode', 'test');
    $stripe_sk = get_option('ptp_stripe_' . $stripe_mode . '_secret', '');
    if (empty($stripe_sk)) {
        $stripe_sk = get_option('ptp_stripe_secret_key', '');
    }
    
    if (empty($stripe_sk)) {
        wp_send_json_error(array('message' => 'Payment not configured'));
    }
    
    // Build metadata for discount tracking
    $metadata = array();
    if ($coupon_discount > 0) {
        $metadata['metadata[coupon_discount]'] = $coupon_discount;
    }
    if ($referral_discount > 0) {
        $metadata['metadata[referral_discount]'] = $referral_discount;
    }
    
    // Attach customer retroactively if email/name provided
    $upd_email = sanitize_email($_POST['email'] ?? $_POST['customer_email'] ?? '');
    $upd_first = sanitize_text_field($_POST['first_name'] ?? '');
    $upd_last = sanitize_text_field($_POST['last_name'] ?? '');
    $upd_name = trim($upd_first . ' ' . $upd_last);
    $upd_phone = sanitize_text_field($_POST['phone'] ?? '');
    
    if (!empty($upd_email) && class_exists('PTP_Stripe') && method_exists('PTP_Stripe', 'find_or_create_customer_by_email')) {
        $upd_customer_id = PTP_Stripe::find_or_create_customer_by_email($upd_email, $upd_name, $upd_phone);
        if ($upd_customer_id) {
            $metadata['customer'] = $upd_customer_id;
        }
        $metadata['receipt_email'] = $upd_email;
        $metadata['metadata[customer_email]'] = $upd_email;
    }
    if (!empty($upd_name)) {
        $metadata['metadata[customer_name]'] = $upd_name;
    }
    
    // Update the PaymentIntent via Stripe API
    $response = wp_remote_post('https://api.stripe.com/v1/payment_intents/' . $payment_intent, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $stripe_sk,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'body' => array_merge(array(
            'amount' => $amount,
        ), $metadata),
        'timeout' => 15,
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Network error'));
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (!empty($body['error'])) {
        wp_send_json_error(array('message' => $body['error']['message'] ?? 'Stripe error'));
    }
    
    wp_send_json_success(array(
        'amount' => $body['amount'] ?? $amount,
        'message' => 'PaymentIntent updated'
    ));
}

