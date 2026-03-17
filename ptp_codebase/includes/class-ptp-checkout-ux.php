<?php
/**
 * PTP Checkout UX Improvements v1.0.0
 * 
 * Fixes UX issues in the checkout flow between trainings and camps:
 * - Smarter popup timing (don't interrupt booking flow)
 * - Improved package display with visual hierarchy
 * - Better referral code UX
 * - Cleaner bundle cross-sell experience
 * 
 * @since 58.0.0
 */

defined('ABSPATH') || exit;

class PTP_Checkout_UX {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Fix popup timing - don't show on booking pages
        add_filter('ptp_should_show_exit_popup', array($this, 'smart_popup_timing'), 10, 1);

        // Enqueue improved styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));

        // v148: Only register PTP Native hooks if WC is active
        if (false) {
            // Improve package selection display on PTP Native checkout
            // REMOVED: add_action('ptp-native_before_checkout_form', array($this, 'render_training_packages_upsell'), 15);

            // Handle cart redirect with better UX
            // REMOVED: add_filter('ptp-native_add_to_cart_redirect', array($this, 'smart_cart_redirect'), 10, 2);

            // Improve order confirmation page
            // REMOVED: add_action('ptp-native_thankyou', array($this, 'render_improved_thank_you_cta'), 5);
        } else {
            // Native PTP hooks
            add_action('ptp_before_checkout_form', array($this, 'render_training_packages_upsell'), 15);
            add_action('ptp_thankyou', array($this, 'render_improved_thank_you_cta'), 5);
        }
    }
    
    /**
     * Smart popup timing - don't interrupt booking flow
     */
    public function smart_popup_timing($should_show) {
        // Don't show popup on these pages
        $url = $_SERVER['REQUEST_URI'] ?? '';
        $no_popup_patterns = array(
            '/trainer/',          // Trainer profile - they're already engaged
            '/book-session',      // Booking flow
            '/checkout',          // PTP Native checkout
            '/cart',              // Cart page
            '/booking-confirmation',
            '/my-training',
            'add-to-cart',        // Add to cart URLs
        );
        
        foreach ($no_popup_patterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return false;
            }
        }
        
        // Don't show if user has items in cart
        if (false && ptp_cart() && ptp_cart()->get_item_count() > 0) {
            return false;
        }
        
        // Don't show if there's a pending training booking
        if (isset($_COOKIE['ptp_pending_training']) || 
            (false && ptp_session() && ptp_session()->get('ptp_training_pending'))) {
            return false;
        }
        
        return $should_show;
    }
    
    /**
     * Render training packages upsell on checkout
     */
    public function render_training_packages_upsell() {
        // Only show if they have camps in cart but no training
        if (!function_exists('ptp_cart') || !ptp_cart()) return;
        
        $has_camp = false;
        foreach (ptp_cart()->get_items() as $item) {
            $product = $item['data'];
            $title = strtolower($product->get_name());
            $cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'slugs'));
            
            if (strpos($title, 'camp') !== false || 
                strpos($title, 'clinic') !== false ||
                array_intersect($cats, array('camps', 'clinics'))) {
                $has_camp = true;
                break;
            }
        }
        
        if (!$has_camp) return;
        
        // Check if bundle discount is active
        $has_bundle = ptp_session()->get('ptp_bundle_discount');
        
        ?>
        <div class="ptp-checkout-upsell" style="margin-bottom:24px">
            <?php if ($has_bundle): ?>
            <!-- Bundle Active - Show Confirmation -->
            <div style="background:linear-gradient(135deg,#0A0A0A 0%,#1F2937 100%);border-radius:16px;padding:24px;color:#fff">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                    <div style="font-size:40px">🎯</div>
                    <div style="flex:1;min-width:200px">
                        <div style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;text-transform:uppercase;color:#FCB900;margin-bottom:4px">
                            Bundle Discount Applied!
                        </div>
                        <div style="font-size:14px;opacity:0.9">
                            Complete your camp purchase, then book your training session to finish the bundle.
                        </div>
                    </div>
                    <div style="background:#FCB900;color:#0A0A0A;padding:8px 16px;border-radius:8px;font-weight:700;font-family:'Oswald',sans-serif;font-size:20px">
                        5% OFF
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- No Bundle - Show Training Upsell -->
            <div style="background:#FFFBEB;border:2px solid #FCB900;border-radius:16px;padding:24px">
                <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
                    <div style="font-size:40px;line-height:1">⚡</div>
                    <div style="flex:1;min-width:250px">
                        <div style="font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;text-transform:uppercase;color:#0A0A0A;margin-bottom:8px">
                            Add Private Training & Save 5%
                        </div>
                        <div style="font-size:14px;color:#4B5563;margin-bottom:16px">
                            Combine your camp registration with 1-on-1 training for maximum skill development. 
                            Perfect for players who want personalized attention before or after camp.
                        </div>
                        <a href="<?php echo esc_url(home_url('/find-trainers/?bundle=1')); ?>" 
                           style="display:inline-flex;align-items:center;gap:8px;background:#0A0A0A;color:#FCB900;
                                  padding:12px 24px;border-radius:8px;font-family:'Oswald',sans-serif;font-weight:700;
                                  text-transform:uppercase;text-decoration:none;font-size:14px;transition:all 0.2s">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                            Browse Trainers
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render bundle awareness banner
     */
    public function render_bundle_awareness_banner() {
        // DISABLED: Reduces popup/banner noise on cart pages
        return;
        
        ?>
        <style>
        .ptp-bundle-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #0A0A0A;
            color: #fff;
            padding: 0;
            z-index: 9990;
            transform: translateY(100%);
            transition: transform 0.3s ease;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
        }
        .ptp-bundle-bar.show { transform: translateY(0); }
        .ptp-bundle-bar-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .ptp-bundle-bar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .ptp-bundle-bar-icon { font-size: 24px; }
        .ptp-bundle-bar-text strong { color: #FCB900; }
        .ptp-bundle-bar-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #FCB900;
            color: #0A0A0A;
            padding: 12px 24px;
            border-radius: 8px;
            font-family: 'Oswald', sans-serif;
            font-weight: 700;
            text-transform: uppercase;
            text-decoration: none;
            font-size: 14px;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .ptp-bundle-bar-cta:hover { background: #fff; }
        .ptp-bundle-bar-close {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.5);
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }
        @media (max-width: 640px) {
            .ptp-bundle-bar-inner {
                flex-direction: column;
                text-align: center;
            }
            .ptp-bundle-bar-text { font-size: 14px; }
        }
        </style>
        
        <div class="ptp-bundle-bar" id="ptp-bundle-bar">
            <div class="ptp-bundle-bar-inner">
                <div class="ptp-bundle-bar-left">
                    <span class="ptp-bundle-bar-icon">🎁</span>
                    <span class="ptp-bundle-bar-text">
                        <strong>Save 5%</strong> - Add private training with this camp
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    <a href="<?php echo esc_url(home_url('/find-trainers/?bundle=1')); ?>" class="ptp-bundle-bar-cta">
                        Add Training
                    </a>
                    <button class="ptp-bundle-bar-close" onclick="closeBundleBar()">&times;</button>
                </div>
            </div>
        </div>
        
        <script>
        (function() {
            var bar = document.getElementById('ptp-bundle-bar');
            if (!bar) return;
            
            // Don't show if dismissed recently
            if (sessionStorage.getItem('ptp_bundle_bar_dismissed')) return;
            
            // Show after 3 seconds
            setTimeout(function() {
                bar.classList.add('show');
            }, 3000);
            
            window.closeBundleBar = function() {
                bar.classList.remove('show');
                sessionStorage.setItem('ptp_bundle_bar_dismissed', '1');
            };
        })();
        </script>
        <?php
    }
    
    /**
     * Smart cart redirect
     */
    public function smart_cart_redirect($url, $product) {
        // If bundle flag is set, go to checkout instead of cart
        if (isset($_GET['ptp_bundle']) && $_GET['ptp_bundle'] == '1') {
            return home_url('/ptp-checkout/');
        }
        return $url;
    }
    
    /**
     * Render improved thank you page CTA
     */
    public function render_improved_thank_you_cta($order_id) {
        if (!function_exists('ptp_session') || !ptp_session()) return;
        
        // Check for pending training
        $has_pending = ptp_session()->get('ptp_training_pending');
        $pending_url = isset($_COOKIE['ptp_pending_training_url']) ? $_COOKIE['ptp_pending_training_url'] : '';
        
        if (!$has_pending) return;
        
        // Clear session
        ptp_session()->set('ptp_training_pending', false);
        
        ?>
        <div class="ptp-thankyou-training" style="background:linear-gradient(135deg,#0A0A0A 0%,#1a1a1a 100%);
                                                   border-radius:20px;padding:40px 32px;margin:32px 0;text-align:center;
                                                   border:2px solid #FCB900;position:relative;overflow:hidden">
            <!-- Background Pattern -->
            <div style="position:absolute;top:0;left:0;right:0;bottom:0;opacity:0.05;
                        background:repeating-linear-gradient(45deg,#FCB900,#FCB900 10px,transparent 10px,transparent 20px)"></div>
            
            <div style="position:relative">
                <div style="width:80px;height:80px;background:#FCB900;border-radius:50%;margin:0 auto 20px;
                            display:flex;align-items:center;justify-content:center;font-size:40px">
                    ✅
                </div>
                
                <h2 style="font-family:'Oswald',sans-serif;font-size:28px;color:#fff;margin:0 0 8px;text-transform:uppercase">
                    Camp Registration Complete!
                </h2>
                
                <p style="color:#FCB900;font-size:18px;font-weight:700;margin:0 0 16px">
                    Now finish your bundle and save 5%
                </p>
                
                <p style="color:rgba(255,255,255,0.7);font-size:15px;margin:0 0 28px;max-width:500px;margin-left:auto;margin-right:auto">
                    You've unlocked a special bundle discount! Book your private training session now 
                    to get personalized instruction before or after camp.
                </p>
                
                <a href="<?php echo esc_url($pending_url ?: home_url('/find-trainers/')); ?>" 
                   id="ptp-complete-training-btn"
                   onclick="localStorage.removeItem('ptp_pending_training_booking');"
                   style="display:inline-flex;align-items:center;gap:10px;background:#FCB900;color:#0A0A0A;
                          padding:18px 40px;border-radius:10px;font-family:'Oswald',sans-serif;font-weight:700;
                          font-size:18px;text-transform:uppercase;text-decoration:none;transition:all 0.2s;
                          box-shadow:0 4px 20px rgba(252,185,0,0.4)">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    Book Training Session
                </a>
                
                <p style="color:rgba(255,255,255,0.5);font-size:13px;margin:20px 0 0">
                    <a href="#" onclick="skipTraining();return false;" style="color:rgba(255,255,255,0.5);text-decoration:underline">
                        Skip for now (you can add training later)
                    </a>
                </p>
            </div>
        </div>
        
        <script>
        (function() {
            var pendingUrl = localStorage.getItem('ptp_pending_training_booking');
            var btn = document.getElementById('ptp-complete-training-btn');
            if (pendingUrl && btn) {
                btn.href = pendingUrl;
            }
            
            window.skipTraining = function() {
                localStorage.removeItem('ptp_pending_training_booking');
                localStorage.removeItem('ptp_bundle_discount');
                document.querySelector('.ptp-thankyou-training').style.display = 'none';
            };
        })();
        </script>
        <?php
    }
    
    /**
     * Enqueue styles
     */
    public function enqueue_styles() {
        // Only on relevant pages
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($url, '/checkout') === false && 
            strpos($url, '/cart') === false &&
            strpos($url, '/training') === false) {
            return;
        }
        
        wp_add_inline_style('ptp-native-general', '
            .ptp-checkout-upsell a:hover {
                background: #FCB900 !important;
                color: #0A0A0A !important;
            }
        ');
    }
}

// Initialize
add_action('plugins_loaded', function() {
    PTP_Checkout_UX::instance();
}, 20);
