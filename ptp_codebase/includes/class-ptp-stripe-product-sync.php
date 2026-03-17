<?php
/**
 * PTP Stripe Product Sync
 * 
 * Creates and syncs actual Stripe Products/Prices from camp data.
 * Products appear in Stripe Dashboard and can use Stripe Checkout.
 * 
 * @version 150.3.0
 */

defined('ABSPATH') || exit;

class PTP_Stripe_Product_Sync {
    
    private static $instance = null;
    private $secret_key;
    private $api_base = 'https://api.stripe.com/v1';
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->secret_key = get_option('ptp_stripe_secret_key', '');
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        
        // AJAX handlers
        // Note: ptp_sync_stripe_products and ptp_create_stripe_product handled by PTP_Stripe_Products class
        add_action('wp_ajax_ptp_sync_single_product', array($this, 'ajax_sync_single'));
        
        // Auto-sync on camp save
        add_action('save_post_ptp_camp', array($this, 'sync_on_save'), 20, 2);
        
        // REST API endpoint for external sync
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=ptp_camp',
            'Stripe Product Sync',
            'Stripe Sync',
            'manage_options',
            'ptp-stripe-sync',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Handle admin actions
     */
    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) return;
        
        // Bulk sync trigger
        if (isset($_GET['ptp_sync_stripe']) && $_GET['ptp_sync_stripe'] === '1') {
            check_admin_referer('ptp_stripe_sync');
            $result = $this->sync_all_products();
            
            $message = sprintf(
                'Stripe sync complete: %d created, %d updated, %d errors',
                $result['created'],
                $result['updated'],
                $result['errors']
            );
            
            add_action('admin_notices', function() use ($message, $result) {
                $class = $result['errors'] > 0 ? 'notice-warning' : 'notice-success';
                echo "<div class='notice {$class} is-dismissible'><p>{$message}</p></div>";
            });
        }
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_stripe_products';
        $products = $wpdb->get_results("SELECT * FROM {$table} WHERE product_type = 'camp' ORDER BY sort_order, name");
        
        $sync_url = wp_nonce_url(
            admin_url('edit.php?post_type=ptp_camp&page=ptp-stripe-sync&ptp_sync_stripe=1'),
            'ptp_stripe_sync'
        );
        
        ?>
        <div class="wrap">
            <h1>Stripe Product Sync</h1>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h2>Sync Camp Products to Stripe</h2>
                <p>This will create or update actual Stripe Products and Prices for all camps. Products will appear in your Stripe Dashboard.</p>
                
                <?php if (empty($this->secret_key)): ?>
                    <div class="notice notice-error inline">
                        <p><strong>Stripe Secret Key not configured.</strong> Go to PTP Settings → Stripe to add your API key.</p>
                    </div>
                <?php else: ?>
                    <p>
                        <a href="<?php echo esc_url($sync_url); ?>" class="button button-primary button-large">
                            🔄 Sync All Products to Stripe
                        </a>
                    </p>
                <?php endif; ?>
            </div>
            
            <h2>Camp Products (<?php echo count($products); ?>)</h2>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Dates</th>
                        <th>Location</th>
                        <th>Stripe Product ID</th>
                        <th>Stripe Price ID</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): 
                        $has_stripe = !empty($product->stripe_product_id) && strpos($product->stripe_product_id, 'prod_') === 0 && strlen($product->stripe_product_id) > 20;
                        $has_price = !empty($product->stripe_price_id) && strpos($product->stripe_price_id, 'price_') === 0;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($product->name); ?></strong></td>
                        <td>$<?php echo number_format($product->price_cents / 100, 2); ?></td>
                        <td><?php echo esc_html($product->camp_dates); ?></td>
                        <td><?php echo esc_html($product->camp_location); ?></td>
                        <td>
                            <?php if ($has_stripe): ?>
                                <code style="font-size: 11px;"><?php echo esc_html($product->stripe_product_id); ?></code>
                            <?php else: ?>
                                <span style="color: #999;">Not synced</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($has_price): ?>
                                <code style="font-size: 11px;"><?php echo esc_html($product->stripe_price_id); ?></code>
                            <?php else: ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($has_stripe && $has_price): ?>
                                <span style="color: green;">✓ Synced</span>
                            <?php elseif ($has_stripe): ?>
                                <span style="color: orange;">⚠ No Price</span>
                            <?php else: ?>
                                <span style="color: #999;">Not synced</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="button button-small ptp-sync-single" data-id="<?php echo $product->id; ?>">
                                Sync
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <script>
            jQuery(function($) {
                $('.ptp-sync-single').on('click', function() {
                    var btn = $(this);
                    var id = btn.data('id');
                    btn.prop('disabled', true).text('Syncing...');
                    
                    $.post(ajaxurl, {
                        action: 'ptp_sync_single_product',
                        product_id: id,
                        _wpnonce: '<?php echo wp_create_nonce('ptp_stripe_sync'); ?>'
                    }, function(response) {
                        if (response.success) {
                            btn.text('✓ Done');
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            btn.text('Error');
                            alert(response.data || 'Sync failed');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * Create a Stripe Product
     */
    public function create_stripe_product($data) {
        if (empty($this->secret_key)) {
            return array('error' => 'Stripe secret key not configured');
        }
        
        $body = array(
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'metadata' => array(
                'ptp_product_id' => $data['ptp_id'] ?? '',
                'product_type' => 'camp',
                'camp_dates' => $data['camp_dates'] ?? '',
                'camp_location' => $data['camp_location'] ?? '',
                'camp_time' => $data['camp_time'] ?? '',
            )
        );
        
        // Add images if available
        if (!empty($data['image_url'])) {
            $body['images'] = array($data['image_url']);
        }
        
        $response = wp_remote_post($this->api_base . '/products', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($result['error'])) {
            return array('error' => $result['error']['message'] ?? 'Unknown Stripe error');
        }
        
        return $result;
    }
    
    /**
     * Update a Stripe Product
     */
    public function update_stripe_product($product_id, $data) {
        if (empty($this->secret_key)) {
            return array('error' => 'Stripe secret key not configured');
        }
        
        $body = array(
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'metadata' => array(
                'ptp_product_id' => $data['ptp_id'] ?? '',
                'product_type' => 'camp',
                'camp_dates' => $data['camp_dates'] ?? '',
                'camp_location' => $data['camp_location'] ?? '',
                'camp_time' => $data['camp_time'] ?? '',
            )
        );
        
        $response = wp_remote_post($this->api_base . '/products/' . $product_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    /**
     * Create a Stripe Price for a Product
     */
    public function create_stripe_price($product_id, $amount_cents, $currency = 'usd') {
        if (empty($this->secret_key)) {
            return array('error' => 'Stripe secret key not configured');
        }
        
        $body = array(
            'product' => $product_id,
            'unit_amount' => intval($amount_cents),
            'currency' => $currency,
        );
        
        $response = wp_remote_post($this->api_base . '/prices', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($result['error'])) {
            return array('error' => $result['error']['message'] ?? 'Unknown Stripe error');
        }
        
        return $result;
    }
    
    /**
     * Get existing Stripe Product
     */
    public function get_stripe_product($product_id) {
        if (empty($this->secret_key)) {
            return null;
        }
        
        $response = wp_remote_get($this->api_base . '/products/' . $product_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $result = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($result['error'])) {
            return null;
        }
        
        return $result;
    }
    
    /**
     * Sync a single product to Stripe
     */
    public function sync_product($product) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        $data = array(
            'ptp_id' => $product->id,
            'name' => $product->name,
            'description' => $product->description ?: "PTP Soccer Camp - {$product->camp_dates} at {$product->camp_location}",
            'camp_dates' => $product->camp_dates,
            'camp_location' => $product->camp_location,
            'camp_time' => $product->camp_time,
            'image_url' => $product->image_url,
        );
        
        $result = array('created' => false, 'updated' => false, 'error' => null);
        
        // Check if we have a valid Stripe product ID
        $has_valid_stripe_id = !empty($product->stripe_product_id) 
            && strpos($product->stripe_product_id, 'prod_') === 0 
            && strlen($product->stripe_product_id) > 20;
        
        if ($has_valid_stripe_id) {
            // Check if product exists in Stripe
            $existing = $this->get_stripe_product($product->stripe_product_id);
            
            if ($existing && !isset($existing['error'])) {
                // Update existing product
                $updated = $this->update_stripe_product($product->stripe_product_id, $data);
                if (isset($updated['error'])) {
                    $result['error'] = $updated['error'];
                    return $result;
                }
                $result['updated'] = true;
                $stripe_product_id = $product->stripe_product_id;
            } else {
                // Product doesn't exist in Stripe, create new
                $has_valid_stripe_id = false;
            }
        }
        
        if (!$has_valid_stripe_id) {
            // Create new Stripe product
            $created = $this->create_stripe_product($data);
            
            if (isset($created['error'])) {
                $result['error'] = $created['error'];
                return $result;
            }
            
            $stripe_product_id = $created['id'];
            $result['created'] = true;
        }
        
        // Create or verify price
        $stripe_price_id = $product->stripe_price_id;
        $has_valid_price = !empty($stripe_price_id) && strpos($stripe_price_id, 'price_') === 0;
        
        if (!$has_valid_price || $result['created']) {
            // Create new price (prices are immutable in Stripe)
            $price = $this->create_stripe_price($stripe_product_id, $product->price_cents);
            
            if (isset($price['error'])) {
                $result['error'] = $price['error'];
                // Still save the product ID even if price failed
            } else {
                $stripe_price_id = $price['id'];
            }
        }
        
        // Update database
        $wpdb->update(
            $table,
            array(
                'stripe_product_id' => $stripe_product_id,
                'stripe_price_id' => $stripe_price_id,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $product->id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        return $result;
    }
    
    /**
     * Sync all products to Stripe
     */
    public function sync_all_products() {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_stripe_products';
        
        $products = $wpdb->get_results("SELECT * FROM {$table} WHERE product_type = 'camp' AND active = 1");
        
        $results = array(
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'details' => array()
        );
        
        foreach ($products as $product) {
            $result = $this->sync_product($product);
            
            if ($result['error']) {
                $results['errors']++;
                $results['details'][] = array(
                    'name' => $product->name,
                    'error' => $result['error']
                );
            } elseif ($result['created']) {
                $results['created']++;
            } elseif ($result['updated']) {
                $results['updated']++;
            }
            
            // Rate limiting - Stripe allows 100 requests/second in live mode
            usleep(50000); // 50ms delay between requests
        }
        
        return $results;
    }
    
    /**
     * AJAX: Sync all products
     */
    public function ajax_sync_all() {
        check_ajax_referer('ptp_stripe_sync');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $result = $this->sync_all_products();
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Sync single product
     */
    public function ajax_sync_single() {
        check_ajax_referer('ptp_stripe_sync', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
        }
        
        global $wpdb;
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE id = %d",
            $product_id
        ));
        
        if (!$product) {
            wp_send_json_error('Product not found');
        }
        
        $result = $this->sync_product($product);
        
        if ($result['error']) {
            wp_send_json_error($result['error']);
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Create new Stripe product
     */
    public function ajax_create_product() {
        check_ajax_referer('ptp_stripe_sync', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $data = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'camp_dates' => sanitize_text_field($_POST['camp_dates'] ?? ''),
            'camp_location' => sanitize_text_field($_POST['camp_location'] ?? ''),
            'camp_time' => sanitize_text_field($_POST['camp_time'] ?? '9:00 AM - 3:00 PM'),
            'image_url' => esc_url_raw($_POST['image_url'] ?? ''),
        );
        
        $price_cents = intval($_POST['price_cents'] ?? 0);
        
        if (empty($data['name'])) {
            wp_send_json_error('Product name is required');
        }
        
        if ($price_cents < 100) {
            wp_send_json_error('Price must be at least $1.00');
        }
        
        // Create Stripe product
        $product = $this->create_stripe_product($data);
        
        if (isset($product['error'])) {
            wp_send_json_error($product['error']);
        }
        
        // Create price
        $price = $this->create_stripe_price($product['id'], $price_cents);
        
        if (isset($price['error'])) {
            wp_send_json_error('Product created but price failed: ' . $price['error']);
        }
        
        // Save to database
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'ptp_stripe_products',
            array(
                'stripe_product_id' => $product['id'],
                'stripe_price_id' => $price['id'],
                'name' => $data['name'],
                'description' => $data['description'],
                'price_cents' => $price_cents,
                'product_type' => 'camp',
                'camp_dates' => $data['camp_dates'],
                'camp_location' => $data['camp_location'],
                'camp_time' => $data['camp_time'],
                'image_url' => $data['image_url'],
                'active' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        wp_send_json_success(array(
            'product_id' => $product['id'],
            'price_id' => $price['id'],
            'db_id' => $wpdb->insert_id
        ));
    }
    
    /**
     * Sync on ptp_camp post save
     */
    public function sync_on_save($post_id, $post) {
        if (wp_is_post_revision($post_id) || $post->post_status !== 'publish') {
            return;
        }
        
        // Get camp data from post meta
        $price = floatval(get_post_meta($post_id, '_camp_price', true)) ?: 525;
        $sale_price = floatval(get_post_meta($post_id, '_camp_sale_price', true));
        $final_price = ($sale_price > 0) ? $sale_price : $price;
        
        $data = array(
            'ptp_id' => $post_id,
            'name' => $post->post_title,
            'description' => wp_strip_all_tags($post->post_content),
            'camp_dates' => get_post_meta($post_id, '_camp_date', true),
            'camp_location' => get_post_meta($post_id, '_camp_location', true),
            'camp_time' => get_post_meta($post_id, '_camp_time', true) ?: '9:00 AM - 3:00 PM',
            'image_url' => get_the_post_thumbnail_url($post_id, 'large'),
        );
        
        $price_cents = intval($final_price * 100);
        
        // Check if product already exists in our table
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE woo_product_id = %d OR name = %s",
            $post_id,
            $post->post_title
        ));
        
        if ($existing) {
            // Update and sync
            $wpdb->update(
                $wpdb->prefix . 'ptp_stripe_products',
                array(
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'price_cents' => $price_cents,
                    'camp_dates' => $data['camp_dates'],
                    'camp_location' => $data['camp_location'],
                    'camp_time' => $data['camp_time'],
                    'image_url' => $data['image_url'],
                    'woo_product_id' => $post_id,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing->id)
            );
            
            // Sync to Stripe
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE id = %d",
                $existing->id
            ));
            $this->sync_product($product);
        } else {
            // Create new
            $wpdb->insert(
                $wpdb->prefix . 'ptp_stripe_products',
                array(
                    'stripe_product_id' => 'pending_' . $post_id,
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'price_cents' => $price_cents,
                    'product_type' => 'camp',
                    'camp_dates' => $data['camp_dates'],
                    'camp_location' => $data['camp_location'],
                    'camp_time' => $data['camp_time'],
                    'image_url' => $data['image_url'],
                    'woo_product_id' => $post_id,
                    'active' => 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                )
            );
            
            // Sync to Stripe
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_stripe_products WHERE id = %d",
                $wpdb->insert_id
            ));
            $this->sync_product($product);
        }
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('ptp/v1', '/stripe/sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_sync_all'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ));
        
        register_rest_route('ptp/v1', '/stripe/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_products'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * REST: Sync all products
     */
    public function rest_sync_all($request) {
        $result = $this->sync_all_products();
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * REST: Get products
     */
    public function rest_get_products($request) {
        global $wpdb;
        $products = $wpdb->get_results(
            "SELECT id, stripe_product_id, stripe_price_id, name, price_cents, camp_dates, camp_location, active 
             FROM {$wpdb->prefix}ptp_stripe_products 
             WHERE product_type = 'camp' AND active = 1 
             ORDER BY sort_order, name"
        );
        return new WP_REST_Response($products, 200);
    }
    
    /**
     * Create Stripe Checkout Session (for direct Stripe checkout)
     */
    public function create_checkout_session($line_items, $success_url, $cancel_url, $metadata = array()) {
        if (empty($this->secret_key)) {
            return array('error' => 'Stripe secret key not configured');
        }
        
        $body = array(
            'mode' => 'payment',
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'line_items' => $line_items,
            'metadata' => $metadata
        );
        
        $response = wp_remote_post($this->api_base . '/checkout/sessions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => http_build_query($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

// Initialize
PTP_Stripe_Product_Sync::instance();
