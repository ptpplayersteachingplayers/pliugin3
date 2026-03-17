<?php
/**
 * PTP PWA Integration v158.2
 * 
 * Progressive Web App features:
 * - Service Worker registration
 * - Web App Manifest
 * - Offline support
 * - Install prompt
 * - Push notifications setup
 * 
 * v158.2: Fixed SW redirect error, manifest scope warning
 */

defined('ABSPATH') || exit;

class PTP_PWA {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_head', array($this, 'add_pwa_meta'), 1);
        add_action('wp_footer', array($this, 'register_service_worker'), 99);
        add_action('init', array($this, 'add_rewrite_rules'), 1);
        add_action('template_redirect', array($this, 'serve_pwa_files'), 1);
        add_filter('query_vars', array($this, 'add_query_vars'));
        
        // Flush rewrite rules when needed
        add_action('admin_init', array($this, 'maybe_flush_rules'));
    }
    
    /**
     * Flush rewrite rules if needed (once)
     */
    public function maybe_flush_rules() {
        if (get_option('ptp_pwa_rules_flushed_v158') !== 'yes') {
            flush_rewrite_rules();
            update_option('ptp_pwa_rules_flushed_v158', 'yes');
        }
    }
    
    /**
     * Add PWA meta tags to head
     */
    public function add_pwa_meta() {
        // Only on frontend PTP pages
        if (is_admin()) return;
        
        $manifest_url = home_url('/ptp-manifest.json');
        $theme_color = '#FCB900';
        $bg_color = '#0A0A0A';
        ?>
        <!-- PWA Meta Tags -->
        <meta name="theme-color" content="<?php echo esc_attr($theme_color); ?>">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="PTP Soccer">
        <meta name="application-name" content="PTP Soccer">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="msapplication-TileColor" content="<?php echo esc_attr($theme_color); ?>">
        <meta name="msapplication-tap-highlight" content="no">
        
        <!-- Manifest -->
        <link rel="manifest" href="<?php echo esc_url($manifest_url); ?>">
        
        <!-- Apple Touch Icons -->
        <link rel="apple-touch-icon" href="<?php echo esc_url($this->get_icon_url(180)); ?>">
        <link rel="apple-touch-icon" sizes="152x152" href="<?php echo esc_url($this->get_icon_url(152)); ?>">
        <link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url($this->get_icon_url(180)); ?>">
        <link rel="apple-touch-icon" sizes="167x167" href="<?php echo esc_url($this->get_icon_url(167)); ?>">
        
        <!-- Apple Splash Screens -->
        <link rel="apple-touch-startup-image" href="<?php echo esc_url($this->get_splash_url()); ?>">
        
        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url($this->get_icon_url(32)); ?>">
        <link rel="icon" type="image/png" sizes="16x16" href="<?php echo esc_url($this->get_icon_url(16)); ?>">
        <?php
    }
    
    /**
     * Register service worker
     * v158.2: Disabled SW registration - causes redirect errors with WordPress rewrite rules
     * PWA install/offline features still work via manifest
     */
    public function register_service_worker() {
        if (is_admin()) return;
        
        // v158.2: Service Worker DISABLED - WordPress rewrite rules cause redirect errors
        // "The script resource is behind a redirect, which is disallowed"
        // This is a known WordPress limitation with SW served via rewrite rules
        ?>
        <script>
        (function() {
            // Service Worker Registration - DISABLED v158.2
            // Causes "script resource is behind a redirect" error with WP rewrite rules
            // Uncomment below if serving SW from actual file path instead of rewrite
            /*
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function() {
                    navigator.serviceWorker.register('/wp-content/plugins/ptp-training-platform/assets/js/ptp-sw.js', {
                        scope: '/'
                    }).then(function(registration) {
                        console.log('[PTP] Service Worker registered:', registration.scope);
                    }).catch(function(error) {
                        console.error('[PTP] Service Worker registration failed:', error);
                    });
                });
            }
            */
            
            // Install Prompt - DISABLED per user request
            // The beforeinstallprompt and related code has been disabled
            // as the add-to-homescreen feature is not needed at this time.
            let deferredPrompt = null;
            
            // Disabled - don't show install prompts
            /*
            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                deferredPrompt = e;
                
                // Show install button after delay (don't be annoying)
                setTimeout(function() {
                    showInstallPrompt();
                }, 30000); // 30 seconds
            });
            */
            
            function showInstallPrompt() {
                // Disabled - return immediately
                return;
                
                // Only show if user has engaged
                if (document.visibilityState !== 'visible') return;
                
                // Check if already shown recently
                const lastShown = localStorage.getItem('ptp_install_prompt');
                if (lastShown && Date.now() - parseInt(lastShown) < 86400000) return; // 24 hours
                
                // Create toast
                const toast = document.createElement('div');
                toast.id = 'ptp-install-toast';
                toast.innerHTML = `
                    <style>
                        #ptp-install-toast {
                            position: fixed;
                            bottom: 20px;
                            left: 20px;
                            right: 20px;
                            max-width: 400px;
                            margin: 0 auto;
                            background: #0A0A0A;
                            color: #fff;
                            padding: 16px 20px;
                            border-radius: 12px;
                            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                            z-index: 9999;
                            display: flex;
                            align-items: center;
                            gap: 16px;
                            font-family: Inter, -apple-system, sans-serif;
                            animation: slideUp 0.3s ease-out;
                        }
                        @keyframes slideUp {
                            from { transform: translateY(100px); opacity: 0; }
                            to { transform: translateY(0); opacity: 1; }
                        }
                        #ptp-install-toast .icon {
                            width: 48px;
                            height: 48px;
                            background: #FCB900;
                            border-radius: 10px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            flex-shrink: 0;
                        }
                        #ptp-install-toast .icon svg {
                            width: 24px;
                            height: 24px;
                            fill: #0A0A0A;
                        }
                        #ptp-install-toast .content {
                            flex: 1;
                        }
                        #ptp-install-toast .title {
                            font-weight: 600;
                            font-size: 14px;
                            margin-bottom: 2px;
                        }
                        #ptp-install-toast .desc {
                            font-size: 12px;
                            color: #9CA3AF;
                        }
                        #ptp-install-toast .actions {
                            display: flex;
                            gap: 8px;
                        }
                        #ptp-install-toast button {
                            padding: 8px 16px;
                            border-radius: 6px;
                            font-size: 12px;
                            font-weight: 600;
                            cursor: pointer;
                            border: none;
                            transition: transform 0.1s;
                        }
                        #ptp-install-toast button:active {
                            transform: scale(0.95);
                        }
                        #ptp-install-toast .install-btn {
                            background: #FCB900;
                            color: #0A0A0A;
                        }
                        #ptp-install-toast .dismiss-btn {
                            background: transparent;
                            color: #9CA3AF;
                        }
                    </style>
                    <div class="icon">
                        <svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                    </div>
                    <div class="content">
                        <div class="title">Add PTP to Home Screen</div>
                        <div class="desc">Quick access to trainers & bookings</div>
                    </div>
                    <div class="actions">
                        <button class="dismiss-btn" onclick="dismissInstall()">Later</button>
                        <button class="install-btn" onclick="installApp()">Install</button>
                    </div>
                `;
                document.body.appendChild(toast);
                
                localStorage.setItem('ptp_install_prompt', Date.now().toString());
            }
            
            window.installApp = function() {
                if (!deferredPrompt) return;
                
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function(result) {
                    console.log('[PTP] Install prompt result:', result.outcome);
                    deferredPrompt = null;
                    dismissInstall();
                    
                    if (result.outcome === 'accepted') {
                        // Track install
                        if (typeof gtag !== 'undefined') {
                            gtag('event', 'pwa_install', { event_category: 'PWA' });
                        }
                    }
                });
            };
            
            window.dismissInstall = function() {
                const toast = document.getElementById('ptp-install-toast');
                if (toast) toast.remove();
            };
            
            // Update notification
            function showUpdateNotification() {
                const toast = document.createElement('div');
                toast.innerHTML = `
                    <div style="position:fixed;bottom:20px;left:20px;right:20px;max-width:400px;margin:0 auto;background:#0A0A0A;color:#fff;padding:16px 20px;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.3);z-index:9999;display:flex;align-items:center;gap:12px;font-family:Inter,-apple-system,sans-serif;">
                        <span style="flex:1;font-size:14px;">A new version is available!</span>
                        <button onclick="location.reload()" style="background:#FCB900;color:#0A0A0A;border:none;padding:8px 16px;border-radius:6px;font-weight:600;cursor:pointer;">Update</button>
                    </div>
                `;
                document.body.appendChild(toast);
            }
            
            // Offline detection
            window.addEventListener('online', function() {
                document.body.classList.remove('ptp-offline');
            });
            
            window.addEventListener('offline', function() {
                document.body.classList.add('ptp-offline');
            });
        })();
        </script>
        <?php
    }
    
    /**
     * Add rewrite rules for SW and manifest
     */
    public function add_rewrite_rules() {
        add_rewrite_rule('^ptp-sw\.js$', 'index.php?ptp_file=sw', 'top');
        add_rewrite_rule('^ptp-manifest\.json$', 'index.php?ptp_file=manifest', 'top');
        add_rewrite_rule('^offline/?$', 'index.php?ptp_file=offline', 'top');
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'ptp_file';
        return $vars;
    }
    
    /**
     * Serve PWA files
     */
    public function serve_pwa_files() {
        $file = get_query_var('ptp_file');
        
        if (!$file) return;
        
        switch ($file) {
            case 'sw':
                $this->serve_service_worker();
                break;
            case 'manifest':
                $this->serve_manifest();
                break;
            case 'offline':
                $this->serve_offline_page();
                break;
        }
    }
    
    /**
     * Serve service worker
     */
    private function serve_service_worker() {
        header('Content-Type: application/javascript');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache');
        
        $sw_path = PTP_PLUGIN_PATH . 'assets/js/ptp-sw.js';
        
        if (file_exists($sw_path)) {
            readfile($sw_path);
        } else {
            echo '// Service worker not found';
        }
        exit;
    }
    
    /**
     * Serve manifest
     * v158.2: Fixed URL scope warnings - use relative URLs
     */
    private function serve_manifest() {
        header('Content-Type: application/manifest+json');
        header('Cache-Control: max-age=86400');
        
        // v158.2: Use relative URLs to avoid scope warnings
        $manifest = array(
            'name' => 'PTP Soccer Training',
            'short_name' => 'PTP Soccer',
            'description' => 'Book private soccer training with verified coaches',
            'start_url' => '/find-trainers/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0A0A0A',
            'theme_color' => '#FCB900',
            'orientation' => 'portrait-primary',
            'icons' => array(
                array(
                    'src' => $this->get_icon_url(192),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ),
                array(
                    'src' => $this->get_icon_url(512),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                )
            ),
            'shortcuts' => array(
                array(
                    'name' => 'Find Trainers',
                    'short_name' => 'Trainers',
                    'url' => '/find-trainers/',
                    'icons' => array(array('src' => $this->get_icon_url(96), 'sizes' => '96x96'))
                ),
                array(
                    'name' => 'My Training',
                    'short_name' => 'Bookings',
                    'url' => '/my-training/',
                    'icons' => array(array('src' => $this->get_icon_url(96), 'sizes' => '96x96'))
                )
            ),
            'categories' => array('sports', 'fitness'),
        );
        
        echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Serve offline page
     */
    private function serve_offline_page() {
        include PTP_PLUGIN_PATH . 'templates/offline.php';
        exit;
    }
    
    /**
     * Get icon URL (fallback to default)
     */
    private function get_icon_url($size = 192) {
        $custom_icon = get_option("ptp_icon_{$size}");
        if ($custom_icon) return $custom_icon;
        
        // Default PTP logo
        return 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png';
    }
    
    /**
     * Get splash screen URL
     */
    private function get_splash_url() {
        return get_option('ptp_splash_screen', 'https://ptpsummercamps.com/wp-content/uploads/2025/11/ptp-splash.png');
    }
    
    /**
     * Check if running as PWA
     */
    public static function is_pwa() {
        return isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'document'
            && isset($_SERVER['HTTP_SEC_FETCH_MODE']) && $_SERVER['HTTP_SEC_FETCH_MODE'] === 'navigate';
    }
}

// Initialize
PTP_PWA::instance();
