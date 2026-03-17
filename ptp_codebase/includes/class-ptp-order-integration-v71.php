<?php
/**
 * PTP PTP Native Order Integration - v71
 * Handles order metadata, custom fields, and order display
 */

defined('ABSPATH') || exit;

class PTP_Order_Integration_V71 {

    public function __construct() {
        // v148: Only register PTP Native hooks if WC is active
        if (!false) {
            // Register native PTP order hooks instead
            add_action('ptp_order_created', array($this, 'save_checkout_fields_to_native_order'), 10, 2);
            add_action('ptp_order_completed', array($this, 'handle_order_completed'));
            add_action('ptp_order_processing', array($this, 'handle_order_processing'));
            return;
        }

        // Save custom checkout fields to order meta
        // REMOVED: add_action('ptp-native_checkout_create_order', array($this, 'save_checkout_fields_to_order'), 10, 2);

        // Display custom fields on order details (admin)
        // REMOVED: add_action('ptp-native_admin_order_data_after_billing_address', array($this, 'display_admin_order_meta'));

        // Display on thank you page
        // REMOVED: add_action('ptp-native_thankyou', array($this, 'display_order_camp_details'), 5);
        // REMOVED: add_action('ptp-native_order_details_after_order_table', array($this, 'display_order_camp_details'));

        // Display on emails
        // REMOVED: add_action('ptp-native_email_after_order_table', array($this, 'display_email_camp_details'), 10, 4);

        // Custom order columns
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_columns'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_columns'), 10, 2);

        // HPOS compatibility
        add_filter('manage_ptp-native_page_wc-orders_columns', array($this, 'add_order_columns'));
        add_action('manage_ptp-native_page_wc-orders_custom_column', array($this, 'render_order_columns_hpos'), 10, 2);

        // Save player info from checkout
        // REMOVED: add_action('ptp-native_checkout_create_order_line_item', array($this, 'save_player_info_to_item'), 10, 4);

        // Display player info on order items
        // REMOVED: add_filter('ptp-native_order_item_display_meta_key', array($this, 'format_item_meta_key'), 10, 3);
        // REMOVED: add_filter('ptp-native_order_item_display_meta_value', array($this, 'format_item_meta_value'), 10, 3);

        // REST API support for orders
        // REMOVED: add_filter('ptp-native_rest_prepare_shop_order_object', array($this, 'add_order_rest_data'), 10, 3);

        // Order status change hooks for notifications
        // REMOVED: add_action('ptp-native_order_status_completed', array($this, 'handle_order_completed'));
        // REMOVED: add_action('ptp-native_order_status_processing', array($this, 'handle_order_processing'));
    }

    /**
     * v148: Save checkout fields to native PTP order
     */
    public function save_checkout_fields_to_native_order($order_id, $order_data) {
        if (function_exists('ptp_get_order')) {
            $order = ptp_get_order($order_id);
            if ($order) {
                foreach ($order_data as $key => $value) {
                    $order->update_meta_data($key, $value);
                }
                $order->save();
            }
        }
    }
    
    /**
     * Save custom checkout fields to order meta
     */
    public function save_checkout_fields_to_order($order, $data) {
        // Player information from checkout
        if (!empty($_POST['player_first_name'])) {
            $order->update_meta_data('_player_first_name', sanitize_text_field($_POST['player_first_name']));
        }
        if (!empty($_POST['player_last_name'])) {
            $order->update_meta_data('_player_last_name', sanitize_text_field($_POST['player_last_name']));
        }
        if (!empty($_POST['player_age'])) {
            $order->update_meta_data('_player_age', absint($_POST['player_age']));
        }
        if (!empty($_POST['player_dob'])) {
            $order->update_meta_data('_player_dob', sanitize_text_field($_POST['player_dob']));
        }
        if (!empty($_POST['player_gender'])) {
            $order->update_meta_data('_player_gender', sanitize_text_field($_POST['player_gender']));
        }
        if (!empty($_POST['player_team'])) {
            $order->update_meta_data('_player_team', sanitize_text_field($_POST['player_team']));
        }
        if (!empty($_POST['player_position'])) {
            $order->update_meta_data('_player_position', sanitize_text_field($_POST['player_position']));
        }
        if (!empty($_POST['skill_level'])) {
            $order->update_meta_data('_skill_level', sanitize_text_field($_POST['skill_level']));
        }
        
        // Parent/Guardian info
        if (!empty($_POST['parent_phone'])) {
            $order->update_meta_data('_parent_phone', sanitize_text_field($_POST['parent_phone']));
        }
        
        // Emergency contact
        if (!empty($_POST['emergency_contact_name'])) {
            $order->update_meta_data('_emergency_contact_name', sanitize_text_field($_POST['emergency_contact_name']));
        }
        if (!empty($_POST['emergency_contact_phone'])) {
            $order->update_meta_data('_emergency_contact_phone', sanitize_text_field($_POST['emergency_contact_phone']));
        }
        
        // Medical info
        if (!empty($_POST['medical_conditions'])) {
            $order->update_meta_data('_medical_conditions', sanitize_textarea_field($_POST['medical_conditions']));
        }
        if (!empty($_POST['allergies'])) {
            $order->update_meta_data('_allergies', sanitize_textarea_field($_POST['allergies']));
        }
        
        // Waiver acceptance
        if (!empty($_POST['waiver_accepted'])) {
            $order->update_meta_data('_waiver_accepted', 'yes');
            $order->update_meta_data('_waiver_accepted_date', current_time('mysql'));
            $order->update_meta_data('_waiver_accepted_ip', $this->get_client_ip());
        }
        
        // Photo/video release
        if (!empty($_POST['photo_release'])) {
            $order->update_meta_data('_photo_release', sanitize_text_field($_POST['photo_release']));
        }
        
        // How did you hear about us
        if (!empty($_POST['referral_source'])) {
            $order->update_meta_data('_referral_source', sanitize_text_field($_POST['referral_source']));
        }
        
        // Special requests/notes
        if (!empty($_POST['special_requests'])) {
            $order->update_meta_data('_special_requests', sanitize_textarea_field($_POST['special_requests']));
        }
        
        // Camp/Clinic specific data extracted from cart items
        $camp_details = $this->extract_camp_details_from_cart();
        if (!empty($camp_details)) {
            $order->update_meta_data('_ptp_camp_details', $camp_details);
        }
        
        // Training session specific data
        if (!empty($_POST['training_session_id'])) {
            $order->update_meta_data('_training_session_id', absint($_POST['training_session_id']));
        }
        if (!empty($_POST['trainer_id'])) {
            $order->update_meta_data('_trainer_id', absint($_POST['trainer_id']));
        }
    }
    
    /**
     * Extract camp details from cart items
     */
    private function extract_camp_details_from_cart() {
        $cart = ptp_cart();
        if (!$cart) return array();
        
        $camp_details = array();
        
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $product = ptp_get_camp_product($product_id);
            
            if (!$product) continue;
            
            $detail = array(
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'quantity' => $cart_item['quantity'],
            );
            
            // Get camp/clinic metadata from product
            $camp_date = get_post_meta($product_id, '_camp_date', true);
            $camp_time = get_post_meta($product_id, '_camp_time', true);
            $camp_location = get_post_meta($product_id, '_camp_location', true);
            $camp_address = get_post_meta($product_id, '_camp_address', true);
            $trainer_name = get_post_meta($product_id, '_trainer_name', true);
            $age_group = get_post_meta($product_id, '_age_group', true);
            
            if ($camp_date) $detail['date'] = $camp_date;
            if ($camp_time) $detail['time'] = $camp_time;
            if ($camp_location) $detail['location'] = $camp_location;
            if ($camp_address) $detail['address'] = $camp_address;
            if ($trainer_name) $detail['trainer'] = $trainer_name;
            if ($age_group) $detail['age_group'] = $age_group;
            
            // Check for variation data
            if (!empty($cart_item['variation_id'])) {
                $variation = ptp_get_camp_product($cart_item['variation_id']);
                if ($variation) {
                    $detail['variation_id'] = $cart_item['variation_id'];
                    $detail['variation_name'] = $variation->get_name();
                    
                    // Get variation specific dates/times
                    $var_date = get_post_meta($cart_item['variation_id'], '_variation_date', true);
                    $var_time = get_post_meta($cart_item['variation_id'], '_variation_time', true);
                    
                    if ($var_date) $detail['date'] = $var_date;
                    if ($var_time) $detail['time'] = $var_time;
                }
            }
            
            // Get custom cart item data
            if (!empty($cart_item['camp_date'])) {
                $detail['date'] = $cart_item['camp_date'];
            }
            if (!empty($cart_item['camp_time'])) {
                $detail['time'] = $cart_item['camp_time'];
            }
            if (!empty($cart_item['camp_location'])) {
                $detail['location'] = $cart_item['camp_location'];
            }
            
            $camp_details[] = $detail;
        }
        
        return $camp_details;
    }
    
    /**
     * Display admin order meta
     */
    public function display_admin_order_meta($order) {
        $player_first = $order->get_meta('_player_first_name');
        $player_last = $order->get_meta('_player_last_name');
        $player_age = $order->get_meta('_player_age');
        $player_team = $order->get_meta('_player_team');
        $skill_level = $order->get_meta('_skill_level');
        $emergency_name = $order->get_meta('_emergency_contact_name');
        $emergency_phone = $order->get_meta('_emergency_contact_phone');
        $medical = $order->get_meta('_medical_conditions');
        $allergies = $order->get_meta('_allergies');
        
        if ($player_first || $player_last) {
            echo '<div class="ptp-admin-order-meta" style="margin-top: 20px; padding: 15px; background: #f8f8f8; border-left: 4px solid #FCB900;">';
            echo '<h3 style="margin-top: 0; color: #0A0A0A;">Player Information</h3>';
            
            echo '<p><strong>Player Name:</strong> ' . esc_html($player_first . ' ' . $player_last) . '</p>';
            
            if ($player_age) {
                echo '<p><strong>Age:</strong> ' . esc_html($player_age) . '</p>';
            }
            if ($player_team) {
                echo '<p><strong>Team:</strong> ' . esc_html($player_team) . '</p>';
            }
            if ($skill_level) {
                echo '<p><strong>Skill Level:</strong> ' . esc_html(ucfirst($skill_level)) . '</p>';
            }
            
            if ($emergency_name || $emergency_phone) {
                echo '<h4 style="margin-bottom: 5px;">Emergency Contact</h4>';
                echo '<p>' . esc_html($emergency_name) . ' - ' . esc_html($emergency_phone) . '</p>';
            }
            
            if ($medical) {
                echo '<h4 style="margin-bottom: 5px;">Medical Conditions</h4>';
                echo '<p>' . esc_html($medical) . '</p>';
            }
            
            if ($allergies) {
                echo '<h4 style="margin-bottom: 5px;">Allergies</h4>';
                echo '<p>' . esc_html($allergies) . '</p>';
            }
            
            echo '</div>';
        }
        
        // Display camp details
        $camp_details = $order->get_meta('_ptp_camp_details');
        if (!empty($camp_details) && is_array($camp_details)) {
            echo '<div class="ptp-admin-camp-details" style="margin-top: 15px; padding: 15px; background: #fff; border: 1px solid #ddd;">';
            echo '<h3 style="margin-top: 0;">Camp/Clinic Details</h3>';
            
            foreach ($camp_details as $camp) {
                echo '<div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee;">';
                echo '<strong>' . esc_html($camp['product_name']) . '</strong><br>';
                
                if (!empty($camp['date'])) {
                    echo '📅 ' . esc_html(date('l, F j, Y', strtotime($camp['date']))) . '<br>';
                }
                if (!empty($camp['time'])) {
                    echo '🕐 ' . esc_html($camp['time']) . '<br>';
                }
                if (!empty($camp['location'])) {
                    echo '📍 ' . esc_html($camp['location']) . '<br>';
                }
                if (!empty($camp['trainer'])) {
                    echo '👤 Trainer: ' . esc_html($camp['trainer']) . '<br>';
                }
                
                echo '</div>';
            }
            
            echo '</div>';
        }
    }
    
    /**
     * Display camp details on thank you page and order details
     */
    public function display_order_camp_details($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return;
        
        $camp_details = $order->get_meta('_ptp_camp_details');
        
        // If no stored camp details, extract from order items
        if (empty($camp_details)) {
            $camp_details = $this->extract_camp_details_from_order($order);
        }
        
        if (empty($camp_details)) return;
        
        ?>
        <section class="ptp-order-camp-details">
            <h2 class="ptp-native-order-details__title" style="font-family: 'Oswald', sans-serif; text-transform: uppercase;">Camp/Clinic Details</h2>
            
            <?php foreach ($camp_details as $camp): ?>
                <div class="ptp-camp-detail-card" style="background: #fff; border: 2px solid #0A0A0A; padding: 20px; margin-bottom: 15px;">
                    <h3 style="margin-top: 0; font-family: 'Oswald', sans-serif; font-size: 18px; color: #0A0A0A;">
                        <?php echo esc_html($camp['product_name']); ?>
                    </h3>
                    
                    <?php if (!empty($camp['date'])): ?>
                        <p style="margin: 8px 0; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 18px;">📅</span>
                            <span><?php echo esc_html(date('l, F j, Y', strtotime($camp['date']))); ?></span>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($camp['time'])): ?>
                        <p style="margin: 8px 0; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 18px;">🕐</span>
                            <span><?php echo esc_html($camp['time']); ?></span>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($camp['location'])): ?>
                        <p style="margin: 8px 0; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 18px;">📍</span>
                            <span><?php echo esc_html($camp['location']); ?></span>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($camp['address'])): ?>
                        <p style="margin: 8px 0; padding-left: 26px; color: #666; font-size: 14px;">
                            <?php echo esc_html($camp['address']); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($camp['trainer'])): ?>
                        <p style="margin: 8px 0; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 18px;">👤</span>
                            <span>Trainer: <?php echo esc_html($camp['trainer']); ?></span>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        
        <?php
        // Display player info if available
        $player_first = $order->get_meta('_player_first_name');
        $player_last = $order->get_meta('_player_last_name');
        
        if ($player_first || $player_last):
        ?>
        <section class="ptp-order-player-details" style="margin-top: 20px;">
            <h2 class="ptp-native-order-details__title" style="font-family: 'Oswald', sans-serif; text-transform: uppercase;">Player Information</h2>
            
            <div style="background: #f9f9f9; border: 2px solid #0A0A0A; padding: 20px;">
                <p><strong>Player:</strong> <?php echo esc_html($player_first . ' ' . $player_last); ?></p>
                
                <?php if ($age = $order->get_meta('_player_age')): ?>
                    <p><strong>Age:</strong> <?php echo esc_html($age); ?></p>
                <?php endif; ?>
                
                <?php if ($team = $order->get_meta('_player_team')): ?>
                    <p><strong>Team:</strong> <?php echo esc_html($team); ?></p>
                <?php endif; ?>
                
                <?php if ($skill = $order->get_meta('_skill_level')): ?>
                    <p><strong>Skill Level:</strong> <?php echo esc_html(ucfirst($skill)); ?></p>
                <?php endif; ?>
            </div>
        </section>
        <?php
        endif;
    }
    
    /**
     * Extract camp details from order items
     */
    private function extract_camp_details_from_order($order) {
        $camp_details = array();
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $product = $item->get_product();
            
            if (!$product) continue;
            
            $detail = array(
                'product_id' => $product_id,
                'product_name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
            );
            
            // Get from product meta
            $camp_date = get_post_meta($product_id, '_camp_date', true);
            $camp_time = get_post_meta($product_id, '_camp_time', true);
            $camp_location = get_post_meta($product_id, '_camp_location', true);
            $camp_address = get_post_meta($product_id, '_camp_address', true);
            $trainer_name = get_post_meta($product_id, '_trainer_name', true);
            
            // Also check for ACF fields if ACF is active
            if (function_exists('get_field')) {
                if (!$camp_date) $camp_date = get_field('camp_date', $product_id);
                if (!$camp_time) $camp_time = get_field('camp_time', $product_id);
                if (!$camp_location) $camp_location = get_field('camp_location', $product_id);
                if (!$camp_address) $camp_address = get_field('camp_address', $product_id);
                if (!$trainer_name) $trainer_name = get_field('trainer_name', $product_id);
            }
            
            // Parse date from product name if not found (e.g., "Camp Name - Jan 2 | 6:30pm-9:00pm")
            if (!$camp_date || !$camp_time) {
                $parsed = $this->parse_date_from_product_name($item->get_name());
                if (!$camp_date && !empty($parsed['date'])) {
                    $camp_date = $parsed['date'];
                }
                if (!$camp_time && !empty($parsed['time'])) {
                    $camp_time = $parsed['time'];
                }
            }
            
            // Get from item meta
            $item_camp_date = $item->get_meta('_camp_date');
            $item_camp_time = $item->get_meta('_camp_time');
            $item_camp_location = $item->get_meta('_camp_location');
            
            if ($item_camp_date) $camp_date = $item_camp_date;
            if ($item_camp_time) $camp_time = $item_camp_time;
            if ($item_camp_location) $camp_location = $item_camp_location;
            
            if ($camp_date) $detail['date'] = $camp_date;
            if ($camp_time) $detail['time'] = $camp_time;
            if ($camp_location) $detail['location'] = $camp_location;
            if ($camp_address) $detail['address'] = $camp_address;
            if ($trainer_name) $detail['trainer'] = $trainer_name;
            
            $camp_details[] = $detail;
        }
        
        return $camp_details;
    }
    
    /**
     * Parse date and time from product name
     */
    private function parse_date_from_product_name($name) {
        $result = array('date' => '', 'time' => '');
        
        // Pattern: "- Jan 2 | 6:30pm-9:00pm" or "- January 2, 2026 | 6:30pm-9:00pm"
        if (preg_match('/- ([A-Za-z]+ \d{1,2}(?:, \d{4})?)\s*\|\s*(\d{1,2}:\d{2}[ap]m-\d{1,2}:\d{2}[ap]m)/i', $name, $matches)) {
            $date_str = $matches[1];
            $time_str = $matches[2];
            
            // Add year if missing
            if (!preg_match('/\d{4}/', $date_str)) {
                $date_str .= ', ' . date('Y');
                // If the date has passed this year, use next year
                $parsed_date = strtotime($date_str);
                if ($parsed_date < time()) {
                    $date_str = preg_replace('/\d{4}$/', date('Y') + 1, $date_str);
                }
            }
            
            $result['date'] = date('Y-m-d', strtotime($date_str));
            $result['time'] = $time_str;
        }
        
        return $result;
    }
    
    /**
     * Display camp details in emails
     */
    public function display_email_camp_details($order, $sent_to_admin, $plain_text, $email) {
        $camp_details = $order->get_meta('_ptp_camp_details');
        
        if (empty($camp_details)) {
            $camp_details = $this->extract_camp_details_from_order($order);
        }
        
        if (empty($camp_details)) return;
        
        if ($plain_text) {
            echo "\n\n=== CAMP/CLINIC DETAILS ===\n\n";
            foreach ($camp_details as $camp) {
                echo $camp['product_name'] . "\n";
                if (!empty($camp['date'])) {
                    echo "Date: " . date('l, F j, Y', strtotime($camp['date'])) . "\n";
                }
                if (!empty($camp['time'])) {
                    echo "Time: " . $camp['time'] . "\n";
                }
                if (!empty($camp['location'])) {
                    echo "Location: " . $camp['location'] . "\n";
                }
                echo "\n";
            }
        } else {
            ?>
            <h2 style="font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 18px; color: #0A0A0A; margin-top: 30px;">Camp/Clinic Details</h2>
            
            <table cellspacing="0" cellpadding="10" style="width: 100%; border: 2px solid #0A0A0A; margin-bottom: 20px;">
                <?php foreach ($camp_details as $camp): ?>
                <tr>
                    <td style="background: #FCB900; border-bottom: 1px solid #0A0A0A;">
                        <strong style="color: #0A0A0A;"><?php echo esc_html($camp['product_name']); ?></strong>
                    </td>
                </tr>
                <tr>
                    <td style="background: #ffffff;">
                        <?php if (!empty($camp['date'])): ?>
                            <p style="margin: 5px 0;">📅 <?php echo esc_html(date('l, F j, Y', strtotime($camp['date']))); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($camp['time'])): ?>
                            <p style="margin: 5px 0;">🕐 <?php echo esc_html($camp['time']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($camp['location'])): ?>
                            <p style="margin: 5px 0;">📍 <?php echo esc_html($camp['location']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php
        }
    }
    
    /**
     * Save player info to order line item
     */
    public function save_player_info_to_item($item, $cart_item_key, $values, $order) {
        // If player info is in cart item data
        if (!empty($values['player_name'])) {
            $item->add_meta_data('Player', $values['player_name']);
        }
        if (!empty($values['player_age'])) {
            $item->add_meta_data('Age', $values['player_age']);
        }
        
        // Camp specific data
        if (!empty($values['camp_date'])) {
            $item->add_meta_data('_camp_date', $values['camp_date']);
        }
        if (!empty($values['camp_time'])) {
            $item->add_meta_data('_camp_time', $values['camp_time']);
        }
        if (!empty($values['camp_location'])) {
            $item->add_meta_data('_camp_location', $values['camp_location']);
        }
    }
    
    /**
     * Format item meta key for display
     */
    public function format_item_meta_key($display_key, $meta, $item) {
        $key_map = array(
            '_camp_date' => 'Date',
            '_camp_time' => 'Time',
            '_camp_location' => 'Location',
            '_player_name' => 'Player',
            '_player_age' => 'Age',
        );
        
        if (isset($key_map[$display_key])) {
            return $key_map[$display_key];
        }
        
        return $display_key;
    }
    
    /**
     * Format item meta value for display
     */
    public function format_item_meta_value($display_value, $meta, $item) {
        // Format dates nicely
        if ($meta->key === '_camp_date' && !empty($display_value)) {
            $timestamp = strtotime($display_value);
            if ($timestamp) {
                return date('l, F j, Y', $timestamp);
            }
        }
        
        return $display_value;
    }
    
    /**
     * Add custom order columns (admin)
     */
    public function add_order_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Add after order_status
            if ($key === 'order_status') {
                $new_columns['ptp_player'] = 'Player';
                $new_columns['ptp_camp_date'] = 'Camp Date';
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Render custom order columns
     */
    public function render_order_columns($column, $post_id) {
        $order = PTP_Native_Order_Manager::get_order($post_id);
        if (!$order) return;
        
        $this->render_column_content($column, $order);
    }
    
    /**
     * Render columns for HPOS
     */
    public function render_order_columns_hpos($column, $order) {
        $this->render_column_content($column, $order);
    }
    
    /**
     * Render column content
     */
    private function render_column_content($column, $order) {
        switch ($column) {
            case 'ptp_player':
                // Try multiple meta key variations
                $first = $order->get_meta('_player_first_name') 
                    ?: $order->get_meta('_ptp_camper_first_name')
                    ?: $order->get_meta('_player_name');
                $last = $order->get_meta('_player_last_name')
                    ?: $order->get_meta('_ptp_camper_last_name')
                    ?: '';
                
                if ($first || $last) {
                    echo esc_html(trim($first . ' ' . $last));
                } else {
                    // Try to get from _ptp_campers array
                    $campers = $order->get_meta('_ptp_campers');
                    if (is_array($campers) && !empty($campers)) {
                        $first_camper = reset($campers);
                        if (!empty($first_camper['name'])) {
                            echo esc_html($first_camper['name']);
                            break;
                        }
                    }
                    
                    // Try order item meta
                    foreach ($order->get_items() as $item) {
                        $player = $item->get_meta('Player Name') ?: $item->get_meta('Camper Name') ?: $item->get_meta('player_name');
                        if ($player) {
                            echo esc_html($player);
                            break 2;
                        }
                    }
                    
                    echo '—';
                }
                break;
                
            case 'ptp_camp_date':
                $camp_details = $order->get_meta('_ptp_camp_details');
                if (!empty($camp_details) && !empty($camp_details[0]['date'])) {
                    echo esc_html(date('M j, Y', strtotime($camp_details[0]['date'])));
                } else {
                    // Try to extract from order items
                    $details = $this->extract_camp_details_from_order($order);
                    if (!empty($details) && !empty($details[0]['date'])) {
                        echo esc_html(date('M j, Y', strtotime($details[0]['date'])));
                    } else {
                        // Try item meta directly
                        foreach ($order->get_items() as $item) {
                            $date = $item->get_meta('Camp Date') ?: $item->get_meta('camp_date') ?: $item->get_meta('Date');
                            if ($date) {
                                echo esc_html(date('M j, Y', strtotime($date)));
                                break 2;
                            }
                        }
                        echo '—';
                    }
                }
                break;
        }
    }
    
    /**
     * Add custom data to REST API response
     */
    public function add_order_rest_data($response, $order, $request) {
        $data = $response->get_data();
        
        // Add player info
        $data['ptp_player'] = array(
            'first_name' => $order->get_meta('_player_first_name'),
            'last_name' => $order->get_meta('_player_last_name'),
            'age' => $order->get_meta('_player_age'),
            'team' => $order->get_meta('_player_team'),
            'skill_level' => $order->get_meta('_skill_level'),
        );
        
        // Add camp details
        $data['ptp_camp_details'] = $order->get_meta('_ptp_camp_details');
        
        // Add emergency contact
        $data['ptp_emergency_contact'] = array(
            'name' => $order->get_meta('_emergency_contact_name'),
            'phone' => $order->get_meta('_emergency_contact_phone'),
        );
        
        $response->set_data($data);
        
        return $response;
    }
    
    /**
     * Handle order completed
     */
    public function handle_order_completed($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return;
        
        // Send confirmation SMS if enabled
        if (class_exists('PTP_SMS_V71')) {
            $phone = $order->get_billing_phone();
            $player_name = $order->get_meta('_player_first_name');
            
            if ($phone && $player_name) {
                $camp_details = $order->get_meta('_ptp_camp_details');
                $camp_name = !empty($camp_details[0]['product_name']) ? $camp_details[0]['product_name'] : 'your camp';
                $camp_date = !empty($camp_details[0]['date']) ? date('M j', strtotime($camp_details[0]['date'])) : '';
                
                $message = "✅ {$player_name} is registered for {$camp_name}";
                if ($camp_date) {
                    $message .= " on {$camp_date}";
                }
                $message .= "! We'll send a reminder before the event. - PTP Soccer";
                
                PTP_SMS_V71::send($phone, $message);
            }
        }
        
        // Create parent record if doesn't exist
        $this->maybe_create_parent_record($order);
    }
    
    /**
     * Handle order processing
     */
    public function handle_order_processing($order_id) {
        // Similar to completed but for processing status
        $this->handle_order_completed($order_id);
    }
    
    /**
     * Create parent record from order
     */
    private function maybe_create_parent_record($order) {
        global $wpdb;
        
        $user_id = $order->get_user_id();
        if (!$user_id) return;
        
        // Check if parent record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if ($exists) return;
        
        // Create parent record
        $wpdb->insert(
            $wpdb->prefix . 'ptp_parents',
            array(
                'user_id' => $user_id,
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Get client IP
     */
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return sanitize_text_field($ip);
    }
    
    /**
     * Static helper to get camp details for an order
     */
    public static function get_order_camp_details($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return array();
        
        $camp_details = $order->get_meta('_ptp_camp_details');
        
        if (empty($camp_details)) {
            $instance = new self();
            $camp_details = $instance->extract_camp_details_from_order($order);
        }
        
        return $camp_details;
    }
    
    /**
     * Static helper to get player info for an order
     */
    public static function get_order_player_info($order_id) {
        $order = PTP_Native_Order_Manager::get_order($order_id);
        if (!$order) return array();
        
        return array(
            'first_name' => $order->get_meta('_player_first_name'),
            'last_name' => $order->get_meta('_player_last_name'),
            'age' => $order->get_meta('_player_age'),
            'dob' => $order->get_meta('_player_dob'),
            'gender' => $order->get_meta('_player_gender'),
            'team' => $order->get_meta('_player_team'),
            'position' => $order->get_meta('_player_position'),
            'skill_level' => $order->get_meta('_skill_level'),
        );
    }
}

// Initialize
new PTP_Order_Integration_V71();
