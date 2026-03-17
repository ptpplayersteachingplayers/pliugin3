<?php
/**
 * PTP Performance Optimizations v85.2
 * 
 * Handles caching, preloading, lazy loading, and speed optimizations.
 * 
 * @since 85.2.0
 */

defined('ABSPATH') || exit;

class PTP_Performance {
    
    private static $start_time;
    
    /**
     * Initialize performance optimizations
     */
    public static function init() {
        self::$start_time = microtime(true);
        
        // Early optimizations
        add_action('init', array(__CLASS__, 'early_optimizations'), 1);
        
        // Add resource hints (preconnect, dns-prefetch)
        add_action('wp_head', array(__CLASS__, 'add_resource_hints'), 1);
        
        // Preload critical assets on PTP pages
        add_action('wp_head', array(__CLASS__, 'preload_critical_assets'), 2);
        
        // Add lazy loading to images
        add_filter('wp_get_attachment_image_attributes', array(__CLASS__, 'add_lazy_loading'), 10, 3);
        
        // Defer non-critical CSS
        add_filter('style_loader_tag', array(__CLASS__, 'defer_non_critical_css'), 10, 4);
        
        // Optimize AJAX responses
        add_action('wp_ajax_nopriv_ptp_get_trainers', array(__CLASS__, 'set_ajax_headers'), 1);
        add_action('wp_ajax_ptp_get_trainers', array(__CLASS__, 'set_ajax_headers'), 1);
        
        // Cache trainers count in transient
        add_action('save_post', array(__CLASS__, 'maybe_clear_trainer_cache'));
        
        // Add performance timing footer (debug only)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('wp_footer', array(__CLASS__, 'output_timing'), 999);
        }
    }
    
    /**
     * Early optimizations - runs before headers sent
     */
    public static function early_optimizations() {
        // Disable heartbeat on frontend (save resources)
        if (!is_admin()) {
            wp_deregister_script('heartbeat');
        }
        
        // Remove unnecessary WordPress features on PTP pages
        if (self::is_ptp_page()) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('wp_head', 'wp_generator');
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'rsd_link');
        }
    }
    
    /**
     * Set optimal headers for AJAX responses
     */
    public static function set_ajax_headers() {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: public, max-age=300'); // 5 min cache for trainer list
            header('X-Content-Type-Options: nosniff');
        }
    }
    
    /**
     * Add resource hints for faster external resource loading
     */
    public static function add_resource_hints() {
        if (!self::is_ptp_page()) {
            return;
        }
        
        echo "\n<!-- PTP Performance v85.2: Resource Hints -->\n";
        
        // Critical preconnects
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '<link rel="preconnect" href="https://js.stripe.com">' . "\n";
        
        // DNS prefetch for other resources
        echo '<link rel="dns-prefetch" href="https://maps.googleapis.com">' . "\n";
        echo '<link rel="dns-prefetch" href="https://ui-avatars.com">' . "\n";
        echo '<link rel="dns-prefetch" href="https://api.stripe.com">' . "\n";
    }
    
    /**
     * Preload critical assets
     */
    public static function preload_critical_assets() {
        if (!self::is_ptp_page()) {
            return;
        }
        
        echo "\n<!-- PTP Performance v85.2: Critical Asset Preload -->\n";
        
        // Preload fonts
        echo '<link rel="preload" href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n";
        echo '<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&display=swap"></noscript>' . "\n";
        
        // Preload mobile CSS if on mobile
        if (wp_is_mobile()) {
            $mobile_css = PTP_PLUGIN_URL . 'assets/css/ptp-mobile-v85.css';
            echo '<link rel="preload" href="' . esc_url($mobile_css) . '" as="style">' . "\n";
        }
    }
    
    /**
     * Add lazy loading to images
     */
    public static function add_lazy_loading($attr, $attachment, $size) {
        if (!isset($attr['loading'])) {
            $attr['loading'] = 'lazy';
        }
        if (!isset($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }
        return $attr;
    }
    
    /**
     * Defer non-critical CSS
     */
    public static function defer_non_critical_css($html, $handle, $href, $media) {
        // List of non-critical stylesheets that can be deferred
        $defer_handles = array(
            'wp-block-library',
            'ptp-native-inline',
            'wc-blocks-style',
        );
        
        if (in_array($handle, $defer_handles) && self::is_ptp_page()) {
            // Convert to non-blocking load
            $html = str_replace("media='all'", "media='print' onload=\"this.media='all'\"", $html);
        }
        
        return $html;
    }
    
    /**
     * Clear trainer cache when needed
     */
    public static function maybe_clear_trainer_cache($post_id = null) {
        global $wpdb;
        
        // Clear all trainer list transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ptp_trainers_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ptp_trainers_%'");
        
        // Clear trainer count
        delete_transient('ptp_active_trainer_count');
    }
    
    /**
     * Get cached trainer count
     */
    public static function get_trainer_count() {
        $count = get_transient('ptp_active_trainer_count');
        
        if ($count === false) {
            global $wpdb;
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'");
            set_transient('ptp_active_trainer_count', $count, HOUR_IN_SECONDS);
        }
        
        return (int) $count;
    }
    
    /**
     * Check if current page is a PTP page
     */
    private static function is_ptp_page() {
        // Check URL path for faster detection
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $ptp_patterns = array(
            '/training', '/find-trainers', '/trainer/', '/book-session',
            '/parent-dashboard', '/trainer-dashboard', '/trainer-onboarding',
            '/ptp-checkout', '/ptp-cart', '/messages', '/my-training'
        );
        
        foreach ($ptp_patterns as $pattern) {
            if (strpos($uri, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Optimize images for display
     */
    public static function optimize_image_url($url, $width = 400, $height = null) {
        if (empty($url)) {
            return '';
        }
        
        // If it's a UI Avatars URL, adjust the size
        if (strpos($url, 'ui-avatars.com') !== false) {
            return preg_replace('/size=\d+/', 'size=' . $width, $url);
        }
        
        return $url;
    }
    
    /**
     * Generate srcset for responsive images
     */
    public static function generate_srcset($url, $sizes = array(200, 400, 800)) {
        if (empty($url) || strpos($url, 'ui-avatars.com') !== false) {
            return '';
        }
        
        $srcset = array();
        foreach ($sizes as $size) {
            $sized_url = self::optimize_image_url($url, $size);
            $srcset[] = $sized_url . ' ' . $size . 'w';
        }
        
        return implode(', ', $srcset);
    }
    
    /**
     * Get critical CSS for inline loading
     */
    public static function get_critical_css($page_type = 'default') {
        $css = ':root{--ptp-gold:#FCB900;--ptp-black:#0A0A0A;--ptp-white:#FFF}';
        $css .= '*{box-sizing:border-box}';
        $css .= 'body{font-family:Inter,-apple-system,sans-serif;-webkit-font-smoothing:antialiased}';
        $css .= '.ptp-skeleton{background:linear-gradient(90deg,#f5f5f5 25%,#e5e5e5 50%,#f5f5f5 75%);background-size:200% 100%;animation:shimmer 1.5s infinite}';
        $css .= '@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}';
        
        return $css;
    }
    
    /**
     * Output performance timing (debug only)
     */
    public static function output_timing() {
        $elapsed = round((microtime(true) - self::$start_time) * 1000, 2);
        echo "\n<!-- PTP Performance: Page generated in {$elapsed}ms -->\n";
    }
    
    /**
     * Minify inline CSS
     */
    public static function minify_css($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Remove whitespace
        $css = str_replace(array("\r\n", "\r", "\n", "\t"), '', $css);
        $css = preg_replace('/\s+/', ' ', $css);
        // Remove spaces around : and ;
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css);
        
        return trim($css);
    }
}

// Initialize
add_action('init', array('PTP_Performance', 'init'));
