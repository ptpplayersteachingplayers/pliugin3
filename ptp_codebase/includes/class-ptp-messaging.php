<?php
/**
 * PTP Messaging System - v71.0.0
 * 
 * Complete messaging functionality:
 * - Real-time AJAX messaging
 * - Conversation management
 * - Unread counts
 * - SMS/Email notifications
 * - Mobile-optimized
 * 
 * @since 71.0.0
 */

defined('ABSPATH') || exit;

class PTP_Messaging_V71 {
    
    private static $table_verified = false;
    private static $messages_table_verified = false;
    
    public static function init() {
        // Ensure tables exist
        add_action('admin_init', array(__CLASS__, 'ensure_tables'), 5);
        add_action('init', array(__CLASS__, 'ensure_tables'), 5);
        
        // AJAX handlers - authenticated users
        add_action('wp_ajax_ptp_get_conversations', array(__CLASS__, 'ajax_get_conversations'));
        add_action('wp_ajax_ptp_get_messages', array(__CLASS__, 'ajax_get_messages'));
        add_action('wp_ajax_ptp_get_new_messages', array(__CLASS__, 'ajax_get_new_messages'));
        add_action('wp_ajax_ptp_send_message', array(__CLASS__, 'ajax_send_message'));
        add_action('wp_ajax_ptp_start_conversation', array(__CLASS__, 'ajax_start_conversation'));
        add_action('wp_ajax_ptp_mark_read', array(__CLASS__, 'ajax_mark_read'));
        add_action('wp_ajax_ptp_get_unread_count', array(__CLASS__, 'ajax_get_unread_count'));
        
        // Public message from trainer profile (creates account if needed)
        add_action('wp_ajax_ptp_send_public_message', array(__CLASS__, 'ajax_send_public_message'));
        add_action('wp_ajax_nopriv_ptp_send_public_message', array(__CLASS__, 'ajax_send_public_message_guest'));
        
        // Register shortcode
        add_shortcode('ptp_messages', array(__CLASS__, 'render_messages_shortcode'));
        
        // Enqueue scripts on messages page
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }
    
    /**
     * Ensure database tables exist
     */
    public static function ensure_tables() {
        self::ensure_conversations_table();
        self::ensure_messages_table();
    }
    
    /**
     * Create conversations table
     */
    private static function ensure_conversations_table() {
        if (self::$table_verified) return true;
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_conversations';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id bigint(20) UNSIGNED NOT NULL,
                parent_id bigint(20) UNSIGNED NOT NULL,
                last_message_id bigint(20) UNSIGNED DEFAULT NULL,
                last_message_at datetime DEFAULT NULL,
                trainer_unread_count int(11) DEFAULT 0,
                parent_unread_count int(11) DEFAULT 0,
                is_archived tinyint(1) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY trainer_parent (trainer_id, parent_id),
                KEY trainer_id (trainer_id),
                KEY parent_id (parent_id),
                KEY last_message_at (last_message_at)
            ) $charset;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        self::$table_verified = true;
        return true;
    }
    
    /**
     * Create messages table
     */
    private static function ensure_messages_table() {
        if (self::$messages_table_verified) return true;
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_messages';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id bigint(20) UNSIGNED NOT NULL,
                sender_id bigint(20) UNSIGNED NOT NULL,
                message text NOT NULL,
                is_read tinyint(1) DEFAULT 0,
                read_at datetime DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY conversation_id (conversation_id),
                KEY sender_id (sender_id),
                KEY created_at (created_at),
                KEY is_read (is_read)
            ) $charset;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        self::$messages_table_verified = true;
        return true;
    }
    
    /**
     * Enqueue scripts
     */
    public static function enqueue_scripts() {
        if (!is_page('messages') && !is_page('messaging')) return;
        
        wp_enqueue_script(
            'ptp-messaging',
            PTP_PLUGIN_URL . 'assets/js/messaging.js',
            array(),
            PTP_VERSION,
            true
        );
        
        wp_localize_script('ptp-messaging', 'ptpMsg', array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_nonce'),
            'userId' => get_current_user_id(),
            'pollInterval' => 3000
        ));
    }
    
    // ===========================================
    // CORE FUNCTIONS
    // ===========================================
    
    /**
     * Get conversation by ID
     */
    public static function get_conversation($conversation_id) {
        global $wpdb;
        self::ensure_tables();
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_conversations WHERE id = %d",
            $conversation_id
        ));
    }
    
    /**
     * Get or create conversation between trainer and parent
     */
    public static function get_or_create_conversation($trainer_id, $parent_id) {
        global $wpdb;
        self::ensure_tables();
        
        $table = $wpdb->prefix . 'ptp_conversations';
        
        // Try to find existing
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE trainer_id = %d AND parent_id = %d",
            $trainer_id, $parent_id
        ));
        
        if ($conversation) {
            return $conversation;
        }
        
        // Create new
        $wpdb->insert($table, array(
            'trainer_id' => $trainer_id,
            'parent_id' => $parent_id,
            'created_at' => current_time('mysql')
        ));
        
        return self::get_conversation($wpdb->insert_id);
    }
    
    /**
     * Get conversations for a user
     */
    public static function get_conversations_for_user($user_id) {
        global $wpdb;
        self::ensure_tables();
        
        // Check if trainer or parent
        $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get_by_user_id($user_id) : null;
        $parent = class_exists('PTP_Parent') ? PTP_Parent::get_by_user_id($user_id) : null;
        
        $conversations_table = $wpdb->prefix . 'ptp_conversations';
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        $parents_table = $wpdb->prefix . 'ptp_parents';
        $messages_table = $wpdb->prefix . 'ptp_messages';
        
        $results = array();
        $seen_ids = array();
        
        // Get conversations where user is trainer
        if ($trainer) {
            $trainer_convos = $wpdb->get_results($wpdb->prepare("
                SELECT c.*, 
                       p.display_name as other_name, 
                       p.user_id as other_user_id,
                       '' as other_photo,
                       c.trainer_unread_count as unread_count,
                       (SELECT message FROM $messages_table WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message
                FROM $conversations_table c
                JOIN $parents_table p ON c.parent_id = p.id
                WHERE c.trainer_id = %d AND c.is_archived = 0
                ORDER BY c.last_message_at DESC
            ", $trainer->id));
            foreach ($trainer_convos as $c) {
                $results[] = $c;
                $seen_ids[] = $c->id;
            }
        }
        
        // Get conversations where user is parent
        if ($parent) {
            $parent_convos = $wpdb->get_results($wpdb->prepare("
                SELECT c.*, 
                       t.display_name as other_name, 
                       t.user_id as other_user_id,
                       t.photo_url as other_photo,
                       c.parent_unread_count as unread_count,
                       (SELECT message FROM $messages_table WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message
                FROM $conversations_table c
                JOIN $trainers_table t ON c.trainer_id = t.id
                WHERE c.parent_id = %d AND c.is_archived = 0
                ORDER BY c.last_message_at DESC
            ", $parent->id));
            foreach ($parent_convos as $c) {
                if (!in_array($c->id, $seen_ids)) {
                    $results[] = $c;
                }
            }
        }
        
        // Sort all results by last_message_at desc
        usort($results, function($a, $b) {
            return strtotime($b->last_message_at ?: '1970-01-01') - strtotime($a->last_message_at ?: '1970-01-01');
        });
        
        return $results;
    }
    
    /**
     * Check if user can access conversation
     */
    public static function user_can_access_conversation($user_id, $conversation_id) {
        global $wpdb;
        
        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT trainer_id, parent_id FROM {$wpdb->prefix}ptp_conversations WHERE id = %d",
            $conversation_id
        ));
        
        if (!$conv) return false;
        
        // Check if user is the trainer
        $trainer = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d AND id = %d",
            $user_id, $conv->trainer_id
        ));
        if ($trainer) return true;
        
        // Check if user is the parent
        $parent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d AND id = %d",
            $user_id, $conv->parent_id
        ));
        if ($parent) return true;
        
        return false;
    }
    
    /**
     * Get other party name in conversation
     */
    public static function get_other_party_name($conversation_id, $user_id) {
        global $wpdb;
        
        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT trainer_id, parent_id FROM {$wpdb->prefix}ptp_conversations WHERE id = %d",
            $conversation_id
        ));
        
        if (!$conv) return '';
        
        // Check if current user is trainer
        $is_trainer = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d AND id = %d",
            $user_id, $conv->trainer_id
        ));
        
        if ($is_trainer) {
            // Return parent name
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}ptp_parents WHERE id = %d",
                $conv->parent_id
            ));
            return $parent ? $parent->first_name . ' ' . $parent->last_name : 'Unknown';
        } else {
            // Return trainer name
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $conv->trainer_id
            ));
            return $trainer ? $trainer->first_name . ' ' . $trainer->last_name : 'Unknown';
        }
    }
    
    /**
     * Get messages for a conversation
     */
    public static function get_messages($conversation_id, $limit = 50, $before_id = null) {
        global $wpdb;
        self::ensure_tables();
        
        $table = $wpdb->prefix . 'ptp_messages';
        
        $where = "conversation_id = %d";
        $params = array($conversation_id);
        
        if ($before_id) {
            $where .= " AND id < %d";
            $params[] = $before_id;
        }
        
        $params[] = $limit;
        
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d",
            $params
        ));
        
        // Reverse to get chronological order
        return array_reverse($messages);
    }
    
    /**
     * Get new messages since last ID
     */
    public static function get_new_messages($conversation_id, $last_id, $user_id) {
        global $wpdb;
        self::ensure_tables();
        
        $table = $wpdb->prefix . 'ptp_messages';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE conversation_id = %d AND id > %d AND sender_id != %d ORDER BY created_at ASC",
            $conversation_id, $last_id, $user_id
        ));
    }
    
    /**
     * Send a message
     */
    public static function send_message($conversation_id, $sender_id, $message) {
        global $wpdb;
        self::ensure_tables();
        
        $conversation = self::get_conversation($conversation_id);
        if (!$conversation) {
            return new WP_Error('invalid_conversation', 'Conversation not found');
        }
        
        // Verify sender is part of conversation
        $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get_by_user_id($sender_id) : null;
        $parent = class_exists('PTP_Parent') ? PTP_Parent::get_by_user_id($sender_id) : null;
        
        $is_trainer_sender = $trainer && $trainer->id == $conversation->trainer_id;
        $is_parent_sender = $parent && $parent->id == $conversation->parent_id;
        
        if (!$is_trainer_sender && !$is_parent_sender && !current_user_can('manage_options')) {
            return new WP_Error('unauthorized', 'You are not part of this conversation');
        }
        
        // v233: Platform protection — scan trainer messages for off-platform contact
        $scan_result = apply_filters('ptp_pre_send_message', $message, $sender_id, $conversation_id);
        if (is_array($scan_result) && isset($scan_result['allow']) && $scan_result['allow'] === false) {
            return new WP_Error('message_blocked', 'This message could not be sent. If you need to share contact information with a family, please do so through the PTP platform tools.');
        }

        // Insert message
        $messages_table = $wpdb->prefix . 'ptp_messages';
        $wpdb->insert($messages_table, array(
            'conversation_id' => $conversation_id,
            'sender_id' => $sender_id,
            'message' => sanitize_textarea_field($message),
            'created_at' => current_time('mysql')
        ));
        
        $message_id = $wpdb->insert_id;
        
        if (!$message_id) {
            return new WP_Error('insert_failed', 'Failed to save message');
        }
        
        // Update conversation
        $conversations_table = $wpdb->prefix . 'ptp_conversations';
        $update_data = array(
            'last_message_id' => $message_id,
            'last_message_at' => current_time('mysql')
        );
        
        if ($is_trainer_sender) {
            $update_data['parent_unread_count'] = $conversation->parent_unread_count + 1;
        } else {
            $update_data['trainer_unread_count'] = $conversation->trainer_unread_count + 1;
        }
        
        $wpdb->update($conversations_table, $update_data, array('id' => $conversation_id));
        
        // Fire hook for SMS/email notifications (SMS class listens to this)
        do_action('ptp_message_sent', $conversation_id, $sender_id, substr($message, 0, 100));
        
        // Send notification to recipient
        self::send_notification($conversation, $sender_id, $message);
        
        return $message_id;
    }
    
    /**
     * Mark conversation as read
     */
    public static function mark_as_read($conversation_id, $user_id) {
        global $wpdb;
        self::ensure_tables();
        
        $conversation = self::get_conversation($conversation_id);
        if (!$conversation) return false;
        
        $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get_by_user_id($user_id) : null;
        $parent = class_exists('PTP_Parent') ? PTP_Parent::get_by_user_id($user_id) : null;
        
        $conversations_table = $wpdb->prefix . 'ptp_conversations';
        $messages_table = $wpdb->prefix . 'ptp_messages';
        
        if ($trainer && $trainer->id == $conversation->trainer_id) {
            $wpdb->update($conversations_table, 
                array('trainer_unread_count' => 0), 
                array('id' => $conversation_id)
            );
        } elseif ($parent && $parent->id == $conversation->parent_id) {
            $wpdb->update($conversations_table, 
                array('parent_unread_count' => 0), 
                array('id' => $conversation_id)
            );
        }
        
        // Mark individual messages as read
        $wpdb->query($wpdb->prepare(
            "UPDATE $messages_table SET is_read = 1, read_at = %s 
             WHERE conversation_id = %d AND sender_id != %d AND is_read = 0",
            current_time('mysql'), $conversation_id, $user_id
        ));
        
        return true;
    }
    
    /**
     * Get total unread count for user
     */
    public static function get_unread_count($user_id) {
        global $wpdb;
        self::ensure_tables();
        
        $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get_by_user_id($user_id) : null;
        $parent = class_exists('PTP_Parent') ? PTP_Parent::get_by_user_id($user_id) : null;
        
        $table = $wpdb->prefix . 'ptp_conversations';
        
        if ($trainer) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(trainer_unread_count), 0) FROM $table WHERE trainer_id = %d",
                $trainer->id
            ));
        } elseif ($parent) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(parent_unread_count), 0) FROM $table WHERE parent_id = %d",
                $parent->id
            ));
        }
        
        return 0;
    }
    
    /**
     * Send notification to message recipient
     */
    private static function send_notification($conversation, $sender_id, $message_text) {
        global $wpdb;
        
        // Get sender and recipient info
        $trainers_table = $wpdb->prefix . 'ptp_trainers';
        $parents_table = $wpdb->prefix . 'ptp_parents';
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $trainers_table WHERE id = %d", 
            $conversation->trainer_id
        ));
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $parents_table WHERE id = %d", 
            $conversation->parent_id
        ));
        
        if (!$trainer || !$parent) return;
        
        // Determine recipient
        if ($trainer->user_id == $sender_id) {
            // Trainer sent, notify parent
            $recipient_phone = $parent->phone ?? '';
            $recipient_email = get_userdata($parent->user_id)->user_email ?? '';
            $sender_name = $trainer->display_name;
        } else {
            // Parent sent, notify trainer
            $recipient_phone = $trainer->phone ?? '';
            $recipient_email = get_userdata($trainer->user_id)->user_email ?? '';
            $sender_name = $parent->display_name;
        }
        
        // Send SMS notification
        if (!empty($recipient_phone) && class_exists('PTP_SMS_V71')) {
            PTP_SMS_V71::send_message_notification($conversation->id, $sender_id, substr($message_text, 0, 50));
        }
        
        // Send email notification
        if (!empty($recipient_email)) {
            $messages_url = home_url('/messages/?conversation=' . $conversation->id);
            $subject = 'New message from ' . $sender_name . ' — PTP Training';
            $body  = "Hi,\n\n";
            $body .= $sender_name . " sent you a message:\n\n";
            $body .= "\"" . wp_trim_words($message_text, 30, '...') . "\"\n\n";
            $body .= "Reply here: " . $messages_url . "\n\n";
            $body .= "— PTP Training Platform";
            
            wp_mail($recipient_email, $subject, $body);
        }
    }
    
    // ===========================================
    // AJAX HANDLERS
    // ===========================================
    
    /**
     * AJAX: Get conversations
     */
    public static function ajax_get_conversations() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $conversations = self::get_conversations_for_user(get_current_user_id());
        
        // Format for frontend
        $formatted = array();
        foreach ($conversations as $conv) {
            $formatted[] = array(
                'id' => $conv->id,
                'other_name' => $conv->other_name,
                'other_photo' => $conv->other_photo ?: '',
                'unread_count' => (int) $conv->unread_count,
                'last_message' => $conv->last_message ? substr($conv->last_message, 0, 50) : '',
                'last_message_at' => $conv->last_message_at
            );
        }
        
        wp_send_json_success(array('conversations' => $formatted));
    }
    
    /**
     * AJAX: Get messages
     */
    public static function ajax_get_messages() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $before_id = isset($_POST['before_id']) ? intval($_POST['before_id']) : null;
        
        if (!$conversation_id) {
            wp_send_json_error(array('message' => 'Invalid conversation'));
            return;
        }
        
        // Verify user has access
        $conversation = self::get_conversation($conversation_id);
        if (!$conversation) {
            wp_send_json_error(array('message' => 'Conversation not found'));
            return;
        }
        
        $user_id = get_current_user_id();
        $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get_by_user_id($user_id) : null;
        $parent = class_exists('PTP_Parent') ? PTP_Parent::get_by_user_id($user_id) : null;
        
        $has_access = ($trainer && $trainer->id == $conversation->trainer_id) ||
                      ($parent && $parent->id == $conversation->parent_id) ||
                      current_user_can('manage_options');
        
        if (!$has_access) {
            wp_send_json_error(array('message' => 'Access denied'));
            return;
        }
        
        // Get messages
        $messages = self::get_messages($conversation_id, 50, $before_id);
        
        // Mark as read
        self::mark_as_read($conversation_id, $user_id);
        
        // Get other user's name
        global $wpdb;
        $other_name = '';
        if ($trainer && $trainer->id == $conversation->trainer_id) {
            $parent_data = $wpdb->get_row($wpdb->prepare(
                "SELECT display_name FROM {$wpdb->prefix}ptp_parents WHERE id = %d",
                $conversation->parent_id
            ));
            $other_name = $parent_data->display_name ?? '';
        } else {
            $trainer_data = $wpdb->get_row($wpdb->prepare(
                "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $conversation->trainer_id
            ));
            $other_name = $trainer_data->display_name ?? '';
        }
        
        // Format messages
        $formatted = array();
        foreach ($messages as $msg) {
            $formatted[] = array(
                'id' => $msg->id,
                'message' => $msg->message,
                'is_mine' => $msg->sender_id == $user_id,
                'created_at' => $msg->created_at,
                'is_read' => (bool) $msg->is_read
            );
        }
        
        wp_send_json_success(array(
            'messages' => $formatted,
            'other_name' => $other_name,
            'conversation_id' => $conversation_id
        ));
    }
    
    /**
     * AJAX: Get new messages (for polling)
     */
    public static function ajax_get_new_messages() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $last_id = isset($_POST['last_id']) ? intval($_POST['last_id']) : 0;
        
        if (!$conversation_id) {
            wp_send_json_success(array('messages' => array()));
            return;
        }
        
        $user_id = get_current_user_id();
        $messages = self::get_new_messages($conversation_id, $last_id, $user_id);
        
        // Mark as read
        if (!empty($messages)) {
            self::mark_as_read($conversation_id, $user_id);
        }
        
        $formatted = array();
        foreach ($messages as $msg) {
            $formatted[] = array(
                'id' => $msg->id,
                'message' => $msg->message,
                'is_mine' => $msg->sender_id == $user_id,
                'created_at' => $msg->created_at
            );
        }
        
        wp_send_json_success(array('messages' => $formatted));
    }
    
    /**
     * AJAX: Send message
     */
    public static function ajax_send_message() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        if (!$conversation_id || empty(trim($message))) {
            wp_send_json_error(array('message' => 'Invalid request'));
            return;
        }
        
        $result = self::send_message($conversation_id, get_current_user_id(), $message);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        wp_send_json_success(array(
            'message_id' => $result,
            'message' => 'Message sent'
        ));
    }
    
    /**
     * AJAX: Start conversation
     */
    public static function ajax_start_conversation() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $trainer_id = isset($_POST['trainer_id']) ? intval($_POST['trainer_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer'));
            return;
        }
        
        // Get or create parent record for current user
        $parent = class_exists('PTP_Parent') ? PTP_Parent::get_by_user_id(get_current_user_id()) : null;
        
        if (!$parent) {
            // Create parent record
            if (class_exists('PTP_Parent')) {
                $user = wp_get_current_user();
                $parent_id = PTP_Parent::create(get_current_user_id(), array(
                    'display_name' => $user->display_name,
                    'email' => $user->user_email
                ));
                if (!is_wp_error($parent_id)) {
                    $parent = PTP_Parent::get_by_user_id(get_current_user_id());
                }
            }
            
            if (!$parent) {
                wp_send_json_error(array('message' => 'Could not create parent profile'));
                return;
            }
        }
        
        // Get or create conversation
        $conversation = self::get_or_create_conversation($trainer_id, $parent->id);
        
        if (!$conversation) {
            wp_send_json_error(array('message' => 'Could not create conversation'));
            return;
        }
        
        // Send initial message if provided
        if (!empty(trim($message))) {
            self::send_message($conversation->id, get_current_user_id(), $message);
        }
        
        wp_send_json_success(array(
            'conversation_id' => $conversation->id,
            'message' => 'Conversation started'
        ));
    }
    
    /**
     * AJAX: Mark conversation as read
     */
    public static function ajax_mark_read() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
            return;
        }
        
        $conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
        
        if ($conversation_id) {
            self::mark_as_read($conversation_id, get_current_user_id());
        }
        
        wp_send_json_success();
    }
    
    /**
     * AJAX: Get unread count
     */
    public static function ajax_get_unread_count() {
        if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_success(array('count' => 0));
            return;
        }
        
        $count = self::get_unread_count(get_current_user_id());
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * AJAX: Send public message (logged in user)
     */
    public static function ajax_send_public_message() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to send a message.'));
            return;
        }
        
        $trainer_id = isset($_POST['trainer_id']) ? intval($_POST['trainer_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        if (!$trainer_id || empty(trim($message))) {
            wp_send_json_error(array('message' => 'Please enter a message'));
            return;
        }
        
        // Get or create parent record
        $parent = class_exists('PTP_Parent') ? PTP_Parent::get_by_user_id(get_current_user_id()) : null;
        if (!$parent) {
            if (class_exists('PTP_Parent')) {
                $user = wp_get_current_user();
                $parent_id = PTP_Parent::create(get_current_user_id(), array(
                    'display_name' => $user->display_name,
                    'email' => $user->user_email
                ));
                if (is_wp_error($parent_id)) {
                    ptp_log('PTP Messaging: Failed to create parent - ' . $parent_id->get_error_message());
                    wp_send_json_error(array('message' => 'Could not create your profile. Please try again.'));
                    return;
                }
                $parent = PTP_Parent::get_by_user_id(get_current_user_id());
            }
            if (!$parent) {
                wp_send_json_error(array('message' => 'Could not find your profile. Please try again.'));
                return;
            }
        }
        
        // Get or create conversation
        $conversation = self::get_or_create_conversation($trainer_id, $parent->id);
        if (!$conversation) {
            wp_send_json_error(array('message' => 'Could not start conversation. Please try again.'));
            return;
        }
        
        // Send message
        $msg_id = self::send_message($conversation->id, get_current_user_id(), $message);
        if (is_wp_error($msg_id)) {
            wp_send_json_error(array('message' => $msg_id->get_error_message()));
            return;
        }
        
        wp_send_json_success(array(
            'conversation_id' => $conversation->id,
            'message' => 'Message sent!'
        ));
    }
    
    /**
     * AJAX: Send public message (guest - creates account)
     */
    public static function ajax_send_public_message_guest() {
        // v242: Nonce verification — guests get the same nonce from the profile page
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }
        
        // v242: IP-based rate limiting — 5 guest messages per hour per IP
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $rate_key = 'ptp_guest_msg_' . md5($ip);
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 5) {
            wp_send_json_error(array('message' => 'Too many messages. Please try again later.'));
            return;
        }
        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);
        
        $trainer_id = isset($_POST['trainer_id']) ? intval($_POST['trainer_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        
        if (!$trainer_id || empty(trim($message)) || empty($name) || !is_email($email)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields'));
            return;
        }
        
        // Check if user exists
        $user = get_user_by('email', $email);
        
        if (!$user) {
            // Create user with random password (never sent in plaintext)
            $password = wp_generate_password(24, true, true);
            $user_id = wp_create_user($email, $password, $email);
            
            if (is_wp_error($user_id)) {
                wp_send_json_error(array('message' => 'Could not create account: ' . $user_id->get_error_message()));
                return;
            }
            
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => $name,
                'first_name' => explode(' ', $name)[0]
            ));
            
            $user = get_user_by('id', $user_id);
            
            // v242: Send password reset link instead of plaintext password
            $reset_key = get_password_reset_key($user);
            $reset_url = network_site_url("wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($user->user_login), 'login');
            wp_mail($email, 'Welcome to PTP Training',
                "Hi $name,\n\nYour PTP Training account has been created so you can message trainers and book sessions.\n\n"
                . "Set your password here: $reset_url\n\n"
                . "Or log in with Google at: " . home_url('/login/') . "\n\n"
                . "— PTP Team"
            );
        }
        
        // v242: Set current user for this request only — no auth cookie for guest-created accounts
        // They need to explicitly log in or use the password reset link
        wp_set_current_user($user->ID);
        
        // Create parent record
        if (class_exists('PTP_Parent')) {
            $parent = PTP_Parent::get_by_user_id($user->ID);
            if (!$parent) {
                PTP_Parent::create($user->ID, array(
                    'display_name' => $name,
                    'email' => $email,
                    'phone' => $phone
                ));
            }
        }
        
        // Now send message — forge nonce for current request context
        $_POST['nonce'] = wp_create_nonce('ptp_nonce');
        $_POST['trainer_id'] = $trainer_id;
        $_POST['message'] = $message;
        
        self::ajax_start_conversation();
    }
    
    /**
     * Render messages shortcode
     */
    public static function render_messages_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<div class="ptp-login-required"><p>Please <a href="' . home_url('/login/') . '">log in</a> to view messages.</p></div>';
        }
        
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/messaging.php';
        return ob_get_clean();
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_Messaging_V71', 'init'), 15);

// Backwards compatibility alias - allows PTP_Messaging::method() calls to work
if (!class_exists('PTP_Messaging')) {
    class_alias('PTP_Messaging_V71', 'PTP_Messaging');
}
