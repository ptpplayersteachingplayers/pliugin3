<?php 
/**
 * Home/Training Landing - redirects to find-trainers
 * Note: /training/ page should use [ptp_training] shortcode instead
 */
defined('ABSPATH') || exit; 

// Redirect to find-trainers to avoid loop with /training/
wp_redirect(home_url('/find-trainers/')); 
exit;
