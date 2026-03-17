<?php
/**
 * PTP SEO Location Pages
 * 
 * Comprehensive local SEO system for:
 * - State landing pages
 * - City/town landing pages  
 * - Training type pages (private training, camps, clinics)
 * - Combined location + service pages
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_SEO_Locations {
    
    /**
     * Service areas configuration
     */
    private static $states = array(
        'pennsylvania' => array(
            'name' => 'Pennsylvania',
            'abbr' => 'PA',
            'major_cities' => array(
                'philadelphia' => array('name' => 'Philadelphia', 'lat' => 39.9526, 'lng' => -75.1652, 'population' => 1584000, 'metro' => true),
                'king-of-prussia' => array('name' => 'King of Prussia', 'lat' => 40.0893, 'lng' => -75.3963, 'population' => 22000),
                'wayne' => array('name' => 'Wayne', 'lat' => 40.0440, 'lng' => -75.3877, 'population' => 32000),
                'bryn-mawr' => array('name' => 'Bryn Mawr', 'lat' => 40.0229, 'lng' => -75.3163, 'population' => 4000),
                'villanova' => array('name' => 'Villanova', 'lat' => 40.0372, 'lng' => -75.3426, 'population' => 9000),
                'radnor' => array('name' => 'Radnor', 'lat' => 40.0465, 'lng' => -75.3593, 'population' => 31500),
                'newtown-square' => array('name' => 'Newtown Square', 'lat' => 39.9851, 'lng' => -75.4082, 'population' => 12500),
                'media' => array('name' => 'Media', 'lat' => 39.9168, 'lng' => -75.3877, 'population' => 6000),
                'west-chester' => array('name' => 'West Chester', 'lat' => 39.9607, 'lng' => -75.6055, 'population' => 20000),
                'malvern' => array('name' => 'Malvern', 'lat' => 40.0362, 'lng' => -75.5135, 'population' => 3500),
                'exton' => array('name' => 'Exton', 'lat' => 40.0290, 'lng' => -75.6210, 'population' => 5000),
                'downingtown' => array('name' => 'Downingtown', 'lat' => 40.0062, 'lng' => -75.7032, 'population' => 8000),
                'collegeville' => array('name' => 'Collegeville', 'lat' => 40.1854, 'lng' => -75.4516, 'population' => 5100),
                'conshohocken' => array('name' => 'Conshohocken', 'lat' => 40.0793, 'lng' => -75.3016, 'population' => 8200),
                'ardmore' => array('name' => 'Ardmore', 'lat' => 40.0065, 'lng' => -75.2905, 'population' => 13000),
                'haverford' => array('name' => 'Haverford', 'lat' => 40.0107, 'lng' => -75.3035, 'population' => 49000),
                'springfield' => array('name' => 'Springfield', 'lat' => 39.9312, 'lng' => -75.3205, 'population' => 24000),
                'doylestown' => array('name' => 'Doylestown', 'lat' => 40.3101, 'lng' => -75.1299, 'population' => 8600),
                'yardley' => array('name' => 'Yardley', 'lat' => 40.2454, 'lng' => -74.8363, 'population' => 2500),
                'newtown' => array('name' => 'Newtown', 'lat' => 40.2290, 'lng' => -74.9371, 'population' => 2200),
                'blue-bell' => array('name' => 'Blue Bell', 'lat' => 40.1526, 'lng' => -75.2663, 'population' => 6000),
                'lansdale' => array('name' => 'Lansdale', 'lat' => 40.2415, 'lng' => -75.2835, 'population' => 17400),
                'horsham' => array('name' => 'Horsham', 'lat' => 40.1779, 'lng' => -75.1271, 'population' => 26000),
                'ambler' => array('name' => 'Ambler', 'lat' => 40.1543, 'lng' => -75.2213, 'population' => 6500),
                'chadds-ford' => array('name' => 'Chadds Ford', 'lat' => 39.8712, 'lng' => -75.5913, 'population' => 3700),
                'kennett-square' => array('name' => 'Kennett Square', 'lat' => 39.8468, 'lng' => -75.7116, 'population' => 6100),
                'glen-mills' => array('name' => 'Glen Mills', 'lat' => 39.9007, 'lng' => -75.4963, 'population' => 10000),
            ),
            'regions' => array('Main Line', 'Delaware County', 'Chester County', 'Montgomery County', 'Bucks County', 'Philadelphia County'),
        ),
        'new-jersey' => array(
            'name' => 'New Jersey',
            'abbr' => 'NJ',
            'major_cities' => array(
                'cherry-hill' => array('name' => 'Cherry Hill', 'lat' => 39.9348, 'lng' => -75.0307, 'population' => 74500, 'metro' => true),
                'moorestown' => array('name' => 'Moorestown', 'lat' => 39.9687, 'lng' => -74.9488, 'population' => 20700),
                'haddonfield' => array('name' => 'Haddonfield', 'lat' => 39.8915, 'lng' => -75.0377, 'population' => 11500),
                'marlton' => array('name' => 'Marlton', 'lat' => 39.8912, 'lng' => -74.9221, 'population' => 10300),
                'medford' => array('name' => 'Medford', 'lat' => 39.8548, 'lng' => -74.8227, 'population' => 23900),
                'mount-laurel' => array('name' => 'Mount Laurel', 'lat' => 39.9340, 'lng' => -74.8910, 'population' => 43500),
                'voorhees' => array('name' => 'Voorhees', 'lat' => 39.8440, 'lng' => -74.9527, 'population' => 29800),
                'collingswood' => array('name' => 'Collingswood', 'lat' => 39.9182, 'lng' => -75.0716, 'population' => 14000),
                'westmont' => array('name' => 'Westmont', 'lat' => 39.9065, 'lng' => -75.0552, 'population' => 13500),
                'princeton' => array('name' => 'Princeton', 'lat' => 40.3573, 'lng' => -74.6672, 'population' => 31800),
                'lawrenceville' => array('name' => 'Lawrenceville', 'lat' => 40.2976, 'lng' => -74.7394, 'population' => 4100),
                'hamilton' => array('name' => 'Hamilton', 'lat' => 40.2176, 'lng' => -74.7094, 'population' => 92000),
                'ewing' => array('name' => 'Ewing', 'lat' => 40.2698, 'lng' => -74.7877, 'population' => 36700),
                'west-windsor' => array('name' => 'West Windsor', 'lat' => 40.2973, 'lng' => -74.6232, 'population' => 28500),
                'pennington' => array('name' => 'Pennington', 'lat' => 40.3284, 'lng' => -74.7916, 'population' => 2700),
                'hopewell' => array('name' => 'Hopewell', 'lat' => 40.3884, 'lng' => -74.7599, 'population' => 2000),
            ),
            'regions' => array('South Jersey', 'Central Jersey', 'Burlington County', 'Camden County', 'Mercer County'),
        ),
        'delaware' => array(
            'name' => 'Delaware',
            'abbr' => 'DE',
            'major_cities' => array(
                'wilmington' => array('name' => 'Wilmington', 'lat' => 39.7447, 'lng' => -75.5484, 'population' => 71000, 'metro' => true),
                'newark' => array('name' => 'Newark', 'lat' => 39.6837, 'lng' => -75.7497, 'population' => 33600),
                'hockessin' => array('name' => 'Hockessin', 'lat' => 39.7854, 'lng' => -75.6963, 'population' => 14000),
                'greenville' => array('name' => 'Greenville', 'lat' => 39.8018, 'lng' => -75.5977, 'population' => 2300),
                'pike-creek' => array('name' => 'Pike Creek', 'lat' => 39.7312, 'lng' => -75.6993, 'population' => 8500),
                'bear' => array('name' => 'Bear', 'lat' => 39.6293, 'lng' => -75.6555, 'population' => 22000),
                'middletown' => array('name' => 'Middletown', 'lat' => 39.4496, 'lng' => -75.7163, 'population' => 22000),
            ),
            'regions' => array('New Castle County', 'Brandywine Valley'),
        ),
        'maryland' => array(
            'name' => 'Maryland',
            'abbr' => 'MD',
            'major_cities' => array(
                'baltimore' => array('name' => 'Baltimore', 'lat' => 39.2904, 'lng' => -76.6122, 'population' => 586000, 'metro' => true),
                'towson' => array('name' => 'Towson', 'lat' => 39.4015, 'lng' => -76.6019, 'population' => 57500),
                'columbia' => array('name' => 'Columbia', 'lat' => 39.2037, 'lng' => -76.8610, 'population' => 105000),
                'ellicott-city' => array('name' => 'Ellicott City', 'lat' => 39.2674, 'lng' => -76.7983, 'population' => 73000),
                'bethesda' => array('name' => 'Bethesda', 'lat' => 38.9848, 'lng' => -77.0947, 'population' => 68000),
                'rockville' => array('name' => 'Rockville', 'lat' => 39.0840, 'lng' => -77.1528, 'population' => 68000),
                'bel-air' => array('name' => 'Bel Air', 'lat' => 39.5351, 'lng' => -76.3483, 'population' => 10500),
                'annapolis' => array('name' => 'Annapolis', 'lat' => 38.9784, 'lng' => -76.4922, 'population' => 40800),
            ),
            'regions' => array('Baltimore Metro', 'Howard County', 'Montgomery County', 'Harford County'),
        ),
        'new-york' => array(
            'name' => 'New York',
            'abbr' => 'NY',
            'major_cities' => array(
                'brooklyn' => array('name' => 'Brooklyn', 'lat' => 40.6782, 'lng' => -73.9442, 'population' => 2600000, 'metro' => true),
                'queens' => array('name' => 'Queens', 'lat' => 40.7282, 'lng' => -73.7949, 'population' => 2300000, 'metro' => true),
                'staten-island' => array('name' => 'Staten Island', 'lat' => 40.5795, 'lng' => -74.1502, 'population' => 476000),
                'westchester' => array('name' => 'Westchester', 'lat' => 41.1220, 'lng' => -73.7949, 'population' => 980000),
                'white-plains' => array('name' => 'White Plains', 'lat' => 41.0340, 'lng' => -73.7629, 'population' => 58500),
                'yonkers' => array('name' => 'Yonkers', 'lat' => 40.9312, 'lng' => -73.8987, 'population' => 200000),
                'scarsdale' => array('name' => 'Scarsdale', 'lat' => 41.0051, 'lng' => -73.7846, 'population' => 18000),
                'rye' => array('name' => 'Rye', 'lat' => 40.9826, 'lng' => -73.6835, 'population' => 16000),
                'mamaroneck' => array('name' => 'Mamaroneck', 'lat' => 40.9490, 'lng' => -73.7321, 'population' => 19000),
                'larchmont' => array('name' => 'Larchmont', 'lat' => 40.9276, 'lng' => -73.7518, 'population' => 6200),
                'bronxville' => array('name' => 'Bronxville', 'lat' => 40.9384, 'lng' => -73.8321, 'population' => 6500),
                'dobbs-ferry' => array('name' => 'Dobbs Ferry', 'lat' => 41.0115, 'lng' => -73.8721, 'population' => 11000),
                'long-island' => array('name' => 'Long Island', 'lat' => 40.7891, 'lng' => -73.1350, 'population' => 2800000, 'metro' => true),
                'garden-city' => array('name' => 'Garden City', 'lat' => 40.7268, 'lng' => -73.6343, 'population' => 23000),
                'great-neck' => array('name' => 'Great Neck', 'lat' => 40.8029, 'lng' => -73.7285, 'population' => 10500),
                'manhasset' => array('name' => 'Manhasset', 'lat' => 40.7979, 'lng' => -73.7004, 'population' => 8500),
                'roslyn' => array('name' => 'Roslyn', 'lat' => 40.7998, 'lng' => -73.6513, 'population' => 3000),
                'huntington' => array('name' => 'Huntington', 'lat' => 40.8682, 'lng' => -73.4257, 'population' => 18500),
            ),
            'regions' => array('NYC Metro', 'Westchester County', 'Nassau County', 'Suffolk County', 'Long Island'),
        ),
    );
    
    /**
     * Training service types
     */
    private static $services = array(
        'private-soccer-training' => array(
            'name' => 'Private Soccer Training',
            'short' => 'Private Training',
            'description' => '1-on-1 personalized soccer training with elite coaches',
            'keywords' => array('private soccer training', 'personal soccer coach', '1 on 1 soccer training', 'private soccer lessons'),
            'icon' => 'user',
        ),
        'soccer-camps' => array(
            'name' => 'Soccer Camps',
            'short' => 'Camps',
            'description' => 'Week-long intensive soccer camps led by professional players',
            'keywords' => array('soccer camps', 'youth soccer camp', 'summer soccer camp', 'soccer training camp'),
            'icon' => 'camp',
        ),
        'soccer-clinics' => array(
            'name' => 'Soccer Clinics',
            'short' => 'Clinics',
            'description' => 'Specialized skill-focused soccer clinics and workshops',
            'keywords' => array('soccer clinics', 'soccer skills clinic', 'soccer workshop', 'elite soccer clinic'),
            'icon' => 'clinic',
        ),
        'group-soccer-training' => array(
            'name' => 'Group Soccer Training',
            'short' => 'Group Training',
            'description' => 'Small group training sessions for focused skill development',
            'keywords' => array('group soccer training', 'small group soccer', 'soccer group lessons', 'team training'),
            'icon' => 'group',
        ),
        'goalkeeper-training' => array(
            'name' => 'Goalkeeper Training',
            'short' => 'GK Training',
            'description' => 'Specialized goalkeeper training and development',
            'keywords' => array('goalkeeper training', 'soccer goalie training', 'gk coach', 'goalkeeper lessons'),
            'icon' => 'goalkeeper',
        ),
        'youth-soccer-training' => array(
            'name' => 'Youth Soccer Training',
            'short' => 'Youth Training',
            'description' => 'Age-appropriate soccer training for young players',
            'keywords' => array('youth soccer training', 'kids soccer training', 'junior soccer lessons', 'youth soccer coach'),
            'icon' => 'youth',
        ),
    );
    
    /**
     * Initialize SEO locations
     */
    public static function init() {
        // Register rewrite rules
        add_action('init', array(__CLASS__, 'register_rewrites'), 10);
        
        // Handle location pages
        add_filter('template_include', array(__CLASS__, 'load_location_template'));
        
        // Add query vars
        add_filter('query_vars', array(__CLASS__, 'add_query_vars'));
        
        // Generate sitemaps
        add_action('init', array(__CLASS__, 'register_sitemap_provider'));
        
        // Add to WordPress sitemap
        add_filter('wp_sitemaps_add_provider', array(__CLASS__, 'add_sitemap_provider'), 10, 2);
        
        // Admin menu for SEO
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_generate_seo_pages', array(__CLASS__, 'ajax_generate_pages'));
        add_action('wp_ajax_ptp_seo_stats', array(__CLASS__, 'ajax_get_stats'));
        add_action('wp_ajax_ptp_flush_seo_rewrites', array(__CLASS__, 'ajax_flush_rewrites'));
        
        // Schema output
        add_action('wp_head', array(__CLASS__, 'output_location_schema'), 5);
        
        // Meta tags
        add_action('wp_head', array(__CLASS__, 'output_location_meta'), 3);
        
        // Breadcrumbs
        add_shortcode('ptp_seo_breadcrumbs', array(__CLASS__, 'breadcrumbs_shortcode'));
    }
    
    /**
     * Register URL rewrites for location pages
     */
    public static function register_rewrites() {
        // State pages: /soccer-training/pennsylvania/
        add_rewrite_rule(
            'soccer-training/([a-z-]+)/?$',
            'index.php?ptp_seo_page=state&ptp_state=$matches[1]',
            'top'
        );
        
        // City pages: /soccer-training/pennsylvania/philadelphia/
        add_rewrite_rule(
            'soccer-training/([a-z-]+)/([a-z-]+)/?$',
            'index.php?ptp_seo_page=city&ptp_state=$matches[1]&ptp_city=$matches[2]',
            'top'
        );
        
        // Service pages: /private-soccer-training/
        add_rewrite_rule(
            '(private-soccer-training|soccer-camps|soccer-clinics|group-soccer-training|goalkeeper-training|youth-soccer-training)/?$',
            'index.php?ptp_seo_page=service&ptp_service=$matches[1]',
            'top'
        );
        
        // Service + State: /private-soccer-training/pennsylvania/
        add_rewrite_rule(
            '(private-soccer-training|soccer-camps|soccer-clinics|group-soccer-training|goalkeeper-training|youth-soccer-training)/([a-z-]+)/?$',
            'index.php?ptp_seo_page=service_state&ptp_service=$matches[1]&ptp_state=$matches[2]',
            'top'
        );
        
        // Service + City: /private-soccer-training/pennsylvania/philadelphia/
        add_rewrite_rule(
            '(private-soccer-training|soccer-camps|soccer-clinics|group-soccer-training|goalkeeper-training|youth-soccer-training)/([a-z-]+)/([a-z-]+)/?$',
            'index.php?ptp_seo_page=service_city&ptp_service=$matches[1]&ptp_state=$matches[2]&ptp_city=$matches[3]',
            'top'
        );
        
        // Near me pages: /soccer-training-near-me/
        add_rewrite_rule(
            'soccer-training-near-me/?$',
            'index.php?ptp_seo_page=near_me',
            'top'
        );
        
        // Near me with service: /private-soccer-training-near-me/
        add_rewrite_rule(
            '(private-soccer-training|soccer-camps|soccer-clinics|goalkeeper-training)-near-me/?$',
            'index.php?ptp_seo_page=service_near_me&ptp_service=$matches[1]',
            'top'
        );
        
        // Find trainers: /find-soccer-trainers/philadelphia/
        add_rewrite_rule(
            'find-soccer-trainers/([a-z-]+)/?$',
            'index.php?ptp_seo_page=find_trainers&ptp_city=$matches[1]',
            'top'
        );
    }
    
    /**
     * Add query vars
     */
    public static function add_query_vars($vars) {
        $vars[] = 'ptp_seo_page';
        $vars[] = 'ptp_state';
        $vars[] = 'ptp_city';
        $vars[] = 'ptp_service';
        return $vars;
    }
    
    /**
     * Load appropriate template for location pages
     */
    public static function load_location_template($template) {
        $seo_page = get_query_var('ptp_seo_page');
        
        if (!$seo_page) {
            return $template;
        }
        
        // Check for custom template in theme
        $custom_template = locate_template('ptp-seo-location.php');
        if ($custom_template) {
            return $custom_template;
        }
        
        // Use plugin template
        $plugin_template = PTP_PLUGIN_DIR . 'templates/seo-location-page.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
        
        return $template;
    }
    
    /**
     * Get page data based on query vars
     */
    public static function get_page_data() {
        $page_type = get_query_var('ptp_seo_page');
        $state_slug = get_query_var('ptp_state');
        $city_slug = get_query_var('ptp_city');
        $service_slug = get_query_var('ptp_service');
        
        $data = array(
            'type' => $page_type,
            'state' => null,
            'city' => null,
            'service' => null,
            'trainers' => array(),
            'camps' => array(),
            'title' => '',
            'description' => '',
            'h1' => '',
            'breadcrumbs' => array(),
            'nearby_cities' => array(),
            'faqs' => array(),
        );
        
        // Get state data
        if ($state_slug && isset(self::$states[$state_slug])) {
            $data['state'] = self::$states[$state_slug];
            $data['state']['slug'] = $state_slug;
        }
        
        // Get city data
        if ($city_slug && $data['state'] && isset($data['state']['major_cities'][$city_slug])) {
            $data['city'] = $data['state']['major_cities'][$city_slug];
            $data['city']['slug'] = $city_slug;
        }
        
        // Get service data
        if ($service_slug && isset(self::$services[$service_slug])) {
            $data['service'] = self::$services[$service_slug];
            $data['service']['slug'] = $service_slug;
        }
        
        // Build page content based on type
        switch ($page_type) {
            case 'state':
                $data = self::build_state_page($data);
                break;
            case 'city':
                $data = self::build_city_page($data);
                break;
            case 'service':
                $data = self::build_service_page($data);
                break;
            case 'service_state':
                $data = self::build_service_state_page($data);
                break;
            case 'service_city':
                $data = self::build_service_city_page($data);
                break;
            case 'near_me':
                $data = self::build_near_me_page($data);
                break;
            case 'find_trainers':
                $data = self::build_find_trainers_page($data);
                break;
        }
        
        // Get trainers for location
        $data['trainers'] = self::get_trainers_for_location($data);
        
        // Get upcoming camps
        $data['camps'] = self::get_camps_for_location($data);
        
        // Build FAQs
        $data['faqs'] = self::build_faqs($data);
        
        return $data;
    }
    
    /**
     * Build state landing page
     */
    private static function build_state_page($data) {
        $state = $data['state'];
        
        $data['title'] = "Soccer Training in {$state['name']} | Private Lessons & Camps | PTP";
        $data['description'] = "Find elite soccer training in {$state['name']}. Book private lessons with NCAA D1 athletes and professional players. Camps and clinics across {$state['abbr']}.";
        $data['h1'] = "Soccer Training in {$state['name']}";
        
        $data['breadcrumbs'] = array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => 'Soccer Training', 'url' => home_url('/soccer-training/')),
            array('label' => $state['name'], 'url' => null),
        );
        
        // Get all cities in state
        $data['all_cities'] = $state['major_cities'];
        
        return $data;
    }
    
    /**
     * Build city landing page
     */
    private static function build_city_page($data) {
        $state = $data['state'];
        $city = $data['city'];
        
        $data['title'] = "Soccer Training in {$city['name']}, {$state['abbr']} | Private Coaches Near You | PTP";
        $data['description'] = "Book private soccer training in {$city['name']}, {$state['abbr']}. Train with NCAA D1 athletes and pro players. Personalized 1-on-1 sessions, camps, and clinics.";
        $data['h1'] = "Soccer Training in {$city['name']}, {$state['abbr']}";
        
        $data['breadcrumbs'] = array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => 'Soccer Training', 'url' => home_url('/soccer-training/')),
            array('label' => $state['name'], 'url' => home_url("/soccer-training/{$state['slug']}/")),
            array('label' => $city['name'], 'url' => null),
        );
        
        // Get nearby cities
        $data['nearby_cities'] = self::get_nearby_cities($city, $state);
        
        return $data;
    }
    
    /**
     * Build service landing page
     */
    private static function build_service_page($data) {
        $service = $data['service'];
        
        $data['title'] = "{$service['name']} | Elite Youth Coaches | PTP";
        $data['description'] = "{$service['description']}. Book verified NCAA D1 athletes and professional players for personalized training sessions.";
        $data['h1'] = $service['name'];
        
        $data['breadcrumbs'] = array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => $service['name'], 'url' => null),
        );
        
        // List all available states
        $data['available_states'] = self::$states;
        
        return $data;
    }
    
    /**
     * Build service + state page
     */
    private static function build_service_state_page($data) {
        $state = $data['state'];
        $service = $data['service'];
        
        $data['title'] = "{$service['name']} in {$state['name']} | Book Elite Coaches | PTP";
        $data['description'] = "Find {$service['short']} in {$state['name']}. Train with NCAA D1 athletes and professional players. Book sessions across {$state['abbr']}.";
        $data['h1'] = "{$service['name']} in {$state['name']}";
        
        $data['breadcrumbs'] = array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => $service['name'], 'url' => home_url("/{$service['slug']}/")),
            array('label' => $state['name'], 'url' => null),
        );
        
        $data['all_cities'] = $state['major_cities'];
        
        return $data;
    }
    
    /**
     * Build service + city page (highest intent)
     */
    private static function build_service_city_page($data) {
        $state = $data['state'];
        $city = $data['city'];
        $service = $data['service'];
        
        $data['title'] = "{$service['name']} in {$city['name']}, {$state['abbr']} | Book Now | PTP";
        $data['description'] = "Book {$service['short']} in {$city['name']}, {$state['abbr']}. Train with verified NCAA D1 athletes and pro players. Personalized coaching that fits your schedule.";
        $data['h1'] = "{$service['name']} in {$city['name']}, {$state['abbr']}";
        
        $data['breadcrumbs'] = array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => $service['name'], 'url' => home_url("/{$service['slug']}/")),
            array('label' => $state['name'], 'url' => home_url("/{$service['slug']}/{$state['slug']}/")),
            array('label' => $city['name'], 'url' => null),
        );
        
        $data['nearby_cities'] = self::get_nearby_cities($city, $state);
        
        return $data;
    }
    
    /**
     * Build near me page
     */
    private static function build_near_me_page($data) {
        $data['title'] = "Soccer Training Near Me | Find Local Coaches | PTP";
        $data['description'] = "Find soccer training near you. Browse verified NCAA D1 athletes and professional players available for private lessons, camps, and clinics in your area.";
        $data['h1'] = "Soccer Training Near Me";
        
        $data['breadcrumbs'] = array(
            array('label' => 'Home', 'url' => home_url('/')),
            array('label' => 'Soccer Training Near Me', 'url' => null),
        );
        
        $data['available_states'] = self::$states;
        $data['all_services'] = self::$services;
        
        return $data;
    }
    
    /**
     * Build find trainers page
     */
    private static function build_find_trainers_page($data) {
        $city_slug = get_query_var('ptp_city');
        
        // Find city across all states
        foreach (self::$states as $state_slug => $state) {
            if (isset($state['major_cities'][$city_slug])) {
                $data['state'] = $state;
                $data['state']['slug'] = $state_slug;
                $data['city'] = $state['major_cities'][$city_slug];
                $data['city']['slug'] = $city_slug;
                break;
            }
        }
        
        if ($data['city']) {
            $city = $data['city'];
            $state = $data['state'];
            
            $data['title'] = "Find Soccer Trainers in {$city['name']} | Book Private Lessons | PTP";
            $data['description'] = "Browse available soccer trainers in {$city['name']}, {$state['abbr']}. View profiles, ratings, and book private training sessions online.";
            $data['h1'] = "Soccer Trainers in {$city['name']}";
        } else {
            $data['title'] = "Find Soccer Trainers | PTP";
            $data['h1'] = "Find Soccer Trainers";
        }
        
        return $data;
    }
    
    /**
     * Get trainers for a specific location
     */
    private static function get_trainers_for_location($data) {
        if (!class_exists('PTP_Trainer')) {
            return array();
        }
        
        $args = array(
            'status' => 'active',
            'limit' => 12,
        );
        
        // Filter by location if city specified
        if ($data['city']) {
            $args['location_search'] = $data['city']['name'];
        } elseif ($data['state']) {
            $args['state'] = $data['state']['abbr'];
        }
        
        return PTP_Trainer::get_all($args);
    }
    
    /**
     * Get camps for location
     */
    private static function get_camps_for_location($data) {
        // Get upcoming camps - integrate with PTP Native products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 6,
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => array('camps', 'clinics'),
                ),
            ),
            'meta_query' => array(
                array(
                    'key' => '_camp_start_date',
                    'value' => date('Y-m-d'),
                    'compare' => '>=',
                    'type' => 'DATE',
                ),
            ),
            'orderby' => 'meta_value',
            'meta_key' => '_camp_start_date',
            'order' => 'ASC',
        );
        
        // Filter by state/city if available
        if ($data['state']) {
            $args['meta_query'][] = array(
                'key' => '_camp_state',
                'value' => $data['state']['abbr'],
            );
        }
        
        $camps = get_posts($args);
        
        return $camps;
    }
    
    /**
     * Get nearby cities
     */
    private static function get_nearby_cities($city, $state) {
        $nearby = array();
        $city_lat = $city['lat'];
        $city_lng = $city['lng'];
        
        foreach ($state['major_cities'] as $slug => $other_city) {
            if ($slug === $city['slug']) continue;
            
            // Calculate distance
            $distance = self::calculate_distance($city_lat, $city_lng, $other_city['lat'], $other_city['lng']);
            
            if ($distance <= 30) { // Within 30 miles
                $other_city['slug'] = $slug;
                $other_city['distance'] = round($distance, 1);
                $nearby[] = $other_city;
            }
        }
        
        // Sort by distance
        usort($nearby, function($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        
        return array_slice($nearby, 0, 8);
    }
    
    /**
     * Calculate distance between two coordinates
     */
    private static function calculate_distance($lat1, $lng1, $lat2, $lng2) {
        $earth_radius = 3959; // Miles
        
        $lat1_rad = deg2rad($lat1);
        $lat2_rad = deg2rad($lat2);
        $delta_lat = deg2rad($lat2 - $lat1);
        $delta_lng = deg2rad($lng2 - $lng1);
        
        $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
             cos($lat1_rad) * cos($lat2_rad) *
             sin($delta_lng / 2) * sin($delta_lng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earth_radius * $c;
    }
    
    /**
     * Build FAQs for location
     */
    private static function build_faqs($data) {
        $faqs = array();
        $location_name = '';
        
        if ($data['city'] && $data['state']) {
            $location_name = $data['city']['name'] . ', ' . $data['state']['abbr'];
        } elseif ($data['state']) {
            $location_name = $data['state']['name'];
        }
        
        $service_name = $data['service'] ? strtolower($data['service']['short']) : 'soccer training';
        
        $faqs[] = array(
            'question' => "How much does {$service_name} cost" . ($location_name ? " in {$location_name}" : "") . "?",
            'answer' => "Private training sessions typically range from $65-150 per hour depending on the trainer's experience and credentials. Many trainers offer package discounts for multiple sessions. NCAA D1 athletes and professional players may charge premium rates.",
        );
        
        $faqs[] = array(
            'question' => "What age groups do you train" . ($location_name ? " in {$location_name}" : "") . "?",
            'answer' => "Our trainers work with all ages from 5 years old through adult. Training is customized to each player's age, skill level, and development goals. Most of our trainers specialize in youth development (ages 8-18).",
        );
        
        $faqs[] = array(
            'question' => "Who are your trainers?",
            'answer' => "All PTP trainers are verified NCAA Division 1 athletes, professional players, or coaches with elite playing backgrounds. Every trainer goes through a background check and vetting process before joining our platform.",
        );
        
        $faqs[] = array(
            'question' => "Where do training sessions take place?",
            'answer' => "Sessions can be held at your preferred location - local parks, school fields, indoor facilities, or your backyard. Many trainers are flexible with location and will travel within their service area.",
        );
        
        $faqs[] = array(
            'question' => "How do I book a session?",
            'answer' => "Browse trainer profiles, check their availability calendar, and book directly online. You can message trainers before booking to discuss your goals. Payment is secure and sessions can be rescheduled with 24-hour notice.",
        );
        
        if ($data['service'] && $data['service']['slug'] === 'soccer-camps') {
            $faqs[] = array(
                'question' => "What's included in your soccer camps?",
                'answer' => "Our camps include daily training sessions led by professional players and D1 athletes, skill competitions, small-sided games, and personalized feedback. Campers receive a jersey, player card, and daily progress videos.",
            );
        }
        
        return $faqs;
    }
    
    /**
     * Output location schema markup
     */
    public static function output_location_schema() {
        $seo_page = get_query_var('ptp_seo_page');
        if (!$seo_page) return;
        
        $data = self::get_page_data();
        $schema = array();
        
        // Organization schema (always)
        $schema[] = array(
            '@context' => 'https://schema.org',
            '@type' => 'SportsOrganization',
            'name' => 'PTP Soccer Camps',
            'alternateName' => 'Players Teaching Players',
            'url' => home_url('/'),
            'logo' => get_option('ptp_logo_url', home_url('/wp-content/uploads/ptp-logo.png')),
            'description' => 'Elite soccer training with NCAA D1 athletes and professional players.',
            'sport' => 'Soccer',
            'areaServed' => array(
                array('@type' => 'State', 'name' => 'Pennsylvania'),
                array('@type' => 'State', 'name' => 'New Jersey'),
                array('@type' => 'State', 'name' => 'Delaware'),
                array('@type' => 'State', 'name' => 'Maryland'),
                array('@type' => 'State', 'name' => 'New York'),
            ),
            'sameAs' => array(
                'https://www.instagram.com/ptpsoccercamps/',
                'https://www.facebook.com/ptpsoccercamps/',
            ),
        );
        
        // LocalBusiness schema for city pages
        if ($data['city'] && $data['state']) {
            $schema[] = array(
                '@context' => 'https://schema.org',
                '@type' => 'SportsActivityLocation',
                'name' => "PTP Soccer Training - {$data['city']['name']}",
                'description' => $data['description'],
                'url' => self::get_current_url(),
                'sport' => 'Soccer',
                'address' => array(
                    '@type' => 'PostalAddress',
                    'addressLocality' => $data['city']['name'],
                    'addressRegion' => $data['state']['abbr'],
                    'addressCountry' => 'US',
                ),
                'geo' => array(
                    '@type' => 'GeoCoordinates',
                    'latitude' => $data['city']['lat'],
                    'longitude' => $data['city']['lng'],
                ),
                'priceRange' => '$$',
                'aggregateRating' => array(
                    '@type' => 'AggregateRating',
                    'ratingValue' => '4.9',
                    'reviewCount' => '150',
                ),
            );
        }
        
        // Service schema
        if ($data['service']) {
            $schema[] = array(
                '@context' => 'https://schema.org',
                '@type' => 'Service',
                'name' => $data['service']['name'],
                'description' => $data['service']['description'],
                'provider' => array(
                    '@type' => 'SportsOrganization',
                    'name' => 'PTP Soccer Camps',
                ),
                'areaServed' => $data['state'] ? array(
                    '@type' => 'State',
                    'name' => $data['state']['name'],
                ) : null,
                'offers' => array(
                    '@type' => 'Offer',
                    'priceRange' => '$65-$150',
                    'priceCurrency' => 'USD',
                ),
            );
        }
        
        // FAQ schema
        if (!empty($data['faqs'])) {
            $faq_schema = array(
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array(),
            );
            
            foreach ($data['faqs'] as $faq) {
                $faq_schema['mainEntity'][] = array(
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text' => $faq['answer'],
                    ),
                );
            }
            
            $schema[] = $faq_schema;
        }
        
        // Breadcrumb schema
        if (!empty($data['breadcrumbs'])) {
            $breadcrumb_schema = array(
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => array(),
            );
            
            $position = 1;
            foreach ($data['breadcrumbs'] as $crumb) {
                $breadcrumb_schema['itemListElement'][] = array(
                    '@type' => 'ListItem',
                    'position' => $position,
                    'name' => $crumb['label'],
                    'item' => $crumb['url'] ?: self::get_current_url(),
                );
                $position++;
            }
            
            $schema[] = $breadcrumb_schema;
        }
        
        // Output schemas
        foreach ($schema as $item) {
            echo '<script type="application/ld+json">' . wp_json_encode($item, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>' . "\n";
        }
    }
    
    /**
     * Output meta tags for location pages
     */
    public static function output_location_meta() {
        $seo_page = get_query_var('ptp_seo_page');
        if (!$seo_page) return;
        
        $data = self::get_page_data();
        $url = self::get_current_url();
        $image = get_option('ptp_default_og_image', home_url('/wp-content/uploads/ptp-og-image.jpg'));
        
        // Basic meta
        echo '<meta name="description" content="' . esc_attr($data['description']) . '">' . "\n";
        echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        
        // Keywords
        $keywords = array('soccer training', 'private soccer lessons', 'youth soccer');
        if ($data['city']) $keywords[] = "soccer {$data['city']['name']}";
        if ($data['state']) $keywords[] = "soccer {$data['state']['name']}";
        if ($data['service']) $keywords = array_merge($keywords, $data['service']['keywords']);
        echo '<meta name="keywords" content="' . esc_attr(implode(', ', $keywords)) . '">' . "\n";
        
        // Geo meta for local SEO
        if ($data['city']) {
            echo '<meta name="geo.region" content="US-' . esc_attr($data['state']['abbr']) . '">' . "\n";
            echo '<meta name="geo.placename" content="' . esc_attr($data['city']['name']) . '">' . "\n";
            echo '<meta name="geo.position" content="' . esc_attr($data['city']['lat'] . ';' . $data['city']['lng']) . '">' . "\n";
            echo '<meta name="ICBM" content="' . esc_attr($data['city']['lat'] . ', ' . $data['city']['lng']) . '">' . "\n";
        }
        
        // Open Graph
        echo '<meta property="og:type" content="website">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($data['title']) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($data['description']) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:site_name" content="PTP Soccer Camps">' . "\n";
        echo '<meta property="og:locale" content="en_US">' . "\n";
        echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        echo '<meta property="og:image:width" content="1200">' . "\n";
        echo '<meta property="og:image:height" content="630">' . "\n";
        
        // Twitter
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($data['title']) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($data['description']) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
    }
    
    /**
     * Get current URL
     */
    private static function get_current_url() {
        global $wp;
        return home_url(add_query_arg(array(), $wp->request)) . '/';
    }
    
    /**
     * Breadcrumbs shortcode
     */
    public static function breadcrumbs_shortcode($atts) {
        $seo_page = get_query_var('ptp_seo_page');
        if (!$seo_page) return '';
        
        $data = self::get_page_data();
        if (empty($data['breadcrumbs'])) return '';
        
        ob_start();
        ?>
        <nav class="ptp-seo-breadcrumbs" aria-label="Breadcrumb">
            <ol class="ptp-breadcrumb-list">
                <?php foreach ($data['breadcrumbs'] as $i => $crumb): ?>
                    <li class="ptp-breadcrumb-item">
                        <?php if ($crumb['url']): ?>
                            <a href="<?php echo esc_url($crumb['url']); ?>"><?php echo esc_html($crumb['label']); ?></a>
                        <?php else: ?>
                            <span aria-current="page"><?php echo esc_html($crumb['label']); ?></span>
                        <?php endif; ?>
                        <?php if ($i < count($data['breadcrumbs']) - 1): ?>
                            <span class="ptp-breadcrumb-sep" aria-hidden="true">›</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <style>
            .ptp-seo-breadcrumbs { margin: 0 0 20px; font-size: 14px; }
            .ptp-breadcrumb-list { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 4px; }
            .ptp-breadcrumb-item { display: flex; align-items: center; gap: 4px; }
            .ptp-breadcrumb-item a { color: #FCB900; text-decoration: none; }
            .ptp-breadcrumb-item a:hover { text-decoration: underline; }
            .ptp-breadcrumb-item span[aria-current] { color: #666; }
            .ptp-breadcrumb-sep { color: #999; }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get all states
     */
    public static function get_states() {
        return self::$states;
    }
    
    /**
     * Get all services
     */
    public static function get_services() {
        return self::$services;
    }
    
    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'ptp-training',
            'SEO Location Pages',
            'SEO Pages',
            'manage_options',
            'ptp-seo-pages',
            array(__CLASS__, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public static function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>PTP SEO Location Pages</h1>
            
            <div class="ptp-seo-admin">
                <div class="ptp-seo-stats" id="ptp-seo-stats">
                    <h2>Page Statistics</h2>
                    <div class="ptp-stats-grid">
                        <div class="ptp-stat-box">
                            <span class="stat-number"><?php echo count(self::$states); ?></span>
                            <span class="stat-label">States</span>
                        </div>
                        <div class="ptp-stat-box">
                            <span class="stat-number">
                                <?php 
                                $total_cities = 0;
                                foreach (self::$states as $state) {
                                    $total_cities += count($state['major_cities']);
                                }
                                echo $total_cities;
                                ?>
                            </span>
                            <span class="stat-label">Cities</span>
                        </div>
                        <div class="ptp-stat-box">
                            <span class="stat-number"><?php echo count(self::$services); ?></span>
                            <span class="stat-label">Services</span>
                        </div>
                        <div class="ptp-stat-box">
                            <span class="stat-number">
                                <?php 
                                // Total potential pages
                                $total = count(self::$states) + $total_cities + count(self::$services);
                                $total += count(self::$states) * count(self::$services); // Service + State
                                $total += $total_cities * count(self::$services); // Service + City
                                echo $total;
                                ?>
                            </span>
                            <span class="stat-label">Total SEO Pages</span>
                        </div>
                    </div>
                </div>
                
                <div class="ptp-seo-actions">
                    <h2>Actions</h2>
                    <button type="button" class="button button-primary" id="ptp-flush-rewrites">
                        Flush Rewrite Rules
                    </button>
                    <button type="button" class="button" id="ptp-generate-sitemap">
                        Generate Sitemap
                    </button>
                    <button type="button" class="button" id="ptp-view-urls">
                        View All URLs
                    </button>
                </div>
                
                <div class="ptp-seo-urls" id="ptp-seo-urls" style="display:none;">
                    <h2>All SEO URLs</h2>
                    <div class="ptp-url-list">
                        <h3>State Pages</h3>
                        <ul>
                            <?php foreach (self::$states as $slug => $state): ?>
                                <li><a href="<?php echo home_url("/soccer-training/{$slug}/"); ?>" target="_blank">/soccer-training/<?php echo $slug; ?>/</a></li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <h3>Service Pages</h3>
                        <ul>
                            <?php foreach (self::$services as $slug => $service): ?>
                                <li><a href="<?php echo home_url("/{$slug}/"); ?>" target="_blank">/<?php echo $slug; ?>/</a></li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <h3>City Pages (Sample - Pennsylvania)</h3>
                        <ul>
                            <?php 
                            $pa = self::$states['pennsylvania'];
                            $count = 0;
                            foreach ($pa['major_cities'] as $city_slug => $city): 
                                if ($count++ >= 10) break;
                            ?>
                                <li><a href="<?php echo home_url("/soccer-training/pennsylvania/{$city_slug}/"); ?>" target="_blank">/soccer-training/pennsylvania/<?php echo $city_slug; ?>/</a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            
            <style>
                .ptp-seo-admin { max-width: 1200px; }
                .ptp-stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0; }
                .ptp-stat-box { background: #fff; border: 1px solid #ddd; padding: 20px; text-align: center; border-radius: 8px; }
                .ptp-stat-box .stat-number { display: block; font-size: 36px; font-weight: 700; color: #FCB900; }
                .ptp-stat-box .stat-label { display: block; font-size: 14px; color: #666; margin-top: 5px; }
                .ptp-seo-actions { margin: 30px 0; }
                .ptp-seo-actions .button { margin-right: 10px; }
                .ptp-url-list ul { columns: 2; column-gap: 40px; }
                .ptp-url-list li { margin: 5px 0; font-family: monospace; font-size: 13px; }
            </style>
            
            <script>
            jQuery(function($) {
                $('#ptp-flush-rewrites').on('click', function() {
                    $(this).text('Flushing...').prop('disabled', true);
                    $.post(ajaxurl, { action: 'ptp_flush_seo_rewrites' }, function() {
                        location.reload();
                    });
                });
                
                $('#ptp-view-urls').on('click', function() {
                    $('#ptp-seo-urls').toggle();
                });
            });
            </script>
        </div>
        <?php
    }
    
    /**
     * AJAX: Flush rewrite rules
     */
    public static function ajax_flush_rewrites() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        flush_rewrite_rules();
        wp_send_json_success('Rewrite rules flushed');
    }
    
    /**
     * Generate sitemap for SEO pages
     */
    public static function generate_sitemap_xml() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        // State pages
        foreach (self::$states as $slug => $state) {
            $xml .= self::sitemap_url_entry(home_url("/soccer-training/{$slug}/"), '0.8', 'weekly');
            
            // City pages
            foreach ($state['major_cities'] as $city_slug => $city) {
                $priority = isset($city['metro']) ? '0.9' : '0.7';
                $xml .= self::sitemap_url_entry(home_url("/soccer-training/{$slug}/{$city_slug}/"), $priority, 'weekly');
            }
        }
        
        // Service pages
        foreach (self::$services as $slug => $service) {
            $xml .= self::sitemap_url_entry(home_url("/{$slug}/"), '0.9', 'weekly');
            
            // Service + State pages
            foreach (self::$states as $state_slug => $state) {
                $xml .= self::sitemap_url_entry(home_url("/{$slug}/{$state_slug}/"), '0.8', 'weekly');
                
                // Service + City pages (high priority)
                foreach ($state['major_cities'] as $city_slug => $city) {
                    if (isset($city['metro'])) { // Only metro cities for service+city
                        $xml .= self::sitemap_url_entry(home_url("/{$slug}/{$state_slug}/{$city_slug}/"), '0.9', 'weekly');
                    }
                }
            }
        }
        
        // Near me pages
        $xml .= self::sitemap_url_entry(home_url('/soccer-training-near-me/'), '0.8', 'weekly');
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * Single sitemap URL entry
     */
    private static function sitemap_url_entry($url, $priority = '0.5', $changefreq = 'monthly') {
        return "  <url>\n" .
               "    <loc>" . esc_url($url) . "</loc>\n" .
               "    <lastmod>" . date('Y-m-d') . "</lastmod>\n" .
               "    <changefreq>{$changefreq}</changefreq>\n" .
               "    <priority>{$priority}</priority>\n" .
               "  </url>\n";
    }
    
    /**
     * Register sitemap provider
     */
    public static function register_sitemap_provider() {
        // Handle sitemap request
        if (isset($_GET['ptp-sitemap']) && $_GET['ptp-sitemap'] === 'locations') {
            header('Content-Type: application/xml; charset=UTF-8');
            echo self::generate_sitemap_xml();
            exit;
        }
    }
    
    /**
     * Get count of all potential pages
     */
    public static function get_page_count() {
        $count = 0;
        
        // States
        $count += count(self::$states);
        
        // Cities
        foreach (self::$states as $state) {
            $count += count($state['major_cities']);
        }
        
        // Services
        $count += count(self::$services);
        
        // Service + State
        $count += count(self::$states) * count(self::$services);
        
        // Service + City (metro only)
        foreach (self::$states as $state) {
            foreach ($state['major_cities'] as $city) {
                if (isset($city['metro'])) {
                    $count += count(self::$services);
                }
            }
        }
        
        return $count;
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_SEO_Locations', 'init'));
