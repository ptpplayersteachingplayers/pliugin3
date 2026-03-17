<?php
/**
 * PTP Training Platform - Uninstall
 *
 * Fired when the plugin is deleted (not deactivated).
 * Removes all database tables, options, user meta, cron events,
 * transients, and custom roles created by the plugin.
 *
 * @package PTP_Training_Platform
 * @since 221.0
 */

// Abort if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// v222 SAFETY: Only drop tables if the admin explicitly opted in.
// This prevents accidental data loss when the plugin folder structure
// causes WordPress to treat an upgrade as a separate plugin install.
$confirmed = get_option('ptp_confirm_full_uninstall', false);
if (!$confirmed) {
    // Just clean up transients and cron — preserve all data
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ptp\_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ptp\_%'");
    
    $cron_hooks = array(
        'ptp_daily_cron', 'ptp_six_hours', 'ptp_process_escrow_releases',
        'ptp_process_payouts', 'ptp_send_session_reminders', 'ptp_send_review_requests',
        'ptp_aggregate_daily_stats', 'ptp_cleanup_sessions', 'ptp_auto_complete_sessions',
        'ptp_monitor_cleanup', 'ptp_stripe_backfill_cron',
    );
    foreach ($cron_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    
    ptp_log('[PTP] Plugin deleted — data PRESERVED (set ptp_confirm_full_uninstall option to true for full wipe)');
    exit;
}

// === FULL DESTRUCTIVE UNINSTALL (only if ptp_confirm_full_uninstall === true) ===
ptp_log('[PTP] FULL UNINSTALL confirmed — dropping all tables and data');

global $wpdb;

// ─── 1. Drop all custom tables ───────────────────────────────────────────────
$tables = array(
    'ptp_abandoned_carts',
    'ptp_achievements',
    'ptp_analytics_daily',
    'ptp_analytics_events',
    'ptp_applications',
    'ptp_availability',
    'ptp_availability_exceptions',
    'ptp_booking_meta',
    'ptp_bookings',
    'ptp_bundles',
    'ptp_calendar_connections',
    'ptp_camp_order_items',
    'ptp_camp_referrals',
    'ptp_conversations',
    'ptp_coupons',
    'ptp_crosssell_clicks',
    'ptp_email_logs',
    'ptp_escrow',
    'ptp_fcm_tokens',
    'ptp_flow_events',
    'ptp_funnel_progress',
    'ptp_gift_card_usage',
    'ptp_gift_cards',
    'ptp_group_participants',
    'ptp_group_sessions',
    'ptp_mentorship_challenges',
    'ptp_mentorship_goals',
    'ptp_mentorship_milestones',
    'ptp_mentorship_pairs',
    'ptp_mentorship_plans',
    'ptp_mentorship_session_attendees',
    'ptp_mentorship_sessions',
    'ptp_mentorship_videos',
    'ptp_messages',
    'ptp_native_order_items',
    'ptp_native_order_meta',
    'ptp_native_orders',
    'ptp_notifications',
    'ptp_open_dates',
    'ptp_packages',
    'ptp_parents',
    'ptp_payout_items',
    'ptp_payouts',
    'ptp_plan_milestones',
    'ptp_player_goals',
    'ptp_players',
    'ptp_quality_flags',
    'ptp_recurring',
    'ptp_referral_codes',
    'ptp_referral_credits',
    'ptp_referral_transactions',
    'ptp_referral_uses',
    'ptp_referrals',
    'ptp_response_tracking',
    'ptp_review_prompts',
    'ptp_reviews',
    'ptp_session_notes',
    'ptp_sessions',
    'ptp_shares',
    'ptp_skill_assessments',
    'ptp_skill_ratings',
    'ptp_sms_log',
    'ptp_social_shares',
    'ptp_stripe_products',
    'ptp_subscribers',
    'ptp_subscriptions',
    'ptp_trainer_metrics',
    'ptp_trainer_referrals',
    'ptp_trainers',
    'ptp_training_plans',
    'ptp_unified_camp_orders',
    'ptp_waitlist',
    'ptp_zip_codes',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

// ─── 2. Delete all plugin options ────────────────────────────────────────────
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ptp\_%'");

// ─── 3. Delete all plugin transients ─────────────────────────────────────────
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ptp\_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ptp\_%'");

// ─── 4. Delete all plugin user meta ─────────────────────────────────────────
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ptp\_%'");

// ─── 5. Clear all scheduled cron events ──────────────────────────────────────
$cron_hooks = array(
    'ptp_aggregate_daily_stats',
    'ptp_auto_complete_sessions',
    'ptp_camp_conversion_day10_email',
    'ptp_camp_conversion_day2_email',
    'ptp_camp_conversion_day5_sms',
    'ptp_camp_conversion_free_session_email',
    'ptp_camp_day_reminder',
    'ptp_camp_week_reminder',
    'ptp_camps_abandonment_cron',
    'ptp_chatbot_cleanup',
    'ptp_check_missing_notes',
    'ptp_check_onboarding_reminders',
    'ptp_cleanup_abandoned_carts',
    'ptp_cleanup_native_sessions',
    'ptp_cleanup_old_data',
    'ptp_cleanup_sessions',
    'ptp_compliance_reminder',
    'ptp_daily_cron',
    'ptp_daily_score_recalculation',
    'ptp_generate_subscription_sessions',
    'ptp_mentorship_check_expiring',
    'ptp_mentorship_free_session_followup',
    'ptp_mentorship_pre_session_reminder',
    'ptp_mentorship_touch_day10',
    'ptp_mentorship_touch_day2',
    'ptp_mentorship_touch_day5',
    'ptp_mn2_check_escalations',
    'ptp_mn2_day10_email',
    'ptp_mn2_day2_email',
    'ptp_mn2_day5_sms',
    'ptp_mn2_free_followup',
    'ptp_mn2_session_1hr_reminder',
    'ptp_mn2_winback_day14',
    'ptp_mn2_winback_day7',
    'ptp_monitor_cleanup',
    'ptp_op_sync_lead',
    'ptp_post_training_followup',
    'ptp_process_escrow_releases',
    'ptp_process_payouts',
    'ptp_process_recurring_bookings',
    'ptp_process_refunds',
    'ptp_process_scheduled_payouts',
    'ptp_send_abandoned_cart_emails',
    'ptp_send_booking_emails',
    'ptp_send_camp_reminder',
    'ptp_send_camp_reminders',
    'ptp_send_completion_requests',
    'ptp_send_hour_reminders',
    'ptp_send_order_emails',
    'ptp_send_post_session_emails',
    'ptp_send_reengagement_emails',
    'ptp_send_review_prompts',
    'ptp_send_review_request_emails',
    'ptp_send_review_requests',
    'ptp_send_session_reminders',
    'ptp_send_training_reminder',
    'ptp_send_w9_email',
    'ptp_send_weekly_digest',
    'ptp_session_reminder',
    'ptp_six_hours',
    'ptp_stripe_backfill_cron',
    'ptp_stripe_ensure_customer',
    'ptp_sync_google_calendars',
    'ptp_update_response_times',
    'ptp_weekly_schedule_reminder',
);

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// ─── 6. Remove custom roles ─────────────────────────────────────────────────
remove_role('ptp_trainer');
remove_role('ptp_parent');

// ─── 7. Delete plugin-created pages ──────────────────────────────────────────
$ptp_pages = array(
    'login', 'register', 'forgot-password', 'reset-password',
    'trainer-dashboard', 'parent-dashboard', 'member-dashboard',
    'apply', 'trainers', 'training', 'account', 'ptp-cart',
    'ptp-checkout', 'thank-you', 'camp-thank-you', 'confirm-session',
    'messaging', 'all-access-pass', 'mentorship', 'mentorship-signup',
    'mentorship-dashboard', 'mentorship-checkout', 'logout',
    'trainer-onboarding', 'trainer-pending', 'offline',
);

foreach ($ptp_pages as $slug) {
    $page = get_page_by_path($slug);
    if ($page) {
        wp_delete_post($page->ID, true);
    }
}

// ─── 8. Flush rewrite rules ─────────────────────────────────────────────────
flush_rewrite_rules();
