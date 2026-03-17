<?php
/**
 * PTP SEO Sitemap Generator
 * 
 * Generates comprehensive XML sitemaps for:
 * - Location pages (states, cities)
 * - Service pages
 * - Combined service + location pages
 * - Trainer profiles
 * - Camps and clinics
 * 
 * Automatically pings Google and Bing on updates
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_SEO_Sitemap {
    
    /**
     * Initialize sitemap functionality
     */
    public static function init() {
        // Register sitemap routes
        add_action('init', array(__CLASS__, 'register_sitemap_routes'));
        
        // Hook into post/trainer updates to invalidate cache
        add_action('ptp_trainer_updated', array(__CLASS__, 'invalidate_cache'));
        add_action('save_post_product', array(__CLASS__, 'invalidate_cache'));
        
        // Admin tools
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('wp_ajax_ptp_generate_sitemap', array(__CLASS__, 'ajax_generate_sitemap'));
        add_action('wp_ajax_ptp_ping_search_engines', array(__CLASS__, 'ajax_ping_search_engines'));
        
        // Add sitemap link to robots.txt
        add_filter('robots_txt', array(__CLASS__, 'add_sitemap_to_robots'), 10, 2);
    }
    
    /**
     * Register sitemap routes
     */
    public static function register_sitemap_routes() {
        // Main sitemap index
        add_rewrite_rule(
            'ptp-sitemap\.xml$',
            'index.php?ptp_sitemap=index',
            'top'
        );
        
        // Individual sitemaps
        add_rewrite_rule(
            'ptp-sitemap-locations\.xml$',
            'index.php?ptp_sitemap=locations',
            'top'
        );
        
        add_rewrite_rule(
            'ptp-sitemap-trainers\.xml$',
            'index.php?ptp_sitemap=trainers',
            'top'
        );
        
        add_rewrite_rule(
            'ptp-sitemap-camps\.xml$',
            'index.php?ptp_sitemap=camps',
            'top'
        );
        
        add_rewrite_rule(
            'ptp-sitemap-services\.xml$',
            'index.php?ptp_sitemap=services',
            'top'
        );
        
        // Add query var
        add_filter('query_vars', function($vars) {
            $vars[] = 'ptp_sitemap';
            return $vars;
        });
        
        // Handle sitemap requests
        add_action('template_redirect', array(__CLASS__, 'handle_sitemap_request'));
    }
    
    /**
     * Handle sitemap requests
     */
    public static function handle_sitemap_request() {
        $sitemap_type = get_query_var('ptp_sitemap');
        
        if (!$sitemap_type) {
            return;
        }
        
        // Set XML headers
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow');
        
        // Generate appropriate sitemap
        switch ($sitemap_type) {
            case 'index':
                echo self::generate_sitemap_index();
                break;
            case 'locations':
                echo self::generate_locations_sitemap();
                break;
            case 'trainers':
                echo self::generate_trainers_sitemap();
                break;
            case 'camps':
                echo self::generate_camps_sitemap();
                break;
            case 'services':
                echo self::generate_services_sitemap();
                break;
        }
        
        exit;
    }
    
    /**
     * Generate sitemap index (links to individual sitemaps)
     */
    public static function generate_sitemap_index() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        $sitemaps = array(
            'ptp-sitemap-locations.xml',
            'ptp-sitemap-services.xml',
            'ptp-sitemap-trainers.xml',
            'ptp-sitemap-camps.xml',
        );
        
        foreach ($sitemaps as $sitemap) {
            $xml .= "  <sitemap>\n";
            $xml .= "    <loc>" . esc_url(home_url('/' . $sitemap)) . "</loc>\n";
            $xml .= "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }
        
        $xml .= '</sitemapindex>';
        
        return $xml;
    }
    
    /**
     * Generate locations sitemap (states, cities, combined)
     */
    public static function generate_locations_sitemap() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        // Get states and services from SEO Locations class
        if (!class_exists('PTP_SEO_Locations')) {
            return $xml . '</urlset>';
        }
        
        $states = PTP_SEO_Locations::get_states();
        $services = PTP_SEO_Locations::get_services();
        
        // Root soccer training page
        $xml .= self::url_entry(home_url('/soccer-training/'), '1.0', 'daily');
        $xml .= self::url_entry(home_url('/soccer-training-near-me/'), '0.9', 'weekly');
        
        // State pages
        foreach ($states as $state_slug => $state) {
            // Main state page
            $xml .= self::url_entry(
                home_url("/soccer-training/{$state_slug}/"),
                '0.9',
                'weekly'
            );
            
            // City pages
            foreach ($state['major_cities'] as $city_slug => $city) {
                $priority = isset($city['metro']) ? '0.9' : '0.7';
                $xml .= self::url_entry(
                    home_url("/soccer-training/{$state_slug}/{$city_slug}/"),
                    $priority,
                    'weekly'
                );
            }
        }
        
        // Service + State combinations
        foreach ($services as $service_slug => $service) {
            foreach ($states as $state_slug => $state) {
                $xml .= self::url_entry(
                    home_url("/{$service_slug}/{$state_slug}/"),
                    '0.8',
                    'weekly'
                );
                
                // Service + City for metro areas only (keeps sitemap manageable)
                foreach ($state['major_cities'] as $city_slug => $city) {
                    if (isset($city['metro']) || $city['population'] > 50000) {
                        $xml .= self::url_entry(
                            home_url("/{$service_slug}/{$state_slug}/{$city_slug}/"),
                            '0.9',
                            'weekly'
                        );
                    }
                }
            }
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * Generate services sitemap
     */
    public static function generate_services_sitemap() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        if (!class_exists('PTP_SEO_Locations')) {
            return $xml . '</urlset>';
        }
        
        $services = PTP_SEO_Locations::get_services();
        
        // Main service pages
        foreach ($services as $slug => $service) {
            $xml .= self::url_entry(
                home_url("/{$slug}/"),
                '1.0',
                'weekly'
            );
            
            // Near me variants
            if (in_array($slug, array('private-soccer-training', 'soccer-camps', 'soccer-clinics', 'goalkeeper-training'))) {
                $xml .= self::url_entry(
                    home_url("/{$slug}-near-me/"),
                    '0.9',
                    'weekly'
                );
            }
        }
        
        // Static pages
        $static_pages = array(
            '/find-trainers/' => '1.0',
            '/camps/' => '1.0',
            '/apply/' => '0.8',
            '/about/' => '0.7',
            '/contact/' => '0.6',
            '/faq/' => '0.6',
        );
        
        foreach ($static_pages as $path => $priority) {
            $xml .= self::url_entry(home_url($path), $priority, 'monthly');
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * Generate trainers sitemap
     */
    public static function generate_trainers_sitemap() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
            xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        
        if (!class_exists('PTP_Trainer')) {
            return $xml . '</urlset>';
        }
        
        $trainers = PTP_Trainer::get_all(array('status' => 'active'));
        
        foreach ($trainers as $trainer) {
            $url = home_url('/trainer/' . $trainer->slug . '/');
            $lastmod = date('Y-m-d', strtotime($trainer->updated_at ?? 'now'));
            
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . esc_url($url) . "</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.8</priority>\n";
            
            // Add trainer image if available
            if (!empty($trainer->photo_url)) {
                $xml .= "    <image:image>\n";
                $xml .= "      <image:loc>" . esc_url($trainer->photo_url) . "</image:loc>\n";
                $xml .= "      <image:title>" . esc_html($trainer->display_name) . " - Soccer Trainer</image:title>\n";
                $xml .= "    </image:image>\n";
            }
            
            $xml .= "  </url>\n";
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * Generate camps sitemap
     */
    public static function generate_camps_sitemap() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
            xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        
        // Get camp products
        $camps = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => array('camps', 'clinics', 'events'),
                ),
            ),
            'post_status' => 'publish',
        ));
        
        foreach ($camps as $camp) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . esc_url(get_permalink($camp->ID)) . "</loc>\n";
            $xml .= "    <lastmod>" . get_the_modified_date('Y-m-d', $camp) . "</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.9</priority>\n";
            
            // Add featured image
            $image = get_the_post_thumbnail_url($camp->ID, 'large');
            if ($image) {
                $xml .= "    <image:image>\n";
                $xml .= "      <image:loc>" . esc_url($image) . "</image:loc>\n";
                $xml .= "      <image:title>" . esc_html($camp->post_title) . "</image:title>\n";
                $xml .= "    </image:image>\n";
            }
            
            $xml .= "  </url>\n";
        }
        
        // Camp category pages
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'slug' => array('camps', 'clinics', 'events'),
            'hide_empty' => true,
        ));
        
        foreach ($categories as $cat) {
            $xml .= self::url_entry(get_term_link($cat), '0.8', 'weekly');
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * Generate a single URL entry
     */
    private static function url_entry($url, $priority = '0.5', $changefreq = 'monthly', $lastmod = null) {
        $lastmod = $lastmod ?: date('Y-m-d');
        
        return "  <url>\n" .
               "    <loc>" . esc_url($url) . "</loc>\n" .
               "    <lastmod>{$lastmod}</lastmod>\n" .
               "    <changefreq>{$changefreq}</changefreq>\n" .
               "    <priority>{$priority}</priority>\n" .
               "  </url>\n";
    }
    
    /**
     * Add sitemap to robots.txt
     */
    public static function add_sitemap_to_robots($output, $public) {
        if ($public) {
            $output .= "\n# PTP Sitemaps\n";
            $output .= "Sitemap: " . home_url('/ptp-sitemap.xml') . "\n";
        }
        return $output;
    }
    
    /**
     * Invalidate sitemap cache
     */
    public static function invalidate_cache() {
        delete_transient('ptp_sitemap_locations');
        delete_transient('ptp_sitemap_trainers');
        delete_transient('ptp_sitemap_camps');
    }
    
    /**
     * Ping search engines about sitemap update
     */
    public static function ping_search_engines() {
        $sitemap_url = home_url('/ptp-sitemap.xml');
        
        $engines = array(
            'Google' => 'https://www.google.com/ping?sitemap=' . urlencode($sitemap_url),
            'Bing' => 'https://www.bing.com/ping?sitemap=' . urlencode($sitemap_url),
        );
        
        $results = array();
        
        foreach ($engines as $name => $ping_url) {
            $response = wp_remote_get($ping_url, array('timeout' => 10));
            $results[$name] = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        }
        
        return $results;
    }
    
    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'ptp-training',
            'Sitemap Generator',
            'Sitemap',
            'manage_options',
            'ptp-sitemap',
            array(__CLASS__, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public static function render_admin_page() {
        $sitemap_url = home_url('/ptp-sitemap.xml');
        ?>
        <div class="wrap">
            <h1>PTP SEO Sitemap</h1>
            
            <div class="ptp-sitemap-admin">
                <div class="ptp-sitemap-info">
                    <h2>Sitemap URLs</h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Sitemap</th>
                                <th>URL</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Sitemap Index</strong></td>
                                <td><a href="<?php echo esc_url($sitemap_url); ?>" target="_blank"><?php echo esc_html($sitemap_url); ?></a></td>
                                <td><span class="dashicons dashicons-yes-alt" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td>Locations</td>
                                <td><a href="<?php echo home_url('/ptp-sitemap-locations.xml'); ?>" target="_blank"><?php echo home_url('/ptp-sitemap-locations.xml'); ?></a></td>
                                <td><span class="dashicons dashicons-yes-alt" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td>Services</td>
                                <td><a href="<?php echo home_url('/ptp-sitemap-services.xml'); ?>" target="_blank"><?php echo home_url('/ptp-sitemap-services.xml'); ?></a></td>
                                <td><span class="dashicons dashicons-yes-alt" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td>Trainers</td>
                                <td><a href="<?php echo home_url('/ptp-sitemap-trainers.xml'); ?>" target="_blank"><?php echo home_url('/ptp-sitemap-trainers.xml'); ?></a></td>
                                <td><span class="dashicons dashicons-yes-alt" style="color: green;"></span></td>
                            </tr>
                            <tr>
                                <td>Camps</td>
                                <td><a href="<?php echo home_url('/ptp-sitemap-camps.xml'); ?>" target="_blank"><?php echo home_url('/ptp-sitemap-camps.xml'); ?></a></td>
                                <td><span class="dashicons dashicons-yes-alt" style="color: green;"></span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="ptp-sitemap-actions" style="margin-top: 30px;">
                    <h2>Actions</h2>
                    <p>
                        <button type="button" class="button button-primary" id="ptp-ping-engines">
                            Ping Google & Bing
                        </button>
                        <span id="ptp-ping-result" style="margin-left: 10px;"></span>
                    </p>
                    <p class="description">
                        Notify search engines about your sitemap. This helps them discover and index your pages faster.
                    </p>
                </div>
                
                <div class="ptp-sitemap-submit" style="margin-top: 30px;">
                    <h2>Submit to Search Console</h2>
                    <p>For best results, submit your sitemap directly to these search consoles:</p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><a href="https://search.google.com/search-console" target="_blank">Google Search Console</a> - Add sitemap: <code><?php echo esc_html($sitemap_url); ?></code></li>
                        <li><a href="https://www.bing.com/webmasters" target="_blank">Bing Webmaster Tools</a> - Submit sitemap URL</li>
                    </ul>
                </div>
                
                <div class="ptp-sitemap-stats" style="margin-top: 30px;">
                    <h2>Sitemap Statistics</h2>
                    <?php
                    if (class_exists('PTP_SEO_Locations')) {
                        $states = PTP_SEO_Locations::get_states();
                        $services = PTP_SEO_Locations::get_services();
                        
                        $total_cities = 0;
                        foreach ($states as $state) {
                            $total_cities += count($state['major_cities']);
                        }
                        
                        $total_pages = count($states) + $total_cities + count($services);
                        $total_pages += count($states) * count($services); // Service + State
                        
                        // Count trainers
                        $trainer_count = 0;
                        if (class_exists('PTP_Trainer')) {
                            $trainers = PTP_Trainer::get_all(array('status' => 'active'));
                            $trainer_count = count($trainers);
                        }
                        
                        // Count camps
                        $camp_count = wp_count_posts('product');
                    ?>
                    <table class="widefat" style="max-width: 400px;">
                        <tr><td>State Pages</td><td><strong><?php echo count($states); ?></strong></td></tr>
                        <tr><td>City Pages</td><td><strong><?php echo $total_cities; ?></strong></td></tr>
                        <tr><td>Service Pages</td><td><strong><?php echo count($services); ?></strong></td></tr>
                        <tr><td>Service + Location Pages</td><td><strong><?php echo count($states) * count($services); ?>+</strong></td></tr>
                        <tr><td>Trainer Profiles</td><td><strong><?php echo $trainer_count; ?></strong></td></tr>
                        <tr><td style="border-top: 2px solid #ddd;"><strong>Total Indexed URLs</strong></td><td style="border-top: 2px solid #ddd;"><strong><?php echo $total_pages + $trainer_count; ?>+</strong></td></tr>
                    </table>
                    <?php } ?>
                </div>
            </div>
            
            <script>
            jQuery(function($) {
                $('#ptp-ping-engines').on('click', function() {
                    var $btn = $(this);
                    var $result = $('#ptp-ping-result');
                    
                    $btn.prop('disabled', true).text('Pinging...');
                    $result.text('');
                    
                    $.post(ajaxurl, {
                        action: 'ptp_ping_search_engines',
                        nonce: '<?php echo wp_create_nonce('ptp_sitemap'); ?>'
                    }, function(response) {
                        $btn.prop('disabled', false).text('Ping Google & Bing');
                        
                        if (response.success) {
                            var results = [];
                            for (var engine in response.data) {
                                results.push(engine + ': ' + (response.data[engine] ? '✓' : '✗'));
                            }
                            $result.html('<span style="color: green;">' + results.join(', ') + '</span>');
                        } else {
                            $result.html('<span style="color: red;">Error pinging search engines</span>');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * AJAX: Ping search engines
     */
    public static function ajax_ping_search_engines() {
        check_ajax_referer('ptp_sitemap', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $results = self::ping_search_engines();
        wp_send_json_success($results);
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_SEO_Sitemap', 'init'));
