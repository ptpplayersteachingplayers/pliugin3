<?php
/**
 * Templates Class
 */

defined('ABSPATH') || exit;

class PTP_Templates {
    
    public static function init() {
        add_filter('template_include', array(__CLASS__, 'template_loader'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        
        // v214: Redirect WordPress default auth pages to custom PTP pages
        add_filter('lostpassword_url', array(__CLASS__, 'custom_lostpassword_url'), 10, 2);
        add_action('login_form_lostpassword', array(__CLASS__, 'redirect_to_custom_lostpassword'));
        add_action('login_form_rp', array(__CLASS__, 'redirect_to_custom_resetpassword'));
        add_action('login_form_resetpass', array(__CLASS__, 'redirect_to_custom_resetpassword'));
    }
    
    /**
     * v214: Override the lost password URL to point to our custom page
     */
    public static function custom_lostpassword_url($url, $redirect) {
        return home_url('/forgot-password/');
    }
    
    /**
     * v214: Redirect wp-login.php?action=lostpassword to custom page
     */
    public static function redirect_to_custom_lostpassword() {
        wp_redirect(home_url('/forgot-password/'));
        exit;
    }
    
    /**
     * v214: Redirect wp-login.php?action=rp (password reset link from email) to custom page
     */
    public static function redirect_to_custom_resetpassword() {
        $key = isset($_GET['key']) ? $_GET['key'] : '';
        $login = isset($_GET['login']) ? $_GET['login'] : '';
        
        if ($key && $login) {
            wp_redirect(home_url('/reset-password/?key=' . urlencode($key) . '&login=' . urlencode($login)));
            exit;
        }
        
        // No valid params, send to forgot page
        wp_redirect(home_url('/forgot-password/'));
        exit;
    }
    
    public static function template_loader($template) {
        // Check if this is a PTP page
        if (is_page()) {
            // v200.4: Never override the homepage template, even if a PTP page
            // is accidentally set as the front page.
            $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
            if ($request_path === '' || is_front_page()) {
                return $template;
            }

            $page_slug = get_post_field('post_name', get_the_ID());
            
            // All-Access landing page
            if ($page_slug === 'all-access') {
                return PTP_PLUGIN_DIR . 'templates/all-access-landing.php';
            }
            
            // Keep these in sync with the slugs created in create_pages() (ptp-training-platform.php).
            // Also keep legacy slugs for backwards compatibility.
            $ptp_pages = array(
                'training',
                'find-trainers',
                'trainer',
                'book-session',
                'booking-confirmation',
                'bundle-checkout',
                'training-checkout',
                'my-training',
                'parent-dashboard',
                'trainer-dashboard',
                'trainer-onboarding',
                'trainer-edit-profile',
                'messages',
                'account',
                'login',
                'register',
                'forgot-password',
                'reset-password',
                'forgot-password',
                'reset-password',
                'apply',
                'logout',
                // legacy
                'trainer-profile',
                'become-a-trainer',
            );
            
            if (in_array($page_slug, $ptp_pages)) {
                // Use theme template if exists, otherwise default
                $theme_template = locate_template('ptp-template.php');
                if ($theme_template) {
                    return $theme_template;
                }
            }
        }
        
        return $template;
    }
    
    public static function body_class($classes) {
        if (is_page()) {
            // v200.4: Never add PTP body classes to the front page
            $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
            if ($request_path === '' || is_front_page()) {
                return $classes;
            }

            $page_slug = get_post_field('post_name', get_the_ID());
            // Keep these in sync with template_loader().
            $ptp_pages = array(
                'training',
                'find-trainers',
                'trainer',
                'book-session',
                'booking-confirmation',
                'bundle-checkout',
                'training-checkout',
                'my-training',
                'parent-dashboard',
                'trainer-dashboard',
                'trainer-onboarding',
                'trainer-edit-profile',
                'messages',
                'account',
                'login',
                'register',
                'forgot-password',
                'reset-password',
                'apply',
                'forgot-password',
                'reset-password',
                'logout',
                // legacy
                'trainer-profile',
                'become-a-trainer',
            );
            
            if (in_array($page_slug, $ptp_pages)) {
                $classes[] = 'ptp-page';
                $classes[] = 'ptp-' . $page_slug;
            }
        }
        
        return $classes;
    }
    
    public static function get_template($template_name, $args = array()) {
        if ($args && is_array($args)) {
            extract($args);
        }
        
        $template_path = PTP_PLUGIN_DIR . 'templates/' . $template_name . '.php';
        
        if (file_exists($template_path)) {
            include $template_path;
        }
    }
    
    public static function format_date($date) {
        return date('l, F j, Y', strtotime($date));
    }
    
    public static function format_time($time) {
        return date('g:i A', strtotime($time));
    }
    
    public static function format_price($amount) {
        return '$' . number_format(floatval($amount), 2);
    }
    
    public static function get_avatar_url($user_id, $size = 96) {
        return get_avatar_url($user_id, array('size' => $size));
    }
    
    public static function rating_stars($rating, $max = 5) {
        $full_stars = floor($rating);
        $half_star = ($rating - $full_stars) >= 0.5;
        $empty_stars = $max - $full_stars - ($half_star ? 1 : 0);
        
        $output = str_repeat('★', $full_stars);
        if ($half_star) {
            $output .= '½';
        }
        $output .= str_repeat('☆', $empty_stars);
        
        return $output;
    }
}
