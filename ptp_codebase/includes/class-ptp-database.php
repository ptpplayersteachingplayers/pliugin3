<?php
/**
 * PTP Database – Canonical schema for all custom tables
 *
 * This is the SINGLE SOURCE OF TRUTH for every custom table in the plugin.
 * Individual classes should NOT create their own tables.
 *
 * Tables created on activation (create_tables):
 *   CORE:         ptp_applications, ptp_trainers, ptp_parents, ptp_players,
 *                 ptp_availability, ptp_bookings, ptp_reviews,
 *                 ptp_conversations, ptp_messages, ptp_escrow
 *   FINANCIAL:    ptp_payouts, ptp_payout_items
 *   SCHEDULING:   ptp_sessions, ptp_calendar_connections,
 *                 ptp_availability_exceptions, ptp_open_dates
 *   ENGAGEMENT:   ptp_notifications, ptp_fcm_tokens
 *   REFERRALS:    ptp_referral_codes, ptp_referral_uses
 *   COUPONS:      ptp_coupons
 *   BOOKINGS:     ptp_booking_meta
 *
 * Auto-migration runs on plugins_loaded via maybe_migrate().
 */
defined('ABSPATH') || exit;

class PTP_Database {

    /* =========================================================================
       ACTIVATION: Create all tables
       ========================================================================= */

    public static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // =====================================================================
        // CORE TABLES (original 9)
        // =====================================================================

        // --- ptp_applications ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_applications (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            name varchar(100) NOT NULL DEFAULT '',
            email varchar(255) NOT NULL DEFAULT '',
            phone varchar(20) DEFAULT '',
            city varchar(100) DEFAULT '',
            state varchar(50) DEFAULT '',
            playing_level varchar(50) DEFAULT '',
            college varchar(255) DEFAULT '',
            team varchar(255) DEFAULT '',
            experience_years int(11) DEFAULT 0,
            bio text,
            why_train text,
            resume_url varchar(500) DEFAULT '',
            instagram varchar(100) DEFAULT '',
            referral_source varchar(100) DEFAULT '',
            location varchar(255) DEFAULT '',
            headline varchar(255) DEFAULT '',
            specialties text,
            hourly_rate decimal(10,2) DEFAULT 0,
            travel_radius int(11) DEFAULT 15,
            password_hash varchar(255) DEFAULT '',
            status varchar(20) DEFAULT 'pending',
            admin_notes text,
            reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_app_status (status),
            KEY idx_app_email (email),
            KEY idx_app_user (user_id)
        ) $c;");

        // --- ptp_trainers ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_trainers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            display_name varchar(100) NOT NULL DEFAULT '',
            slug varchar(100) NOT NULL DEFAULT '',
            email varchar(255) DEFAULT '',
            phone varchar(20) DEFAULT '',
            headline varchar(255) DEFAULT '',
            bio text,
            coaching_why text,
            training_philosophy text,
            training_policy text,
            photo_url varchar(500) DEFAULT '',
            cover_photo_url varchar(500) DEFAULT '',
            gallery text,
            hourly_rate decimal(10,2) NOT NULL DEFAULT 70,
            location varchar(255) DEFAULT '',
            city varchar(100) DEFAULT '',
            state varchar(50) DEFAULT '',
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            travel_radius int(11) DEFAULT 15,
            training_locations text,
            college varchar(255) DEFAULT '',
            team varchar(255) DEFAULT '',
            playing_level varchar(50) DEFAULT '',
            position varchar(100) DEFAULT '',
            experience_years int(11) DEFAULT 0,
            years_coaching int(11) DEFAULT 0,
            specialties text,
            instagram varchar(100) DEFAULT '',
            facebook varchar(100) DEFAULT '',
            twitter varchar(100) DEFAULT '',
            safesport_doc_url varchar(500) DEFAULT '',
            safesport_verified tinyint(1) DEFAULT 0,
            safesport_expiry date DEFAULT NULL,
            safesport_requested_at datetime DEFAULT NULL,
            background_doc_url varchar(500) DEFAULT '',
            background_verified tinyint(1) DEFAULT 0,
            background_requested_at datetime DEFAULT NULL,
            tax_id_last4 varchar(4) DEFAULT '',
            tax_id_type varchar(10) DEFAULT 'ssn',
            legal_name varchar(255) DEFAULT '',
            tax_address_line1 varchar(255) DEFAULT '',
            tax_address_line2 varchar(255) DEFAULT '',
            tax_city varchar(100) DEFAULT '',
            tax_state varchar(2) DEFAULT '',
            tax_zip varchar(10) DEFAULT '',
            w9_submitted tinyint(1) DEFAULT 0,
            w9_submitted_at datetime DEFAULT NULL,
            w9_requested_at datetime DEFAULT NULL,
            contractor_agreement_signed tinyint(1) DEFAULT 0,
            contractor_agreement_signed_at datetime DEFAULT NULL,
            contractor_agreement_ip varchar(45) DEFAULT '',
            stripe_account_id varchar(255) DEFAULT '',
            stripe_charges_enabled tinyint(1) DEFAULT 0,
            stripe_payouts_enabled tinyint(1) DEFAULT 0,
            stripe_onboarding_complete tinyint(1) DEFAULT 0,
            stripe_reminder_sent_at datetime DEFAULT NULL,
            stripe_reminder_count int(11) DEFAULT 0,
            payout_method varchar(50) DEFAULT 'venmo',
            payout_venmo varchar(100) DEFAULT '',
            payout_paypal varchar(255) DEFAULT '',
            payout_zelle varchar(255) DEFAULT '',
            payout_cashapp varchar(100) DEFAULT '',
            payout_bank_name varchar(100) DEFAULT '',
            payout_bank_routing varchar(9) DEFAULT '',
            payout_bank_account varchar(20) DEFAULT '',
            payout_bank_account_type varchar(10) DEFAULT 'checking',
            payout_check_address text DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            approved_at datetime DEFAULT NULL,
            is_featured tinyint(1) DEFAULT 0,
            sort_order int(11) DEFAULT 0,
            is_verified tinyint(1) DEFAULT 0,
            is_background_checked tinyint(1) DEFAULT 0,
            total_sessions int(11) DEFAULT 0,
            total_earnings decimal(10,2) DEFAULT 0,
            total_paid decimal(10,2) DEFAULT 0,
            average_rating decimal(3,2) DEFAULT 0,
            review_count int(11) DEFAULT 0,
            happy_student_score int(11) DEFAULT 0,
            reliability_score int(11) DEFAULT 100,
            responsiveness_score int(11) DEFAULT 100,
            return_rate int(11) DEFAULT 0,
            is_supercoach tinyint(1) DEFAULT 0,
            supercoach_awarded_at datetime DEFAULT NULL,
            intro_video_url varchar(500) DEFAULT '',
            mentorship_enabled tinyint(1) DEFAULT 0,
            mentorship_packages varchar(100) DEFAULT 'single,kickstart,development,elite',
            mentorship_bio text DEFAULT NULL,
            mentorship_max_mentees int(11) DEFAULT 20,
            lesson_lengths varchar(50) DEFAULT '60',
            max_participants int(11) DEFAULT 1,
            trainer_faqs longtext DEFAULT NULL,
            bio_sections text DEFAULT NULL,
            session_preferences text DEFAULT NULL,
            last_nudge_at datetime DEFAULT NULL,
            last_nudge_type varchar(50) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_trainer_status (status),
            KEY idx_trainer_slug (slug),
            KEY idx_trainer_user (user_id),
            KEY idx_trainer_email (email)
        ) $c;");

        // --- ptp_parents (canonical: superset of all 3 old definitions) ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_parents (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            display_name varchar(100) NOT NULL DEFAULT '',
            first_name varchar(100) DEFAULT '',
            last_name varchar(100) DEFAULT '',
            phone varchar(20) DEFAULT '',
            email varchar(255) DEFAULT '',
            location varchar(255) DEFAULT '',
            address text DEFAULT NULL,
            city varchar(100) DEFAULT '',
            state varchar(50) DEFAULT '',
            zip varchar(20) DEFAULT '',
            latitude decimal(10,8) DEFAULT NULL,
            longitude decimal(11,8) DEFAULT NULL,
            emergency_name varchar(100) DEFAULT '',
            emergency_phone varchar(20) DEFAULT '',
            emergency_relation varchar(50) DEFAULT '',
            medical_info text DEFAULT NULL,
            total_sessions int(11) DEFAULT 0,
            total_spent decimal(10,2) DEFAULT 0.00,
            notification_email tinyint(1) DEFAULT 1,
            notification_sms tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_parent_user (user_id),
            KEY idx_parent_email (email)
        ) $c;");

        // --- ptp_players (canonical: superset of all 3 old definitions) ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_players (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            name varchar(100) NOT NULL DEFAULT '',
            first_name varchar(100) DEFAULT '',
            last_name varchar(100) DEFAULT '',
            age int(11) DEFAULT NULL,
            birth_date date DEFAULT NULL,
            dob date DEFAULT NULL,
            gender varchar(20) DEFAULT '',
            skill_level varchar(20) DEFAULT 'beginner',
            position varchar(100) DEFAULT '',
            current_team varchar(100) DEFAULT '',
            shirt_size varchar(20) DEFAULT '',
            goals text,
            notes text,
            emergency_name varchar(255) DEFAULT '',
            emergency_phone varchar(50) DEFAULT '',
            emergency_relation varchar(100) DEFAULT '',
            medical_info text DEFAULT NULL,
            insurance_provider varchar(255) DEFAULT '',
            insurance_policy varchar(100) DEFAULT '',
            insurance_group varchar(100) DEFAULT '',
            waiver_accepted tinyint(1) DEFAULT 0,
            waiver_accepted_at datetime DEFAULT NULL,
            waiver_ip varchar(45) DEFAULT '',
            photo_consent tinyint(1) DEFAULT 1,
            instagram_handle varchar(100) DEFAULT '',
            player_photo_url varchar(500) DEFAULT '',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player_parent (parent_id)
        ) $c;");

        // --- ptp_availability ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_availability (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            day_of_week tinyint(1) NOT NULL DEFAULT 0,
            start_time time NOT NULL,
            end_time time NOT NULL,
            slot_duration int(11) DEFAULT 60,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_avail_trainer (trainer_id, day_of_week)
        ) $c;");

        // --- ptp_bookings (canonical: uses start_time, NOT session_time) ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_bookings (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_number varchar(50) NOT NULL DEFAULT '',
            trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            player_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            session_date date DEFAULT NULL,
            start_time time DEFAULT NULL,
            session_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            duration_minutes int(11) DEFAULT 60,
            location varchar(255) DEFAULT '',
            location_notes text,
            hourly_rate decimal(10,2) NOT NULL DEFAULT 0,
            total_amount decimal(10,2) NOT NULL DEFAULT 0,
            platform_fee decimal(10,2) DEFAULT 0,
            trainer_payout decimal(10,2) NOT NULL DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            parent_confirmed tinyint(1) DEFAULT 0,
            trainer_confirmed tinyint(1) DEFAULT 0,
            parent_confirmed_at datetime DEFAULT NULL,
            trainer_confirmed_at datetime DEFAULT NULL,
            cancelled_by varchar(20) DEFAULT NULL,
            cancellation_reason text,
            cancelled_at datetime DEFAULT NULL,
            payment_status varchar(20) DEFAULT 'pending',
            payment_intent_id varchar(255) DEFAULT '',
            stripe_payment_id varchar(255) DEFAULT '',
            payout_status varchar(20) DEFAULT 'pending',
            payout_date datetime DEFAULT NULL,
            stripe_transfer_id varchar(255) DEFAULT '',
            refund_status varchar(20) DEFAULT 'none',
            refund_amount decimal(10,2) DEFAULT 0,
            stripe_refund_id varchar(255) DEFAULT '',
            reminder_sent tinyint(1) DEFAULT 0,
            hour_reminder_sent tinyint(1) DEFAULT 0,
            review_request_sent tinyint(1) DEFAULT 0,
            completion_request_sent tinyint(1) DEFAULT 0,
            is_recurring tinyint(1) DEFAULT 0,
            recurring_id bigint(20) UNSIGNED DEFAULT NULL,
            notes text,
            session_type varchar(20) DEFAULT 'single',
            session_count int(11) DEFAULT 1,
            sessions_remaining int(11) DEFAULT 1,
            group_players text,
            group_size int(11) DEFAULT 1,
            coupon_code varchar(50) DEFAULT '',
            coupon_discount decimal(10,2) DEFAULT 0,
            coupon_id bigint(20) UNSIGNED DEFAULT NULL,
            free_code_id bigint(20) UNSIGNED DEFAULT NULL,
            referral_code varchar(50) DEFAULT '',
            referral_discount decimal(10,2) DEFAULT 0,
            referral_id bigint(20) UNSIGNED DEFAULT NULL,
            package_type varchar(50) DEFAULT 'single',
            total_sessions int(11) DEFAULT 1,
            sessions_completed int(11) DEFAULT 0,
            amount_paid decimal(10,2) DEFAULT 0,
            guest_email varchar(255) DEFAULT NULL,
            source varchar(50) DEFAULT '',
            escrow_id bigint(20) UNSIGNED DEFAULT NULL,
            escrow_status varchar(30) DEFAULT '',
            funds_held tinyint(1) DEFAULT 0,
            package_credit_id bigint(20) UNSIGNED DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_booking_trainer_date (trainer_id, session_date),
            KEY idx_booking_parent (parent_id),
            KEY idx_booking_number (booking_number),
            KEY idx_booking_status (status),
            KEY idx_booking_payment (payment_intent_id(191))
        ) $c;");

        // --- ptp_reviews ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_reviews (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            rating tinyint(1) NOT NULL DEFAULT 5,
            review_text text,
            trainer_response text,
            trainer_responded_at datetime DEFAULT NULL,
            is_public tinyint(1) DEFAULT 1,
            is_verified tinyint(1) DEFAULT 0,
            status varchar(20) DEFAULT 'published',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_review_trainer (trainer_id)
        ) $c;");

        // --- ptp_conversations ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_conversations (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            last_message_id bigint(20) UNSIGNED DEFAULT NULL,
            last_message_at datetime DEFAULT NULL,
            trainer_unread_count int(11) DEFAULT 0,
            parent_unread_count int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_conv_trainer_parent (trainer_id, parent_id)
        ) $c;");

        // --- ptp_messages ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            sender_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            sender_type varchar(20) DEFAULT 'user',
            message text NOT NULL,
            is_read tinyint(1) DEFAULT 0,
            read_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_msg_conversation (conversation_id)
        ) $c;");

        // --- ptp_escrow ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_escrow (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            payment_intent_id varchar(255) NOT NULL DEFAULT '',
            total_amount decimal(10,2) NOT NULL DEFAULT 0,
            platform_fee decimal(10,2) NOT NULL DEFAULT 0,
            trainer_amount decimal(10,2) NOT NULL DEFAULT 0,
            fee_rate decimal(4,2) DEFAULT 0.15,
            session_number int UNSIGNED DEFAULT 1,
            status varchar(30) DEFAULT 'holding',
            session_date date DEFAULT NULL,
            session_time time DEFAULT NULL,
            trainer_completed_at datetime DEFAULT NULL,
            parent_confirmed_at datetime DEFAULT NULL,
            auto_confirmed tinyint(1) DEFAULT 0,
            release_eligible_at datetime DEFAULT NULL,
            released_at datetime DEFAULT NULL,
            stripe_transfer_id varchar(255) DEFAULT '',
            release_method varchar(50) DEFAULT '',
            release_notes text,
            disputed_at datetime DEFAULT NULL,
            dispute_reason text,
            dispute_resolution varchar(50) DEFAULT '',
            dispute_resolved_at datetime DEFAULT NULL,
            dispute_resolved_by bigint(20) UNSIGNED DEFAULT NULL,
            resolution_notes text,
            refund_amount decimal(10,2) DEFAULT 0,
            parent_rating tinyint(1) DEFAULT NULL,
            parent_feedback text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_escrow_booking (booking_id)
        ) $c;");

        // =====================================================================
        // FINANCIAL (previously missing — 40+ broken SQL operations)
        // =====================================================================

        // --- ptp_payouts ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_payouts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0,
            gross_amount decimal(10,2) DEFAULT 0,
            fee decimal(10,2) DEFAULT 0,
            total_amount decimal(10,2) DEFAULT 0,
            platform_fee decimal(10,2) DEFAULT 0,
            trainer_amount decimal(10,2) DEFAULT 0,
            booking_count int(11) DEFAULT 1,
            method varchar(50) DEFAULT '',
            payout_method varchar(50) DEFAULT 'stripe',
            payout_reference varchar(255) DEFAULT '',
            stripe_transfer_id varchar(255) DEFAULT '',
            transaction_id varchar(255) DEFAULT '',
            status varchar(30) DEFAULT 'pending',
            notes text DEFAULT NULL,
            processed_by bigint(20) UNSIGNED DEFAULT NULL,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            processed_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_payout_trainer (trainer_id),
            KEY idx_payout_status (status),
            KEY idx_payout_booking (booking_id)
        ) $c;");

        // --- ptp_payout_items ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_payout_items (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payout_id bigint(20) UNSIGNED NOT NULL,
            booking_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pi_payout (payout_id),
            KEY idx_pi_booking (booking_id)
        ) $c;");

        // =====================================================================
        // SCHEDULING (previously missing — 20+ broken SQL operations)
        // =====================================================================

        // --- ptp_sessions (admin-created sessions via Schedule Calendar) ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_sessions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            customer_id bigint(20) UNSIGNED DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,
            player_id bigint(20) UNSIGNED DEFAULT NULL,
            player_name varchar(100) DEFAULT '',
            player_age int(11) DEFAULT NULL,
            session_date date NOT NULL,
            start_time time NOT NULL,
            end_time time DEFAULT NULL,
            duration_minutes int(11) DEFAULT 60,
            session_status varchar(30) DEFAULT 'scheduled',
            payment_status varchar(30) DEFAULT 'unpaid',
            session_type varchar(20) DEFAULT '1on1',
            location_text varchar(255) DEFAULT '',
            price decimal(10,2) DEFAULT 0,
            trainer_payout decimal(10,2) DEFAULT 0,
            internal_notes text DEFAULT NULL,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session_trainer (trainer_id),
            KEY idx_session_date (session_date),
            KEY idx_session_status (session_status)
        ) $c;");

        // --- ptp_calendar_connections (Google Calendar OAuth) ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_calendar_connections (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            provider varchar(20) DEFAULT 'google',
            access_token text DEFAULT NULL,
            refresh_token text DEFAULT NULL,
            token_expires datetime DEFAULT NULL,
            calendar_id varchar(255) DEFAULT '',
            calendar_email varchar(255) DEFAULT '',
            sync_enabled tinyint(1) DEFAULT 1,
            last_sync datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cal_user (user_id),
            KEY idx_cal_provider (user_id, provider)
        ) $c;");

        // --- ptp_availability_exceptions ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_availability_exceptions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            exception_date date NOT NULL,
            exception_type varchar(20) DEFAULT 'blocked',
            is_available tinyint(1) DEFAULT 0,
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            reason varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_exc_trainer_date (trainer_id, exception_date),
            KEY idx_exc_date (exception_date)
        ) $c;");

        // --- ptp_open_dates ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_open_dates (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            date date NOT NULL,
            start_time time NOT NULL DEFAULT '09:00:00',
            end_time time NOT NULL DEFAULT '17:00:00',
            location varchar(255) DEFAULT '',
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_od_trainer_date (trainer_id, date),
            KEY idx_od_date (date)
        ) $c;");

        // =====================================================================
        // ENGAGEMENT (previously missing — push notifications broken)
        // =====================================================================

        // --- ptp_notifications ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_notifications (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            type varchar(50) DEFAULT 'general',
            title varchar(255) DEFAULT '',
            message text DEFAULT NULL,
            data longtext DEFAULT NULL,
            is_read tinyint(1) DEFAULT 0,
            read_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notif_user (user_id),
            KEY idx_notif_read (user_id, is_read)
        ) $c;");

        // --- ptp_fcm_tokens ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_fcm_tokens (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            token text NOT NULL,
            device_type varchar(20) DEFAULT '',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_fcm_user (user_id),
            KEY idx_fcm_active (is_active)
        ) $c;");

        // =====================================================================
        // REFERRALS & COUPONS (previously missing)
        // =====================================================================

        // --- ptp_referral_codes ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_referral_codes (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            code varchar(32) NOT NULL DEFAULT '',
            reward_amount decimal(10,2) DEFAULT 20.00,
            discount_amount decimal(10,2) DEFAULT 20.00,
            times_used int(11) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_rc_code (code),
            KEY idx_rc_user (user_id)
        ) $c;");

        // --- ptp_referral_uses ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_referral_uses (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            referrer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            referred_id bigint(20) UNSIGNED DEFAULT NULL,
            referral_code varchar(32) DEFAULT '',
            booking_id bigint(20) UNSIGNED DEFAULT NULL,
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            status varchar(20) DEFAULT 'completed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ru_referrer (referrer_id),
            KEY idx_ru_code (referral_code)
        ) $c;");

        // --- ptp_coupons ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_coupons (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code varchar(50) NOT NULL DEFAULT '',
            description varchar(255) DEFAULT '',
            discount_type varchar(20) DEFAULT 'fixed',
            discount_value decimal(10,2) DEFAULT 0,
            applies_to varchar(50) DEFAULT 'all',
            minimum_spend decimal(10,2) DEFAULT 0,
            usage_limit int(11) DEFAULT 0,
            usage_count int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'active',
            starts_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_coupon_code (code),
            KEY idx_coupon_status (status)
        ) $c;");

        // =====================================================================
        // BOOKING META (previously missing)
        // =====================================================================

        // --- ptp_booking_meta ---
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_booking_meta (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            meta_key varchar(255) NOT NULL DEFAULT '',
            meta_value longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bm_booking (booking_id),
            KEY idx_bm_key (meta_key(191))
        ) $c;");

        update_option('ptp_db_version', '220.0.0');
        
        // v227: Flush table existence cache after creating/updating tables
        if (class_exists('PTP_Query_Cache')) {
            PTP_Query_Cache::flush_table_cache();
        }
    }

    /* =========================================================================
       MIGRATION: Add missing columns to existing installs
       ========================================================================= */

    public static function maybe_migrate() {
        $current = get_option('ptp_db_version', '0');
        if (version_compare($current, '220.0.0', '>=')) {
            return;
        }

        global $wpdb;

        // Re-run create_tables — dbDelta is safe to run on existing tables
        // and will ADD missing columns without dropping data.
        self::create_tables();

        // --- ptp_parents: ensure new columns exist on old installs ---
        $table = $wpdb->prefix . 'ptp_parents';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            $needed = array(
                'first_name'         => "varchar(100) DEFAULT ''",
                'last_name'          => "varchar(100) DEFAULT ''",
                'emergency_name'     => "varchar(100) DEFAULT ''",
                'emergency_phone'    => "varchar(20) DEFAULT ''",
                'emergency_relation' => "varchar(50) DEFAULT ''",
                'medical_info'       => "text DEFAULT NULL",
                'address'            => "text DEFAULT NULL",
                'city'               => "varchar(100) DEFAULT ''",
                'state'              => "varchar(50) DEFAULT ''",
                'zip'                => "varchar(20) DEFAULT ''",
            );
            foreach ($needed as $col => $def) {
                if (!in_array($col, $cols)) {
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                }
            }
        }

        // --- ptp_players: ensure new columns exist ---
        $table = $wpdb->prefix . 'ptp_players';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            $needed = array(
                'first_name'         => "varchar(100) DEFAULT ''",
                'last_name'          => "varchar(100) DEFAULT ''",
                'dob'                => "date DEFAULT NULL",
                'gender'             => "varchar(20) DEFAULT ''",
                'shirt_size'         => "varchar(20) DEFAULT ''",
                'emergency_name'     => "varchar(255) DEFAULT ''",
                'emergency_phone'    => "varchar(50) DEFAULT ''",
                'emergency_relation' => "varchar(100) DEFAULT ''",
                'medical_info'       => "text DEFAULT NULL",
                'insurance_provider' => "varchar(255) DEFAULT ''",
                'insurance_policy'   => "varchar(100) DEFAULT ''",
                'insurance_group'    => "varchar(100) DEFAULT ''",
                'waiver_accepted'    => "tinyint(1) DEFAULT 0",
                'waiver_accepted_at' => "datetime DEFAULT NULL",
                'waiver_ip'          => "varchar(45) DEFAULT ''",
                'photo_consent'      => "tinyint(1) DEFAULT 1",
                'instagram_handle'   => "varchar(100) DEFAULT ''",
                'player_photo_url'   => "varchar(500) DEFAULT ''",
            );
            foreach ($needed as $col => $def) {
                if (!in_array($col, $cols)) {
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                }
            }
        }

        // --- ptp_bookings: ensure all columns exist ---
        $table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            $needed = array(
                'booking_number'         => "varchar(50) NOT NULL DEFAULT ''",
                'start_time'             => "time DEFAULT NULL",
                'session_time'           => "time DEFAULT NULL",
                'end_time'               => "time DEFAULT NULL",
                'duration_minutes'       => "int(11) DEFAULT 60",
                'location_notes'         => "text",
                'hourly_rate'            => "decimal(10,2) NOT NULL DEFAULT 0",
                'total_amount'           => "decimal(10,2) NOT NULL DEFAULT 0",
                'platform_fee'           => "decimal(10,2) DEFAULT 0",
                'parent_confirmed'       => "tinyint(1) DEFAULT 0",
                'trainer_confirmed'      => "tinyint(1) DEFAULT 0",
                'parent_confirmed_at'    => "datetime DEFAULT NULL",
                'trainer_confirmed_at'   => "datetime DEFAULT NULL",
                'cancelled_by'           => "varchar(20) DEFAULT NULL",
                'cancellation_reason'    => "text",
                'cancelled_at'           => "datetime DEFAULT NULL",
                'payment_status'         => "varchar(20) DEFAULT 'pending'",
                'payment_intent_id'      => "varchar(255) DEFAULT ''",
                'stripe_payment_id'      => "varchar(255) DEFAULT ''",
                'payout_status'          => "varchar(20) DEFAULT 'pending'",
                'payout_date'            => "datetime DEFAULT NULL",
                'stripe_transfer_id'     => "varchar(255) DEFAULT ''",
                'refund_status'          => "varchar(20) DEFAULT 'none'",
                'refund_amount'          => "decimal(10,2) DEFAULT 0",
                'stripe_refund_id'       => "varchar(255) DEFAULT ''",
                'reminder_sent'          => "tinyint(1) DEFAULT 0",
                'hour_reminder_sent'     => "tinyint(1) DEFAULT 0",
                'review_request_sent'    => "tinyint(1) DEFAULT 0",
                'completion_request_sent'=> "tinyint(1) DEFAULT 0",
                'is_recurring'           => "tinyint(1) DEFAULT 0",
                'recurring_id'           => "bigint(20) UNSIGNED DEFAULT NULL",
                'session_type'           => "varchar(20) DEFAULT 'single'",
                'session_count'          => "int(11) DEFAULT 1",
                'sessions_remaining'     => "int(11) DEFAULT 1",
                'group_players'          => "text",
                'group_size'             => "int(11) DEFAULT 1",
                'coupon_code'            => "varchar(50) DEFAULT ''",
                'coupon_discount'        => "decimal(10,2) DEFAULT 0",
                'coupon_id'              => "bigint(20) UNSIGNED DEFAULT NULL",
                'free_code_id'           => "bigint(20) UNSIGNED DEFAULT NULL",
                'referral_code'          => "varchar(50) DEFAULT ''",
                'referral_discount'      => "decimal(10,2) DEFAULT 0",
                'referral_id'            => "bigint(20) UNSIGNED DEFAULT NULL",
                'package_type'           => "varchar(50) DEFAULT 'single'",
                'total_sessions'         => "int(11) DEFAULT 1",
                'sessions_completed'     => "int(11) DEFAULT 0",
                'amount_paid'            => "decimal(10,2) DEFAULT 0",
                'guest_email'            => "varchar(255) DEFAULT NULL",
                'source'                 => "varchar(50) DEFAULT ''",
                'escrow_id'              => "bigint(20) UNSIGNED DEFAULT NULL",
                'escrow_status'          => "varchar(30) DEFAULT ''",
                'funds_held'             => "tinyint(1) DEFAULT 0",
                'package_credit_id'      => "bigint(20) UNSIGNED DEFAULT NULL",
                'completed_at'           => "datetime DEFAULT NULL",
            );
            foreach ($needed as $col => $def) {
                if (!in_array($col, $cols)) {
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                }
            }

            // Migrate session_time data to start_time if session_time column exists
            if (in_array('session_time', $cols) && in_array('start_time', $cols)) {
                $wpdb->query("UPDATE {$table} SET start_time = session_time WHERE start_time IS NULL AND session_time IS NOT NULL");
            }
        }

        // --- ptp_trainers: ensure new columns ---
        $table = $wpdb->prefix . 'ptp_trainers';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            $needed = array(
                'approved_at'      => "datetime DEFAULT NULL",
                'last_nudge_at'    => "datetime DEFAULT NULL",
                'last_nudge_type'  => "varchar(50) DEFAULT ''",
            );
            foreach ($needed as $col => $def) {
                if (!in_array($col, $cols)) {
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                }
            }
        }

        // --- ptp_applications: ensure new columns ---
        $table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            $needed = array(
                'location'      => "varchar(255) DEFAULT ''",
                'headline'      => "varchar(255) DEFAULT ''",
                'specialties'   => "text",
                'hourly_rate'   => "decimal(10,2) DEFAULT 0",
                'travel_radius' => "int(11) DEFAULT 15",
                'password_hash' => "varchar(255) DEFAULT ''",
            );
            foreach ($needed as $col => $def) {
                if (!in_array($col, $cols)) {
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                }
            }
        }

        // --- ptp_availability: add slot_duration if missing ---
        $table = $wpdb->prefix . 'ptp_availability';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            if (!in_array('slot_duration', $cols)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN slot_duration int(11) DEFAULT 60");
            }
        }

        update_option('ptp_db_version', '220.0.0');
        ptp_log('PTP: Database migrated to v220.0.0 — all tables verified');
    }

    public static function seed_defaults() {
        // Pages are auto-created in the main plugin file.
    }
}
