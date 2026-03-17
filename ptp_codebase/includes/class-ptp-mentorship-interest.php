<?php
/**
 * PTP Mentorship Interest — Interest form submission (Step 1)
 * 
 * No login required — camp families can submit interest directly.
 * Handles deduplication, capacity checks, player creation, and SMS alerts.
 */

if (!defined('ABSPATH')) exit;

class PTP_Mentorship_Interest {

    public static function init() {
        add_action('wp_ajax_ptp_mentorship_interest', array(__CLASS__, 'ajax_submit_interest'));
        add_action('wp_ajax_nopriv_ptp_mentorship_interest', array(__CLASS__, 'ajax_submit_interest'));
    }

    // ================================================================
    // INTEREST FORM (no login needed)
    // ================================================================
    public static function ajax_submit_interest() {
        $trainer_id      = intval($_POST['trainer_id'] ?? 0);
        $parent_name     = sanitize_text_field($_POST['parent_name'] ?? '');
        $parent_email    = sanitize_email($_POST['parent_email'] ?? '');
        $player_name     = sanitize_text_field($_POST['player_name'] ?? '');
        $player_age      = intval($_POST['player_age'] ?? 0);
        $player_position = sanitize_text_field($_POST['player_position'] ?? '');
        $goals           = sanitize_textarea_field($_POST['goals'] ?? '');
        $source          = sanitize_text_field($_POST['source'] ?? 'direct');
        $source_detail   = sanitize_text_field($_POST['source_detail'] ?? '');
        $camp_order_id   = intval($_POST['camp_order_id'] ?? 0);

        // Honeypot
        if (!empty($_POST['website_url'])) wp_send_json_error('Invalid submission');

        if (!$trainer_id || !$parent_email || !$player_name) {
            wp_send_json_error('Please fill out all required fields.');
        }
        
        // v235.6: Server-side age guardrail
        if ($player_age < 6 || $player_age > 18) {
            wp_send_json_error('Mentorship is available for players ages 6-18.');
        }
        
        // v235.6: Require parent consent
        if (empty($_POST['parent_consent'])) {
            wp_send_json_error('Parent consent is required to proceed.');
        }

        global $wpdb;
        // v228: Fixed status = 'approved' → 'active' (trainers use 'active' everywhere)
        // This was silently rejecting EVERY interest form submission
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, slug, mentorship_enabled FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'", $trainer_id
        ));
        if (!$trainer || empty($trainer->mentorship_enabled)) {
            wp_send_json_error('This coach is not currently available for mentorship.');
        }

        // Capacity check
        $active_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE trainer_id = %d AND status IN ('active','interest','intro_scheduled','intro_done')",
            $trainer->id
        ));
        $max = intval($trainer->mentorship_max_mentees ?? 20);
        if ($active_count >= $max) {
            wp_send_json_error("{$trainer->display_name}'s mentorship is currently full. We'll notify you when a spot opens.");
        }

        // Dedupe
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_mentorship_pairs
             WHERE trainer_id = %d AND parent_email = %s AND status IN ('interest','intro_scheduled','intro_done')",
            $trainer->id, $parent_email
        ));
        if ($existing) {
            wp_send_json_success(array(
                'pair_id' => $existing,
                'message' => "You've already expressed interest! {$trainer->display_name} will be in touch soon.",
                'duplicate' => true,
            ));
            return;
        }

        // Find parent user if they have an account
        $user = get_user_by('email', $parent_email);
        $parent_id = $user ? $user->ID : 0;

        // Find or create player
        $player_id = self::find_or_create_player($parent_id, $player_name, $player_age, $player_position, $parent_email);

        // Package selection
        $selected_package = sanitize_text_field($_POST['package'] ?? 'development');
        if (!isset(PTP_Mentorship::PACKAGES[$selected_package])) $selected_package = 'development';
        $pkg = PTP_Mentorship::PACKAGES[$selected_package];

        // Auto-generate intro call room
        $intro_url = PTP_Mentorship_Sessions::generate_meeting_url($trainer, 0, 'intro');

        // Ensure tables exist
        $pairs_table = $wpdb->prefix . 'ptp_mentorship_pairs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$pairs_table}'") !== $pairs_table) {
            PTP_Mentorship_Database::create_tables();
        }

        $wpdb->insert("{$wpdb->prefix}ptp_mentorship_pairs", array(
            'trainer_id'             => $trainer->id,
            'parent_id'              => $parent_id,
            'player_id'              => $player_id,
            'package_type'           => $selected_package,
            'status'                 => 'interest',
            'source'                 => $source,
            'source_detail'          => $source_detail,
            'camp_order_id'          => $camp_order_id ?: null,
            'sessions_total'         => $pkg['sessions'],
            'session_length_minutes' => $pkg['session_length'],
            'per_session_price'      => $pkg['per_session'],
            'parent_name'            => $parent_name,
            'parent_email'           => $parent_email,
            'player_name'            => $player_name,
            'player_goals'           => $goals,
            'player_position'        => $player_position,
            'intro_call_meeting_url' => $intro_url,
            'parent_consent_at'      => current_time('mysql'),
        ));
        $pair_id = $wpdb->insert_id;

        if (!$pair_id) {
            ptp_log('[PTP Mentorship] Failed to insert pair: ' . $wpdb->last_error);
            wp_send_json_error('Something went wrong. Please try again or email luke@ptpsummercamps.com.');
        }

        // Notify trainer
        do_action('ptp_mentorship_interest_submitted', $pair_id, $trainer->id, array(
            'parent_name'  => $parent_name,
            'parent_email' => $parent_email,
            'player_name'  => $player_name,
            'player_age'   => $player_age,
            'goals'        => $goals,
            'source'       => $source,
            'camp_order_id'=> $camp_order_id,
        ));

        // SMS alert to Luke
        if (class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send')) {
            $sms_msg = "New mentorship interest: {$player_name} ({$parent_name}) wants to work with {$trainer->display_name}. Package: " . ucfirst($selected_package) . ". Source: {$source}.";
            PTP_SMS::send(get_option('ptp_contact_phone', '+16106714778'), $sms_msg);
        }

        wp_send_json_success(array(
            'pair_id'      => $pair_id,
            'trainer_name' => $trainer->display_name,
            'message'      => "{$trainer->display_name} will reach out to schedule a free intro call. No commitment until you're ready.",
        ));
    }

    // ================================================================
    // PLAYER FIND/CREATE
    // v233 M2: Handle unauthenticated submissions — search by email→user→parent_id
    // ================================================================
    private static function find_or_create_player($parent_id, $player_name, $player_age, $player_position, $parent_email = '') {
        global $wpdb;

        // v233 M2: If no parent_id but we have email, try to resolve it
        if (!$parent_id && $parent_email) {
            $user = get_user_by('email', $parent_email);
            if ($user) $parent_id = $user->ID;
        }

        $player_id = 0;

        // Search by parent_id + name
        if ($parent_id) {
            $player_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND first_name = %s LIMIT 1",
                $parent_id, $player_name
            ));
        }

        // v233 M2: Fallback — search by name among orphaned players (parent_id = 0)
        if (!$player_id) {
            $player_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_players WHERE parent_id = 0 AND first_name = %s LIMIT 1",
                $player_name
            ));
            // Link the orphaned player to this parent if we have one
            if ($player_id && $parent_id) {
                $wpdb->update("{$wpdb->prefix}ptp_players", array('parent_id' => $parent_id), array('id' => $player_id));
            }
        }

        if (!$player_id) {
            // Ensure ptp_players table exists
            $players_table = $wpdb->prefix . 'ptp_players';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$players_table}'") !== $players_table) {
                if (class_exists('PTP_Database') && method_exists('PTP_Database', 'create_tables')) {
                    PTP_Database::create_tables();
                } else {
                    $charset = $wpdb->get_charset_collate();
                    $wpdb->query("CREATE TABLE IF NOT EXISTS {$players_table} (
                        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                        parent_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
                        name varchar(100) NOT NULL DEFAULT '',
                        first_name varchar(100) DEFAULT '',
                        last_name varchar(100) DEFAULT '',
                        age int(11) DEFAULT NULL,
                        position varchar(100) DEFAULT '',
                        PRIMARY KEY (id),
                        KEY parent_id (parent_id)
                    ) {$charset}");
                }
            }

            $wpdb->insert("{$wpdb->prefix}ptp_players", array(
                'parent_id'  => $parent_id,
                'name'       => $player_name,
                'first_name' => $player_name,
                'age'        => $player_age ?: null,
                'position'   => $player_position,
            ));
            $player_id = $wpdb->insert_id;
        }

        return $player_id;
    }
}
