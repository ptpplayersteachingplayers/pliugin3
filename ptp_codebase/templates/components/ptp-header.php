<?php
/**
 * PTP Header Component v88
 * 
 * Responsive header with mobile drawer and desktop navigation
 * 
 * Usage: 
 *   <?php PTP_Header::render(); ?>
 *   or use action hook: do_action('ptp_header');
 */

defined('ABSPATH') || exit;

class PTP_Header {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Register header action
        add_action('ptp_header', array($this, 'output'));
        
        // Enqueue styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Add body classes
        add_filter('body_class', array($this, 'body_classes'));
        
        // Footer scripts
        add_action('wp_footer', array($this, 'footer_scripts'), 5);
    }
    
    /**
     * Enqueue header assets
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'ptp-header',
            PTP_PLUGIN_URL . 'assets/css/ptp-header-v88.css',
            array(),
            PTP_VERSION
        );
    }
    
    /**
     * Add body classes
     */
    public function body_classes($classes) {
        // v171: Always add has-ptp-header when PTP header is active
        $classes[] = 'has-ptp-header';
        
        // Check if mobile
        if (wp_is_mobile()) {
            $classes[] = 'has-bottom-nav';
        }
        
        // Check for announcement
        $announcement = get_option('ptp_announcement_text', '');
        if (!empty($announcement)) {
            $classes[] = 'has-announcement';
        }
        
        return $classes;
    }
    
    /**
     * Get navigation items
     */
    public static function get_nav_items() {
        $items = array(
            array(
                'label' => 'Find Trainers',
                'url' => home_url('/find-trainers/'),
                'icon' => 'search',
            ),
            array(
                'label' => 'Summer Camps',
                'url' => home_url('/ptp-find-a-camp/'),
                'icon' => 'sun',
                'highlight' => true,
            ),
            array(
                'label' => 'How It Works',
                'url' => home_url('/how-it-works/'),
                'icon' => 'help-circle',
            ),
            array(
                'label' => 'About',
                'url' => home_url('/about/'),
                'icon' => 'info',
            ),
        );
        
        return apply_filters('ptp_header_nav_items', $items);
    }
    
    /**
     * Get user menu items
     */
    public static function get_user_menu_items() {
        $user_id = get_current_user_id();
        
        // Check if trainer
        global $wpdb;
        $is_trainer = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
        
        $items = array();
        
        if ($is_trainer) {
            $items[] = array(
                'label' => 'Dashboard',
                'url' => home_url('/trainer-dashboard/'),
                'icon' => 'layout',
            );
            $items[] = array(
                'label' => 'My Schedule',
                'url' => home_url('/trainer-dashboard/?tab=schedule'),
                'icon' => 'calendar',
            );
            $items[] = array(
                'label' => 'Earnings',
                'url' => home_url('/trainer-dashboard/?tab=earnings'),
                'icon' => 'dollar-sign',
            );
            $items[] = array(
                'label' => 'Messages',
                'url' => home_url('/trainer-dashboard/?tab=messages'),
                'icon' => 'message-circle',
            );
            $items[] = array(
                'label' => 'Edit Profile',
                'url' => home_url('/trainer-dashboard/?tab=profile'),
                'icon' => 'settings',
            );
        } else {
            $items[] = array(
                'label' => 'My Training',
                'url' => home_url('/my-training/'),
                'icon' => 'calendar',
            );
            $items[] = array(
                'label' => 'My Players',
                'url' => home_url('/my-training/?tab=players'),
                'icon' => 'users',
            );
            $items[] = array(
                'label' => 'Messages',
                'url' => home_url('/my-training/?tab=messages'),
                'icon' => 'message-circle',
            );
        }
        
        $items[] = array(
            'label' => 'Account Settings',
            'url' => home_url('/account/'),
            'icon' => 'settings',
        );
        
        $items[] = array(
            'label' => 'Log Out',
            'url' => wp_logout_url(home_url()),
            'icon' => 'log-out',
            'class' => 'logout',
        );
        
        return apply_filters('ptp_header_user_menu_items', $items);
    }
    
    /**
     * Render header
     */
    public static function render() {
        self::instance()->output();
    }
    
    /**
     * Output header HTML
     */
    public function output() {
        $logo_url = get_option('ptp_logo_url', 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png');
        $logged_in = is_user_logged_in();
        $user = wp_get_current_user();
        $nav_items = self::get_nav_items();
        $current_url = trailingslashit($_SERVER['REQUEST_URI']);
        
        // Get cart count
        $cart_count = 0;
        if (function_exists('ptp_cart') && ptp_cart()) {
            $cart_count = ptp_cart()->get_item_count();
        }
        
        // Get unread message count
        $msg_unread = 0;
        if ($logged_in && class_exists('PTP_Messaging_V71')) {
            $msg_unread = intval(PTP_Messaging_V71::get_unread_count($user->ID));
        }
        
        // Get referral balance if logged in
        $credit_balance = 0;
        if ($logged_in && class_exists('PTP_Referral_System')) {
            $credit_balance = PTP_Referral_System::get_credit_balance($user->ID, 'parent');
        }
        
        // User avatar
        $avatar_url = '';
        if ($logged_in) {
            $avatar_url = get_avatar_url($user->ID, array('size' => 72));
        }
        ?>
        <!-- PTP Header v88 -->
        <header class="ptp-header" id="ptpHeader">
            <div class="ptp-header-inner">
                <!-- Logo -->
                <div class="ptp-header-logo">
                    <a href="<?php echo esc_url(home_url('/')); ?>">
                        <img src="<?php echo esc_url($logo_url); ?>" alt="PTP Soccer">
                    </a>
                </div>
                
                <!-- Desktop Navigation -->
                <nav class="ptp-header-nav">
                    <?php foreach ($nav_items as $item): 
                        $is_active = strpos($current_url, parse_url($item['url'], PHP_URL_PATH)) !== false;
                    ?>
                    <a href="<?php echo esc_url($item['url']); ?>" 
                       class="ptp-header-nav-item <?php echo $is_active ? 'active' : ''; ?>">
                        <?php echo esc_html($item['label']); ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
                
                <!-- Actions -->
                <div class="ptp-header-actions">
                    <!-- Search -->
                    <button type="button" class="ptp-header-icon ptp-hide-mobile" id="searchToggle" aria-label="Search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="M21 21l-4.35-4.35"/>
                        </svg>
                    </button>
                    
                    <!-- Cart -->
                    <a href="<?php echo esc_url(home_url('/ptp-cart/')); ?>" class="ptp-header-icon" aria-label="Cart">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="9" cy="21" r="1"/>
                            <circle cx="20" cy="21" r="1"/>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                        <span class="ptp-header-badge"><?php echo $cart_count > 0 ? $cart_count : ''; ?></span>
                    </a>
                    
                    <?php if ($logged_in): ?>
                    <!-- Messages -->
                    <a href="<?php echo esc_url(home_url('/messages/')); ?>" class="ptp-header-icon" aria-label="Messages">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        <span class="ptp-header-badge ptp-msg-badge"><?php echo $msg_unread > 0 ? $msg_unread : ''; ?></span>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($logged_in): ?>
                    <!-- User Menu -->
                    <div class="ptp-header-user" style="position:relative;">
                        <div class="ptp-header-avatar" id="userMenuToggle">
                            <img src="<?php echo esc_url($avatar_url); ?>" alt="">
                        </div>
                        
                        <div class="ptp-user-dropdown" id="userDropdown">
                            <div class="ptp-user-dropdown-header">
                                <div class="ptp-user-dropdown-name"><?php echo esc_html($user->display_name); ?></div>
                                <div class="ptp-user-dropdown-email"><?php echo esc_html($user->user_email); ?></div>
                                <?php if ($credit_balance > 0): ?>
                                <div class="ptp-user-dropdown-balance">
                                    <span class="ptp-user-dropdown-balance-label">Credit</span>
                                    <span class="ptp-user-dropdown-balance-amount">$<?php echo number_format($credit_balance); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="ptp-user-dropdown-links">
                                <?php foreach (self::get_user_menu_items() as $item): ?>
                                <a href="<?php echo esc_url($item['url']); ?>" 
                                   class="ptp-user-dropdown-item <?php echo isset($item['class']) ? esc_attr($item['class']) : ''; ?>">
                                    <?php echo self::get_icon($item['icon']); ?>
                                    <?php echo esc_html($item['label']); ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Login/CTA -->
                    <a href="<?php echo esc_url(home_url('/login/')); ?>" class="ptp-header-icon ptp-hide-mobile" aria-label="Login">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </a>
                    <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="ptp-header-cta">
                        Book Training
                    </a>
                    <?php endif; ?>
                    
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="ptp-header-toggle" id="mobileMenuToggle" aria-label="Menu">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                </div>
            </div>
        </header>
        
        <!-- Mobile Navigation Drawer -->
        <div class="ptp-mobile-nav-overlay" id="mobileNavOverlay"></div>
        <nav class="ptp-mobile-nav" id="mobileNav">
            <div class="ptp-mobile-nav-header">
                <img src="<?php echo esc_url($logo_url); ?>" alt="PTP" style="height:28px;">
                <button type="button" class="ptp-mobile-nav-close" id="mobileNavClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            
            <?php if ($logged_in): ?>
            <div class="ptp-mobile-nav-user">
                <div class="ptp-mobile-nav-user-avatar">
                    <img src="<?php echo esc_url($avatar_url); ?>" alt="">
                </div>
                <div class="ptp-mobile-nav-user-info">
                    <div class="ptp-mobile-nav-user-name"><?php echo esc_html($user->display_name); ?></div>
                    <div class="ptp-mobile-nav-user-email"><?php echo esc_html($user->user_email); ?></div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="ptp-mobile-nav-links">
                <?php foreach ($nav_items as $item): 
                    $is_active = strpos($current_url, parse_url($item['url'], PHP_URL_PATH)) !== false;
                ?>
                <a href="<?php echo esc_url($item['url']); ?>" 
                   class="ptp-mobile-nav-item <?php echo $is_active ? 'active' : ''; ?>">
                    <?php echo self::get_icon($item['icon']); ?>
                    <?php echo esc_html($item['label']); ?>
                </a>
                <?php endforeach; ?>
                
                <div class="ptp-mobile-nav-divider"></div>
                
                <?php if ($logged_in): ?>
                    <?php foreach (self::get_user_menu_items() as $item): ?>
                    <a href="<?php echo esc_url($item['url']); ?>" 
                       class="ptp-mobile-nav-item <?php echo isset($item['class']) ? esc_attr($item['class']) : ''; ?>">
                        <?php echo self::get_icon($item['icon']); ?>
                        <?php echo esc_html($item['label']); ?>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <a href="<?php echo esc_url(home_url('/login/')); ?>" class="ptp-mobile-nav-item">
                        <?php echo self::get_icon('log-in'); ?>
                        Log In
                    </a>
                    <a href="<?php echo esc_url(home_url('/register/')); ?>" class="ptp-mobile-nav-item">
                        <?php echo self::get_icon('user-plus'); ?>
                        Sign Up
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="ptp-mobile-nav-cta">
                <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" width="18" height="18" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="M21 21l-4.35-4.35"/>
                    </svg>
                    Find a Trainer
                </a>
            </div>
        </nav>
        
        <!-- Bottom Navigation (Mobile) -->
        <nav class="ptp-bottom-nav">
            <a href="<?php echo esc_url(home_url('/')); ?>" 
               class="ptp-bottom-nav-item <?php echo is_front_page() ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                Home
            </a>
            <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" 
               class="ptp-bottom-nav-item <?php echo strpos($current_url, 'find-trainers') !== false ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="M21 21l-4.35-4.35"/>
                </svg>
                Find
            </a>
            <?php if ($logged_in): ?>
            <a href="<?php echo esc_url(home_url('/messages/')); ?>" 
               class="ptp-bottom-nav-item <?php echo strpos($current_url, 'messages') !== false ? 'active' : ''; ?>" style="position:relative">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <?php if ($msg_unread > 0): ?><span class="ptp-msg-badge-mobile"><?php echo $msg_unread > 9 ? '9+' : $msg_unread; ?></span><?php endif; ?>
                Chat
            </a>
            <a href="<?php echo esc_url(home_url('/my-training/')); ?>" 
               class="ptp-bottom-nav-item <?php echo strpos($current_url, 'my-training') !== false ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                Account
            </a>
            <?php else: ?>
            <a href="<?php echo esc_url(home_url('/ptp-find-a-camp/')); ?>" 
               class="ptp-bottom-nav-item <?php echo strpos($current_url, 'camp') !== false ? 'active' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/>
                    <line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/>
                    <line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
                Camps
            </a>
            <a href="<?php echo esc_url(home_url('/login/')); ?>" 
               class="ptp-bottom-nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Login
            </a>
            <?php endif; ?>
        </nav>
        
        <!-- Search Modal -->
        <div class="ptp-search-modal" id="searchModal">
            <div class="ptp-search-container">
                <div class="ptp-search-input-wrapper">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="M21 21l-4.35-4.35"/>
                    </svg>
                    <input type="text" class="ptp-search-input" placeholder="Search trainers, locations..." id="searchInput" autocomplete="off">
                </div>
                <div class="ptp-search-results" id="searchResults">
                    <!-- Results populated by JS -->
                </div>
            </div>
        </div>
        
        <!-- Scroll Progress -->
        <div class="ptp-scroll-progress" id="scrollProgress"></div>
        
        <?php if ($logged_in): ?>
        <!-- v235.1: Poll unread message count every 30s and update badges -->
        <script>
        (function(){
            var last = <?php echo intval($msg_unread); ?>;
            function updateBadges(n){
                // Desktop badge
                document.querySelectorAll('.ptp-msg-badge').forEach(function(el){ el.textContent = n > 0 ? n : ''; });
                // Mobile badge
                var mob = document.querySelector('.ptp-msg-badge-mobile');
                if (n > 0) {
                    if (!mob) {
                        var chatLink = document.querySelector('.ptp-bottom-nav-item[href*="messages"]');
                        if (chatLink) {
                            mob = document.createElement('span');
                            mob.className = 'ptp-msg-badge-mobile';
                            chatLink.style.position = 'relative';
                            chatLink.insertBefore(mob, chatLink.firstChild);
                        }
                    }
                    if (mob) mob.textContent = n > 9 ? '9+' : n;
                } else if (mob) {
                    mob.remove();
                }
                last = n;
            }
            function poll(){
                var fd = new FormData();
                fd.append('action','ptp_get_unread_count');
                fd.append('nonce','<?php echo wp_create_nonce("ptp_nonce"); ?>');
                fetch('<?php echo admin_url("admin-ajax.php"); ?>',{method:'POST',body:fd})
                    .then(function(r){return r.json()})
                    .then(function(d){
                        if(d.success && typeof d.data.count !== 'undefined'){
                            var n = parseInt(d.data.count) || 0;
                            if(n !== last) updateBadges(n);
                        }
                    }).catch(function(){});
            }
            if(document.visibilityState !== 'hidden') setInterval(poll, 30000);
            document.addEventListener('visibilitychange', function(){
                if(document.visibilityState === 'visible') poll();
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Get icon SVG
     */
    public static function get_icon($name) {
        $icons = array(
            'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>',
            'sun' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
            'help-circle' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'info' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
            'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'message-circle' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
            'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'log-out' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
            'log-in' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
            'user-plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>',
            'layout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
            'dollar-sign' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        );
        
        return isset($icons[$name]) ? $icons[$name] : '';
    }
    
    /**
     * Footer scripts for header functionality
     */
    public function footer_scripts() {
        ?>
        <script>
        (function() {
            // v132.6: Cleanup any stuck scroll-blocking classes on page load
            document.body.classList.remove('menu-open', 'modal-open', 'ptp-drawer-open', 'no-scroll', 'overflow-hidden');
            document.documentElement.classList.remove('menu-open', 'modal-open', 'ptp-drawer-open', 'no-scroll');
            document.body.style.overflow = '';
            document.body.style.position = '';
            document.body.style.height = '';
            
            var header = document.getElementById('ptpHeader');
            var toggle = document.getElementById('mobileMenuToggle');
            var nav = document.getElementById('mobileNav');
            var overlay = document.getElementById('mobileNavOverlay');
            var closeBtn = document.getElementById('mobileNavClose');
            var searchToggle = document.getElementById('searchToggle');
            var searchModal = document.getElementById('searchModal');
            var scrollProgress = document.getElementById('scrollProgress');
            var userToggle = document.getElementById('userMenuToggle');
            var userDropdown = document.getElementById('userDropdown');
            
            // Scroll effect
            var lastScroll = 0;
            window.addEventListener('scroll', function() {
                var scroll = window.pageYOffset;
                
                // Add scrolled class (check header exists)
                if (header) {
                    if (scroll > 20) {
                        header.classList.add('scrolled');
                    } else {
                        header.classList.remove('scrolled');
                    }
                }
                
                // Update progress bar
                if (scrollProgress) {
                    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
                    var progress = (scroll / docHeight) * 100;
                    scrollProgress.style.width = progress + '%';
                }
                
                lastScroll = scroll;
            });
            
            // Mobile menu
            function openMobileNav() {
                if (nav) nav.classList.add('open');
                if (overlay) overlay.classList.add('open');
                if (toggle) toggle.classList.add('open');
                document.body.classList.add('menu-open');
            }
            
            function closeMobileNav() {
                if (nav) nav.classList.remove('open');
                if (overlay) overlay.classList.remove('open');
                if (toggle) toggle.classList.remove('open');
                document.body.classList.remove('menu-open');
            }
            
            if (toggle) toggle.addEventListener('click', function() {
                if (nav && nav.classList.contains('open')) {
                    closeMobileNav();
                } else {
                    openMobileNav();
                }
            });
            
            if (overlay) overlay.addEventListener('click', closeMobileNav);
            if (closeBtn) closeBtn.addEventListener('click', closeMobileNav);
            
            // Close on nav item click
            var navItems = nav ? nav.querySelectorAll('.ptp-mobile-nav-item') : [];
            navItems.forEach(function(item) {
                item.addEventListener('click', closeMobileNav);
            });
            
            // Search modal
            if (searchToggle && searchModal) {
                searchToggle.addEventListener('click', function() {
                    searchModal.classList.add('open');
                    setTimeout(function() {
                        document.getElementById('searchInput').focus();
                    }, 100);
                });
                
                searchModal.addEventListener('click', function(e) {
                    if (e.target === searchModal) {
                        searchModal.classList.remove('open');
                    }
                });
                
                // ESC to close
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && searchModal.classList.contains('open')) {
                        searchModal.classList.remove('open');
                    }
                });
            }
            
            // User dropdown toggle (for mobile)
            if (userToggle && userDropdown) {
                userToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userDropdown.classList.toggle('open');
                });
                
                document.addEventListener('click', function(e) {
                    if (!userDropdown.contains(e.target) && !userToggle.contains(e.target)) {
                        userDropdown.classList.remove('open');
                    }
                });
            }
        })();
        </script>
        <?php
    }
}

/**
 * v165: Conditional header initialization
 * 
 * Only initialize PTP header on pages that need it.
 * This prevents CSS/JS conflicts on standalone dashboard pages,
 * checkout flows, and pages that use theme header.
 */
add_action('wp', function() {
    // List of pages that should NOT have PTP header injected
    $exclude_pages = array(
        // Standalone dashboards
        'login', 'register', 'logout',
        'trainer-dashboard', 'parent-dashboard', 'my-training',
        'trainer-onboarding', 'trainer-pending',
        
        // Checkout flows
        'ptp-checkout', 'training-checkout', 'checkout', 'camp-checkout',
        'ptp-cart', 'cart', 'thank-you',
        
        // Pages using theme header
        'find-trainers', 'trainer', 'trainer-profile',
        'training', 'training-landing',
        
        // Other standalone
        'account', 'messages', 'apply', 'member-dashboard', 'ptp-cart-debug'
    );
    
    // Check current page against exclude list
    foreach ($exclude_pages as $page) {
        if (is_page($page)) {
            return;
        }
    }
    
    // Check for trainer profile URL pattern (/trainer/slug/)
    $path = isset($_SERVER['REQUEST_URI']) ? trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') : '';
    if (preg_match('#^trainer/[^/]+/?$#', $path)) {
        return;
    }
    
    // Check if page explicitly opts out via filter
    if (apply_filters('ptp_skip_header', false)) {
        return;
    }
    
    // Initialize PTP header for all other pages
    PTP_Header::instance();
}, 1);
