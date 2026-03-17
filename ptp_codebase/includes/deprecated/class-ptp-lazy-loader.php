<?php
/**
 * PTP Lazy Loader v227
 * 
 * Registers AJAX hooks as thin stubs that load the actual class file on-demand.
 * This avoids parsing ~3.5MB of PHP on every page load when those classes
 * are only needed during specific AJAX calls.
 * 
 * Also handles deferred frontend loading via template_redirect.
 */
defined('ABSPATH') || exit;

class PTP_Lazy_Loader {

    private static $ajax_map = null;
    private static $loaded_files = array();

    /**
     * Initialize lazy loading
     */
    public static function init() {
        // Register all AJAX stubs
        self::register_ajax_stubs();
        
        // Defer heavy frontend files to template_redirect
        if (!wp_doing_ajax() && !self::is_rest() && !is_admin()) {
            add_action('template_redirect', array(__CLASS__, 'load_frontend_files'), -1);
        }
    }

    /**
     * Get the complete AJAX action → file map
     */
    private static function get_ajax_map() {
        if (self::$ajax_map !== null) return self::$ajax_map;
        
        $dir = PTP_PLUGIN_DIR;
        
        // Map each AJAX action to the file that handles it
        // Only includes files that are NOT in the always-loaded core set
        self::$ajax_map = array(
            // unified-checkout (182KB)
            'ptp_unified_checkout'           => 'includes/class-ptp-unified-checkout.php',
            'ptp_save_checkout'              => 'includes/class-ptp-unified-checkout.php',
            'ptp_create_order_after_payment'  => 'includes/class-ptp-unified-checkout.php',
            
            // v231: free-session-apply removed
            
            // seo-locations-v85 (99KB)
            'ptp_seo_stats'                  => 'includes/class-ptp-seo-locations-v85.php',
            'ptp_seo_flush_rewrites'         => 'includes/class-ptp-seo-locations-v85.php',
            
            // mentorship (93KB)
            'ptp_mentorship_interest'        => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_purchase'        => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_purchase_addon'  => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_schedule_session'=> 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_mark_session_complete' => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_set_goal'        => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_complete_goal'   => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_complete_intro'  => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_create_plan'     => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_get_dashboard'   => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_get_mentees'     => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_upload_video'    => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_submit_video'    => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_review_video'    => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_get_video_queue' => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_post_challenge'  => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_cancel'          => 'includes/class-ptp-mentorship.php',
            'ptp_mentorship_add_milestone'   => 'includes/class-ptp-mentorship.php',
            'ptp_save_mentorship_settings'   => 'includes/class-ptp-mentorship.php',
            'ptp_admin_mentorship_stats'     => 'includes/class-ptp-mentorship.php',
            'ptp_add_player'                 => 'includes/class-ptp-mentorship.php',
            
            // camp-checkout-v99 (76KB)
            'ptp99_update'                   => 'includes/class-ptp-camp-checkout-v99.php',
            
            // trainer-matching (73KB)
            // No AJAX hooks but loaded for shortcodes
            
            // camp-crosssell-everywhere (70KB)
            'ptp_get_camp_recommendations'   => 'includes/class-ptp-camp-crosssell-everywhere.php',
            'ptp_dismiss_camp_banner'        => 'includes/class-ptp-camp-crosssell-everywhere.php',
            
            // viral-engine (57KB)
            'ptp_generate_share_card'        => 'includes/class-ptp-viral-engine.php',
            'ptp_get_referral_stats'         => 'includes/class-ptp-viral-engine.php',
            'ptp_get_social_proof'           => 'includes/class-ptp-viral-engine.php',
            'ptp_validate_referral'          => 'includes/class-ptp-viral-engine.php',
            'ptp_redeem_credit'              => 'includes/class-ptp-viral-engine.php',
            'ptp_track_share'                => 'includes/class-ptp-viral-engine.php',
            
            // crosssell-engine (51KB)
            'ptp_get_recommendations'        => 'includes/class-ptp-crosssell-engine.php',
            'ptp_apply_package_upgrade'      => 'includes/class-ptp-crosssell-engine.php',
            'ptp_create_bundle'              => 'includes/class-ptp-crosssell-engine.php',
            'ptp_track_crosssell_click'      => 'includes/class-ptp-crosssell-engine.php',

            // growth (41KB)
            'ptp_add_to_waitlist'            => 'includes/class-ptp-growth.php',
            'ptp_apply_bundle_discount'      => 'includes/class-ptp-growth.php',
            'ptp_get_camp_upsell'            => 'includes/class-ptp-growth.php',
            'ptp_get_recent_bookings'        => 'includes/class-ptp-growth.php',
            'ptp_clear_pending_training'     => 'includes/class-ptp-growth.php',
            
            // camp-checkout (42KB)
            'ptp_calculate_camp_totals'      => 'includes/class-ptp-camp-checkout.php',
            'ptp_get_camp_products'          => 'includes/class-ptp-camp-checkout.php',
            
            // camp-orders (42KB)
            'ptp_camp_checkout'              => 'includes/class-ptp-camp-orders.php',
            'ptp_apply_camp_referral'        => 'includes/class-ptp-camp-orders.php',
            
            // order-email-wiring (44KB)
            'ptp_resend_confirmation'        => 'includes/class-ptp-order-email-wiring.php',
            'ptp_send_order_email'           => 'includes/class-ptp-order-email-wiring.php',
            
            // booking-wizard (29KB)
            'ptp_wizard_check_availability'  => 'includes/class-ptp-booking-wizard.php',
            'ptp_wizard_get_locations'       => 'includes/class-ptp-booking-wizard.php',
            'ptp_wizard_get_packages'        => 'includes/class-ptp-booking-wizard.php',
            'ptp_wizard_get_similar_trainers'=> 'includes/class-ptp-booking-wizard.php',
            'ptp_wizard_get_slots'           => 'includes/class-ptp-booking-wizard.php',
            'ptp_wizard_process_booking'     => 'includes/class-ptp-booking-wizard.php',
            'ptp_wizard_submit_lead'         => 'includes/class-ptp-booking-wizard.php',
            
            // viral-enhancements (26KB)
            'ptp_submit_review_with_share'   => 'includes/class-ptp-viral-enhancements.php',
            
            // abandoned-cart (36KB)
            'ptp_abandoned_cart_log'          => 'includes/class-ptp-abandoned-cart-recovery.php',
            'ptp_abandoned_cart_stats'        => 'includes/class-ptp-abandoned-cart-recovery.php',
            
            // bundle-checkout
            'ptp_add_camp_to_bundle'         => 'includes/class-ptp-bundle-checkout.php',
            'ptp_clear_bundle'               => 'includes/class-ptp-bundle-checkout.php',
            'ptp_get_bundle_status'          => 'includes/class-ptp-bundle-checkout.php',
            'ptp_confirm_bundle_payment'     => 'includes/class-ptp-bundle-checkout.php',
            'ptp_process_bundle_checkout'    => 'includes/class-ptp-bundle-checkout.php',
            
            // training-plans (35KB)
            'ptp_create_training_plan'       => 'includes/class-ptp-training-plans.php',
            'ptp_update_training_plan'       => 'includes/class-ptp-training-plans.php',
            'ptp_add_assessment'             => 'includes/class-ptp-training-plans.php',
            'ptp_add_session_note'           => 'includes/class-ptp-training-plans.php',
            'ptp_complete_milestone'         => 'includes/class-ptp-training-plans.php',
            'ptp_get_player_progress'        => 'includes/class-ptp-training-plans.php',
            'ptp_get_session_recap'          => 'includes/class-ptp-training-plans.php',
            'ptp_send_session_recap'         => 'includes/class-ptp-training-plans.php',
            
            // email-automation (33KB)
            'ptp_track_checkout_start'       => 'includes/class-ptp-email-automation.php',
            
            // gift-cards
            'ptp_apply_gift_card'            => 'includes/class-ptp-gift-cards.php',
            'ptp_check_gift_card'            => 'includes/class-ptp-gift-cards.php',
            'ptp_purchase_gift_card'         => 'includes/class-ptp-gift-cards.php',
            'ptp_redeem_gift_card'           => 'includes/class-ptp-gift-cards.php',
            
            // subscriptions
            'ptp_create_subscription'        => 'includes/class-ptp-subscriptions.php',
            'ptp_cancel_subscription'        => 'includes/class-ptp-subscriptions.php',
            'ptp_pause_subscription'         => 'includes/class-ptp-subscriptions.php',
            'ptp_resume_subscription'        => 'includes/class-ptp-subscriptions.php',
            'ptp_get_subscription'           => 'includes/class-ptp-subscriptions.php',
            'ptp_update_subscription'        => 'includes/class-ptp-subscriptions.php',
            
            // recurring
            'ptp_book_recurring'             => 'includes/class-ptp-recurring.php',
            'ptp_cancel_recurring'           => 'includes/class-ptp-recurring.php',
            'ptp_create_package'             => 'includes/class-ptp-recurring.php',
            'ptp_skip_session'               => 'includes/class-ptp-recurring.php',
            
            // groups
            'ptp_create_group_session'       => 'includes/class-ptp-groups.php',
            'ptp_get_open_groups'            => 'includes/class-ptp-groups.php',
            'ptp_join_group_session'         => 'includes/class-ptp-groups.php',
            'ptp_leave_group_session'        => 'includes/class-ptp-groups.php',
            
            // calendar-sync
            'ptp_connect_google_calendar'    => 'includes/class-ptp-calendar-sync.php',
            'ptp_disconnect_google_calendar' => 'includes/class-ptp-calendar-sync.php',
            'ptp_get_calendar_status'        => 'includes/class-ptp-calendar-sync.php',
            'ptp_sync_calendar'              => 'includes/class-ptp-calendar-sync.php',
            
            // tax-reporting
            'ptp_export_1099_data'           => 'includes/class-ptp-tax-reporting.php',
            'ptp_generate_1099_preview'      => 'includes/class-ptp-tax-reporting.php',
            
            // quality-control
            'ptp_get_quality_dashboard'      => 'includes/class-ptp-quality-control.php',
            'ptp_report_no_show'             => 'includes/class-ptp-quality-control.php',
            'ptp_submit_session_review'      => 'includes/class-ptp-quality-control.php',
            'ptp_pause_trainer'              => 'includes/class-ptp-quality-control.php',
            'ptp_unpause_trainer'            => 'includes/class-ptp-quality-control.php',
            'ptp_resolve_flag'               => 'includes/class-ptp-quality-control.php',
            'ptp_save_session_notes'         => 'includes/class-ptp-quality-control.php',
            
            // google-reviews
            'ptp_dismiss_google_prompt'      => 'includes/class-ptp-google-reviews.php',
            'ptp_track_google_review_click'  => 'includes/class-ptp-google-reviews.php',
            
            // calendar-enhancements
            'ptp_check_conflicts'            => 'includes/class-ptp-calendar-enhancements.php',
            'ptp_create_recurring_sessions'  => 'includes/class-ptp-calendar-enhancements.php',
            'ptp_get_trainer_conflicts'      => 'includes/class-ptp-calendar-enhancements.php',
            'ptp_send_session_reminder'      => 'includes/class-ptp-calendar-enhancements.php',
            
            // referral-system
            'ptp_generate_referral_link'     => 'includes/class-ptp-referral-system.php',
            'ptp_share_referral'             => 'includes/class-ptp-referral-system.php',
            
            // trainer-loyalty
            'ptp_get_loyalty_status'         => 'includes/class-ptp-trainer-loyalty.php',
            
            // trainer-referrals
            'ptp_get_trainer_referral_stats' => 'includes/class-ptp-trainer-referrals.php',
            'ptp_send_trainer_invite'        => 'includes/class-ptp-trainer-referrals.php',
            
            // happy-score
            'ptp_get_trainer_score_breakdown'=> 'includes/class-ptp-happy-score.php',
            
            // all-access-pass
            'ptp_membership_checkout'        => 'includes/class-ptp-all-access-pass.php',
            'ptp_service_purchase'           => 'includes/class-ptp-all-access-pass.php',
            
            // popups
            'ptp_subscribe_email'            => 'includes/class-ptp-popups.php',
            
            // thankyou
            'ptp_process_thankyou_upsell'    => 'includes/class-ptp-thankyou-ajax.php',
            'ptp_track_thankyou_share'       => 'includes/class-ptp-thankyou-ajax.php',
            'ptp_thankyou_add_camp'          => 'includes/class-ptp-thankyou-upsell-handler.php',
            'ptp_thankyou_add_training'      => 'includes/class-ptp-thankyou-upsell-handler.php',
            
            // social-announcement
            'ptp_save_social_announcement'   => 'includes/class-ptp-social-announcement.php',
            'ptp_update_announcement_status' => 'includes/class-ptp-social-announcement.php',
            
            // first-session-promo
            'ptp_generate_free_session_code' => 'includes/class-ptp-first-session-promo.php',
            
            // unified-cart (51KB)
            'ptp_get_unified_cart'           => 'includes/class-ptp-unified-cart.php',
            
            // all-access-pass (36KB)
            'ptp_membership_checkout'        => 'includes/class-ptp-all-access-pass.php',
            'ptp_service_purchase'           => 'includes/class-ptp-all-access-pass.php',
            
            // popups (22KB)
            'ptp_subscribe_email'            => 'includes/class-ptp-popups.php',
            
            // stripe-backfill (32KB)
            'ptp_stripe_backfill_run'        => 'includes/class-ptp-stripe-backfill.php',
            'ptp_stripe_backfill_scan'       => 'includes/class-ptp-stripe-backfill.php',
            
            // free-session-conversion
            // No direct AJAX hooks but loaded with free-session-apply
        );
        
        return self::$ajax_map;
    }

    /**
     * Register all AJAX actions as lightweight stubs
     */
    private static function register_ajax_stubs() {
        $map = self::get_ajax_map();
        
        foreach ($map as $action => $file) {
            // Register both logged-in and logged-out handlers
            add_action('wp_ajax_' . $action, array(__CLASS__, 'ajax_dispatcher'), 1);
            add_action('wp_ajax_nopriv_' . $action, array(__CLASS__, 'ajax_dispatcher'), 1);
        }
    }

    /**
     * AJAX dispatcher — loads the required file and lets WordPress re-fire the action
     */
    public static function ajax_dispatcher() {
        $action = sanitize_text_field($_REQUEST['action'] ?? '');
        if (empty($action)) return;
        
        $map = self::get_ajax_map();
        if (!isset($map[$action])) return;
        
        $file = PTP_PLUGIN_DIR . $map[$action];
        
        // Only load if not already loaded
        if (!isset(self::$loaded_files[$file]) && file_exists($file)) {
            self::$loaded_files[$file] = true;
            require_once $file;
            
            // Some files need companion files
            $companions = self::get_companions($map[$action]);
            foreach ($companions as $comp) {
                $comp_file = PTP_PLUGIN_DIR . $comp;
                if (!isset(self::$loaded_files[$comp_file]) && file_exists($comp_file)) {
                    self::$loaded_files[$comp_file] = true;
                    require_once $comp_file;
                }
            }
        }
        
        // The file's constructor should have registered the real handler at default priority (10)
        // Our stub runs at priority 1, so we just need to remove ourselves and let WP continue
        // Actually, WP fires all callbacks for the action. The real handler will fire at its registered priority.
        // We just need to not output anything ourselves.
    }

    /**
     * Get companion files that should load together
     */
    private static function get_companions($file) {
        $companions = array(
            'includes/class-ptp-unified-checkout.php' => array(
                'includes/class-ptp-unified-checkout-handler.php',
                'includes/class-ptp-unified-session-bridge.php',
            ),
            'includes/class-ptp-free-session-apply.php' => array(),  // v231: removed
            'includes/class-ptp-camp-checkout.php' => array(
                'includes/class-ptp-camp-checkout-v99.php',
            ),
            'includes/class-ptp-camp-orders.php' => array(
                'includes/class-ptp-camp-emails.php',
                'includes/class-ptp-unified-order-email.php',
            ),
            'includes/class-ptp-viral-engine.php' => array(
                'includes/class-ptp-viral-enhancements.php',
            ),
        );
        return $companions[$file] ?? array();
    }

    /**
     * Load frontend-specific files only on actual page renders
     */
    public static function load_frontend_files() {
        $dir = PTP_PLUGIN_DIR;
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // SEO rewrites — only on location/city pages
        if (strpos($uri, '/soccer-training/') !== false || strpos($uri, '/soccer-camps/') !== false) {
            self::load_file($dir . 'includes/class-ptp-seo-locations-v85.php');
        }
        
        // Camp crosssell — only on training-related pages
        if (strpos($uri, '/trainer/') !== false || strpos($uri, '/ptp-cart') !== false || 
            strpos($uri, '/ptp-checkout') !== false || strpos($uri, '/training') !== false) {
            self::load_file($dir . 'includes/class-ptp-camp-crosssell-everywhere.php');
        }
        
        // Checkout files — only on checkout/cart pages
        if (strpos($uri, '/ptp-cart') !== false || strpos($uri, '/ptp-checkout') !== false) {
            self::load_file($dir . 'includes/class-ptp-unified-cart.php');
            self::load_file($dir . 'includes/class-ptp-unified-checkout.php');
            self::load_file($dir . 'includes/class-ptp-unified-checkout-handler.php');
            self::load_file($dir . 'includes/class-ptp-unified-session-bridge.php');
            self::load_file($dir . 'includes/class-ptp-checkout-v77.php');
            self::load_file($dir . 'includes/class-ptp-bulletproof-checkout.php');
            self::load_file($dir . 'includes/class-ptp-bundle-checkout.php');
            self::load_file($dir . 'includes/class-ptp-abandoned-cart-recovery.php');
        }
        
        // Thank-you page
        if (strpos($uri, '/thank-you') !== false || strpos($uri, '/thankyou') !== false || 
            isset($_GET['ptp_order'])) {
            self::load_file($dir . 'includes/class-ptp-thankyou-handler.php');
            self::load_file($dir . 'includes/class-ptp-training-thankyou.php');
            self::load_file($dir . 'includes/ptp-thankyou-v100-loader.php');
            self::load_file($dir . 'includes/class-ptp-thankyou-upsell-handler.php');
        }
        
        // v231: Free session landing pages removed
        
        // Mentorship pages
        if (strpos($uri, '/mentorship') !== false || strpos($uri, '/dashboard') !== false || 
            strpos($uri, '/trainer/') !== false) {
            self::load_file($dir . 'includes/class-ptp-mentorship.php');
        }
    }

    /**
     * Load a file if not already loaded
     */
    private static function load_file($file) {
        if (!isset(self::$loaded_files[$file]) && file_exists($file)) {
            self::$loaded_files[$file] = true;
            require_once $file;
        }
    }

    /**
     * Check if this is a REST request
     */
    private static function is_rest() {
        return (defined('REST_REQUEST') && REST_REQUEST) || 
               (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false);
    }

    /**
     * Force-load a deferred file (called by classes that need a dependency)
     */
    public static function ensure_loaded($file) {
        self::load_file(PTP_PLUGIN_DIR . $file);
    }
}
