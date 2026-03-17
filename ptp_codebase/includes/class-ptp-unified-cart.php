<?php
/**
 * PTP Unified Cart v1.0.0
 * 
 * Combines PTP Native cart items AND pending PTP training bookings
 * into a single unified cart preview. Shows bundle discount when
 * both training and camps are present.
 * 
 * Features:
 * - Floating cart icon with combined item count
 * - Slide-out panel showing all items
 * - Bundle discount display (15% off)
 * - Smart checkout routing
 * - Real-time AJAX updates
 * 
 * @since 60.1.0
 */

defined('ABSPATH') || exit;

class PTP_Unified_Cart {
    
    private static $instance = null;
    
    const BUNDLE_DISCOUNT_PERCENT = 15;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Render cart widget in footer
        add_action('wp_footer', array($this, 'render_cart_widget'), 5);
        
        // AJAX endpoints
        add_action('wp_ajax_ptp_get_unified_cart', array($this, 'ajax_get_cart'));
        add_action('wp_ajax_nopriv_ptp_get_unified_cart', array($this, 'ajax_get_cart'));
        // Note: ptp_remove_training_from_cart handled by PTP_Cart_Ajax class
        
        // REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Hook into PTP Native cart updates
        // REMOVED: add_action('ptp-native_add_to_cart', array($this, 'trigger_cart_update'));
        // REMOVED: add_action('ptp-native_cart_item_removed', array($this, 'trigger_cart_update'));
        // REMOVED: add_action('ptp-native_cart_emptied', array($this, 'trigger_cart_update'));
        
        // PTP Native cart fragments - update unified cart count via AJAX
        // REMOVED: add_filter('ptp-native_add_to_cart_fragments', array($this, 'cart_count_fragment'));
        
        // Shortcode for embedding cart icon elsewhere
        add_shortcode('ptp_cart_icon', array($this, 'render_cart_icon_shortcode'));
        
        // Action for themes to render just the cart icon in their header
        add_action('ptp_render_cart_icon', array($this, 'render_header_cart_icon'));
    }
    
    /**
     * PTP Native cart fragment for unified cart count
     */
    public function cart_count_fragment($fragments) {
        $cart = $this->get_cart_data();
        
        // Update FAB count
        $fragments['.ptp-uc-fab-count'] = '<span class="ptp-uc-fab-count" id="ptp-uc-fab-count">' . esc_html($cart['item_count']) . '</span>';
        
        // Also provide data for JS
        $fragments['ptp_unified_cart_data'] = $cart;
        
        return $fragments;
    }
    
    /**
     * Render cart icon for theme headers
     * v60.5.0: Now links directly to cart page instead of opening popup
     */
    public function render_header_cart_icon() {
        $cart = $this->get_cart_data();
        $cart_url = home_url('/ptp-cart/');
        ?>
        <a href="<?php echo esc_url($cart_url); ?>" class="ptp-uc-header-cart" aria-label="View cart">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="9" cy="21" r="1"></circle>
                <circle cx="20" cy="21" r="1"></circle>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
            </svg>
            <?php if ($cart['item_count'] > 0): ?>
                <span class="ptp-uc-header-count"><?php echo esc_html($cart['item_count']); ?></span>
            <?php endif; ?>
        </a>
        <style>
        .ptp-uc-header-cart {
            position: relative;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        .ptp-uc-header-cart svg { color: currentColor; }
        .ptp-uc-header-cart:hover { opacity: 0.8; }
        .ptp-uc-header-count {
            position: absolute;
            top: 0;
            right: 0;
            min-width: 18px;
            height: 18px;
            background: #FCB900;
            color: #0A0A0A;
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 700;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
        }
        </style>
        <?php
    }
    
    /**
     * Register REST routes
     */
    public function register_rest_routes() {
        register_rest_route('ptp/v1', '/unified-cart', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_cart'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * Get unified cart data
     * Now uses PTP_Cart_Helper as single source of truth
     */
    public function get_cart_data() {
        // Use centralized cart helper if available
        if (class_exists('PTP_Cart_Helper')) {
            return PTP_Cart_Helper::get_cart_data();
        }
        
        // Fallback to original implementation
        $data = array(
            'woo_items' => array(),
            'training_items' => array(),
            'cart_subtotal' => 0,
            'training_subtotal' => 0,
            'subtotal' => 0,
            'bundle_discount' => 0,
            'bundle_discount_percent' => self::BUNDLE_DISCOUNT_PERCENT,
            'total' => 0,
            'item_count' => 0,
            'has_bundle' => false,
            'checkout_url' => '',
            'checkout_label' => 'Checkout',
        );
        
        // Get PTP Native cart items
        // v158: Re-enabled native cart loading (was disabled with false &&)
        if (function_exists('ptp_cart') && ptp_cart()) {
            foreach (ptp_cart()->get_items() as $cart_key => $cart_item) {
                $product = $cart_item['data'];
                $product_id = $cart_item['product_id'];
                
                // Check if this is a camp/clinic - improved detection
                $is_camp = $this->is_camp_product($product);
                
                $item = array(
                    'key' => $cart_key,
                    'product_id' => $product_id,
                    'name' => $product->get_name(),
                    'quantity' => $cart_item['quantity'],
                    'price' => floatval($product->get_price()),
                    'subtotal' => floatval($cart_item['line_subtotal']),
                    'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: '',
                    'permalink' => get_permalink($product_id),
                    'is_camp' => $is_camp,
                    'type' => $is_camp ? 'camp' : 'product',
                );
                
                $data['woo_items'][] = $item;
                $data['cart_subtotal'] += $item['subtotal'];
            }
        }
        
        // Get pending training items (from bundle or session)
        $training_items = $this->get_pending_training();
        foreach ($training_items as $item) {
            $data['training_items'][] = $item;
            $data['training_subtotal'] += floatval($item['price']);
        }
        
        // Calculate totals
        $data['subtotal'] = $data['cart_subtotal'] + $data['training_subtotal'];
        $data['item_count'] = count($data['woo_items']) + count($data['training_items']);
        
        // Check for bundle (training + camp)
        $has_camps = false;
        foreach ($data['woo_items'] as $item) {
            if ($item['is_camp']) {
                $has_camps = true;
                break;
            }
        }
        
        $has_training = count($data['training_items']) > 0;
        $data['has_bundle'] = $has_camps && $has_training;
        
        // Apply bundle discount if applicable
        if ($data['has_bundle']) {
            $data['bundle_discount'] = round($data['subtotal'] * (self::BUNDLE_DISCOUNT_PERCENT / 100), 2);
            $data['total'] = $data['subtotal'] - $data['bundle_discount'];
        } else {
            $data['total'] = $data['subtotal'];
        }
        
        // Determine checkout URL and label
        if ($data['has_bundle']) {
            // Check if we have an active bundle code
            $bundle_code = $this->get_active_bundle_code();
            if ($bundle_code) {
                $data['checkout_url'] = home_url('/bundle-checkout/?bundle=' . $bundle_code);
            } else {
                // Need to create bundle first
                $data['checkout_url'] = '#create-bundle';
            }
            $data['checkout_label'] = 'Bundle Checkout - Save ' . self::BUNDLE_DISCOUNT_PERCENT . '%';
        } elseif ($has_training && !$has_camps) {
            // Training only
            $data['checkout_url'] = home_url('/training-checkout/');
            $data['checkout_label'] = 'Complete Training Booking';
        } elseif (!$has_training && count($data['woo_items']) > 0) {
            // PTP Native only
            $data['checkout_url'] = home_url('/ptp-checkout/');
            $data['checkout_label'] = 'Checkout';
        } else {
            $data['checkout_url'] = home_url('/find-trainers/');
            $data['checkout_label'] = 'Browse Trainers';
        }
        
        return $data;
    }
    
    /**
     * Get pending training from session/bundle
     */
    private function get_pending_training() {
        $items = array();
        
        // Check for active bundle
        if (class_exists('PTP_Bundle_Checkout')) {
            $bundle_checkout = PTP_Bundle_Checkout::instance();
            $bundle_code = $bundle_checkout->get_active_bundle_code();
            
            if ($bundle_code) {
                $bundle = $bundle_checkout->get_bundle_by_code($bundle_code);
                
                if ($bundle && $bundle->trainer_id && $bundle->status !== 'completed') {
                    $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get($bundle->trainer_id) : null;
                    
                    $package_names = array(
                        'single' => 'Single Session',
                        '5pack' => '5-Session Pack',
                        '10pack' => '10-Session Pack',
                    );
                    
                    $items[] = array(
                        'type' => 'training',
                        'bundle_code' => $bundle_code,
                        'trainer_id' => $bundle->trainer_id,
                        'trainer_name' => $trainer ? $trainer->display_name : 'Trainer',
                        'trainer_image' => $trainer ? ($trainer->photo_url ?: '') : '',
                        'package' => $bundle->training_package,
                        'package_name' => $package_names[$bundle->training_package] ?? 'Training Session',
                        'sessions' => intval($bundle->training_sessions),
                        'date' => $bundle->training_date,
                        'time' => $bundle->training_time,
                        'location' => $bundle->training_location,
                        'price' => floatval($bundle->training_amount),
                        'removable' => true,
                    );
                }
            }
        }
        
        // Also check session for pending training not yet in bundle
        if (isset($_SESSION['ptp_pending_training'])) {
            $pending = $_SESSION['ptp_pending_training'];
            
            // Only add if not already in bundle
            $already_in_bundle = false;
            foreach ($items as $item) {
                if ($item['trainer_id'] == ($pending['trainer_id'] ?? 0)) {
                    $already_in_bundle = true;
                    break;
                }
            }
            
            if (!$already_in_bundle && !empty($pending['trainer_id'])) {
                $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get($pending['trainer_id']) : null;
                
                $items[] = array(
                    'type' => 'training',
                    'bundle_code' => null,
                    'trainer_id' => $pending['trainer_id'],
                    'trainer_name' => $trainer ? $trainer->display_name : 'Trainer',
                    'trainer_image' => $trainer ? ($trainer->photo_url ?: '') : '',
                    'package' => $pending['package'] ?? 'single',
                    'package_name' => $pending['package_name'] ?? 'Training Session',
                    'sessions' => intval($pending['sessions'] ?? 1),
                    'date' => $pending['date'] ?? '',
                    'time' => $pending['time'] ?? '',
                    'location' => $pending['location'] ?? '',
                    'price' => floatval($pending['price'] ?? 0),
                    'removable' => true,
                );
            }
        }
        
        return $items;
    }
    
    /**
     * Check if product is a camp/clinic
     * Uses PTP_Cart_Helper if available, otherwise falls back to local check
     */
    private function is_camp_product($product) {
        if (class_exists('PTP_Cart_Helper')) {
            return PTP_Cart_Helper::is_camp_product($product);
        }
        
        // Fallback logic
        if (!$product || !is_object($product)) {
            return false;
        }
        
        $product_id = $product->get_id();
        $product_type = get_post_meta($product_id, '_ptp_product_type', true);
        
        if (in_array($product_type, array('camp', 'clinic'))) {
            return true;
        }
        
        $title = strtolower($product->get_name());
        if (strpos($title, 'camp') !== false || strpos($title, 'clinic') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get active bundle code
     */
    private function get_active_bundle_code() {
        if (class_exists('PTP_Bundle_Checkout')) {
            return PTP_Bundle_Checkout::instance()->get_active_bundle_code();
        }
        return null;
    }
    
    /**
     * AJAX: Get unified cart
     */
    public function ajax_get_cart() {
        wp_send_json_success($this->get_cart_data());
    }
    
    /**
     * REST: Get unified cart
     */
    public function rest_get_cart($request) {
        return rest_ensure_response($this->get_cart_data());
    }
    
    /**
     * AJAX: Remove training from cart
     * Updated to use standardized nonce verification
     */
    public function ajax_remove_training() {
        // Use cart helper for nonce verification if available
        if (class_exists('PTP_Cart_Helper') && !PTP_Cart_Helper::verify_cart_nonce()) {
            PTP_Cart_Helper::send_nonce_error();
            return;
        }
        
        $bundle_code = sanitize_text_field($_POST['bundle_code'] ?? '');
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        
        // Clear from session
        if (isset($_SESSION['ptp_pending_training'])) {
            unset($_SESSION['ptp_pending_training']);
        }
        
        // Clear bundle using cart helper if available
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::clear_bundle($bundle_code);
        } elseif ($bundle_code && class_exists('PTP_Bundle_Checkout')) {
            PTP_Bundle_Checkout::instance()->clear_bundle();
        }
        
        wp_send_json_success(array(
            'message' => 'Training removed',
            'cart' => $this->get_cart_data(),
        ));
    }
    
    /**
     * Trigger cart update (for PTP Native hooks)
     */
    public function trigger_cart_update() {
        // Invalidate cache if helper available
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        // This is mainly for triggering frontend refresh via hooks
        do_action('ptp_unified_cart_updated', $this->get_cart_data());
    }
    
    /**
     * Render cart icon shortcode
     * v60.5.0: Now links directly to cart page instead of opening popup
     */
    public function render_cart_icon_shortcode($atts) {
        $atts = shortcode_atts(array(
            'style' => 'icon', // icon, text, both
        ), $atts);
        
        $cart = $this->get_cart_data();
        $count = $cart['item_count'];
        $cart_url = home_url('/ptp-cart/');
        
        ob_start();
        ?>
        <a href="<?php echo esc_url($cart_url); ?>" class="ptp-uc-trigger" aria-label="View cart">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="9" cy="21" r="1"></circle>
                <circle cx="20" cy="21" r="1"></circle>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
            </svg>
            <?php if ($count > 0): ?>
                <span class="ptp-uc-count"><?php echo esc_html($count); ?></span>
            <?php endif; ?>
            <?php if ($atts['style'] !== 'icon'): ?>
                <span class="ptp-uc-text">Cart</span>
            <?php endif; ?>
        </a>
        <style>
        .ptp-uc-trigger {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            text-decoration: none;
            color: inherit;
        }
        .ptp-uc-trigger:hover { opacity: 0.8; }
        .ptp-uc-count {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 18px;
            height: 18px;
            background: #FCB900;
            color: #0A0A0A;
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 700;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
        }
        .ptp-uc-text {
            font-weight: 500;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the unified cart widget
     * 
     * v60.5.0: Cart popup disabled - users go directly to /ptp-cart/ page instead
     * Keeping this method for backwards compatibility but it no longer renders the popup
     */
    public function render_cart_widget() {
        // Cart popup completely disabled in v60.5.0
        // Users now go directly to /ptp-cart/ page for cart management
        // The header cart icon (via ptp_render_cart_icon action) links to the cart page
        return;
        ?>
        
        <!-- PTP Unified Cart Widget -->
        <div id="ptp-unified-cart" class="ptp-uc<?php echo $show_fab ? '' : ' ptp-uc-no-fab'; ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            
            <?php if ($show_fab): ?>
            <!-- Floating Cart Button -->
            <button class="ptp-uc-fab" id="ptp-uc-fab" aria-label="Open cart">
                <svg class="ptp-uc-fab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="9" cy="21" r="1"></circle>
                    <circle cx="20" cy="21" r="1"></circle>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                </svg>
                <span class="ptp-uc-fab-count" id="ptp-uc-fab-count"><?php echo esc_html($cart['item_count']); ?></span>
            </button>
            <?php endif; ?>
            
            <!-- Overlay -->
            <div class="ptp-uc-overlay" id="ptp-uc-overlay"></div>
            
            <!-- Slide-out Panel -->
            <div class="ptp-uc-panel" id="ptp-uc-panel">
                
                <!-- Panel Header -->
                <div class="ptp-uc-header">
                    <h3 class="ptp-uc-title">YOUR CART</h3>
                    <button class="ptp-uc-close" id="ptp-uc-close" aria-label="Close cart">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
                
                <!-- Bundle Savings Banner (shows when bundle active) -->
                <div class="ptp-uc-bundle-banner" id="ptp-uc-bundle-banner" style="display: none;">
                    <div class="ptp-uc-bundle-icon">🎁</div>
                    <div class="ptp-uc-bundle-text">
                        <strong>BUNDLE & SAVE <?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>%</strong>
                        <span>Training + Camp discount applied!</span>
                    </div>
                </div>
                
                <!-- Cart Items -->
                <div class="ptp-uc-items" id="ptp-uc-items">
                    <!-- Items rendered via JS -->
                    <div class="ptp-uc-empty" id="ptp-uc-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <circle cx="9" cy="21" r="1"></circle>
                            <circle cx="20" cy="21" r="1"></circle>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                        </svg>
                        <p>Your cart is empty</p>
                        <a href="<?php echo esc_url(home_url('/find-trainers/')); ?>" class="ptp-uc-browse">Find Trainers</a>
                    </div>
                </div>
                
                <!-- Upsell Banner (shows when training but no camp) -->
                <div class="ptp-uc-upsell" id="ptp-uc-upsell" style="display: none;">
                    <div class="ptp-uc-upsell-card">
                        <div class="ptp-uc-upsell-badge">
                            <span>🎁</span>
                            <span>BUNDLE DEAL</span>
                        </div>
                        <div class="ptp-uc-upsell-body">
                            <h4>Add a Camp</h4>
                            <p>Get <strong><?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>% off</strong> your entire order</p>
                            <a href="<?php echo esc_url(home_url('/ptp-find-a-camp/?bundle=1')); ?>" class="ptp-uc-upsell-cta">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                View Camps
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Upsell Banner (shows when camp but no training) -->
                <div class="ptp-uc-upsell-training" id="ptp-uc-upsell-training" style="display: none;">
                    <div class="ptp-uc-upsell-card">
                        <div class="ptp-uc-upsell-badge">
                            <span>🎁</span>
                            <span>BUNDLE DEAL</span>
                        </div>
                        <div class="ptp-uc-upsell-body">
                            <h4>Add Private Training</h4>
                            <p>Get <strong><?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>% off</strong> your entire order with 1-on-1 coaching from D1 college athletes</p>
                            <a href="<?php echo esc_url(home_url('/find-trainers/?bundle=1')); ?>" class="ptp-uc-upsell-cta">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
                                </svg>
                                Find Your Trainer
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Cart Totals -->
                <div class="ptp-uc-totals" id="ptp-uc-totals">
                    <div class="ptp-uc-total-row ptp-uc-subtotal">
                        <span>Subtotal</span>
                        <span id="ptp-uc-subtotal">$0.00</span>
                    </div>
                    <div class="ptp-uc-total-row ptp-uc-discount" id="ptp-uc-discount-row" style="display: none;">
                        <span>Bundle Discount (<?php echo self::BUNDLE_DISCOUNT_PERCENT; ?>%)</span>
                        <span id="ptp-uc-discount">-$0.00</span>
                    </div>
                    <div class="ptp-uc-total-row ptp-uc-total">
                        <span>Total</span>
                        <span id="ptp-uc-total">$0.00</span>
                    </div>
                </div>
                
                <!-- Checkout Button -->
                <div class="ptp-uc-checkout">
                    <a href="#" class="ptp-uc-checkout-btn" id="ptp-uc-checkout-btn">
                        <span id="ptp-uc-checkout-label">Checkout</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </a>
                    <p class="ptp-uc-secure">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        Secure checkout powered by Stripe
                    </p>
                </div>
                
            </div>
        </div>
        
        <style>
/* PTP Unified Cart - Styles */
.ptp-uc { --ptp-gold: #FCB900; --ptp-black: #0A0A0A; --ptp-green: #059669; }

/* Floating Action Button */
.ptp-uc-fab {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 60px;
    height: 60px;
    background: var(--ptp-black);
    border: 2px solid var(--ptp-gold);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 9998;
    transition: all 0.2s ease;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.ptp-uc-fab:hover {
    transform: scale(1.05);
    background: #1a1a1a;
}
.ptp-uc-fab-icon {
    width: 26px;
    height: 26px;
    color: #fff;
}
.ptp-uc-fab-count {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 22px;
    height: 22px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    font-family: 'Oswald', sans-serif;
    font-size: 13px;
    font-weight: 700;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
}
.ptp-uc-fab-count:empty,
.ptp-uc-fab-count[data-count="0"] { display: none; }

/* Overlay */
.ptp-uc-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}
.ptp-uc.is-open .ptp-uc-overlay {
    opacity: 1;
    visibility: visible;
}

/* Slide-out Panel */
.ptp-uc-panel {
    position: fixed;
    top: 0;
    right: 0;
    width: 100%;
    max-width: 420px;
    height: 100%;
    background: #fff;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.3s ease;
    box-shadow: -4px 0 30px rgba(0,0,0,0.2);
}
.ptp-uc.is-open .ptp-uc-panel {
    transform: translateX(0);
}

/* Header */
.ptp-uc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    background: var(--ptp-black);
    color: #fff;
    flex-shrink: 0;
}
.ptp-uc-title {
    font-family: 'Oswald', sans-serif;
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    letter-spacing: 0.5px;
}
.ptp-uc-close {
    width: 36px;
    height: 36px;
    background: transparent;
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.ptp-uc-close:hover {
    border-color: var(--ptp-gold);
    background: rgba(252,185,0,0.1);
}
.ptp-uc-close svg {
    width: 18px;
    height: 18px;
    color: #fff;
}

/* Bundle Banner */
.ptp-uc-bundle-banner {
    background: linear-gradient(135deg, var(--ptp-green) 0%, #047857 100%);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    color: #fff;
}
.ptp-uc-bundle-icon { font-size: 24px; }
.ptp-uc-bundle-text strong {
    display: block;
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
}
.ptp-uc-bundle-text span {
    font-size: 13px;
    opacity: 0.9;
}

/* Items Container */
.ptp-uc-items {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
}

/* Empty State */
.ptp-uc-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    text-align: center;
    color: #6B7280;
}
.ptp-uc-empty svg {
    width: 64px;
    height: 64px;
    margin-bottom: 16px;
    opacity: 0.3;
}
.ptp-uc-empty p {
    margin: 0 0 20px;
    font-size: 16px;
}
.ptp-uc-browse {
    display: inline-block;
    background: var(--ptp-black);
    color: #fff;
    padding: 12px 28px;
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    text-transform: uppercase;
    text-decoration: none;
    border: 2px solid var(--ptp-black);
    transition: all 0.2s;
}
.ptp-uc-browse:hover {
    background: #fff;
    color: var(--ptp-black);
}

/* Cart Item */
.ptp-uc-item {
    display: flex;
    gap: 14px;
    padding: 16px;
    background: #F9FAFB;
    border: 2px solid #E5E7EB;
    margin-bottom: 12px;
}
.ptp-uc-item-image {
    width: 72px;
    height: 72px;
    background: #E5E7EB;
    border: 2px solid #D1D5DB;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.ptp-uc-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.ptp-uc-item-image svg {
    width: 32px;
    height: 32px;
    color: #9CA3AF;
}
.ptp-uc-item-info {
    flex: 1;
    min-width: 0;
}
.ptp-uc-item-type {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 3px 8px;
    margin-bottom: 4px;
}
.ptp-uc-item-type.camp {
    background: #DBEAFE;
    color: #1D4ED8;
}
.ptp-uc-item-type.training {
    background: #FEF3C7;
    color: #B45309;
}
.ptp-uc-item-type.product {
    background: #E5E7EB;
    color: #374151;
}
.ptp-uc-item-name {
    font-family: 'Oswald', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--ptp-black);
    margin: 0 0 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ptp-uc-item-meta {
    font-size: 12px;
    color: #6B7280;
    margin: 0;
}
.ptp-uc-item-price {
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 600;
    color: var(--ptp-black);
    margin-top: 6px;
}
.ptp-uc-item-remove {
    width: 28px;
    height: 28px;
    background: transparent;
    border: 1px solid #E5E7EB;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    align-self: flex-start;
    transition: all 0.2s;
}
.ptp-uc-item-remove:hover {
    background: #FEE2E2;
    border-color: #EF4444;
}
.ptp-uc-item-remove svg {
    width: 14px;
    height: 14px;
    color: #6B7280;
}
.ptp-uc-item-remove:hover svg {
    color: #EF4444;
}

/* Upsell Cards - Professional Design */
.ptp-uc-upsell,
.ptp-uc-upsell-training {
    padding: 0 16px 16px !important;
}
.ptp-uc-upsell-card {
    background: linear-gradient(135deg, #0A0A0A 0%, #1a1a1a 100%) !important;
    border: 2px solid var(--ptp-gold) !important;
    overflow: hidden !important;
    border-radius: 0 !important;
}
.ptp-uc-upsell-badge {
    background: var(--ptp-gold) !important;
    padding: 8px 14px !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    font-family: 'Oswald', sans-serif !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    color: var(--ptp-black) !important;
    letter-spacing: 1px !important;
}
.ptp-uc-upsell-body {
    padding: 16px !important;
    color: #fff !important;
}
.ptp-uc-upsell-body h4 {
    font-family: 'Oswald', sans-serif !important;
    font-size: 16px !important;
    font-weight: 600 !important;
    margin: 0 0 6px !important;
    text-transform: uppercase !important;
    color: #fff !important;
}
.ptp-uc-upsell-body p {
    font-size: 13px !important;
    color: rgba(255,255,255,0.8) !important;
    margin: 0 0 14px !important;
    line-height: 1.4 !important;
}
.ptp-uc-upsell-body p strong {
    color: var(--ptp-gold) !important;
}
.ptp-uc-upsell-cta {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    width: 100% !important;
    padding: 12px 16px !important;
    background: var(--ptp-gold) !important;
    color: var(--ptp-black) !important;
    font-family: 'Oswald', sans-serif !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    text-decoration: none !important;
    transition: all 0.2s !important;
    border-radius: 0 !important;
}
.ptp-uc-upsell-cta:hover {
    background: #e5a800 !important;
    color: var(--ptp-black) !important;
}
.ptp-uc-upsell-cta svg {
    flex-shrink: 0 !important;
}

/* Totals */
.ptp-uc-totals {
    padding: 20px 24px;
    background: #F9FAFB;
    border-top: 2px solid #E5E7EB;
    flex-shrink: 0;
}
.ptp-uc-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
    color: #374151;
    margin-bottom: 8px;
}
.ptp-uc-total-row:last-child { margin-bottom: 0; }
.ptp-uc-discount span:last-child {
    color: var(--ptp-green);
    font-weight: 600;
}
.ptp-uc-total {
    font-family: 'Oswald', sans-serif;
    font-size: 20px !important;
    font-weight: 700;
    color: var(--ptp-black) !important;
    padding-top: 12px;
    margin-top: 12px;
    border-top: 2px solid #E5E7EB;
}

/* Checkout */
.ptp-uc-checkout {
    padding: 20px 24px 24px;
    background: #fff;
    border-top: 1px solid #E5E7EB;
    flex-shrink: 0;
}
.ptp-uc-checkout-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    padding: 16px 24px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 700;
    text-transform: uppercase;
    text-decoration: none;
    border: 2px solid var(--ptp-gold);
    transition: all 0.2s;
}
.ptp-uc-checkout-btn:hover {
    background: #E5A800;
    border-color: #E5A800;
}
.ptp-uc-checkout-btn svg {
    width: 20px;
    height: 20px;
}
.ptp-uc-checkout-btn.disabled {
    background: #E5E7EB;
    border-color: #E5E7EB;
    color: #9CA3AF;
    pointer-events: none;
}
.ptp-uc-secure {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 12px 0 0;
    font-size: 12px;
    color: #6B7280;
}
.ptp-uc-secure svg { color: var(--ptp-green); }

/* Mobile */
@media (max-width: 480px) {
    .ptp-uc-panel { max-width: 100%; }
    .ptp-uc-fab {
        bottom: 16px;
        right: 16px;
        width: 56px;
        height: 56px;
    }
}

/* Hide when empty and closed */
.ptp-uc[data-count="0"]:not(.is-open) .ptp-uc-fab-count { display: none; }
</style>

<script>
(function() {
    'use strict';
    
    const UC = {
        panel: null,
        overlay: null,
        fab: null,
        isOpen: false,
        cart: <?php echo json_encode($cart); ?>,
        nonce: '<?php echo esc_js($nonce); ?>',
        
        init() {
            this.panel = document.getElementById('ptp-uc-panel');
            this.overlay = document.getElementById('ptp-uc-overlay');
            this.fab = document.getElementById('ptp-uc-fab');
            
            if (!this.panel) return; // Panel is required, FAB is optional
            
            // Event listeners
            if (this.fab) {
                this.fab.addEventListener('click', () => this.open());
            }
            this.overlay.addEventListener('click', () => this.close());
            document.getElementById('ptp-uc-close').addEventListener('click', () => this.close());
            
            // Also listen for any element with data-ptp-cart-trigger attribute
            document.querySelectorAll('[data-ptp-cart-trigger]').forEach(el => {
                el.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.open();
                });
            });
            
            // Keyboard
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isOpen) this.close();
            });
            
            // Initial render
            this.render();
            
            // Listen for cart updates from PTP Native
            document.body.addEventListener('added_to_cart', () => this.refresh());
            document.body.addEventListener('removed_from_cart', () => this.refresh());
            document.body.addEventListener('ptp_cart_emptied', () => this.refresh());
            
            // Listen for PTP events
            document.body.addEventListener('ptp_training_added', () => this.refresh());
            document.body.addEventListener('ptp_bundle_created', () => this.refresh());
            
            // Auto-open if just added something
            if (window.location.search.includes('cart_opened=1')) {
                setTimeout(() => this.open(), 300);
            }
        },
        
        open() {
            this.isOpen = true;
            document.getElementById('ptp-unified-cart').classList.add('is-open');
            document.body.style.overflow = 'hidden';
            this.refresh(); // Always refresh when opening
        },
        
        close() {
            this.isOpen = false;
            document.getElementById('ptp-unified-cart').classList.remove('is-open');
            document.body.style.overflow = '';
        },
        
        async refresh() {
            try {
                const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=ptp_get_unified_cart&nonce=' + this.nonce
                });
                const result = await response.json();
                if (result.success) {
                    this.cart = result.data;
                    this.render();
                }
            } catch (e) {
                console.error('Cart refresh failed:', e);
            }
        },
        
        render() {
            const cart = this.cart;
            
            // Update FAB count (if FAB exists)
            const fabCount = document.getElementById('ptp-uc-fab-count');
            if (fabCount) {
                fabCount.textContent = cart.item_count;
                fabCount.style.display = cart.item_count > 0 ? 'flex' : 'none';
            }
            
            // Update any header cart counts (for theme integration)
            document.querySelectorAll('.ptp-uc-header-count, [data-ptp-cart-count]').forEach(el => {
                el.textContent = cart.item_count;
                el.style.display = cart.item_count > 0 ? '' : 'none';
            });
            
            document.getElementById('ptp-unified-cart').setAttribute('data-count', cart.item_count);
            
            // Bundle banner
            document.getElementById('ptp-uc-bundle-banner').style.display = cart.has_bundle ? 'flex' : 'none';
            
            // Render items
            const itemsContainer = document.getElementById('ptp-uc-items');
            const emptyState = document.getElementById('ptp-uc-empty');
            
            if (cart.item_count === 0) {
                emptyState.style.display = 'flex';
                // Clear any existing items
                const existingItems = itemsContainer.querySelectorAll('.ptp-uc-item');
                existingItems.forEach(item => item.remove());
            } else {
                emptyState.style.display = 'none';
                
                // Build items HTML
                let itemsHtml = '';
                
                // Training items first
                cart.training_items.forEach(item => {
                    itemsHtml += this.renderTrainingItem(item);
                });
                
                // PTP Native items
                cart.woo_items.forEach(item => {
                    itemsHtml += this.renderWooItem(item);
                });
                
                // Clear and append
                const existingItems = itemsContainer.querySelectorAll('.ptp-uc-item');
                existingItems.forEach(item => item.remove());
                emptyState.insertAdjacentHTML('beforebegin', itemsHtml);
                
                // Attach remove handlers
                this.attachRemoveHandlers();
            }
            
            // Upsell banners
            const hasCamps = cart.woo_items.some(item => item.is_camp);
            const hasTraining = cart.training_items.length > 0;
            
            document.getElementById('ptp-uc-upsell').style.display = (hasTraining && !hasCamps) ? 'block' : 'none';
            document.getElementById('ptp-uc-upsell-training').style.display = (!hasTraining && hasCamps) ? 'block' : 'none';
            
            // Totals
            document.getElementById('ptp-uc-subtotal').textContent = this.formatMoney(cart.subtotal);
            document.getElementById('ptp-uc-discount-row').style.display = cart.has_bundle ? 'flex' : 'none';
            document.getElementById('ptp-uc-discount').textContent = '-' + this.formatMoney(cart.bundle_discount);
            document.getElementById('ptp-uc-total').textContent = this.formatMoney(cart.total);
            
            // Show/hide totals section
            document.getElementById('ptp-uc-totals').style.display = cart.item_count > 0 ? 'block' : 'none';
            
            // Checkout button
            const checkoutBtn = document.getElementById('ptp-uc-checkout-btn');
            const checkoutLabel = document.getElementById('ptp-uc-checkout-label');
            
            if (cart.item_count > 0) {
                checkoutBtn.href = cart.checkout_url;
                checkoutBtn.classList.remove('disabled');
                checkoutLabel.textContent = cart.checkout_label;
            } else {
                checkoutBtn.href = '#';
                checkoutBtn.classList.add('disabled');
                checkoutLabel.textContent = 'Cart is empty';
            }
        },
        
        renderTrainingItem(item) {
            const dateStr = item.date ? new Date(item.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '';
            const timeStr = item.time ? this.formatTime(item.time) : '';
            const meta = [item.package_name, item.sessions > 1 ? item.sessions + ' sessions' : '', dateStr, timeStr].filter(Boolean).join(' • ');
            
            return `
                <div class="ptp-uc-item" data-type="training" data-bundle-code="${item.bundle_code || ''}" data-trainer-id="${item.trainer_id}">
                    <div class="ptp-uc-item-image">
                        ${item.trainer_image 
                            ? `<img src="${item.trainer_image}" alt="${item.trainer_name}">` 
                            : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a7.5 7.5 0 0 1 13 0"/></svg>`
                        }
                    </div>
                    <div class="ptp-uc-item-info">
                        <span class="ptp-uc-item-type training">Training</span>
                        <h4 class="ptp-uc-item-name">${item.trainer_name}</h4>
                        <p class="ptp-uc-item-meta">${meta}</p>
                        <div class="ptp-uc-item-price">${this.formatMoney(item.price)}</div>
                    </div>
                    ${item.removable ? `
                        <button class="ptp-uc-item-remove" data-remove-training aria-label="Remove">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    ` : ''}
                </div>
            `;
        },
        
        renderWooItem(item) {
            const typeLabel = item.is_camp ? 'Camp' : 'Product';
            const typeClass = item.is_camp ? 'camp' : 'product';
            const meta = item.quantity > 1 ? `Qty: ${item.quantity}` : '';
            
            return `
                <div class="ptp-uc-item" data-type="woo" data-cart-key="${item.key}">
                    <div class="ptp-uc-item-image">
                        ${item.image 
                            ? `<img src="${item.image}" alt="${item.name}">` 
                            : `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>`
                        }
                    </div>
                    <div class="ptp-uc-item-info">
                        <span class="ptp-uc-item-type ${typeClass}">${typeLabel}</span>
                        <h4 class="ptp-uc-item-name">${item.name}</h4>
                        ${meta ? `<p class="ptp-uc-item-meta">${meta}</p>` : ''}
                        <div class="ptp-uc-item-price">${this.formatMoney(item.subtotal)}</div>
                    </div>
                    <button class="ptp-uc-item-remove" data-remove-woo="${item.key}" aria-label="Remove">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
            `;
        },
        
        attachRemoveHandlers() {
            // Training remove
            document.querySelectorAll('[data-remove-training]').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const item = btn.closest('.ptp-uc-item');
                    const bundleCode = item.dataset.bundleCode;
                    const trainerId = item.dataset.trainerId;
                    
                    btn.disabled = true;
                    item.style.opacity = '0.5';
                    
                    try {
                        await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=ptp_remove_training_from_cart&nonce=${this.nonce}&bundle_code=${bundleCode}&trainer_id=${trainerId}`
                        });
                        this.refresh();
                    } catch (e) {
                        console.error('Remove failed:', e);
                        btn.disabled = false;
                        item.style.opacity = '1';
                    }
                });
            });
            
            // PTP Native remove
            document.querySelectorAll('[data-remove-woo]').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const cartKey = btn.dataset.removeWoo;
                    const item = btn.closest('.ptp-uc-item');
                    
                    btn.disabled = true;
                    item.style.opacity = '0.5';
                    
                    try {
                        // Use PTP Native AJAX remove
                        const formData = new FormData();
                        formData.append('action', 'remove_from_cart');
                        formData.append('cart_item_key', cartKey);
                        
                        await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            body: formData
                        });
                        
                        // Trigger WC event
                        document.body.dispatchEvent(new CustomEvent('removed_from_cart'));
                        this.refresh();
                    } catch (e) {
                        console.error('Remove failed:', e);
                        btn.disabled = false;
                        item.style.opacity = '1';
                    }
                });
            });
        },
        
        formatMoney(amount) {
            return '$' + parseFloat(amount || 0).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        },
        
        formatTime(time) {
            if (!time) return '';
            const [h, m] = time.split(':');
            const hour = parseInt(h);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return `${hour12}:${m} ${ampm}`;
        }
    };
    
    // Initialize when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => UC.init());
    } else {
        UC.init();
    }
    
    // Expose for external use
    window.PTPUnifiedCart = UC;
})();
</script>
        <?php
    }
}

// Initialize
function ptp_unified_cart() {
    return PTP_Unified_Cart::instance();
}

// Helper function to get cart data
function ptp_get_unified_cart_data() {
    return PTP_Unified_Cart::instance()->get_cart_data();
}

// Helper to check if user has bundle discount
function ptp_has_bundle_in_cart() {
    $cart = ptp_get_unified_cart_data();
    return $cart['has_bundle'];
}
