<?php
/**
 * PTP Retargeting Pixels v1.0.0
 * 
 * Integrates tracking pixels for:
 * - Facebook/Meta Pixel
 * - Google Ads Conversion Tracking
 * - Google Analytics 4
 * - TikTok Pixel
 * 
 * Tracks key conversion events across the funnel
 */

defined('ABSPATH') || exit;

class PTP_Pixels {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Add pixels to head
        add_action('wp_head', array($this, 'output_pixels'), 1);
        
        // Track events
        add_action('wp_footer', array($this, 'output_event_tracking'), 99);
        
        // Server-side tracking for conversions
        add_action('ptp_booking_completed', array($this, 'track_purchase'), 10, 2);
        add_action('ptp_trainer_application_submitted', array($this, 'track_lead'));
    }
    
    /**
     * Get pixel IDs from options
     */
    private function get_pixels() {
        return array(
            'fb_pixel_id' => get_option('ptp_fb_pixel_id', ''),
            'google_ads_id' => get_option('ptp_google_ads_id', ''),
            'google_ads_conversion_label' => get_option('ptp_google_ads_conversion_label', ''),
            'ga4_measurement_id' => get_option('ptp_ga4_measurement_id', ''),
            'tiktok_pixel_id' => get_option('ptp_tiktok_pixel_id', ''),
        );
    }
    
    /**
     * Output pixel base codes in head
     */
    public function output_pixels() {
        $pixels = $this->get_pixels();
        
        // Facebook Pixel
        if (!empty($pixels['fb_pixel_id'])):
        ?>
        <!-- Facebook Pixel -->
        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?php echo esc_js($pixels['fb_pixel_id']); ?>');
        fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=<?php echo esc_attr($pixels['fb_pixel_id']); ?>&ev=PageView&noscript=1"/></noscript>
        <?php
        endif;
        
        // Google Analytics 4 + Google Ads
        if (!empty($pixels['ga4_measurement_id']) || !empty($pixels['google_ads_id'])):
            $gtag_id = !empty($pixels['ga4_measurement_id']) ? $pixels['ga4_measurement_id'] : $pixels['google_ads_id'];
        ?>
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($gtag_id); ?>"></script>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        <?php if (!empty($pixels['ga4_measurement_id'])): ?>
        gtag('config', '<?php echo esc_js($pixels['ga4_measurement_id']); ?>');
        <?php endif; ?>
        <?php if (!empty($pixels['google_ads_id'])): ?>
        gtag('config', '<?php echo esc_js($pixels['google_ads_id']); ?>');
        <?php endif; ?>
        </script>
        <?php
        endif;
        
        // TikTok Pixel
        if (!empty($pixels['tiktok_pixel_id'])):
        ?>
        <!-- TikTok Pixel -->
        <script>
        !function (w, d, t) {
          w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};
          ttq.load('<?php echo esc_js($pixels['tiktok_pixel_id']); ?>');
          ttq.page();
        }(window, document, 'ttq');
        </script>
        <?php
        endif;
    }
    
    /**
     * Output event tracking based on current page
     */
    public function output_event_tracking() {
        $pixels = $this->get_pixels();
        $page_type = $this->get_page_type();
        
        if (empty($page_type)) return;
        ?>
        <script>
        (function() {
            var pageType = '<?php echo esc_js($page_type); ?>';
            var pixels = {
                fb: <?php echo !empty($pixels['fb_pixel_id']) ? 'true' : 'false'; ?>,
                gtag: <?php echo (!empty($pixels['ga4_measurement_id']) || !empty($pixels['google_ads_id'])) ? 'true' : 'false'; ?>,
                ttq: <?php echo !empty($pixels['tiktok_pixel_id']) ? 'true' : 'false'; ?>
            };
            
            // Track based on page type
            switch(pageType) {
                case 'trainer_profile':
                    trackEvent('ViewContent', {
                        content_type: 'trainer',
                        content_id: '<?php echo esc_js($this->get_trainer_id()); ?>',
                        content_name: '<?php echo esc_js($this->get_trainer_name()); ?>'
                    });
                    break;
                    
                case 'find_trainers':
                    trackEvent('Search', {
                        search_string: 'trainers',
                        content_type: 'trainer_listing'
                    });
                    break;
                    
                case 'checkout':
                    trackEvent('InitiateCheckout', {
                        content_type: 'training_session',
                        value: <?php echo floatval($this->get_checkout_value()); ?>,
                        currency: 'USD'
                    });
                    break;
                    
                case 'booking_confirmation':
                    // Purchase tracked server-side, but also fire client-side
                    trackEvent('Purchase', {
                        content_type: 'training_session',
                        value: <?php echo floatval($this->get_booking_value()); ?>,
                        currency: 'USD'
                    });
                    break;
                    
                case 'apply':
                    trackEvent('ViewContent', {
                        content_type: 'trainer_application',
                        content_name: 'Become a Trainer'
                    });
                    break;
            }
            
            // Track CTA clicks
            document.addEventListener('click', function(e) {
                var target = e.target.closest('a, button');
                if (!target) return;
                
                // Book now buttons
                if (target.textContent.match(/book\s*now/i) || target.classList.contains('book-cta')) {
                    trackEvent('AddToCart', {
                        content_type: 'training_session',
                        content_name: 'Book Session Click'
                    });
                }
                
                // Apply button
                if (target.href && target.href.includes('/apply')) {
                    trackEvent('Lead', {
                        content_type: 'trainer_application',
                        content_name: 'Apply Button Click'
                    });
                }
            });
            
            // Universal track function
            function trackEvent(eventName, params) {
                params = params || {};
                
                // Facebook Pixel
                if (pixels.fb && typeof fbq === 'function') {
                    fbq('track', eventName, params);
                }
                
                // Google Analytics/Ads
                if (pixels.gtag && typeof gtag === 'function') {
                    var gaEvent = eventName.toLowerCase();
                    if (eventName === 'ViewContent') gaEvent = 'view_item';
                    if (eventName === 'AddToCart') gaEvent = 'add_to_cart';
                    if (eventName === 'InitiateCheckout') gaEvent = 'begin_checkout';
                    if (eventName === 'Purchase') gaEvent = 'purchase';
                    if (eventName === 'Lead') gaEvent = 'generate_lead';
                    if (eventName === 'Search') gaEvent = 'search';
                    
                    gtag('event', gaEvent, {
                        currency: params.currency || 'USD',
                        value: params.value || 0,
                        items: params.content_id ? [{
                            item_id: params.content_id,
                            item_name: params.content_name || ''
                        }] : undefined
                    });
                }
                
                // TikTok Pixel
                if (pixels.ttq && typeof ttq === 'object') {
                    ttq.track(eventName, params);
                }
                
                // Also track in our analytics
                if (typeof ptpTrack === 'function') {
                    ptpTrack('pixel_' + eventName.toLowerCase(), params);
                }
            }
            
            // Expose for manual tracking
            window.ptpPixelTrack = trackEvent;
        })();
        </script>
        <?php
    }
    
    /**
     * Server-side purchase tracking
     */
    public function track_purchase($booking_id, $booking) {
        $pixels = $this->get_pixels();
        
        // Facebook Conversions API (if configured)
        if (!empty($pixels['fb_pixel_id']) && !empty(get_option('ptp_fb_access_token'))) {
            $this->fb_conversions_api('Purchase', array(
                'value' => floatval($booking->amount),
                'currency' => 'USD',
                'content_ids' => array($booking->trainer_id),
                'content_type' => 'product',
            ), $booking->parent_id);
        }
    }
    
    /**
     * Track trainer application as lead
     */
    public function track_lead($application_id) {
        $pixels = $this->get_pixels();
        
        if (!empty($pixels['fb_pixel_id']) && !empty(get_option('ptp_fb_access_token'))) {
            $this->fb_conversions_api('Lead', array(
                'content_name' => 'Trainer Application',
                'content_category' => 'trainer_signup',
            ));
        }
    }
    
    /**
     * Facebook Conversions API call
     */
    private function fb_conversions_api($event_name, $params, $user_id = null) {
        $pixel_id = get_option('ptp_fb_pixel_id');
        $access_token = get_option('ptp_fb_access_token');
        
        if (empty($pixel_id) || empty($access_token)) return;
        
        $user_data = array();
        
        if ($user_id) {
            $user = get_user_by('ID', $user_id);
            if ($user) {
                $user_data['em'] = hash('sha256', strtolower($user->user_email));
                $user_data['fn'] = hash('sha256', strtolower($user->first_name ?: ''));
                $user_data['ln'] = hash('sha256', strtolower($user->last_name ?: ''));
            }
        }
        
        $user_data['client_ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_data['client_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $user_data['fbc'] = $_COOKIE['_fbc'] ?? '';
        $user_data['fbp'] = $_COOKIE['_fbp'] ?? '';
        
        $event = array(
            'event_name' => $event_name,
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => home_url($_SERVER['REQUEST_URI'] ?? ''),
            'user_data' => $user_data,
            'custom_data' => $params,
        );
        
        $url = "https://graph.facebook.com/v18.0/{$pixel_id}/events";
        
        wp_remote_post($url, array(
            'body' => array(
                'data' => json_encode(array($event)),
                'access_token' => $access_token,
            ),
            'blocking' => false,
        ));
    }
    
    /**
     * Helper functions
     */
    private function get_page_type() {
        $url = $_SERVER['REQUEST_URI'] ?? '';
        
        if (strpos($url, '/trainer/') !== false) return 'trainer_profile';
        if (strpos($url, '/find-trainers') !== false) return 'find_trainers';
        if (strpos($url, '/checkout') !== false) return 'checkout';
        if (strpos($url, '/booking-confirmation') !== false) return 'booking_confirmation';
        if (strpos($url, '/apply') !== false) return 'apply';
        if (strpos($url, '/book-session') !== false) return 'booking';
        
        return '';
    }
    
    private function get_trainer_id() {
        global $wpdb;
        $slug = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s",
            $slug
        ));
        return $trainer ? $trainer->id : '';
    }
    
    private function get_trainer_name() {
        global $wpdb;
        $slug = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}ptp_trainers WHERE slug = %s",
            $slug
        ));
        return $trainer ? $trainer->display_name : '';
    }
    
    private function get_checkout_value() {
        return isset($_GET['total']) ? floatval($_GET['total']) : 80;
    }
    
    private function get_booking_value() {
        if (isset($_GET['booking_id'])) {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare(
                "SELECT amount FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
                intval($_GET['booking_id'])
            )) ?: 80;
        }
        return 80;
    }
    
    /**
     * Add settings fields to admin
     */
    public static function register_settings() {
        register_setting('ptp_pixels', 'ptp_fb_pixel_id');
        register_setting('ptp_pixels', 'ptp_fb_access_token');
        register_setting('ptp_pixels', 'ptp_fb_test_mode');
        register_setting('ptp_pixels', 'ptp_fb_test_event_code');
        register_setting('ptp_pixels', 'ptp_google_ads_id');
        register_setting('ptp_pixels', 'ptp_google_ads_conversion_label');
        register_setting('ptp_pixels', 'ptp_ga4_measurement_id');
        register_setting('ptp_pixels', 'ptp_tiktok_pixel_id');
    }
    
    /**
     * Render settings page section
     */
    public static function render_settings() {
        ?>
        <div class="ptp-settings-section">
            <h3>📊 Retargeting Pixels</h3>
            <p class="description">Add your tracking pixels to retarget visitors and track conversions.</p>
            
            <table class="form-table">
                <tr>
                    <th>Facebook Pixel ID</th>
                    <td>
                        <input type="text" name="ptp_fb_pixel_id" value="<?php echo esc_attr(get_option('ptp_fb_pixel_id')); ?>" class="regular-text" placeholder="123456789012345">
                        <p class="description">Find in Facebook Events Manager</p>
                    </td>
                </tr>
                <tr>
                    <th>Facebook Access Token</th>
                    <td>
                        <input type="text" name="ptp_fb_access_token" value="<?php echo esc_attr(get_option('ptp_fb_access_token')); ?>" class="regular-text" placeholder="EAABs...">
                        <p class="description">For Conversions API - Events Manager → Settings → Generate Access Token</p>
                    </td>
                </tr>
                <tr>
                    <th>Conversions API Test Mode</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ptp_fb_test_mode" value="1" <?php checked(get_option('ptp_fb_test_mode'), 1); ?>>
                            Enable test mode (events go to Test Events tab)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Test Event Code</th>
                    <td>
                        <input type="text" name="ptp_fb_test_event_code" value="<?php echo esc_attr(get_option('ptp_fb_test_event_code')); ?>" class="regular-text" placeholder="TEST12345">
                        <p class="description">From Events Manager → Test Events tab (only used in test mode)</p>
                    </td>
                </tr>
                <tr>
                    <th>Google Ads ID</th>
                    <td>
                        <input type="text" name="ptp_google_ads_id" value="<?php echo esc_attr(get_option('ptp_google_ads_id')); ?>" class="regular-text" placeholder="AW-123456789">
                    </td>
                </tr>
                <tr>
                    <th>Google Ads Conversion Label</th>
                    <td>
                        <input type="text" name="ptp_google_ads_conversion_label" value="<?php echo esc_attr(get_option('ptp_google_ads_conversion_label')); ?>" class="regular-text" placeholder="AbCdEf...">
                    </td>
                </tr>
                <tr>
                    <th>Google Analytics 4 ID</th>
                    <td>
                        <input type="text" name="ptp_ga4_measurement_id" value="<?php echo esc_attr(get_option('ptp_ga4_measurement_id')); ?>" class="regular-text" placeholder="G-XXXXXXXXXX">
                    </td>
                </tr>
                <tr>
                    <th>TikTok Pixel ID</th>
                    <td>
                        <input type="text" name="ptp_tiktok_pixel_id" value="<?php echo esc_attr(get_option('ptp_tiktok_pixel_id')); ?>" class="regular-text" placeholder="CXXXXXXXXX">
                    </td>
                </tr>
            </table>
            
            <?php if (get_option('ptp_fb_pixel_id') && get_option('ptp_fb_access_token')): ?>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 12px; border-radius: 4px; margin-top: 15px;">
                <strong>✅ Meta Conversions API Active</strong><br>
                Server-side Purchase events will fire on Stripe checkout completion.
                <?php if (get_option('ptp_fb_test_mode')): ?>
                <br><em>Test mode enabled - check Events Manager → Test Events</em>
                <?php endif; ?>
            </div>
            <?php elseif (get_option('ptp_fb_pixel_id')): ?>
            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px; margin-top: 15px;">
                <strong>⚠️ Add Access Token for Server-Side Tracking</strong><br>
                Your pixel is browser-only. Add an access token to enable Conversions API and improve match rates from ~43% to 90%+.
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Initialize
add_action('init', function() {
    PTP_Pixels::instance();
});
add_action('admin_init', array('PTP_Pixels', 'register_settings'));
