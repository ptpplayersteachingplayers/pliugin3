<?php
/**
 * PTP Admin Tools Page
 * 
 * Provides admin interface for:
 * - Database repair
 * - Stripe Connect management
 * - Cache configuration
 * - System diagnostics
 * 
 * @version 72.0.0
 */

defined('ABSPATH') || exit;

class PTP_Admin_Tools_V72 {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu_pages'), 99);
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('wp_ajax_ptp_run_repair', array($this, 'ajax_run_repair'));
        add_action('wp_ajax_ptp_resend_stripe_link', array($this, 'ajax_resend_stripe_link'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_menu_pages() {
        add_submenu_page(
            'ptp-training',
            'Tools & Diagnostics',
            'Tools',
            'manage_options',
            'ptp-tools',
            array($this, 'render_tools_page')
        );
    }
    
    /**
     * Handle form actions
     */
    public function handle_actions() {
        if (!isset($_POST['ptp_tools_action'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['ptp_tools_nonce'], 'ptp_tools_action')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $action = sanitize_text_field($_POST['ptp_tools_action']);
        
        switch ($action) {
            case 'repair_tables':
                $this->do_repair_tables();
                break;
            case 'add_indexes':
                $this->do_add_indexes();
                break;
            case 'clear_repair_cache':
                delete_option('ptp_last_auto_repair');
                add_settings_error('ptp_tools', 'cache_cleared', 'Auto-repair cache cleared. Repair will run on next page load.', 'success');
                break;
            case 'regenerate_profiles':
                $this->do_regenerate_profiles();
                break;
            case 'recreate_database':
                $this->do_recreate_database();
                break;
        }
    }
    
    /**
     * Render tools page
     */
    public function render_tools_page() {
        ?>
        <div class="wrap">
            <h1>PTP Training Platform - Tools & Diagnostics</h1>
            
            <?php settings_errors('ptp_tools'); ?>
            
            <div class="ptp-tools-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-top: 20px;">
                
                <!-- Database Repair Section -->
                <div class="ptp-tool-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2 style="margin-top: 0;">🔧 Database Repair</h2>
                    <p>Automatically add missing columns to database tables. Safe to run multiple times.</p>
                    
                    <?php $this->render_table_status(); ?>
                    
                    <form method="post" style="margin-top: 15px;">
                        <?php wp_nonce_field('ptp_tools_action', 'ptp_tools_nonce'); ?>
                        <input type="hidden" name="ptp_tools_action" value="repair_tables">
                        <button type="submit" class="button button-primary">Run Table Repair</button>
                    </form>
                    
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('ptp_tools_action', 'ptp_tools_nonce'); ?>
                        <input type="hidden" name="ptp_tools_action" value="add_indexes">
                        <button type="submit" class="button">Add Performance Indexes</button>
                    </form>
                    
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('ptp_tools_action', 'ptp_tools_nonce'); ?>
                        <input type="hidden" name="ptp_tools_action" value="clear_repair_cache">
                        <button type="submit" class="button">Clear Auto-Repair Cache</button>
                    </form>
                    
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('ptp_tools_action', 'ptp_tools_nonce'); ?>
                        <input type="hidden" name="ptp_tools_action" value="regenerate_profiles">
                        <button type="submit" class="button button-secondary" style="background:#FCB900;border-color:#e5a800;color:#000;font-weight:600;">Regenerate All Trainer Profiles</button>
                    </form>
                    <p style="margin-top:6px;color:#666;font-size:12px;">Re-slugs all trainers, flushes caches, rewrite rules, and forces CSS reload.</p>
                    
                    <p style="margin-top: 15px; color: #666; font-size: 12px;">
                        <strong>Last auto-repair:</strong> 
                        <?php 
                        $last = get_option('ptp_last_auto_repair', 0);
                        echo $last ? date('Y-m-d H:i:s', $last) : 'Never';
                        ?>
                    </p>
                </div>
                
                
                <!-- Database Recreate Section -->
                <div class="ptp-tool-card" style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:4px">
                    <h2 style="margin-top:0">Recreate Database Tables</h2>
                    <p>Drops ALL PTP tables and recreates from canonical schema.</p>
                    <p style="color:#d63638;font-weight:600">WARNING: Deletes all PTP data. Back up first.</p>
                    <?php global $wpdb;$ptp_tbl=$wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}ptp_%'");if($ptp_tbl):?>
                    <details style="margin:12px 0"><summary style="cursor:pointer;font-weight:600">Tables to drop (<?php echo count($ptp_tbl);?>)</summary>
                    <ul style="background:#f6f7f7;padding:15px 15px 15px 35px;border-radius:4px;margin:8px 0;font-size:13px">
                    <?php foreach($ptp_tbl as $t):?><li><code><?php echo esc_html($t);?></code></li><?php endforeach;?>
                    </ul></details><?php endif;?>
                    <form method="post" style="margin-top:15px" onsubmit="return confirm('Drop and recreate ALL PTP tables? Cannot be undone.')">
                    <?php wp_nonce_field('ptp_tools_action','ptp_tools_nonce');?>
                    <input type="hidden" name="ptp_tools_action" value="recreate_database">
                    <button type="submit" class="button" style="background:#d63638;border-color:#d63638;color:#fff">Drop and Recreate All Tables</button>
                    </form></div>
                
                <!-- Stripe Connect Section -->
                <div class="ptp-tool-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2 style="margin-top: 0;">💳 Stripe Connect Status</h2>
                    <p>Trainers who haven't completed Stripe onboarding can't receive payouts.</p>
                    
                    <?php $this->render_stripe_incomplete_trainers(); ?>
                </div>
                
                <!-- Cache Configuration Section -->
                <div class="ptp-tool-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2 style="margin-top: 0;">🚀 Cache Configuration</h2>
                    <p>These pages should be excluded from caching for proper functionality:</p>
                    
                    <ul style="background: #f6f7f7; padding: 15px 15px 15px 35px; border-radius: 4px; margin: 15px 0;">
                        <li><code>/trainer-dashboard/</code></li>
                        <li><code>/parent-dashboard/</code></li>
                        <li><code>/training-checkout/</code></li>
                        <li><code>/checkout/</code></li>
                        <li><code>/cart/</code></li>
                        <li><code>/messages/</code></li>
                        <li><code>/account/</code></li>
                        <li><code>/login/</code></li>
                        <li><code>/register/</code></li>
                        <li><code>/booking-confirmation/</code></li>
                    </ul>
                    
                    <?php $this->render_cache_plugin_status(); ?>
                </div>
                
                <!-- System Status Section -->
                <div class="ptp-tool-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2 style="margin-top: 0;">📊 System Status</h2>
                    
                    <table class="widefat" style="margin-top: 15px;">
                        <tbody>
                            <tr>
                                <td><strong>Plugin Version</strong></td>
                                <td><?php echo PTP_VERSION; ?></td>
                            </tr>
                            <tr>
                                <td><strong>PHP Version</strong></td>
                                <td><?php echo PHP_VERSION; ?> <?php echo version_compare(PHP_VERSION, '8.2', '>=') ? '✅' : '⚠️ (8.2+ recommended)'; ?></td>
                            </tr>
                            <tr>
                                <td><strong>WordPress Version</strong></td>
                                <td><?php echo get_bloginfo('version'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>PTP Native</strong></td>
                                <td><?php echo true ? PTP_VERSION . ' ✅' : 'Not Active ⚠️'; ?></td>
                            </tr>
                            <tr>
                                <td><strong>OPcache</strong></td>
                                <td><?php echo function_exists('opcache_get_status') && opcache_get_status() ? 'Enabled ✅' : 'Not Available'; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Memory Limit</strong></td>
                                <td><?php echo ini_get('memory_limit'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Max Execution Time</strong></td>
                                <td><?php echo ini_get('max_execution_time'); ?>s</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <h3 style="margin-top: 20px;">API Keys Status</h3>
                    <table class="widefat">
                        <tbody>
                            <tr>
                                <td><strong>Stripe Live</strong></td>
                                <td><?php 
                                    $stripe_live = get_option('ptp_stripe_secret_key', '');
                                    echo $stripe_live && strpos($stripe_live, 'sk_live') === 0 ? '✅ Configured' : '⚠️ Not configured';
                                ?></td>
                            </tr>
                            <tr>
                                <td><strong>Stripe Connect</strong></td>
                                <td><?php 
                                    $stripe_connect = get_option('ptp_stripe_connect_client_id', '');
                                    echo $stripe_connect ? '✅ Configured' : '⚠️ Not configured';
                                ?></td>
                            </tr>
                            <tr>
                                <td><strong>OpenPhone SMS</strong></td>
                                <td><?php 
                                    $op_key = get_option('ptp_openphone_api_key', '');
                                    echo $op_key ? '✅ Configured' : '⚠️ Not configured';
                                ?></td>
                            </tr>
                            <tr>
                                <td><strong>Google Calendar</strong></td>
                                <td><?php 
                                    $gcal = get_option('ptp_google_client_id', '');
                                    echo $gcal ? '✅ Configured' : 'ℹ️ Optional';
                                ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Template Versions Section -->
                <div class="ptp-tool-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h2 style="margin-top: 0;">📄 Template Versions</h2>
                    <p>Active template files (v71 templates are preferred):</p>
                    
                    <?php $this->render_template_status(); ?>
                </div>
                
            </div>
        </div>
        
        <style>
            .ptp-tool-card code {
                background: #e5e5e5;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }
            .ptp-status-ok { color: #00a32a; }
            .ptp-status-warning { color: #dba617; }
            .ptp-status-error { color: #d63638; }
        </style>
        <?php
    }
    
    /**
     * Render table status
     */
    private function render_table_status() {
        global $wpdb;
        
        $tables = array(
            'ptp_trainers',
            'ptp_bookings', 
            'ptp_parents',
            'ptp_players',
            'ptp_conversations',
            'ptp_messages',
            'ptp_availability',
            'ptp_availability_exceptions',
            'ptp_reviews',
            'ptp_payouts',
            'ptp_escrow',
            'ptp_applications',
        );
        
        echo '<table class="widefat" style="margin-top: 15px;"><thead><tr><th>Table</th><th>Status</th><th>Rows</th></tr></thead><tbody>';
        
        foreach ($tables as $table) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
            
            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $full_table");
                echo "<tr><td><code>$table</code></td><td class='ptp-status-ok'>✅ OK</td><td>$count</td></tr>";
            } else {
                echo "<tr><td><code>$table</code></td><td class='ptp-status-error'>❌ Missing</td><td>-</td></tr>";
            }
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Render Stripe incomplete trainers
     */
    private function render_stripe_incomplete_trainers() {
        if (class_exists('PTP_Fixes_V72')) {
            $trainers = PTP_Fixes_V72::get_incomplete_stripe_trainers();
        } else {
            global $wpdb;
            $trainers = $wpdb->get_results("
                SELECT * FROM {$wpdb->prefix}ptp_trainers 
                WHERE status = 'active' 
                AND (stripe_onboarding_complete = 0 OR stripe_account_id IS NULL OR stripe_account_id = '')
            ");
        }
        
        if (empty($trainers)) {
            echo '<p style="color: #00a32a;">✅ All active trainers have completed Stripe setup!</p>';
            return;
        }
        
        echo '<p class="ptp-status-warning">⚠️ ' . count($trainers) . ' trainer(s) need to complete Stripe setup:</p>';
        echo '<table class="widefat" style="margin-top: 10px;"><thead><tr><th>Trainer</th><th>Reminders</th><th>Action</th></tr></thead><tbody>';
        
        foreach ($trainers as $trainer) {
            $reminders = intval($trainer->stripe_reminder_count ?? 0);
            $last = $trainer->stripe_reminder_sent_at ?? 'Never';
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($trainer->display_name) . '</strong><br><small>' . esc_html($trainer->email ?? '') . '</small></td>';
            echo '<td>' . $reminders . ' sent<br><small>Last: ' . esc_html($last) . '</small></td>';
            echo '<td><button class="button ptp-resend-stripe" data-trainer-id="' . esc_attr($trainer->id) . '">Send Reminder</button></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.ptp-resend-stripe').on('click', function() {
                var btn = $(this);
                var trainerId = btn.data('trainer-id');
                
                btn.prop('disabled', true).text('Sending...');
                
                $.post(ajaxurl, {
                    action: 'ptp_resend_stripe_link',
                    trainer_id: trainerId,
                    nonce: '<?php echo wp_create_nonce('ptp_admin_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        btn.text('Sent ✓');
                    } else {
                        btn.prop('disabled', false).text('Failed');
                        alert(response.data || 'Failed to send reminder');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render cache plugin status
     */
    private function render_cache_plugin_status() {
        $plugins = array(
            'WP Rocket' => defined('WP_ROCKET_VERSION'),
            'W3 Total Cache' => defined('W3TC'),
            'LiteSpeed Cache' => defined('LSCWP_V'),
            'WP Super Cache' => defined('WPCACHEHOME'),
            'Autoptimize' => defined('AUTOPTIMIZE_PLUGIN_VERSION'),
        );
        
        $active_caching = array_filter($plugins);
        
        if (empty($active_caching)) {
            echo '<p>ℹ️ No caching plugin detected. Cache exclusions are handled via HTTP headers.</p>';
            return;
        }
        
        echo '<p><strong>Detected caching plugins:</strong></p><ul>';
        foreach ($active_caching as $name => $active) {
            echo '<li>✅ ' . esc_html($name) . ' - <span class="ptp-status-ok">Auto-configured</span></li>';
        }
        echo '</ul>';
        echo '<p style="color: #666; font-size: 12px;">PTP automatically adds exclusion rules for these plugins.</p>';
    }
    
    /**
     * Render template status
     */
    private function render_template_status() {
        $templates = array(
            'trainer-profile-v177.php' => 'Trainer Profile',
            'trainer-dashboard-v200.php' => 'Trainer Dashboard',
            'parent-dashboard-v200.php' => 'Parent Dashboard',
            'trainers-grid.php' => 'Find Trainer',
            'ptp-cart.php' => 'Cart',
            'messaging.php' => 'Messaging',
            'thank-you-v175.php' => 'Thank You',
        );
        
        echo '<table class="widefat" style="margin-top: 10px;"><thead><tr><th>Template</th><th>Status</th></tr></thead><tbody>';
        
        foreach ($templates as $file => $name) {
            $path = PTP_PLUGIN_DIR . 'templates/' . $file;
            $exists = file_exists($path);
            
            echo '<tr>';
            echo '<td>' . esc_html($name) . '<br><small><code>' . esc_html($file) . '</code></small></td>';
            echo '<td>' . ($exists ? '<span class="ptp-status-ok">✅ Available</span>' : '<span class="ptp-status-error">❌ Missing</span>') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
    /**
     * Regenerate all trainer profiles: flush slugs, clear caches, flush rewrite rules
     */
    private function do_regenerate_profiles() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_trainers';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            add_settings_error('ptp_tools', 'no_table', 'Trainers table not found.', 'error');
            return;
        }
        $trainers = $wpdb->get_results("SELECT id, display_name, slug FROM $table WHERE status = 'active'");
        $fixed = 0;
        foreach ($trainers as $t) {
            $new_slug = sanitize_title($t->display_name ?: 'trainer-' . $t->id);
            // Check for duplicates
            $dupe = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE slug = %s AND id != %d", $new_slug, $t->id
            ));
            if ($dupe) $new_slug .= '-' . $t->id;
            if ($t->slug !== $new_slug || empty($t->slug)) {
                $wpdb->update($table, array('slug' => $new_slug), array('id' => $t->id));
                $fixed++;
            }
            // Clear individual caches
            delete_transient('ptp_trainer_slug_' . sanitize_key($t->slug));
            delete_transient('ptp_trainer_slug_' . sanitize_key($new_slug));
            delete_transient('ptp_profile_reviews_' . $t->id);
        }
        // Clear global caches
        delete_transient('ptp_active_trainers');
        delete_transient('ptp_trainers_grid');
        // Flush rewrite rules so /trainer/slug/ routes work
        flush_rewrite_rules();
        // Force CSS cache bust
        update_option('ptp_plugin_version', PTP_VERSION . '.' . time());
        add_settings_error('ptp_tools', 'profiles_regenerated',
            count($trainers) . ' trainer profiles regenerated. ' . $fixed . ' slug(s) updated. Caches cleared. Rewrite rules flushed.',
            'success'
        );
    }

    /**
     * Do table repair
     */
    private function do_repair_tables() {
        if (class_exists('PTP_Fixes_V72')) {
            $fixes = PTP_Fixes_V72::instance();
            $repairs = $fixes->comprehensive_table_repair();
            add_settings_error('ptp_tools', 'repair_complete', "Table repair complete. $repairs column(s) added.", 'success');
        } elseif (class_exists('PTP_Database')) {
            PTP_Database::repair_tables();
            add_settings_error('ptp_tools', 'repair_complete', 'Table repair complete (legacy method).', 'success');
        } else {
            add_settings_error('ptp_tools', 'repair_error', 'Database class not found.', 'error');
        }
    }
    
    /**
     * Add performance indexes
     */
    private function do_add_indexes() {
        if (class_exists('PTP_Database') && method_exists('PTP_Database', 'add_performance_indexes')) {
            $count = PTP_Database::add_performance_indexes();
            add_settings_error('ptp_tools', 'indexes_added', "$count index(es) added for performance.", 'success');
        } else {
            add_settings_error('ptp_tools', 'indexes_error', 'Index method not available.', 'error');
        }
    }
    
    /**
     * AJAX: Run repair
     */
    public function ajax_run_repair() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $this->do_repair_tables();
        wp_send_json_success('Repair complete');
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
        
        if (class_exists('PTP_Fixes_V72')) {
            $result = PTP_Fixes_V72::admin_resend_stripe_link($trainer_id);
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }
            
            wp_send_json_success('Reminder sent');
        } else {
            wp_send_json_error('Fixes class not loaded');
        }
    }
    private function do_recreate_database(){
        global $wpdb;
        $tables=$wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}ptp_%'");
        $dropped=0;$errors=[];
        foreach($tables as $table){
            if(strpos($table,$wpdb->prefix.'ptp_')!==0)continue;
            $r=$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            if($r===false){$errors[]=$table;}else{$dropped++;}
        }
        if(class_exists('PTP_Database')){
            PTP_Database::create_tables();
            if(method_exists('PTP_Database','maybe_migrate'))PTP_Database::maybe_migrate();
        }
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ptp_%' OR option_name LIKE '_transient_timeout_ptp_%'");
        delete_option('ptp_last_auto_repair');delete_option('ptp_db_version');
        $new=count($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}ptp_%'"));
        if($errors){add_settings_error('ptp_tools','re_err',"Dropped {$dropped}, recreated {$new}. Errors: ".implode(', ',$errors),'warning');}
        else{add_settings_error('ptp_tools','re_ok',"Dropped {$dropped} old tables, created {$new} fresh tables.",'success');}
    }

}

// Initialize
add_action('plugins_loaded', array('PTP_Admin_Tools_V72', 'instance'), 15);
