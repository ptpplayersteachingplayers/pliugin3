<?php
/**
 * PTP Auth Page Setup - v221
 * Include this BEFORE wp_head() in all auth templates.
 * Strips theme/Elementor styles while keeping WP core infrastructure intact.
 */
defined('ABSPATH') || exit;

// Strip theme/Elementor/plugin styles — keep only WP core infrastructure
add_action('wp_enqueue_scripts', function() {
    global $wp_styles, $wp_scripts;

    // Whitelist: only keep styles needed for WP core
    $keep_styles = array('admin-bar', 'dashicons', 'wp-block-library');
    if (!empty($wp_styles->registered)) {
        foreach (array_keys($wp_styles->registered) as $handle) {
            if (!in_array($handle, $keep_styles)) {
                wp_dequeue_style($handle);
            }
        }
    }

    // Keep ALL scripts — only strip Elementor frontend bloat
    $strip_scripts = array(
        'elementor-frontend', 'elementor-pro-frontend', 'elementor-waypoints',
        'elementor-webpack-runtime', 'elementor-frontend-modules',
        'ekit-framework-js-frontend', 'elementskit-framework-js-frontend',
    );
    if (!empty($wp_scripts->registered)) {
        foreach ($strip_scripts as $handle) {
            wp_dequeue_script($handle);
        }
    }
}, 9999);

// Hide admin bar on auth pages
add_filter('show_admin_bar', '__return_false');
