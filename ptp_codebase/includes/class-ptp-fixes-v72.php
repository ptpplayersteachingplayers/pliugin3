<?php
/**
 * PTP Fixes v72 - Comprehensive Issue Resolution
 * 
 * Addresses:
 * - 10.1 Missing Table Columns (Auto-repair on init)
 * - 10.2 Nonce Expiration (Auto-refresh system)
 * - 10.3 Stripe Connect Onboarding (Reminders + Admin tools)
 * - 10.4 Duplicate File Versions (Unified template loader)
 * - 10.5 Large Class Files (Autoloader implementation)
 * - 10.6 Caching Considerations (Cache exclusion headers)
 * 
 * @version 72.0.0
 */

defined('ABSPATH') || exit;

class PTP_Fixes_V72 {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // 10.1: Auto-repair tables on init (cached - runs once per day max)
        add_action('init', array($this, 'maybe_auto_repair_tables'), 5);
        
        // 10.2: Nonce refresh system
        add_action('wp_enqueue_scripts', array($this, 'enqueue_nonce_refresh'));
        add_action('wp_ajax_ptp_refresh_nonce', array($this, 'ajax_refresh_nonce'));
        add_action('wp_ajax_nopriv_ptp_refresh_nonce', array($this, 'ajax_refresh_nonce'));
        
        // 10.3: Stripe Connect reminder system
        add_action('ptp_daily_cron', array($this, 'send_stripe_connect_reminders'));
        add_action('admin_init', array($this, 'register_stripe_reminder_settings'));
        
        // 10.4: Template version consolidation
        add_filter('ptp_template_file', array($this, 'consolidate_template_versions'), 10, 2);
        
        // 10.6: Cache exclusion for dynamic pages
        add_action('template_redirect', array($this, 'add_cache_exclusion_headers'), 1);
        add_filter('rocket_cache_reject_uri', array($this, 'wp_rocket_exclude_pages'));
        add_filter('w3tc_reject_uri', array($this, 'w3tc_exclude_pages'));
        add_filter('litespeed_cache_exclude', array($this, 'litespeed_exclude_pages'));
        
        // Register the daily cron if not exists
        if (!wp_next_scheduled('ptp_daily_cron')) {
            wp_schedule_event(time(), 'daily', 'ptp_daily_cron');
        }
    }
    
    // =========================================================================
    // 10.1: AUTO-REPAIR MISSING TABLE COLUMNS
    // =========================================================================
    
    /**
     * Auto-repair tables once per day (cached check)
     */
    public function maybe_auto_repair_tables() {
        $last_repair = get_option('ptp_last_auto_repair', 0);
        $one_day = 86400;
        
        // Only run once per day
        if (time() - $last_repair < $one_day) {
            return;
        }
        
        // Run comprehensive repair
        $this->comprehensive_table_repair();
        
        update_option('ptp_last_auto_repair', time());
    }
    
    /**
     * Comprehensive table repair - adds ALL missing columns to ALL tables
     */
    public function comprehensive_table_repair() {
        global $wpdb;
        
        $repairs_made = 0;
        
        // ============ ptp_trainers - ALL columns ============
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        if ($wpdb->get_var("SHOW TABLES LIKE '$trainers_table'")) {
            $trainer_columns = array(
                'user_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'display_name' => "varchar(100) NOT NULL DEFAULT ''",
                'slug' => "varchar(100) NOT NULL DEFAULT ''",
                'email' => "varchar(255) DEFAULT ''",
                'phone' => "varchar(20) DEFAULT ''",
                'headline' => "varchar(255) DEFAULT ''",
                'bio' => "text",
                'photo_url' => "varchar(500) DEFAULT ''",
                'cover_photo_url' => "varchar(500) DEFAULT ''",
                'gallery' => "text",
                'hourly_rate' => "decimal(10,2) NOT NULL DEFAULT 70",
                'location' => "varchar(255) DEFAULT ''",
                'city' => "varchar(100) DEFAULT ''",
                'state' => "varchar(50) DEFAULT ''",
                'latitude' => "decimal(10,8) DEFAULT NULL",
                'longitude' => "decimal(11,8) DEFAULT NULL",
                'travel_radius' => "int(11) DEFAULT 15",
                'training_locations' => "text",
                'college' => "varchar(255) DEFAULT ''",
                'team' => "varchar(255) DEFAULT ''",
                'playing_level' => "varchar(50) DEFAULT ''",
                'position' => "varchar(100) DEFAULT ''",
                'experience_years' => "int(11) DEFAULT 0",
                'specialties' => "text",
                'instagram' => "varchar(100) DEFAULT ''",
                'facebook' => "varchar(100) DEFAULT ''",
                'twitter' => "varchar(100) DEFAULT ''",
                'safesport_doc_url' => "varchar(500) DEFAULT ''",
                'safesport_verified' => "tinyint(1) DEFAULT 0",
                'safesport_expiry' => "date DEFAULT NULL",
                'safesport_requested_at' => "datetime DEFAULT NULL",
                'background_doc_url' => "varchar(500) DEFAULT ''",
                'background_verified' => "tinyint(1) DEFAULT 0",
                'background_requested_at' => "datetime DEFAULT NULL",
                'tax_id_last4' => "varchar(4) DEFAULT ''",
                'tax_id_type' => "varchar(10) DEFAULT 'ssn'",
                'legal_name' => "varchar(255) DEFAULT ''",
                'tax_address_line1' => "varchar(255) DEFAULT ''",
                'tax_address_line2' => "varchar(255) DEFAULT ''",
                'tax_city' => "varchar(100) DEFAULT ''",
                'tax_state' => "varchar(2) DEFAULT ''",
                'tax_zip' => "varchar(10) DEFAULT ''",
                'w9_submitted' => "tinyint(1) DEFAULT 0",
                'w9_submitted_at' => "datetime DEFAULT NULL",
                'w9_requested_at' => "datetime DEFAULT NULL",
                'contractor_agreement_signed' => "tinyint(1) DEFAULT 0",
                'contractor_agreement_signed_at' => "datetime DEFAULT NULL",
                'contractor_agreement_ip' => "varchar(45) DEFAULT ''",
                'stripe_account_id' => "varchar(255) DEFAULT ''",
                'stripe_charges_enabled' => "tinyint(1) DEFAULT 0",
                'stripe_payouts_enabled' => "tinyint(1) DEFAULT 0",
                'stripe_onboarding_complete' => "tinyint(1) DEFAULT 0",
                'stripe_reminder_sent_at' => "datetime DEFAULT NULL",
                'stripe_reminder_count' => "int(11) DEFAULT 0",
                'payout_method' => "varchar(50) DEFAULT 'venmo'",
                'payout_venmo' => "varchar(100) DEFAULT ''",
                'payout_paypal' => "varchar(255) DEFAULT ''",
                'payout_zelle' => "varchar(255) DEFAULT ''",
                'payout_cashapp' => "varchar(100) DEFAULT ''",
                'payout_bank_name' => "varchar(100) DEFAULT ''",
                'payout_bank_routing' => "varchar(9) DEFAULT ''",
                'payout_bank_account' => "varchar(20) DEFAULT ''",
                'payout_bank_account_type' => "varchar(10) DEFAULT 'checking'",
                'payout_check_address' => "text DEFAULT NULL",
                'status' => "varchar(20) DEFAULT 'pending'",
                'is_featured' => "tinyint(1) DEFAULT 0",
                'sort_order' => "int(11) DEFAULT 0",
                'is_verified' => "tinyint(1) DEFAULT 0",
                'is_background_checked' => "tinyint(1) DEFAULT 0",
                'total_sessions' => "int(11) DEFAULT 0",
                'total_earnings' => "decimal(10,2) DEFAULT 0",
                'total_paid' => "decimal(10,2) DEFAULT 0",
                'average_rating' => "decimal(3,2) DEFAULT 0",
                'review_count' => "int(11) DEFAULT 0",
                'happy_student_score' => "int(11) DEFAULT 0",
                'reliability_score' => "int(11) DEFAULT 100",
                'responsiveness_score' => "int(11) DEFAULT 100",
                'return_rate' => "int(11) DEFAULT 0",
                'is_supercoach' => "tinyint(1) DEFAULT 0",
                'supercoach_awarded_at' => "datetime DEFAULT NULL",
                'intro_video_url' => "varchar(500) DEFAULT ''",
                'lesson_lengths' => "varchar(50) DEFAULT '60'",
                'max_participants' => "int(11) DEFAULT 1",
                'trainer_faqs' => "longtext DEFAULT NULL",
                'bio_sections' => "text DEFAULT NULL",
                'session_preferences' => "text DEFAULT NULL",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            
            $repairs_made += $this->add_missing_columns($trainers_table, $trainer_columns);
        }
        
        // ============ ptp_bookings - ALL columns ============
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bookings_table'")) {
            $booking_columns = array(
                'booking_number' => "varchar(20) NOT NULL DEFAULT ''",
                'trainer_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'parent_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'player_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'session_date' => "date DEFAULT NULL",
                'start_time' => "time DEFAULT NULL",
                'end_time' => "time DEFAULT NULL",
                'duration_minutes' => "int(11) DEFAULT 60",
                'location' => "varchar(255) DEFAULT ''",
                'location_notes' => "text",
                'hourly_rate' => "decimal(10,2) NOT NULL DEFAULT 0",
                'total_amount' => "decimal(10,2) NOT NULL DEFAULT 0",
                'platform_fee' => "decimal(10,2) DEFAULT 0",
                'trainer_payout' => "decimal(10,2) NOT NULL DEFAULT 0",
                'status' => "varchar(20) DEFAULT 'pending'",
                'parent_confirmed' => "tinyint(1) DEFAULT 0",
                'trainer_confirmed' => "tinyint(1) DEFAULT 0",
                'parent_confirmed_at' => "datetime DEFAULT NULL",
                'trainer_confirmed_at' => "datetime DEFAULT NULL",
                'cancelled_by' => "varchar(20) DEFAULT NULL",
                'cancellation_reason' => "text",
                'cancelled_at' => "datetime DEFAULT NULL",
                'payment_status' => "varchar(20) DEFAULT 'pending'",
                'payment_intent_id' => "varchar(255) DEFAULT ''",
                'stripe_payment_id' => "varchar(255) DEFAULT ''",
                'payout_status' => "varchar(20) DEFAULT 'pending'",
                'payout_date' => "datetime DEFAULT NULL",
                'stripe_transfer_id' => "varchar(255) DEFAULT ''",
                'refund_status' => "varchar(20) DEFAULT 'none'",
                'refund_amount' => "decimal(10,2) DEFAULT 0",
                'stripe_refund_id' => "varchar(255) DEFAULT ''",
                'reminder_sent' => "tinyint(1) DEFAULT 0",
                'hour_reminder_sent' => "tinyint(1) DEFAULT 0",
                'review_request_sent' => "tinyint(1) DEFAULT 0",
                'completion_request_sent' => "tinyint(1) DEFAULT 0",
                'is_recurring' => "tinyint(1) DEFAULT 0",
                'recurring_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'notes' => "text",
                'session_type' => "varchar(20) DEFAULT 'single'",
                'session_count' => "int(11) DEFAULT 1",
                'sessions_remaining' => "int(11) DEFAULT 1",
                'group_players' => "text",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            
            $repairs_made += $this->add_missing_columns($bookings_table, $booking_columns);
        }
        
        // ============ ptp_parents - ALL columns ============
        $parents_table = $wpdb->prefix . 'ptp_parents';
        if ($wpdb->get_var("SHOW TABLES LIKE '$parents_table'")) {
            $parent_columns = array(
                'user_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'display_name' => "varchar(100) NOT NULL DEFAULT ''",
                'phone' => "varchar(20) DEFAULT ''",
                'email' => "varchar(255) DEFAULT ''",
                'location' => "varchar(255) DEFAULT ''",
                'latitude' => "decimal(10,8) DEFAULT NULL",
                'longitude' => "decimal(11,8) DEFAULT NULL",
                'total_sessions' => "int(11) DEFAULT 0",
                'total_spent' => "decimal(10,2) DEFAULT 0.00",
                'notification_email' => "tinyint(1) DEFAULT 1",
                'notification_sms' => "tinyint(1) DEFAULT 1",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            
            $repairs_made += $this->add_missing_columns($parents_table, $parent_columns);
        }
        
        // ============ ptp_players - ALL columns ============
        $players_table = $wpdb->prefix . 'ptp_players';
        if ($wpdb->get_var("SHOW TABLES LIKE '$players_table'")) {
            $player_columns = array(
                'parent_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'name' => "varchar(100) NOT NULL DEFAULT ''",
                'age' => "int(11) DEFAULT NULL",
                'birth_date' => "date DEFAULT NULL",
                'skill_level' => "varchar(20) DEFAULT 'beginner'",
                'position' => "varchar(100) DEFAULT ''",
                'current_team' => "varchar(100) DEFAULT ''",
                'goals' => "text",
                'notes' => "text",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            
            $repairs_made += $this->add_missing_columns($players_table, $player_columns);
        }
        
        // ============ ptp_conversations - ALL columns ============
        $conversations_table = $wpdb->prefix . 'ptp_conversations';
        if ($wpdb->get_var("SHOW TABLES LIKE '$conversations_table'")) {
            $conversation_columns = array(
                'trainer_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'parent_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'last_message_id' => "bigint(20) UNSIGNED DEFAULT NULL",
                'last_message_at' => "datetime DEFAULT NULL",
                'trainer_unread_count' => "int(11) DEFAULT 0",
                'parent_unread_count' => "int(11) DEFAULT 0",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            
            $repairs_made += $this->add_missing_columns($conversations_table, $conversation_columns);
        }
        
        // ============ ptp_messages - ALL columns ============
        $messages_table = $wpdb->prefix . 'ptp_messages';
        if ($wpdb->get_var("SHOW TABLES LIKE '$messages_table'")) {
            $message_columns = array(
                'conversation_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'sender_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'sender_type' => "varchar(20) DEFAULT 'user'",
                'message' => "text NOT NULL",
                'is_read' => "tinyint(1) DEFAULT 0",
                'read_at' => "datetime DEFAULT NULL",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
            );
            
            $repairs_made += $this->add_missing_columns($messages_table, $message_columns);
        }
        
        // ============ ptp_escrow - ALL columns ============
        $escrow_table = $wpdb->prefix . 'ptp_escrow';
        if ($wpdb->get_var("SHOW TABLES LIKE '$escrow_table'")) {
            $escrow_columns = array(
                'booking_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'trainer_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'parent_id' => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'payment_intent_id' => "varchar(255) NOT NULL DEFAULT ''",
                'total_amount' => "decimal(10,2) NOT NULL DEFAULT 0",
                'platform_fee' => "decimal(10,2) NOT NULL DEFAULT 0",
                'trainer_amount' => "decimal(10,2) NOT NULL DEFAULT 0",
                'status' => "varchar(30) DEFAULT 'holding'",
                'session_date' => "date DEFAULT NULL",
                'session_time' => "time DEFAULT NULL",
                'trainer_completed_at' => "datetime DEFAULT NULL",
                'parent_confirmed_at' => "datetime DEFAULT NULL",
                'auto_confirmed' => "tinyint(1) DEFAULT 0",
                'release_eligible_at' => "datetime DEFAULT NULL",
                'released_at' => "datetime DEFAULT NULL",
                'stripe_transfer_id' => "varchar(255) DEFAULT ''",
                'release_method' => "varchar(50) DEFAULT ''",
                'release_notes' => "text",
                'disputed_at' => "datetime DEFAULT NULL",
                'dispute_reason' => "text",
                'dispute_resolution' => "varchar(50) DEFAULT ''",
                'dispute_resolved_at' => "datetime DEFAULT NULL",
                'dispute_resolved_by' => "bigint(20) UNSIGNED DEFAULT NULL",
                'resolution_notes' => "text",
                'refund_amount' => "decimal(10,2) DEFAULT 0",
                'parent_rating' => "tinyint(1) DEFAULT NULL",
                'parent_feedback' => "text",
                'created_at' => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            
            $repairs_made += $this->add_missing_columns($escrow_table, $escrow_columns);
        }
        
        // Add missing indexes
        $this->add_missing_indexes();
        
        // ============ ptp_applications - ALL columns ============
        $applications_table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '$applications_table'") !== $applications_table) {
            // Create the table if it doesn't exist at all
            $wpdb->query("CREATE TABLE IF NOT EXISTS $applications_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id bigint(20) UNSIGNED DEFAULT NULL,
                name varchar(100) NOT NULL DEFAULT '',
                email varchar(255) NOT NULL DEFAULT '',
                phone varchar(20) DEFAULT '',
                experience text,
                playing_level varchar(50) DEFAULT '',
                college varchar(255) DEFAULT '',
                location varchar(255) DEFAULT '',
                city varchar(100) DEFAULT '',
                state varchar(50) DEFAULT '',
                status varchar(20) DEFAULT 'pending',
                admin_notes text,
                reviewed_at datetime DEFAULT NULL,
                reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_app_email (email),
                KEY idx_app_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $app_columns = array(
                'user_id'        => "bigint(20) UNSIGNED DEFAULT NULL",
                'name'           => "varchar(100) NOT NULL DEFAULT ''",
                'email'          => "varchar(255) NOT NULL DEFAULT ''",
                'phone'          => "varchar(20) DEFAULT ''",
                'experience'     => "text",
                'playing_level'  => "varchar(50) DEFAULT ''",
                'college'        => "varchar(255) DEFAULT ''",
                'location'       => "varchar(255) DEFAULT ''",
                'city'           => "varchar(100) DEFAULT ''",
                'state'          => "varchar(50) DEFAULT ''",
                'status'         => "varchar(20) DEFAULT 'pending'",
                'admin_notes'    => "text",
                'reviewed_at'    => "datetime DEFAULT NULL",
                'reviewed_by'    => "bigint(20) UNSIGNED DEFAULT NULL",
                'created_at'     => "datetime DEFAULT CURRENT_TIMESTAMP",
            );
            $repairs_made += $this->add_missing_columns($applications_table, $app_columns);
        }
        
        // ============ ptp_reviews - ALL columns ============
        $reviews_table = $wpdb->prefix . 'ptp_reviews';
        if ($wpdb->get_var("SHOW TABLES LIKE '$reviews_table'") !== $reviews_table) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS $reviews_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                booking_id bigint(20) UNSIGNED DEFAULT NULL,
                user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                parent_id bigint(20) UNSIGNED DEFAULT NULL,
                player_id bigint(20) UNSIGNED DEFAULT NULL,
                rating tinyint(1) NOT NULL DEFAULT 5,
                review_text text,
                trainer_reply text,
                trainer_reply_at datetime DEFAULT NULL,
                is_published tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_review_trainer (trainer_id),
                KEY idx_review_booking (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $review_columns = array(
                'trainer_id'       => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'booking_id'       => "bigint(20) UNSIGNED DEFAULT NULL",
                'user_id'          => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'parent_id'        => "bigint(20) UNSIGNED DEFAULT NULL",
                'player_id'        => "bigint(20) UNSIGNED DEFAULT NULL",
                'rating'           => "tinyint(1) NOT NULL DEFAULT 5",
                'review_text'      => "text",
                'trainer_reply'    => "text",
                'trainer_reply_at' => "datetime DEFAULT NULL",
                'is_published'     => "tinyint(1) DEFAULT 1",
                'created_at'       => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at'       => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            $repairs_made += $this->add_missing_columns($reviews_table, $review_columns);
        }
        
        // ============ ptp_availability - ALL columns ============
        $avail_table = $wpdb->prefix . 'ptp_availability';
        if ($wpdb->get_var("SHOW TABLES LIKE '$avail_table'") !== $avail_table) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS $avail_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                day_of_week tinyint(1) NOT NULL DEFAULT 0,
                start_time time NOT NULL,
                end_time time NOT NULL,
                is_active tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_avail_trainer_day (trainer_id, day_of_week)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $avail_columns = array(
                'trainer_id'   => "bigint(20) UNSIGNED NOT NULL DEFAULT 0",
                'day_of_week'  => "tinyint(1) NOT NULL DEFAULT 0",
                'start_time'   => "time NOT NULL",
                'end_time'     => "time NOT NULL",
                'is_active'    => "tinyint(1) DEFAULT 1",
                'created_at'   => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at'   => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            $repairs_made += $this->add_missing_columns($avail_table, $avail_columns);
        }
        
        // ============ ptp_bundles - ALL columns ============
        $bundles_table = $wpdb->prefix . 'ptp_bundles';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bundles_table'") !== $bundles_table) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS $bundles_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id bigint(20) UNSIGNED DEFAULT NULL,
                session_id varchar(100) DEFAULT '',
                trainer_id bigint(20) UNSIGNED DEFAULT NULL,
                bundle_type varchar(30) DEFAULT 'training',
                item_data longtext,
                sessions int(11) DEFAULT 1,
                amount decimal(10,2) DEFAULT 0,
                status varchar(20) DEFAULT 'pending',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_bundle_user (user_id),
                KEY idx_bundle_session (session_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $bundle_columns = array(
                'user_id'     => "bigint(20) UNSIGNED DEFAULT NULL",
                'session_id'  => "varchar(100) DEFAULT ''",
                'trainer_id'  => "bigint(20) UNSIGNED DEFAULT NULL",
                'bundle_type' => "varchar(30) DEFAULT 'training'",
                'item_data'   => "longtext",
                'sessions'    => "int(11) DEFAULT 1",
                'amount'      => "decimal(10,2) DEFAULT 0",
                'status'      => "varchar(20) DEFAULT 'pending'",
                'created_at'  => "datetime DEFAULT CURRENT_TIMESTAMP",
                'updated_at'  => "datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            );
            $repairs_made += $this->add_missing_columns($bundles_table, $bundle_columns);
        }
        
        if ($repairs_made > 0) {
            ptp_log("PTP Auto-Repair: Made $repairs_made column repairs");
        }
        
        return $repairs_made;
    }
    
    /**
     * Helper: Add missing columns to a table
     */
    private function add_missing_columns($table, $columns) {
        global $wpdb;
        $added = 0;
        
        foreach ($columns as $column => $definition) {
            $exists = $wpdb->get_var("SHOW COLUMNS FROM $table LIKE '$column'");
            if (!$exists) {
                $result = $wpdb->query("ALTER TABLE $table ADD COLUMN $column $definition");
                if ($result !== false) {
                    $added++;
                    ptp_log("PTP Auto-Repair: Added $column to $table");
                }
            }
        }
        
        return $added;
    }
    
    /**
     * Add missing indexes for performance
     */
    private function add_missing_indexes() {
        global $wpdb;
        
        $indexes = array(
            'ptp_trainers' => array(
                'idx_trainer_status' => 'status',
                'idx_trainer_slug' => 'slug',
                'idx_trainer_user' => 'user_id',
                'idx_trainer_email' => 'email',
                'idx_trainer_featured' => 'is_featured DESC, sort_order ASC',
            ),
            'ptp_bookings' => array(
                'idx_booking_trainer_date' => 'trainer_id, session_date',
                'idx_booking_parent' => 'parent_id',
                'idx_booking_status' => 'status, session_date',
                'idx_booking_number' => 'booking_number',
            ),
            'ptp_conversations' => array(
                'idx_conv_trainer_parent' => 'trainer_id, parent_id',
            ),
            'ptp_messages' => array(
                'idx_msg_conversation' => 'conversation_id, created_at DESC',
            ),
        );
        
        foreach ($indexes as $table => $table_indexes) {
            $full_table = $wpdb->prefix . $table;
            
            if ($wpdb->get_var("SHOW TABLES LIKE '$full_table'") !== $full_table) {
                continue;
            }
            
            foreach ($table_indexes as $index_name => $columns) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.statistics 
                     WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
                    $full_table, $index_name
                ));
                
                if (!$exists) {
                    $wpdb->query("ALTER TABLE $full_table ADD INDEX $index_name ($columns)");
                }
            }
        }
    }
    
    // =========================================================================
    // 10.2: NONCE REFRESH SYSTEM
    // =========================================================================
    
    /**
     * Enqueue nonce refresh script on all PTP pages
     */
    public function enqueue_nonce_refresh() {
        if (!$this->is_ptp_page()) {
            return;
        }
        
        wp_enqueue_script(
            'ptp-nonce-refresh',
            PTP_PLUGIN_URL . 'assets/js/nonce-refresh.js',
            array('jquery'),
            PTP_VERSION,
            true
        );
        
        wp_localize_script('ptp-nonce-refresh', 'ptpNonceRefresh', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_nonce'),
            'refreshInterval' => 1800000, // 30 minutes in milliseconds
            'maxAge' => 43200000, // 12 hours - force refresh after this
        ));
    }
    
    /**
     * AJAX handler to refresh nonce
     */
    public function ajax_refresh_nonce() {
        wp_send_json_success(array(
            'nonce' => wp_create_nonce('ptp_nonce'),
            'timestamp' => time(),
        ));
    }
    
    /**
     * Check if current page is a PTP page
     */
    private function is_ptp_page() {
        if (is_admin()) {
            return isset($_GET['page']) && strpos($_GET['page'], 'ptp') === 0;
        }
        
        global $post;
        if ($post && is_object($post) && !empty($post->post_content)) {
            return strpos($post->post_content, '[ptp_') !== false;
        }
        
        // Check URL patterns
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $ptp_patterns = array(
            '/trainer-dashboard/',
            '/parent-dashboard/',
            '/training-checkout/',
            '/ptp-cart/',
            '/ptp-checkout/',
            '/messages/',
            '/trainer/',
            '/find-trainer/',
            '/apply/',
        );
        
        foreach ($ptp_patterns as $pattern) {
            if (strpos($uri, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    // =========================================================================
    // 10.3: STRIPE CONNECT ONBOARDING REMINDERS
    // =========================================================================
    
    /**
     * Send Stripe Connect reminders to trainers who haven't completed setup
     */
    public function send_stripe_connect_reminders() {
        global $wpdb;
        
        // Get trainers who are active but haven't completed Stripe onboarding
        $trainers = $wpdb->get_results("
            SELECT t.*, u.user_email 
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE t.status = 'active'
            AND (t.stripe_onboarding_complete = 0 OR t.stripe_onboarding_complete IS NULL)
            AND (t.stripe_account_id IS NULL OR t.stripe_account_id = '' OR t.stripe_payouts_enabled = 0)
            AND (t.stripe_reminder_sent_at IS NULL OR t.stripe_reminder_sent_at < DATE_SUB(NOW(), INTERVAL 3 DAY))
            AND t.stripe_reminder_count < 5
        ");
        
        if (empty($trainers)) {
            return;
        }
        
        foreach ($trainers as $trainer) {
            $this->send_stripe_reminder($trainer);
        }
    }
    
    /**
     * Send individual Stripe reminder
     */
    private function send_stripe_reminder($trainer) {
        global $wpdb;
        
        // Generate Stripe onboarding link
        $onboarding_link = home_url('/trainer-dashboard/?stripe_setup=1');
        
        $reminder_count = intval($trainer->stripe_reminder_count) + 1;
        
        // Send SMS if available
        if (!empty($trainer->phone) && class_exists('PTP_SMS_V71')) {
            $message = "Hi {$trainer->display_name}! Complete your Stripe setup to start receiving payouts for your training sessions. Setup takes 5 minutes: {$onboarding_link}";
            PTP_SMS_V71::send($trainer->phone, $message);
        }
        
        // Send email
        if (!empty($trainer->email) || !empty($trainer->user_email)) {
            $email = !empty($trainer->email) ? $trainer->email : $trainer->user_email;
            
            $subject = "Action Required: Complete Your Payout Setup";
            $body = "
Hi {$trainer->display_name},

You're all set up as a PTP trainer, but we noticed you haven't completed your payout setup yet.

Without Stripe connected, we can't send you payments for your training sessions.

Complete your setup now (takes about 5 minutes):
{$onboarding_link}

Once connected, you'll receive payouts automatically after each completed session.

Questions? Reply to this email and we'll help you out.

- The PTP Team
            ";
            
            wp_mail($email, $subject, $body);
        }
        
        // Update reminder tracking
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'stripe_reminder_sent_at' => current_time('mysql'),
                'stripe_reminder_count' => $reminder_count,
            ),
            array('id' => $trainer->id)
        );
        
        ptp_log("PTP: Sent Stripe reminder #{$reminder_count} to trainer {$trainer->id}");
    }
    
    /**
     * Admin can manually resend Stripe setup link
     */
    public static function admin_resend_stripe_link($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found');
        }
        
        $instance = self::instance();
        
        // Reset reminder count to allow fresh reminders
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('stripe_reminder_count' => 0),
            array('id' => $trainer_id)
        );
        
        $instance->send_stripe_reminder($trainer);
        
        return true;
    }
    
    /**
     * Get trainers with incomplete Stripe setup (for admin view)
     */
    public static function get_incomplete_stripe_trainers() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT t.*, 
                   COALESCE(t.stripe_reminder_count, 0) as reminder_count,
                   t.stripe_reminder_sent_at as last_reminder
            FROM {$wpdb->prefix}ptp_trainers t
            WHERE t.status = 'active'
            AND (t.stripe_onboarding_complete = 0 OR t.stripe_onboarding_complete IS NULL)
            AND (t.stripe_account_id IS NULL OR t.stripe_account_id = '' OR t.stripe_payouts_enabled = 0)
            ORDER BY t.created_at DESC
        ");
    }
    
    /**
     * Register admin settings for Stripe reminders
     */
    public function register_stripe_reminder_settings() {
        // Add AJAX handler for manual resend
        add_action('wp_ajax_ptp_resend_stripe_link', array($this, 'ajax_resend_stripe_link'));
    }
    
    /**
     * AJAX: Resend Stripe link
     */
    public function ajax_resend_stripe_link() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $trainer_id = intval($_POST['trainer_id']);
        $result = self::admin_resend_stripe_link($trainer_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success('Stripe setup link sent');
    }
    
    // =========================================================================
    // 10.4: TEMPLATE VERSION CONSOLIDATION
    // =========================================================================
    
    /**
     * Consolidate template versions - always use latest version
     * 
     * NOTE (v176): This filter is registered on 'ptp_template_file' but no code
     * calls apply_filters('ptp_template_file', ...) — shortcodes hardcode paths.
     * Kept for backwards compat if third-party code uses the filter.
     * Use PTP_Fixes_V72::get_template() for direct lookups.
     */
    /**
     * Single source of truth for template version mapping.
     * Update ONLY this array when changing template versions.
     */
    private static $template_versions = array(
        'trainer-profile'        => 'trainer-profile-v177.php',
        'trainer-dashboard'      => 'trainer-dashboard-v200.php',
        'parent-dashboard'       => 'parent-dashboard-v200.php',
        'find-trainer'           => 'trainers-grid.php',
        'cart'                   => 'ptp-cart.php',
        'messaging'              => 'messaging.php',
        'trainer-onboarding'     => 'trainer-onboarding-v133.php',
        'trainer-profile-editor' => 'trainer-profile-editor-v2.php',
        'thank-you'              => 'thank-you-v175.php',
    );

    public function consolidate_template_versions($file, $template_name) {
        $base_name = preg_replace('/-v\d+\.php$/', '', basename($file));
        $base_name = str_replace('.php', '', $base_name);
        
        if (isset(self::$template_versions[$base_name])) {
            $new_file = PTP_PLUGIN_DIR . 'templates/' . self::$template_versions[$base_name];
            if (file_exists($new_file)) {
                return $new_file;
            }
        }
        
        return $file;
    }
    
    /**
     * Get the canonical template file for a given template name
     */
    public static function get_template($template_name) {
        if (isset(self::$template_versions[$template_name])) {
            $file = PTP_PLUGIN_DIR . 'templates/' . self::$template_versions[$template_name];
            if (file_exists($file)) {
                return $file;
            }
        }
        
        $file = PTP_PLUGIN_DIR . 'templates/' . $template_name . '.php';
        if (file_exists($file)) {
            return $file;
        }
        
        return false;
    }
    
    // =========================================================================
    // 10.6: CACHE EXCLUSION HEADERS
    // =========================================================================
    
    /**
     * Add cache exclusion headers for dynamic PTP pages
     */
    public function add_cache_exclusion_headers() {
        if (!$this->should_exclude_from_cache()) {
            return;
        }
        
        // Prevent caching
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        
        // Signal to various cache plugins
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
    
    /**
     * Check if current page should be excluded from cache
     */
    private function should_exclude_from_cache() {
        // Always exclude if user is logged in and on a PTP page
        if (is_user_logged_in() && $this->is_ptp_page()) {
            return true;
        }
        
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Dynamic pages that should never be cached
        $exclude_patterns = array(
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
        );
        
        foreach ($exclude_patterns as $pattern) {
            if (strpos($uri, $pattern) !== false) {
                return true;
            }
        }
        
        // Exclude if cart has items
        if (false && ptp_cart() && !ptp_cart()->is_empty()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * WP Rocket: Exclude PTP pages from cache
     */
    public function wp_rocket_exclude_pages($uris) {
        $exclude = array(
            '/trainer-dashboard/(.*)',
            '/parent-dashboard/(.*)',
            '/training-checkout/(.*)',
            '/ptp-checkout/(.*)',
            '/checkout/(.*)',
            '/ptp-cart/(.*)',
            '/cart/(.*)',
            '/messages/(.*)',
            '/account/(.*)',
            '/login/(.*)',
            '/register/(.*)',
            '/logout/(.*)',
            '/apply/(.*)',
            '/booking-confirmation/(.*)',
            '/trainer/(.*)', // Dynamic trainer profiles
        );
        
        return array_merge($uris, $exclude);
    }
    
    /**
     * W3 Total Cache: Exclude PTP pages
     */
    public function w3tc_exclude_pages($uris) {
        return $this->wp_rocket_exclude_pages($uris);
    }
    
    /**
     * LiteSpeed Cache: Exclude PTP pages
     */
    public function litespeed_exclude_pages($excludes) {
        $ptp_excludes = array(
            'trainer-dashboard',
            'parent-dashboard',
            'training-checkout',
            'ptp-checkout',
            'checkout',
            'ptp-cart',
            'cart',
            'messages',
            'account',
            'login',
            'register',
            'logout',
            'apply',
            'booking-confirmation',
        );
        
        return array_merge($excludes, $ptp_excludes);
    }
}

// Initialize fixes
add_action('plugins_loaded', array('PTP_Fixes_V72', 'instance'), 5);
