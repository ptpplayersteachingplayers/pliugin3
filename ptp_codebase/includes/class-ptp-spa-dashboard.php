<?php
/**
 * PTP SPA Dashboard Handler
 * Enables single-page application style dashboards with no page reloads
 * 
 * @since 71.0.0
 */

defined('ABSPATH') || exit;

class PTP_SPA_Dashboard {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // AJAX handlers for tab loading
        add_action('wp_ajax_ptp_load_trainer_tab', array($this, 'load_trainer_tab'));
        add_action('wp_ajax_ptp_load_parent_tab', array($this, 'load_parent_tab'));
        
        // Dashboard stats endpoints
        add_action('wp_ajax_ptp_get_trainer_stats', array($this, 'get_trainer_stats'));
        add_action('wp_ajax_ptp_get_parent_stats', array($this, 'get_parent_stats'));
        
        // Real-time updates
        add_action('wp_ajax_ptp_get_notifications', array($this, 'get_notifications'));
    }
    
    /**
     * Load trainer dashboard tab content
     */
    public function load_trainer_tab() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $tab = sanitize_text_field($_POST['tab'] ?? 'overview');
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        ob_start();
        
        switch ($tab) {
            case 'overview':
                $this->render_trainer_overview($trainer);
                break;
                
            case 'schedule':
                $this->render_trainer_schedule($trainer);
                break;
                
            case 'bookings':
                $this->render_trainer_bookings($trainer);
                break;
                
            case 'earnings':
                $this->render_trainer_earnings($trainer);
                break;
                
            case 'messages':
                $this->render_trainer_messages($trainer);
                break;
                
            case 'profile':
                $this->render_trainer_profile($trainer);
                break;
                
            case 'settings':
                $this->render_trainer_settings($trainer);
                break;
                
            default:
                $this->render_trainer_overview($trainer);
        }
        
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'tab' => $tab
        ));
    }
    
    /**
     * Load parent dashboard tab content
     */
    public function load_parent_tab() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $tab = sanitize_text_field($_POST['tab'] ?? 'overview');
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));
        
        // Create parent record if doesn't exist
        if (!$parent) {
            $user = wp_get_current_user();
            $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
                'user_id' => get_current_user_id(),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
                'email' => $user->user_email
            ));
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                get_current_user_id()
            ));
        }
        
        ob_start();
        
        switch ($tab) {
            case 'overview':
                $this->render_parent_overview($parent);
                break;
                
            case 'players':
                $this->render_parent_players($parent);
                break;
                
            case 'bookings':
                $this->render_parent_bookings($parent);
                break;
                
            case 'messages':
                $this->render_parent_messages($parent);
                break;
                
            case 'settings':
                $this->render_parent_settings($parent);
                break;
                
            default:
                $this->render_parent_overview($parent);
        }
        
        $html = ob_get_clean();
        
        wp_send_json_success(array(
            'html' => $html,
            'tab' => $tab
        ));
    }
    
    /**
     * Get trainer stats for dashboard
     */
    public function get_trainer_stats() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        $stats = array(
            'upcoming_sessions' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND session_date >= CURDATE() AND status IN ('confirmed', 'pending')",
                $trainer->id
            )),
            'this_week' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status IN ('confirmed', 'pending')",
                $trainer->id
            )),
            'total_sessions' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND status = 'completed'",
                $trainer->id
            )),
            'total_earnings' => $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND status = 'completed'",
                $trainer->id
            )),
            'pending_payout' => $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
                $trainer->id
            )),
            'unread_messages' => $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(trainer_unread_count), 0) FROM {$wpdb->prefix}ptp_conversations 
                 WHERE trainer_id = %d",
                $trainer->id
            ))
        );
        
        wp_send_json_success($stats);
    }
    
    /**
     * Get parent stats for dashboard
     */
    public function get_parent_stats() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        global $wpdb;
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$parent) {
            wp_send_json_success(array(
                'upcoming_sessions' => 0,
                'total_players' => 0,
                'total_sessions' => 0,
                'unread_messages' => 0
            ));
            return;
        }
        
        $stats = array(
            'upcoming_sessions' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE parent_id = %d AND session_date >= CURDATE() AND status IN ('confirmed', 'pending')",
                $parent->id
            )),
            'total_players' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d",
                $parent->id
            )),
            'total_sessions' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE parent_id = %d AND status = 'completed'",
                $parent->id
            )),
            'unread_messages' => $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(parent_unread_count), 0) FROM {$wpdb->prefix}ptp_conversations 
                 WHERE parent_id = %d",
                $parent->id
            ))
        );
        
        wp_send_json_success($stats);
    }
    
    /**
     * Get notifications
     */
    public function get_notifications() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_success(array('notifications' => array()));
        }
        
        $notifications = array();
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        // Check for unread messages
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if ($trainer) {
            $unread = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(trainer_unread_count) FROM {$wpdb->prefix}ptp_conversations WHERE trainer_id = %d",
                $trainer->id
            ));
            
            if ($unread > 0) {
                $notifications[] = array(
                    'type' => 'message',
                    'count' => intval($unread),
                    'text' => $unread . ' unread message' . ($unread > 1 ? 's' : '')
                );
            }
            
            // Check for upcoming sessions today
            $today_sessions = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND session_date = CURDATE() AND status = 'confirmed'",
                $trainer->id
            ));
            
            if ($today_sessions > 0) {
                $notifications[] = array(
                    'type' => 'session',
                    'count' => intval($today_sessions),
                    'text' => $today_sessions . ' session' . ($today_sessions > 1 ? 's' : '') . ' today'
                );
            }
        }
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if ($parent) {
            $unread = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(parent_unread_count) FROM {$wpdb->prefix}ptp_conversations WHERE parent_id = %d",
                $parent->id
            ));
            
            if ($unread > 0) {
                $notifications[] = array(
                    'type' => 'message',
                    'count' => intval($unread),
                    'text' => $unread . ' unread message' . ($unread > 1 ? 's' : '')
                );
            }
        }
        
        wp_send_json_success(array('notifications' => $notifications));
    }
    
    // ==================================================
    // TRAINER TAB RENDERS
    // ==================================================
    
    private function render_trainer_overview($trainer) {
        global $wpdb;
        
        $upcoming = $wpdb->get_results($wpdb->prepare("
            SELECT b.*, p.name as player_name, par.display_name as parent_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents par ON b.parent_id = par.id
            WHERE b.trainer_id = %d AND b.session_date >= CURDATE()
            ORDER BY b.session_date ASC, b.start_time ASC
            LIMIT 5
        ", $trainer->id));
        
        $stats = array(
            'this_week' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
                $trainer->id
            )),
            'earnings_month' => $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
                 WHERE trainer_id = %d AND status = 'completed'
                 AND MONTH(session_date) = MONTH(CURDATE()) AND YEAR(session_date) = YEAR(CURDATE())",
                $trainer->id
            ))
        );
        
        ?>
        <div class="trainer-overview">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">THIS WEEK</div>
                    <div class="stat-value"><?php echo intval($stats['this_week']); ?></div>
                    <div class="stat-sublabel">sessions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">EARNINGS (MONTH)</div>
                    <div class="stat-value">$<?php echo number_format($stats['earnings_month'], 0); ?></div>
                </div>
            </div>
            
            <h3>UPCOMING SESSIONS</h3>
            <?php if (empty($upcoming)) : ?>
                <div class="empty-state">
                    <p>No upcoming sessions scheduled</p>
                </div>
            <?php else : ?>
                <div class="sessions-list">
                    <?php foreach ($upcoming as $session) : ?>
                        <div class="session-card">
                            <div class="session-date">
                                <span class="day"><?php echo date('D', strtotime($session->session_date)); ?></span>
                                <span class="date"><?php echo date('M j', strtotime($session->session_date)); ?></span>
                            </div>
                            <div class="session-info">
                                <div class="player-name"><?php echo esc_html($session->player_name ?: 'Player'); ?></div>
                                <div class="session-time"><?php echo date('g:i A', strtotime($session->start_time)); ?></div>
                                <div class="parent-name">Parent: <?php echo esc_html($session->parent_name); ?></div>
                            </div>
                            <div class="session-status status-<?php echo esc_attr($session->status); ?>">
                                <?php echo esc_html(ucfirst($session->status)); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_trainer_schedule($trainer) {
        include PTP_PLUGIN_DIR . 'templates/components/schedule-calendar.php';
    }
    
    private function render_trainer_bookings($trainer) {
        global $wpdb;
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT b.*, p.name as player_name, par.display_name as parent_name, par.phone as parent_phone
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents par ON b.parent_id = par.id
            WHERE b.trainer_id = %d
            ORDER BY b.session_date DESC, b.start_time DESC
            LIMIT 20
        ", $trainer->id));
        
        ?>
        <div class="trainer-bookings">
            <h3>YOUR BOOKINGS</h3>
            <?php if (empty($bookings)) : ?>
                <div class="empty-state">
                    <p>No bookings yet</p>
                </div>
            <?php else : ?>
                <div class="bookings-list">
                    <?php foreach ($bookings as $booking) : ?>
                        <div class="booking-card">
                            <div class="booking-date">
                                <?php echo date('M j, Y', strtotime($booking->session_date)); ?>
                                <span class="booking-time"><?php echo date('g:i A', strtotime($booking->start_time)); ?></span>
                            </div>
                            <div class="booking-info">
                                <div class="player"><?php echo esc_html($booking->player_name ?: 'Player'); ?></div>
                                <div class="parent"><?php echo esc_html($booking->parent_name); ?></div>
                            </div>
                            <div class="booking-price">$<?php echo number_format($booking->trainer_payout ?: $booking->price * 0.75, 2); ?></div>
                            <div class="booking-status status-<?php echo esc_attr($booking->status); ?>">
                                <?php echo esc_html(ucfirst($booking->status)); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_trainer_earnings($trainer) {
        include PTP_PLUGIN_DIR . 'templates/components/trainer-earnings.php';
    }
    
    private function render_trainer_messages($trainer) {
        ?>
        <div class="trainer-messages">
            <p>Loading messages...</p>
            <script>window.location.href = '<?php echo home_url('/messages/'); ?>';</script>
        </div>
        <?php
    }
    
    private function render_trainer_profile($trainer) {
        ?>
        <div class="trainer-profile-edit">
            <h3>EDIT PROFILE</h3>
            <form id="trainer-profile-form" class="ptp-form">
                <div class="form-group">
                    <label>DISPLAY NAME</label>
                    <input type="text" name="display_name" value="<?php echo esc_attr($trainer->display_name); ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>FIRST NAME</label>
                        <input type="text" name="first_name" value="<?php echo esc_attr($trainer->first_name); ?>">
                    </div>
                    <div class="form-group">
                        <label>LAST NAME</label>
                        <input type="text" name="last_name" value="<?php echo esc_attr($trainer->last_name); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>PHONE</label>
                    <input type="tel" name="phone" value="<?php echo esc_attr($trainer->phone); ?>">
                </div>
                <div class="form-group">
                    <label>BIO</label>
                    <textarea name="bio" rows="4"><?php echo esc_textarea($trainer->bio); ?></textarea>
                </div>
                <div class="form-group">
                    <label>HOURLY RATE ($)</label>
                    <input type="number" name="hourly_rate" value="<?php echo esc_attr($trainer->hourly_rate); ?>" min="25" step="5">
                </div>
                <div class="form-group">
                    <label>SPECIALTY</label>
                    <input type="text" name="specialty" value="<?php echo esc_attr($trainer->specialty); ?>" placeholder="e.g., Shooting, Dribbling, Goalkeeping">
                </div>
                <button type="submit" class="btn btn-primary">SAVE PROFILE</button>
            </form>
        </div>
        <?php
    }
    
    private function render_trainer_settings($trainer) {
        ?>
        <div class="trainer-settings">
            <h3>SETTINGS</h3>
            
            <div class="settings-section">
                <h4>Notifications</h4>
                <label class="toggle-setting">
                    <input type="checkbox" name="sms_notifications" checked>
                    <span>SMS notifications for new bookings</span>
                </label>
                <label class="toggle-setting">
                    <input type="checkbox" name="email_notifications" checked>
                    <span>Email notifications</span>
                </label>
            </div>
            
            <div class="settings-section">
                <h4>Calendar</h4>
                <div id="google-calendar-status">
                    <button type="button" class="btn btn-outline" id="connect-gcal">
                        Connect Google Calendar
                    </button>
                </div>
            </div>
            
            <div class="settings-section">
                <h4>Payments</h4>
                <div id="stripe-status">
                    <?php if ($trainer->stripe_account_id) : ?>
                        <p>✓ Stripe Connected</p>
                        <button type="button" class="btn btn-outline" id="stripe-dashboard">
                            Open Stripe Dashboard
                        </button>
                    <?php else : ?>
                        <button type="button" class="btn btn-primary" id="connect-stripe">
                            Connect Stripe to Get Paid
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    // ==================================================
    // PARENT TAB RENDERS
    // ==================================================
    
    private function render_parent_overview($parent) {
        global $wpdb;
        
        $upcoming = $wpdb->get_results($wpdb->prepare("
            SELECT b.*, t.display_name as trainer_name, p.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            WHERE b.parent_id = %d AND b.session_date >= CURDATE()
            ORDER BY b.session_date ASC, b.start_time ASC
            LIMIT 5
        ", $parent->id));
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d",
            $parent->id
        ));
        
        ?>
        <div class="parent-overview">
            <h3>UPCOMING SESSIONS</h3>
            <?php if (empty($upcoming)) : ?>
                <div class="empty-state">
                    <p>No upcoming sessions</p>
                    <a href="<?php echo home_url('/find-trainer/'); ?>" class="btn btn-primary">FIND A TRAINER</a>
                </div>
            <?php else : ?>
                <div class="sessions-list">
                    <?php foreach ($upcoming as $session) : ?>
                        <div class="session-card">
                            <div class="session-date">
                                <span class="day"><?php echo date('D', strtotime($session->session_date)); ?></span>
                                <span class="date"><?php echo date('M j', strtotime($session->session_date)); ?></span>
                            </div>
                            <div class="session-info">
                                <div class="player-name"><?php echo esc_html($session->player_name ?: 'Player'); ?></div>
                                <div class="trainer-name">with <?php echo esc_html($session->trainer_name); ?></div>
                                <div class="session-time"><?php echo date('g:i A', strtotime($session->start_time)); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <h3>YOUR PLAYERS</h3>
            <?php if (empty($players)) : ?>
                <div class="empty-state">
                    <p>Add your first player</p>
                    <button type="button" class="btn btn-outline" data-action="add-player">ADD PLAYER</button>
                </div>
            <?php else : ?>
                <div class="players-list">
                    <?php foreach ($players as $player) : ?>
                        <div class="player-card">
                            <div class="player-avatar"><?php echo strtoupper(substr($player->name, 0, 1)); ?></div>
                            <div class="player-info">
                                <div class="player-name"><?php echo esc_html($player->name); ?></div>
                                <div class="player-details">
                                    <?php if ($player->age) : ?>Age <?php echo intval($player->age); ?><?php endif; ?>
                                    <?php if ($player->team) : ?> • <?php echo esc_html($player->team); ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_parent_players($parent) {
        global $wpdb;
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d ORDER BY name ASC",
            $parent->id
        ));
        
        ?>
        <div class="parent-players">
            <div class="section-header">
                <h3>YOUR PLAYERS</h3>
                <button type="button" class="btn btn-primary" data-action="add-player">+ ADD PLAYER</button>
            </div>
            
            <?php if (empty($players)) : ?>
                <div class="empty-state">
                    <p>No players added yet. Add your first player to book training sessions.</p>
                </div>
            <?php else : ?>
                <div class="players-grid">
                    <?php foreach ($players as $player) : ?>
                        <div class="player-card-full" data-player-id="<?php echo intval($player->id); ?>">
                            <div class="player-avatar-lg"><?php echo strtoupper(substr($player->name, 0, 1)); ?></div>
                            <h4><?php echo esc_html($player->name); ?></h4>
                            <?php if ($player->age) : ?><p>Age: <?php echo intval($player->age); ?></p><?php endif; ?>
                            <?php if ($player->team) : ?><p>Team: <?php echo esc_html($player->team); ?></p><?php endif; ?>
                            <?php if ($player->skill_level) : ?><p>Level: <?php echo esc_html($player->skill_level); ?></p><?php endif; ?>
                            <div class="player-actions">
                                <button type="button" class="btn btn-sm btn-outline" data-action="edit-player">Edit</button>
                                <button type="button" class="btn btn-sm btn-danger" data-action="delete-player">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_parent_bookings($parent) {
        global $wpdb;
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, p.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            WHERE b.parent_id = %d
            ORDER BY b.session_date DESC, b.start_time DESC
            LIMIT 20
        ", $parent->id));
        
        ?>
        <div class="parent-bookings">
            <h3>YOUR BOOKINGS</h3>
            <?php if (empty($bookings)) : ?>
                <div class="empty-state">
                    <p>No bookings yet</p>
                    <a href="<?php echo home_url('/find-trainer/'); ?>" class="btn btn-primary">FIND A TRAINER</a>
                </div>
            <?php else : ?>
                <div class="bookings-list">
                    <?php foreach ($bookings as $booking) : ?>
                        <div class="booking-card">
                            <div class="booking-date">
                                <?php echo date('M j, Y', strtotime($booking->session_date)); ?>
                                <span class="booking-time"><?php echo date('g:i A', strtotime($booking->start_time)); ?></span>
                            </div>
                            <div class="booking-info">
                                <div class="player"><?php echo esc_html($booking->player_name ?: 'Player'); ?></div>
                                <div class="trainer">with <?php echo esc_html($booking->trainer_name); ?></div>
                            </div>
                            <div class="booking-price">$<?php echo number_format($booking->price, 2); ?></div>
                            <div class="booking-status status-<?php echo esc_attr($booking->status); ?>">
                                <?php echo esc_html(ucfirst($booking->status)); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_parent_messages($parent) {
        ?>
        <div class="parent-messages">
            <p>Loading messages...</p>
            <script>window.location.href = '<?php echo home_url('/messages/'); ?>';</script>
        </div>
        <?php
    }
    
    private function render_parent_settings($parent) {
        ?>
        <div class="parent-settings">
            <h3>ACCOUNT SETTINGS</h3>
            <form id="parent-settings-form" class="ptp-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>FIRST NAME</label>
                        <input type="text" name="first_name" value="<?php echo esc_attr($parent->first_name); ?>">
                    </div>
                    <div class="form-group">
                        <label>LAST NAME</label>
                        <input type="text" name="last_name" value="<?php echo esc_attr($parent->last_name); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>EMAIL</label>
                    <input type="email" name="email" value="<?php echo esc_attr($parent->email); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>PHONE</label>
                    <input type="tel" name="phone" value="<?php echo esc_attr($parent->phone); ?>">
                </div>
                <button type="submit" class="btn btn-primary">SAVE SETTINGS</button>
            </form>
        </div>
        <?php
    }
}

// Initialize
PTP_SPA_Dashboard::instance();
