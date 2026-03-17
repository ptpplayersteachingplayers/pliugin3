<?php
/**
 * PTP Page Creator Tool v147
 * 
 * Auto-creates and recreates all required PTP pages with correct shortcodes.
 * Pages are auto-created on plugin activation.
 * Access manual controls via: WP Admin > PTP > Page Setup
 * 
 * @since 86.0.0
 * @updated 147.0.0 - Auto-creation on activation like PTP Camps plugin
 */

defined('ABSPATH') || exit;

class PTP_Page_Creator {
    
    private static $instance = null;
    
    // All required PTP pages - comprehensive list
    private static $pages = array(
        // ==========================================
        // MAIN TRAINING PAGES
        // v216: /training/ removed — homepage (/) serves training-hub directly
        //       via PTP_Shortcodes::intercept_training_page()
        // ==========================================
        // v231: Free session landing pages removed
        'find-trainers' => array(
            'title' => 'Find Trainers',
            'slug' => 'find-trainers',
            'content' => '[ptp_trainers_grid]',
            'description' => 'Trainer search/grid page',
        ),
        'trainers' => array(
            'title' => 'Our Trainers',
            'slug' => 'trainers',
            'content' => '[ptp_trainers_grid]',
            'description' => 'Alternative trainers listing page',
        ),
        'trainer' => array(
            'title' => 'Trainer Profile',
            'slug' => 'trainer',
            'content' => '[ptp_trainer_profile]',
            'description' => 'Individual trainer profile (uses URL param)',
        ),
        
        // ==========================================
        // CART & CHECKOUT - TRAINING
        // ==========================================
        'cart' => array(
            'title' => 'Cart',
            'slug' => 'cart',
            'content' => '[ptp_cart]',
            'description' => 'PTP training cart',
        ),
        'ptp-cart' => array(
            'title' => 'PTP Cart',
            'slug' => 'ptp-cart',
            'content' => '[ptp_cart]',
            'description' => 'PTP cart (alias)',
        ),
        'checkout' => array(
            'title' => 'Checkout',
            'slug' => 'checkout',
            'content' => '[ptp_checkout]',
            'description' => 'PTP checkout (handles both camps and training)',
        ),
        'ptp-checkout' => array(
            'title' => 'Checkout',
            'slug' => 'ptp-checkout',
            'content' => '[ptp_checkout]',
            'description' => 'PTP unified checkout',
        ),
        'training-checkout' => array(
            'title' => 'Training Checkout',
            'slug' => 'training-checkout',
            'content' => '[ptp_training_checkout]',
            'description' => 'Training checkout (alternate)',
        ),
        'bundle-checkout' => array(
            'title' => 'Bundle Checkout',
            'slug' => 'bundle-checkout',
            'content' => '[ptp_bundle_checkout]',
            'description' => 'Bundle/package checkout',
        ),
        
        // ==========================================
        // CART & CHECKOUT - CAMPS (from PTP Camps plugin)
        // ==========================================
        'camp-checkout' => array(
            'title' => 'Camp Checkout',
            'slug' => 'camp-checkout',
            'content' => '[ptp_camp_checkout]',
            'description' => 'Camp registration checkout (Stripe direct)',
        ),
        'camp-thank-you' => array(
            'title' => 'Camp Thank You',
            'slug' => 'camp-thank-you',
            'content' => '[ptp_camp_thank_you]',
            'description' => 'Camp registration confirmation',
        ),
        'camps' => array(
            'title' => 'Summer Camps',
            'slug' => 'camps',
            'content' => '[ptp_camps]',
            'description' => 'Camp products listing',
        ),
        
        // ==========================================
        // USER AUTH
        // ==========================================
        'login' => array(
            'title' => 'Login',
            'slug' => 'login',
            'content' => '[ptp_login]',
            'description' => 'PTP login page (template override)',
        ),
        'register' => array(
            'title' => 'Register',
            'slug' => 'register',
            'content' => '[ptp_register]',
            'description' => 'PTP registration page (template override)',
        ),
        'forgot-password' => array(
            'title' => 'Forgot Password',
            'slug' => 'forgot-password',
            'content' => '[ptp_forgot_password]',
            'description' => 'PTP password reset request page',
        ),
        'reset-password' => array(
            'title' => 'Reset Password',
            'slug' => 'reset-password',
            'content' => '[ptp_reset_password]',
            'description' => 'PTP password reset form page',
        ),
        'forgot-password' => array(
            'title' => 'Forgot Password',
            'slug' => 'forgot-password',
            'content' => '[ptp_forgot_password]',
            'description' => 'PTP forgot password page (v214)',
        ),
        'reset-password' => array(
            'title' => 'Reset Password',
            'slug' => 'reset-password',
            'content' => '[ptp_reset_password]',
            'description' => 'PTP reset password page (v214)',
        ),
        'logout' => array(
            'title' => 'Logout',
            'slug' => 'logout',
            'content' => '',
            'description' => 'Logout handler (template override)',
        ),
        'my-account' => array(
            'title' => 'My Account',
            'slug' => 'my-account',
            'content' => '[ptp_account]',
            'description' => 'PTP Native account (redirects to dashboard)',
        ),
        'account' => array(
            'title' => 'Account Settings',
            'slug' => 'account',
            'content' => '[ptp_account]',
            'description' => 'User account settings',
        ),
        
        // ==========================================
        // TRAINER ONBOARDING FLOW
        // ==========================================
        'apply' => array(
            'title' => 'Become a Trainer',
            'slug' => 'apply',
            'content' => '[ptp_trainer_application]',
            'description' => 'Trainer application form (template override)',
        ),
        'trainer-onboarding' => array(
            'title' => 'Complete Your Profile',
            'slug' => 'trainer-onboarding',
            'content' => '[ptp_trainer_onboarding]',
            'description' => 'New trainer profile setup wizard',
        ),
        'trainer-dashboard' => array(
            'title' => 'Trainer Dashboard',
            'slug' => 'trainer-dashboard',
            'content' => '[ptp_trainer_dashboard]',
            'description' => 'Trainer schedule and earnings (template override)',
        ),
        'trainer-pending' => array(
            'title' => 'Application Under Review',
            'slug' => 'trainer-pending',
            'content' => '[ptp_trainer_pending]',
            'description' => 'Pending trainer status page',
        ),
        'trainer-edit-profile' => array(
            'title' => 'Edit Profile',
            'slug' => 'trainer-edit-profile',
            'content' => '[ptp_trainer_profile_editor]',
            'description' => 'Trainer profile editor',
        ),
        
        // ==========================================
        // PARENT PAGES
        // ==========================================
        'parent-dashboard' => array(
            'title' => 'Parent Dashboard',
            'slug' => 'parent-dashboard',
            'content' => '[ptp_parent_dashboard]',
            'description' => 'Parent booking management',
        ),
        'my-training' => array(
            'title' => 'My Training',
            'slug' => 'my-training',
            'content' => '[ptp_my_training]',
            'description' => 'Parent training dashboard (alias)',
        ),
        'book' => array(
            'title' => 'Book Training',
            'slug' => 'book',
            'content' => '[ptp_booking_wizard]',
            'description' => 'Booking wizard flow',
        ),
        'book-session' => array(
            'title' => 'Book Session',
            'slug' => 'book-session',
            'content' => '[ptp_booking_form]',
            'description' => 'Direct booking form',
        ),
        'booking-confirmation' => array(
            'title' => 'Booking Confirmed',
            'slug' => 'booking-confirmation',
            'content' => '[ptp_booking_confirmation]',
            'description' => 'Booking confirmation page',
        ),
        'review' => array(
            'title' => 'Review Session',
            'slug' => 'review',
            'content' => '[ptp_review_page]',
            'description' => 'Session review form (uses ?booking= URL param)',
        ),
        'player-progress' => array(
            'title' => 'Player Progress',
            'slug' => 'player-progress',
            'content' => '[ptp_player_progress]',
            'description' => 'Player progress tracking',
        ),
        
        // ==========================================
        // COMMUNICATION
        // ==========================================
        'messages' => array(
            'title' => 'Messages',
            'slug' => 'messages',
            'content' => '[ptp_messaging]',
            'description' => 'Parent-trainer messaging',
        ),
        
        // ==========================================
        // THANK YOU PAGES
        // ==========================================
        'thank-you' => array(
            'title' => 'Thank You',
            'slug' => 'thank-you',
            'content' => '[ptp_thank_you]',
            'description' => 'Post-purchase thank you page',
        ),
        
        // ==========================================
        // MEMBERSHIPS & PACKAGES
        // ==========================================
        'all-access' => array(
            'title' => 'All-Access Pass',
            'slug' => 'all-access',
            'content' => '<!-- Template loaded by plugin -->',
            'description' => 'All-Access Pass landing page',
        ),
        'training-plans' => array(
            'title' => 'Training Plans',
            'slug' => 'training-plans',
            'content' => '[ptp_training_plans]',
            'description' => 'Training packages and plans',
        ),
        'gift-cards' => array(
            'title' => 'Gift Cards',
            'slug' => 'gift-cards',
            'content' => '[ptp_gift_cards]',
            'description' => 'Gift card purchase page',
        ),
        
        // ==========================================
        // OTHER
        // ==========================================
        'faq' => array(
            'title' => 'FAQ',
            'slug' => 'faq',
            'content' => '<!-- FAQ Page - Add content here -->',
            'description' => 'Frequently asked questions',
        ),
        'social-login' => array(
            'title' => 'Social Login',
            'slug' => 'social-login',
            'content' => '',
            'description' => 'Social login callback handler',
        ),

        // ==========================================
        // MENTORSHIP FLOW
        // ==========================================
        'mentorship-signup' => array(
            'title' => 'Mentorship Signup',
            'slug' => 'mentorship-signup',
            'content' => '[ptp_mentorship_signup]',
            'description' => 'Mentorship package selection & interest form (?trainer_id=X&package=Y)',
        ),
        'mentorship' => array(
            'title' => 'Online Mentorship',
            'slug' => 'mentorship',
            'content' => '[ptp_mentorship_hub]',
            'description' => 'Mentorship hub / parent dashboard',
        ),
        'mentorship-dashboard' => array(
            'title' => 'Mentorship Dashboard',
            'slug' => 'mentorship-dashboard',
            'content' => '[ptp_mentorship_hub]',
            'description' => 'Mentorship dashboard (alias)',
        ),
        'mentorship-interest' => array(
            'title' => 'Request Mentorship',
            'slug' => 'mentorship-interest',
            'content' => '[ptp_mentorship_interest]',
            'description' => 'Mentorship interest / application form',
        ),
        'mentorship-checkout' => array(
            'title' => 'Mentorship Checkout',
            'slug' => 'mentorship-checkout',
            'content' => '[ptp_mentorship_checkout]',
            'description' => 'Mentorship package payment checkout',
        ),
    );
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
        
        // Auto-create missing pages on admin init (one-time check)
        add_action('admin_init', array($this, 'maybe_auto_create_pages'));
        
        // v185: Fallback — if /training/ 404s, create the page on the fly and redirect
        add_action('template_redirect', array($this, 'rescue_missing_training_page'), 5);
    }
    
    /**
     * v185: If /training/ is a 404, the WP page was deleted/trashed.
     * Auto-create it and redirect so it renders immediately.
     * Also handles other critical PTP pages.
     */
    public function rescue_missing_training_page() {
        if (!is_404()) return;
        
        $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        
        // Critical pages that must always exist (v216: 'training' removed — homepage serves it)
        $critical_pages = array('find-trainers', 'checkout', 'cart', 'login', 'register', 'forgot-password', 'reset-password', 'mentorship-signup', 'mentorship', 'mentorship-dashboard', 'mentorship-interest', 'mentorship-checkout');
        
        if (!in_array($path, $critical_pages)) return;
        if (!isset(self::$pages[$path])) return;
        
        $page_def = self::$pages[$path];
        
        // Check if page exists in any state
        $existing = get_page_by_path($page_def['slug'], OBJECT, 'page');
        
        $did_fix = false;
        
        if ($existing && $existing->post_status !== 'publish') {
            // Restore trashed/drafted page
            wp_update_post(array(
                'ID' => $existing->ID,
                'post_status' => 'publish',
                'post_content' => $page_def['content'],
            ));
            ptp_log('PTP v185: Restored trashed page /' . $path . '/');
            $did_fix = true;
        } elseif (!$existing) {
            // Create it fresh
            wp_insert_post(array(
                'post_title'   => $page_def['title'],
                'post_name'    => $page_def['slug'],
                'post_content' => $page_def['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ));
            ptp_log('PTP v185: Auto-created missing page /' . $path . '/');
            $did_fix = true;
        }
        
        // v211: Only flush + redirect if we actually created or restored something.
        // Previously this ran unconditionally, causing infinite 302 loops when the
        // page existed but was 404ing due to the ptp_page rewrite rule conflict.
        if ($did_fix) {
            flush_rewrite_rules(false);
            wp_safe_redirect(home_url('/' . $path . '/'), 302);
            exit;
        }
    }
    
    /**
     * STATIC: Called from plugin activation hook
     * Creates all missing pages and fixes pages with wrong content
     */
    public static function activate() {
        $created = array();
        $updated = array();
        
        foreach (self::$pages as $key => $page) {
            $existing = get_page_by_path($page['slug'], OBJECT, 'page');
            
            if (!$existing) {
                // Create new page
                $page_id = wp_insert_post(array(
                    'post_title' => $page['title'],
                    'post_name' => $page['slug'],
                    'post_content' => $page['content'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_author' => 1, // Admin user
                ));
                if ($page_id && !is_wp_error($page_id)) {
                    $created[] = $page['slug'];
                }
            } elseif ($existing->post_status === 'trash') {
                // Restore from trash and update content
                wp_update_post(array(
                    'ID' => $existing->ID,
                    'post_status' => 'publish',
                    'post_content' => $page['content'],
                ));
                $updated[] = $page['slug'];
            } elseif ($existing->post_status !== 'publish') {
                // Ensure page is published
                wp_update_post(array(
                    'ID' => $existing->ID,
                    'post_status' => 'publish',
                ));
                $updated[] = $page['slug'];
            }
        }
        
        // Update version marker
        update_option('ptp_pages_created_version', '185.0');
        if (!empty($created)) {
            ptp_log('PTP Page Creator: Created pages - ' . implode(', ', $created));
        }
        if (!empty($updated)) {
            ptp_log('PTP Page Creator: Updated pages - ' . implode(', ', $updated));
        }
        
        return array('created' => $created, 'updated' => $updated);
    }
    
    /**
     * STATIC: Force recreate all pages (deletes and recreates)
     * Use with caution - will overwrite any customizations
     */
    public static function force_recreate_all() {
        $recreated = array();
        
        foreach (self::$pages as $key => $page) {
            // Delete existing
            $existing = get_page_by_path($page['slug'], OBJECT, 'page');
            if ($existing) {
                wp_delete_post($existing->ID, true); // Force delete (skip trash)
            }
            
            // Create fresh
            $page_id = wp_insert_post(array(
                'post_title' => $page['title'],
                'post_name' => $page['slug'],
                'post_content' => $page['content'],
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => get_current_user_id() ?: 1,
            ));
            
            if ($page_id && !is_wp_error($page_id)) {
                $recreated[] = $page['slug'];
            }
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Update version marker
        update_option('ptp_pages_created_version', '185.0');
        
        return $recreated;
    }
    
    /**
     * Get list of all page definitions
     */
    public static function get_pages() {
        return self::$pages;
    }

    /**
     * Auto-create missing pages if this is first run
     */
    public function maybe_auto_create_pages() {
        // Force recreate via URL: ?ptp_recreate_pages=1
        if (isset($_GET['ptp_recreate_pages']) && $_GET['ptp_recreate_pages'] == '1' && current_user_can('manage_options')) {
            self::force_recreate_all();
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>✅ All PTP pages recreated! <a href="' . home_url('/training/') . '" target="_blank">Test Training Page →</a></p></div>';
            });
            return;
        }
        
        // Only run once per version (bumped to 185.0 to ensure /training page exists)
        $version_check = get_option('ptp_pages_created_version', '0');
        if (version_compare($version_check, '185.0', '>=')) {
            return;
        }
        
        // Create all missing pages (non-destructive)
        $result = self::activate();
        
        // Flush rewrite rules if pages were created
        if (!empty($result['created']) || !empty($result['updated'])) {
            flush_rewrite_rules();
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'ptp-dashboard',
            'Page Setup',
            '📄 Page Setup',
            'manage_options',
            'ptp-page-setup',
            array($this, 'render_page')
        );
    }
    
    /**
     * Handle create/delete actions
     */
    public function handle_actions() {
        if (!current_user_can('manage_options')) return;
        
        // Create single page
        if (isset($_POST['ptp_create_page']) && wp_verify_nonce($_POST['ptp_page_nonce'], 'ptp_page_action')) {
            $page_key = sanitize_key($_POST['ptp_create_page']);
            if (isset(self::$pages[$page_key])) {
                $this->create_page($page_key);
            }
        }
        
        // Create all pages
        if (isset($_POST['ptp_create_all']) && wp_verify_nonce($_POST['ptp_page_nonce'], 'ptp_page_action')) {
            foreach (self::$pages as $key => $page) {
                if (!$this->page_exists($page['slug'])) {
                    $this->create_page($key);
                }
            }
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>✅ All missing pages created!</p></div>';
            });
        }
        
        // Delete and recreate page
        if (isset($_POST['ptp_recreate_page']) && wp_verify_nonce($_POST['ptp_page_nonce'], 'ptp_page_action')) {
            $page_key = sanitize_key($_POST['ptp_recreate_page']);
            if (isset(self::$pages[$page_key])) {
                $this->delete_page($page_key);
                $this->create_page($page_key);
            }
        }
    }
    
    /**
     * Check if page exists
     */
    private function page_exists($slug) {
        $page = get_page_by_path($slug);
        return $page && $page->post_status === 'publish';
    }
    
    /**
     * Get existing page
     */
    private function get_page($slug) {
        return get_page_by_path($slug);
    }
    
    /**
     * Create a page
     */
    private function create_page($key) {
        if (!isset(self::$pages[$key])) return false;
        
        $page = self::$pages[$key];
        
        // Check if exists (even as draft/trash)
        $existing = get_page_by_path($page['slug'], OBJECT, 'page');
        if ($existing) {
            // Update existing
            wp_update_post(array(
                'ID' => $existing->ID,
                'post_status' => 'publish',
                'post_content' => $page['content'],
            ));
            return $existing->ID;
        }
        
        // Create new
        $page_id = wp_insert_post(array(
            'post_title' => $page['title'],
            'post_name' => $page['slug'],
            'post_content' => $page['content'],
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => get_current_user_id(),
        ));
        
        return $page_id;
    }
    
    /**
     * Delete a page
     */
    private function delete_page($key) {
        if (!isset(self::$pages[$key])) return false;
        
        $page = get_page_by_path(self::$pages[$key]['slug']);
        if ($page) {
            wp_delete_post($page->ID, true);
            return true;
        }
        return false;
    }
    
    /**
     * Render admin page
     */
    public function render_page() {
        $force_url = admin_url('admin.php?page=ptp-page-setup&ptp_recreate_pages=1');
        ?>
        <div class="wrap">
            <h1>📄 PTP Page Setup</h1>
            <p>Create or recreate all required PTP pages with the correct shortcodes.</p>
            
            <!-- QUICK FIX BOX -->
            <div style="background: #d1fae5; border-left: 4px solid #22c55e; padding: 15px 20px; margin-bottom: 20px; max-width: 600px;">
                <h3 style="margin: 0 0 10px; font-size: 16px;">⚡ Quick Fix - Pages Not Working?</h3>
                <p style="margin: 0 0 12px; font-size: 14px;">Click below to force-recreate ALL pages and flush permalinks:</p>
                <a href="<?php echo esc_url($force_url); ?>" class="button button-primary" onclick="return confirm('This will delete and recreate ALL PTP pages. Continue?');">
                    🔄 Force Recreate All Pages
                </a>
                <p style="margin: 10px 0 0; font-size: 12px; color: #666;">
                    After recreating, go to <strong>Settings → Permalinks</strong> and click <strong>Save Changes</strong>.
                </p>
            </div>
            
            <form method="post" style="margin-bottom: 20px;">
                <?php wp_nonce_field('ptp_page_action', 'ptp_page_nonce'); ?>
                <button type="submit" name="ptp_create_all" class="button button-primary button-hero">
                    🚀 Create All Missing Pages
                </button>
            </form>
            
            <table class="wp-list-table widefat fixed striped" style="max-width: 1200px;">
                <thead>
                    <tr>
                        <th style="width: 30px;">Status</th>
                        <th style="width: 150px;">Page</th>
                        <th style="width: 120px;">Slug</th>
                        <th>Shortcode</th>
                        <th style="width: 200px;">Description</th>
                        <th style="width: 250px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (self::$pages as $key => $page): 
                        $exists = $this->page_exists($page['slug']);
                        $existing = $this->get_page($page['slug']);
                        $url = home_url('/' . $page['slug'] . '/');
                    ?>
                    <tr>
                        <td>
                            <?php if ($exists): ?>
                                <span style="color: #22c55e; font-size: 20px;">✓</span>
                            <?php else: ?>
                                <span style="color: #ef4444; font-size: 20px;">✗</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($page['title']); ?></strong>
                        </td>
                        <td>
                            <code>/<?php echo esc_html($page['slug']); ?>/</code>
                        </td>
                        <td>
                            <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px;">
                                <?php echo esc_html($page['content']); ?>
                            </code>
                        </td>
                        <td style="font-size: 12px; color: #666;">
                            <?php echo esc_html($page['description']); ?>
                        </td>
                        <td>
                            <form method="post" style="display: inline-flex; gap: 5px;">
                                <?php wp_nonce_field('ptp_page_action', 'ptp_page_nonce'); ?>
                                
                                <?php if ($exists): ?>
                                    <a href="<?php echo esc_url($url); ?>" class="button" target="_blank">View</a>
                                    <a href="<?php echo esc_url(get_edit_post_link($existing->ID)); ?>" class="button">Edit</a>
                                    <button type="submit" name="ptp_recreate_page" value="<?php echo esc_attr($key); ?>" 
                                            class="button" onclick="return confirm('This will delete and recreate the page. Continue?');">
                                        🔄 Recreate
                                    </button>
                                <?php else: ?>
                                    <button type="submit" name="ptp_create_page" value="<?php echo esc_attr($key); ?>" 
                                            class="button button-primary">
                                        ➕ Create
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top: 30px; padding: 20px; background: #fffbeb; border-left: 4px solid #fcb900;">
                <h3 style="margin-top: 0;">⚠️ Important Notes</h3>
                <ul style="margin-bottom: 0;">
                    <li><strong>Training page</strong> - Main entry point for private training. Shows trainer grid.</li>
                    <li><strong>Trainer page</strong> - Individual profiles. URL format: <code>/trainer/john-smith/</code></li>
                    <li><strong>Checkout</strong> - PTP Native checkout handles both camps AND training bundles.</li>
                    <li><strong>PTP Checkout</strong> - Training-only checkout (alternative flow).</li>
                    <li>If a page shows blank, check that the shortcode class is loaded in the plugin.</li>
                </ul>
            </div>
            
            <div style="margin-top: 20px; padding: 20px; background: #fef3c7; border-left: 4px solid #f59e0b;">
                <h3 style="margin-top: 0;">⭐ Trainer Onboarding Flow</h3>
                <p>When trainers apply, they go through this flow:</p>
                <ol style="margin-bottom: 0;">
                    <li><strong>/apply/</strong> - Trainer submits application form</li>
                    <li><strong>Pending State</strong> - Shows "Application Pending" while awaiting admin approval</li>
                    <li><strong>/trainer-onboarding/</strong> - After approval, trainer completes profile (photo, bio, experience, pricing, availability, Stripe)</li>
                    <li><strong>/trainer-dashboard/</strong> - Main dashboard for managing bookings and earnings</li>
                </ol>
                <p style="margin-top: 12px; margin-bottom: 0;"><strong>Admin:</strong> Approve trainers in WP Admin → PTP → Trainers</p>
            </div>
            
            <div style="margin-top: 20px; padding: 20px; background: #f0f9ff; border-left: 4px solid #3b82f6;">
                <h3 style="margin-top: 0;">🔧 Troubleshooting</h3>
                <p>If clicking "Private Training" doesn't work:</p>
                <ol style="margin-bottom: 0;">
                    <li>Check if the <code>/training/</code> page exists (green checkmark above)</li>
                    <li>If it exists but is blank, click "Recreate" to fix the shortcode</li>
                    <li>Clear any caching plugins after creating pages</li>
                    <li>Check if permalinks need refresh: Settings > Permalinks > Save</li>
                </ol>
            </div>
            
            <div style="margin-top: 20px; padding: 20px; background: #fef2f2; border-left: 4px solid #ef4444;">
                <h3 style="margin-top: 0;">🗑️ Reset All Pages</h3>
                <p>Need a completely fresh start? This will delete ALL PTP pages and recreate them.</p>
                <form method="post">
                    <?php wp_nonce_field('ptp_page_action', 'ptp_page_nonce'); ?>
                    <button type="submit" name="ptp_reset_all" class="button" 
                            onclick="return confirm('⚠️ WARNING: This will DELETE all PTP pages and recreate them. Any customizations will be lost. Continue?');"
                            style="background: #ef4444; color: white; border-color: #dc2626;">
                        🗑️ Delete & Recreate All Pages
                    </button>
                </form>
                <?php
                if (isset($_POST['ptp_reset_all']) && wp_verify_nonce($_POST['ptp_page_nonce'], 'ptp_page_action')) {
                    $recreated = self::force_recreate_all();
                    echo '<p style="color: #22c55e; font-weight: bold; margin-top: 10px;">✅ All ' . count($recreated) . ' pages reset!</p>';
                }
                ?>
            </div>
        </div>
        <?php
    }
}

// Initialize
PTP_Page_Creator::instance();
