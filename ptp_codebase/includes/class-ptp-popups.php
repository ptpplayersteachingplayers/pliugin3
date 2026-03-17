<?php
/**
 * PTP Popups & Exit Intent v1.0.0
 * 
 * - Exit intent popup for email capture
 * - First-time visitor discount offer
 * - Referral program promotion
 */

defined('ABSPATH') || exit;

class PTP_Popups {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_footer', array($this, 'render_exit_popup'));
        add_action('wp_ajax_ptp_subscribe_email', array($this, 'subscribe_email'));
        add_action('wp_ajax_nopriv_ptp_subscribe_email', array($this, 'subscribe_email'));
    }
    
    /**
     * Render exit intent popup
     */
    public function render_exit_popup() {
        // Don't show on admin
        if (is_admin()) return;
        
        // DISABLED: Exit popup causes user friction
        // Uncomment the code below to re-enable
        return;
        
        // Apply smart timing filter - allows other code to prevent popup
        $should_show = apply_filters('ptp_should_show_exit_popup', true);
        if (!$should_show) return;
        
        // Legacy checks (kept for backward compatibility)
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($url, '/checkout') !== false || 
            strpos($url, '/booking-confirmation') !== false ||
            strpos($url, '/my-training') !== false ||
            strpos($url, '/trainer-dashboard') !== false ||
            strpos($url, '/trainer/') !== false ||
            strpos($url, '/book-session') !== false ||
            strpos($url, '/cart') !== false) {
            return;
        }
        
        $logo_url = class_exists('PTP_Images') ? PTP_Images::logo() : '';
        $nonce = wp_create_nonce('ptp_popup');
        ?>
        
        <style>
        /* Exit Intent Popup */
        .ptp-exit-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 99999;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            opacity: 0;
            transition: opacity 0.3s;
        }
        .ptp-exit-overlay.show {
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 1;
        }
        .ptp-exit-popup {
            background: #fff;
            border-radius: 16px;
            max-width: 440px;
            width: 90%;
            overflow: hidden;
            transform: scale(0.9) translateY(20px);
            transition: transform 0.3s;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }
        .ptp-exit-overlay.show .ptp-exit-popup {
            transform: scale(1) translateY(0);
        }
        .ptp-exit-header {
            background: linear-gradient(135deg, #0A0A0A 0%, #1F2937 100%);
            padding: 32px 24px;
            text-align: center;
            position: relative;
        }
        .ptp-exit-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            background: rgba(255,255,255,0.1);
            border: none;
            border-radius: 50%;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .ptp-exit-close:hover {
            background: rgba(255,255,255,0.2);
        }
        .ptp-exit-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(252,185,0,0.2);
            color: #FCB900;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .ptp-exit-title {
            font-family: 'Oswald', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: #fff;
            margin: 0 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .ptp-exit-subtitle {
            font-size: 16px;
            color: rgba(255,255,255,0.7);
            margin: 0;
        }
        .ptp-exit-discount {
            font-size: 48px;
            font-weight: 900;
            color: #FCB900;
            font-family: 'Oswald', sans-serif;
        }
        .ptp-exit-body {
            padding: 24px;
        }
        .ptp-exit-benefits {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 24px;
        }
        .ptp-exit-benefit {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: #374151;
        }
        .ptp-exit-benefit svg {
            color: #10B981;
            flex-shrink: 0;
        }
        .ptp-exit-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .ptp-exit-input {
            padding: 14px 16px;
            border: 2px solid #E5E7EB;
            border-radius: 8px;
            font-size: 16px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }
        .ptp-exit-input:focus {
            border-color: #FCB900;
        }
        .ptp-exit-btn {
            padding: 16px;
            background: #FCB900;
            color: #0A0A0A;
            border: none;
            border-radius: 8px;
            font-family: 'Oswald', sans-serif;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ptp-exit-btn:hover {
            background: #e5a800;
            transform: translateY(-1px);
        }
        .ptp-exit-btn:disabled {
            background: #E5E7EB;
            color: #9CA3AF;
            cursor: not-allowed;
            transform: none;
        }
        .ptp-exit-terms {
            font-size: 11px;
            color: #9CA3AF;
            text-align: center;
            margin-top: 12px;
        }
        .ptp-exit-success {
            text-align: center;
            padding: 20px 0;
        }
        .ptp-exit-success-icon {
            width: 64px;
            height: 64px;
            background: #D1FAE5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .ptp-exit-success-icon svg {
            color: #059669;
        }
        .ptp-exit-success h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 24px;
            color: #111;
            margin: 0 0 8px;
            text-transform: uppercase;
        }
        .ptp-exit-success p {
            color: #6B7280;
            margin: 0 0 16px;
        }
        .ptp-exit-code {
            background: #FEF3C7;
            border: 2px dashed #FCB900;
            padding: 12px 24px;
            border-radius: 8px;
            font-family: 'Oswald', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: #0A0A0A;
            letter-spacing: 2px;
            display: inline-block;
        }
        
        /* Referral Banner (bottom of trainer profile) */
        .ptp-referral-banner {
            background: linear-gradient(135deg, #0A0A0A 0%, #1F2937 100%);
            padding: 24px;
            border-radius: 12px;
            margin: 24px 0;
            text-align: center;
        }
        .ptp-referral-title {
            font-family: 'Oswald', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: #FCB900;
            margin: 0 0 8px;
            text-transform: uppercase;
        }
        .ptp-referral-text {
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            margin: 0 0 16px;
        }
        .ptp-referral-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #FCB900;
            color: #0A0A0A;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .ptp-referral-btn:hover {
            background: #e5a800;
            transform: translateY(-1px);
        }
        </style>
        
        <div id="ptp-exit-overlay" class="ptp-exit-overlay">
            <div class="ptp-exit-popup">
                <div class="ptp-exit-header">
                    <button class="ptp-exit-close" onclick="closeExitPopup()">&times;</button>
                    <div class="ptp-exit-badge">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        First-Time Visitor Special
                    </div>
                    <div class="ptp-exit-discount">$10 OFF</div>
                    <h2 class="ptp-exit-title">Your First Training Session</h2>
                    <p class="ptp-exit-subtitle">Don't miss out on elite 1-on-1 training!</p>
                </div>
                
                <div class="ptp-exit-body">
                    <div id="exit-form-container">
                        <div class="ptp-exit-benefits">
                            <div class="ptp-exit-benefit">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                Train with NCAA D1 & pro athletes
                            </div>
                            <div class="ptp-exit-benefit">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                100% money-back guarantee
                            </div>
                            <div class="ptp-exit-benefit">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                Free cancellation up to 24 hours
                            </div>
                        </div>
                        
                        <form class="ptp-exit-form" onsubmit="submitExitForm(event)">
                            <input type="email" class="ptp-exit-input" id="exit-email" placeholder="Enter your email" required>
                            <button type="submit" class="ptp-exit-btn" id="exit-submit">Get My $10 Off Code</button>
                        </form>
                        
                        <p class="ptp-exit-terms">No spam, ever. Unsubscribe anytime.</p>
                    </div>
                    
                    <div id="exit-success-container" style="display:none">
                        <div class="ptp-exit-success">
                            <div class="ptp-exit-success-icon">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            </div>
                            <h3>You're In!</h3>
                            <p>Use this code at checkout:</p>
                            <div class="ptp-exit-code" id="discount-code">WELCOME10</div>
                            <p style="margin-top:16px;font-size:13px">Code expires in 48 hours</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        (function() {
            var shown = false;
            var exitOverlay = document.getElementById('ptp-exit-overlay');
            
            // Check if already shown this session or user subscribed
            if (sessionStorage.getItem('ptp_exit_shown') || localStorage.getItem('ptp_subscribed')) {
                return;
            }
            
            // Exit intent detection
            document.addEventListener('mouseout', function(e) {
                if (shown) return;
                if (e.clientY < 10 && e.relatedTarget === null) {
                    showExitPopup();
                }
            });
            
            // Mobile: Show after 30 seconds
            if (/Mobi|Android/i.test(navigator.userAgent)) {
                setTimeout(function() {
                    if (!shown && !sessionStorage.getItem('ptp_exit_shown')) {
                        showExitPopup();
                    }
                }, 30000);
            }
            
            window.showExitPopup = function() {
                if (shown) return;
                shown = true;
                sessionStorage.setItem('ptp_exit_shown', '1');
                exitOverlay.classList.add('show');
                document.body.style.overflow = 'hidden';
            };
            
            window.closeExitPopup = function() {
                exitOverlay.classList.remove('show');
                document.body.style.overflow = '';
            };
            
            // Close on overlay click
            exitOverlay.addEventListener('click', function(e) {
                if (e.target === exitOverlay) {
                    closeExitPopup();
                }
            });
            
            // Close on escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeExitPopup();
                }
            });
            
            window.submitExitForm = function(e) {
                e.preventDefault();
                
                var email = document.getElementById('exit-email').value;
                var btn = document.getElementById('exit-submit');
                
                btn.disabled = true;
                btn.textContent = 'Processing...';
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=ptp_subscribe_email&nonce=<?php echo $nonce; ?>&email=' + encodeURIComponent(email)
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        localStorage.setItem('ptp_subscribed', '1');
                        document.getElementById('exit-form-container').style.display = 'none';
                        document.getElementById('exit-success-container').style.display = 'block';
                        document.getElementById('discount-code').textContent = data.data.code;
                        
                        // Track conversion
                        if (window.ptpTrack) {
                            ptpTrack('email_capture', { source: 'exit_popup' });
                        }
                    } else {
                        alert('Something went wrong. Please try again.');
                        btn.disabled = false;
                        btn.textContent = 'Get My $10 Off Code';
                    }
                })
                .catch(function() {
                    alert('Something went wrong. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Get My $10 Off Code';
                });
            };
        })();
        </script>
        <?php
    }
    
    /**
     * Handle email subscription
     */
    public function subscribe_email() {
        check_ajax_referer('ptp_popup', 'nonce');
        
        $email = sanitize_email($_POST['email'] ?? '');
        
        if (!is_email($email)) {
            wp_send_json_error('Invalid email');
        }
        
        global $wpdb;
        
        // Check if already subscribed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_subscribers WHERE email = %s",
            $email
        ));
        
        // Generate discount code
        $code = 'WELCOME' . strtoupper(substr(md5($email), 0, 4));
        
        if (!$existing) {
            // Create subscribers table if needed
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_subscribers (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                email varchar(255) NOT NULL,
                source varchar(50) DEFAULT 'exit_popup',
                discount_code varchar(20),
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY email (email)
            ) " . $wpdb->get_charset_collate());
            
            $wpdb->insert(
                $wpdb->prefix . 'ptp_subscribers',
                array(
                    'email' => $email,
                    'source' => 'exit_popup',
                    'discount_code' => $code,
                )
            );
        }
        
        // Store discount for later use
        update_option('ptp_discount_' . $code, array(
            'amount' => 10,
            'email' => $email,
            'expires' => time() + (48 * 3600),
            'used' => false,
        ));
        
        // Send welcome email with code
        $this->send_welcome_email($email, $code);
        
        wp_send_json_success(array('code' => $code));
    }
    
    /**
     * Send welcome email with discount code
     */
    private function send_welcome_email($email, $code) {
        $logo_url = class_exists('PTP_Images') ? PTP_Images::logo() : '';
        $browse_url = home_url('/find-trainers/');
        
        $subject = "Your $10 discount code is inside! 🎁";
        
        $body = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="utf-8"></head>
        <body style="margin:0;padding:0;background:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">
            <div style="max-width:600px;margin:0 auto;padding:20px">
                <div style="text-align:center;padding:20px 0">
                    <img src="' . esc_url($logo_url) . '" alt="PTP Training" style="height:40px">
                </div>
                <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
                    <div style="background:linear-gradient(135deg,#FCB900,#F59E0B);padding:32px;text-align:center">
                        <h1 style="margin:0;font-size:32px;color:#0A0A0A">$10 OFF</h1>
                        <p style="margin:8px 0 0;color:#0A0A0A;opacity:0.8">Your first training session</p>
                    </div>
                    <div style="padding:32px;text-align:center">
                        <p style="margin:0 0 24px;color:#374151;font-size:16px">Use this code at checkout:</p>
                        <div style="background:#FEF3C7;border:2px dashed #FCB900;padding:16px 32px;border-radius:8px;display:inline-block;margin-bottom:24px">
                            <span style="font-size:28px;font-weight:700;color:#0A0A0A;letter-spacing:2px">' . esc_html($code) . '</span>
                        </div>
                        <p style="margin:0 0 24px;color:#6B7280;font-size:14px">Code expires in 48 hours</p>
                        <a href="' . esc_url($browse_url) . '" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:16px">Find a Trainer</a>
                    </div>
                </div>
                <div style="text-align:center;padding:24px;color:#6B7280;font-size:13px">
                    <p style="margin:0">PTP Training - Train With The Best</p>
                </div>
            </div>
        </body>
        </html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
        );
        
        wp_mail($email, $subject, $body, $headers);
    }
}

// Initialize
PTP_Popups::instance();

/**
 * Render referral banner for logged-in users
 */
function ptp_render_referral_banner() {
    if (!is_user_logged_in()) return;
    
    $user_id = get_current_user_id();
    $referral_code = PTP_Email_Automation::get_referral_code($user_id);
    $share_url = home_url('/find-trainers/?ref=' . $referral_code);
    ?>
    <div class="ptp-referral-banner">
        <h3 class="ptp-referral-title">Give $20, Get $20</h3>
        <p class="ptp-referral-text">Share your referral code with friends. They get $20 off, you get $20 credit!</p>
        <div style="background:#FEF3C7;display:inline-block;padding:8px 16px;border-radius:6px;margin-bottom:12px">
            <span style="font-family:Oswald,sans-serif;font-size:20px;font-weight:700;letter-spacing:2px;color:#0A0A0A"><?php echo esc_html($referral_code); ?></span>
        </div>
        <br>
        <a href="sms:?body=Train%20with%20elite%20athletes%20near%20you!%20Use%20my%20code%20<?php echo esc_attr($referral_code); ?>%20for%20%2420%20off%3A%20<?php echo urlencode($share_url); ?>" class="ptp-referral-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 002 2h12a2 2 0 002-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            Share via Text
        </a>
    </div>
    <?php
}
