<?php
/**
 * PTP AJAX Handlers – v176.1
 *
 * Handles: application submission, onboarding save, login, register, admin review.
 */
defined('ABSPATH') || exit;

class PTP_Ajax {

    public static function init() {
        add_action('wp_ajax_ptp_submit_application',        array(__CLASS__, 'submit_application'));
        add_action('wp_ajax_nopriv_ptp_submit_application', array(__CLASS__, 'submit_application'));
        add_action('wp_ajax_ptp_refresh_nonce',             array(__CLASS__, 'refresh_nonce'));
        add_action('wp_ajax_nopriv_ptp_refresh_nonce',      array(__CLASS__, 'refresh_nonce'));
        add_action('wp_ajax_ptp_save_onboarding',           array(__CLASS__, 'save_onboarding'));
        add_action('wp_ajax_nopriv_ptp_login',              array(__CLASS__, 'login'));
        add_action('wp_ajax_ptp_login',                     array(__CLASS__, 'login'));
        add_action('wp_ajax_nopriv_ptp_register',           array(__CLASS__, 'register'));
        add_action('wp_ajax_ptp_register',                  array(__CLASS__, 'register'));
        add_action('wp_ajax_nopriv_ptp_forgot_password',    array(__CLASS__, 'forgot_password'));
        add_action('wp_ajax_nopriv_ptp_reset_password',     array(__CLASS__, 'reset_password_ajax'));
        add_action('wp_ajax_ptp_admin_review_application',  array(__CLASS__, 'admin_review_application'));
    }

    /* ---- NONCE REFRESH (for cached pages) ---- */
    public static function refresh_nonce() {
        wp_send_json_success(array('nonce' => wp_create_nonce('ptp_nonce')));
    }

    /* ---- TRAINER APPLICATION (/apply) ---- */
    public static function submit_application() {
        // v220: Don't die on nonce failure — return JSON so the form can show a real error
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page and try again.'));
        }
        global $wpdb;

        $name      = sanitize_text_field($_POST['name'] ?? '');
        $email     = sanitize_email($_POST['email'] ?? '');
        $phone     = sanitize_text_field($_POST['phone'] ?? '');
        $city      = sanitize_text_field($_POST['city'] ?? '');
        $state     = sanitize_text_field($_POST['state'] ?? '');
        $level     = sanitize_text_field($_POST['playing_level'] ?? '');
        $college   = sanitize_text_field($_POST['college'] ?? '');
        $team      = sanitize_text_field($_POST['team'] ?? '');
        $exp       = intval($_POST['experience_years'] ?? 0);
        $bio       = sanitize_textarea_field($_POST['bio'] ?? $_POST['experience'] ?? '');
        $why       = sanitize_textarea_field($_POST['why_train'] ?? '');
        $ig        = sanitize_text_field($_POST['instagram'] ?? '');
        $referral  = sanitize_text_field($_POST['referral_source'] ?? '');
        $password  = $_POST['password'] ?? '';

        if (empty($name) || empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => 'Name and a valid email are required.'));
        }

        // Ensure table exists and has all required columns
        $table = $wpdb->prefix . 'ptp_applications';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            $c = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$table} (
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
        } else {
            // Table exists — add any missing columns
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
            $needed = array(
                'city'             => "varchar(100) DEFAULT ''",
                'state'            => "varchar(50) DEFAULT ''",
                'playing_level'    => "varchar(50) DEFAULT ''",
                'college'          => "varchar(255) DEFAULT ''",
                'team'             => "varchar(255) DEFAULT ''",
                'experience_years' => "int(11) DEFAULT 0",
                'bio'              => "text",
                'coaching_why'     => "text",
                'training_philosophy' => "text",
                'training_policy'  => "text",
                'why_train'        => "text",
                'resume_url'       => "varchar(500) DEFAULT ''",
                'instagram'        => "varchar(100) DEFAULT ''",
                'referral_source'  => "varchar(100) DEFAULT ''",
                'admin_notes'      => "text",
                'reviewed_by'      => "bigint(20) UNSIGNED DEFAULT NULL",
                'reviewed_at'      => "datetime DEFAULT NULL",
                // v187.1: columns read by approve_application
                'location'         => "varchar(255) DEFAULT ''",
                'headline'         => "varchar(255) DEFAULT ''",
                'specialties'      => "text",
                'hourly_rate'      => "decimal(10,2) DEFAULT 0",
                'travel_radius'    => "int(11) DEFAULT 15",
                'password_hash'    => "varchar(255) DEFAULT ''",
            );
            foreach ($needed as $col => $def) {
                if (!in_array($col, $cols)) {
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
                }
            }
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE email = %s AND status IN ('pending','approved')", $email
        ));
        if ($exists) {
            wp_send_json_error(array('message' => 'An application with this email already exists.'));
        }

        // Get or create user
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        if (!$user_id) {
            $u = get_user_by('email', $email);
            if ($u) {
                $user_id = $u->ID;
            } elseif (!empty($password) && strlen($password) >= 8) {
                $parts = explode(' ', $name, 2);
                $username = sanitize_user(strtolower(str_replace(' ', '', $name)));
                if (username_exists($username)) $username = $username . wp_rand(10, 999);
                // v216.1: Suppress WP "set your password" notification — trainer chose their password
                add_filter('wp_send_new_user_notification_to_user', '__return_false');
                add_filter('wp_send_new_user_notification_to_admin', '__return_false');
                $new_user_id = wp_create_user($username, $password, $email);
                remove_filter('wp_send_new_user_notification_to_user', '__return_false');
                remove_filter('wp_send_new_user_notification_to_admin', '__return_false');
                if (!is_wp_error($new_user_id)) {
                    $user_id = $new_user_id;
                    wp_update_user(array(
                        'ID' => $user_id,
                        'first_name' => $parts[0] ?? '',
                        'last_name' => $parts[1] ?? '',
                        'display_name' => $name,
                        'role' => 'subscriber',
                    ));
                }
            }
        }

        // v220: Build location from city + state for admin display and trainer profile
        $location = '';
        if ($city && $state) {
            $location = $city . ', ' . $state;
        } elseif ($city) {
            $location = $city;
        } elseif ($state) {
            $location = $state;
        }

        // v221: Store password hash so we can restore it if user account needs to be recreated during approval
        $password_hash = '';
        if (!empty($password)) {
            $password_hash = wp_hash_password($password);
        } elseif ($user_id) {
            // User already exists — grab their current hash for safekeeping
            $existing_user = get_user_by('ID', $user_id);
            if ($existing_user) {
                $password_hash = $existing_user->user_pass;
            }
        }

        $result = $wpdb->insert($table, array(
            'user_id' => $user_id, 'name' => $name, 'email' => $email, 'phone' => $phone,
            'city' => $city, 'state' => $state, 'location' => $location, 'playing_level' => $level,
            'college' => $college, 'team' => $team, 'experience_years' => $exp,
            'bio' => $bio, 'why_train' => $why, 'instagram' => $ig,
            'referral_source' => $referral, 'status' => 'pending', 'created_at' => current_time('mysql'),
            'password_hash' => $password_hash,
        ));

        if (!$result || !$wpdb->insert_id) {
            $db_err = $wpdb->last_error;
            ptp_log("PTP Apply Error: {$db_err} | Name: {$name} | Email: {$email}");
            wp_send_json_error(array('message' => 'Database error — please contact support. ' . ($db_err ? '(' . $db_err . ')' : '')));
        }

        $app_id = $wpdb->insert_id;

        // Admin notification (plain text is fine for admin)
        $admin_body = "New Trainer Application\n"
            . "========================\n\n"
            . "Name: {$name}\n"
            . "Email: {$email}\n"
            . "Phone: {$phone}\n"
            . "Location: {$city}, {$state}\n"
            . "Playing Level: {$level}\n"
            . "College: {$college}\n\n"
            . "Experience / Bio:\n{$bio}\n\n"
            . "Review: " . admin_url("admin.php?page=ptp-applications&app={$app_id}");
        wp_mail(get_option('admin_email'), "New PTP Trainer Application: {$name}", $admin_body);

        // Applicant confirmation — use HTML template if available, fallback to plain text
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_application_received')) {
            PTP_Email::send_application_received($email, $name);
        } else {
            $first = explode(' ', $name, 2)[0];
            wp_mail($email, "We received your PTP application!", 
                "Hi {$first},\n\nThanks for applying to become a PTP trainer! We'll review your application within 24-48 hours.\n\n— The PTP Soccer Team");
        }

        // Auto-login if we created or found a user
        if ($user_id && !is_user_logged_in()) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
        }

        wp_send_json_success(array(
            'message'  => "Application submitted! We'll review it within 24-48 hours.",
            'app_id'   => $app_id,
            'redirect' => home_url('/trainer-pending/'),
        ));
    }

    /* ---- ADMIN: APPROVE / REJECT ---- */
    public static function admin_review_application() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;

        $app_id = intval($_POST['application_id'] ?? 0);
        $action = sanitize_text_field($_POST['review_action'] ?? '');
        if (!$app_id || !in_array($action, array('approve','reject'))) wp_send_json_error('Invalid request.');

        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_applications WHERE id = %d", $app_id
        ));
        if (!$app) wp_send_json_error('Application not found.');

        if ($action === 'approve') {
            $user_id = $app->user_id;
            $generated_password = ''; // Track if we had to generate a new password
            
            if (!$user_id || !get_user_by('ID', $user_id)) {
                $ue = get_user_by('email', $app->email);
                if ($ue) { $user_id = $ue->ID; }
                else {
                    $un = sanitize_user(strtolower(str_replace(' ', '', $app->name)), true);
                    if (username_exists($un)) $un .= wp_rand(100,999);
                    
                    // v216.1: Suppress WP default new-user notification emails
                    // The trainer chose their password at application time — no "set your password" emails
                    add_filter('wp_send_new_user_notification_to_user', '__return_false');
                    add_filter('wp_send_new_user_notification_to_admin', '__return_false');
                    
                    // v216.1: If we have their original password hash, use a throwaway password
                    // for wp_create_user then immediately restore the real hash.
                    // If no hash stored, generate a temp (edge case: applied without password).
                    $placeholder_pw = wp_generate_password(24, true, true);
                    $user_id = wp_create_user($un, $placeholder_pw, $app->email);
                    if (is_wp_error($user_id)) wp_send_json_error($user_id->get_error_message());
                    
                    // Remove the notification suppression filters
                    remove_filter('wp_send_new_user_notification_to_user', '__return_false');
                    remove_filter('wp_send_new_user_notification_to_admin', '__return_false');
                    
                    // v216.1: Restore the applicant's original password — this IS their active password
                    if (!empty($app->password_hash)) {
                        $wpdb->update($wpdb->users, array('user_pass' => $app->password_hash), array('ID' => $user_id));
                        wp_cache_delete($user_id, 'users');
                        clean_user_cache($user_id);
                        // Password was set by the trainer — no temp password needed
                        $generated_password = '';
                    } else {
                        // Edge case: applied without setting a password (logged-in user or legacy)
                        $generated_password = $placeholder_pw;
                    }
                    
                    $name_parts = explode(' ', $app->name, 2);
                    wp_update_user(array(
                        'ID' => $user_id,
                        'display_name' => $app->name,
                        'first_name' => $name_parts[0] ?? '',
                        'last_name' => $name_parts[1] ?? '',
                    ));
                }
            }

            $user = new WP_User($user_id);
            $user->add_role('ptp_trainer');

            $slug = sanitize_title($app->name);
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s", $slug))) {
                $slug .= '-' . wp_rand(10,99);
            }

            // Check if trainer record already exists for this user
            $existing_trainer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", $user_id
            ));

            if ($existing_trainer_id) {
                $trainer_id = $existing_trainer_id;
                $wpdb->update($wpdb->prefix . 'ptp_trainers', array(
                    'status' => 'active',
                ), array('id' => $trainer_id));
            } else {
                $wpdb->insert($wpdb->prefix . 'ptp_trainers', array(
                    'user_id' => $user_id, 'display_name' => $app->name, 'slug' => $slug,
                    'email' => $app->email, 'phone' => $app->phone ?? '',
                    'city' => $app->city ?? '', 'state' => $app->state ?? '',
                    'playing_level' => $app->playing_level ?? '',
                    'college' => $app->college ?? '', 'team' => $app->team ?? '',
                    'experience_years' => $app->experience_years ?? 0, 'bio' => $app->bio ?? '',
                    'instagram' => $app->instagram ?? '',
                    'status' => 'active', 'created_at' => current_time('mysql'),
                ));
                $trainer_id = $wpdb->insert_id;
            }

            // Update application status
            $wpdb->update($wpdb->prefix . 'ptp_applications', array(
                'status' => 'approved', 'user_id' => $user_id,
                'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql'),
            ), array('id' => $app_id));

            // v221: Send the full approval email with login credentials
            // Use send_application_approved which includes login details and onboarding steps
            if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_application_approved')) {
                // If trainer set their own password during application, don't include it in email
                // (tell them to use the password they created). If we generated one, include it.
                PTP_Email::send_application_approved($app->email, $app->name, $generated_password ?: null);
            }

            // Fire the approval hook — triggers:
            // - PTP_Onboarding_Reminders::on_trainer_approved (reminder schedule)
            // - PTP_SMS::send_trainer_welcome (SMS welcome)
            // - PTP_Trainer_Referrals::link_referral_on_approval
            // - PTP_Social::auto_post_new_trainer
            // NOTE: We removed PTP_Email::send_trainer_approval_email from this hook
            //       because send_application_approved above already handles the email
            if ($trainer_id) {
                do_action('ptp_trainer_approved', $trainer_id);
            }

            wp_send_json_success(array('message' => "Approved! Trainer record created for {$app->name}."));

        } else {
            $wpdb->update($wpdb->prefix . 'ptp_applications', array(
                'status' => 'rejected', 'reviewed_by' => get_current_user_id(),
                'reviewed_at' => current_time('mysql'),
                'admin_notes' => sanitize_textarea_field($_POST['admin_notes'] ?? ''),
            ), array('id' => $app_id));

            // Use HTML template if available
            if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_application_rejected')) {
                PTP_Email::send_application_rejected($app->email, $app->name);
            } else {
                wp_mail($app->email, 'PTP Application Update',
                    "Hi {$app->name},\n\nThank you for your interest. After review, we're unable to move forward at this time.\n\n— The PTP Team");
            }

            wp_send_json_success(array('message' => 'Application rejected.'));
        }
    }

    /* ---- SAVE ONBOARDING ---- */
    public static function save_onboarding() {
        // v221: Graceful nonce handling
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page and try again.'));
        }
        if (!is_user_logged_in()) wp_send_json_error('Not logged in.');
        global $wpdb;

        $user_id = get_current_user_id();
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", $user_id
        ));
        if (!$trainer) wp_send_json_error('No trainer record found.');

        // v193: Server-side validation for required fields
        $bio = sanitize_textarea_field($_POST['bio'] ?? '');
        $playing_level = sanitize_text_field($_POST['playing_level'] ?? '');
        $hourly_rate = floatval($_POST['hourly_rate'] ?? 0);
        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? '');
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        
        if (empty($first_name) || empty($last_name)) {
            wp_send_json_error(array('message' => 'First and last name are required.'));
        }
        if (strlen($bio) < 50) {
            wp_send_json_error(array('message' => 'Bio must be at least 50 characters.'));
        }
        if (empty($playing_level)) {
            wp_send_json_error(array('message' => 'Select your highest level played.'));
        }
        if ($hourly_rate < 25 || $hourly_rate > 300) {
            wp_send_json_error(array('message' => 'Set an hourly rate between $25 and $300.'));
        }
        if (empty($city) || empty($state)) {
            wp_send_json_error(array('message' => 'City and state are required.'));
        }
        $locations_json = !empty($_POST['training_locations_json']) ? json_decode(stripslashes($_POST['training_locations_json']), true) : null;
        if (empty($locations_json) && empty($trainer->training_locations)) {
            wp_send_json_error(array('message' => 'Add at least 1 training location.'));
        }
        $avail_json = !empty($_POST['availability_json']) ? json_decode(stripslashes($_POST['availability_json']), true) : null;
        $existing_avail = class_exists('PTP_Availability') ? PTP_Availability::get_weekly($trainer->id) : array();
        if (empty($avail_json) && empty($existing_avail)) {
            wp_send_json_error(array('message' => 'Enable at least 1 day of availability.'));
        }

        $allowed = array(
            'first_name','last_name','display_name','headline','bio','phone','hourly_rate','city','state','location',
            'playing_level','college','team','position','specialties','experience_years','years_coaching',
            'instagram','lesson_lengths','max_participants','travel_radius',
            'coaching_why','training_philosophy',
            'mentorship_enabled','mentorship_packages','mentorship_bio','mentorship_max_mentees',
        );

        $data = array(); $formats = array();
        // v134: Textarea fields need sanitize_textarea_field to preserve line breaks
        $textarea_fields = array('bio', 'coaching_why', 'training_philosophy', 'mentorship_bio');
        foreach ($allowed as $f) {
            if (!isset($_POST[$f])) continue;
            if (in_array($f, array('hourly_rate'))) { $data[$f] = floatval($_POST[$f]); $formats[] = '%f'; }
            elseif (in_array($f, array('experience_years','max_participants','travel_radius','years_coaching','mentorship_enabled','mentorship_max_mentees'))) { $data[$f] = intval($_POST[$f]); $formats[] = '%d'; }
            elseif (in_array($f, $textarea_fields)) { $data[$f] = sanitize_textarea_field($_POST[$f]); $formats[] = '%s'; }
            else { $data[$f] = sanitize_text_field($_POST[$f]); $formats[] = '%s'; }
        }

        if (!empty($data['display_name']) && $data['display_name'] !== $trainer->display_name) {
            $slug = sanitize_title($data['display_name']);
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s AND id != %d", $slug, $trainer->id))) {
                $slug .= '-' . $trainer->id;
            }
            $data['slug'] = $slug; $formats[] = '%s';
        }

        // v216.3: Mentorship fields — handle tiers array and enabled toggle
        // mentorship_packages comes as checkbox array, override the broken sanitize_text_field result
        if (isset($_POST['mentorship_packages']) && is_array($_POST['mentorship_packages'])) {
            $valid_pkgs = array('single', 'kickstart', 'development', 'elite');
            $selected = array_intersect(array_map('sanitize_text_field', $_POST['mentorship_packages']), $valid_pkgs);
            $data['mentorship_packages'] = !empty($selected) ? implode(',', $selected) : 'single,kickstart,development,elite';
        }
        // If mentorship_enabled checkbox not in POST, set to 0 (unchecked)
        if (!isset($_POST['mentorship_enabled']) && isset($_POST['bio'])) {
            $data['mentorship_enabled'] = 0;
            if (!in_array('%d', $formats)) $formats[] = '%d';
        }

        // v211: Auto-compose location column from city + state
        // This is the trainer's general area shown on cards/search results
        $city_val  = $data['city']  ?? $trainer->city  ?? '';
        $state_val = $data['state'] ?? $trainer->state ?? '';
        if ($city_val && $state_val && !isset($data['location'])) {
            $data['location'] = $city_val . ', ' . $state_val;
            $formats[] = '%s';
        }

        // v200.4: Save training locations as structured JSON objects [{name, address, lat, lng, place_id}]
        if (!empty($_POST['training_locations_json'])) {
            $locations = json_decode(stripslashes($_POST['training_locations_json']), true);
            if (is_array($locations)) {
                $sanitized = array();
                foreach ($locations as $loc) {
                    if (is_string($loc)) {
                        // Legacy: plain string — wrap in object
                        $sanitized[] = array(
                            'name'     => sanitize_text_field($loc),
                            'address'  => sanitize_text_field($loc),
                            'lat'      => null,
                            'lng'      => null,
                            'place_id' => '',
                        );
                    } elseif (is_array($loc) && !empty($loc['name'])) {
                        $sanitized[] = array(
                            'name'     => sanitize_text_field($loc['name'] ?? ''),
                            'address'  => sanitize_text_field($loc['address'] ?? ($loc['name'] ?? '')),
                            'lat'      => isset($loc['lat']) && is_numeric($loc['lat']) ? floatval($loc['lat']) : null,
                            'lng'      => isset($loc['lng']) && is_numeric($loc['lng']) ? floatval($loc['lng']) : null,
                            'place_id' => sanitize_text_field($loc['place_id'] ?? ''),
                        );
                    }
                }
                if (!empty($sanitized)) {
                    $data['training_locations'] = wp_json_encode($sanitized);
                    $formats[] = '%s';
                }
            }
        }

        // v190: Save contractor agreement (Bug #8)
        if (!empty($_POST['agree_contract']) && empty($trainer->contractor_agreement_signed)) {
            $data['contractor_agreement_signed'] = 1;
            $formats[] = '%d';
            $data['contractor_agreement_signed_at'] = current_time('mysql');
            $formats[] = '%s';
            $data['contractor_agreement_ip'] = sanitize_text_field($_SERVER['REMOTE_ADDR']);
            $formats[] = '%s';
        }

        // v2: Assemble mentorship_packages from individual checkboxes (legacy fallback)
        if (isset($_POST['mentorship_enabled'])) {
            $pkgs = array();
            if (!empty($_POST['mentorship_pkg_single'])) $pkgs[] = 'single';
            if (!empty($_POST['mentorship_pkg_kickstart'])) $pkgs[] = 'kickstart';
            if (!empty($_POST['mentorship_pkg_development'])) $pkgs[] = 'development';
            if (!empty($_POST['mentorship_pkg_elite'])) $pkgs[] = 'elite';
            if (empty($pkgs) && intval($_POST['mentorship_enabled'])) {
                $pkgs = array('single', 'kickstart', 'development', 'elite');
            }
            $data['mentorship_packages'] = implode(',', $pkgs);
            $formats[] = '%s';
        }

        if (empty($data)) wp_send_json_error('No data to save.');

        $data['updated_at'] = current_time('mysql'); $formats[] = '%s';
        $wpdb->update($wpdb->prefix . 'ptp_trainers', $data, array('id' => $trainer->id), $formats, array('%d'));

        // v194: Handle photo upload from onboarding form
        if (!empty($_FILES['photo']) && $_FILES['photo']['size'] > 0 && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_image_types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
            $file_check = wp_check_filetype(basename($_FILES['photo']['name']), $allowed_image_types);
            if (!empty($file_check['ext']) && $_FILES['photo']['size'] <= 5 * 1024 * 1024) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                
                $attachment_id = media_handle_upload('photo', 0);
                if (!is_wp_error($attachment_id)) {
                    // Resize to max 800x800
                    $file_path = get_attached_file($attachment_id);
                    if ($file_path && file_exists($file_path)) {
                        $editor = wp_get_image_editor($file_path);
                        if (!is_wp_error($editor)) {
                            $img_size = $editor->get_size();
                            if ($img_size && ($img_size['width'] > 800 || $img_size['height'] > 800)) {
                                $editor->resize(800, 800, false);
                                $editor->set_quality(85);
                                $saved = $editor->save($file_path);
                                if (!is_wp_error($saved)) {
                                    wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));
                                }
                            }
                        }
                    }
                    $photo_url = wp_get_attachment_url($attachment_id);
                    $wpdb->update($wpdb->prefix . 'ptp_trainers', array('photo_url' => $photo_url), array('id' => $trainer->id));
                }
            }
        }

        // v191: Detect newly completed onboarding steps for SMS celebrations
        $had_availability = false;
        if (class_exists('PTP_Availability') && method_exists('PTP_Availability', 'get_weekly')) {
            $had_availability = !empty(PTP_Availability::get_weekly($trainer->id));
        }
        $step_checks = array(
            'bio'       => (!empty($data['bio']) && strlen($data['bio']) >= 50) && (empty($trainer->bio) || strlen($trainer->bio) < 50),
            'locations' => !empty($data['training_locations']) && empty($trainer->training_locations),
            'schedule'  => !empty($_POST['availability_json']) && !$had_availability,
        );
        foreach ($step_checks as $step => $just_completed) {
            if ($just_completed) {
                do_action('ptp_trainer_onboarding_step', $trainer->id, $step, true);
            }
        }

        // v191: Fire hook so PTP_Onboarding_Reminders can detect real-time completion (Bug #11)
        do_action('ptp_trainer_onboarding_saved', $trainer->id);

        // v191: Check full onboarding completion (all 9 steps) — properly gates profile_complete (Bug #12)
        // Only fires ptp_trainer_profile_complete when ALL steps pass, not just name+rate
        if (class_exists('PTP_Onboarding_Reminders') && method_exists('PTP_Onboarding_Reminders', 'check_onboarding_completion')) {
            PTP_Onboarding_Reminders::check_onboarding_completion($trainer->id);
        }

        // Save availability
        if (!empty($_POST['availability_json'])) {
            $avail = json_decode(stripslashes($_POST['availability_json']), true);
            if (is_array($avail)) {
                $wpdb->delete($wpdb->prefix . 'ptp_availability', array('trainer_id' => $trainer->id), array('%d'));
                foreach ($avail as $slot) {
                    if (!isset($slot['day'], $slot['start'], $slot['end'])) continue;
                    $wpdb->insert($wpdb->prefix . 'ptp_availability', array(
                        'trainer_id' => $trainer->id, 'day_of_week' => intval($slot['day']),
                        'start_time' => sanitize_text_field($slot['start']),
                        'end_time' => sanitize_text_field($slot['end']),
                        'is_active' => 1, 'created_at' => current_time('mysql'),
                    ), array('%d','%d','%s','%s','%d','%s'));
                }
            }
        }

        wp_send_json_success(array('message' => 'Profile saved!', 'redirect' => home_url('/trainer-dashboard/?welcome=1')));
    }

    /* ---- LOGIN ---- */
    public static function login() {
        // v221: Graceful nonce handling — don't die, return JSON error
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page and try again.'));
        }
        $email = sanitize_email($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if (empty($email) || empty($pass)) wp_send_json_error(array('message' => 'Email and password are required.'));

        $user = wp_authenticate($email, $pass);
        if (is_wp_error($user)) wp_send_json_error(array('message' => 'Invalid email or password.'));

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        // Unified dashboard routing via PTP_User
        if (class_exists('PTP_User')) {
            $redir = PTP_User::get_dashboard_url($user->ID);
        } else {
            // Fallback: check roles directly
            $roles = (array) $user->roles;
            if (in_array('ptp_trainer', $roles) || in_array('trainer', $roles)) {
                $redir = home_url('/trainer-dashboard/');
            } elseif (in_array('administrator', $roles)) {
                $redir = admin_url();
            } else {
                $redir = home_url('/parent-dashboard/');
            }
        }
        wp_send_json_success(array('redirect' => $redir));
    }

    /* ---- REGISTER ---- */
    public static function register() {
        // v221: Graceful nonce handling
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page and try again.'));
        }
        $name  = sanitize_text_field($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $pass  = $_POST['password'] ?? '';
        // v221: Frontend sends 'user_type', accept both for safety
        $role  = sanitize_text_field($_POST['user_type'] ?? $_POST['role'] ?? 'parent');

        if (empty($name) || empty($email) || empty($pass)) wp_send_json_error(array('message' => 'All fields are required.'));
        if (!is_email($email)) wp_send_json_error(array('message' => 'Invalid email.'));
        if (email_exists($email)) wp_send_json_error(array('message' => 'Account already exists. <a href="'.home_url('/login/').'">Log in</a>.'));
        if (strlen($pass) < 6) wp_send_json_error(array('message' => 'Password must be at least 6 characters.'));

        $un = sanitize_user(strtolower(str_replace(' ', '', $name)), true);
        if (username_exists($un)) $un .= wp_rand(100,999);

        $user_id = wp_create_user($un, $pass, $email);
        if (is_wp_error($user_id)) wp_send_json_error(array('message' => $user_id->get_error_message()));

        // v221: Split name and save first/last
        $parts = explode(' ', trim($name), 2);
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';
        wp_update_user(array('ID' => $user_id, 'display_name' => $name, 'first_name' => $first, 'last_name' => $last));
        $user = new WP_User($user_id);

        if ($role === 'trainer') {
            $redirect = home_url('/apply/');
        } else {
            $user->add_role('ptp_parent');
            global $wpdb;
            $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
                'user_id'      => $user_id,
                'display_name' => $name,
                'first_name'   => $first,
                'last_name'    => $last,
                'email'        => $email,
                'phone'        => $phone,
                'created_at'   => current_time('mysql'),
            ));
            // v221: Also save phone to user meta for WP profile
            if ($phone) update_user_meta($user_id, 'billing_phone', $phone);
            $redirect = home_url('/parent-dashboard/');
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        wp_send_json_success(array('redirect' => $redirect));
    }
    
    /**
     * v214: Forgot password AJAX handler
     * Always returns success to not reveal if email exists
     */
    public static function forgot_password() {
        // v221: Verify nonce (frontend field: ptp_forgot_nonce, action: ptp_forgot_password)
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_forgot_password')) {
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page and try again.'));
        }

        // Rate limit
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $rate_key = 'ptp_forgot_rate_' . md5($ip);
        $rate = get_transient($rate_key) ?: 0;
        if ($rate >= 3) {
            // Still return success to not reveal rate limiting
            wp_send_json_success(array('message' => 'If this email exists, a reset link has been sent.'));
        }
        set_transient($rate_key, $rate + 1, 15 * MINUTE_IN_SECONDS);
        
        $email = sanitize_email($_POST['email'] ?? '');
        
        if ($email && is_email($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                // Use WordPress built-in password reset
                retrieve_password($email);
                ptp_log("[PTP Auth v214] Password reset requested for: {$email}");
            }
        }
        
        // Always return success
        wp_send_json_success(array('message' => 'If this email exists, a reset link has been sent.'));
    }
    
    /**
     * v214: Reset password AJAX handler
     */
    public static function reset_password_ajax() {
        // v221: Verify nonce (frontend field: ptp_reset_nonce, action: ptp_reset_password)
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_reset_password')) {
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page and try again.'));
        }

        $pass = $_POST['pass'] ?? '';
        $key = sanitize_text_field($_POST['key'] ?? '');
        $login = sanitize_text_field($_POST['login'] ?? '');
        
        if (strlen($pass) < 8) {
            wp_send_json_error('Password must be at least 8 characters.');
        }
        
        if (!$key || !$login) {
            wp_send_json_error('Invalid reset link.');
        }
        
        // Verify the key
        $user = check_password_reset_key($key, $login);
        
        if (is_wp_error($user)) {
            wp_send_json_error('This reset link is invalid or has expired.');
        }
        
        // Reset the password
        reset_password($user, $pass);
        ptp_log("[PTP Auth v214] Password reset completed for user: {$login}");
        
        wp_send_json_success(array('message' => 'Password reset successfully.'));
    }
}

PTP_Ajax::init();
