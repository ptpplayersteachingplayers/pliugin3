<?php
/**
 * PTP Mentorship Database — Table creation & migrations
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Database {

    public static function init() {
        register_activation_hook(PTP_PLUGIN_FILE, array(__CLASS__, 'create_tables'));
        add_action('admin_init', array(__CLASS__, 'maybe_create_tables'));
    }

    // ================================================================
    // TABLE CREATION
    // ================================================================
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Mentorship pairs — session package model
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_mentorship_pairs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            player_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            package_type varchar(20) NOT NULL DEFAULT 'kickstart',
            status enum('interest','intro_scheduled','intro_done','active','paused','completed','cancelled') DEFAULT 'interest',
            source varchar(50) DEFAULT 'direct',
            source_detail varchar(255) DEFAULT '',
            camp_order_id bigint(20) UNSIGNED DEFAULT NULL,
            sessions_total int(11) DEFAULT 12,
            sessions_completed int(11) DEFAULT 0,
            session_length_minutes int(11) DEFAULT 30,
            per_session_price int(11) DEFAULT 4900,
            video_reviews_remaining int(11) DEFAULT 0,
            film_breakdowns_remaining int(11) DEFAULT 0,
            stripe_subscription_id varchar(255) DEFAULT '',
            stripe_customer_id varchar(255) DEFAULT '',
            stripe_payment_intent varchar(255) DEFAULT '',
            intro_call_at datetime DEFAULT NULL,
            intro_call_meeting_url varchar(500) DEFAULT '',
            parent_consent_at datetime DEFAULT NULL,
            intro_call_notes text DEFAULT NULL,
            parent_name varchar(255) DEFAULT '',
            parent_email varchar(255) DEFAULT '',
            player_name varchar(255) DEFAULT '',
            player_goals text DEFAULT NULL,
            player_position varchar(100) DEFAULT '',
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            cancel_reason text DEFAULT NULL,
            next_session_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id),
            KEY player_id (player_id),
            KEY status (status),
            KEY source (source),
            KEY camp_order_id (camp_order_id)
        ) $charset;");

        // Video submissions
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_mentorship_videos (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pair_id bigint(20) UNSIGNED NOT NULL,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED NOT NULL,
            video_url varchar(500) NOT NULL DEFAULT '',
            thumbnail_url varchar(500) DEFAULT '',
            player_note text DEFAULT NULL,
            coach_feedback text DEFAULT NULL,
            coach_video_url varchar(500) DEFAULT '',
            tags varchar(255) DEFAULT '',
            status enum('pending','reviewed','archived') DEFAULT 'pending',
            reviewed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pair_id (pair_id),
            KEY status (status),
            KEY trainer_id (trainer_id)
        ) $charset;");

        // Goals
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_mentorship_goals (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pair_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            target_date date DEFAULT NULL,
            status enum('active','completed','archived') DEFAULT 'active',
            completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pair_id (pair_id),
            KEY status (status)
        ) $charset;");

        // Milestones / badges
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_mentorship_milestones (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pair_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            badge_type varchar(50) DEFAULT 'custom',
            awarded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pair_id (pair_id)
        ) $charset;");

        // Sessions (individual calls)
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_mentorship_sessions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            session_type enum('intro','one_on_one','group','film_breakdown') DEFAULT 'one_on_one',
            title varchar(255) DEFAULT '',
            description text DEFAULT NULL,
            scheduled_at datetime NOT NULL,
            duration_minutes int(11) DEFAULT 30,
            meeting_url varchar(500) DEFAULT '',
            max_participants int(11) DEFAULT 1,
            status enum('scheduled','completed','cancelled','no_show') DEFAULT 'scheduled',
            completed_at datetime DEFAULT NULL,
            trainer_notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY scheduled_at (scheduled_at),
            KEY status (status)
        ) $charset;");

        // Session attendees
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_mentorship_session_attendees (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id bigint(20) UNSIGNED NOT NULL,
            pair_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED NOT NULL,
            attended tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY pair_id (pair_id)
        ) $charset;");

        // Challenges
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_mentorship_challenges (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            video_required tinyint(1) DEFAULT 0,
            due_date date DEFAULT NULL,
            status enum('active','completed','archived') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY status (status)
        ) $charset;");

        // Training plans
        dbDelta("CREATE TABLE {$wpdb->prefix}ptp_mentorship_plans (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pair_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) DEFAULT 'Training Plan',
            content longtext,
            status enum('active','archived') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pair_id (pair_id)
        ) $charset;");

        update_option('ptp_mentorship_db_version', PTP_Mentorship::VERSION);

        if (!wp_next_scheduled('ptp_mentorship_check_expiring')) {
            wp_schedule_event(time() + 3600, 'daily', 'ptp_mentorship_check_expiring');
        }
    }

    // ================================================================
    // MIGRATIONS — adds columns to existing tables
    // ================================================================
    public static function maybe_create_tables() {
        if (get_option('ptp_mentorship_db_version') !== PTP_Mentorship::VERSION) {
            self::create_tables();
        }

        global $wpdb;

        // ── Trainer columns ──
        $trainer_table = $wpdb->prefix . 'ptp_trainers';
        $trainer_cols  = $wpdb->get_col("SHOW COLUMNS FROM {$trainer_table}");
        if (!in_array('mentorship_enabled', $trainer_cols)) {
            $wpdb->query("ALTER TABLE {$trainer_table} ADD COLUMN mentorship_enabled tinyint(1) DEFAULT 0 AFTER intro_video_url");
            $wpdb->query("ALTER TABLE {$trainer_table} ADD COLUMN mentorship_packages varchar(100) DEFAULT 'single,kickstart,development,elite' AFTER mentorship_enabled");
            $wpdb->query("ALTER TABLE {$trainer_table} ADD COLUMN mentorship_bio text DEFAULT NULL AFTER mentorship_packages");
            $wpdb->query("ALTER TABLE {$trainer_table} ADD COLUMN mentorship_max_mentees int(11) DEFAULT 20 AFTER mentorship_bio");
        }
        if (!in_array('zoom_email', $trainer_cols)) {
            $wpdb->query("ALTER TABLE {$trainer_table} ADD COLUMN zoom_email varchar(255) DEFAULT '' AFTER mentorship_max_mentees");
        }

        // ── Pairs: stripe_payment_intent for single sessions ──
        $pairs_table = $wpdb->prefix . 'ptp_mentorship_pairs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$pairs_table}'")) {
            $pair_cols = $wpdb->get_col("SHOW COLUMNS FROM {$pairs_table}");
            if (!in_array('stripe_payment_intent', $pair_cols)) {
                $wpdb->query("ALTER TABLE {$pairs_table} ADD COLUMN stripe_payment_intent varchar(255) DEFAULT '' AFTER stripe_customer_id");
            }
        }

        // ── Goals: goal_type ──
        $goals_table = $wpdb->prefix . 'ptp_mentorship_goals';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$goals_table}'")) {
            $goal_cols = $wpdb->get_col("SHOW COLUMNS FROM {$goals_table}");
            if (!in_array('goal_type', $goal_cols)) {
                $wpdb->query("ALTER TABLE {$goals_table} ADD COLUMN goal_type ENUM('game','mental','identity','life') DEFAULT 'game' AFTER title");
            }
        }

        // ── Sessions: Zoom + post-session fields ──
        $sess_table = $wpdb->prefix . 'ptp_mentorship_sessions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$sess_table}'")) {
            $sess_cols = $wpdb->get_col("SHOW COLUMNS FROM {$sess_table}");
            if (!in_array('zoom_meeting_id', $sess_cols)) {
                $wpdb->query("ALTER TABLE {$sess_table} ADD COLUMN zoom_meeting_id bigint(20) DEFAULT NULL AFTER meeting_url");
            }
            if (!in_array('host_url', $sess_cols)) {
                $wpdb->query("ALTER TABLE {$sess_table} ADD COLUMN host_url varchar(1000) DEFAULT '' AFTER zoom_meeting_id");
            }
            if (!in_array('zoom_password', $sess_cols)) {
                $wpdb->query("ALTER TABLE {$sess_table} ADD COLUMN zoom_password varchar(50) DEFAULT '' AFTER host_url");
            }
            if (!in_array('pre_session_note', $sess_cols)) {
                $wpdb->query("ALTER TABLE {$sess_table} ADD COLUMN pre_session_note text DEFAULT NULL AFTER trainer_notes");
            }
            if (!in_array('action_item', $sess_cols)) {
                $wpdb->query("ALTER TABLE {$sess_table} ADD COLUMN action_item varchar(500) DEFAULT NULL AFTER pre_session_note");
            }
            if (!in_array('parent_summary', $sess_cols)) {
                $wpdb->query("ALTER TABLE {$sess_table} ADD COLUMN parent_summary text DEFAULT NULL AFTER action_item");
            }
            if (!in_array('session_number', $sess_cols)) {
                $wpdb->query("ALTER TABLE {$sess_table} ADD COLUMN session_number int(11) DEFAULT 0 AFTER parent_summary");
            }
            if (!in_array('energy_rating', $sess_cols)) {
                $wpdb->query("ALTER TABLE {$sess_table} ADD COLUMN energy_rating tinyint(1) DEFAULT NULL AFTER session_number");
            }
        }
    }
}
