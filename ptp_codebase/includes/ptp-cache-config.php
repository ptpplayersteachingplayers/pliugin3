<?php
/**
 * PTP Cache Configuration
 * 
 * This file contains cache configuration settings for various caching plugins
 * and hosting providers. The PTP plugin automatically applies these rules
 * when the respective plugins are detected.
 * 
 * @version 72.0.0
 */

defined('ABSPATH') || exit;

/**
 * Pages that must be excluded from caching
 * These pages contain dynamic content that changes based on user state
 */
$ptp_no_cache_pages = array(
    '/trainer-dashboard/',
    '/parent-dashboard/',
    '/training-checkout/',
    '/ptp-checkout/',
    '/checkout/',
    '/ptp-cart/',
    '/cart/',
    '/messages/',
    '/account/',
    '/login/',
    '/register/',
    '/logout/',
    '/apply/',
    '/booking-confirmation/',
    '/trainer-onboarding/',
);

/**
 * Cookies that should bypass cache when present
 */
$ptp_cache_bypass_cookies = array(
    'wordpress_logged_in_',
    'ptp-native_cart_hash',
    'ptp-native_items_in_cart',
    'ptp_session',
);

/**
 * Query strings that should bypass cache
 */
$ptp_cache_bypass_query_strings = array(
    'stripe_setup',
    'booking',
    'confirm',
    'cancel',
    'ptp_action',
);

// ============================================================================
// WP ROCKET CONFIGURATION
// ============================================================================
// Add to wp-config.php or functions.php if auto-detection doesn't work:
/*
add_filter('rocket_cache_reject_uri', function($uris) {
    $ptp_uris = array(
        '/trainer-dashboard/(.*)',
        '/parent-dashboard/(.*)',
        '/training-checkout/(.*)',
        '/checkout/(.*)',
        '/cart/(.*)',
        '/messages/(.*)',
        '/account/(.*)',
        '/booking-confirmation/(.*)',
    );
    return array_merge($uris, $ptp_uris);
});
*/

// ============================================================================
// W3 TOTAL CACHE CONFIGURATION
// ============================================================================
// Add these URIs to "Never cache the following pages" in W3TC settings:
/*
trainer-dashboard/*
parent-dashboard/*
training-checkout/*
checkout/*
cart/*
messages/*
account/*
booking-confirmation/*
*/

// ============================================================================
// LITESPEED CACHE CONFIGURATION
// ============================================================================
// Add to .htaccess in your WordPress root:
/*
<IfModule LiteSpeed>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/trainer-dashboard/ [OR]
    RewriteCond %{REQUEST_URI} ^/parent-dashboard/ [OR]
    RewriteCond %{REQUEST_URI} ^/training-checkout/ [OR]
    RewriteCond %{REQUEST_URI} ^/checkout/ [OR]
    RewriteCond %{REQUEST_URI} ^/cart/ [OR]
    RewriteCond %{REQUEST_URI} ^/messages/ [OR]
    RewriteCond %{REQUEST_URI} ^/account/ [OR]
    RewriteCond %{REQUEST_URI} ^/booking-confirmation/
    RewriteRule .* - [E=Cache-Control:no-cache]
</IfModule>
*/

// ============================================================================
// CLOUDFLARE PAGE RULES
// ============================================================================
// Create these page rules in Cloudflare dashboard:
/*
Rule 1: yourdomain.com/trainer-dashboard/*
- Cache Level: Bypass

Rule 2: yourdomain.com/parent-dashboard/*
- Cache Level: Bypass

Rule 3: yourdomain.com/*checkout*
- Cache Level: Bypass

Rule 4: yourdomain.com/cart/*
- Cache Level: Bypass

Rule 5: yourdomain.com/messages/*
- Cache Level: Bypass
*/

// ============================================================================
// NGINX CONFIGURATION
// ============================================================================
// Add to your nginx.conf server block:
/*
# PTP Training Platform - Bypass cache for dynamic pages
location ~* ^/(trainer-dashboard|parent-dashboard|training-checkout|checkout|cart|messages|account|booking-confirmation)/ {
    add_header Cache-Control "no-cache, no-store, must-revalidate";
    add_header Pragma "no-cache";
    add_header Expires "0";
    
    # If using FastCGI cache
    fastcgi_cache_bypass 1;
    fastcgi_no_cache 1;
    
    # If using proxy cache
    proxy_cache_bypass 1;
    proxy_no_cache 1;
}
*/

// ============================================================================
// VARNISH CACHE CONFIGURATION
// ============================================================================
// Add to your VCL file:
/*
sub vcl_recv {
    # PTP Training Platform - Bypass cache for dynamic pages
    if (req.url ~ "^/(trainer-dashboard|parent-dashboard|training-checkout|checkout|cart|messages|account|booking-confirmation)/") {
        return (pass);
    }
}
*/

// ============================================================================
// KINSTA / WP ENGINE / FLYWHEEL
// ============================================================================
// These managed WordPress hosts typically auto-exclude pages with:
// - Logged-in users
// - PTP Native cart/checkout
// 
// If you need additional exclusions, contact their support and provide:
// - /trainer-dashboard/*
// - /parent-dashboard/*
// - /messages/*
// - /booking-confirmation/*

// ============================================================================
// REDIS OBJECT CACHE
// ============================================================================
// Object caching is generally safe with PTP. However, if using Redis or Memcached,
// ensure session data is being properly isolated per user.

// ============================================================================
// PROGRAMMATIC CACHE HELPERS
// ============================================================================

/**
 * Check if current page should skip cache
 */
function ptp_should_skip_cache() {
    global $ptp_no_cache_pages;
    
    // Always skip for logged in users on PTP pages
    if (is_user_logged_in()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        foreach ($ptp_no_cache_pages as $pattern) {
            if (strpos($uri, rtrim($pattern, '/')) !== false) {
                return true;
            }
        }
    }
    
    // Skip if PTP Native cart has items
    if (false && ptp_cart() && !ptp_cart()->is_empty()) {
        return true;
    }
    
    return false;
}

/**
 * Add no-cache headers programmatically
 */
function ptp_add_no_cache_headers() {
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    }
}

/**
 * Set cache control constants for WordPress
 */
function ptp_define_cache_constants() {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEOBJECT')) {
        define('DONOTCACHEOBJECT', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }
}
