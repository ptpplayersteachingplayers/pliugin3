<?php
/**
 * PTP Camp Checkout v99 - Mobile-First Smart Checkout
 * 
 * v99 Changes (Integration with ptp-camp-product-v10.3.5):
 * - Detects when multiple camps selected from product page
 * - Hides upgrade bumps when multiweek already selected
 * - Shows confirmation message for multi-camp selections
 * - Prevents double-discount conflicts
 * 
 * Integration Flow:
 * 1. User selects 2+ camps on product page → ptp99_camps_in_cart session set
 * 2. Checkout v99 detects this and hides upgrade bumps
 * 3. Discount is applied by camp template's apply_multiweek_discount()
 * 
 * Features:
 * - Mobile-first responsive design
 * - Smart progressive disclosure
 * - Touch-optimized (48px min targets)
 * - Debounced AJAX for performance
 * - Integrated upsells in order summary
 * - Before/After Care: $60 bundled
 * 
 * @since 99.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Checkout_V99 {
    
    private static $instance = null;
    
    // Pricing
    const SIBLING_DISCOUNT_PCT = 10; // 10% off siblings
    const REFERRAL_DISCOUNT = 25;
    const CARE_BUNDLE = 60;  // Before + After Care bundled
    const JERSEY_PRICE = 50;
    const JERSEY_ORIGINAL = 75;
    const PROCESSING_RATE = 0.03;
    const PROCESSING_FLAT = 0.30;
    
    const TEAM_DISCOUNTS = [5 => 10, 10 => 15, 15 => 20];
    
    // Camp Pack pricing (price anchoring)
    const CAMP_PACKS = [
        2 => ['discount' => 10, 'label' => '2-CAMP PACK'],
        3 => ['discount' => 20, 'label' => '3-CAMP PACK'],
    ];
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // v149: PTP Native hooks only if WC active
        if (false) {
            // Payment restrictions
            // REMOVED: add_filter('ptp-native_available_payment_gateways', [$this, 'card_only_gateways'], 9999);
            // REMOVED: add_filter('wc_stripe_show_payment_request_on_checkout', '__return_false', 9999);
            // REMOVED: add_filter('wc_stripe_show_express_checkout_buttons', '__return_false', 9999);
            // REMOVED: add_filter('wc_stripe_upe_available_payment_methods', fn() => ['card'], 9999);

            // Checkout hooks
            // REMOVED: add_action('ptp-native_before_order_notes', [$this, 'render_discounts_section'], 20);
            // REMOVED: add_action('ptp-native_review_order_before_order_total', [$this, 'render_sidebar_upsells'], 15);
            // REMOVED: add_action('ptp-native_cart_calculate_fees', [$this, 'calculate_fees'], 30);

            // Order processing
            // REMOVED: add_action('ptp-native_checkout_create_order', [$this, 'save_order_meta'], 25, 2);
            // REMOVED: add_action('ptp-native_thankyou', [$this, 'handle_referral'], 10);
            // REMOVED: add_action('ptp-native_checkout_order_created', [$this, 'on_order_created'], 10);
            // REMOVED: add_action('ptp-native_payment_complete', [$this, 'on_order_created'], 10);
        } else {
            // Native PTP hooks
            add_action('ptp_before_order_notes', [$this, 'render_discounts_section'], 20);
            add_action('ptp_review_order_before_total', [$this, 'render_sidebar_upsells'], 15);
            add_action('ptp_checkout_create_order', [$this, 'save_order_meta'], 25, 2);
            add_action('ptp_thankyou', [$this, 'handle_referral'], 10);
            add_action('ptp_order_created', [$this, 'on_order_created'], 10);
        }

        // AJAX (works with both)
        add_action('wp_ajax_ptp99_update', [$this, 'ajax_update']);
        add_action('wp_ajax_nopriv_ptp99_update', [$this, 'ajax_update']);

        // Assets
        add_action('wp_head', [$this, 'output_styles'], 99);
        add_action('wp_footer', [$this, 'output_scripts'], 99);

        // DB
        add_action('init', [$this, 'init_tables']);
    }
    
    // =========================================
    // HELPERS
    // =========================================

    private function is_camp_checkout() {
        // v149: Support both WC and native cart
        $cart_items = array();

        if (false && false && ptp_cart()) {
            $cart_items = ptp_cart()->get_items();
        } elseif (function_exists('ptp_cart')) {
            $cart_items = ptp_cart()->get_cart();
        }

        if (empty($cart_items)) return false;

        foreach ($cart_items as $item) {
            $pid = $item['product_id'] ?? ($item['item_id'] ?? 0);

            // Check for camp item type in native cart
            if (isset($item['item_type']) && $item['item_type'] === 'camp') {
                return true;
            }

            // Check 1: PTP Native product categories
            if (has_term(['camps', 'clinics', 'camp', 'clinic', 'summer-camps', 'soccer-camps'], 'product_cat', $pid)) {
                return true;
            }

            // Check 2: Product name contains camp/clinic
            $product_name = '';
            if (false && false) {
                $p = ptp_get_camp_product($pid);
                if ($p) $product_name = $p->get_name();
            } else {
                $product_name = get_the_title($pid);
            }

            if ($product_name && (stripos($product_name, 'camp') !== false || stripos($product_name, 'clinic') !== false)) {
                return true;
            }

            // Check 3: Custom meta flag
            if (get_post_meta($pid, '_ptp_is_camp', true) === 'yes') {
                return true;
            }
        }
        return false;
    }

    private function sess($key, $default = null) {
        // v149: Support both WC and native session
        if (false && false && ptp_session()) {
            return ptp_session()->get("ptp99_{$key}", $default);
        } elseif (function_exists('ptp_session')) {
            return ptp_session()->get("ptp99_{$key}", $default);
        }
        return $default;
    }

    private function set_sess($key, $val) {
        // v149: Support both WC and native session
        if (false && false && ptp_session()) {
            ptp_session()->set("ptp99_{$key}", $val);
        } elseif (function_exists('ptp_session')) {
            ptp_session()->set("ptp99_{$key}", $val);
        }
    }
    
    // =========================================
    // PAYMENT
    // =========================================
    
    public function card_only_gateways($gateways) {
        if (!is_page('ptp-checkout')) return $gateways;
        $keep = ['stripe', 'stripe_cc', 'ptp-native_payments'];
        return array_intersect_key($gateways, array_flip($keep));
    }
    
    // =========================================
    // FEES
    // =========================================
    
    public function calculate_fees($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!$this->is_camp_checkout()) return;
        
        $subtotal = $cart->get_subtotal();
        
        // Care bundle (Before + After together)
        if ($this->sess('care_bundle')) {
            $cart->add_fee('Before + After Care (8am-4:30pm)', self::CARE_BUNDLE, true);
            $subtotal += self::CARE_BUNDLE;
        }
        
        // Jersey
        if ($this->sess('jersey')) {
            $cart->add_fee('WC 2026 x PTP Jersey', self::JERSEY_PRICE, true);
            $subtotal += self::JERSEY_PRICE;
        }
        
        // Sibling discount (10% off sibling registration)
        $count = max(1, (int)$this->sess('campers', 1));
        if ($count > 1 && !$this->sess('is_team')) {
            $camp_price = $cart->get_subtotal() / $count; // Per-camper price
            $disc = round($camp_price * ($count - 1) * self::SIBLING_DISCOUNT_PCT / 100, 2);
            $cart->add_fee("Sibling Discount (10% × " . ($count-1) . ")", -$disc, true);
            $subtotal -= $disc;
        }
        
        // Team discount
        if ($this->sess('is_team')) {
            $size = (int)$this->sess('team_size', 5);
            $pct = 0;
            foreach (self::TEAM_DISCOUNTS as $min => $p) if ($size >= $min) $pct = $p;
            if ($pct > 0) {
                $disc = round($cart->get_subtotal() * $pct / 100, 2);
                $cart->add_fee("Team Discount ({$pct}%)", -$disc, true);
                $subtotal -= $disc;
            }
        }
        
        // Referral
        $ref = $this->sess('referral');
        if ($ref && $this->valid_referral($ref)) {
            $cart->add_fee('Referral Discount', -self::REFERRAL_DISCOUNT, true);
            $subtotal -= self::REFERRAL_DISCOUNT;
        }
        
        // Upgrade pack
        $upgrade = $this->sess('upgrade_pack', '');
        if ($upgrade) {
            $base_price = $cart->get_subtotal(); // Price of 1 camp
            switch ($upgrade) {
                case '2pack':
                    // Add 1 more camp at 10% off
                    $add_price = round($base_price * 0.90);
                    $cart->add_fee('2-Camp Pack (+1 camp, 10% off)', $add_price, true);
                    $subtotal += $add_price;
                    break;
                case '3pack':
                    // Add 2 more camps at 20% off each
                    $add_price = round($base_price * 2 * 0.80);
                    $cart->add_fee('3-Camp Pack (+2 camps, 20% off)', $add_price, true);
                    $subtotal += $add_price;
                    break;
                case 'allaccess':
                    // Replace with All-Access Pass pricing
                    // Remove the camp and add All-Access
                    $aa_price = 4000 - $base_price; // Subtract camp already in cart
                    $cart->add_fee('All-Access Pass Upgrade', $aa_price, true);
                    $subtotal += $aa_price;
                    break;
            }
        }
        
        // Processing fee
        $fee = round($subtotal * self::PROCESSING_RATE + self::PROCESSING_FLAT, 2);
        $cart->add_fee('Card Processing (3% + $0.30)', $fee, true);
    }
    
    // =========================================
    // AJAX
    // =========================================
    
    public function ajax_update() {
        check_ajax_referer('ptp99', 'n');
        
        $action = sanitize_key($_POST['a'] ?? '');
        $data = $_POST['d'] ?? [];
        
        switch ($action) {
            case 'toggle':
                $field = sanitize_key($data['f'] ?? '');
                $allowed = ['care_bundle', 'jersey', 'is_team', 'add_sibling'];
                if (in_array($field, $allowed)) {
                    $current = $this->sess($field);
                    $this->set_sess($field, !$current);
                }
                break;
                
            case 'set':
                $field = sanitize_key($data['f'] ?? '');
                $val = $data['v'] ?? '';
                $allowed = ['campers', 'team_size', 'team_name', 'referral'];
                if (in_array($field, $allowed)) {
                    $this->set_sess($field, is_numeric($val) ? (int)$val : sanitize_text_field($val));
                }
                break;
                
            case 'referral':
                $code = strtoupper(sanitize_text_field($data['code'] ?? ''));
                if ($this->valid_referral($code)) {
                    $this->set_sess('referral', $code);
                    wp_send_json_success(['msg' => 'Code applied! -$' . self::REFERRAL_DISCOUNT]);
                } else {
                    $this->set_sess('referral', '');
                    wp_send_json_error(['msg' => 'Invalid code']);
                }
                return;
                
            case 'upgrade':
                $pack = sanitize_key($data['pack'] ?? '');
                $current = $this->sess('upgrade_pack', '');
                // Toggle - if same pack, clear it; otherwise set it
                if ($current === $pack) {
                    $this->set_sess('upgrade_pack', '');
                } else {
                    $this->set_sess('upgrade_pack', $pack);
                }
                break;
        }
        
        ptp_cart() && ptp_cart()->calculate_totals();
        wp_send_json_success();
    }
    
    // =========================================
    // REFERRAL SYSTEM
    // =========================================
    
    public function init_tables() {
        global $wpdb;
        $t = $wpdb->prefix . 'ptp_referrals';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            $wpdb->query("CREATE TABLE $t (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) UNIQUE NOT NULL,
                order_id BIGINT UNSIGNED,
                email VARCHAR(100),
                uses INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) {$wpdb->get_charset_collate()}");
        }
    }
    
    private function valid_referral($code) {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}ptp_referrals WHERE code=%s AND uses<10", $code
        ));
    }
    
    public function handle_referral($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order || $order->get_meta('_ptp_ref_done')) return;
        
        // Credit used referral
        $used = $this->sess('referral');
        if ($used) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_referrals SET uses=uses+1 WHERE code=%s", $used
            ));
            $order->update_meta_data('_ptp_referral_used', $used);
        }
        
        // Generate new code
        $code = 'PTP' . strtoupper(substr(md5($order_id . time()), 0, 6));
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'ptp_referrals', [
            'code' => $code,
            'order_id' => $order_id,
            'email' => $order->get_billing_email()
        ]);
        $order->update_meta_data('_ptp_referral_code', $code);
        $order->update_meta_data('_ptp_ref_done', 1);
        $order->save();
        
        // Display
        echo '<div class="ptp-ref-box"><div class="ptp-ref-inner">';
        echo '<span class="ptp-ref-icon">🎁</span>';
        echo '<h3>SHARE & EARN</h3>';
        echo '<p>Give friends <b>$' . self::REFERRAL_DISCOUNT . ' off</b> their first camp!</p>';
        echo '<div class="ptp-ref-code"><span>' . esc_html($code) . '</span>';
        echo '<button onclick="navigator.clipboard.writeText(\'' . esc_js($code) . '\');this.textContent=\'Copied!\'">Copy</button></div>';
        echo '</div></div>';
    }
    
    // =========================================
    // ORDER META
    // =========================================
    
    public function save_order_meta($order, $data) {
        if (!$this->is_camp_checkout()) return;
        
        ptp_log('[PTP Checkout v99] save_order_meta called for order #' . $order->get_id());
        
        $order->update_meta_data('_ptp_v99', 1);
        $order->update_meta_data('_ptp_care_bundle', $this->sess('care_bundle') ? 1 : 0);
        $order->update_meta_data('_ptp_jersey', $this->sess('jersey') ? 1 : 0);
        $order->update_meta_data('_ptp_campers', $this->sess('campers', 1));
        
        // Upgrade pack
        $upgrade = $this->sess('upgrade_pack', '');
        if ($upgrade) {
            $order->update_meta_data('_ptp_upgrade_pack', $upgrade);
        }
        
        if ($this->sess('is_team')) {
            $order->update_meta_data('_ptp_team', 1);
            $order->update_meta_data('_ptp_team_size', $this->sess('team_size'));
            $order->update_meta_data('_ptp_team_name', sanitize_text_field($_POST['ptp_team_name'] ?? ''));
        }
        
        // Siblings
        if (!empty($_POST['sib']) && is_array($_POST['sib'])) {
            $sibs = array_map(fn($s) => array_map('sanitize_text_field', $s), $_POST['sib']);
            $order->update_meta_data('_ptp_siblings', $sibs);
        }
        
        // v115.5.1: Comprehensive camper info capture from checkout fields
        $camper_first = '';
        $camper_last = '';
        $campers_data = [];
        
        // Check multiple field naming patterns
        $first_patterns = ['camper_first_name', 'ptp_camper_first', 'camper_first', 'player_first_name', 'camper_1_first'];
        $last_patterns = ['camper_last_name', 'ptp_camper_last', 'camper_last', 'player_last_name', 'camper_1_last'];
        
        foreach ($first_patterns as $pattern) {
            if (!empty($_POST[$pattern])) {
                $camper_first = sanitize_text_field($_POST[$pattern]);
                break;
            }
        }
        
        foreach ($last_patterns as $pattern) {
            if (!empty($_POST[$pattern])) {
                $camper_last = sanitize_text_field($_POST[$pattern]);
                break;
            }
        }
        
        // Check for indexed campers (camper_1_first, camper_2_first, etc.)
        for ($i = 1; $i <= 10; $i++) {
            $first = sanitize_text_field($_POST["camper_{$i}_first"] ?? $_POST["camper_{$i}_first_name"] ?? '');
            $last = sanitize_text_field($_POST["camper_{$i}_last"] ?? $_POST["camper_{$i}_last_name"] ?? '');
            $dob = sanitize_text_field($_POST["camper_{$i}_dob"] ?? $_POST["camper_{$i}_birthday"] ?? '');
            $size = sanitize_text_field($_POST["camper_{$i}_size"] ?? $_POST["camper_{$i}_shirt"] ?? '');
            
            if ($first || $last) {
                $campers_data[] = [
                    'first_name' => $first,
                    'last_name' => $last,
                    'dob' => $dob,
                    'shirt_size' => $size
                ];
                
                // Use first camper as primary
                if (empty($camper_first) && $first) {
                    $camper_first = $first;
                    $camper_last = $last;
                }
            }
        }
        
        // Save camper info at order level
        if ($camper_first) {
            $full_name = trim($camper_first . ' ' . $camper_last);
            $order->update_meta_data('_player_name', $full_name);
            $order->update_meta_data('_camper_first_name', $camper_first);
            $order->update_meta_data('_camper_last_name', $camper_last);
            ptp_log('[PTP Checkout v99] Saved camper: ' . $full_name);
        }
        
        if (!empty($campers_data)) {
            $order->update_meta_data('_ptp_campers_data', $campers_data);
            ptp_log('[PTP Checkout v99] Saved ' . count($campers_data) . ' campers to _ptp_campers_data');
            
            // v115.5.3: Also save camper data to individual line items
            $camper_index = 0;
            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
                $is_camp = get_post_meta($product_id, '_ptp_is_camp', true) === 'yes' ||
                          has_term(['camps', 'clinics', 'camp', 'summer-camps'], 'product_cat', $product_id) ||
                          stripos($item->get_name(), 'camp') !== false ||
                          stripos($item->get_name(), 'clinic') !== false;
                
                if ($is_camp) {
                    $qty = $item->get_quantity();
                    
                    // Assign campers to this item
                    $item_campers = [];
                    for ($q = 0; $q < $qty; $q++) {
                        if (isset($campers_data[$camper_index])) {
                            $item_campers[] = $campers_data[$camper_index];
                            $camper_index++;
                        }
                    }
                    
                    if (!empty($item_campers)) {
                        ptp_update_order_item_meta($item_id, '_ptp_item_campers', $item_campers);
                        
                        // Also set display-friendly meta for first camper
                        $first_camper = $item_campers[0];
                        $name = trim($first_camper['first_name'] . ' ' . $first_camper['last_name']);
                        ptp_update_order_item_meta($item_id, 'Player Name', $name);
                        if (!empty($first_camper['shirt_size'])) {
                            ptp_update_order_item_meta($item_id, 'T-Shirt Size', $first_camper['shirt_size']);
                        }
                        
                        ptp_log("[PTP Checkout v99] Assigned " . count($item_campers) . " campers to item #$item_id");
                    }
                }
            }
        }
        
        // Emergency contact capture
        $emergency_name = sanitize_text_field($_POST['emergency_contact'] ?? $_POST['emergency_name'] ?? '');
        $emergency_phone = sanitize_text_field($_POST['emergency_phone'] ?? $_POST['emergency_contact_phone'] ?? '');
        if ($emergency_name) {
            $order->update_meta_data('_ptp_emergency_name', $emergency_name);
            $order->update_meta_data('_ptp_emergency_phone', $emergency_phone);
        }
        
        ptp_log('[PTP Checkout v99] Order meta saved successfully');
    }
    
    /**
     * v115.5.1: Set tracking cookie when order is created
     */
    public function on_order_created($order_id) {
        if (is_object($order_id)) {
            $order_id = $order_id->get_id();
        }
        
        ptp_log('[PTP Checkout v99] on_order_created: Setting tracking cookie for order #' . $order_id);
        
        // Set cookie (1 hour expiry)
        if (!headers_sent()) {
            setcookie('ptp_last_order', $order_id, time() + 3600, '/');
        }
        
        // Also set in session
        if (false && ptp_session()) {
            ptp_session()->set('ptp_last_order_id', $order_id);
        }
    }
    
    // =========================================
    // RENDER: SIDEBAR UPSELLS
    // =========================================
    
    public function render_sidebar_upsells() {
        if (!$this->is_camp_checkout()) return;
        
        $jersey = $this->sess('jersey');
        $care = $this->sess('care_bundle');
        
        // Get cart total for pack calculations
        $cart_total = ptp_cart() ? ptp_cart()->get_subtotal() : 840;
        $pack2_total = $cart_total * 2 * 0.9;
        $pack2_save = ($cart_total * 2) - $pack2_total;
        $pack3_total = $cart_total * 3 * 0.8;
        $pack3_save = ($cart_total * 3) - $pack3_total;
        
        // All-Access values
        $aa_value = 5930;
        $aa_price = 4000;
        $aa_save = $aa_value - $aa_price;
        ?>
        <tr class="ptp-upsells-row"><td colspan="2">
            
            <!-- CAMP PACKS - PRICE ANCHORING -->
            <div class="ptp-packs">
                <div class="ptp-packs-hd">
                    <span class="ptp-packs-badge">💰 UNLOCK SAVINGS</span>
                    <div class="ptp-packs-title">CAMP <em>PACKS</em></div>
                </div>
                
                <div class="ptp-anchor">
                    <span class="ptp-anchor-lbl">Your Cart</span>
                    <div>
                        <span class="ptp-anchor-price">$<?php echo number_format($cart_total, 0); ?></span>
                        <span class="ptp-anchor-rate">1 camp</span>
                    </div>
                </div>
                
                <div class="ptp-pack-grid">
                    <a href="<?php echo home_url('/camp-packs/?pack=2'); ?>" class="ptp-pack">
                        <span class="ptp-pack-badge">SAVE 10%</span>
                        <div class="ptp-pack-name">2-CAMP <em>PACK</em></div>
                        <div class="ptp-pack-pricing">
                            <span class="ptp-pack-was">$<?php echo number_format($cart_total * 2, 0); ?></span>
                            <span class="ptp-pack-now">$<?php echo number_format($pack2_total, 0); ?></span>
                        </div>
                        <div class="ptp-pack-meta">Save $<?php echo number_format($pack2_save, 0); ?></div>
                    </a>
                    <a href="<?php echo home_url('/camp-packs/?pack=3'); ?>" class="ptp-pack feat">
                        <span class="ptp-pack-badge best">POPULAR</span>
                        <div class="ptp-pack-name">3-CAMP <em>PACK</em></div>
                        <div class="ptp-pack-pricing">
                            <span class="ptp-pack-was">$<?php echo number_format($cart_total * 3, 0); ?></span>
                            <span class="ptp-pack-now">$<?php echo number_format($pack3_total, 0); ?></span>
                        </div>
                        <div class="ptp-pack-meta">Save $<?php echo number_format($pack3_save, 0); ?></div>
                    </a>
                </div>
                
                <!-- ALL ACCESS PASS -->
                <a href="<?php echo home_url('/all-access/'); ?>" class="ptp-aa">
                    <span class="ptp-aa-badge">⭐ BEST VALUE</span>
                    <div class="ptp-aa-top">
                        <div class="ptp-aa-name">ALL-ACCESS <em>PASS</em></div>
                        <div class="ptp-aa-pricing">
                            <span class="ptp-aa-was">$<?php echo number_format($aa_value, 0); ?></span>
                            <span class="ptp-aa-now">$<?php echo number_format($aa_price, 0); ?></span>
                        </div>
                    </div>
                    <div class="ptp-aa-items">
                        <span>Camps<b>6</b></span>
                        <span>Private<b>12</b></span>
                        <span>Clinics<b>6</b></span>
                        <span>Video<b>4hr</b></span>
                        <span>Mentor<b>4hr</b></span>
                        <span>Year<b>✓</b></span>
                    </div>
                    <div class="ptp-aa-save">SAVE $<?php echo number_format($aa_save, 0); ?> (33% OFF) →</div>
                </a>
            </div>
            
            <!-- UPSELLS -->
            <div class="ptp-upsell-title">Add-Ons</div>
            
            <!-- BEFORE + AFTER CARE BUNDLE -->
            <div class="ptp-care <?php echo $care ? 'on' : ''; ?>" data-u="care_bundle">
                <div class="ptp-care-top">
                    <span class="ptp-care-icons">🌅🌆</span>
                    <div class="ptp-care-info">
                        <strong>BEFORE + AFTER CARE</strong>
                        <span>Full day: 8am – 4:30pm</span>
                    </div>
                    <div class="ptp-care-price">
                        <b>$<?php echo self::CARE_BUNDLE; ?></b><s>$90</s>
                    </div>
                </div>
                <div class="ptp-care-times">
                    <div><strong>Before</strong>8-9am</div>
                    <div><strong>After</strong>3-4:30pm</div>
                </div>
                <button type="button" class="ptp-care-btn"><?php echo $care ? '✓ ADDED – FULL DAY' : '+ ADD CARE – $60'; ?></button>
            </div>
            
            <!-- JERSEY -->
            <div class="ptp-jersey <?php echo $jersey ? 'on' : ''; ?>" data-u="jersey">
                <span class="ptp-jersey-tag">✨ LIMITED</span>
                <div class="ptp-jersey-top">
                    <span class="ptp-jersey-emoji">👕</span>
                    <div class="ptp-jersey-info">
                        <strong>WC 2026 X PTP JERSEY</strong>
                        <span>2nd exclusive jersey</span>
                    </div>
                    <div class="ptp-jersey-price">
                        <b>$<?php echo self::JERSEY_PRICE; ?></b><s>$<?php echo self::JERSEY_ORIGINAL; ?></s>
                    </div>
                </div>
                <button type="button" class="ptp-jersey-btn"><?php echo $jersey ? '✓ ADDED' : '+ ADD JERSEY'; ?></button>
            </div>
            
        </td></tr>
        <?php
    }
    
    // =========================================
    // RENDER: DISCOUNTS SECTION
    // =========================================
    
    public function render_discounts_section($checkout) {
        if (!$this->is_camp_checkout()) return;
        
        // DEBUG: Add visible marker
        echo '<!-- PTP V99 DISCOUNTS SECTION START -->';
        
        $ref = $this->sess('referral', '');
        $is_team = $this->sess('is_team');
        $has_sib = $this->sess('add_sibling');
        ?>
        <div id="ptp99" data-nonce="<?php echo wp_create_nonce('ptp99'); ?>">
        
        <!-- DISCOUNTS HEADER -->
        <div class="ptp-section">
            <h3 class="ptp-section-title">
                <span class="ptp-num">5</span>
                DISCOUNTS
                <span class="ptp-opt">OPTIONAL</span>
            </h3>
            
            <!-- SIBLING -->
            <div class="ptp-card <?php echo $has_sib ? 'open' : ''; ?>" data-card="sibling">
                <div class="ptp-card-head" data-toggle="add_sibling">
                    <span class="ptp-card-icon">👨‍👩‍👧‍👦</span>
                    <div class="ptp-card-info">
                        <strong>ADD SIBLING – SAVE <?php echo self::SIBLING_DISCOUNT_PCT; ?>% [V100 TEST]</strong>
                        <span>Same camp, <?php echo self::SIBLING_DISCOUNT_PCT; ?>% off per sibling</span>
                    </div>
                    <span class="ptp-card-toggle"></span>
                </div>
                <div class="ptp-card-body" id="ptp-sibs"></div>
                <div class="ptp-savings" id="ptp-savings" style="display:none;">
                    💰 Saving $<span id="ptp-sav-amt">0</span> with sibling discount
                </div>
            </div>
            
            <!-- REFERRAL -->
            <div class="ptp-card ptp-card-ref <?php echo $ref ? 'done' : ''; ?>">
                <div class="ptp-card-head ptp-card-head-static">
                    <span class="ptp-card-icon">⭐</span>
                    <div class="ptp-card-info">
                        <strong>REFERRAL CODE – SAVE $<?php echo self::REFERRAL_DISCOUNT; ?></strong>
                        <span>Have a code from a friend?</span>
                    </div>
                </div>
                <div class="ptp-card-body ptp-card-body-always">
                    <div class="ptp-input-row">
                        <input type="text" id="ptp-ref-input" placeholder="ENTER CODE" maxlength="12" value="<?php echo esc_attr($ref); ?>" <?php echo $ref ? 'readonly' : ''; ?>>
                        <button type="button" id="ptp-ref-btn"><?php echo $ref ? '✓' : 'Apply'; ?></button>
                    </div>
                    <div id="ptp-ref-msg" class="<?php echo $ref ? 'ok' : ''; ?>"><?php echo $ref ? '✅ -$' . self::REFERRAL_DISCOUNT : ''; ?></div>
                </div>
            </div>
            
            <!-- TEAM -->
            <div class="ptp-card <?php echo $is_team ? 'open' : ''; ?>" data-card="team">
                <div class="ptp-card-head" data-toggle="is_team">
                    <span class="ptp-card-icon">👥</span>
                    <div class="ptp-card-info">
                        <strong>TEAM REGISTRATION – SAVE 15%</strong>
                        <span>5+ players from the same team</span>
                    </div>
                    <span class="ptp-card-toggle"></span>
                </div>
                <div class="ptp-card-body ptp-team-form">
                    <div class="ptp-row">
                        <div class="ptp-field">
                            <label>Team Name</label>
                            <input type="text" name="ptp_team_name" placeholder="FC Lightning U12">
                        </div>
                        <div class="ptp-field">
                            <label># Players</label>
                            <input type="number" id="ptp-team-size" min="5" max="50" value="5">
                        </div>
                    </div>
                    <div class="ptp-tiers">
                        <span>5-9: <b>10%</b></span>
                        <span>10-14: <b>15%</b></span>
                        <span>15+: <b>20%</b></span>
                    </div>
                </div>
            </div>
            
            <!-- ============================================ -->
            <!-- UPGRADE & SAVE - INSIDE DISCOUNTS SECTION -->
            <!-- v99: Integration with camp product template -->
            <!-- ============================================ -->
            
            <?php
            $cart_total = ptp_cart() ? ptp_cart()->get_subtotal() : 420;
            
            // v99: Check if user already selected multiple camps from product page
            $camps_in_cart = (int)$this->sess('camps_in_cart', 0);
            if ($camps_in_cart < 1) {
                // Count camps in cart directly
                $camps_in_cart = 0;
                if (ptp_cart()) {
                    foreach (ptp_cart()->get_items() as $item) {
                        $pid = $item['product_id'];
                        $product = ptp_get_camp_product($pid);
                        if (!$product) continue;
                        $is_camp = has_term(['camps', 'clinics', 'camp', 'summer-camps'], 'product_cat', $pid) ||
                                   stripos($product->get_name(), 'camp') !== false ||
                                   stripos($product->get_name(), 'clinic') !== false;
                        if ($is_camp) {
                            $camps_in_cart += $item['quantity'];
                        }
                    }
                }
            }
            
            // v99: Show different UI based on whether multiple camps already selected
            $show_upgrade_bumps = ($camps_in_cart <= 1);
            
            // Get per-camp price (if multiple camps, divide subtotal)
            $single_price = $camps_in_cart > 1 ? ($cart_total / $camps_in_cart) : $cart_total;
            // Savings must match PTP_Camp_Orders::MULTIWEEK_DISCOUNTS (2=>10%, 3=>15%, 4+=>20%)
            $pack2_save = round($single_price * 2 * 0.10);   // 2 weeks × 10% = total savings
            $pack3_save = round($single_price * 3 * 0.15);   // 3 weeks × 15% = total savings
            $aa_price = 4000;
            $aa_save = 1930;
            $upgrade_pack = $this->sess('upgrade_pack', '');
            
            if ($camps_in_cart > 1) :
                // User already selected multiple camps from product page - show confirmation
                // Match PTP_Camp_Orders::MULTIWEEK_DISCOUNTS: 2=>10%, 3=>15%, 4+=>20%
                $discount_pct = 0;
                if (class_exists('PTP_Camp_Orders')) {
                    foreach (PTP_Camp_Orders::MULTIWEEK_DISCOUNTS as $min_weeks => $pct) {
                        if ($camps_in_cart >= $min_weeks) {
                            $discount_pct = $pct;
                        }
                    }
                } else {
                    if ($camps_in_cart >= 4) $discount_pct = 20;
                    elseif ($camps_in_cart >= 3) $discount_pct = 15;
                    elseif ($camps_in_cart >= 2) $discount_pct = 10;
                }
            ?>
            <div class="ptp-card ptp-multicamp-selected" style="background:#ecfdf5; border:2px solid #10b981;">
                <div class="ptp-card-head ptp-card-head-static">
                    <span class="ptp-card-icon">✅</span>
                    <div class="ptp-card-info">
                        <strong style="color:#059669;"><?php echo $camps_in_cart; ?>-CAMP PACK SELECTED</strong>
                        <span>You're saving <?php echo $discount_pct; ?>% with multiweek discount!</span>
                    </div>
                </div>
                <div class="ptp-card-body ptp-card-body-always" style="display:block;">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;">
                        <span><?php echo $camps_in_cart; ?> camp weeks selected</span>
                        <strong style="color:#059669;">-<?php echo $discount_pct; ?>% applied</strong>
                    </div>
                    <a href="<?php echo home_url('/camps/'); ?>" style="display:block;text-align:center;margin-top:12px;color:#FCB900;font-size:12px;">
                        ← Change camp selections
                    </a>
                </div>
            </div>
            
            <?php else : ?>
            <!-- Single camp - show upgrade bumps -->
            <div class="ptp-card" style="background:#fffbeb; border:3px solid #FCB900;">
                <div class="ptp-card-head ptp-card-head-static">
                    <span class="ptp-card-icon">⚡</span>
                    <div class="ptp-card-info">
                        <strong>UPGRADE & SAVE</strong>
                        <span>Add more camps at a discount</span>
                    </div>
                </div>
                <div class="ptp-card-body ptp-card-body-always" style="display:block !important;">
                    <!-- 2-CAMP PACK -->
                    <label class="ptp-bump <?php echo $upgrade_pack === '2pack' ? 'checked' : ''; ?>" data-upgrade="2pack" style="display:flex !important;">
                        <input type="checkbox" name="ptp_upgrade" value="2pack" <?php checked($upgrade_pack, '2pack'); ?>>
                        <div class="ptp-bump-check">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                        <div class="ptp-bump-content">
                            <div class="ptp-bump-badge">SAVE 10%</div>
                            <div class="ptp-bump-title">ADD 2ND CAMP</div>
                            <div class="ptp-bump-desc">Register for any 2nd camp week</div>
                        </div>
                        <div class="ptp-bump-price">
                            <span class="ptp-bump-add">+$<?php echo number_format($single_price * 0.90, 0); ?></span>
                            <span class="ptp-bump-save">Save $<?php echo number_format($pack2_save, 0); ?></span>
                        </div>
                    </label>
                    
                    <!-- 3-CAMP PACK -->
                    <label class="ptp-bump <?php echo $upgrade_pack === '3pack' ? 'checked' : ''; ?>" data-upgrade="3pack" style="display:flex !important;">
                        <input type="checkbox" name="ptp_upgrade" value="3pack" <?php checked($upgrade_pack, '3pack'); ?>>
                        <div class="ptp-bump-check">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                        <div class="ptp-bump-content">
                            <div class="ptp-bump-badge best">MOST POPULAR</div>
                            <div class="ptp-bump-title">ADD 2 MORE CAMPS</div>
                            <div class="ptp-bump-desc">3-camp pack — best summer value</div>
                        </div>
                        <div class="ptp-bump-price">
                            <span class="ptp-bump-add">+$<?php echo number_format($single_price * 2 * 0.85, 0); ?></span>
                            <span class="ptp-bump-save">Save $<?php echo number_format($pack3_save, 0); ?></span>
                        </div>
                    </label>
                    
                    <!-- ALL-ACCESS PASS -->
                    <label class="ptp-bump ptp-bump-aa <?php echo $upgrade_pack === 'allaccess' ? 'checked' : ''; ?>" data-upgrade="allaccess" style="display:flex !important;">
                        <input type="checkbox" name="ptp_upgrade" value="allaccess" <?php checked($upgrade_pack, 'allaccess'); ?>>
                        <div class="ptp-bump-check">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                        <div class="ptp-bump-content">
                            <div class="ptp-bump-badge gold">⭐ BEST VALUE</div>
                            <div class="ptp-bump-title">GO ALL-ACCESS</div>
                            <div class="ptp-bump-desc">6 camps + 12 sessions + year-round training</div>
                        </div>
                        <div class="ptp-bump-price">
                            <span class="ptp-bump-add">$<?php echo number_format($aa_price, 0); ?></span>
                            <span class="ptp-bump-save">Save $<?php echo number_format($aa_save, 0); ?></span>
                        </div>
                    </label>
                    
                    <div style="text-align:center; margin-top:12px;">
                        <a href="<?php echo home_url('/all-access/'); ?>" style="color:#FCB900; font-size:12px;">Learn more about All-Access Pass →</a>
                    </div>
                </div>
            </div>
            <?php endif; // End of single camp / multicamp conditional ?>
            
        </div>
        
        <input type="hidden" name="ptp_campers" id="ptp-campers" value="<?php echo (int)$this->sess('campers', 1); ?>">
        </div>
        <!-- PTP V99 DISCOUNTS SECTION END -->
        <?php
    }
    
    // =========================================
    // RENDER: UPGRADE BUMPS (INLINE) - DEPRECATED
    // =========================================
    
    private function render_upgrade_bumps() {
        // Now rendered directly in render_discounts_section
    }
    
    // =========================================
    // STYLES - MOBILE FIRST
    // =========================================
    
    public function output_styles() {
        if (!is_page('ptp-checkout')) return;
        ?>
        <style id="ptp99-css">
        /* PTP v99 - COMPACT MOBILE-FIRST */
        :root {
            --gold: #FCB900;
            --black: #0A0A0A;
            --green: #22c55e;
            --red: #ef4444;
            --gray: #666;
            --gray-lt: #f5f5f5;
            --border: #e0e0e0;
        }
        
        #ptp99 { font-family: 'Inter', -apple-system, sans-serif; font-size: 13px; }
        
        /* Hide express checkout */
        #wc-stripe-payment-request-wrapper,
        .wc-stripe-payment-request-button-separator,
        [id*="express-checkout"] { display: none !important; }
        
        /* Section */
        .ptp-section { margin: 16px 0; }
        
        .ptp-section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding-bottom: 8px;
            margin: 0 0 10px;
            border-bottom: 2px solid var(--black);
        }
        
        .ptp-num {
            width: 20px; height: 20px;
            background: var(--gold);
            color: var(--black);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
        }
        
        .ptp-opt {
            margin-left: auto;
            font-size: 8px;
            color: var(--gray);
            letter-spacing: 1px;
        }
        
        /* Cards */
        .ptp-card {
            border: 2px solid var(--border);
            margin-bottom: 8px;
            transition: border-color 0.2s;
        }
        
        .ptp-card.open { border-color: var(--gold); }
        
        .ptp-card-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            cursor: pointer;
            min-height: 52px;
            -webkit-tap-highlight-color: transparent;
        }
        
        .ptp-card-head:active { background: var(--gray-lt); }
        .ptp-card-head-static { cursor: default; }
        .ptp-card-head-static:active { background: transparent; }
        
        .ptp-card-icon { font-size: 18px; }
        .ptp-card-info { flex: 1; }
        
        .ptp-card-info strong {
            display: block;
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--black);
        }
        
        .ptp-card-info span {
            font-size: 10px;
            color: var(--gray);
        }
        
        .ptp-card-toggle {
            width: 22px; height: 22px;
            border: 2px solid var(--black);
            position: relative;
        }
        
        .ptp-card-toggle::before,
        .ptp-card-toggle::after {
            content: '';
            position: absolute;
            background: var(--black);
        }
        
        .ptp-card-toggle::before {
            width: 10px; height: 2px;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
        }
        
        .ptp-card-toggle::after {
            width: 2px; height: 10px;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
        }
        
        .ptp-card.open .ptp-card-toggle {
            background: var(--gold);
            border-color: var(--gold);
        }
        
        .ptp-card.open .ptp-card-toggle::after { height: 0; }
        
        .ptp-card-body {
            display: none;
            padding: 0 12px 12px;
        }
        
        .ptp-card.open .ptp-card-body,
        .ptp-card-body-always { display: block; }
        
        /* Form */
        .ptp-row { display: flex; gap: 8px; margin-bottom: 8px; }
        .ptp-row:last-child { margin-bottom: 0; }
        .ptp-field { flex: 1; }
        
        .ptp-field label {
            display: block;
            font-family: 'Oswald', sans-serif;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray);
            margin-bottom: 4px;
        }
        
        .ptp-field input,
        .ptp-field select {
            width: 100%;
            padding: 10px 8px;
            border: 2px solid var(--border);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        
        .ptp-field input:focus,
        .ptp-field select:focus {
            outline: none;
            border-color: var(--gold);
        }
        
        /* Referral */
        .ptp-input-row { display: flex; }
        
        .ptp-input-row input {
            flex: 1;
            padding: 10px;
            border: 2px solid var(--black);
            border-right: none;
            font-family: 'Oswald', sans-serif;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-align: center;
        }
        
        .ptp-input-row input:focus { outline: none; border-color: var(--gold); }
        
        .ptp-input-row button {
            padding: 10px 16px;
            background: var(--gold);
            border: 2px solid var(--gold);
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        #ptp-ref-msg {
            font-size: 11px;
            margin-top: 6px;
            padding: 8px;
            display: none;
        }
        
        #ptp-ref-msg.ok { display: block; background: #dcfce7; color: #166534; }
        #ptp-ref-msg.err { display: block; background: #fee2e2; color: #991b1b; }
        
        .ptp-card-ref.done .ptp-input-row input {
            background: #f0fdf4;
            color: var(--green);
            border-color: var(--green);
        }
        
        .ptp-card-ref.done .ptp-input-row button {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
        }
        
        /* Team Tiers */
        .ptp-tiers {
            display: flex;
            background: var(--black);
            margin-top: 8px;
        }
        
        .ptp-tiers span {
            flex: 1;
            padding: 8px;
            text-align: center;
            font-family: 'Oswald', sans-serif;
            font-size: 10px;
            color: #888;
            text-transform: uppercase;
            border-right: 1px solid #333;
        }
        
        .ptp-tiers span:last-child { border-right: none; }
        .ptp-tiers b { display: block; color: var(--gold); font-size: 12px; margin-top: 2px; }
        
        /* Sibling */
        .ptp-sib {
            background: var(--gray-lt);
            border: 2px solid var(--gold);
            padding: 10px;
            margin-bottom: 8px;
        }
        
        .ptp-sib-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
        }
        
        .ptp-sib-head span {
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .ptp-sib-head button {
            background: none;
            border: none;
            color: var(--red);
            font-family: 'Oswald', sans-serif;
            font-size: 10px;
            text-transform: uppercase;
            padding: 6px 10px;
            margin: -6px -10px -6px 0;
        }
        
        .ptp-savings {
            background: var(--gold);
            color: var(--black);
            padding: 10px;
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
            letter-spacing: 0.5px;
        }
        
        /* =============================================
           ORDER SUMMARY - COMPACT
           ============================================= */
        .ptp-upsells-row td { padding: 0 !important; }
        
        /* Packs */
        .ptp-packs {
            background: var(--black);
            padding: 12px;
            margin-bottom: 10px;
        }
        
        .ptp-packs-hd {
            text-align: center;
            margin-bottom: 10px;
        }
        
        .ptp-packs-badge {
            display: inline-block;
            background: var(--gold);
            color: var(--black);
            font-family: 'Oswald', sans-serif;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 3px 8px;
            margin-bottom: 4px;
        }
        
        .ptp-packs-title {
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
        }
        
        .ptp-packs-title em { color: var(--gold); font-style: italic; }
        
        .ptp-anchor {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            background: #1a1a1a;
            border: 1px solid #333;
            margin-bottom: 8px;
            font-family: 'Oswald', sans-serif;
        }
        
        .ptp-anchor-lbl {
            font-size: 8px;
            color: var(--gold);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .ptp-anchor-price {
            font-size: 14px;
            font-weight: 700;
            color: var(--gold);
        }
        
        .ptp-anchor-rate {
            font-size: 9px;
            color: #666;
            margin-left: 6px;
        }
        
        .ptp-pack-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-bottom: 8px;
        }
        
        .ptp-pack {
            display: block;
            background: #1a1a1a;
            border: 2px solid #333;
            padding: 10px 8px;
            text-align: center;
            text-decoration: none;
            position: relative;
            transition: all 0.2s;
        }
        
        .ptp-pack:hover { border-color: var(--gold); }
        .ptp-pack.feat { border-color: var(--gold); }
        
        .ptp-pack-badge {
            position: absolute;
            top: -7px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--gold);
            color: var(--black);
            font-family: 'Oswald', sans-serif;
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 6px;
            white-space: nowrap;
        }
        
        .ptp-pack-badge.best { background: var(--green); color: #fff; }
        
        .ptp-pack-name {
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
            margin-bottom: 2px;
        }
        
        .ptp-pack-name em { color: var(--gold); font-style: italic; }
        
        .ptp-pack-was {
            font-size: 9px;
            color: #666;
            text-decoration: line-through;
        }
        
        .ptp-pack-now {
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--gold);
            margin-left: 4px;
        }
        
        .ptp-pack-meta {
            font-size: 9px;
            color: var(--green);
            margin-top: 2px;
        }
        
        /* All Access */
        .ptp-aa {
            display: block;
            background: linear-gradient(135deg, #1a1a1a, #0a0a0a);
            border: 2px solid var(--gold);
            padding: 12px;
            position: relative;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .ptp-aa:hover { box-shadow: 0 0 20px rgba(252,185,0,0.2); }
        
        .ptp-aa-badge {
            position: absolute;
            top: -8px;
            left: 12px;
            background: var(--gold);
            color: var(--black);
            font-family: 'Oswald', sans-serif;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 3px 10px;
        }
        
        .ptp-aa-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .ptp-aa-name {
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
        }
        
        .ptp-aa-name em { color: var(--gold); font-style: italic; }
        
        .ptp-aa-pricing { text-align: right; }
        
        .ptp-aa-was {
            font-size: 10px;
            color: #666;
            text-decoration: line-through;
        }
        
        .ptp-aa-now {
            font-family: 'Oswald', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: var(--gold);
        }
        
        .ptp-aa-items {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            padding: 8px;
            background: rgba(0,0,0,0.3);
            font-size: 9px;
            color: #888;
            margin-bottom: 8px;
        }
        
        .ptp-aa-items span {
            text-align: center;
            padding: 4px 2px;
            border-right: 1px solid #333;
        }
        
        .ptp-aa-items span:nth-child(3n) { border-right: none; }
        
        .ptp-aa-items b {
            display: block;
            color: var(--gold);
            font-family: 'Oswald', sans-serif;
            font-size: 12px;
        }
        
        .ptp-aa-save {
            background: var(--green);
            color: #fff;
            font-family: 'Oswald', sans-serif;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
            padding: 6px;
            letter-spacing: 1px;
        }
        
        /* Upsell Title */
        .ptp-upsell-title {
            font-family: 'Oswald', sans-serif;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--gray);
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }
        
        /* Care Bundle */
        .ptp-care {
            background: linear-gradient(135deg, #065f46, #047857);
            border: 2px solid #10b981;
            padding: 10px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .ptp-care.on {
            border-color: var(--gold);
            box-shadow: 0 0 0 2px var(--gold);
        }
        
        .ptp-care-top {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        
        .ptp-care-icons { font-size: 16px; }
        .ptp-care-info { flex: 1; }
        
        .ptp-care-info strong {
            display: block;
            font-family: 'Oswald', sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
        }
        
        .ptp-care-info span {
            font-size: 10px;
            color: rgba(255,255,255,0.7);
        }
        
        .ptp-care-price b {
            font-family: 'Oswald', sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--gold);
        }
        
        .ptp-care-price s {
            font-size: 10px;
            color: rgba(255,255,255,0.5);
            margin-left: 4px;
        }
        
        .ptp-care-times {
            display: flex;
            gap: 8px;
            padding: 6px 8px;
            background: rgba(0,0,0,0.2);
            font-size: 10px;
            color: rgba(255,255,255,0.8);
            margin-bottom: 8px;
        }
        
        .ptp-care-times div { flex: 1; }
        
        .ptp-care-times strong {
            display: block;
            font-family: 'Oswald', sans-serif;
            font-size: 8px;
            color: var(--gold);
            text-transform: uppercase;
        }
        
        .ptp-care-btn {
            width: 100%;
            background: transparent;
            border: 2px solid var(--gold);
            color: var(--gold);
            font-family: 'Oswald', sans-serif;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 8px;
            cursor: pointer;
        }
        
        .ptp-care.on .ptp-care-btn {
            background: var(--gold);
            color: var(--black);
        }
        
        /* Jersey */
        .ptp-jersey {
            background: var(--black);
            border: 2px solid #333;
            padding: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .ptp-jersey.on { border-color: var(--gold); }
        
        .ptp-jersey-tag {
            display: inline-block;
            background: var(--gold);
            color: var(--black);
            font-family: 'Oswald', sans-serif;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 6px;
            margin-bottom: 8px;
        }
        
        .ptp-jersey-top {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        
        .ptp-jersey-emoji { font-size: 22px; }
        .ptp-jersey-info { flex: 1; }
        
        .ptp-jersey-info strong {
            display: block;
            font-family: 'Oswald', sans-serif;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
        }
        
        .ptp-jersey-info span {
            font-size: 9px;
            color: #888;
        }
        
        .ptp-jersey-price b {
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--gold);
        }
        
        .ptp-jersey-price s {
            font-size: 9px;
            color: #666;
            margin-left: 4px;
        }
        
        .ptp-jersey-btn {
            width: 100%;
            background: transparent;
            border: 2px solid var(--gold);
            color: var(--gold);
            font-family: 'Oswald', sans-serif;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 8px;
            cursor: pointer;
        }
        
        .ptp-jersey.on .ptp-jersey-btn {
            background: var(--green);
            border-color: var(--green);
            color: #fff;
        }
        
        /* Referral Thank You */
        .ptp-ref-box { margin: 16px 0; }
        
        .ptp-ref-inner {
            background: linear-gradient(135deg, var(--black), #1a1a1a);
            color: #fff;
            padding: 20px;
            text-align: center;
            border: 2px solid var(--gold);
        }
        
        .ptp-ref-icon { font-size: 32px; margin-bottom: 6px; display: block; }
        
        .ptp-ref-inner h3 {
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
            color: var(--gold);
            margin: 0 0 6px;
        }
        
        .ptp-ref-inner p { margin: 0 0 12px; font-size: 12px; }
        
        .ptp-ref-code { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; }
        
        .ptp-ref-code span {
            background: var(--gold);
            color: var(--black);
            font-family: 'Oswald', monospace;
            font-size: 16px;
            font-weight: 700;
            padding: 10px 16px;
            letter-spacing: 2px;
        }
        
        .ptp-ref-code button {
            background: #fff;
            color: var(--black);
            border: none;
            padding: 10px 14px;
            font-family: 'Oswald', sans-serif;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
        }
        
        /* Mobile */
        @media (max-width: 480px) {
            .ptp-row { flex-direction: column; }
            .ptp-input-row { flex-direction: column; }
            .ptp-input-row input { border-right: 2px solid var(--black); border-bottom: none; }
            .ptp-input-row button { width: 100%; }
            .ptp-ref-code { flex-direction: column; }
            .ptp-ref-code span, .ptp-ref-code button { width: 100%; text-align: center; }
        }
        
        /* ==========================================
           UPGRADE SECTION (ORDER BUMP)
           ========================================== */
        
        .ptp-upgrade-section {
            margin: 24px 0;
            padding-top: 8px;
        }
        
        .ptp-bump {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            margin-bottom: 10px;
            border: 2px solid var(--border);
            background: #fff;
            cursor: pointer;
            transition: all 0.2s ease;
            -webkit-tap-highlight-color: transparent;
        }
        
        .ptp-bump:hover {
            border-color: var(--gold);
            background: #fffdf5;
        }
        
        .ptp-bump.checked {
            border-color: var(--green);
            background: #f0fdf4;
        }
        
        .ptp-bump input[type="checkbox"] {
            display: none;
        }
        
        .ptp-bump-check {
            width: 24px;
            height: 24px;
            min-width: 24px;
            border: 2px solid var(--border);
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }
        
        .ptp-bump-check svg {
            width: 14px;
            height: 14px;
            opacity: 0;
            stroke: #fff;
            transition: opacity 0.15s;
        }
        
        .ptp-bump.checked .ptp-bump-check {
            background: var(--green);
            border-color: var(--green);
        }
        
        .ptp-bump.checked .ptp-bump-check svg {
            opacity: 1;
        }
        
        .ptp-bump-content {
            flex: 1;
            min-width: 0;
        }
        
        .ptp-bump-badge {
            display: inline-block;
            font-family: 'Oswald', sans-serif;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            background: var(--black);
            color: #fff;
            padding: 3px 8px;
            margin-bottom: 4px;
        }
        
        .ptp-bump-badge.best {
            background: var(--gold);
            color: var(--black);
        }
        
        .ptp-bump-badge.gold {
            background: linear-gradient(135deg, #FCB900, #f59e0b);
            color: var(--black);
        }
        
        .ptp-bump-title {
            font-family: 'Oswald', sans-serif;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--black);
        }
        
        .ptp-bump-desc {
            font-size: 11px;
            color: var(--gray);
            margin-top: 2px;
        }
        
        .ptp-bump-price {
            text-align: right;
            min-width: 80px;
        }
        
        .ptp-bump-add {
            display: block;
            font-family: 'Oswald', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--black);
        }
        
        .ptp-bump-save {
            display: block;
            font-size: 10px;
            font-weight: 600;
            color: var(--green);
            text-transform: uppercase;
        }
        
        /* All-Access bump special styling */
        .ptp-bump-aa {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border-color: var(--gold);
        }
        
        .ptp-bump-aa:hover {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        }
        
        .ptp-bump-aa.checked {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-color: var(--green);
        }
        
        /* Mobile adjustments */
        @media (max-width: 480px) {
            .ptp-bump {
                flex-wrap: wrap;
                padding: 12px;
            }
            
            .ptp-bump-content {
                flex: 1 1 calc(100% - 50px);
            }
            
            .ptp-bump-price {
                width: 100%;
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 8px;
                padding-top: 8px;
                border-top: 1px solid var(--border);
            }
            
            .ptp-bump-add {
                font-size: 16px;
            }
        }
        
        /* AA Link */
        .ptp-aa-link {
            text-align: center;
            margin-top: 12px;
        }
        
        .ptp-aa-link a {
            color: var(--gold);
            font-size: 12px;
            text-decoration: none;
        }
        
        .ptp-aa-link a:hover {
            text-decoration: underline;
        }
        </style>
        <?php
    }
    
    // =========================================
    // SCRIPTS - SMART & DEBOUNCED
    // =========================================
    
    public function output_scripts() {
        if (!is_page('ptp-checkout')) return;
        ?>
        <script id="ptp99-js">
        (function($){
            if (!$('#ptp99').length && !$('.ptp-upsell').length) return;
            
            var nonce = $('#ptp99').data('nonce') || '';
            var ajax = '<?php echo admin_url('admin-ajax.php'); ?>';
            var sibCount = 0;
            var debounce;
            
            function send(action, data, cb) {
                clearTimeout(debounce);
                debounce = setTimeout(function() {
                    $.post(ajax, {action: 'ptp99_update', n: nonce, a: action, d: data}, function(r) {
                        r.success && $('body').trigger('update_checkout');
                        cb && cb(r);
                    });
                }, 100);
            }
            
            // Card toggles
            $(document).on('click', '[data-toggle]', function(e) {
                e.preventDefault();
                var f = $(this).data('toggle');
                var $c = $(this).closest('.ptp-card');
                var opening = !$c.hasClass('open');
                
                $c.toggleClass('open');
                send('toggle', {f: f});
                
                if (f === 'add_sibling' && opening && sibCount === 0) addSib();
                if (f === 'is_team' && opening) $('[data-card="sibling"]').removeClass('open');
                if (f === 'add_sibling' && opening) $('[data-card="team"]').removeClass('open');
            });
            
            // Upsells (care bundle and jersey)
            $(document).on('click', '.ptp-care-btn, .ptp-jersey-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $p = $(this).closest('[data-u]');
                var u = $p.data('u');
                var on = $p.hasClass('on');
                
                $p.toggleClass('on');
                
                if (u === 'care_bundle') {
                    $(this).text(on ? '+ ADD CARE – $60' : '✓ ADDED – FULL DAY');
                } else if (u === 'jersey') {
                    $(this).text(on ? '+ ADD JERSEY' : '✓ ADDED');
                }
                
                send('toggle', {f: u});
            });
            
            // Allow clicking whole upsell card
            $(document).on('click', '.ptp-care, .ptp-jersey', function(e) {
                if (!$(e.target).is('button')) {
                    $(this).find('button').click();
                }
            });
            
            // Upgrade bumps (radio-style - only one can be selected)
            $(document).on('click', '.ptp-bump', function(e) {
                e.preventDefault();
                var $this = $(this);
                var pack = $this.data('upgrade');
                var wasChecked = $this.hasClass('checked');
                
                // Remove checked from all bumps
                $('.ptp-bump').removeClass('checked');
                $('.ptp-bump input').prop('checked', false);
                
                // Toggle current one
                if (!wasChecked) {
                    $this.addClass('checked');
                    $this.find('input').prop('checked', true);
                }
                
                // Send to server
                send('upgrade', {pack: wasChecked ? '' : pack});
            });
            
            // Referral
            $('#ptp-ref-btn').on('click', function() {
                var $b = $(this), code = $('#ptp-ref-input').val().trim().toUpperCase(), $m = $('#ptp-ref-msg');
                if (!code) { $m.removeClass('ok').addClass('err').text('Enter a code'); return; }
                
                $b.prop('disabled', true).text('...');
                $.post(ajax, {action: 'ptp99_update', n: nonce, a: 'referral', d: {code: code}}, function(r) {
                    $b.prop('disabled', false);
                    if (r.success) {
                        $m.removeClass('err').addClass('ok').text(r.data.msg);
                        $('#ptp-ref-input').prop('readonly', true);
                        $b.text('✓');
                        $('.ptp-card-ref').addClass('done');
                        $('body').trigger('update_checkout');
                    } else {
                        $m.removeClass('ok').addClass('err').text(r.data.msg);
                        $b.text('Apply');
                    }
                });
            });
            
            $('#ptp-ref-input').on('keypress', function(e) { e.which === 13 && (e.preventDefault(), $('#ptp-ref-btn').click()); });
            
            // Team size
            $('#ptp-team-size').on('change', function() { send('set', {f: 'team_size', v: $(this).val()}); });
            
            // Siblings
            function addSib() {
                sibCount++;
                var i = sibCount - 1;
                var h = '<div class="ptp-sib" data-i="'+sibCount+'"><div class="ptp-sib-head"><span>⚽ Camper #'+(sibCount+1)+'</span><button type="button" class="ptp-sib-rm">✕ Remove</button></div>' +
                    '<div class="ptp-row"><div class="ptp-field"><label>First Name</label><input type="text" name="sib['+i+'][fn]" required></div><div class="ptp-field"><label>Last Name</label><input type="text" name="sib['+i+'][ln]" required></div></div>' +
                    '<div class="ptp-row"><div class="ptp-field"><label>Date of Birth</label><input type="date" name="sib['+i+'][dob]" required></div><div class="ptp-field"><label>Shirt Size</label><select name="sib['+i+'][sz]" required><option value="">Select</option><option>Youth S</option><option>Youth M</option><option>Youth L</option><option>Adult S</option><option>Adult M</option><option>Adult L</option><option>Adult XL</option></select></div></div></div>';
                $('#ptp-sibs').append(h);
                updateSibs();
            }
            
            $(document).on('click', '.ptp-sib-rm', function() {
                $(this).closest('.ptp-sib').slideUp(150, function() {
                    $(this).remove();
                    renumberSibs();
                    updateSibs();
                    if (!$('.ptp-sib').length) {
                        $('[data-card="sibling"]').removeClass('open');
                        send('toggle', {f: 'add_sibling'});
                        sibCount = 0;
                    }
                });
            });
            
            function renumberSibs() {
                $('.ptp-sib').each(function(i) {
                    $(this).attr('data-i', i+1).find('.ptp-sib-head span').text('⚽ Camper #'+(i+2));
                    $(this).find('input,select').each(function() {
                        var n = $(this).attr('name');
                        n && $(this).attr('name', n.replace(/\[\d+\]/, '['+i+']'));
                    });
                });
                sibCount = $('.ptp-sib').length;
            }
            
            function updateSibs() {
                var c = 1 + $('.ptp-sib').length;
                $('#ptp-campers').val(c);
                // Calculate 10% savings per sibling based on cart subtotal
                var cartTotal = <?php echo ptp_cart() ? ptp_cart()->get_subtotal() : 840; ?>;
                var perCamper = cartTotal / c;
                var s = Math.round(perCamper * (c - 1) * 0.10);
                s > 0 ? ($('#ptp-sav-amt').text(s), $('#ptp-savings').slideDown(150)) : $('#ptp-savings').slideUp(150);
                send('set', {f: 'campers', v: c});
            }
        })(jQuery);
        </script>
        <?php
    }
}

PTP_Camp_Checkout_V99::instance();
