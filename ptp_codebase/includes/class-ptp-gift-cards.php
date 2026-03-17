<?php
/**
 * PTP Gift Cards System
 * Purchase gift cards for training sessions
 * Redeem at checkout
 * 
 * Version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_Gift_Cards {
    
    const CODE_PREFIX = 'PTP';
    const CODE_LENGTH = 12;
    const DEFAULT_EXPIRY_DAYS = 365;
    
    public static function init() {
        // AJAX handlers
        add_action('wp_ajax_ptp_purchase_gift_card', array(__CLASS__, 'ajax_purchase'));
        add_action('wp_ajax_nopriv_ptp_purchase_gift_card', array(__CLASS__, 'ajax_purchase'));
        add_action('wp_ajax_ptp_redeem_gift_card', array(__CLASS__, 'ajax_redeem'));
        add_action('wp_ajax_ptp_check_gift_card', array(__CLASS__, 'ajax_check_balance'));
        add_action('wp_ajax_nopriv_ptp_check_gift_card', array(__CLASS__, 'ajax_check_balance'));
        add_action('wp_ajax_ptp_apply_gift_card', array(__CLASS__, 'ajax_apply_to_booking'));
        
        // Shortcodes
        add_shortcode('ptp_gift_cards', array(__CLASS__, 'render_purchase_page'));
        add_shortcode('ptp_gift_card_balance', array(__CLASS__, 'render_balance_checker'));
        
        // Email hooks
        add_action('ptp_gift_card_purchased', array(__CLASS__, 'send_gift_email'), 10, 1);
    }
    
    /**
     * Create database table
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_gift_cards (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code varchar(20) NOT NULL,
            purchaser_id bigint(20) UNSIGNED DEFAULT NULL,
            purchaser_email varchar(255) NOT NULL,
            purchaser_name varchar(100) DEFAULT '',
            recipient_email varchar(255) DEFAULT '',
            recipient_name varchar(100) DEFAULT '',
            amount decimal(10,2) NOT NULL,
            balance decimal(10,2) NOT NULL,
            message text,
            design varchar(50) DEFAULT 'default',
            delivery_date date DEFAULT NULL,
            delivered tinyint(1) DEFAULT 0,
            delivered_at datetime DEFAULT NULL,
            redeemed_by bigint(20) UNSIGNED DEFAULT NULL,
            redeemed_at datetime DEFAULT NULL,
            stripe_payment_id varchar(255) DEFAULT '',
            status enum('pending','active','partially_used','used','expired','cancelled') DEFAULT 'pending',
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY purchaser_id (purchaser_id),
            KEY recipient_email (recipient_email),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        dbDelta($sql);
        
        // Gift card usage log
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_gift_card_usage (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            gift_card_id bigint(20) UNSIGNED NOT NULL,
            booking_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            amount_used decimal(10,2) NOT NULL,
            balance_after decimal(10,2) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY gift_card_id (gift_card_id),
            KEY booking_id (booking_id)
        ) $charset_collate;";
        dbDelta($sql);
    }
    
    /**
     * Generate unique gift card code
     */
    public static function generate_code() {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed confusing chars
        $code = self::CODE_PREFIX;
        
        for ($i = 0; $i < self::CODE_LENGTH - strlen(self::CODE_PREFIX); $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Add dashes for readability: PTP-XXXX-XXXX
        $code = substr($code, 0, 3) . '-' . substr($code, 3, 4) . '-' . substr($code, 7, 4);
        
        // Verify uniqueness
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_gift_cards WHERE code = %s",
            $code
        ));
        
        if ($exists) {
            return self::generate_code(); // Recursively generate new code
        }
        
        return $code;
    }
    
    /**
     * Purchase a gift card
     */
    public static function purchase($data) {
        global $wpdb;
        
        // Validate
        $amount = floatval($data['amount'] ?? 0);
        if ($amount < 25 || $amount > 500) {
            return new WP_Error('invalid_amount', 'Gift card amount must be between $25 and $500');
        }
        
        $purchaser_email = sanitize_email($data['purchaser_email'] ?? '');
        if (!is_email($purchaser_email)) {
            return new WP_Error('invalid_email', 'Please enter a valid email address');
        }
        
        // Generate code
        $code = self::generate_code();
        
        // Calculate expiry
        $expiry_days = intval($data['expiry_days'] ?? self::DEFAULT_EXPIRY_DAYS);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiry_days} days"));
        
        // Determine delivery
        $delivery_date = null;
        $delivered = 0;
        if (!empty($data['send_now']) || empty($data['delivery_date'])) {
            $delivered = 1; // Will be sent immediately after payment
        } else {
            $delivery_date = sanitize_text_field($data['delivery_date']);
        }
        
        // Insert record
        $result = $wpdb->insert(
            $wpdb->prefix . 'ptp_gift_cards',
            array(
                'code' => $code,
                'purchaser_id' => get_current_user_id() ?: null,
                'purchaser_email' => $purchaser_email,
                'purchaser_name' => sanitize_text_field($data['purchaser_name'] ?? ''),
                'recipient_email' => sanitize_email($data['recipient_email'] ?? ''),
                'recipient_name' => sanitize_text_field($data['recipient_name'] ?? ''),
                'amount' => $amount,
                'balance' => $amount,
                'message' => sanitize_textarea_field($data['message'] ?? ''),
                'design' => sanitize_text_field($data['design'] ?? 'default'),
                'delivery_date' => $delivery_date,
                'delivered' => $delivered,
                'status' => 'pending', // Pending until payment confirmed
                'expires_at' => $expires_at,
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Failed to create gift card');
        }
        
        $gift_card_id = $wpdb->insert_id;
        
        return array(
            'gift_card_id' => $gift_card_id,
            'code' => $code,
            'amount' => $amount,
        );
    }
    
    /**
     * Activate gift card after payment
     */
    public static function activate($gift_card_id, $stripe_payment_id = '') {
        global $wpdb;
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_gift_cards',
            array(
                'status' => 'active',
                'stripe_payment_id' => $stripe_payment_id,
            ),
            array('id' => $gift_card_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result) {
            // Trigger delivery
            do_action('ptp_gift_card_purchased', $gift_card_id);
        }
        
        return $result;
    }
    
    /**
     * Get gift card by code
     */
    public static function get_by_code($code) {
        global $wpdb;
        
        // Normalize code (remove dashes, uppercase)
        $code = strtoupper(str_replace('-', '', $code));
        // Re-add dashes for lookup
        $formatted_code = substr($code, 0, 3) . '-' . substr($code, 3, 4) . '-' . substr($code, 7, 4);
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_gift_cards WHERE code = %s OR code = %s",
            $code, $formatted_code
        ));
    }
    
    /**
     * Check gift card balance and validity
     */
    public static function check_balance($code) {
        $card = self::get_by_code($code);
        
        if (!$card) {
            return new WP_Error('not_found', 'Gift card not found');
        }
        
        if ($card->status === 'expired' || strtotime($card->expires_at) < time()) {
            return new WP_Error('expired', 'This gift card has expired');
        }
        
        if ($card->status === 'cancelled') {
            return new WP_Error('cancelled', 'This gift card has been cancelled');
        }
        
        if ($card->status === 'used' || $card->balance <= 0) {
            return new WP_Error('depleted', 'This gift card has been fully used');
        }
        
        if ($card->status === 'pending') {
            return new WP_Error('pending', 'This gift card has not been activated yet');
        }
        
        return array(
            'code' => $card->code,
            'balance' => floatval($card->balance),
            'original_amount' => floatval($card->amount),
            'expires_at' => $card->expires_at,
            'status' => $card->status,
        );
    }
    
    /**
     * Apply gift card to a booking
     * Returns amount applied
     */
    public static function apply_to_booking($code, $booking_id, $amount_to_apply, $user_id) {
        global $wpdb;
        
        // Validate card
        $balance_check = self::check_balance($code);
        if (is_wp_error($balance_check)) {
            return $balance_check;
        }
        
        $card = self::get_by_code($code);
        $available = floatval($card->balance);
        $to_apply = min($available, floatval($amount_to_apply));
        
        if ($to_apply <= 0) {
            return new WP_Error('no_balance', 'No balance available on this gift card');
        }
        
        // Calculate new balance
        $new_balance = $available - $to_apply;
        $new_status = $new_balance > 0 ? 'partially_used' : 'used';
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Update gift card
        $updated = $wpdb->update(
            $wpdb->prefix . 'ptp_gift_cards',
            array(
                'balance' => $new_balance,
                'status' => $new_status,
                'redeemed_by' => $card->redeemed_by ?: $user_id,
                'redeemed_at' => $card->redeemed_at ?: current_time('mysql'),
            ),
            array('id' => $card->id),
            array('%f', '%s', '%d', '%s'),
            array('%d')
        );
        
        if (!$updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('update_failed', 'Failed to apply gift card');
        }
        
        // Log usage
        $logged = $wpdb->insert(
            $wpdb->prefix . 'ptp_gift_card_usage',
            array(
                'gift_card_id' => $card->id,
                'booking_id' => $booking_id,
                'user_id' => $user_id,
                'amount_used' => $to_apply,
                'balance_after' => $new_balance,
            ),
            array('%d', '%d', '%d', '%f', '%f')
        );
        
        if (!$logged) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('log_failed', 'Failed to log gift card usage');
        }
        
        $wpdb->query('COMMIT');
        
        return array(
            'amount_applied' => $to_apply,
            'remaining_balance' => $new_balance,
            'card_status' => $new_status,
        );
    }
    
    /**
     * Send gift card email
     */
    public static function send_gift_email($gift_card_id) {
        global $wpdb;
        
        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_gift_cards WHERE id = %d",
            $gift_card_id
        ));
        
        if (!$card || empty($card->recipient_email)) {
            // No recipient - send confirmation to purchaser only
            return;
        }
        
        // Check if scheduled for later delivery
        if ($card->delivery_date && strtotime($card->delivery_date) > time()) {
            // Schedule for later
            wp_schedule_single_event(
                strtotime($card->delivery_date . ' 09:00:00'),
                'ptp_deliver_gift_card',
                array($gift_card_id)
            );
            return;
        }
        
        // Send now
        if (class_exists('PTP_Email')) {
            PTP_Email::send_gift_card_email($card);
            
            // Mark as delivered
            $wpdb->update(
                $wpdb->prefix . 'ptp_gift_cards',
                array(
                    'delivered' => 1,
                    'delivered_at' => current_time('mysql'),
                ),
                array('id' => $gift_card_id)
            );
        }
    }
    
    /**
     * AJAX: Purchase gift card
     */
    public static function ajax_purchase() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $result = self::purchase($_POST);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Create Stripe PaymentIntent
        if (class_exists('PTP_Stripe') && PTP_Stripe::is_enabled()) {
            $amount = floatval($_POST['amount']);
            
            $gc_email = sanitize_email($_POST['sender_email'] ?? $_POST['email'] ?? '');
            $gc_name = sanitize_text_field($_POST['sender_name'] ?? $_POST['name'] ?? '');
            
            $intent = PTP_Stripe::create_payment_intent($amount, array(
                'gift_card_id' => $result['gift_card_id'],
                'type' => 'gift_card',
                'customer_email' => $gc_email,
                'customer_name' => $gc_name,
            ));
            
            if (is_wp_error($intent)) {
                wp_send_json_error(array('message' => $intent->get_error_message()));
            }
            
            $result['client_secret'] = $intent['client_secret'];
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Check gift card balance
     */
    public static function ajax_check_balance() {
        $code = sanitize_text_field($_POST['code'] ?? $_GET['code'] ?? '');
        
        if (empty($code)) {
            wp_send_json_error(array('message' => 'Please enter a gift card code'));
        }
        
        $result = self::check_balance($code);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Apply gift card to booking
     */
    public static function ajax_apply_to_booking() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to use a gift card'));
        }
        
        $code = sanitize_text_field($_POST['code'] ?? '');
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        
        if (empty($code) || !$booking_id || $amount <= 0) {
            wp_send_json_error(array('message' => 'Invalid request'));
        }
        
        $result = self::apply_to_booking($code, $booking_id, $amount, get_current_user_id());
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Render gift card purchase page
     */
    public static function render_purchase_page($atts = array()) {
        ob_start();
        ?>
        <div class="ptp-gift-cards" style="max-width:600px;margin:0 auto;font-family:'Inter',sans-serif;">
            <div style="text-align:center;margin-bottom:32px;">
                <h2 style="font-family:'Oswald',sans-serif;font-size:32px;margin:0 0 8px;">GIVE THE GIFT OF TRAINING</h2>
                <p style="color:#666;margin:0;">Perfect for birthdays, holidays, or celebrating achievements</p>
            </div>
            
            <form id="ptp-gift-card-form" style="background:#f9f9f9;padding:24px;border:2px solid #e5e5e5;">
                <!-- Amount Selection -->
                <div style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:12px;text-transform:uppercase;font-size:12px;letter-spacing:0.5px;">SELECT AMOUNT</label>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;">
                        <?php foreach (array(50, 100, 150, 200) as $amt): ?>
                        <button type="button" class="ptp-amount-btn" data-amount="<?php echo $amt; ?>" style="
                            padding:16px;
                            border:2px solid #e5e5e5;
                            background:#fff;
                            font-size:18px;
                            font-weight:600;
                            cursor:pointer;
                            transition:all 0.2s;
                        ">$<?php echo $amt; ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:12px;">
                        <label style="font-size:13px;color:#666;">Or enter custom amount:</label>
                        <input type="number" id="ptp-custom-amount" min="25" max="500" placeholder="$25 - $500" style="
                            width:100%;
                            padding:12px;
                            border:2px solid #e5e5e5;
                            font-size:16px;
                            margin-top:4px;
                        ">
                    </div>
                    <input type="hidden" name="amount" id="ptp-gift-amount" value="">
                </div>
                
                <!-- Recipient Info -->
                <div style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:12px;text-transform:uppercase;font-size:12px;letter-spacing:0.5px;">RECIPIENT</label>
                    <input type="text" name="recipient_name" placeholder="Recipient's Name" required style="
                        width:100%;
                        padding:12px;
                        border:2px solid #e5e5e5;
                        font-size:16px;
                        margin-bottom:8px;
                    ">
                    <input type="email" name="recipient_email" placeholder="Recipient's Email" required style="
                        width:100%;
                        padding:12px;
                        border:2px solid #e5e5e5;
                        font-size:16px;
                    ">
                </div>
                
                <!-- Personal Message -->
                <div style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:12px;text-transform:uppercase;font-size:12px;letter-spacing:0.5px;">PERSONAL MESSAGE (OPTIONAL)</label>
                    <textarea name="message" rows="3" placeholder="Add a personal message..." style="
                        width:100%;
                        padding:12px;
                        border:2px solid #e5e5e5;
                        font-size:16px;
                        resize:vertical;
                    "></textarea>
                </div>
                
                <!-- Your Info -->
                <div style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:12px;text-transform:uppercase;font-size:12px;letter-spacing:0.5px;">YOUR INFO</label>
                    <input type="text" name="purchaser_name" placeholder="Your Name" required style="
                        width:100%;
                        padding:12px;
                        border:2px solid #e5e5e5;
                        font-size:16px;
                        margin-bottom:8px;
                    ">
                    <input type="email" name="purchaser_email" placeholder="Your Email" required style="
                        width:100%;
                        padding:12px;
                        border:2px solid #e5e5e5;
                        font-size:16px;
                    ">
                </div>
                
                <!-- Delivery -->
                <div style="margin-bottom:24px;">
                    <label style="display:block;font-weight:600;margin-bottom:12px;text-transform:uppercase;font-size:12px;letter-spacing:0.5px;">DELIVERY</label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;">
                        <input type="radio" name="delivery" value="now" checked> Send immediately
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="radio" name="delivery" value="later"> Schedule for later:
                        <input type="date" name="delivery_date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" style="padding:8px;border:1px solid #ddd;">
                    </label>
                </div>
                
                <!-- Submit -->
                <button type="submit" id="ptp-gift-submit" style="
                    width:100%;
                    padding:16px;
                    background:#FCB900;
                    color:#0A0A0A;
                    border:none;
                    font-family:'Oswald',sans-serif;
                    font-size:18px;
                    font-weight:600;
                    text-transform:uppercase;
                    cursor:pointer;
                    transition:background 0.2s;
                ">PURCHASE GIFT CARD</button>
                
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('ptp_nonce'); ?>">
            </form>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const amountBtns = document.querySelectorAll('.ptp-amount-btn');
            const customInput = document.getElementById('ptp-custom-amount');
            const hiddenAmount = document.getElementById('ptp-gift-amount');
            
            amountBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    amountBtns.forEach(b => b.style.borderColor = '#e5e5e5');
                    this.style.borderColor = '#FCB900';
                    this.style.background = '#FCB900';
                    hiddenAmount.value = this.dataset.amount;
                    customInput.value = '';
                });
            });
            
            customInput.addEventListener('input', function() {
                amountBtns.forEach(b => {
                    b.style.borderColor = '#e5e5e5';
                    b.style.background = '#fff';
                });
                hiddenAmount.value = this.value;
            });
            
            document.getElementById('ptp-gift-card-form').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const amount = hiddenAmount.value;
                if (!amount || amount < 25) {
                    alert('Please select an amount of at least $25');
                    return;
                }
                
                const btn = document.getElementById('ptp-gift-submit');
                btn.disabled = true;
                btn.textContent = 'PROCESSING...';
                
                const formData = new FormData(this);
                formData.append('action', 'ptp_purchase_gift_card');
                formData.append('send_now', document.querySelector('input[name="delivery"]:checked').value === 'now' ? '1' : '0');
                
                try {
                    const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // TODO: Handle Stripe payment
                        alert('Gift card created! Code: ' + data.data.code);
                        window.location.reload();
                    } else {
                        alert(data.data.message || 'Something went wrong');
                    }
                } catch (err) {
                    alert('Error: ' + err.message);
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'PURCHASE GIFT CARD';
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render balance checker widget
     */
    public static function render_balance_checker($atts = array()) {
        ob_start();
        ?>
        <div class="ptp-gift-balance-checker" style="max-width:400px;font-family:'Inter',sans-serif;">
            <form id="ptp-balance-form" style="display:flex;gap:8px;">
                <input type="text" id="ptp-balance-code" placeholder="Enter gift card code" style="
                    flex:1;
                    padding:12px;
                    border:2px solid #e5e5e5;
                    font-size:16px;
                    text-transform:uppercase;
                ">
                <button type="submit" style="
                    padding:12px 24px;
                    background:#FCB900;
                    color:#0A0A0A;
                    border:none;
                    font-weight:600;
                    cursor:pointer;
                ">CHECK</button>
            </form>
            <div id="ptp-balance-result" style="margin-top:16px;display:none;"></div>
        </div>
        
        <script>
        document.getElementById('ptp-balance-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const code = document.getElementById('ptp-balance-code').value;
            const resultDiv = document.getElementById('ptp-balance-result');
            
            const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=ptp_check_gift_card&code=' + encodeURIComponent(code));
            const data = await response.json();
            
            resultDiv.style.display = 'block';
            
            if (data.success) {
                resultDiv.innerHTML = '<div style="padding:16px;background:#22C55E20;border:2px solid #22C55E;"><strong>Balance: $' + data.data.balance.toFixed(2) + '</strong><br><small>Expires: ' + new Date(data.data.expires_at).toLocaleDateString() + '</small></div>';
            } else {
                resultDiv.innerHTML = '<div style="padding:16px;background:#EF444420;border:2px solid #EF4444;">' + data.data.message + '</div>';
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

// Initialize
PTP_Gift_Cards::init();
