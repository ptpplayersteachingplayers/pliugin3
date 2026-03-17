<?php
/**
 * PTP Growth Engine v1.0.0
 * 
 * Handles:
 * - Camp upsell modals during booking flow
 * - Bundle discounts (training + camp)
 * - Real-time social proof notifications
 * - Social sharing integration
 * - Waitlist for sold-out camps
 * - Early bird & sibling discounts
 */

defined('ABSPATH') || exit;

class PTP_Growth {
    
    private static $instance = null;
    
    // Discount settings
    const BUNDLE_DISCOUNT_PERCENT = 5;  // 5% off when booking training + camp
    const SIBLING_DISCOUNT_PERCENT = 20; // 20% off second child
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Camp upsell modal
        add_action('wp_footer', array($this, 'render_camp_upsell_modal'));
        
        // Social proof notifications - DISABLED v123: per user request
        // add_action('wp_footer', array($this, 'render_social_proof'));
        
        // Social sharing buttons
        add_action('wp_footer', array($this, 'render_share_modal'));
        
        // Pending training booking banner (after PTP Native checkout) - DISABLED: interferes with mobile UX
        // add_action('wp_footer', array($this, 'render_pending_training_banner'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_get_camp_upsell', array($this, 'ajax_get_camp_upsell'));
        add_action('wp_ajax_nopriv_ptp_get_camp_upsell', array($this, 'ajax_get_camp_upsell'));
        add_action('wp_ajax_ptp_add_to_waitlist', array($this, 'ajax_add_to_waitlist'));
        add_action('wp_ajax_nopriv_ptp_add_to_waitlist', array($this, 'ajax_add_to_waitlist'));
        add_action('wp_ajax_ptp_get_recent_bookings', array($this, 'ajax_get_recent_bookings'));
        add_action('wp_ajax_nopriv_ptp_get_recent_bookings', array($this, 'ajax_get_recent_bookings'));
        add_action('wp_ajax_ptp_apply_bundle_discount', array($this, 'ajax_apply_bundle_discount'));
        add_action('wp_ajax_nopriv_ptp_apply_bundle_discount', array($this, 'ajax_apply_bundle_discount'));
        add_action('wp_ajax_ptp_clear_pending_training', array($this, 'ajax_clear_pending_training'));
        add_action('wp_ajax_nopriv_ptp_clear_pending_training', array($this, 'ajax_clear_pending_training'));
        
        // PTP Native bundle discount
        // REMOVED: add_action('ptp-native_cart_calculate_fees', array($this, 'apply_bundle_discount_to_cart'));
        
        // PTP Native thank you page - show training booking CTA
        // REMOVED: add_action('ptp-native_thankyou', array($this, 'show_training_booking_cta'), 5);
        
        // Track bundle flag from URL
        add_action('init', array($this, 'capture_bundle_flag'));
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_waitlist (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id bigint(20) UNSIGNED NOT NULL,
            email varchar(255) NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            phone varchar(20) DEFAULT NULL,
            notified tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            notified_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY email (email)
        ) $charset;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Render camp upsell modal (triggered after booking step 3)
     */
    public function render_camp_upsell_modal() {
        // Only on booking-related pages
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($url, '/book-session') === false && 
            strpos($url, '/checkout') === false &&
            strpos($url, '/trainer/') === false) {
            return;
        }
        
        $nonce = wp_create_nonce('ptp_growth');
        ?>
        <style>
        /* Camp Upsell Modal */
        .ptp-upsell-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 99998;
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }
        .ptp-upsell-overlay.show {
            display: flex;
        }
        .ptp-upsell-modal {
            background: #fff;
            border-radius: 16px;
            max-width: 500px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: transform 0.3s;
        }
        .ptp-upsell-overlay.show .ptp-upsell-modal {
            transform: scale(1);
        }
        .ptp-upsell-header {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            padding: 24px;
            text-align: center;
            position: relative;
        }
        .ptp-upsell-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(255,255,255,0.2);
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
        }
        .ptp-upsell-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            color: #fff;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .ptp-upsell-title {
            font-family: 'Oswald', sans-serif;
            font-size: 26px;
            font-weight: 700;
            color: #fff;
            margin: 0 0 8px;
            text-transform: uppercase;
        }
        .ptp-upsell-subtitle {
            color: rgba(255,255,255,0.9);
            margin: 0;
            font-size: 15px;
        }
        .ptp-upsell-body {
            padding: 24px;
        }
        .ptp-upsell-camps {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }
        .ptp-upsell-camp {
            display: flex;
            gap: 12px;
            padding: 12px;
            border: 2px solid #E5E7EB;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ptp-upsell-camp:hover {
            border-color: #10B981;
            background: #ECFDF5;
        }
        .ptp-upsell-camp.selected {
            border-color: #10B981;
            background: #ECFDF5;
        }
        .ptp-upsell-camp-img {
            width: 80px;
            height: 60px;
            border-radius: 6px;
            object-fit: cover;
            flex-shrink: 0;
        }
        .ptp-upsell-camp-info {
            flex: 1;
            min-width: 0;
        }
        .ptp-upsell-camp-name {
            font-weight: 700;
            font-size: 14px;
            color: #111;
            margin: 0 0 4px;
        }
        .ptp-upsell-camp-meta {
            font-size: 12px;
            color: #6B7280;
            margin: 0;
        }
        .ptp-upsell-camp-price {
            text-align: right;
            flex-shrink: 0;
        }
        .ptp-upsell-camp-amount {
            font-weight: 700;
            font-size: 16px;
            color: #111;
        }
        .ptp-upsell-camp-original {
            font-size: 12px;
            color: #9CA3AF;
            text-decoration: line-through;
        }
        .ptp-upsell-camp-discount {
            font-size: 11px;
            color: #10B981;
            font-weight: 600;
        }
        .ptp-upsell-summary {
            background: #F9FAFB;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .ptp-upsell-summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .ptp-upsell-summary-row:last-child {
            margin-bottom: 0;
            padding-top: 8px;
            border-top: 1px solid #E5E7EB;
            font-weight: 700;
        }
        .ptp-upsell-summary-savings {
            color: #10B981;
        }
        .ptp-upsell-actions {
            display: flex;
            gap: 12px;
        }
        .ptp-upsell-btn {
            flex: 1;
            padding: 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            border: none;
            transition: all 0.2s;
        }
        .ptp-upsell-btn.primary {
            background: #10B981;
            color: #fff;
        }
        .ptp-upsell-btn.primary:hover {
            background: #059669;
        }
        .ptp-upsell-btn.secondary {
            background: #fff;
            color: #6B7280;
            border: 2px solid #E5E7EB;
        }
        .ptp-upsell-btn.secondary:hover {
            border-color: #9CA3AF;
        }
        .ptp-upsell-urgency {
            text-align: center;
            font-size: 12px;
            color: #DC2626;
            margin-top: 12px;
        }
        </style>
        
        <div id="ptp-upsell-overlay" class="ptp-upsell-overlay">
            <div class="ptp-upsell-modal">
                <div class="ptp-upsell-header">
                    <button class="ptp-upsell-close" onclick="closeUpsellModal()">&times;</button>
                    <div class="ptp-upsell-badge">🎁 Bundle & Save <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>%</div>
                    <h2 class="ptp-upsell-title">Add a Camp?</h2>
                    <p class="ptp-upsell-subtitle">Get <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>% off when you book training + camp together!</p>
                </div>
                <div class="ptp-upsell-body">
                    <div class="ptp-upsell-camps" id="upsell-camps-list">
                        <!-- Loaded via AJAX -->
                        <div style="text-align:center;padding:20px;color:#6B7280">Loading camps...</div>
                    </div>
                    
                    <div class="ptp-upsell-summary" id="upsell-summary" style="display:none">
                        <div class="ptp-upsell-summary-row">
                            <span>Training Session</span>
                            <span id="upsell-training-price">$80</span>
                        </div>
                        <div class="ptp-upsell-summary-row">
                            <span>Camp</span>
                            <span id="upsell-camp-price">$0</span>
                        </div>
                        <div class="ptp-upsell-summary-row ptp-upsell-summary-savings">
                            <span>Bundle Discount (<?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>%)</span>
                            <span id="upsell-discount">-$0</span>
                        </div>
                        <div class="ptp-upsell-summary-row">
                            <span>Total</span>
                            <span id="upsell-total">$0</span>
                        </div>
                    </div>
                    
                    <div class="ptp-upsell-actions">
                        <button class="ptp-upsell-btn secondary" onclick="closeUpsellModal()">No Thanks</button>
                        <button class="ptp-upsell-btn primary" id="upsell-add-btn" onclick="addCampToCart()" disabled>Add Camp</button>
                    </div>
                    
                    <div class="ptp-upsell-urgency" id="upsell-urgency" style="display:none">
                        ⚡ Only <span id="upsell-spots">5</span> spots left!
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        (function() {
            var nonce = '<?php echo $nonce; ?>';
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var selectedCamp = null;
            var trainingPrice = 80;
            
            // Show upsell after booking initiated
            window.showCampUpsell = function(sessionPrice) {
                trainingPrice = sessionPrice || 80;
                document.getElementById('ptp-upsell-overlay').classList.add('show');
                document.body.style.overflow = 'hidden';
                loadCamps();
            };
            
            window.closeUpsellModal = function(skipBooking) {
                document.getElementById('ptp-upsell-overlay').classList.remove('show');
                document.body.style.overflow = '';
                
                // Continue to training booking if there's a stored URL (and not skipping)
                if (!skipBooking) {
                    var bookingUrl = sessionStorage.getItem('ptp_booking_url');
                    if (bookingUrl) {
                        sessionStorage.removeItem('ptp_booking_url');
                        window.location.href = bookingUrl;
                    }
                }
            };
            
            function loadCamps() {
                fetch(ajaxUrl + '?action=ptp_get_camp_upsell&nonce=' + nonce)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.data.camps.length > 0) {
                            renderCamps(data.data.camps);
                        } else {
                            // No camps available, continue to booking
                            closeUpsellModal();
                        }
                    });
            }
            
            function renderCamps(camps) {
                var html = '';
                camps.forEach(function(camp) {
                    var discountedPrice = camp.price * (1 - <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>/100);
                    html += '<div class="ptp-upsell-camp" data-id="' + camp.id + '" data-price="' + camp.price + '" data-url="' + camp.url + '" onclick="selectCamp(this)">' +
                        '<img src="' + camp.image + '" class="ptp-upsell-camp-img" alt="">' +
                        '<div class="ptp-upsell-camp-info">' +
                            '<p class="ptp-upsell-camp-name">' + camp.name + '</p>' +
                            '<p class="ptp-upsell-camp-meta">' + camp.date + ' • ' + camp.location + '</p>' +
                        '</div>' +
                        '<div class="ptp-upsell-camp-price">' +
                            '<div class="ptp-upsell-camp-amount">$' + Math.round(discountedPrice) + '</div>' +
                            '<div class="ptp-upsell-camp-original">$' + camp.price + '</div>' +
                            '<div class="ptp-upsell-camp-discount">Save $' + Math.round(camp.price * <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>/100) + '</div>' +
                        '</div>' +
                    '</div>';
                });
                document.getElementById('upsell-camps-list').innerHTML = html;
            }
            
            window.selectCamp = function(el) {
                document.querySelectorAll('.ptp-upsell-camp').forEach(c => c.classList.remove('selected'));
                el.classList.add('selected');
                
                selectedCamp = {
                    id: el.dataset.id,
                    price: parseFloat(el.dataset.price),
                    url: el.dataset.url
                };
                
                updateSummary();
            };
            
            function updateSummary() {
                if (!selectedCamp) return;
                
                var campPrice = selectedCamp.price;
                var subtotal = trainingPrice + campPrice;
                var discount = subtotal * (<?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>/100);
                var total = subtotal - discount;
                
                document.getElementById('upsell-training-price').textContent = '$' + trainingPrice;
                document.getElementById('upsell-camp-price').textContent = '$' + campPrice;
                document.getElementById('upsell-discount').textContent = '-$' + Math.round(discount);
                document.getElementById('upsell-total').textContent = '$' + Math.round(total);
                document.getElementById('upsell-summary').style.display = 'block';
                document.getElementById('upsell-add-btn').disabled = false;
            }
            
            window.addCampToCart = function() {
                if (!selectedCamp) return;
                
                // Store training booking info for after PTP Native checkout
                var bookingUrl = sessionStorage.getItem('ptp_booking_url');
                if (bookingUrl) {
                    // Save to localStorage for persistence across PTP Native checkout
                    localStorage.setItem('ptp_pending_training_booking', bookingUrl);
                    localStorage.setItem('ptp_bundle_discount', '<?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>');
                }
                
                // Track event
                if (window.ptpPixelTrack) {
                    ptpPixelTrack('AddToCart', {
                        content_type: 'camp_bundle',
                        value: selectedCamp.price,
                        currency: 'USD'
                    });
                }
                
                // Add camp to PTP Native cart and redirect to cart page
                // The cart will show the training upsell with 15% bundle discount
                window.location.href = '<?php echo esc_url(home_url('/ptp-cart/')); ?>?add-to-cart=' + selectedCamp.id;
            };
            
            // Close on overlay click
            document.getElementById('ptp-upsell-overlay').addEventListener('click', function(e) {
                if (e.target === this) closeUpsellModal();
            });
        })();
        </script>
        <?php
    }
    
    /**
     * Render social proof notifications
     */
    public function render_social_proof() {
        // Only on trainer-related pages
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($url, '/find-trainers') === false && 
            strpos($url, '/trainer/') === false &&
            strpos($url, '/training') === false &&
            $url !== '/' && $url !== '') {
            return;
        }
        
        $nonce = wp_create_nonce('ptp_growth');
        ?>
        <style>
        .ptp-social-proof {
            position: fixed;
            bottom: 24px;
            left: 24px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 9999;
            max-width: 320px;
            transform: translateX(-400px);
            transition: transform 0.4s ease;
            border-left: 4px solid #10B981;
        }
        .ptp-social-proof.show {
            transform: translateX(0);
        }
        .ptp-social-proof-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        .ptp-social-proof-content {
            flex: 1;
            min-width: 0;
        }
        .ptp-social-proof-text {
            font-size: 14px;
            color: #111;
            margin: 0 0 2px;
            line-height: 1.3;
        }
        .ptp-social-proof-text strong {
            color: #10B981;
        }
        .ptp-social-proof-time {
            font-size: 11px;
            color: #9CA3AF;
        }
        .ptp-social-proof-close {
            position: absolute;
            top: 6px;
            right: 8px;
            background: none;
            border: none;
            color: #9CA3AF;
            font-size: 16px;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }
        @media (max-width: 640px) {
            .ptp-social-proof {
                left: 12px;
                right: 12px;
                bottom: 80px;
                max-width: none;
            }
        }
        </style>
        
        <div id="ptp-social-proof" class="ptp-social-proof">
            <button class="ptp-social-proof-close" onclick="hideSocialProof()">&times;</button>
            <img class="ptp-social-proof-avatar" id="sp-avatar" src="" alt="">
            <div class="ptp-social-proof-content">
                <p class="ptp-social-proof-text" id="sp-text"></p>
                <span class="ptp-social-proof-time" id="sp-time"></span>
            </div>
        </div>
        
        <script>
        (function() {
            var nonce = '<?php echo $nonce; ?>';
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var notifications = [];
            var currentIndex = 0;
            var container = document.getElementById('ptp-social-proof');
            
            // Don't show if dismissed recently
            if (sessionStorage.getItem('ptp_sp_dismissed')) return;
            
            // Load recent bookings
            fetch(ajaxUrl + '?action=ptp_get_recent_bookings&nonce=' + nonce)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        notifications = data.data;
                        showNextNotification();
                    }
                });
            
            function showNextNotification() {
                if (notifications.length === 0) return;
                
                var n = notifications[currentIndex % notifications.length];
                
                document.getElementById('sp-avatar').src = n.trainer_photo || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(n.trainer_name);
                document.getElementById('sp-text').innerHTML = '<strong>' + n.parent_name + '</strong> booked a session with ' + n.trainer_name;
                document.getElementById('sp-time').textContent = n.time_ago;
                
                container.classList.add('show');
                
                setTimeout(function() {
                    container.classList.remove('show');
                    currentIndex++;
                    
                    // Show next after delay
                    setTimeout(showNextNotification, 30000 + Math.random() * 30000);
                }, 5000);
            }
            
            window.hideSocialProof = function() {
                container.classList.remove('show');
                sessionStorage.setItem('ptp_sp_dismissed', '1');
            };
        })();
        </script>
        <?php
    }
    
    /**
     * Render share modal
     */
    public function render_share_modal() {
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($url, '/trainer/') === false) return;
        
        ?>
        <style>
        .ptp-share-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            background: #F3F4F6;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ptp-share-btn:hover {
            background: #E5E7EB;
        }
        .ptp-share-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 99998;
            align-items: center;
            justify-content: center;
        }
        .ptp-share-overlay.show {
            display: flex;
        }
        .ptp-share-modal {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            max-width: 400px;
            width: 90%;
        }
        .ptp-share-title {
            font-family: 'Oswald', sans-serif;
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 16px;
            text-align: center;
        }
        .ptp-share-options {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .ptp-share-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 12px;
            border-radius: 10px;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
        }
        .ptp-share-option:hover {
            background: #F3F4F6;
        }
        .ptp-share-option svg {
            width: 32px;
            height: 32px;
        }
        .ptp-share-option span {
            font-size: 11px;
            font-weight: 500;
        }
        .ptp-share-link-box {
            display: flex;
            gap: 8px;
        }
        .ptp-share-link-box input {
            flex: 1;
            padding: 12px;
            border: 2px solid #E5E7EB;
            border-radius: 8px;
            font-size: 14px;
        }
        .ptp-share-link-box button {
            padding: 12px 20px;
            background: #FCB900;
            color: #0A0A0A;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
        }
        </style>
        
        <div id="ptp-share-overlay" class="ptp-share-overlay" onclick="if(event.target===this)closeShareModal()">
            <div class="ptp-share-modal">
                <h3 class="ptp-share-title">Share This Trainer</h3>
                <div class="ptp-share-options">
                    <a href="#" class="ptp-share-option" id="share-sms" style="color:#10B981">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                        <span>Text</span>
                    </a>
                    <a href="#" class="ptp-share-option" id="share-facebook" style="color:#1877F2">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>
                        <span>Facebook</span>
                    </a>
                    <a href="#" class="ptp-share-option" id="share-twitter" style="color:#1DA1F2">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z"/></svg>
                        <span>Twitter</span>
                    </a>
                    <a href="#" class="ptp-share-option" id="share-email" style="color:#EA4335">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <span>Email</span>
                    </a>
                </div>
                <div class="ptp-share-link-box">
                    <input type="text" id="share-link" readonly value="">
                    <button onclick="copyShareLink()">Copy</button>
                </div>
            </div>
        </div>
        
        <script>
        (function() {
            var shareUrl = window.location.href;
            var shareTitle = document.title;
            var shareText = 'Check out this awesome trainer on PTP Training!';
            
            window.openShareModal = function() {
                document.getElementById('ptp-share-overlay').classList.add('show');
                document.getElementById('share-link').value = shareUrl;
                
                // Set share links
                document.getElementById('share-sms').href = 'sms:?body=' + encodeURIComponent(shareText + ' ' + shareUrl);
                document.getElementById('share-facebook').href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(shareUrl);
                document.getElementById('share-twitter').href = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(shareUrl);
                document.getElementById('share-email').href = 'mailto:?subject=' + encodeURIComponent(shareTitle) + '&body=' + encodeURIComponent(shareText + '\n\n' + shareUrl);
            };
            
            window.closeShareModal = function() {
                document.getElementById('ptp-share-overlay').classList.remove('show');
            };
            
            window.copyShareLink = function() {
                var input = document.getElementById('share-link');
                input.select();
                document.execCommand('copy');
                input.nextElementSibling.textContent = 'Copied!';
                setTimeout(() => input.nextElementSibling.textContent = 'Copy', 2000);
            };
        })();
        </script>
        <?php
    }
    
    /**
     * AJAX: Get camps for upsell
     */
    public function ajax_get_camp_upsell() {
        // v223.1: Removed dead if(!false) guard
        
        $camps = ptp_get_camps_clinics(3);
        $result = array();
        
        foreach ($camps as $product) {
            $result[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => floatval($product->get_price()),
                'image' => wp_get_attachment_url($product->get_image_id()) ?: '',
                'url' => $product->get_permalink(),
                'date' => ptp_extract_camp_date($product),
                'location' => ptp_extract_camp_location($product),
                'stock' => $product->get_stock_quantity(),
            );
        }
        
        wp_send_json_success(array('camps' => $result));
    }
    
    /**
     * AJAX: Get recent bookings for social proof
     */
    public function ajax_get_recent_bookings() {
        global $wpdb;
        
        $bookings = $wpdb->get_results(
            "SELECT b.created_at, 
                    CONCAT(LEFT(p.display_name, 1), '***') as parent_name,
                    t.display_name as trainer_name,
                    t.photo_url as trainer_photo
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             WHERE b.status IN ('confirmed', 'completed')
             AND b.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY b.created_at DESC
             LIMIT 10"
        );
        
        $result = array();
        foreach ($bookings as $b) {
            $time_ago = human_time_diff(strtotime($b->created_at), current_time('timestamp')) . ' ago';
            $result[] = array(
                'parent_name' => $b->parent_name,
                'trainer_name' => $b->trainer_name,
                'trainer_photo' => $b->trainer_photo,
                'time_ago' => $time_ago,
            );
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Add to waitlist
     */
    public function ajax_add_to_waitlist() {
        global $wpdb;
        
        $product_id = intval($_POST['product_id'] ?? 0);
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        
        if (!$product_id || !is_email($email)) {
            wp_send_json_error('Invalid data');
        }
        
        // Check if already on list
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_waitlist WHERE product_id = %d AND email = %s",
            $product_id, $email
        ));
        
        if ($existing) {
            wp_send_json_success(array('message' => "You're already on the waitlist!"));
        }
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_waitlist',
            array(
                'product_id' => $product_id,
                'email' => $email,
                'phone' => $phone,
                'user_id' => get_current_user_id() ?: null,
            )
        );
        
        wp_send_json_success(array('message' => "You're on the waitlist! We'll notify you when spots open."));
    }
    
    /**
     * Apply bundle discount in PTP Native cart
     * DISABLED v117.2: Only one discount allowed at a time
     */
    public function apply_bundle_discount_to_cart($cart) {
        // v117.2: Bundle discount disabled - only one discount allowed at a time
        return;
        
        if (is_admin() && !defined('DOING_AJAX')) return;
        
        // Check if bundle flag is set in session
        if (!ptp_session()->get('ptp_bundle_discount')) {
            return;
        }
        
        // Check if we have camp products in cart
        $has_camp = false;
        $camp_subtotal = 0;
        
        foreach ($cart->get_cart() as $item) {
            $product = $item['data'];
            $title = strtolower($product->get_name());
            $cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'slugs'));
            
            if (strpos($title, 'camp') !== false || 
                strpos($title, 'clinic') !== false ||
                array_intersect($cats, array('camps', 'clinics', 'camp', 'clinic', 'winter-clinics', 'summer-camps'))) {
                $has_camp = true;
                $camp_subtotal += $item['line_total'];
            }
        }
        
        if ($has_camp) {
            $discount = $camp_subtotal * (self::BUNDLE_DISCOUNT_PERCENT / 100);
            $cart->add_fee('🎁 Bundle Discount (' . self::BUNDLE_DISCOUNT_PERCENT . '% off - complete training booking after checkout)', -$discount);
        }
    }
    
    /**
     * Capture bundle flag from URL
     */
    public function capture_bundle_flag() {
        if (isset($_GET['ptp_bundle']) && $_GET['ptp_bundle'] == '1') {
            if (false && ptp_session()) {
                ptp_session()->set('ptp_bundle_discount', true);
                ptp_session()->set('ptp_training_pending', true);
            }
        }
    }
    
    /**
     * Show training booking CTA on PTP Native thank you page
     */
    public function show_training_booking_cta($order_id) {
        // Check if this was a bundle purchase with pending training
        if (!function_exists('ptp_session') || !ptp_session()) return;
        
        if (!ptp_session()->get('ptp_training_pending')) return;
        
        // Clear the session flag
        ptp_session()->set('ptp_training_pending', false);
        ptp_session()->set('ptp_bundle_discount', false);
        ?>
        <div class="ptp-training-cta-box" style="background:linear-gradient(135deg,#0A0A0A,#1F2937);border-radius:16px;padding:32px;margin:32px 0;text-align:center">
            <div style="font-size:48px;margin-bottom:16px">🎉</div>
            <h3 style="color:#FCB900;font-family:'Oswald',sans-serif;font-size:24px;margin:0 0 12px;text-transform:uppercase">Camp Booked! Now Complete Your Training</h3>
            <p style="color:rgba(255,255,255,0.9);margin:0 0 24px;font-size:16px">You saved <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>% with the bundle discount. Now book your private training session to complete the package!</p>
            <a href="#" id="ptp-complete-training-btn" style="display:inline-block;background:#FCB900;color:#0A0A0A;padding:16px 32px;border-radius:8px;font-weight:700;font-size:16px;text-decoration:none;text-transform:uppercase">
                Book Training Session →
            </a>
            <p style="color:rgba(255,255,255,0.6);margin:16px 0 0;font-size:13px">
                <a href="#" onclick="clearPendingTraining();return false;" style="color:rgba(255,255,255,0.6)">Skip training booking</a>
            </p>
        </div>
        <script>
        (function() {
            var pendingUrl = localStorage.getItem('ptp_pending_training_booking');
            var btn = document.getElementById('ptp-complete-training-btn');
            if (pendingUrl && btn) {
                btn.href = pendingUrl;
                btn.addEventListener('click', function() {
                    localStorage.removeItem('ptp_pending_training_booking');
                    localStorage.removeItem('ptp_bundle_discount');
                });
            } else if (btn) {
                btn.href = '<?php echo home_url('/find-trainers/'); ?>';
            }
            
            window.clearPendingTraining = function() {
                localStorage.removeItem('ptp_pending_training_booking');
                localStorage.removeItem('ptp_bundle_discount');
                document.querySelector('.ptp-training-cta-box').style.display = 'none';
            };
        })();
        </script>
        <?php
    }
    
    /**
     * Render pending training banner (sticky banner when there's a pending booking)
     */
    public function render_pending_training_banner() {
        // Only show on non-checkout pages
        $url = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($url, '/checkout') !== false || 
            strpos($url, '/cart') !== false ||
            strpos($url, '/book-session') !== false) {
            return;
        }
        ?>
        <div id="ptp-pending-training-banner" style="display:none;position:fixed;bottom:0;left:0;right:0;background:#0A0A0A;color:#fff;padding:12px 20px;z-index:9998;box-shadow:0 -4px 20px rgba(0,0,0,0.3)">
            <div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                <div style="display:flex;align-items:center;gap:12px">
                    <span style="font-size:24px">⚡</span>
                    <div>
                        <strong style="color:#FCB900">You have a pending training booking!</strong>
                        <span style="opacity:0.8;margin-left:8px">Complete it to get your bundle discount.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:center">
                    <a href="#" id="pending-training-link" style="background:#FCB900;color:#0A0A0A;padding:10px 20px;border-radius:6px;font-weight:700;text-decoration:none;font-size:14px">Complete Booking</a>
                    <button onclick="dismissPendingBanner()" style="background:transparent;border:none;color:rgba(255,255,255,0.6);cursor:pointer;font-size:20px">&times;</button>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var pendingUrl = localStorage.getItem('ptp_pending_training_booking');
            var banner = document.getElementById('ptp-pending-training-banner');
            var link = document.getElementById('pending-training-link');
            
            if (pendingUrl && banner && link) {
                banner.style.display = 'block';
                link.href = pendingUrl;
                document.body.style.paddingBottom = '70px';
                
                link.addEventListener('click', function() {
                    localStorage.removeItem('ptp_pending_training_booking');
                    localStorage.removeItem('ptp_bundle_discount');
                });
            }
            
            window.dismissPendingBanner = function() {
                localStorage.removeItem('ptp_pending_training_booking');
                localStorage.removeItem('ptp_bundle_discount');
                banner.style.display = 'none';
                document.body.style.paddingBottom = '';
            };
        })();
        </script>
        <?php
    }
    
    /**
     * AJAX: Clear pending training booking
     */
    public function ajax_clear_pending_training() {
        if (false && ptp_session()) {
            ptp_session()->set('ptp_training_pending', false);
            ptp_session()->set('ptp_bundle_discount', false);
        }
        wp_send_json_success();
    }
    
    /**
     * Render share button (for use in templates)
     */
    public static function render_share_button() {
        ?>
        <button class="ptp-share-btn" onclick="openShareModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
            </svg>
            Share
        </button>
        <?php
    }
}

// Initialize
PTP_Growth::instance();
