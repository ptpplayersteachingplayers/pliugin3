<?php
/**
 * PTP App Control Center
 * Complete admin interface for managing the mobile app
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_App_Control {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'), 20);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ptp_send_push_notification', array($this, 'ajax_send_push'));
        add_action('wp_ajax_ptp_save_app_onboarding_screens', array($this, 'ajax_save_onboarding'));
        add_action('wp_ajax_ptp_upload_app_asset', array($this, 'ajax_upload_asset'));
    }
    
    public function add_menu() {
        // DISABLED - App Control moved to Settings page
        // add_submenu_page(
        //     'ptp-dashboard',
        //     'App Control',
        //     'App Control',
        //     'manage_options',
        //     'ptp-app-control',
        //     array($this, 'render_page')
        // );
        
        // DISABLED - Camps managed via PTP Native products
        // add_submenu_page(
        //     'ptp-dashboard',
        //     'Camps & Clinics',
        //     'Camps & Clinics',
        //     'manage_options',
        //     'ptp-camps',
        //     array($this, 'render_camps_page')
        // );
    }
    
    public function register_settings() {
        // Branding settings
        register_setting('ptp_app_branding', 'ptp_app_name');
        register_setting('ptp_app_branding', 'ptp_tagline');
        register_setting('ptp_app_branding', 'ptp_logo_url');
        register_setting('ptp_app_branding', 'ptp_logo_dark_url');
        register_setting('ptp_app_branding', 'ptp_app_icon_url');
        register_setting('ptp_app_branding', 'ptp_splash_url');
        register_setting('ptp_app_branding', 'ptp_color_primary');
        register_setting('ptp_app_branding', 'ptp_color_secondary');
        register_setting('ptp_app_branding', 'ptp_default_og_image');
        
        // Social links
        register_setting('ptp_app_branding', 'ptp_instagram');
        register_setting('ptp_app_branding', 'ptp_facebook');
        register_setting('ptp_app_branding', 'ptp_twitter');
        register_setting('ptp_app_branding', 'ptp_youtube');
        register_setting('ptp_app_branding', 'ptp_tiktok');
        
        // Contact info
        register_setting('ptp_app_branding', 'ptp_contact_email');
        register_setting('ptp_app_branding', 'ptp_support_email');
        register_setting('ptp_app_branding', 'ptp_contact_phone');
        
        // Feature flags
        register_setting('ptp_app_features', 'ptp_feature_groups');
        register_setting('ptp_app_features', 'ptp_feature_training_plans');
        register_setting('ptp_app_features', 'ptp_feature_recurring');
        register_setting('ptp_app_features', 'ptp_feature_calendar_sync');
        register_setting('ptp_app_features', 'ptp_push_enabled');
        register_setting('ptp_app_features', 'ptp_feature_camps');
        
        // Auth settings
        register_setting('ptp_app_features', 'ptp_google_client_id');
        register_setting('ptp_app_features', 'ptp_apple_client_id');
        register_setting('ptp_app_features', 'ptp_apple_team_id');
        
        // Google Maps
        register_setting('ptp_app_features', 'ptp_google_maps_key');
        
        // Version control
        register_setting('ptp_app_version', 'ptp_min_app_version');
        register_setting('ptp_app_version', 'ptp_current_app_version');
        register_setting('ptp_app_version', 'ptp_force_update');
        register_setting('ptp_app_version', 'ptp_update_message');
        register_setting('ptp_app_version', 'ptp_maintenance_mode');
        register_setting('ptp_app_version', 'ptp_maintenance_message');
        
        // Legal URLs
        register_setting('ptp_app_content', 'ptp_terms_url');
        register_setting('ptp_app_content', 'ptp_privacy_url');
        register_setting('ptp_app_content', 'ptp_refund_url');
        register_setting('ptp_app_content', 'ptp_trainer_agreement_url');
        register_setting('ptp_app_content', 'ptp_waiver_url');
        
        // Hero content
        register_setting('ptp_app_content', 'ptp_hero_title');
        register_setting('ptp_app_content', 'ptp_hero_subtitle');
        
        // Push notifications
        register_setting('ptp_app_features', 'ptp_fcm_server_key');
        register_setting('ptp_app_features', 'ptp_fcm_sender_id');
    }
    
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'ptp-app-control') === false && strpos($hook, 'ptp-camps') === false) {
            return;
        }
        
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        // Google Maps for camps location
        $maps_key = get_option('ptp_google_maps_api_key', '') ?: get_option('ptp_google_maps_key', '');
        if ($maps_key) {
            wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=' . $maps_key . '&libraries=places', array(), null, true);
        }
        
        wp_enqueue_style('ptp-app-control', PTP_PLUGIN_URL . 'admin/css/app-control.css', array(), PTP_VERSION);
        wp_enqueue_script('ptp-app-control', PTP_PLUGIN_URL . 'admin/js/app-control.js', array('jquery', 'wp-color-picker', 'jquery-ui-sortable'), PTP_VERSION, true);
        
        wp_localize_script('ptp-app-control', 'ptpAppControl', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_app_control'),
            'mapsKey' => $maps_key,
        ));
    }
    
    public function render_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'branding';
        ?>
        <div class="wrap ptp-app-control">
            <h1>
                <span class="dashicons dashicons-smartphone"></span>
                App Control Center
            </h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=ptp-app-control&tab=branding" class="nav-tab <?php echo $active_tab === 'branding' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-customizer"></span> Branding
                </a>
                <a href="?page=ptp-app-control&tab=features" class="nav-tab <?php echo $active_tab === 'features' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-plugins"></span> Features
                </a>
                <a href="?page=ptp-app-control&tab=onboarding" class="nav-tab <?php echo $active_tab === 'onboarding' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-welcome-learn-more"></span> Onboarding
                </a>
                <a href="?page=ptp-app-control&tab=version" class="nav-tab <?php echo $active_tab === 'version' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-update"></span> Version Control
                </a>
                <a href="?page=ptp-app-control&tab=content" class="nav-tab <?php echo $active_tab === 'content' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-edit"></span> Content
                </a>
                <a href="?page=ptp-app-control&tab=push" class="nav-tab <?php echo $active_tab === 'push' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-megaphone"></span> Push Notifications
                </a>
                <a href="?page=ptp-app-control&tab=maps" class="nav-tab <?php echo $active_tab === 'maps' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-location"></span> Maps
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'branding':
                        $this->render_branding_tab();
                        break;
                    case 'features':
                        $this->render_features_tab();
                        break;
                    case 'onboarding':
                        $this->render_onboarding_tab();
                        break;
                    case 'version':
                        $this->render_version_tab();
                        break;
                    case 'content':
                        $this->render_content_tab();
                        break;
                    case 'push':
                        $this->render_push_tab();
                        break;
                    case 'maps':
                        $this->render_maps_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    private function render_branding_tab() {
        ?>
        <form method="post" action="options.php" class="ptp-form">
            <?php settings_fields('ptp_app_branding'); ?>
            
            <div class="ptp-card">
                <h2>App Identity</h2>
                
                <table class="form-table">
                    <tr>
                        <th>App Name</th>
                        <td>
                            <input type="text" name="ptp_app_name" value="<?php echo esc_attr(get_option('ptp_app_name', 'PTP Training')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Tagline</th>
                        <td>
                            <input type="text" name="ptp_tagline" value="<?php echo esc_attr(get_option('ptp_tagline', 'Players Teaching Players')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ptp-card">
                <h2>Logos & Icons</h2>
                
                <div class="ptp-asset-grid">
                    <div class="ptp-asset-item">
                        <label>Logo (Light Background)</label>
                        <div class="ptp-asset-preview">
                            <img src="<?php echo esc_url(get_option('ptp_logo_url', PTP_Images::LOGO)); ?>" id="logo-preview">
                        </div>
                        <input type="hidden" name="ptp_logo_url" id="ptp_logo_url" value="<?php echo esc_attr(get_option('ptp_logo_url')); ?>">
                        <button type="button" class="button ptp-upload-btn" data-target="ptp_logo_url" data-preview="logo-preview">Upload Logo</button>
                    </div>
                    
                    <div class="ptp-asset-item">
                        <label>Logo (Dark Background)</label>
                        <div class="ptp-asset-preview dark">
                            <img src="<?php echo esc_url(get_option('ptp_logo_dark_url', PTP_Images::LOGO)); ?>" id="logo-dark-preview">
                        </div>
                        <input type="hidden" name="ptp_logo_dark_url" id="ptp_logo_dark_url" value="<?php echo esc_attr(get_option('ptp_logo_dark_url')); ?>">
                        <button type="button" class="button ptp-upload-btn" data-target="ptp_logo_dark_url" data-preview="logo-dark-preview">Upload Logo</button>
                    </div>
                    
                    <div class="ptp-asset-item">
                        <label>App Icon (1024x1024)</label>
                        <div class="ptp-asset-preview square">
                            <img src="<?php echo esc_url(get_option('ptp_app_icon_url') ?: 'https://via.placeholder.com/200?text=App+Icon'); ?>" id="icon-preview">
                        </div>
                        <input type="hidden" name="ptp_app_icon_url" id="ptp_app_icon_url" value="<?php echo esc_attr(get_option('ptp_app_icon_url')); ?>">
                        <button type="button" class="button ptp-upload-btn" data-target="ptp_app_icon_url" data-preview="icon-preview">Upload Icon</button>
                    </div>
                    
                    <div class="ptp-asset-item">
                        <label>Splash Screen</label>
                        <div class="ptp-asset-preview tall">
                            <img src="<?php echo esc_url(get_option('ptp_splash_url') ?: PTP_Images::get('BG7A1915')); ?>" id="splash-preview">
                        </div>
                        <input type="hidden" name="ptp_splash_url" id="ptp_splash_url" value="<?php echo esc_attr(get_option('ptp_splash_url')); ?>">
                        <button type="button" class="button ptp-upload-btn" data-target="ptp_splash_url" data-preview="splash-preview">Upload Image</button>
                    </div>
                </div>
            </div>
            
            <div class="ptp-card">
                <h2>Brand Colors</h2>
                
                <div class="ptp-color-grid">
                    <div class="ptp-color-item">
                        <label>Primary Color (Gold)</label>
                        <input type="text" name="ptp_color_primary" value="<?php echo esc_attr(get_option('ptp_color_primary', '#FCB900')); ?>" class="ptp-color-picker">
                    </div>
                    
                    <div class="ptp-color-item">
                        <label>Secondary Color (Black)</label>
                        <input type="text" name="ptp_color_secondary" value="<?php echo esc_attr(get_option('ptp_color_secondary', '#0A0A0A')); ?>" class="ptp-color-picker">
                    </div>
                </div>
                
                <div class="ptp-color-preview" id="color-preview">
                    <div class="preview-button primary">Book Session</div>
                    <div class="preview-button secondary">Learn More</div>
                    <div class="preview-card">
                        <div class="preview-header">Sample Card</div>
                        <div class="preview-body">This is how your brand colors will look in the app.</div>
                    </div>
                </div>
            </div>
            
            <div class="ptp-card">
                <h2>Social Links</h2>
                
                <table class="form-table">
                    <tr>
                        <th><span class="dashicons dashicons-instagram"></span> Instagram</th>
                        <td>
                            <input type="text" name="ptp_instagram" value="<?php echo esc_attr(get_option('ptp_instagram')); ?>" class="regular-text" placeholder="username (without @)">
                        </td>
                    </tr>
                    <tr>
                        <th><span class="dashicons dashicons-facebook"></span> Facebook</th>
                        <td>
                            <input type="text" name="ptp_facebook" value="<?php echo esc_attr(get_option('ptp_facebook')); ?>" class="regular-text" placeholder="page name or URL">
                        </td>
                    </tr>
                    <tr>
                        <th><span class="dashicons dashicons-twitter"></span> Twitter/X</th>
                        <td>
                            <input type="text" name="ptp_twitter" value="<?php echo esc_attr(get_option('ptp_twitter')); ?>" class="regular-text" placeholder="username (without @)">
                        </td>
                    </tr>
                    <tr>
                        <th><span class="dashicons dashicons-youtube"></span> YouTube</th>
                        <td>
                            <input type="text" name="ptp_youtube" value="<?php echo esc_attr(get_option('ptp_youtube')); ?>" class="regular-text" placeholder="channel URL">
                        </td>
                    </tr>
                    <tr>
                        <th>TikTok</th>
                        <td>
                            <input type="text" name="ptp_tiktok" value="<?php echo esc_attr(get_option('ptp_tiktok')); ?>" class="regular-text" placeholder="username (without @)">
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ptp-card">
                <h2>Contact Information</h2>
                
                <table class="form-table">
                    <tr>
                        <th>Contact Email</th>
                        <td>
                            <input type="email" name="ptp_contact_email" value="<?php echo esc_attr(get_option('ptp_contact_email', 'info@ptptraining.com')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Support Email</th>
                        <td>
                            <input type="email" name="ptp_support_email" value="<?php echo esc_attr(get_option('ptp_support_email', 'support@ptptraining.com')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Phone Number</th>
                        <td>
                            <input type="text" name="ptp_contact_phone" value="<?php echo esc_attr(get_option('ptp_contact_phone')); ?>" class="regular-text" placeholder="(555) 123-4567">
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php submit_button('Save Branding Settings'); ?>
        </form>
        <?php
    }
    
    private function render_features_tab() {
        ?>
        <form method="post" action="options.php" class="ptp-form">
            <?php settings_fields('ptp_app_features'); ?>
            
            <div class="ptp-card">
                <h2>Core Features</h2>
                <p class="description">Toggle features on/off for your mobile app</p>
                
                <div class="ptp-feature-grid">
                    <label class="ptp-feature-toggle">
                        <input type="checkbox" name="ptp_feature_groups" value="1" <?php checked(get_option('ptp_feature_groups', 1)); ?>>
                        <span class="toggle-switch"></span>
                        <span class="toggle-label">
                            <strong>Group Sessions</strong>
                            <small>Allow trainers to host 2-4 player sessions</small>
                        </span>
                    </label>
                    
                    <label class="ptp-feature-toggle">
                        <input type="checkbox" name="ptp_feature_training_plans" value="1" <?php checked(get_option('ptp_feature_training_plans', 1)); ?>>
                        <span class="toggle-switch"></span>
                        <span class="toggle-label">
                            <strong>Training Plans</strong>
                            <small>Multi-session training plans with milestones</small>
                        </span>
                    </label>
                    
                    <label class="ptp-feature-toggle">
                        <input type="checkbox" name="ptp_feature_recurring" value="1" <?php checked(get_option('ptp_feature_recurring', 1)); ?>>
                        <span class="toggle-switch"></span>
                        <span class="toggle-label">
                            <strong>Recurring Sessions</strong>
                            <small>Book weekly recurring training sessions</small>
                        </span>
                    </label>
                    
                    <label class="ptp-feature-toggle">
                        <input type="checkbox" name="ptp_feature_calendar_sync" value="1" <?php checked(get_option('ptp_feature_calendar_sync')); ?>>
                        <span class="toggle-switch"></span>
                        <span class="toggle-label">
                            <strong>Calendar Sync</strong>
                            <small>Sync with Google/Apple calendars</small>
                        </span>
                    </label>
                    
                    <label class="ptp-feature-toggle">
                        <input type="checkbox" name="ptp_feature_camps" value="1" <?php checked(get_option('ptp_feature_camps', 1)); ?>>
                        <span class="toggle-switch"></span>
                        <span class="toggle-label">
                            <strong>Camps & Clinics</strong>
                            <small>Show PTP Native camps in the app</small>
                        </span>
                    </label>
                    
                    <label class="ptp-feature-toggle">
                        <input type="checkbox" name="ptp_push_enabled" value="1" <?php checked(get_option('ptp_push_enabled')); ?>>
                        <span class="toggle-switch"></span>
                        <span class="toggle-label">
                            <strong>Push Notifications</strong>
                            <small>Send push notifications via Firebase</small>
                        </span>
                    </label>
                </div>
            </div>
            
            <div class="ptp-card">
                <h2>Social Login</h2>
                <p class="description">Enable Google and Apple Sign-In for faster registration</p>
                
                <table class="form-table">
                    <tr>
                        <th>
                            <img src="https://www.google.com/favicon.ico" style="width:16px;vertical-align:middle;margin-right:5px;">
                            Google Client ID
                        </th>
                        <td>
                            <input type="text" name="ptp_google_client_id" value="<?php echo esc_attr(get_option('ptp_google_client_id')); ?>" class="large-text" placeholder="xxxxx.apps.googleusercontent.com">
                            <p class="description">
                                Get this from <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <span class="dashicons dashicons-apple"></span>
                            Apple Client ID
                        </th>
                        <td>
                            <input type="text" name="ptp_apple_client_id" value="<?php echo esc_attr(get_option('ptp_apple_client_id')); ?>" class="large-text" placeholder="com.yourapp.bundleid">
                            <p class="description">
                                Configure in <a href="https://developer.apple.com/account/resources/identifiers" target="_blank">Apple Developer Portal</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Apple Team ID</th>
                        <td>
                            <input type="text" name="ptp_apple_team_id" value="<?php echo esc_attr(get_option('ptp_apple_team_id')); ?>" class="regular-text" placeholder="XXXXXXXXXX">
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ptp-card">
                <h2>Firebase Push Notifications</h2>
                
                <table class="form-table">
                    <tr>
                        <th>FCM Server Key</th>
                        <td>
                            <input type="password" name="ptp_fcm_server_key" value="<?php echo esc_attr(get_option('ptp_fcm_server_key')); ?>" class="large-text">
                            <p class="description">
                                Get this from <a href="https://console.firebase.google.com" target="_blank">Firebase Console</a> → Project Settings → Cloud Messaging
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>FCM Sender ID</th>
                        <td>
                            <input type="text" name="ptp_fcm_sender_id" value="<?php echo esc_attr(get_option('ptp_fcm_sender_id')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php submit_button('Save Feature Settings'); ?>
        </form>
        <?php
    }
    
    private function render_onboarding_tab() {
        $screens = get_option('ptp_onboarding_screens', $this->get_default_onboarding());
        ?>
        <div class="ptp-card">
            <h2>Onboarding Screens</h2>
            <p class="description">Customize the screens users see when they first open the app. Drag to reorder.</p>
            
            <div id="onboarding-screens" class="ptp-onboarding-list">
                <?php foreach ($screens as $i => $screen): ?>
                <div class="ptp-onboarding-screen" data-index="<?php echo $i; ?>">
                    <div class="screen-handle">
                        <span class="dashicons dashicons-menu"></span>
                    </div>
                    <div class="screen-preview">
                        <img src="<?php echo esc_url($screen['image']); ?>" class="screen-image">
                    </div>
                    <div class="screen-fields">
                        <input type="text" class="screen-title" placeholder="Title" value="<?php echo esc_attr($screen['title']); ?>">
                        <textarea class="screen-subtitle" placeholder="Subtitle"><?php echo esc_textarea($screen['subtitle']); ?></textarea>
                        <input type="text" class="screen-button" placeholder="Button Text" value="<?php echo esc_attr($screen['button_text']); ?>">
                        <input type="hidden" class="screen-image-url" value="<?php echo esc_attr($screen['image']); ?>">
                        <button type="button" class="button screen-upload-btn">Change Image</button>
                    </div>
                    <div class="screen-actions">
                        <button type="button" class="button screen-remove" title="Remove"><span class="dashicons dashicons-trash"></span></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <p>
                <button type="button" class="button" id="add-onboarding-screen">
                    <span class="dashicons dashicons-plus-alt2"></span> Add Screen
                </button>
            </p>
            
            <p>
                <button type="button" class="button button-primary" id="save-onboarding">
                    Save Onboarding Screens
                </button>
                <span id="onboarding-save-status"></span>
            </p>
        </div>
        
        <div class="ptp-card">
            <h2>Phone Preview</h2>
            <div class="ptp-phone-preview">
                <div class="phone-frame">
                    <div class="phone-screen" id="phone-preview-screen">
                        <img src="<?php echo esc_url($screens[0]['image'] ?? ''); ?>" class="preview-bg">
                        <div class="preview-content">
                            <h3 class="preview-title"><?php echo esc_html($screens[0]['title'] ?? ''); ?></h3>
                            <p class="preview-subtitle"><?php echo esc_html($screens[0]['subtitle'] ?? ''); ?></p>
                            <div class="preview-dots">
                                <?php for ($i = 0; $i < count($screens); $i++): ?>
                                <span class="dot <?php echo $i === 0 ? 'active' : ''; ?>"></span>
                                <?php endfor; ?>
                            </div>
                            <button class="preview-btn"><?php echo esc_html($screens[0]['button_text'] ?? 'Next'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function render_version_tab() {
        ?>
        <form method="post" action="options.php" class="ptp-form">
            <?php settings_fields('ptp_app_version'); ?>
            
            <div class="ptp-card">
                <h2>App Version Control</h2>
                
                <table class="form-table">
                    <tr>
                        <th>Current App Version</th>
                        <td>
                            <input type="text" name="ptp_current_app_version" value="<?php echo esc_attr(get_option('ptp_current_app_version', '1.0.0')); ?>" class="regular-text" placeholder="1.0.0">
                            <p class="description">The latest version available in app stores</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Minimum Required Version</th>
                        <td>
                            <input type="text" name="ptp_min_app_version" value="<?php echo esc_attr(get_option('ptp_min_app_version', '1.0.0')); ?>" class="regular-text" placeholder="1.0.0">
                            <p class="description">Users below this version will be prompted to update</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Force Update</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ptp_force_update" value="1" <?php checked(get_option('ptp_force_update')); ?>>
                                Block app usage until user updates
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Update Message</th>
                        <td>
                            <textarea name="ptp_update_message" rows="3" class="large-text"><?php echo esc_textarea(get_option('ptp_update_message', 'A new version is available. Please update for the best experience.')); ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ptp-card <?php echo get_option('ptp_maintenance_mode') ? 'maintenance-active' : ''; ?>">
                <h2>
                    <span class="dashicons dashicons-warning"></span>
                    Maintenance Mode
                </h2>
                
                <table class="form-table">
                    <tr>
                        <th>Enable Maintenance</th>
                        <td>
                            <label class="ptp-feature-toggle inline">
                                <input type="checkbox" name="ptp_maintenance_mode" value="1" <?php checked(get_option('ptp_maintenance_mode')); ?>>
                                <span class="toggle-switch"></span>
                                <span class="toggle-label">
                                    <strong>Maintenance Mode Active</strong>
                                </span>
                            </label>
                            <p class="description" style="color:#dc3545;">⚠️ This will prevent all users from using the app!</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Maintenance Message</th>
                        <td>
                            <textarea name="ptp_maintenance_message" rows="3" class="large-text"><?php echo esc_textarea(get_option('ptp_maintenance_message', "We're currently performing maintenance. Please try again shortly.")); ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php submit_button('Save Version Settings'); ?>
        </form>
        <?php
    }
    
    private function render_content_tab() {
        ?>
        <form method="post" action="options.php" class="ptp-form">
            <?php settings_fields('ptp_app_content'); ?>
            
            <div class="ptp-card">
                <h2>Home Screen Hero</h2>
                
                <table class="form-table">
                    <tr>
                        <th>Hero Title</th>
                        <td>
                            <input type="text" name="ptp_hero_title" value="<?php echo esc_attr(get_option('ptp_hero_title', 'Train Like a Pro')); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Hero Subtitle</th>
                        <td>
                            <textarea name="ptp_hero_subtitle" rows="2" class="large-text"><?php echo esc_textarea(get_option('ptp_hero_subtitle', 'Learn from current professional and NCAA Division 1 athletes')); ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ptp-card">
                <h2>Legal Document URLs</h2>
                
                <table class="form-table">
                    <tr>
                        <th>Terms of Service</th>
                        <td>
                            <input type="url" name="ptp_terms_url" value="<?php echo esc_attr(get_option('ptp_terms_url', site_url('/terms'))); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Privacy Policy</th>
                        <td>
                            <input type="url" name="ptp_privacy_url" value="<?php echo esc_attr(get_option('ptp_privacy_url', site_url('/privacy'))); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Refund Policy</th>
                        <td>
                            <input type="url" name="ptp_refund_url" value="<?php echo esc_attr(get_option('ptp_refund_url', site_url('/refund-policy'))); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Trainer Agreement</th>
                        <td>
                            <input type="url" name="ptp_trainer_agreement_url" value="<?php echo esc_attr(get_option('ptp_trainer_agreement_url', site_url('/trainer-agreement'))); ?>" class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th>Parent Waiver</th>
                        <td>
                            <input type="url" name="ptp_waiver_url" value="<?php echo esc_attr(get_option('ptp_waiver_url', site_url('/waiver'))); ?>" class="large-text">
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ptp-card">
                <h2>FAQ Management</h2>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=ptp-faq'); ?>" class="button">
                        <span class="dashicons dashicons-editor-help"></span> Manage FAQ
                    </a>
                </p>
            </div>
            
            <?php submit_button('Save Content Settings'); ?>
        </form>
        <?php
    }
    
    private function render_push_tab() {
        global $wpdb;
        
        $total_tokens = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_fcm_tokens");
        $trainer_tokens = $wpdb->get_var("
            SELECT COUNT(DISTINCT f.user_id) 
            FROM {$wpdb->prefix}ptp_fcm_tokens f
            JOIN {$wpdb->prefix}ptp_trainers t ON f.user_id = t.user_id
        ");
        $parent_tokens = $wpdb->get_var("
            SELECT COUNT(DISTINCT f.user_id) 
            FROM {$wpdb->prefix}ptp_fcm_tokens f
            JOIN {$wpdb->prefix}ptp_parents p ON f.user_id = p.user_id
        ");
        ?>
        <div class="ptp-card">
            <h2>Send Push Notification</h2>
            
            <?php if (!get_option('ptp_fcm_server_key')): ?>
            <div class="notice notice-warning inline">
                <p>
                    <strong>FCM not configured.</strong> 
                    <a href="?page=ptp-app-control&tab=features">Add your Firebase Server Key</a> to enable push notifications.
                </p>
            </div>
            <?php else: ?>
            
            <div class="ptp-push-stats">
                <div class="stat">
                    <span class="stat-value"><?php echo number_format($total_tokens); ?></span>
                    <span class="stat-label">Registered Devices</span>
                </div>
                <div class="stat">
                    <span class="stat-value"><?php echo number_format($trainer_tokens); ?></span>
                    <span class="stat-label">Trainers</span>
                </div>
                <div class="stat">
                    <span class="stat-value"><?php echo number_format($parent_tokens); ?></span>
                    <span class="stat-label">Parents</span>
                </div>
            </div>
            
            <form id="push-notification-form">
                <table class="form-table">
                    <tr>
                        <th>Send To</th>
                        <td>
                            <select name="audience" id="push-audience">
                                <option value="all">All Users</option>
                                <option value="trainers">Trainers Only</option>
                                <option value="parents">Parents Only</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Title</th>
                        <td>
                            <input type="text" name="title" id="push-title" class="large-text" placeholder="Notification title" required>
                        </td>
                    </tr>
                    <tr>
                        <th>Message</th>
                        <td>
                            <textarea name="message" id="push-message" rows="3" class="large-text" placeholder="Notification message" required></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th>Link (optional)</th>
                        <td>
                            <input type="text" name="link" id="push-link" class="large-text" placeholder="e.g., /trainers or /camps/123">
                            <p class="description">Deep link to open when notification is tapped</p>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="submit" class="button button-primary button-hero" id="send-push-btn">
                        <span class="dashicons dashicons-megaphone"></span>
                        Send Push Notification
                    </button>
                </p>
                
                <div id="push-result" style="display:none;"></div>
            </form>
            <?php endif; ?>
        </div>
        
        <div class="ptp-card">
            <h2>Recent Notifications</h2>
            <?php
            $recent = $wpdb->get_results("
                SELECT * FROM {$wpdb->prefix}ptp_push_log 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            
            if ($recent): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Title</th>
                        <th>Audience</th>
                        <th>Sent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $log): ?>
                    <tr>
                        <td><?php echo date('M j, Y g:ia', strtotime($log->created_at)); ?></td>
                        <td><?php echo esc_html($log->title); ?></td>
                        <td><?php echo ucfirst($log->audience); ?></td>
                        <td><?php echo number_format($log->sent_count); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p class="description">No notifications sent yet.</p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    private function render_maps_tab() {
        $maps_key = get_option('ptp_google_maps_api_key', '') ?: get_option('ptp_google_maps_key', '');
        ?>
        <form method="post" action="options.php" class="ptp-form">
            <?php settings_fields('ptp_app_features'); ?>
            
            <div class="ptp-card">
                <h2>Google Maps Configuration</h2>
                <p class="description">Google Maps is used for trainer location selection during onboarding and for the "Find Trainers" map view.</p>
                
                <table class="form-table">
                    <tr>
                        <th>Google Maps API Key</th>
                        <td>
                            <input type="text" name="ptp_google_maps_key" value="<?php echo esc_attr($maps_key); ?>" class="large-text" placeholder="AIzaSy...">
                            <p class="description">
                                Get your API key from <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php if ($maps_key): ?>
                <h3>Required APIs</h3>
                <p>Make sure these APIs are enabled in your Google Cloud project:</p>
                <ul class="ptp-checklist">
                    <li><span class="dashicons dashicons-yes"></span> Maps JavaScript API</li>
                    <li><span class="dashicons dashicons-yes"></span> Places API</li>
                    <li><span class="dashicons dashicons-yes"></span> Geocoding API</li>
                </ul>
                
                <h3>Test Map</h3>
                <div id="test-map" style="height: 300px; border: 2px solid #0a0a0a; margin-top: 15px;"></div>
                <?php else: ?>
                <div class="notice notice-warning inline">
                    <p>Add your Google Maps API key to enable location features.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <?php submit_button('Save Maps Settings'); ?>
        </form>
        <?php
    }
    
    private function get_default_onboarding() {
        return array(
            array(
                'id' => 'welcome',
                'title' => 'Train Like a Pro',
                'subtitle' => 'Learn from current professional and NCAA Division 1 athletes',
                'image' => PTP_Images::get('BG7A1915'),
                'button_text' => 'Next',
            ),
            array(
                'id' => 'trainers',
                'title' => 'Elite Trainers',
                'subtitle' => 'Our trainers play at the highest levels - professional leagues and top college programs',
                'image' => PTP_Images::get('BG7A1874'),
                'button_text' => 'Next',
            ),
            array(
                'id' => 'booking',
                'title' => 'Easy Booking',
                'subtitle' => 'Find trainers near you, check their availability, and book sessions instantly',
                'image' => PTP_Images::get('BG7A1797'),
                'button_text' => 'Next',
            ),
            array(
                'id' => 'progress',
                'title' => 'Track Progress',
                'subtitle' => 'Get session notes, skill assessments, and personalized training plans',
                'image' => PTP_Images::get('BG7A1642'),
                'button_text' => 'Get Started',
            ),
        );
    }
    
    public function ajax_save_onboarding() {
        check_ajax_referer('ptp_app_control', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $screens = isset($_POST['screens']) ? $_POST['screens'] : array();
        $sanitized = array();
        
        foreach ($screens as $screen) {
            $sanitized[] = array(
                'id' => sanitize_key($screen['id'] ?? 'screen'),
                'title' => sanitize_text_field($screen['title'] ?? ''),
                'subtitle' => sanitize_textarea_field($screen['subtitle'] ?? ''),
                'image' => esc_url_raw($screen['image'] ?? ''),
                'button_text' => sanitize_text_field($screen['button_text'] ?? 'Next'),
            );
        }
        
        update_option('ptp_onboarding_screens', $sanitized);
        
        wp_send_json_success('Onboarding screens saved');
    }
    
    public function ajax_send_push() {
        check_ajax_referer('ptp_app_control', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $title = sanitize_text_field($_POST['title'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $audience = sanitize_text_field($_POST['audience'] ?? 'all');
        $link = sanitize_text_field($_POST['link'] ?? '');
        
        if (empty($title) || empty($message)) {
            wp_send_json_error('Title and message required');
        }
        
        if (!class_exists('PTP_Push_Notifications')) {
            wp_send_json_error('Push notifications not available');
        }
        
        $result = PTP_Push_Notifications::send_to_audience($audience, $title, $message, array('link' => $link));
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Log the notification
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ptp_push_log', array(
            'title' => $title,
            'message' => $message,
            'audience' => $audience,
            'sent_count' => $result['sent'] ?? 0,
            'created_at' => current_time('mysql'),
        ));
        
        wp_send_json_success(array(
            'sent' => $result['sent'] ?? 0,
            'message' => 'Notification sent successfully',
        ));
    }
    
    /**
     * Render camps management page
     */
    public function render_camps_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'camps';
        
        ?>
        <div class="wrap ptp-camps-admin">
            <h1>
                <span class="dashicons dashicons-calendar-alt"></span>
                Camps & Clinics
                <?php if ($action === 'list'): ?>
                <a href="<?php echo admin_url('admin.php?page=ptp-camps&action=new'); ?>" class="page-title-action">Add New</a>
                <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="page-title-action">Add via PTP Native</a>
                <?php endif; ?>
            </h1>
            
            <?php if ($action === 'list'): ?>
            <nav class="nav-tab-wrapper">
                <a href="?page=ptp-camps&tab=camps" class="nav-tab <?php echo $tab === 'camps' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-calendar-alt"></span> All Camps
                </a>
                <a href="?page=ptp-camps&tab=registrations" class="nav-tab <?php echo $tab === 'registrations' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-groups"></span> Registrations
                </a>
                <a href="?page=ptp-camps&tab=sync" class="nav-tab <?php echo $tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-update"></span> PTP Native Sync
                </a>
            </nav>
            <?php endif; ?>
            
            <?php
            switch ($action) {
                case 'new':
                case 'edit':
                    $this->render_camp_form();
                    break;
                case 'registrations':
                    $this->render_camp_registrations();
                    break;
                default:
                    switch ($tab) {
                        case 'registrations':
                            $this->render_all_registrations();
                            break;
                        case 'sync':
                            $this->render_woo_sync();
                            break;
                        default:
                            $this->render_camps_list();
                    }
            }
            ?>
        </div>
        <?php
    }
    
    private function render_camps_list() {
        global $wpdb;
        
        // Get stats - ALL PTP Native products
        $total_camps = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish', 'draft', 'pending')");
        $upcoming = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ptp_start_date'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             AND (pm.meta_value >= %s OR pm.meta_value IS NULL)",
            date('Y-m-d')
        ));
        $total_registrations = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_camp_registrations WHERE status = 'confirmed'") ?: 0;
        $total_revenue = $wpdb->get_var("SELECT SUM(amount_paid) FROM {$wpdb->prefix}ptp_camp_registrations WHERE status = 'confirmed'") ?: 0;
        
        // Get ALL PTP Native products - they are all camps/clinics
        $camps = get_posts(array(
            'post_type' => 'product',
            'post_status' => array('publish', 'draft', 'pending'),
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        ?>
        
        <div class="ptp-stats-row" style="display:flex;gap:20px;margin-bottom:20px;">
            <div class="ptp-card" style="flex:1;text-align:center;">
                <div style="font-size:36px;font-weight:bold;color:#FCB900;"><?php echo $total_camps; ?></div>
                <div style="color:#666;text-transform:uppercase;font-size:12px;">Total Camps</div>
            </div>
            <div class="ptp-card" style="flex:1;text-align:center;">
                <div style="font-size:36px;font-weight:bold;color:#16a34a;"><?php echo $upcoming; ?></div>
                <div style="color:#666;text-transform:uppercase;font-size:12px;">Upcoming</div>
            </div>
            <div class="ptp-card" style="flex:1;text-align:center;">
                <div style="font-size:36px;font-weight:bold;color:#3b82f6;"><?php echo $total_registrations; ?></div>
                <div style="color:#666;text-transform:uppercase;font-size:12px;">Registrations</div>
            </div>
            <div class="ptp-card" style="flex:1;text-align:center;">
                <div style="font-size:36px;font-weight:bold;color:#0a0a0a;">$<?php echo number_format($total_revenue, 0); ?></div>
                <div style="color:#666;text-transform:uppercase;font-size:12px;">Revenue</div>
            </div>
        </div>
        
        <div class="ptp-card">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Camp/Clinic</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Location</th>
                        <th>Price</th>
                        <th>Capacity</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($camps)): ?>
                    <tr>
                        <td colspan="8">
                            No products found in PTP Native. 
                            <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>">Create your first camp or clinic</a>.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($camps as $camp): 
                        $product = ptp_get_camp_product($camp->ID);
                        $start = get_post_meta($camp->ID, '_ptp_start_date', true);
                        $end = get_post_meta($camp->ID, '_ptp_end_date', true);
                        
                        // Auto-detect type from title or meta
                        $type = get_post_meta($camp->ID, '_ptp_camp_type', true);
                        if (empty($type)) {
                            $title_lower = strtolower($camp->post_title);
                            if (strpos($title_lower, 'clinic') !== false) {
                                $type = 'clinic';
                            } elseif (strpos($title_lower, 'academy') !== false) {
                                $type = 'academy';
                            } else {
                                $type = 'camp';
                            }
                        }
                        
                        $city = get_post_meta($camp->ID, '_ptp_city', true);
                        $state = get_post_meta($camp->ID, '_ptp_state', true);
                        $max = get_post_meta($camp->ID, '_ptp_max_capacity', true);
                        $sold = get_post_meta($camp->ID, '_ptp_sold_count', true) ?: 0;
                    ?>
                    <tr>
                        <td>
                            <strong>
                                <a href="<?php echo admin_url('admin.php?page=ptp-camps&action=edit&id=' . $camp->ID); ?>">
                                    <?php echo esc_html($camp->post_title); ?>
                                </a>
                            </strong>
                            <?php if ($product && $product->is_featured()): ?>
                            <span class="ptp-badge gold">Featured</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="ptp-badge <?php echo $type; ?>"><?php echo ucfirst($type); ?></span>
                        </td>
                        <td>
                            <?php if ($start): ?>
                            <?php echo date('M j', strtotime($start)); ?>
                            <?php if ($end && $end !== $start): ?>
                            - <?php echo date('M j, Y', strtotime($end)); ?>
                            <?php else: ?>
                            , <?php echo date('Y', strtotime($start)); ?>
                            <?php endif; ?>
                            <?php else: ?>
                            <em>Not set</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            $location = trim("$city, $state", ", ");
                            echo $location ? esc_html($location) : '<em>Not set</em>'; 
                            ?>
                        </td>
                        <td>$<?php echo $product ? number_format($product->get_price(), 0) : '0'; ?></td>
                        <td>
                            <?php echo intval($sold); ?> / <?php echo intval($max) ?: '∞'; ?>
                            <?php if ($max && $sold >= $max): ?>
                            <span class="ptp-badge red">Full</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($camp->post_status === 'publish'): ?>
                            <span class="ptp-badge green">Live</span>
                            <?php else: ?>
                            <span class="ptp-badge gray"><?php echo ucfirst($camp->post_status); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=ptp-camps&action=edit&id=' . $camp->ID); ?>" class="button button-small">Edit</a>
                            <a href="<?php echo admin_url('admin.php?page=ptp-camps&action=registrations&id=' . $camp->ID); ?>" class="button button-small">Registrations</a>
                            <a href="<?php echo get_permalink($camp->ID); ?>" class="button button-small" target="_blank">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private function render_camp_form() {
        $camp_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $product = $camp_id ? ptp_get_camp_product($camp_id) : null;
        $post = $camp_id ? get_post($camp_id) : null;
        
        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ptp_camp_nonce'])) {
            if (wp_verify_nonce($_POST['ptp_camp_nonce'], 'ptp_save_camp')) {
                $camp_id = $this->save_camp($_POST, $camp_id);
                if ($camp_id) {
                    echo '<div class="notice notice-success"><p>Camp saved successfully!</p></div>';
                    $product = ptp_get_camp_product($camp_id);
                    $post = get_post($camp_id);
                }
            }
        }
        
        // Get meta values
        $meta = array(
            'type' => get_post_meta($camp_id, '_ptp_camp_type', true) ?: 'camp',
            'start_date' => get_post_meta($camp_id, '_ptp_start_date', true),
            'end_date' => get_post_meta($camp_id, '_ptp_end_date', true),
            'location_name' => get_post_meta($camp_id, '_ptp_location_name', true),
            'address' => get_post_meta($camp_id, '_ptp_address', true),
            'city' => get_post_meta($camp_id, '_ptp_city', true),
            'state' => get_post_meta($camp_id, '_ptp_state', true),
            'zip' => get_post_meta($camp_id, '_ptp_zip', true),
            'latitude' => get_post_meta($camp_id, '_ptp_latitude', true),
            'longitude' => get_post_meta($camp_id, '_ptp_longitude', true),
            'max_capacity' => get_post_meta($camp_id, '_ptp_max_capacity', true),
            'age_groups' => get_post_meta($camp_id, '_ptp_age_groups', true) ?: array(),
            'skill_levels' => get_post_meta($camp_id, '_ptp_skill_levels', true) ?: array(),
            'daily_times' => get_post_meta($camp_id, '_ptp_daily_times', true) ?: array('start' => '09:00', 'end' => '15:00'),
            'what_to_bring' => get_post_meta($camp_id, '_ptp_what_to_bring', true),
            'included' => get_post_meta($camp_id, '_ptp_included', true),
            'contact_email' => get_post_meta($camp_id, '_ptp_contact_email', true),
            'contact_phone' => get_post_meta($camp_id, '_ptp_contact_phone', true),
        );
        ?>
        
        <form method="post" class="ptp-form ptp-camp-form">
            <?php wp_nonce_field('ptp_save_camp', 'ptp_camp_nonce'); ?>
            
            <div class="ptp-form-columns">
                <div class="ptp-form-main">
                    <div class="ptp-card">
                        <h2>Basic Information</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th>Name *</th>
                                <td>
                                    <input type="text" name="title" value="<?php echo esc_attr($post->post_title ?? ''); ?>" class="large-text" required>
                                </td>
                            </tr>
                            <tr>
                                <th>Type</th>
                                <td>
                                    <select name="camp_type">
                                        <option value="camp" <?php selected($meta['type'], 'camp'); ?>>Summer Camp</option>
                                        <option value="clinic" <?php selected($meta['type'], 'clinic'); ?>>Skills Clinic</option>
                                        <option value="academy" <?php selected($meta['type'], 'academy'); ?>>Academy Program</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Short Description</th>
                                <td>
                                    <textarea name="short_description" rows="3" class="large-text"><?php echo esc_textarea($product ? $product->get_short_description() : ''); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th>Full Description</th>
                                <td>
                                    <?php wp_editor($product ? $product->get_description() : '', 'camp_description', array('textarea_rows' => 8)); ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="ptp-card">
                        <h2>Dates & Times</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th>Start Date *</th>
                                <td>
                                    <input type="date" name="start_date" value="<?php echo esc_attr($meta['start_date']); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th>End Date *</th>
                                <td>
                                    <input type="date" name="end_date" value="<?php echo esc_attr($meta['end_date']); ?>" required>
                                </td>
                            </tr>
                            <tr>
                                <th>Daily Hours</th>
                                <td>
                                    <input type="time" name="daily_start" value="<?php echo esc_attr($meta['daily_times']['start']); ?>" style="width:120px;">
                                    to
                                    <input type="time" name="daily_end" value="<?php echo esc_attr($meta['daily_times']['end']); ?>" style="width:120px;">
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="ptp-card">
                        <h2>Location</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th>Venue Name</th>
                                <td>
                                    <input type="text" name="location_name" value="<?php echo esc_attr($meta['location_name']); ?>" class="large-text" placeholder="e.g., Villanova Stadium">
                                </td>
                            </tr>
                            <tr>
                                <th>Address</th>
                                <td>
                                    <input type="text" name="address" id="camp-address" value="<?php echo esc_attr($meta['address']); ?>" class="large-text" placeholder="Start typing to search...">
                                </td>
                            </tr>
                            <tr>
                                <th>City / State / Zip</th>
                                <td>
                                    <input type="text" name="city" value="<?php echo esc_attr($meta['city']); ?>" style="width:150px;" placeholder="City">
                                    <select name="state" style="width:100px;">
                                        <option value="">State</option>
                                        <option value="PA" <?php selected($meta['state'], 'PA'); ?>>PA</option>
                                        <option value="NJ" <?php selected($meta['state'], 'NJ'); ?>>NJ</option>
                                        <option value="DE" <?php selected($meta['state'], 'DE'); ?>>DE</option>
                                        <option value="MD" <?php selected($meta['state'], 'MD'); ?>>MD</option>
                                        <option value="NY" <?php selected($meta['state'], 'NY'); ?>>NY</option>
                                    </select>
                                    <input type="text" name="zip" value="<?php echo esc_attr($meta['zip']); ?>" style="width:80px;" placeholder="Zip">
                                </td>
                            </tr>
                        </table>
                        
                        <input type="hidden" name="latitude" id="camp-lat" value="<?php echo esc_attr($meta['latitude']); ?>">
                        <input type="hidden" name="longitude" id="camp-lng" value="<?php echo esc_attr($meta['longitude']); ?>">
                        
                        <div id="camp-map" style="height:250px;border:2px solid #0a0a0a;margin-top:15px;"></div>
                    </div>
                    
                    <div class="ptp-card">
                        <h2>Additional Details</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th>What to Bring</th>
                                <td>
                                    <textarea name="what_to_bring" rows="4" class="large-text" placeholder="Athletic gear, water bottle, appropriate footwear..."><?php echo esc_textarea($meta['what_to_bring']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th>What's Included</th>
                                <td>
                                    <textarea name="included" rows="4" class="large-text" placeholder="Camp t-shirt, snacks, skills certificate..."><?php echo esc_textarea($meta['included']); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th>Contact Email</th>
                                <td>
                                    <input type="email" name="contact_email" value="<?php echo esc_attr($meta['contact_email']); ?>" class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th>Contact Phone</th>
                                <td>
                                    <input type="text" name="contact_phone" value="<?php echo esc_attr($meta['contact_phone']); ?>" class="regular-text">
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div class="ptp-form-sidebar">
                    <div class="ptp-card">
                        <h3>Publish</h3>
                        <p>
                            <label>
                                <input type="checkbox" name="is_published" value="1" <?php checked($post && $post->post_status === 'publish'); ?>>
                                Published (visible on site)
                            </label>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="is_featured" value="1" <?php checked($product && $product->is_featured()); ?>>
                                Featured Camp
                            </label>
                        </p>
                        <p>
                            <button type="submit" class="button button-primary button-large" style="width:100%;">
                                <?php echo $camp_id ? 'Update Camp' : 'Create Camp'; ?>
                            </button>
                        </p>
                    </div>
                    
                    <div class="ptp-card">
                        <h3>Pricing</h3>
                        <table class="form-table">
                            <tr>
                                <th>Price ($)</th>
                                <td>
                                    <input type="number" name="price" value="<?php echo $product ? $product->get_regular_price() : ''; ?>" step="0.01" min="0" style="width:100%;">
                                </td>
                            </tr>
                            <tr>
                                <th>Sale Price ($)</th>
                                <td>
                                    <input type="number" name="sale_price" value="<?php echo $product ? $product->get_sale_price() : ''; ?>" step="0.01" min="0" style="width:100%;">
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="ptp-card">
                        <h3>Capacity</h3>
                        <table class="form-table">
                            <tr>
                                <th>Max Campers</th>
                                <td>
                                    <input type="number" name="max_capacity" value="<?php echo esc_attr($meta['max_capacity']); ?>" min="1" style="width:100%;">
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="ptp-card">
                        <h3>Age Groups</h3>
                        <?php
                        $ages = array('u6' => 'U6 (5-6)', 'u8' => 'U8 (7-8)', 'u10' => 'U10 (9-10)', 'u12' => 'U12 (11-12)', 'u14' => 'U14 (13-14)', 'u16' => 'U16 (15-16)', 'u18' => 'U18 (17-18)');
                        foreach ($ages as $key => $label):
                        ?>
                        <label style="display:block;margin:5px 0;">
                            <input type="checkbox" name="age_groups[]" value="<?php echo $key; ?>" <?php checked(in_array($key, $meta['age_groups'])); ?>>
                            <?php echo $label; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="ptp-card">
                        <h3>Skill Levels</h3>
                        <?php
                        $levels = array('beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced', 'elite' => 'Elite');
                        foreach ($levels as $key => $label):
                        ?>
                        <label style="display:block;margin:5px 0;">
                            <input type="checkbox" name="skill_levels[]" value="<?php echo $key; ?>" <?php checked(in_array($key, $meta['skill_levels'])); ?>>
                            <?php echo $label; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="ptp-card">
                        <h3>Featured Image</h3>
                        <div class="ptp-asset-preview">
                            <img src="<?php echo $product ? wp_get_attachment_url($product->get_image_id()) : 'https://via.placeholder.com/300x200?text=Camp+Image'; ?>" id="camp-image-preview">
                        </div>
                        <input type="hidden" name="image_id" id="camp_image_id" value="<?php echo $product ? $product->get_image_id() : ''; ?>">
                        <button type="button" class="button ptp-upload-btn" data-target="camp_image_id" data-preview="camp-image-preview" style="width:100%;margin-top:10px;">
                            Set Image
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <?php
    }
    
    private function save_camp($data, $camp_id = 0) {
        // Create or update product
        $product_data = array(
            'post_title' => sanitize_text_field($data['title']),
            'post_content' => wp_kses_post($data['camp_description'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($data['short_description'] ?? ''),
            'post_status' => !empty($data['is_published']) ? 'publish' : 'draft',
            'post_type' => 'product',
        );
        
        if ($camp_id) {
            $product_data['ID'] = $camp_id;
            wp_update_post($product_data);
        } else {
            $camp_id = wp_insert_post($product_data);
        }
        
        if (!$camp_id) return false;
        
        // Set product type
        wp_set_object_terms($camp_id, 'simple', 'product_type');
        
        // Set as PTP camp
        update_post_meta($camp_id, '_ptp_is_camp', 'yes');
        
        // Pricing
        update_post_meta($camp_id, '_regular_price', floatval($data['price'] ?? 0));
        update_post_meta($camp_id, '_price', floatval($data['sale_price'] ?: $data['price'] ?? 0));
        if (!empty($data['sale_price'])) {
            update_post_meta($camp_id, '_sale_price', floatval($data['sale_price']));
        }
        
        // Featured
        $product = ptp_get_camp_product($camp_id);
        if ($product) {
            $product->set_featured(!empty($data['is_featured']));
            $product->save();
        }
        
        // Image
        if (!empty($data['image_id'])) {
            set_post_thumbnail($camp_id, intval($data['image_id']));
        }
        
        // Camp meta
        update_post_meta($camp_id, '_ptp_camp_type', sanitize_text_field($data['camp_type'] ?? 'camp'));
        update_post_meta($camp_id, '_ptp_start_date', sanitize_text_field($data['start_date'] ?? ''));
        update_post_meta($camp_id, '_ptp_end_date', sanitize_text_field($data['end_date'] ?? ''));
        update_post_meta($camp_id, '_ptp_location_name', sanitize_text_field($data['location_name'] ?? ''));
        update_post_meta($camp_id, '_ptp_address', sanitize_text_field($data['address'] ?? ''));
        update_post_meta($camp_id, '_ptp_city', sanitize_text_field($data['city'] ?? ''));
        update_post_meta($camp_id, '_ptp_state', sanitize_text_field($data['state'] ?? ''));
        update_post_meta($camp_id, '_ptp_zip', sanitize_text_field($data['zip'] ?? ''));
        update_post_meta($camp_id, '_ptp_latitude', floatval($data['latitude'] ?? 0));
        update_post_meta($camp_id, '_ptp_longitude', floatval($data['longitude'] ?? 0));
        update_post_meta($camp_id, '_ptp_max_capacity', intval($data['max_capacity'] ?? 0));
        update_post_meta($camp_id, '_ptp_age_groups', array_map('sanitize_text_field', $data['age_groups'] ?? array()));
        update_post_meta($camp_id, '_ptp_skill_levels', array_map('sanitize_text_field', $data['skill_levels'] ?? array()));
        update_post_meta($camp_id, '_ptp_daily_times', array(
            'start' => sanitize_text_field($data['daily_start'] ?? '09:00'),
            'end' => sanitize_text_field($data['daily_end'] ?? '15:00'),
        ));
        update_post_meta($camp_id, '_ptp_what_to_bring', sanitize_textarea_field($data['what_to_bring'] ?? ''));
        update_post_meta($camp_id, '_ptp_included', sanitize_textarea_field($data['included'] ?? ''));
        update_post_meta($camp_id, '_ptp_contact_email', sanitize_email($data['contact_email'] ?? ''));
        update_post_meta($camp_id, '_ptp_contact_phone', sanitize_text_field($data['contact_phone'] ?? ''));
        
        // Stock management
        if (!empty($data['max_capacity'])) {
            update_post_meta($camp_id, '_manage_stock', 'yes');
            update_post_meta($camp_id, '_stock', intval($data['max_capacity']));
        }
        
        return $camp_id;
    }
    
    /**
     * Render all registrations
     */
    private function render_all_registrations() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_camp_registrations';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        
        if (!$table_exists) {
            echo '<div class="ptp-card"><p>No registrations yet. Registrations will appear here when customers purchase camp products.</p></div>';
            return;
        }
        
        $registrations = $wpdb->get_results("
            SELECT r.*, p.post_title as camp_name,
                   pm.meta_value as start_date,
                   pm2.meta_value as camp_type
            FROM $table r
            JOIN {$wpdb->posts} p ON r.camp_id = p.ID
            LEFT JOIN {$wpdb->postmeta} pm ON r.camp_id = pm.post_id AND pm.meta_key = '_ptp_start_date'
            LEFT JOIN {$wpdb->postmeta} pm2 ON r.camp_id = pm2.post_id AND pm2.meta_key = '_ptp_camp_type'
            ORDER BY r.created_at DESC
            LIMIT 100
        ");
        ?>
        
        <div class="ptp-card">
            <h2>All Registrations</h2>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Camp/Clinic</th>
                        <th>Date</th>
                        <th>Parent</th>
                        <th>Qty</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Registered</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registrations)): ?>
                    <tr>
                        <td colspan="8">No registrations found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($registrations as $reg): ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $reg->order_id . '&action=edit'); ?>">
                                #<?php echo $reg->order_id; ?>
                            </a>
                        </td>
                        <td>
                            <strong><?php echo esc_html($reg->camp_name); ?></strong>
                            <span class="ptp-badge <?php echo $reg->camp_type ?: 'camp'; ?>" style="font-size:10px;">
                                <?php echo ucfirst($reg->camp_type ?: 'camp'); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo $reg->start_date ? date('M j, Y', strtotime($reg->start_date)) : '—'; ?>
                        </td>
                        <td>
                            <?php echo esc_html($reg->parent_name); ?><br>
                            <small><?php echo esc_html($reg->parent_email); ?></small>
                        </td>
                        <td><?php echo $reg->quantity; ?></td>
                        <td>$<?php echo number_format($reg->amount_paid, 2); ?></td>
                        <td>
                            <?php
                            $status_colors = array(
                                'confirmed' => 'green',
                                'pending' => 'gold',
                                'cancelled' => 'red',
                            );
                            $color = $status_colors[$reg->status] ?? 'gray';
                            ?>
                            <span class="ptp-badge <?php echo $color; ?>"><?php echo ucfirst($reg->status); ?></span>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($reg->created_at)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="ptp-card">
            <h2>Export Registrations</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=ptp-camps&action=export&format=csv'); ?>" class="button">
                    <span class="dashicons dashicons-download"></span> Export to CSV
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render single camp registrations
     */
    private function render_camp_registrations() {
        global $wpdb;
        
        $camp_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$camp_id) {
            echo '<div class="notice notice-error"><p>Camp not found.</p></div>';
            return;
        }
        
        $camp = get_post($camp_id);
        $table = $wpdb->prefix . 'ptp_camp_registrations';
        
        $registrations = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table WHERE camp_id = %d ORDER BY created_at DESC
        ", $camp_id));
        
        $start = get_post_meta($camp_id, '_ptp_start_date', true);
        $location = get_post_meta($camp_id, '_ptp_location_name', true);
        ?>
        
        <p><a href="<?php echo admin_url('admin.php?page=ptp-camps'); ?>">&larr; Back to All Camps</a></p>
        
        <div class="ptp-card">
            <h2>
                <?php echo esc_html($camp->post_title); ?> - Registrations
            </h2>
            <p>
                📅 <?php echo date('M j, Y', strtotime($start)); ?> | 
                📍 <?php echo esc_html($location); ?> |
                👥 <?php echo count($registrations); ?> registered
            </p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Parent Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Qty</th>
                        <th>Paid</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $reg): ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url('post.php?post=' . $reg->order_id . '&action=edit'); ?>">
                                #<?php echo $reg->order_id; ?>
                            </a>
                        </td>
                        <td><?php echo esc_html($reg->parent_name); ?></td>
                        <td><a href="mailto:<?php echo esc_attr($reg->parent_email); ?>"><?php echo esc_html($reg->parent_email); ?></a></td>
                        <td><?php echo esc_html($reg->parent_phone); ?></td>
                        <td><?php echo $reg->quantity; ?></td>
                        <td>$<?php echo number_format($reg->amount_paid, 2); ?></td>
                        <td>
                            <span class="ptp-badge <?php echo $reg->status === 'confirmed' ? 'green' : 'red'; ?>">
                                <?php echo ucfirst($reg->status); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render PTP Native sync page
     */
    private function render_woo_sync() {
        global $wpdb;
        
        // Check if PTP Native is active
        if (!false) {
            ?>
            <div class="ptp-card">
                <h2>PTP Native Not Active</h2>
                <p>PTP Native is required for camp registrations and payments.</p>
                <p><a href="<?php echo admin_url('plugin-install.php?s=ptp-native&tab=search'); ?>" class="button button-primary">Install PTP Native</a></p>
            </div>
            <?php
            return;
        }
        
        // Get product stats
        $all_products = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'");
        $with_dates = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_ptp_start_date' AND meta_value != ''");
        $with_location = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_ptp_city' AND meta_value != ''");
        
        // Find products missing key details
        $needs_details = $wpdb->get_results("
            SELECT p.ID, p.post_title 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ptp_start_date'
            LEFT JOIN {$wpdb->postmeta} pm_city ON p.ID = pm_city.post_id AND pm_city.meta_key = '_ptp_city'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND (pm_date.meta_value IS NULL OR pm_date.meta_value = '' OR pm_city.meta_value IS NULL OR pm_city.meta_value = '')
            LIMIT 20
        ");
        ?>
        
        <div class="ptp-card">
            <h2>✅ All PTP Native Products Are Camps/Clinics</h2>
            <p style="font-size:16px;color:#16a34a;"><strong>Every PTP Native product automatically appears in the mobile app!</strong></p>
            
            <table class="form-table">
                <tr>
                    <th>PTP Native</th>
                    <td><span class="ptp-badge green">Active</span> v<?php echo PTP_VERSION; ?></td>
                </tr>
                <tr>
                    <th>Total Products (All Camps)</th>
                    <td><strong style="font-size:24px;color:#FCB900;"><?php echo $all_products; ?></strong></td>
                </tr>
                <tr>
                    <th>With Dates Set</th>
                    <td><?php echo $with_dates; ?> / <?php echo $all_products; ?></td>
                </tr>
                <tr>
                    <th>With Location Set</th>
                    <td><?php echo $with_location; ?> / <?php echo $all_products; ?></td>
                </tr>
            </table>
        </div>
        
        <?php if (!empty($needs_details)): ?>
        <div class="ptp-card">
            <h2>⚠️ Products Missing Details</h2>
            <p>These products are live but missing dates or location info. Add details to improve the mobile app experience:</p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($needs_details as $product): ?>
                    <tr>
                        <td><?php echo esc_html($product->post_title); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=ptp-camps&action=edit&id=' . $product->ID); ?>" class="button button-small button-primary">
                                Add Details
                            </a>
                            <a href="<?php echo admin_url('post.php?post=' . $product->ID . '&action=edit'); ?>" class="button button-small">
                                Edit in PTP Native
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <div class="ptp-card">
            <h2>How It Works</h2>
            
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:20px;">
                <div style="text-align:center;padding:20px;background:#f5f5f5;border:2px solid #0a0a0a;">
                    <div style="font-size:48px;">1️⃣</div>
                    <h3>Create Any Product</h3>
                    <p>Every PTP Native product is automatically a camp/clinic</p>
                </div>
                <div style="text-align:center;padding:20px;background:#f5f5f5;border:2px solid #0a0a0a;">
                    <div style="font-size:48px;">2️⃣</div>
                    <h3>Add Details (Optional)</h3>
                    <p>Set dates, location, capacity for better mobile experience</p>
                </div>
                <div style="text-align:center;padding:20px;background:#f5f5f5;border:2px solid #0a0a0a;">
                    <div style="font-size:48px;">3️⃣</div>
                    <h3>Track Registrations</h3>
                    <p>Orders automatically sync and update capacity</p>
                </div>
            </div>
        </div>
        
        <div class="ptp-card">
            <h2>Automatic Features</h2>
            
            <ul class="ptp-checklist">
                <li><span class="dashicons dashicons-yes" style="color:#16a34a;"></span> <strong>ALL products visible in mobile app</strong> - no manual marking needed</li>
                <li><span class="dashicons dashicons-yes" style="color:#16a34a;"></span> Auto-detects type from title (camp, clinic, academy)</li>
                <li><span class="dashicons dashicons-yes" style="color:#16a34a;"></span> PTP Native orders create registrations automatically</li>
                <li><span class="dashicons dashicons-yes" style="color:#16a34a;"></span> Stock/capacity updates when registrations are made</li>
                <li><span class="dashicons dashicons-yes" style="color:#16a34a;"></span> Cancelled orders restore available spots</li>
                <li><span class="dashicons dashicons-yes" style="color:#16a34a;"></span> Camp details included in order emails</li>
                <li><span class="dashicons dashicons-yes" style="color:#16a34a;"></span> Full REST API for mobile app</li>
            </ul>
        </div>
        
        <div class="ptp-card">
            <h2>Adding Extra Details</h2>
            <p>While all products work automatically, you can enhance them with:</p>
            <ul>
                <li><strong>Dates:</strong> Start/end dates, daily times</li>
                <li><strong>Location:</strong> Venue name, address, map coordinates</li>
                <li><strong>Capacity:</strong> Max campers, track registrations</li>
                <li><strong>Age Groups:</strong> U6, U8, U10, etc.</li>
                <li><strong>Details:</strong> What to bring, what's included, contact info</li>
            </ul>
            <p>
                <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button button-primary">
                    Manage All Products
                </a>
                <a href="<?php echo admin_url('admin.php?page=ptp-camps&action=new'); ?>" class="button">
                    Create New Camp
                </a>
            </p>
        </div>
        <?php
    }
}

// Initialize
PTP_App_Control::instance();
