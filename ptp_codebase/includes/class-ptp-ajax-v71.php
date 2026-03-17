<?php
/**
 * PTP AJAX Handler - v71
 * Handles all AJAX requests for the platform
 */

defined('ABSPATH') || exit;

class PTP_Ajax_V71 {
    
    public function __construct() {
        // v213: Messaging endpoints now handled by PTP_Messaging class
        // add_action('wp_ajax_ptp_get_conversations', array($this, 'get_conversations'));
        // add_action('wp_ajax_ptp_get_messages', array($this, 'get_messages'));
        // add_action('wp_ajax_ptp_get_new_messages', array($this, 'get_new_messages'));
        // add_action('wp_ajax_ptp_send_message', array($this, 'send_message'));
        // add_action('wp_ajax_ptp_start_conversation', array($this, 'start_conversation'));
        // add_action('wp_ajax_ptp_mark_read', array($this, 'mark_read'));
        // add_action('wp_ajax_ptp_get_unread_count', array($this, 'get_unread_count'));
        
        // Public messaging - send_public_message handled by PTP_Messaging, guest version is unique here
        // add_action('wp_ajax_ptp_send_public_message', array($this, 'send_public_message'));
        add_action('wp_ajax_nopriv_ptp_send_public_message_guest', array($this, 'send_public_message_guest'));
        
        // Schedule/Availability - blocks handled by PTP_Availability, slots are unique here
        // add_action('wp_ajax_ptp_get_availability_blocks', array($this, 'get_availability_blocks'));
        // add_action('wp_ajax_ptp_save_availability_blocks', array($this, 'save_availability_blocks'));
        add_action('wp_ajax_ptp_get_trainer_slots', array($this, 'get_trainer_slots'));
        add_action('wp_ajax_nopriv_ptp_get_trainer_slots', array($this, 'get_trainer_slots'));
        
        // Dashboard tab loading - handled by dashboard templates
        // add_action('wp_ajax_ptp_load_trainer_tab', array($this, 'load_trainer_tab'));
        // add_action('wp_ajax_ptp_load_parent_tab', array($this, 'load_parent_tab'));
        
        // Profile endpoints
        add_action('wp_ajax_ptp_save_trainer_profile', array($this, 'save_trainer_profile'));
        
        // Session confirmation (trainer dashboard)
        add_action('wp_ajax_ptp_confirm_session', array($this, 'confirm_session'));
        
        // Player endpoints - get_players handled elsewhere, save/delete unique here
        // add_action('wp_ajax_ptp_get_players', array($this, 'get_players'));
        add_action('wp_ajax_ptp_save_player', array($this, 'save_player'));
        add_action('wp_ajax_ptp_delete_player', array($this, 'delete_player'));
    }
    
    /**
     * Verify nonce
     */
    private function verify_nonce() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'), 403);
        }
    }
    
    /**
     * Require login
     */
    private function require_login() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'), 401);
        }
    }
    
    // ==========================================
    // MESSAGING ENDPOINTS
    // ==========================================
    
    public function get_conversations() {
        $this->verify_nonce();
        $this->require_login();
        
        $user_id = get_current_user_id();
        $conversations = PTP_Messaging_V71::get_conversations_for_user($user_id);
        
        wp_send_json_success(array('conversations' => $conversations));
    }
    
    public function get_messages() {
        $this->verify_nonce();
        $this->require_login();
        
        $conversation_id = absint($_POST['conversation_id']);
        $user_id = get_current_user_id();
        
        if (!PTP_Messaging_V71::user_can_access_conversation($user_id, $conversation_id)) {
            wp_send_json_error(array('message' => 'Access denied'), 403);
        }
        
        $messages = PTP_Messaging_V71::get_messages($conversation_id, 50, null, $user_id);
        $other_party = PTP_Messaging_V71::get_other_party($conversation_id, $user_id);
        
        PTP_Messaging_V71::mark_as_read($conversation_id, $user_id);
        
        wp_send_json_success(array(
            'messages' => $messages,
            'other_name' => $other_party ? $other_party->name : 'Unknown',
            'other_photo' => $other_party ? $other_party->photo : ''
        ));
    }
    
    public function get_new_messages() {
        $this->verify_nonce();
        $this->require_login();
        
        $conversation_id = absint($_POST['conversation_id']);
        $last_id = absint($_POST['last_id']);
        $user_id = get_current_user_id();
        
        if (!PTP_Messaging_V71::user_can_access_conversation($user_id, $conversation_id)) {
            wp_send_json_success(array('messages' => array()));
        }
        
        $messages = PTP_Messaging_V71::get_new_messages($conversation_id, $last_id, $user_id);
        
        if (!empty($messages)) {
            PTP_Messaging_V71::mark_as_read($conversation_id, $user_id);
        }
        
        wp_send_json_success(array('messages' => $messages));
    }
    
    public function send_message() {
        $this->verify_nonce();
        $this->require_login();
        
        $conversation_id = absint($_POST['conversation_id']);
        $message = sanitize_textarea_field($_POST['message']);
        $user_id = get_current_user_id();
        
        if (empty($message)) {
            wp_send_json_error(array('message' => 'Message cannot be empty'));
        }
        
        if (!PTP_Messaging_V71::user_can_access_conversation($user_id, $conversation_id)) {
            wp_send_json_error(array('message' => 'Access denied'), 403);
        }
        
        $message_id = PTP_Messaging_V71::send_message($conversation_id, $user_id, $message);
        
        if ($message_id) {
            wp_send_json_success(array('message_id' => $message_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to send message'));
        }
    }
    
    public function start_conversation() {
        $this->verify_nonce();
        $this->require_login();
        
        $trainer_id = absint($_POST['trainer_id']);
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        $user_id = get_current_user_id();
        
        $conversation_id = PTP_Messaging_V71::get_or_create_conversation($trainer_id, $user_id);
        
        if (!$conversation_id) {
            wp_send_json_error(array('message' => 'Failed to create conversation'));
        }
        
        if (!empty($message)) {
            PTP_Messaging_V71::send_message($conversation_id, $user_id, $message);
        }
        
        wp_send_json_success(array('conversation_id' => $conversation_id));
    }
    
    public function mark_read() {
        $this->verify_nonce();
        $this->require_login();
        
        $conversation_id = absint($_POST['conversation_id']);
        $user_id = get_current_user_id();
        
        PTP_Messaging_V71::mark_as_read($conversation_id, $user_id);
        
        wp_send_json_success();
    }
    
    public function get_unread_count() {
        $this->verify_nonce();
        $this->require_login();
        
        $count = PTP_Messaging_V71::get_unread_count(get_current_user_id());
        
        wp_send_json_success(array('count' => $count));
    }
    
    public function send_public_message() {
        $this->verify_nonce();
        $this->require_login();
        
        $trainer_id = absint($_POST['trainer_id']);
        $message = sanitize_textarea_field($_POST['message']);
        $user_id = get_current_user_id();
        
        if (empty($message)) {
            wp_send_json_error(array('message' => 'Message cannot be empty'));
        }
        
        $conversation_id = PTP_Messaging_V71::get_or_create_conversation($trainer_id, $user_id);
        
        if ($conversation_id) {
            PTP_Messaging_V71::send_message($conversation_id, $user_id, $message);
            wp_send_json_success(array('conversation_id' => $conversation_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to send message'));
        }
    }
    
    public function send_public_message_guest() {
        $this->verify_nonce();
        
        $trainer_id = absint($_POST['trainer_id']);
        $message = sanitize_textarea_field($_POST['message']);
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        
        if (empty($message) || empty($name) || empty($email)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields'));
        }
        
        $user = get_user_by('email', $email);
        
        if ($user) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID);
            $user_id = $user->ID;
        } else {
            $name_parts = explode(' ', $name, 2);
            $first_name = $name_parts[0];
            $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
            
            $password = wp_generate_password(12);
            $user_id = wp_create_user($email, $password, $email);
            
            if (is_wp_error($user_id)) {
                wp_send_json_error(array('message' => 'Failed to create account'));
            }
            
            wp_update_user(array(
                'ID' => $user_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'display_name' => $name
            ));
            
            if ($phone) {
                update_user_meta($user_id, 'billing_phone', $phone);
            }
            
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'ptp_parents',
                array(
                    'user_id' => $user_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone' => $phone,
                    'created_at' => current_time('mysql')
                )
            );
            
            wp_new_user_notification($user_id, null, 'user');
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
        }
        
        $conversation_id = PTP_Messaging_V71::get_or_create_conversation($trainer_id, $user_id);
        
        if ($conversation_id) {
            PTP_Messaging_V71::send_message($conversation_id, $user_id, $message);
            
            wp_send_json_success(array(
                'conversation_id' => $conversation_id,
                'redirect' => home_url('/messages/?conversation=' . $conversation_id)
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to send message'));
        }
    }
    
    // ==========================================
    // SCHEDULE ENDPOINTS
    // ==========================================
    
    public function get_availability_blocks() {
        $this->verify_nonce();
        $this->require_login();
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        $schedule = PTP_Availability_V71::get_schedule($trainer_id);
        
        wp_send_json_success(array('schedule' => $schedule));
    }
    
    public function save_availability_blocks() {
        $this->verify_nonce();
        $this->require_login();
        
        $user_id = get_current_user_id();
        $schedule = json_decode(stripslashes($_POST['schedule']), true);
        
        if (!is_array($schedule)) {
            wp_send_json_error(array('message' => 'Invalid schedule data'));
        }
        
        global $wpdb;
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        $result = PTP_Availability_V71::save_schedule($trainer_id, $schedule);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Schedule saved'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save schedule'));
        }
    }
    
    public function get_trainer_slots() {
        $trainer_id = absint($_POST['trainer_id'] ?? $_GET['trainer_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? $_GET['date'] ?? date('Y-m-d'));
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer ID required'));
        }
        
        $slots = PTP_Availability_V71::get_available_slots($trainer_id, $date);
        
        wp_send_json_success(array('slots' => $slots));
    }
    
    // ==========================================
    // DASHBOARD TAB LOADING
    // ==========================================
    
    public function load_trainer_tab() {
        $this->verify_nonce();
        $this->require_login();
        
        $tab = sanitize_text_field($_POST['tab']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Not authorized'));
        }
        
        ob_start();
        
        switch ($tab) {
            case 'overview':
                $this->render_trainer_overview($trainer);
                break;
            case 'sessions':
                $this->render_trainer_sessions($trainer);
                break;
            case 'schedule':
                $this->render_schedule_editor();
                break;
            case 'messages':
                $this->render_messages_panel($user_id);
                break;
            case 'earnings':
                $this->render_trainer_earnings($trainer);
                break;
            case 'profile':
                $this->render_trainer_profile($trainer);
                break;
            default:
                echo '<p>Tab not found</p>';
        }
        
        $html = ob_get_clean();
        
        wp_send_json_success(array('html' => $html));
    }
    
    public function load_parent_tab() {
        $this->verify_nonce();
        $this->require_login();
        
        $tab = sanitize_text_field($_POST['tab']);
        $user_id = get_current_user_id();
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        ob_start();
        
        switch ($tab) {
            case 'overview':
                $this->render_parent_overview($parent, $user_id);
                break;
            case 'sessions':
                $this->render_parent_sessions($user_id);
                break;
            case 'players':
                $this->render_parent_players($user_id);
                break;
            case 'messages':
                $this->render_messages_panel($user_id);
                break;
            default:
                echo '<p>Tab not found</p>';
        }
        
        $html = ob_get_clean();
        
        wp_send_json_success(array('html' => $html));
    }
    
    private function render_trainer_overview($trainer) {
        global $wpdb;
        
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND status IN ('pending', 'confirmed') AND session_date >= CURDATE()",
            $trainer->id
        )) ?: 0;
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND status = 'completed'",
            $trainer->id
        )) ?: 0;
        
        $earnings = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(trainer_payout) FROM {$wpdb->prefix}ptp_bookings WHERE trainer_id = %d AND status = 'completed'",
            $trainer->id
        )) ?: 0;
        
        $unread = PTP_Messaging_V71::get_unread_count($trainer->user_id);
        
        ?>
        <div class="ptp-stats-grid">
            <div class="ptp-stat-card"><div class="ptp-stat-value"><?php echo $pending; ?></div><div class="ptp-stat-label">Upcoming</div></div>
            <div class="ptp-stat-card"><div class="ptp-stat-value"><?php echo $total; ?></div><div class="ptp-stat-label">Total Sessions</div></div>
            <div class="ptp-stat-card highlight"><div class="ptp-stat-value">$<?php echo number_format($earnings); ?></div><div class="ptp-stat-label">Earnings</div></div>
            <div class="ptp-stat-card"><div class="ptp-stat-value"><?php echo $unread; ?></div><div class="ptp-stat-label">Messages</div></div>
        </div>
        <?php
    }
    
    private function render_trainer_sessions($trainer) {
        echo '<p>Sessions list coming soon...</p>';
    }
    
    private function render_schedule_editor() {
        ?>
        <div class="ptp-schedule-header">
            <h2>Weekly Availability</h2>
            <button class="ptp-btn ptp-btn-primary ptp-save-schedule-btn">Save Schedule</button>
        </div>
        <div class="ptp-schedule-grid"></div>
        <?php
    }
    
    private function render_trainer_earnings($trainer) {
        echo '<p>Earnings dashboard coming soon...</p>';
    }
    
    private function render_trainer_profile($trainer) {
        ?>
        <form class="ptp-profile-form">
            <div class="ptp-form-section">
                <div class="ptp-form-section-header"><h3>Profile</h3></div>
                <div class="ptp-form-section-body">
                    <div class="ptp-form-group">
                        <label class="ptp-label">Display Name</label>
                        <input type="text" name="display_name" class="ptp-input" value="<?php echo esc_attr($trainer->display_name ?? ''); ?>">
                    </div>
                    <div class="ptp-form-group">
                        <label class="ptp-label">Bio</label>
                        <textarea name="bio" class="ptp-textarea"><?php echo esc_textarea($trainer->bio ?? ''); ?></textarea>
                    </div>
                    <div class="ptp-form-group">
                        <label class="ptp-label">Hourly Rate ($)</label>
                        <input type="number" name="hourly_rate" class="ptp-input" value="<?php echo esc_attr($trainer->hourly_rate ?? 50); ?>">
                    </div>
                </div>
            </div>
            <button type="submit" class="ptp-btn ptp-btn-primary">Save Profile</button>
        </form>
        <?php
    }
    
    private function render_messages_panel($user_id) {
        $conversations = PTP_Messaging_V71::get_conversations_for_user($user_id);
        ?>
        <div class="ptp-messages-container">
            <div class="ptp-conversations-panel">
                <div class="ptp-conversations-header"><h3>Messages</h3></div>
                <div class="ptp-conversations-list">
                    <?php if (empty($conversations)): ?>
                        <div class="ptp-empty-state"><span class="ptp-empty-icon">💬</span><p>No messages</p></div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conv): ?>
                            <div class="ptp-conversation-item <?php echo $conv->unread_count > 0 ? 'has-unread' : ''; ?>" data-conversation-id="<?php echo $conv->id; ?>">
                                <div class="ptp-conversation-avatar">
                                    <span class="ptp-avatar-placeholder"><?php echo substr($conv->other_name, 0, 1); ?></span>
                                </div>
                                <div class="ptp-conversation-info">
                                    <div class="ptp-conversation-name"><?php echo esc_html($conv->other_name); ?></div>
                                    <div class="ptp-conversation-preview"><?php echo esc_html($conv->last_message); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ptp-chat-panel">
                <div class="ptp-chat-placeholder"><span class="ptp-chat-placeholder-icon">💬</span><p>Select a conversation</p></div>
                <div class="ptp-chat-active" style="display:none;">
                    <div class="ptp-chat-header">
                        <button class="ptp-mobile-back"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg></button>
                        <div class="ptp-chat-name"></div>
                    </div>
                    <div class="ptp-messages-area"><div class="ptp-messages-list"></div></div>
                    <div class="ptp-message-input-area">
                        <div class="ptp-message-form">
                            <textarea class="ptp-message-input" placeholder="Type a message..."></textarea>
                            <button class="ptp-send-btn"><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function render_parent_overview($parent, $user_id) {
        $unread = PTP_Messaging_V71::get_unread_count($user_id);
        
        $orders = PTP_Native_Order_Manager::get_orders(array('customer_id' => $user_id, 'limit' => -1, 'status' => array('completed', 'processing')));
        
        ?>
        <div class="ptp-stats-grid">
            <div class="ptp-stat-card"><div class="ptp-stat-value"><?php echo count($orders); ?></div><div class="ptp-stat-label">Orders</div></div>
            <div class="ptp-stat-card"><div class="ptp-stat-value"><?php echo $unread; ?></div><div class="ptp-stat-label">Messages</div></div>
        </div>
        <h3>Recent Orders</h3>
        <?php if (empty($orders)): ?>
            <div class="ptp-empty-state"><span class="ptp-empty-icon">📦</span><p>No orders yet</p><a href="<?php echo home_url('/ptp-find-a-camp/'); ?>" class="ptp-btn ptp-btn-primary">Browse Camps</a></div>
        <?php endif;
    }
    
    private function render_parent_sessions($user_id) {
        echo '<p>Sessions coming soon...</p>';
    }
    
    private function render_parent_players($user_id) {
        global $wpdb;
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_user_id = %d",
            $user_id
        ));
        
        ?>
        <div style="display:flex;justify-content:space-between;margin-bottom:20px;">
            <h2>My Players</h2>
            <button class="ptp-btn ptp-btn-primary ptp-add-player-btn">+ Add Player</button>
        </div>
        <?php if (empty($players)): ?>
            <div class="ptp-empty-state"><span class="ptp-empty-icon">⚽</span><p>No players yet</p></div>
        <?php else: ?>
            <div class="ptp-players-grid">
                <?php foreach ($players as $player): ?>
                    <div class="ptp-player-card">
                        <div class="ptp-player-avatar"><?php echo strtoupper(substr($player->first_name, 0, 1)); ?></div>
                        <div class="ptp-player-info">
                            <h4><?php echo esc_html($player->first_name . ' ' . $player->last_name); ?></h4>
                            <div class="ptp-player-details">Age <?php echo $player->age; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;
    }
    
    // ==========================================
    // PROFILE ENDPOINTS
    // ==========================================
    
    public function save_trainer_profile() {
        $this->verify_nonce();
        $this->require_login();
        
        $user_id = get_current_user_id();
        
        global $wpdb;
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Not found'));
        }
        
        $data = array();
        if (isset($_POST['display_name'])) $data['display_name'] = sanitize_text_field($_POST['display_name']);
        if (isset($_POST['bio'])) $data['bio'] = sanitize_textarea_field($_POST['bio']);
        if (isset($_POST['hourly_rate'])) $data['hourly_rate'] = floatval($_POST['hourly_rate']);
        if (isset($_POST['headline'])) $data['headline'] = sanitize_text_field($_POST['headline']);
        // v134: Support all onboarding fields from dashboard
        if (isset($_POST['coaching_why'])) $data['coaching_why'] = sanitize_textarea_field($_POST['coaching_why']);
        if (isset($_POST['training_philosophy'])) $data['training_philosophy'] = sanitize_textarea_field($_POST['training_philosophy']);
        if (isset($_POST['team'])) $data['team'] = sanitize_text_field($_POST['team']);
        if (isset($_POST['playing_level'])) $data['playing_level'] = sanitize_text_field($_POST['playing_level']);
        if (isset($_POST['specialties'])) $data['specialties'] = sanitize_text_field($_POST['specialties']);
        if (isset($_POST['experience_years'])) $data['experience_years'] = intval($_POST['experience_years']);
        if (isset($_POST['years_coaching'])) $data['years_coaching'] = intval($_POST['years_coaching']);
        if (isset($_POST['travel_radius'])) $data['travel_radius'] = intval($_POST['travel_radius']);
        if (isset($_POST['city'])) $data['city'] = sanitize_text_field($_POST['city']);
        if (isset($_POST['state'])) $data['state'] = sanitize_text_field($_POST['state']);
        // Auto-compose location from city + state
        if (!empty($data['city']) && !empty($data['state'])) {
            $data['location'] = $data['city'] . ', ' . $data['state'];
        }
        
        if (!empty($data)) {
            $wpdb->update($wpdb->prefix . 'ptp_trainers', $data, array('id' => $trainer_id));
        }
        
        // v211: Save training locations from dashboard (structured JSON)
        if (isset($_POST['training_locations_json'])) {
            $locations = json_decode(stripslashes($_POST['training_locations_json']), true);
            if (is_array($locations)) {
                $sanitized = array();
                foreach ($locations as $loc) {
                    if (is_string($loc)) {
                        $sanitized[] = array('name' => sanitize_text_field($loc), 'address' => sanitize_text_field($loc), 'lat' => null, 'lng' => null, 'place_id' => '');
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
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    array('training_locations' => wp_json_encode($sanitized)),
                    array('id' => $trainer_id),
                    array('%s'),
                    array('%d')
                );
            }
        }
        
        wp_send_json_success();
    }
    
    /**
     * AJAX: Trainer confirms a completed session
     * Called from trainer dashboard confirm banner
     */
    public function confirm_session() {
        $this->verify_nonce();
        $this->require_login();
        
        $booking_id = absint($_POST['booking_id'] ?? 0);
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid session'));
        }
        
        try {
        
        $user_id = get_current_user_id();
        global $wpdb;
        
        // Verify this booking belongs to the logged-in trainer
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d AND trainer_id = %d",
            $booking_id, $trainer_id
        ));
        
        if (!$booking) {
            wp_send_json_error(array('message' => 'Session not found'));
        }
        
        // Use PTP_Booking if available, otherwise direct update
        if (class_exists('PTP_Booking') && method_exists('PTP_Booking', 'confirm_by_trainer')) {
            try {
                $result = PTP_Booking::confirm_by_trainer($booking_id, $trainer_id);
                if (is_wp_error($result)) {
                    wp_send_json_error(array('message' => $result->get_error_message()));
                }
            } catch (\Throwable $hook_e) {
                // If confirm_by_trainer crashes (e.g. in check_completion hooks), fall back to direct update
                ptp_log('PTP Ajax: confirm_by_trainer error for booking ' . $booking_id . ': ' . $hook_e->getMessage());
                $wpdb->update(
                    $wpdb->prefix . 'ptp_bookings',
                    array(
                        'trainer_confirmed' => 1,
                        'trainer_confirmed_at' => current_time('mysql'),
                    ),
                    array('id' => $booking_id),
                    array('%d', '%s'),
                    array('%d')
                );
            }
        } else {
            // Direct fallback: mark trainer confirmed
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'trainer_confirmed' => 1,
                    'trainer_confirmed_at' => current_time('mysql'),
                ),
                array('id' => $booking_id),
                array('%d', '%s'),
                array('%d')
            );
        }
        
        wp_send_json_success(array('message' => 'Session confirmed'));
        
        } catch (\Throwable $e) {
            ptp_log('PTP Ajax confirm_session fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(array('message' => 'Server error: ' . $e->getMessage()));
        }
    }
    
    // ==========================================
    // PLAYER ENDPOINTS
    // ==========================================
    
    public function get_players() {
        $this->verify_nonce();
        $this->require_login();
        
        global $wpdb;
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_user_id = %d",
            get_current_user_id()
        ));
        
        wp_send_json_success(array('players' => $players));
    }
    
    public function save_player() {
        $this->verify_nonce();
        $this->require_login();
        
        $user_id = get_current_user_id();
        $player_id = absint($_POST['player_id'] ?? 0);
        
        $data = array(
            'parent_user_id' => $user_id,
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name' => sanitize_text_field($_POST['last_name']),
            'age' => absint($_POST['age']),
        );
        
        global $wpdb;
        
        if ($player_id) {
            $wpdb->update($wpdb->prefix . 'ptp_players', $data, array('id' => $player_id, 'parent_user_id' => $user_id));
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($wpdb->prefix . 'ptp_players', $data);
            $player_id = $wpdb->insert_id;
        }
        
        wp_send_json_success(array('player_id' => $player_id));
    }
    
    public function delete_player() {
        $this->verify_nonce();
        $this->require_login();
        
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'ptp_players', array(
            'id' => absint($_POST['player_id']),
            'parent_user_id' => get_current_user_id()
        ));
        
        wp_send_json_success();
    }
}

new PTP_Ajax_V71();
