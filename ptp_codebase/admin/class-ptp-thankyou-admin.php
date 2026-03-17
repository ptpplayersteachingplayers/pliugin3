<?php
/**
 * PTP Thank You Page Admin Settings v100
 * 
 * Admin settings for configuring the thank you page features:
 * - Upsell product and pricing
 * - Social announcement settings
 * - Feature toggles
 * 
 * @since 100.0.0
 */

defined('ABSPATH') || exit;

class PTP_ThankYou_Admin {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Add admin menu - DISABLED: Now part of Settings > Thank You tab
     */
    public function add_admin_menu() {
        // v223.1: Removed deprecated admin menu — consolidated into Settings > Thank You tab
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Training CTA settings (replaces upsell)
        register_setting('ptp_thankyou_settings', 'ptp_thankyou_training_cta_enabled');
        register_setting('ptp_thankyou_settings', 'ptp_thankyou_training_cta_url');
        
        // Announcement settings
        register_setting('ptp_thankyou_settings', 'ptp_thankyou_announcement_enabled');
        register_setting('ptp_thankyou_settings', 'ptp_instagram_handle');
        
        // Referral settings (uses existing referral system)
        register_setting('ptp_thankyou_settings', 'ptp_thankyou_referral_enabled');
        register_setting('ptp_thankyou_settings', 'ptp_referral_amount');
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Get current values
        $training_cta_enabled = get_option('ptp_thankyou_training_cta_enabled', 'yes');
        $training_cta_url = get_option('ptp_thankyou_training_cta_url', '/find-trainers/');
        
        $announcement_enabled = get_option('ptp_thankyou_announcement_enabled', 'yes');
        $instagram_handle = get_option('ptp_instagram_handle', '@ptpsoccercamps');
        
        $referral_enabled = get_option('ptp_thankyou_referral_enabled', 'yes');
        $referral_amount = get_option('ptp_referral_amount', 25);
        
        // Get stats
        global $wpdb;
        
        $announcements_table = $wpdb->prefix . 'ptp_social_announcements';
        $announcement_count = 0;
        $announcement_posted = 0;
        $announcement_pending = 0;
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$announcements_table'") === $announcements_table) {
            $announcement_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $announcements_table") ?: 0;
            $announcement_posted = (int) $wpdb->get_var("SELECT COUNT(*) FROM $announcements_table WHERE status = 'posted'") ?: 0;
            $announcement_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $announcements_table WHERE status = 'pending'") ?: 0;
        }
        
        // Get referral stats from shares table
        $shares_table = $wpdb->prefix . 'ptp_shares';
        $total_shares = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$shares_table'") === $shares_table) {
            $total_shares = (int) $wpdb->get_var("SELECT COUNT(*) FROM $shares_table WHERE share_type = 'thank_you_page'") ?: 0;
        }
        
        ?>
        <div class="wrap">
            <h1>🎉 Thank You Page Settings</h1>
            <p style="color:#666;margin-bottom:30px;">
                Configure the viral thank you page that drives referrals, social shares, and training bookings.
            </p>
            
            <!-- Stats Cards -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:40px;">
                <div style="background:#fff;padding:24px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#FCB900;"><?php echo $announcement_pending; ?></div>
                    <div style="color:#666;font-size:14px;">Pending Announcements</div>
                </div>
                <div style="background:#fff;padding:24px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#22C55E;"><?php echo $announcement_posted; ?></div>
                    <div style="color:#666;font-size:14px;">Posts Published</div>
                </div>
                <div style="background:#fff;padding:24px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#E1306C;"><?php echo $announcement_count; ?></div>
                    <div style="color:#666;font-size:14px;">Total IG Opt-ins</div>
                </div>
                <div style="background:#fff;padding:24px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size:32px;font-weight:700;color:#3B82F6;"><?php echo $total_shares; ?></div>
                    <div style="color:#666;font-size:14px;">Referral Shares</div>
                </div>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('ptp_thankyou_settings'); ?>
                
                <!-- Training CTA Settings -->
                <div style="background:#fff;padding:32px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:24px;">
                    <h2 style="font-family:'Oswald',sans-serif;font-size:20px;margin:0 0 8px;display:flex;align-items:center;gap:10px;">
                        ⚡ Private Training CTA
                    </h2>
                    <p style="color:#666;margin:0 0 24px;">Drive camp registrants to book private training sessions.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Training CTA</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ptp_thankyou_training_cta_enabled" value="yes" <?php checked($training_cta_enabled, 'yes'); ?>>
                                    Show "Book Private Training" section on thank you page
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">CTA Destination</th>
                            <td>
                                <input type="text" name="ptp_thankyou_training_cta_url" value="<?php echo esc_attr($training_cta_url); ?>" style="width:300px;" placeholder="/find-trainers/">
                                <p class="description">URL where the "Browse Trainers" button links to. For training bookings, this is overridden to link back to the same trainer.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <div style="margin-top:20px;padding:16px;background:#FEF3C7;border-left:4px solid #FCB900;border-radius:4px;">
                        <strong style="display:block;margin-bottom:4px;">💡 Why not a fixed-price upsell?</strong>
                        <p style="margin:0;color:#666;font-size:13px;">Since trainers set their own prices, we link to /find-trainers/ where parents can browse available coaches and pricing. For training session thank-you pages, we automatically link back to the same trainer.</p>
                    </div>
                </div>
                
                <!-- Social Announcement Settings -->
                <div style="background:#fff;padding:32px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:24px;">
                    <h2 style="font-family:'Oswald',sans-serif;font-size:20px;margin:0 0 8px;display:flex;align-items:center;gap:10px;">
                        📸 Social Announcements
                    </h2>
                    <p style="color:#666;margin:0 0 24px;">Let parents opt-in to have their registration announced on your Instagram.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Announcements</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ptp_thankyou_announcement_enabled" value="yes" <?php checked($announcement_enabled, 'yes'); ?>>
                                    Show announcement opt-in on thank you page
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Your Instagram Handle</th>
                            <td>
                                <input type="text" name="ptp_instagram_handle" value="<?php echo esc_attr($instagram_handle); ?>" style="width:200px;" placeholder="@ptpsoccercamps">
                                <p class="description">Your Instagram account where announcements are posted.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <div style="margin-top:20px;padding:16px;background:#F9FAFB;border-radius:8px;">
                        <strong style="display:block;margin-bottom:8px;">📋 How It Works:</strong>
                        <ol style="margin:0;padding-left:20px;color:#666;font-size:14px;">
                            <li>Parent opts-in on thank you page with their Instagram handle</li>
                            <li>Announcement is added to queue (<a href="<?php echo admin_url('admin.php?page=ptp-announcements'); ?>">📸 Announcements</a>)</li>
                            <li>You or your VA posts to Instagram using the template + tags the parent</li>
                            <li>Parent's network sees it → FOMO → more registrations</li>
                        </ol>
                    </div>
                </div>
                
                <!-- Referral Settings -->
                <div style="background:#fff;padding:32px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:24px;">
                    <h2 style="font-family:'Oswald',sans-serif;font-size:20px;margin:0 0 8px;display:flex;align-items:center;gap:10px;">
                        🎁 Referral Program
                    </h2>
                    <p style="color:#666;margin:0 0 24px;">Give $25, Get $25 - shown prominently on thank you page.</p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Referral Section</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ptp_thankyou_referral_enabled" value="yes" <?php checked($referral_enabled, 'yes'); ?>>
                                    Show referral program on thank you page
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Referral Amount</th>
                            <td>
                                <input type="number" name="ptp_referral_amount" value="<?php echo esc_attr($referral_amount); ?>" style="width:100px;" step="1" min="0">
                                <span style="color:#666;margin-left:8px;">$ credit for both referrer and referee</span>
                            </td>
                        </tr>
                    </table>
                    
                    <p style="margin-top:16px;color:#666;font-size:13px;">
                        ℹ️ Referral tracking uses the existing PTP Referral System (Admin → PTP → 🎁 Referrals)
                    </p>
                </div>
                
                <?php submit_button('Save Settings'); ?>
            </form>
            
            <!-- Preview Link -->
            <div style="background:#0A0A0A;padding:24px;border-radius:12px;margin-top:30px;">
                <h3 style="color:#FCB900;font-family:'Oswald',sans-serif;margin:0 0 12px;">Preview Thank You Page</h3>
                <p style="color:rgba(255,255,255,0.7);margin:0 0 16px;">To see the thank you page in action, complete a test purchase or use this URL with a recent order ID:</p>
                <code style="display:block;background:#1a1a1a;padding:12px 16px;border-radius:6px;color:#FCB900;font-size:13px;">
                    <?php echo home_url('/thank-you/?order_id=YOUR_ORDER_ID'); ?>
                </code>
            </div>
        </div>
        <?php
    }
}

// Initialize immediately when file is loaded (only in admin)
// Loader handles timing via plugins_loaded
if (is_admin() && class_exists('PTP_ThankYou_Admin')) {
    PTP_ThankYou_Admin::instance();
}
