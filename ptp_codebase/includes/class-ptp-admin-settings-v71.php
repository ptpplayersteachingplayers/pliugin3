<?php
/**
 * PTP Admin Settings - v71 (OpenPhone)
 * Configuration for Stripe Connect, Google Calendar, SMS (OpenPhone)
 * 
 * Replaces Twilio with OpenPhone API.
 * Drop-in replacement for includes/class-ptp-admin-settings-v71.php
 */

defined('ABSPATH') || exit;

class PTP_Admin_Settings_V71 {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_ptp_test_stripe_connection', array($this, 'test_stripe_connection'));
        add_action('wp_ajax_ptp_test_openphone_connection', array($this, 'test_openphone_connection'));
    }
    
    /**
     * Add admin menu
     */
    public function add_menu_page() {
        // DISABLED - Settings integrated into main PTP menu
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Stripe settings
        register_setting('ptp_stripe_settings', 'ptp_stripe_publishable_key');
        register_setting('ptp_stripe_settings', 'ptp_stripe_secret_key');
        register_setting('ptp_stripe_settings', 'ptp_stripe_connect_client_id');
        register_setting('ptp_stripe_settings', 'ptp_stripe_webhook_secret');
        register_setting('ptp_stripe_settings', 'ptp_platform_fee_percent');
        register_setting('ptp_stripe_settings', 'ptp_processing_fee_percent');
        register_setting('ptp_stripe_settings', 'ptp_processing_fee_fixed');
        register_setting('ptp_stripe_settings', 'ptp_processing_fee_enabled');
        
        // Early bird settings
        register_setting('ptp_stripe_settings', 'ptp_early_bird_enabled');
        register_setting('ptp_stripe_settings', 'ptp_early_bird_percent');
        register_setting('ptp_stripe_settings', 'ptp_early_bird_end_date');
        
        // Google Calendar settings
        register_setting('ptp_google_settings', 'ptp_google_client_id');
        register_setting('ptp_google_settings', 'ptp_google_client_secret');
        
        // SMS settings — OpenPhone
        register_setting('ptp_sms_settings', 'ptp_openphone_api_key');
        register_setting('ptp_sms_settings', 'ptp_openphone_from');
        register_setting('ptp_sms_settings', 'ptp_openphone_user_id');
        register_setting('ptp_sms_settings', 'ptp_sms_enabled');
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $active_tab = $_GET['tab'] ?? 'stripe';
        ?>
        <div class="wrap">
            <h1>PTP Training Platform Settings</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=ptp-settings-main&tab=stripe" class="nav-tab <?php echo $active_tab === 'stripe' ? 'nav-tab-active' : ''; ?>">
                    💳 Stripe Payments
                </a>
                <a href="?page=ptp-settings-main&tab=google" class="nav-tab <?php echo $active_tab === 'google' ? 'nav-tab-active' : ''; ?>">
                    📅 Google Calendar
                </a>
                <a href="?page=ptp-settings-main&tab=sms" class="nav-tab <?php echo $active_tab === 'sms' ? 'nav-tab-active' : ''; ?>">
                    📱 SMS (OpenPhone)
                </a>
            </nav>
            
            <div class="ptp-settings-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'google':
                        $this->render_google_settings();
                        break;
                    case 'sms':
                        $this->render_sms_settings();
                        break;
                    default:
                        $this->render_stripe_settings();
                }
                ?>
            </div>
        </div>
        
        <style>
            .ptp-settings-content {
                max-width: 800px;
            }
            .ptp-settings-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                margin-bottom: 20px;
            }
            .ptp-settings-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .ptp-field-row {
                margin-bottom: 15px;
            }
            .ptp-field-row label {
                display: block;
                font-weight: 600;
                margin-bottom: 5px;
            }
            .ptp-field-row input[type="text"],
            .ptp-field-row input[type="password"],
            .ptp-field-row input[type="number"] {
                width: 100%;
                max-width: 400px;
            }
            .ptp-field-row .description {
                color: #666;
                font-size: 13px;
                margin-top: 5px;
            }
            .ptp-test-btn {
                margin-left: 10px;
            }
            .ptp-status {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }
            .ptp-status.success {
                background: #d4edda;
                color: #155724;
            }
            .ptp-status.error {
                background: #f8d7da;
                color: #721c24;
            }
            .ptp-status.warning {
                background: #fff3cd;
                color: #856404;
            }
        </style>
        <?php
    }
    
    /**
     * Stripe settings section
     */
    private function render_stripe_settings() {
        $publishable = get_option('ptp_stripe_publishable_key', '');
        $secret = get_option('ptp_stripe_secret_key', '');
        $client_id = get_option('ptp_stripe_connect_client_id', '');
        $webhook = get_option('ptp_stripe_webhook_secret', '');
        $platform_fee = get_option('ptp_platform_fee_percent', 25);
        $processing_fee = get_option('ptp_processing_fee_percent', 3.2);
        
        $is_configured = !empty($publishable) && !empty($secret);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('ptp_stripe_settings'); ?>
            
            <div class="ptp-settings-section">
                <h2>Stripe API Keys</h2>
                <p>
                    Status: 
                    <?php if ($is_configured): ?>
                        <span class="ptp-status success">Connected</span>
                    <?php else: ?>
                        <span class="ptp-status warning">Not configured</span>
                    <?php endif; ?>
                </p>
                
                <div class="ptp-field-row">
                    <label>Publishable Key</label>
                    <input type="text" name="ptp_stripe_publishable_key" value="<?php echo esc_attr($publishable); ?>" placeholder="pk_...">
                    <p class="description">Find this in your Stripe Dashboard > Developers > API Keys</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Secret Key</label>
                    <input type="password" name="ptp_stripe_secret_key" value="<?php echo esc_attr($secret); ?>" placeholder="sk_...">
                    <p class="description">Keep this private. Never share or expose publicly.</p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Stripe Connect (For Trainer Payouts)</h2>
                
                <div class="ptp-field-row">
                    <label>Connect Client ID</label>
                    <input type="text" name="ptp_stripe_connect_client_id" value="<?php echo esc_attr($client_id); ?>" placeholder="ca_...">
                    <p class="description">Find this in Stripe Dashboard > Settings > Connect > OAuth</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Webhook Signing Secret</label>
                    <input type="password" name="ptp_stripe_webhook_secret" value="<?php echo esc_attr($webhook); ?>" placeholder="whsec_...">
                    <p class="description">
                        Webhook URL: <code><?php echo rest_url('ptp/v1/stripe-webhook'); ?></code>
                    </p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Fee Settings</h2>
                <p style="background:#FFFBEB;border:1px solid #FCB900;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;">
                    <strong>Graduated Commission Active:</strong> Sessions 1-2 = 50% PTP / 50% Trainer. Sessions 3-4 = 25% PTP / 75% Trainer. Session 5+ = 15% PTP / 85% Trainer. Per parent-trainer pair. The setting below is deprecated.
                </p>
                
                <div class="ptp-field-row">
                    <label>Fallback Fee (%) <em style="color:#999;font-weight:normal;">- Only when parent unknown</em></label>
                    <input type="number" name="ptp_platform_fee_percent" value="<?php echo esc_attr($platform_fee); ?>" min="0" max="50" step="0.5" style="width: 100px;">
                    <p class="description">Used only when parent-trainer pair can't be identified. Graduated schedule (50/25/15%) applies to all normal bookings.</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Customer Processing Fee (%)</label>
                    <input type="number" name="ptp_processing_fee_percent" value="<?php echo esc_attr($processing_fee); ?>" min="0" max="10" step="0.1" style="width: 100px;">
                    <p class="description">Fee added to customer's order total (default: 3.2%)</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Processing Fee Fixed ($)</label>
                    <?php $processing_fee_fixed = get_option('ptp_processing_fee_fixed', 0.30); ?>
                    <input type="number" name="ptp_processing_fee_fixed" value="<?php echo esc_attr($processing_fee_fixed); ?>" min="0" max="5" step="0.01" style="width: 100px;">
                    <p class="description">Fixed amount added to processing fee (default: $0.30)</p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Early Bird Discount</h2>
                
                <?php 
                $early_bird_enabled = get_option('ptp_early_bird_enabled', false);
                $early_bird_percent = get_option('ptp_early_bird_percent', 10);
                $early_bird_end_date = get_option('ptp_early_bird_end_date', '2026-02-16');
                ?>
                
                <div class="ptp-field-row">
                    <label>Early Bird Enabled</label>
                    <label style="display:inline-flex;align-items:center;gap:8px;font-weight:normal;">
                        <input type="checkbox" name="ptp_early_bird_enabled" value="1" <?php checked($early_bird_enabled); ?>>
                        Enable early bird discount for camp registrations
                    </label>
                </div>
                
                <div class="ptp-field-row">
                    <label>Discount Percentage (%)</label>
                    <input type="number" name="ptp_early_bird_percent" value="<?php echo esc_attr($early_bird_percent); ?>" min="0" max="50" step="1" style="width: 100px;">
                    <p class="description">Percentage discount off camp price (default: 10%)</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>End Date</label>
                    <input type="date" name="ptp_early_bird_end_date" value="<?php echo esc_attr($early_bird_end_date); ?>" style="width: 180px;">
                    <p class="description">Early bird discount ends on this date</p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Stripe Product Sync</h2>
                <p class="description">Sync camp products to Stripe so they appear in your Stripe Dashboard and can use Stripe Checkout.</p>
                
                <?php
                global $wpdb;
                $table = $wpdb->prefix . 'ptp_stripe_products';
                $total_products = 0;
                $synced_products = 0;
                
                if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                    $total_products = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE active = 1");
                    $synced_products = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE active = 1 AND stripe_product_id LIKE 'prod_%' AND stripe_product_id NOT LIKE 'prod_ptp_%'");
                }
                ?>
                
                <div style="background:#f5f5f5;border-radius:8px;padding:16px;margin-bottom:16px;">
                    <div style="display:flex;gap:24px;margin-bottom:12px;">
                        <div>
                            <strong style="font-size:24px;"><?php echo $total_products; ?></strong>
                            <span style="color:#666;display:block;font-size:12px;">Total Products</span>
                        </div>
                        <div>
                            <strong style="font-size:24px;color:#22c55e;"><?php echo $synced_products; ?></strong>
                            <span style="color:#666;display:block;font-size:12px;">Synced to Stripe</span>
                        </div>
                        <div>
                            <strong style="font-size:24px;color:<?php echo ($total_products - $synced_products) > 0 ? '#f59e0b' : '#22c55e'; ?>;"><?php echo $total_products - $synced_products; ?></strong>
                            <span style="color:#666;display:block;font-size:12px;">Pending Sync</span>
                        </div>
                    </div>
                    
                    <button type="button" id="ptp-sync-stripe-products" class="button button-primary" <?php echo empty($secret) ? 'disabled' : ''; ?>>
                        Sync All Products to Stripe
                    </button>
                    
                    <?php if (empty($secret)): ?>
                        <p style="color:#dc2626;margin-top:8px;font-size:12px;">Configure Stripe Secret Key first</p>
                    <?php endif; ?>
                    
                    <div id="ptp-sync-results" style="display:none;margin-top:12px;padding:12px;background:#fff;border-radius:4px;"></div>
                </div>
                
                <p class="description">
                    Or use URL trigger: <code><?php echo admin_url('?ptp_sync_stripe=1'); ?></code>
                </p>
            </div>
            
            <script>
            jQuery(function($) {
                $('#ptp-sync-stripe-products').on('click', function() {
                    var btn = $(this);
                    var results = $('#ptp-sync-results');
                    
                    btn.prop('disabled', true).text('Syncing...');
                    results.hide();
                    
                    $.post(ajaxurl, {
                        action: 'ptp_sync_stripe_products',
                        nonce: '<?php echo wp_create_nonce('ptp_admin_nonce'); ?>'
                    }, function(response) {
                        btn.prop('disabled', false).text('Sync All Products to Stripe');
                        
                        if (response.success) {
                            var data = response.data;
                            var html = '<strong>Sync Complete!</strong><br>';
                            html += 'Synced: ' + data.synced + '/' + data.total + ' products<br>';
                            if (data.errors > 0) {
                                html += '<span style="color:#dc2626;">Errors: ' + data.errors + '</span>';
                            }
                            results.html(html).css('border-left', '3px solid #22c55e').show();
                            
                            setTimeout(function() { location.reload(); }, 2000);
                        } else {
                            results.html('<strong style="color:#dc2626;">Error:</strong> ' + (response.data || 'Sync failed')).css('border-left', '3px solid #dc2626').show();
                        }
                    }).fail(function() {
                        btn.prop('disabled', false).text('Sync All Products to Stripe');
                        results.html('<strong style="color:#dc2626;">Request failed</strong>').css('border-left', '3px solid #dc2626').show();
                    });
                });
            });
            </script>
            
            <?php submit_button('Save Stripe Settings'); ?>
        </form>
        <?php
    }
    
    /**
     * Google Calendar settings
     */
    private function render_google_settings() {
        $client_id = get_option('ptp_google_client_id', '');
        $client_secret = get_option('ptp_google_client_secret', '');
        $is_configured = !empty($client_id) && !empty($client_secret);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('ptp_google_settings'); ?>
            
            <div class="ptp-settings-section">
                <h2>Google Calendar API</h2>
                <p>
                    Status: 
                    <?php if ($is_configured): ?>
                        <span class="ptp-status success">Configured</span>
                    <?php else: ?>
                        <span class="ptp-status warning">Not configured</span>
                    <?php endif; ?>
                </p>
                
                <div class="ptp-field-row">
                    <label>Client ID</label>
                    <input type="text" name="ptp_google_client_id" value="<?php echo esc_attr($client_id); ?>">
                    <p class="description">From Google Cloud Console > APIs & Services > Credentials</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>Client Secret</label>
                    <input type="password" name="ptp_google_client_secret" value="<?php echo esc_attr($client_secret); ?>">
                </div>
                
                <div class="ptp-field-row">
                    <label>OAuth Redirect URIs</label>
                    <p><strong>For Calendar Sync:</strong></p>
                    <code><?php echo admin_url('admin-ajax.php?action=ptp_google_oauth_callback'); ?></code>
                    <p style="margin-top:8px;"><strong>For "Continue with Google" Login:</strong></p>
                    <code><?php echo admin_url('admin-ajax.php?action=ptp_google_login_callback'); ?></code>
                    <p class="description" style="margin-top:12px;">Add BOTH URIs to your Google Cloud OAuth consent screen's Authorized redirect URIs.</p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Setup Instructions</h2>
                <ol>
                    <li>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                    <li>Create a project or select existing</li>
                    <li>Enable the Google Calendar API</li>
                    <li>Create OAuth 2.0 credentials</li>
                    <li>Add the redirect URI above to your credentials</li>
                    <li>Copy Client ID and Secret here</li>
                </ol>
            </div>
            
            <?php submit_button('Save Google Settings'); ?>
        </form>
        <?php
    }
    
    /**
     * SMS settings — OpenPhone
     */
    private function render_sms_settings() {
        $api_key = get_option('ptp_openphone_api_key', 'fRwRNgl2ynRQFy5oBUhvRuZtQA2Cz4CS');
        $from = get_option('ptp_openphone_from', '+16106714778');
        $user_id = get_option('ptp_openphone_user_id', '');
        $enabled = get_option('ptp_sms_enabled', '1');
        $is_configured = !empty($api_key) && !empty($from);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('ptp_sms_settings'); ?>
            
            <div class="ptp-settings-section">
                <h2>OpenPhone SMS</h2>
                <p>
                    Status: 
                    <?php if ($is_configured && $enabled): ?>
                        <span class="ptp-status success">Enabled</span>
                        <button type="button" class="button ptp-test-btn" id="ptp-test-openphone">Test Connection</button>
                    <?php elseif ($is_configured): ?>
                        <span class="ptp-status warning">Configured but disabled</span>
                    <?php else: ?>
                        <span class="ptp-status error">Not configured</span>
                    <?php endif; ?>
                </p>
                <div id="ptp-openphone-test-result" style="display:none;margin:10px 0;padding:10px;border-radius:4px;font-size:13px;"></div>
                
                <div class="ptp-field-row">
                    <label>
                        <input type="checkbox" name="ptp_sms_enabled" value="1" <?php checked($enabled, '1'); ?>>
                        Enable SMS Notifications
                    </label>
                </div>
                
                <div class="ptp-field-row">
                    <label>API Key</label>
                    <input type="password" name="ptp_openphone_api_key" value="<?php echo esc_attr($api_key); ?>" placeholder="sk_op_...">
                    <p class="description">From <a href="https://app.openphone.com/settings/api" target="_blank">OpenPhone > Settings > API Keys</a></p>
                </div>
                
                <div class="ptp-field-row">
                    <label>From Phone Number / Phone Number ID</label>
                    <input type="text" name="ptp_openphone_from" value="<?php echo esc_attr($from); ?>" placeholder="+1234567890 or PNxxxxxxxx">
                    <p class="description">E.164 phone number or OpenPhone Phone Number ID (starts with PN). The test button below will show your available numbers.</p>
                </div>
                
                <div class="ptp-field-row">
                    <label>User ID (optional)</label>
                    <input type="text" name="ptp_openphone_user_id" value="<?php echo esc_attr($user_id); ?>" placeholder="USxxxxxxxx">
                    <p class="description">Defaults to the phone number owner. Only needed if multiple users share one number.</p>
                </div>
            </div>
            
            <div class="ptp-settings-section">
                <h2>Webhook Setup</h2>
                <p class="description">To receive incoming SMS and delivery confirmations, add this webhook in OpenPhone:</p>
                <ol>
                    <li>Go to <a href="https://app.openphone.com/settings/webhooks" target="_blank">OpenPhone > Settings > Webhooks</a></li>
                    <li>Add webhook URL: <code><?php echo rest_url('ptp/v1/openphone/webhook'); ?></code></li>
                    <li>Enable events: <strong>message.received</strong>, <strong>message.delivered</strong></li>
                    <li>Save</li>
                </ol>
                <p class="description" style="margin-top:8px;">
                    Also configure in Chatbot API webhook: <code><?php echo rest_url('ptp/v1/chatbot/sms-webhook'); ?></code>
                </p>
            </div>
            
            <script>
            jQuery(function($) {
                $('#ptp-test-openphone').on('click', function() {
                    var btn = $(this);
                    var result = $('#ptp-openphone-test-result');
                    btn.prop('disabled', true).text('Testing...');
                    result.hide();
                    
                    $.post(ajaxurl, {
                        action: 'ptp_test_openphone_connection',
                        nonce: '<?php echo wp_create_nonce('ptp_admin_nonce'); ?>'
                    }, function(response) {
                        btn.prop('disabled', false).text('Test Connection');
                        if (response.success) {
                            result.html('<strong>Connected!</strong> ' + response.data.message)
                                  .css({background: '#d4edda', color: '#155724', border: '1px solid #c3e6cb'}).show();
                        } else {
                            result.html('<strong>Failed:</strong> ' + (response.data || 'Unknown error'))
                                  .css({background: '#f8d7da', color: '#721c24', border: '1px solid #f5c6cb'}).show();
                        }
                    }).fail(function() {
                        btn.prop('disabled', false).text('Test Connection');
                        result.html('<strong>Request failed</strong>')
                              .css({background: '#f8d7da', color: '#721c24', border: '1px solid #f5c6cb'}).show();
                    });
                });
            });
            </script>
            
            <?php submit_button('Save SMS Settings'); ?>
        </form>
        <?php
    }
    
    /**
     * Test Stripe connection
     */
    public function test_stripe_connection() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $secret_key = get_option('ptp_stripe_secret_key');
        if (empty($secret_key)) {
            wp_send_json_error('Secret key not configured');
        }
        
        try {
            $stripe_init = PTP_PLUGIN_DIR . 'vendor/stripe/stripe-php/init.php';
            if (!file_exists($stripe_init)) {
                wp_send_json_error(array('message' => 'Stripe PHP SDK not installed. Run composer install.'));
                return;
            }
            require_once $stripe_init;
            \Stripe\Stripe::setApiKey($secret_key);
            
            $account = \Stripe\Account::retrieve();
            
            wp_send_json_success(array(
                'message' => 'Connected to ' . $account->business_profile->name,
                'account_id' => $account->id
            ));
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Test OpenPhone connection
     */
    public function test_openphone_connection() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $api_key = get_option('ptp_openphone_api_key', 'fRwRNgl2ynRQFy5oBUhvRuZtQA2Cz4CS');
        
        if (empty($api_key)) {
            wp_send_json_error('OpenPhone API key not configured');
        }
        
        $response = wp_remote_get('https://api.openphone.com/v1/phone-numbers', array(
            'headers' => array(
                'Authorization' => $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 401) {
            wp_send_json_error('Invalid API key');
        }
        
        if ($code === 402) {
            wp_send_json_error('Insufficient OpenPhone credits. Add credits at app.openphone.com');
        }
        
        if ($code >= 200 && $code < 300) {
            $numbers = $body['data'] ?? array();
            $number_list = array();
            foreach ($numbers as $num) {
                $number_list[] = ($num['formattedNumber'] ?? $num['phoneNumber'] ?? 'Unknown') . ' (ID: ' . ($num['id'] ?? '?') . ')';
            }
            $msg = count($numbers) . ' phone number(s) found';
            if (!empty($number_list)) {
                $msg .= ': ' . implode(', ', array_slice($number_list, 0, 5));
            }
            wp_send_json_success(array('message' => $msg, 'numbers' => $numbers));
        }
        
        wp_send_json_error('HTTP ' . $code . ': ' . ($body['message'] ?? 'Unknown error'));
    }
}

// Initialize
PTP_Admin_Settings_V71::instance();
