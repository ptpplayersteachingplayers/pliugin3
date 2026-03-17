<?php
/**
 * PTP SEO Titles & Meta Descriptions
 * 
 * Automatically sets optimized SEO titles and meta descriptions
 * for all PTP pages. Works with Yoast, RankMath, or standalone.
 * 
 * @package PTP_Training_Platform
 * @since 125.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTP_SEO_Titles {
    
    private static $instance = null;
    
    // Site-wide settings
    const SITE_NAME = 'Players Teaching Players';
    const SITE_TAGLINE = 'Youth Soccer Camps & Private Training';
    const FAMILIES_COUNT = '500+';
    
    // SEO data for pages
    private $seo_data = array();
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->init_seo_data();
        
        // Set site title/tagline on activation
        add_action('admin_init', array($this, 'set_site_options'));
        
        // Filter document title
        add_filter('pre_get_document_title', array($this, 'filter_title'), 99);
        add_filter('wp_title', array($this, 'filter_title'), 99);
        
        // Yoast SEO integration
        add_filter('wpseo_title', array($this, 'filter_title'), 99);
        add_filter('wpseo_metadesc', array($this, 'filter_meta_description'), 99);
        
        // RankMath integration
        add_filter('rank_math/frontend/title', array($this, 'filter_title'), 99);
        add_filter('rank_math/frontend/description', array($this, 'filter_meta_description'), 99);
        
        // Fallback meta description for non-SEO plugin sites
        add_action('wp_head', array($this, 'output_meta_description'), 1);
        
        // Admin page to view/edit SEO settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // AJAX save
        add_action('wp_ajax_ptp_save_seo_settings', array($this, 'ajax_save_settings'));
    }
    
    /**
     * Initialize SEO data for all pages
     */
    private function init_seo_data() {
        $families = self::FAMILIES_COUNT;
        
        $this->seo_data = array(
            // ============================================
            // HOMEPAGE
            // ============================================
            'home' => array(
                'title' => 'Youth Soccer Camps & Private Training | Players Teaching Players',
                'description' => "Youth soccer camps and 1-on-1 training with NCAA D1 college athletes. Serving PA, NJ, DE, MD & NY. {$families} families served. Book your kid's spot today!",
            ),
            
            // ============================================
            // CORE PAGES
            // ============================================
            'find-a-camp' => array(
                'title' => 'Youth Soccer Camps Near Me | Summer Camps 2026 | PTP',
                'description' => 'Find youth soccer camps in Pennsylvania, New Jersey, Delaware & Maryland. College athlete coaches, small groups, ages 5-14. Register for summer 2026!',
            ),
            'camps' => array(
                'title' => 'Youth Soccer Camps Near Me | Summer Camps 2026 | PTP',
                'description' => 'Find youth soccer camps in Pennsylvania, New Jersey, Delaware & Maryland. College athlete coaches, small groups, ages 5-14. Register for summer 2026!',
            ),
            'private-training' => array(
                'title' => 'Private Soccer Training | 1-on-1 Sessions | PTP',
                'description' => 'Book private soccer training with NCAA Division 1 athletes. Personalized skill development for kids, flexible scheduling. Sessions starting at $75. Book today!',
            ),
            'training' => array(
                'title' => 'Private Soccer Training | 1-on-1 Sessions | PTP',
                'description' => 'Book private soccer training with NCAA Division 1 athletes. Personalized skill development for kids, flexible scheduling. Sessions starting at $75. Book today!',
            ),
            'find-trainers' => array(
                'title' => 'Find Soccer Trainers Near Me | Book Private Lessons | PTP',
                'description' => 'Browse 20+ elite soccer trainers - NCAA D1 athletes and college players. Filter by location & availability. Book private training sessions for your kid online.',
            ),
            'trainers' => array(
                'title' => 'Our Soccer Coaches | NCAA D1 College Athletes | PTP',
                'description' => 'Meet our coaching staff - NCAA Division 1 athletes from Villanova, Penn, Temple and more. Find the perfect trainer for your kid today.',
            ),
            'our-coaches' => array(
                'title' => 'Our Soccer Coaches | NCAA D1 College Athletes | PTP',
                'description' => 'Meet our coaching staff - NCAA Division 1 athletes from Villanova, Penn, Temple and more. Find the perfect trainer for your kid today.',
            ),
            
            // ============================================
            // MEMBERSHIP
            // ============================================
            'gold-pass' => array(
                'title' => 'PTP Gold Pass | Soccer Training Membership | Save on Camps',
                'description' => 'Join PTP Gold Pass for free private training, 20% off camps, priority booking & personalized skill roadmaps. The best value in youth soccer. Join now!',
            ),
            'membership' => array(
                'title' => 'PTP Membership | Youth Soccer Training Plans | PTP',
                'description' => 'Join PTP membership for free private training, 20% off camps, priority booking & personalized skill roadmaps. The best value in youth soccer. Join now!',
            ),
            'all-access' => array(
                'title' => 'PTP All-Access Pass | Unlimited Soccer Training | PTP',
                'description' => 'Get unlimited private soccer training, free camps, and priority booking with PTP All-Access. Best value for serious young players. Join today!',
            ),
            
            // ============================================
            // CLINICS
            // ============================================
            'clinics' => array(
                'title' => 'Soccer Clinics & Workshops | Youth Skills Training | PTP',
                'description' => 'Drop-in soccer clinics for kids focused on finishing, 1v1 moves, and game IQ. Taught by college players. No commitment, just skills. Find a clinic near you!',
            ),
            
            // ============================================
            // ABOUT / TRUST
            // ============================================
            'about' => array(
                'title' => 'About PTP | Youth Soccer Training | Players Teaching Players',
                'description' => "Founded by former Philadelphia Union Academy player. PTP brings NCAA athletes to mentor young players. {$families} families served. Our story.",
            ),
            'about-us' => array(
                'title' => 'About PTP | Youth Soccer Training | Players Teaching Players',
                'description' => "Founded by former Philadelphia Union Academy player. PTP brings NCAA athletes to mentor young players. {$families} families served. Our story.",
            ),
            'reviews' => array(
                'title' => 'PTP Reviews | 5-Star Youth Soccer Camp Reviews | PTP',
                'description' => "See why {$families} families trust PTP for youth soccer camps and training. Real parent reviews, verified testimonials. Read what families say about us.",
            ),
            'testimonials' => array(
                'title' => 'PTP Reviews | 5-Star Youth Soccer Camp Reviews | PTP',
                'description' => "See why {$families} families trust PTP for youth soccer camps and training. Real parent reviews, verified testimonials. Read what families say about us.",
            ),
            
            // ============================================
            // APPLY
            // ============================================
            'apply' => array(
                'title' => 'Become a Soccer Trainer | Coaching Jobs | PTP',
                'description' => 'Join PTP as a youth soccer trainer. Hiring NCAA D1/D2/D3 athletes & academy players. Set your own schedule, earn $50-100/hour. Apply in 5 minutes.',
            ),
            'become-a-trainer' => array(
                'title' => 'Become a Soccer Trainer | Coaching Jobs | PTP',
                'description' => 'Join PTP as a youth soccer trainer. Hiring NCAA D1/D2/D3 athletes & academy players. Set your own schedule, earn $50-100/hour. Apply in 5 minutes.',
            ),
            
            // ============================================
            // STATE PAGES
            // ============================================
            'pennsylvania' => array(
                'title' => 'Soccer Camps in Pennsylvania | Youth Camps 2026 | PTP',
                'description' => 'Top-rated youth soccer camps across Pennsylvania. Main Line, Philadelphia, Chester County locations. College athlete coaches, small groups. Register now!',
            ),
            'pa' => array(
                'title' => 'Soccer Camps in Pennsylvania | Youth Camps 2026 | PTP',
                'description' => 'Top-rated youth soccer camps across Pennsylvania. Main Line, Philadelphia, Chester County locations. College athlete coaches, small groups. Register now!',
            ),
            'soccer-camps-pennsylvania' => array(
                'title' => 'Soccer Camps in Pennsylvania | Youth Camps 2026 | PTP',
                'description' => 'Top-rated youth soccer camps across Pennsylvania. Main Line, Philadelphia, Chester County locations. College athlete coaches, small groups. Register now!',
            ),
            'new-jersey' => array(
                'title' => 'Soccer Camps in New Jersey | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in South Jersey & Central NJ. College athlete coaches, ages 5-14, half & full day options. Book your kid\'s spot today!',
            ),
            'nj' => array(
                'title' => 'Soccer Camps in New Jersey | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in South Jersey & Central NJ. College athlete coaches, ages 5-14, half & full day options. Book your kid\'s spot today!',
            ),
            'soccer-camps-new-jersey' => array(
                'title' => 'Soccer Camps in New Jersey | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in South Jersey & Central NJ. College athlete coaches, ages 5-14, half & full day options. Book your kid\'s spot today!',
            ),
            'delaware' => array(
                'title' => 'Soccer Camps in Delaware | Youth Camps 2026 | PTP',
                'description' => 'Premier youth soccer camps in Delaware. Train with NCAA athletes. Small groups, skill-focused training for kids. Summer 2026 registration open!',
            ),
            'de' => array(
                'title' => 'Soccer Camps in Delaware | Youth Camps 2026 | PTP',
                'description' => 'Premier youth soccer camps in Delaware. Train with NCAA athletes. Small groups, skill-focused training for kids. Summer 2026 registration open!',
            ),
            'soccer-camps-delaware' => array(
                'title' => 'Soccer Camps in Delaware | Youth Camps 2026 | PTP',
                'description' => 'Premier youth soccer camps in Delaware. Train with NCAA athletes. Small groups, skill-focused training for kids. Summer 2026 registration open!',
            ),
            'maryland' => array(
                'title' => 'Soccer Camps in Maryland | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Maryland with college athlete coaches. Skill development, mentorship-driven training for kids. Ages 5-14. Register today!',
            ),
            'md' => array(
                'title' => 'Soccer Camps in Maryland | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Maryland with college athlete coaches. Skill development, mentorship-driven training for kids. Ages 5-14. Register today!',
            ),
            'soccer-camps-maryland' => array(
                'title' => 'Soccer Camps in Maryland | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Maryland with college athlete coaches. Skill development, mentorship-driven training for kids. Ages 5-14. Register today!',
            ),
            'new-york' => array(
                'title' => 'Soccer Camps in New York | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in NY. NCAA athlete coaches, small group training, real mentorship for kids. Half & full day options. Book now!',
            ),
            'ny' => array(
                'title' => 'Soccer Camps in New York | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in NY. NCAA athlete coaches, small group training, real mentorship for kids. Half & full day options. Book now!',
            ),
            'soccer-camps-new-york' => array(
                'title' => 'Soccer Camps in New York | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in NY. NCAA athlete coaches, small group training, real mentorship for kids. Half & full day options. Book now!',
            ),
            
            // ============================================
            // PHILADELPHIA
            // ============================================
            'philadelphia' => array(
                'title' => 'Soccer Camps in Philadelphia | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Philadelphia. College athlete coaches, Center City, South Philly, Northeast locations. Ages 5-14. Register now!',
            ),
            'philly' => array(
                'title' => 'Soccer Camps in Philadelphia | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Philadelphia. College athlete coaches, Center City, South Philly, Northeast locations. Ages 5-14. Register now!',
            ),
            'soccer-camps-philadelphia' => array(
                'title' => 'Soccer Camps in Philadelphia | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Philadelphia. College athlete coaches, Center City, South Philly, Northeast locations. Ages 5-14. Register now!',
            ),
            
            // ============================================
            // MAIN LINE - ALL CITIES
            // ============================================
            'main-line' => array(
                'title' => 'Soccer Camps on the Main Line | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps on the Main Line PA - Wayne, Radnor, Bryn Mawr, Villanova, Ardmore. College athlete coaches, small groups. Summer 2026 registration open!',
            ),
            'soccer-camps-main-line' => array(
                'title' => 'Soccer Camps on the Main Line | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps on the Main Line PA - Wayne, Radnor, Bryn Mawr, Villanova, Ardmore. College athlete coaches, small groups. Summer 2026 registration open!',
            ),
            
            // Wayne
            'wayne' => array(
                'title' => 'Soccer Camps in Wayne PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Wayne, PA on the Main Line. NCAA D1 & college athlete coaches, 8:1 ratio, ages 5-14. Half & full day options. Register now!',
            ),
            'soccer-camps-wayne' => array(
                'title' => 'Soccer Camps in Wayne PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Wayne, PA on the Main Line. NCAA D1 & college athlete coaches, 8:1 ratio, ages 5-14. Half & full day options. Register now!',
            ),
            'soccer-camps-wayne-pa' => array(
                'title' => 'Soccer Camps in Wayne PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Wayne, PA on the Main Line. NCAA D1 & college athlete coaches, 8:1 ratio, ages 5-14. Half & full day options. Register now!',
            ),
            
            // Radnor
            'radnor' => array(
                'title' => 'Soccer Camps in Radnor PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Radnor Township, PA. Train with NCAA D1 & college athletes. Small groups, real mentorship for kids. Summer 2026 spots available!',
            ),
            'soccer-camps-radnor' => array(
                'title' => 'Soccer Camps in Radnor PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Radnor Township, PA. Train with NCAA D1 & college athletes. Small groups, real mentorship for kids. Summer 2026 spots available!',
            ),
            'soccer-camps-radnor-pa' => array(
                'title' => 'Soccer Camps in Radnor PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Radnor Township, PA. Train with NCAA D1 & college athletes. Small groups, real mentorship for kids. Summer 2026 spots available!',
            ),
            
            // Bryn Mawr
            'bryn-mawr' => array(
                'title' => 'Soccer Camps in Bryn Mawr PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Bryn Mawr, PA. College athlete coaches, skill development, ages 5-14. Main Line\'s premier soccer training for kids. Book now!',
            ),
            'soccer-camps-bryn-mawr' => array(
                'title' => 'Soccer Camps in Bryn Mawr PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Bryn Mawr, PA. College athlete coaches, skill development, ages 5-14. Main Line\'s premier soccer training for kids. Book now!',
            ),
            'soccer-camps-bryn-mawr-pa' => array(
                'title' => 'Soccer Camps in Bryn Mawr PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Bryn Mawr, PA. College athlete coaches, skill development, ages 5-14. Main Line\'s premier soccer training for kids. Book now!',
            ),
            
            // Villanova
            'villanova' => array(
                'title' => 'Soccer Camps in Villanova PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Villanova, PA. Train with Villanova athletes and college players. Small groups, ages 5-14. Summer 2026 registration open!',
            ),
            'soccer-camps-villanova' => array(
                'title' => 'Soccer Camps in Villanova PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Villanova, PA. Train with Villanova athletes and college players. Small groups, ages 5-14. Summer 2026 registration open!',
            ),
            'soccer-camps-villanova-pa' => array(
                'title' => 'Soccer Camps in Villanova PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Villanova, PA. Train with Villanova athletes and college players. Small groups, ages 5-14. Summer 2026 registration open!',
            ),
            
            // Ardmore
            'ardmore' => array(
                'title' => 'Soccer Camps in Ardmore PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Ardmore, PA on the Main Line. College athlete coaches, 8:1 ratio, skill-focused training for kids. Register today!',
            ),
            'soccer-camps-ardmore' => array(
                'title' => 'Soccer Camps in Ardmore PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Ardmore, PA on the Main Line. College athlete coaches, 8:1 ratio, skill-focused training for kids. Register today!',
            ),
            'soccer-camps-ardmore-pa' => array(
                'title' => 'Soccer Camps in Ardmore PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Ardmore, PA on the Main Line. College athlete coaches, 8:1 ratio, skill-focused training for kids. Register today!',
            ),
            
            // Haverford
            'haverford' => array(
                'title' => 'Soccer Camps in Haverford PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Haverford, PA. NCAA athletes and college players coaching kids ages 5-14. Small groups, real skill development. Book now!',
            ),
            'soccer-camps-haverford' => array(
                'title' => 'Soccer Camps in Haverford PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Haverford, PA. NCAA athletes and college players coaching kids ages 5-14. Small groups, real skill development. Book now!',
            ),
            'soccer-camps-haverford-pa' => array(
                'title' => 'Soccer Camps in Haverford PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Haverford, PA. NCAA athletes and college players coaching kids ages 5-14. Small groups, real skill development. Book now!',
            ),
            
            // Narberth
            'narberth' => array(
                'title' => 'Soccer Camps in Narberth PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Narberth, PA. College athlete coaches, ages 5-14, half & full day options on the Main Line. Summer 2026 spots available!',
            ),
            'soccer-camps-narberth' => array(
                'title' => 'Soccer Camps in Narberth PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Narberth, PA. College athlete coaches, ages 5-14, half & full day options on the Main Line. Summer 2026 spots available!',
            ),
            'soccer-camps-narberth-pa' => array(
                'title' => 'Soccer Camps in Narberth PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Narberth, PA. College athlete coaches, ages 5-14, half & full day options on the Main Line. Summer 2026 spots available!',
            ),
            
            // Gladwyne
            'gladwyne' => array(
                'title' => 'Soccer Camps in Gladwyne PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Gladwyne, PA. Train with college athletes on the Main Line. Small groups, skill-focused training for kids. Register now!',
            ),
            'soccer-camps-gladwyne' => array(
                'title' => 'Soccer Camps in Gladwyne PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Gladwyne, PA. Train with college athletes on the Main Line. Small groups, skill-focused training for kids. Register now!',
            ),
            'soccer-camps-gladwyne-pa' => array(
                'title' => 'Soccer Camps in Gladwyne PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Gladwyne, PA. Train with college athletes on the Main Line. Small groups, skill-focused training for kids. Register now!',
            ),
            
            // Merion Station
            'merion-station' => array(
                'title' => 'Soccer Camps in Merion Station PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Merion Station, PA. NCAA D1 & college athlete coaches, ages 5-14. Main Line soccer training for kids. Book today!',
            ),
            'soccer-camps-merion-station' => array(
                'title' => 'Soccer Camps in Merion Station PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Merion Station, PA. NCAA D1 & college athlete coaches, ages 5-14. Main Line soccer training for kids. Book today!',
            ),
            
            // Bala Cynwyd
            'bala-cynwyd' => array(
                'title' => 'Soccer Camps in Bala Cynwyd PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Bala Cynwyd, PA. College athlete coaches, small groups, ages 5-14. Summer 2026 registration now open!',
            ),
            'soccer-camps-bala-cynwyd' => array(
                'title' => 'Soccer Camps in Bala Cynwyd PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Bala Cynwyd, PA. College athlete coaches, small groups, ages 5-14. Summer 2026 registration now open!',
            ),
            
            // Rosemont
            'rosemont' => array(
                'title' => 'Soccer Camps in Rosemont PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Rosemont, PA on the Main Line. Train with college athletes. Skill development for kids ages 5-14. Register now!',
            ),
            'soccer-camps-rosemont' => array(
                'title' => 'Soccer Camps in Rosemont PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Rosemont, PA on the Main Line. Train with college athletes. Skill development for kids ages 5-14. Register now!',
            ),
            
            // Devon
            'devon' => array(
                'title' => 'Soccer Camps in Devon PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Devon, PA. NCAA athletes coaching kids ages 5-14. Main Line\'s best soccer training. Half & full day options available!',
            ),
            'soccer-camps-devon' => array(
                'title' => 'Soccer Camps in Devon PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Devon, PA. NCAA athletes coaching kids ages 5-14. Main Line\'s best soccer training. Half & full day options available!',
            ),
            'soccer-camps-devon-pa' => array(
                'title' => 'Soccer Camps in Devon PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Devon, PA. NCAA athletes coaching kids ages 5-14. Main Line\'s best soccer training. Half & full day options available!',
            ),
            
            // Paoli
            'paoli' => array(
                'title' => 'Soccer Camps in Paoli PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Paoli, PA. College athlete coaches, 8:1 ratio, ages 5-14. Skill-focused training for kids. Summer 2026 open!',
            ),
            'soccer-camps-paoli' => array(
                'title' => 'Soccer Camps in Paoli PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Paoli, PA. College athlete coaches, 8:1 ratio, ages 5-14. Skill-focused training for kids. Summer 2026 open!',
            ),
            'soccer-camps-paoli-pa' => array(
                'title' => 'Soccer Camps in Paoli PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Paoli, PA. College athlete coaches, 8:1 ratio, ages 5-14. Skill-focused training for kids. Summer 2026 open!',
            ),
            
            // Berwyn
            'berwyn' => array(
                'title' => 'Soccer Camps in Berwyn PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Berwyn, PA. Train with college athletes on the Main Line. Small groups, real mentorship for kids. Book today!',
            ),
            'soccer-camps-berwyn' => array(
                'title' => 'Soccer Camps in Berwyn PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Berwyn, PA. Train with college athletes on the Main Line. Small groups, real mentorship for kids. Book today!',
            ),
            'soccer-camps-berwyn-pa' => array(
                'title' => 'Soccer Camps in Berwyn PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Berwyn, PA. Train with college athletes on the Main Line. Small groups, real mentorship for kids. Book today!',
            ),
            
            // Strafford
            'strafford' => array(
                'title' => 'Soccer Camps in Strafford PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Strafford, PA. NCAA athletes coaching kids ages 5-14. Main Line soccer training. Summer 2026 registration open!',
            ),
            'soccer-camps-strafford' => array(
                'title' => 'Soccer Camps in Strafford PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Strafford, PA. NCAA athletes coaching kids ages 5-14. Main Line soccer training. Summer 2026 registration open!',
            ),
            
            // ============================================
            // KING OF PRUSSIA
            // ============================================
            'king-of-prussia' => array(
                'title' => 'Soccer Camps in King of Prussia PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in King of Prussia, PA. College athlete coaches, 8:1 player ratio, ages 5-14. Half & full day summer camp options. Register today!',
            ),
            'soccer-camps-king-of-prussia' => array(
                'title' => 'Soccer Camps in King of Prussia PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in King of Prussia, PA. College athlete coaches, 8:1 player ratio, ages 5-14. Half & full day summer camp options. Register today!',
            ),
            'soccer-camps-king-of-prussia-pa' => array(
                'title' => 'Soccer Camps in King of Prussia PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in King of Prussia, PA. College athlete coaches, 8:1 player ratio, ages 5-14. Half & full day summer camp options. Register today!',
            ),
            
            // ============================================
            // CHESTER COUNTY
            // ============================================
            'chester-county' => array(
                'title' => 'Soccer Camps in Chester County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Chester County - West Chester, Downingtown, Malvern, Exton. College athlete coaches, skill-focused training for kids. Book summer 2026!',
            ),
            'soccer-camps-chester-county' => array(
                'title' => 'Soccer Camps in Chester County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Chester County - West Chester, Downingtown, Malvern, Exton. College athlete coaches, skill-focused training for kids. Book summer 2026!',
            ),
            
            // West Chester
            'west-chester' => array(
                'title' => 'Soccer Camps in West Chester PA | Kid Camps 2026 | PTP',
                'description' => 'Top-rated youth soccer camps in West Chester, PA. College athlete coaches, skill-focused curriculum for kids. Chester County\'s best. Summer 2026 open!',
            ),
            'soccer-camps-west-chester' => array(
                'title' => 'Soccer Camps in West Chester PA | Kid Camps 2026 | PTP',
                'description' => 'Top-rated youth soccer camps in West Chester, PA. College athlete coaches, skill-focused curriculum for kids. Chester County\'s best. Summer 2026 open!',
            ),
            'soccer-camps-west-chester-pa' => array(
                'title' => 'Soccer Camps in West Chester PA | Kid Camps 2026 | PTP',
                'description' => 'Top-rated youth soccer camps in West Chester, PA. College athlete coaches, skill-focused curriculum for kids. Chester County\'s best. Summer 2026 open!',
            ),
            
            // Malvern
            'malvern' => array(
                'title' => 'Soccer Camps in Malvern PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Malvern, PA. NCAA athletes coaching kids ages 5-14. Small groups, real skill development. Summer 2026 spots available!',
            ),
            'soccer-camps-malvern' => array(
                'title' => 'Soccer Camps in Malvern PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Malvern, PA. NCAA athletes coaching kids ages 5-14. Small groups, real skill development. Summer 2026 spots available!',
            ),
            'soccer-camps-malvern-pa' => array(
                'title' => 'Soccer Camps in Malvern PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Malvern, PA. NCAA athletes coaching kids ages 5-14. Small groups, real skill development. Summer 2026 spots available!',
            ),
            
            // Exton
            'exton' => array(
                'title' => 'Soccer Camps in Exton PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Exton, PA. College athlete coaches, ages 5-14, half & full day options. Chester County soccer training for kids. Register now!',
            ),
            'soccer-camps-exton' => array(
                'title' => 'Soccer Camps in Exton PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Exton, PA. College athlete coaches, ages 5-14, half & full day options. Chester County soccer training for kids. Register now!',
            ),
            'soccer-camps-exton-pa' => array(
                'title' => 'Soccer Camps in Exton PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Exton, PA. College athlete coaches, ages 5-14, half & full day options. Chester County soccer training for kids. Register now!',
            ),
            
            // Downingtown
            'downingtown' => array(
                'title' => 'Soccer Camps in Downingtown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Downingtown, PA. Train with college athletes. 8:1 ratio, skill-focused training for kids. Summer 2026 registration open!',
            ),
            'soccer-camps-downingtown' => array(
                'title' => 'Soccer Camps in Downingtown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Downingtown, PA. Train with college athletes. 8:1 ratio, skill-focused training for kids. Summer 2026 registration open!',
            ),
            'soccer-camps-downingtown-pa' => array(
                'title' => 'Soccer Camps in Downingtown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Downingtown, PA. Train with college athletes. 8:1 ratio, skill-focused training for kids. Summer 2026 registration open!',
            ),
            
            // Phoenixville
            'phoenixville' => array(
                'title' => 'Soccer Camps in Phoenixville PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Phoenixville, PA. NCAA athletes coaching kids ages 5-14. Small groups, real mentorship. Book your spot today!',
            ),
            'soccer-camps-phoenixville' => array(
                'title' => 'Soccer Camps in Phoenixville PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Phoenixville, PA. NCAA athletes coaching kids ages 5-14. Small groups, real mentorship. Book your spot today!',
            ),
            
            // Kennett Square
            'kennett-square' => array(
                'title' => 'Soccer Camps in Kennett Square PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Kennett Square, PA. College athlete coaches, skill development for kids. Chester County\'s best soccer training. Register now!',
            ),
            'soccer-camps-kennett-square' => array(
                'title' => 'Soccer Camps in Kennett Square PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Kennett Square, PA. College athlete coaches, skill development for kids. Chester County\'s best soccer training. Register now!',
            ),
            
            // Glen Mills
            'glen-mills' => array(
                'title' => 'Soccer Camps in Glen Mills PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Glen Mills, PA. Train with college athletes. Ages 5-14, half & full day options. Summer 2026 spots available!',
            ),
            'soccer-camps-glen-mills' => array(
                'title' => 'Soccer Camps in Glen Mills PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Glen Mills, PA. Train with college athletes. Ages 5-14, half & full day options. Summer 2026 spots available!',
            ),
            
            // ============================================
            // MONTGOMERY COUNTY
            // ============================================
            'montgomery-county' => array(
                'title' => 'Soccer Camps in Montgomery County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in MontCo - King of Prussia, Conshohocken, Blue Bell, Lansdale. College athlete coaches. Small groups, big results for kids. Register today!',
            ),
            'soccer-camps-montgomery-county' => array(
                'title' => 'Soccer Camps in Montgomery County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in MontCo - King of Prussia, Conshohocken, Blue Bell, Lansdale. College athlete coaches. Small groups, big results for kids. Register today!',
            ),
            
            // Conshohocken
            'conshohocken' => array(
                'title' => 'Soccer Camps in Conshohocken PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Conshohocken, PA. College athlete coaches, 8:1 ratio, ages 5-14. Skill-focused training for kids. Summer 2026 open!',
            ),
            'soccer-camps-conshohocken' => array(
                'title' => 'Soccer Camps in Conshohocken PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Conshohocken, PA. College athlete coaches, 8:1 ratio, ages 5-14. Skill-focused training for kids. Summer 2026 open!',
            ),
            
            // Blue Bell
            'blue-bell' => array(
                'title' => 'Soccer Camps in Blue Bell PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Blue Bell, PA. NCAA athletes coaching kids ages 5-14. Small groups, real skill development. Register today!',
            ),
            'soccer-camps-blue-bell' => array(
                'title' => 'Soccer Camps in Blue Bell PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Blue Bell, PA. NCAA athletes coaching kids ages 5-14. Small groups, real skill development. Register today!',
            ),
            
            // Lansdale
            'lansdale' => array(
                'title' => 'Soccer Camps in Lansdale PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Lansdale, PA. College athlete coaches, ages 5-14. Montgomery County soccer training for kids. Book your spot now!',
            ),
            'soccer-camps-lansdale' => array(
                'title' => 'Soccer Camps in Lansdale PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Lansdale, PA. College athlete coaches, ages 5-14. Montgomery County soccer training for kids. Book your spot now!',
            ),
            
            // Horsham
            'horsham' => array(
                'title' => 'Soccer Camps in Horsham PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Horsham, PA. Train with college athletes. 8:1 ratio, skill-focused training for kids. Summer 2026 registration open!',
            ),
            'soccer-camps-horsham' => array(
                'title' => 'Soccer Camps in Horsham PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Horsham, PA. Train with college athletes. 8:1 ratio, skill-focused training for kids. Summer 2026 registration open!',
            ),
            
            // Ambler
            'ambler' => array(
                'title' => 'Soccer Camps in Ambler PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Ambler, PA. NCAA athletes coaching kids ages 5-14. Small groups, mentorship-driven training. Book today!',
            ),
            'soccer-camps-ambler' => array(
                'title' => 'Soccer Camps in Ambler PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Ambler, PA. NCAA athletes coaching kids ages 5-14. Small groups, mentorship-driven training. Book today!',
            ),
            
            // Plymouth Meeting
            'plymouth-meeting' => array(
                'title' => 'Soccer Camps in Plymouth Meeting PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Plymouth Meeting, PA. College athlete coaches, ages 5-14, half & full day options. Summer 2026 spots available!',
            ),
            'soccer-camps-plymouth-meeting' => array(
                'title' => 'Soccer Camps in Plymouth Meeting PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Plymouth Meeting, PA. College athlete coaches, ages 5-14, half & full day options. Summer 2026 spots available!',
            ),
            
            // Fort Washington
            'fort-washington' => array(
                'title' => 'Soccer Camps in Fort Washington PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Fort Washington, PA. Train with college athletes. Small groups, real skill development for kids. Register now!',
            ),
            'soccer-camps-fort-washington' => array(
                'title' => 'Soccer Camps in Fort Washington PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Fort Washington, PA. Train with college athletes. Small groups, real skill development for kids. Register now!',
            ),
            
            // Jenkintown
            'jenkintown' => array(
                'title' => 'Soccer Camps in Jenkintown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Jenkintown, PA. NCAA athletes coaching kids ages 5-14. 8:1 ratio, mentorship-driven training. Book today!',
            ),
            'soccer-camps-jenkintown' => array(
                'title' => 'Soccer Camps in Jenkintown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Jenkintown, PA. NCAA athletes coaching kids ages 5-14. 8:1 ratio, mentorship-driven training. Book today!',
            ),
            
            // Glenside
            'glenside' => array(
                'title' => 'Soccer Camps in Glenside PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Glenside, PA. College athlete coaches, skill-focused training for kids. Summer 2026 registration open!',
            ),
            'soccer-camps-glenside' => array(
                'title' => 'Soccer Camps in Glenside PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Glenside, PA. College athlete coaches, skill-focused training for kids. Summer 2026 registration open!',
            ),
            
            // ============================================
            // DELAWARE COUNTY (DELCO)
            // ============================================
            'delco' => array(
                'title' => 'Soccer Camps in Delaware County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Delaware County - Media, Springfield, Ridley, Swarthmore. College athlete coaches, small groups for kids. Summer 2026 registration open!',
            ),
            'delaware-county' => array(
                'title' => 'Soccer Camps in Delaware County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Delaware County - Media, Springfield, Ridley, Swarthmore. College athlete coaches, small groups for kids. Summer 2026 registration open!',
            ),
            'soccer-camps-delco' => array(
                'title' => 'Soccer Camps in Delaware County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Delaware County - Media, Springfield, Ridley, Swarthmore. College athlete coaches, small groups for kids. Summer 2026 registration open!',
            ),
            
            // Media
            'media' => array(
                'title' => 'Soccer Camps in Media PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Media, PA. Train with NCAA athletes in Delaware County. Small groups, real skill development for kids. Book summer 2026!',
            ),
            'soccer-camps-media' => array(
                'title' => 'Soccer Camps in Media PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Media, PA. Train with NCAA athletes in Delaware County. Small groups, real skill development for kids. Book summer 2026!',
            ),
            'soccer-camps-media-pa' => array(
                'title' => 'Soccer Camps in Media PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Media, PA. Train with NCAA athletes in Delaware County. Small groups, real skill development for kids. Book summer 2026!',
            ),
            
            // Springfield
            'springfield' => array(
                'title' => 'Soccer Camps in Springfield PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Springfield, PA. College athlete coaches, 8:1 ratio, ages 5-14. Delaware County soccer training for kids. Register now!',
            ),
            'soccer-camps-springfield' => array(
                'title' => 'Soccer Camps in Springfield PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Springfield, PA. College athlete coaches, 8:1 ratio, ages 5-14. Delaware County soccer training for kids. Register now!',
            ),
            
            // Swarthmore
            'swarthmore' => array(
                'title' => 'Soccer Camps in Swarthmore PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Swarthmore, PA. NCAA athletes coaching kids ages 5-14. Small groups, skill-focused training. Summer 2026 open!',
            ),
            'soccer-camps-swarthmore' => array(
                'title' => 'Soccer Camps in Swarthmore PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Swarthmore, PA. NCAA athletes coaching kids ages 5-14. Small groups, skill-focused training. Summer 2026 open!',
            ),
            
            // Drexel Hill
            'drexel-hill' => array(
                'title' => 'Soccer Camps in Drexel Hill PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Drexel Hill, PA. College athlete coaches, ages 5-14. Delaware County soccer training for kids. Book today!',
            ),
            'soccer-camps-drexel-hill' => array(
                'title' => 'Soccer Camps in Drexel Hill PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Drexel Hill, PA. College athlete coaches, ages 5-14. Delaware County soccer training for kids. Book today!',
            ),
            
            // Havertown
            'havertown' => array(
                'title' => 'Soccer Camps in Havertown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Havertown, PA. Train with college athletes. 8:1 ratio, skill-focused training for kids. Summer 2026 registration open!',
            ),
            'soccer-camps-havertown' => array(
                'title' => 'Soccer Camps in Havertown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Havertown, PA. Train with college athletes. 8:1 ratio, skill-focused training for kids. Summer 2026 registration open!',
            ),
            
            // Newtown Square
            'newtown-square' => array(
                'title' => 'Soccer Camps in Newtown Square PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Newtown Square, PA. NCAA athletes coaching kids ages 5-14. Small groups, real mentorship. Register now!',
            ),
            'soccer-camps-newtown-square' => array(
                'title' => 'Soccer Camps in Newtown Square PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Newtown Square, PA. NCAA athletes coaching kids ages 5-14. Small groups, real mentorship. Register now!',
            ),
            
            // ============================================
            // BUCKS COUNTY
            // ============================================
            'bucks-county' => array(
                'title' => 'Soccer Camps in Bucks County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Bucks County - Doylestown, Newtown, Yardley, Langhorne. College athlete coaches, ages 5-14 kids. Register now!',
            ),
            'soccer-camps-bucks-county' => array(
                'title' => 'Soccer Camps in Bucks County PA | Youth Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Bucks County - Doylestown, Newtown, Yardley, Langhorne. College athlete coaches, ages 5-14 kids. Register now!',
            ),
            
            // Doylestown
            'doylestown' => array(
                'title' => 'Soccer Camps in Doylestown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Doylestown, PA. College athlete coaches, 8:1 ratio, Bucks County\'s premier training for kids. Half & full day options available!',
            ),
            'soccer-camps-doylestown' => array(
                'title' => 'Soccer Camps in Doylestown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Doylestown, PA. College athlete coaches, 8:1 ratio, Bucks County\'s premier training for kids. Half & full day options available!',
            ),
            'soccer-camps-doylestown-pa' => array(
                'title' => 'Soccer Camps in Doylestown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Doylestown, PA. College athlete coaches, 8:1 ratio, Bucks County\'s premier training for kids. Half & full day options available!',
            ),
            
            // Newtown (Bucks)
            'newtown' => array(
                'title' => 'Soccer Camps in Newtown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Newtown, Bucks County PA. NCAA athletes coaching kids ages 5-14, skill-focused training. Register for summer 2026!',
            ),
            'soccer-camps-newtown' => array(
                'title' => 'Soccer Camps in Newtown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Newtown, Bucks County PA. NCAA athletes coaching kids ages 5-14, skill-focused training. Register for summer 2026!',
            ),
            'soccer-camps-newtown-pa' => array(
                'title' => 'Soccer Camps in Newtown PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Newtown, Bucks County PA. NCAA athletes coaching kids ages 5-14, skill-focused training. Register for summer 2026!',
            ),
            
            // Yardley
            'yardley' => array(
                'title' => 'Soccer Camps in Yardley PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Yardley, PA. College athlete coaches, small groups, ages 5-14. Bucks County soccer training for kids. Book today!',
            ),
            'soccer-camps-yardley' => array(
                'title' => 'Soccer Camps in Yardley PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Yardley, PA. College athlete coaches, small groups, ages 5-14. Bucks County soccer training for kids. Book today!',
            ),
            
            // Langhorne
            'langhorne' => array(
                'title' => 'Soccer Camps in Langhorne PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Langhorne, PA. Train with college athletes. 8:1 ratio, skill development for kids. Summer 2026 spots available!',
            ),
            'soccer-camps-langhorne' => array(
                'title' => 'Soccer Camps in Langhorne PA | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Langhorne, PA. Train with college athletes. 8:1 ratio, skill development for kids. Summer 2026 spots available!',
            ),
            
            // ============================================
            // NEW JERSEY CITIES
            // ============================================
            'cherry-hill' => array(
                'title' => 'Soccer Camps in Cherry Hill NJ | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Cherry Hill, NJ. College athlete coaches, small groups, ages 5-14. South Jersey\'s best soccer training for kids. Register now!',
            ),
            'soccer-camps-cherry-hill' => array(
                'title' => 'Soccer Camps in Cherry Hill NJ | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Cherry Hill, NJ. College athlete coaches, small groups, ages 5-14. South Jersey\'s best soccer training for kids. Register now!',
            ),
            
            'princeton' => array(
                'title' => 'Soccer Camps in Princeton NJ | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Princeton, NJ. NCAA athletes coaching kids ages 5-14. Skill-focused training, half & full day options. Book today!',
            ),
            'soccer-camps-princeton' => array(
                'title' => 'Soccer Camps in Princeton NJ | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Princeton, NJ. NCAA athletes coaching kids ages 5-14. Skill-focused training, half & full day options. Book today!',
            ),
            
            'haddonfield' => array(
                'title' => 'Soccer Camps in Haddonfield NJ | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Haddonfield, NJ. College athlete coaches, 8:1 ratio. South Jersey soccer training for kids. Summer 2026 registration open!',
            ),
            'soccer-camps-haddonfield' => array(
                'title' => 'Soccer Camps in Haddonfield NJ | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Haddonfield, NJ. College athlete coaches, 8:1 ratio. South Jersey soccer training for kids. Summer 2026 registration open!',
            ),
            
            'moorestown' => array(
                'title' => 'Soccer Camps in Moorestown NJ | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Moorestown, NJ. Train with college athletes. Small groups, skill development for kids. Book your spot now!',
            ),
            'soccer-camps-moorestown' => array(
                'title' => 'Soccer Camps in Moorestown NJ | Kid Camps 2026 | PTP',
                'description' => 'Youth soccer camps in Moorestown, NJ. Train with college athletes. Small groups, skill development for kids. Book your spot now!',
            ),
            
            // ============================================
            // ACCOUNT PAGES (noindex)
            // ============================================
            'login' => array(
                'title' => 'Login | Players Teaching Players',
                'description' => 'Log in to your PTP account to manage bookings, view schedules, and access your training dashboard.',
            ),
            'register' => array(
                'title' => 'Create Account | Players Teaching Players',
                'description' => 'Create your PTP account to book soccer camps and private training sessions with college athletes.',
            ),
            'my-account' => array(
                'title' => 'My Account | Players Teaching Players',
                'description' => 'Manage your PTP account, view upcoming sessions, and track your player\'s progress.',
            ),
            'parent-dashboard' => array(
                'title' => 'Parent Dashboard | Players Teaching Players',
                'description' => 'View your bookings, manage players, and track training progress in your PTP parent dashboard.',
            ),
            'trainer-dashboard' => array(
                'title' => 'Trainer Dashboard | Players Teaching Players',
                'description' => 'Manage your schedule, view bookings, and track earnings in your PTP trainer dashboard.',
            ),
            'account' => array(
                'title' => 'My Account | Players Teaching Players',
                'description' => 'Manage your PTP account settings, payment methods, and profile information.',
            ),
            'messages' => array(
                'title' => 'Messages | Players Teaching Players',
                'description' => 'View and send messages to your trainers and families.',
            ),
            'trainer-onboarding' => array(
                'title' => 'Trainer Onboarding | Players Teaching Players',
                'description' => 'Complete your PTP trainer profile and start accepting bookings.',
            ),
        );
        
        // Allow filtering
        $this->seo_data = apply_filters('ptp_seo_data', $this->seo_data);
    }
    
    /**
     * Set site options on first run
     */
    public function set_site_options() {
        if (get_option('ptp_seo_initialized')) {
            return;
        }
        
        update_option('blogname', self::SITE_NAME);
        update_option('blogdescription', self::SITE_TAGLINE);
        update_option('ptp_seo_initialized', true);
        
        ptp_log('[PTP SEO] Site title and tagline set: ' . self::SITE_NAME . ' | ' . self::SITE_TAGLINE);
    }
    
    /**
     * Get current page slug
     */
    private function get_current_slug() {
        global $post;
        
        if (is_front_page() || is_home()) {
            return 'home';
        }
        
        if (is_page() && $post) {
            return $post->post_name;
        }
        
        if (is_singular('ptp_trainer')) {
            return 'trainer-profile';
        }
        
        return '';
    }
    
    /**
     * Get SEO data for current page
     */
    private function get_current_seo_data() {
        $slug = $this->get_current_slug();
        
        if (isset($this->seo_data[$slug])) {
            return $this->seo_data[$slug];
        }
        
        // Check for partial matches (e.g., soccer-camps-pennsylvania matches pennsylvania)
        foreach ($this->seo_data as $key => $data) {
            if (strpos($slug, $key) !== false) {
                return $data;
            }
        }
        
        return null;
    }
    
    /**
     * Filter page title
     */
    public function filter_title($title) {
        $seo = $this->get_current_seo_data();
        
        if ($seo && !empty($seo['title'])) {
            return $seo['title'];
        }
        
        return $title;
    }
    
    /**
     * Filter meta description
     */
    public function filter_meta_description($description) {
        $seo = $this->get_current_seo_data();
        
        if ($seo && !empty($seo['description'])) {
            return $seo['description'];
        }
        
        return $description;
    }
    
    /**
     * Output meta description if no SEO plugin
     */
    public function output_meta_description() {
        // Skip if Yoast or RankMath is active
        if (defined('WPSEO_VERSION') || class_exists('RankMath')) {
            return;
        }
        
        $seo = $this->get_current_seo_data();
        
        if ($seo && !empty($seo['description'])) {
            echo '<meta name="description" content="' . esc_attr($seo['description']) . '">' . "\n";
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'ptp-settings',
            'SEO Titles',
            'SEO Titles',
            'manage_options',
            'ptp-seo-titles',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>PTP SEO Titles & Descriptions</h1>
            <p>These SEO titles and meta descriptions are automatically applied to your pages.</p>
            
            <h2>Site-Wide Settings</h2>
            <table class="form-table">
                <tr>
                    <th>Site Title</th>
                    <td><code><?php echo esc_html(self::SITE_NAME); ?></code></td>
                </tr>
                <tr>
                    <th>Tagline</th>
                    <td><code><?php echo esc_html(self::SITE_TAGLINE); ?></code></td>
                </tr>
                <tr>
                    <th>Families Count</th>
                    <td><code><?php echo esc_html(self::FAMILIES_COUNT); ?></code></td>
                </tr>
            </table>
            
            <h2>Page SEO Data (<?php echo count($this->seo_data); ?> pages)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 200px;">Page Slug</th>
                        <th style="width: 350px;">SEO Title</th>
                        <th>Meta Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->seo_data as $slug => $data): ?>
                    <tr>
                        <td><code><?php echo esc_html($slug); ?></code></td>
                        <td><?php echo esc_html($data['title']); ?></td>
                        <td><?php echo esc_html($data['description']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <h2>How It Works</h2>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>Titles and descriptions are applied automatically based on page slug</li>
                <li>Works with Yoast SEO, RankMath, or standalone WordPress</li>
                <li>Site title and tagline are set on plugin activation</li>
                <li>Use the <code>ptp_seo_data</code> filter to customize</li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * AJAX save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Future: allow custom overrides
        wp_send_json_success('Settings saved');
    }
    
    /**
     * Force refresh SEO settings
     */
    public static function refresh() {
        delete_option('ptp_seo_initialized');
        self::instance()->set_site_options();
    }
}

// Initialize
PTP_SEO_Titles::instance();
