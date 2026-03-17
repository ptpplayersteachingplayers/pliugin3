<?php
/**
 * PTP SEO & Social Media Integration
 * Handles meta tags, Open Graph, schema markup, and social sharing
 */

defined('ABSPATH') || exit;

class PTP_SEO {
    
    public static function init() {
        // Meta tags
        add_action('wp_head', array(__CLASS__, 'output_meta_tags'), 1);
        add_action('wp_head', array(__CLASS__, 'output_schema_markup'), 2);
        
        // Modify document title
        add_filter('pre_get_document_title', array(__CLASS__, 'custom_title'), 20);
        add_filter('document_title_parts', array(__CLASS__, 'modify_title_parts'), 20);
        
        // Add social share buttons shortcode
        add_shortcode('ptp_social_share', array(__CLASS__, 'social_share_buttons'));
        
        // Sitemap support
        add_filter('wp_sitemaps_posts_query_args', array(__CLASS__, 'sitemap_add_trainers'), 10, 2);
    }
    
    /**
     * Output meta tags
     */
    public static function output_meta_tags() {
        global $post;
        
        $meta = self::get_page_meta();
        
        if (!$meta) return;
        
        // Basic meta
        echo '<meta name="description" content="' . esc_attr($meta['description']) . '">' . "\n";
        echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">' . "\n";
        
        // Canonical URL
        echo '<link rel="canonical" href="' . esc_url($meta['url']) . '">' . "\n";
        
        // Keywords for trainer profiles
        if (!empty($meta['keywords'])) {
            echo '<meta name="keywords" content="' . esc_attr($meta['keywords']) . '">' . "\n";
        }
        
        // Geo meta tags for local SEO
        if (!empty($meta['geo'])) {
            echo '<meta name="geo.region" content="' . esc_attr($meta['geo']['region']) . '">' . "\n";
            echo '<meta name="geo.placename" content="' . esc_attr($meta['geo']['placename']) . '">' . "\n";
            if (!empty($meta['geo']['position'])) {
                echo '<meta name="geo.position" content="' . esc_attr($meta['geo']['position']) . '">' . "\n";
                echo '<meta name="ICBM" content="' . esc_attr($meta['geo']['position']) . '">' . "\n";
            }
        }
        
        // Open Graph
        echo '<meta property="og:type" content="' . esc_attr($meta['og_type']) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($meta['title']) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($meta['description']) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($meta['url']) . '">' . "\n";
        echo '<meta property="og:site_name" content="PTP Training">' . "\n";
        echo '<meta property="og:locale" content="en_US">' . "\n";
        
        if (!empty($meta['image'])) {
            echo '<meta property="og:image" content="' . esc_url($meta['image']) . '">' . "\n";
            echo '<meta property="og:image:width" content="1200">' . "\n";
            echo '<meta property="og:image:height" content="630">' . "\n";
            echo '<meta property="og:image:alt" content="' . esc_attr($meta['image_alt'] ?? $meta['title']) . '">' . "\n";
        }
        
        // Twitter Card
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($meta['title']) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($meta['description']) . '">' . "\n";
        echo '<meta name="twitter:site" content="@ptptraining">' . "\n";
        
        if (!empty($meta['image'])) {
            echo '<meta name="twitter:image" content="' . esc_url($meta['image']) . '">' . "\n";
        }
        
        // Additional for trainer profiles
        if (!empty($meta['trainer'])) {
            echo '<meta property="profile:first_name" content="' . esc_attr($meta['trainer']->first_name) . '">' . "\n";
            echo '<meta property="profile:last_name" content="' . esc_attr($meta['trainer']->last_name) . '">' . "\n";
            
            // Article publish time (for content freshness)
            if (!empty($meta['trainer']->created_at)) {
                echo '<meta property="article:published_time" content="' . esc_attr(date('c', strtotime($meta['trainer']->created_at))) . '">' . "\n";
            }
            echo '<meta property="article:modified_time" content="' . esc_attr(date('c')) . '">' . "\n";
        }
    }
    
    /**
     * Get meta data for current page
     */
    private static function get_page_meta() {
        $page_slug = get_post_field('post_name', get_post());
        $default_image = get_option('ptp_default_og_image', 'https://ptpsummercamps.com/wp-content/uploads/2025/12/BG7A1915.jpg');
        
        // Get configurable sport name (defaults to empty for generic)
        $sport = get_option('ptp_primary_sport', '');
        $sport_label = $sport ? ucfirst($sport) : 'Sports';
        $sport_lower = $sport ?: 'sports';
        
        $meta = array(
            'title' => get_bloginfo('name') . ' | Private Training',
            'description' => 'Connect with verified NCAA Division 1 athletes and professional players for personalized 1-on-1 training sessions.',
            'image' => $default_image,
            'url' => get_permalink(),
            'og_type' => 'website',
            'keywords' => '',
            'geo' => array(),
        );
        
        switch ($page_slug) {
            case 'find-trainers':
                $meta['title'] = 'Find Trainers Near You | Private 1-on-1 Training | PTP Training';
                $meta['description'] = 'Browse verified NCAA D1 athletes and professional players available for private training sessions. Book elite youth training in PA, NJ, NY, MD, DE.';
                $meta['keywords'] = 'private trainer, private lessons, youth training, coach near me, 1-on-1 training';
                break;
                
            case 'trainer-profile':
                $trainer_slug = isset($_GET['trainer']) ? sanitize_text_field($_GET['trainer']) : '';
                if ($trainer_slug) {
                    $trainer = PTP_Trainer::get_by_slug($trainer_slug);
                    if ($trainer) {
                        // Build location string
                        $location_str = '';
                        $geo_region = 'US-PA';
                        $geo_placename = '';
                        
                        if (!empty($trainer->locations)) {
                            $locations = is_string($trainer->locations) ? json_decode($trainer->locations, true) : $trainer->locations;
                            if (!empty($locations) && is_array($locations)) {
                                $first_loc = reset($locations);
                                if (is_array($first_loc) && !empty($first_loc['address'])) {
                                    $location_str = $first_loc['address'];
                                } elseif (is_string($first_loc)) {
                                    $location_str = $first_loc;
                                }
                                
                                // Extract city/state for geo
                                if (preg_match('/([^,]+),\s*([A-Z]{2})/', $location_str, $matches)) {
                                    $geo_placename = trim($matches[1]);
                                    $state = $matches[2];
                                    $geo_region = 'US-' . $state;
                                }
                            }
                        }
                        
                        // Build specialties string
                        $specialties_str = '';
                        if (!empty($trainer->specialties)) {
                            $specs = is_string($trainer->specialties) ? json_decode($trainer->specialties, true) : $trainer->specialties;
                            if (is_array($specs)) {
                                $specialties_str = implode(', ', array_slice($specs, 0, 3));
                            }
                        }
                        
                        // SEO-optimized title: "Name - Private Trainer in City, ST | PTP Training"
                        $title_location = $geo_placename ? " in {$geo_placename}" : '';
                        $meta['title'] = "{$trainer->display_name} - Private Trainer{$title_location} | PTP Training";
                        
                        // SEO-optimized description with keywords
                        $desc_parts = array();
                        $desc_parts[] = "Book private training with {$trainer->display_name}";
                        if ($trainer->college) {
                            $desc_parts[] = $trainer->college . ' athlete';
                        }
                        if ($specialties_str) {
                            $desc_parts[] = "specializing in {$specialties_str}";
                        }
                        $desc_parts[] = "\${$trainer->hourly_rate}/hour";
                        if ($geo_placename) {
                            $desc_parts[] = "serving {$geo_placename} area";
                        }
                        $desc_parts[] = 'Background verified';
                        
                        $meta['description'] = implode('. ', $desc_parts) . '. Book online today!';
                        
                        // Keywords
                        $keywords = array(
                            'private trainer ' . ($geo_placename ?: ''),
                            'private lessons',
                            $trainer->display_name,
                            'youth training',
                            '1-on-1 coach',
                        );
                        if ($specialties_str) {
                            $keywords[] = strtolower($specialties_str) . ' training';
                        }
                        $meta['keywords'] = implode(', ', array_filter($keywords));
                        
                        // Geo data for local SEO
                        $meta['geo'] = array(
                            'region' => $geo_region,
                            'placename' => $geo_placename,
                        );
                        if ($trainer->latitude && $trainer->longitude) {
                            $meta['geo']['position'] = $trainer->latitude . ';' . $trainer->longitude;
                        }
                        
                        $meta['og_type'] = 'profile';
                        $meta['trainer'] = $trainer;
                        $meta['url'] = home_url('/trainer-profile/?trainer=' . $trainer->slug);
                        
                        if ($trainer->photo_url) {
                            $meta['image'] = $trainer->photo_url;
                            $meta['image_alt'] = $trainer->display_name . ' - PTP Trainer';
                        }
                    }
                }
                break;
                
            case 'become-a-trainer':
                $meta['title'] = 'Become a PTP Trainer | Earn $50-100+/Hour | Apply Today';
                $meta['description'] = 'Turn your playing experience into income. NCAA D1 athletes and pros wanted. Set your own rates, flexible hours, earn up to 85% per session. Join 50+ elite trainers.';
                $meta['keywords'] = 'coaching jobs, become a trainer, trainer jobs, private instructor, athletic coaching';
                break;
                
            case 'login':
                $meta['title'] = 'Login | PTP Training';
                $meta['description'] = 'Sign in to manage your PTP Training account, view upcoming sessions, and message trainers.';
                break;
                
            case 'register':
                $meta['title'] = 'Create Account | PTP Training';
                $meta['description'] = 'Join PTP Training to book training sessions with NCAA D1 athletes and professional players.';
                break;
                
            case 'training':
                $meta['title'] = 'Private Training | Elite 1-on-1 Coaching | PTP Training';
                $meta['description'] = 'Book private training with elite NCAA D1 athletes and professional players. Personalized youth coaching for ages 6-14. Serving PA, NJ, NY, MD, DE.';
                $meta['keywords'] = 'private training, youth coaching, 1-on-1 lessons, elite training';
                break;
        }
        
        return $meta;
    }
    
    /**
     * Custom document title
     */
    public static function custom_title($title) {
        $meta = self::get_page_meta();
        
        if ($meta && !empty($meta['title'])) {
            return $meta['title'];
        }
        
        return $title;
    }
    
    /**
     * Modify title parts
     */
    public static function modify_title_parts($title_parts) {
        // Don't add site name on custom pages
        $page_slug = get_post_field('post_name', get_post());
        $ptp_pages = array('find-trainers', 'trainer-profile', 'become-a-trainer', 'my-training', 'trainer-dashboard');
        
        if (in_array($page_slug, $ptp_pages)) {
            unset($title_parts['site']);
        }
        
        return $title_parts;
    }
    
    /**
     * Output Schema.org structured data
     */
    public static function output_schema_markup() {
        $page_slug = get_post_field('post_name', get_post());
        
        // Organization schema on all pages
        $org_schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'SportsActivityLocation',
            'name' => 'PTP Training',
            'description' => 'Elite training with NCAA D1 athletes and professional players',
            'url' => home_url(),
            'logo' => get_option('ptp_logo_url', 'https://ptpsummercamps.com/wp-content/uploads/2025/10/Untitled-design-35.png'),
            'sameAs' => array(
                get_option('ptp_facebook_url', ''),
                get_option('ptp_instagram_url', ''),
                get_option('ptp_twitter_url', ''),
            ),
            'address' => array(
                '@type' => 'PostalAddress',
                'addressRegion' => 'PA',
                'addressCountry' => 'US',
            ),
            'priceRange' => '$50-$150',
        );
        
        // Filter out empty social URLs
        $org_schema['sameAs'] = array_filter($org_schema['sameAs']);
        
        echo '<script type="application/ld+json">' . json_encode($org_schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        
        // Trainer profile schema
        if ($page_slug === 'trainer-profile') {
            $trainer_slug = isset($_GET['trainer']) ? sanitize_text_field($_GET['trainer']) : '';
            if ($trainer_slug) {
                $trainer = PTP_Trainer::get_by_slug($trainer_slug);
                if ($trainer) {
                    // Person schema
                    $trainer_schema = array(
                        '@context' => 'https://schema.org',
                        '@type' => 'Person',
                        '@id' => home_url('/trainer-profile/?trainer=' . $trainer->slug . '#person'),
                        'name' => $trainer->display_name,
                        'givenName' => $trainer->first_name,
                        'familyName' => $trainer->last_name,
                        'jobTitle' => 'Private Trainer',
                        'description' => $trainer->headline ?: $trainer->bio,
                        'url' => home_url('/trainer-profile/?trainer=' . $trainer->slug),
                        'image' => $trainer->photo_url,
                        'worksFor' => array(
                            '@type' => 'Organization',
                            'name' => 'PTP Training',
                            'url' => home_url(),
                        ),
                        'knowsAbout' => array('Sports Training', 'Youth Development', 'Athletic Development'),
                    );
                    
                    // Add specialties
                    if (!empty($trainer->specialties)) {
                        $specs = is_string($trainer->specialties) ? json_decode($trainer->specialties, true) : $trainer->specialties;
                        if (is_array($specs)) {
                            $trainer_schema['knowsAbout'] = array_merge($trainer_schema['knowsAbout'], $specs);
                        }
                    }
                    
                    if ($trainer->college) {
                        $trainer_schema['alumniOf'] = array(
                            '@type' => 'CollegeOrUniversity',
                            'name' => $trainer->college,
                        );
                    }
                    
                    // Add aggregate rating if has reviews
                    if ($trainer->average_rating && $trainer->review_count) {
                        $trainer_schema['aggregateRating'] = array(
                            '@type' => 'AggregateRating',
                            'ratingValue' => $trainer->average_rating,
                            'reviewCount' => $trainer->review_count,
                            'bestRating' => 5,
                            'worstRating' => 1,
                        );
                    }
                    
                    echo '<script type="application/ld+json">' . json_encode($trainer_schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
                    
                    // Service schema with PriceSpecification
                    $service_schema = array(
                        '@context' => 'https://schema.org',
                        '@type' => 'Service',
                        '@id' => home_url('/trainer-profile/?trainer=' . $trainer->slug . '#service'),
                        'name' => 'Private Training with ' . $trainer->display_name,
                        'serviceType' => 'Private Athletic Training',
                        'description' => "One-on-one training sessions with {$trainer->display_name}, a verified " . ($trainer->college ?: 'elite') . " athlete.",
                        'provider' => array(
                            '@id' => home_url('/trainer-profile/?trainer=' . $trainer->slug . '#person'),
                        ),
                        'offers' => array(
                            '@type' => 'Offer',
                            'price' => $trainer->hourly_rate,
                            'priceCurrency' => 'USD',
                            'priceSpecification' => array(
                                '@type' => 'UnitPriceSpecification',
                                'price' => $trainer->hourly_rate,
                                'priceCurrency' => 'USD',
                                'unitCode' => 'HUR',
                                'unitText' => 'per hour',
                            ),
                            'availability' => 'https://schema.org/InStock',
                            'validFrom' => date('Y-m-d'),
                        ),
                        'category' => 'Sports Training',
                        'audience' => array(
                            '@type' => 'Audience',
                            'audienceType' => 'Youth Athletes',
                            'suggestedMinAge' => 6,
                            'suggestedMaxAge' => 14,
                        ),
                    );
                    
                    // Add area served
                    if ($trainer->latitude && $trainer->longitude) {
                        $service_schema['areaServed'] = array(
                            '@type' => 'GeoCircle',
                            'geoMidpoint' => array(
                                '@type' => 'GeoCoordinates',
                                'latitude' => $trainer->latitude,
                                'longitude' => $trainer->longitude,
                            ),
                            'geoRadius' => ($trainer->travel_radius ?: 15) . ' miles',
                        );
                    }
                    
                    echo '<script type="application/ld+json">' . json_encode($service_schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
                    
                    // LocalBusiness schema for local SEO
                    $local_business = array(
                        '@context' => 'https://schema.org',
                        '@type' => 'SportsActivityLocation',
                        'name' => $trainer->display_name . ' - PTP Training',
                        'description' => 'Private 1-on-1 training with ' . $trainer->display_name,
                        'url' => home_url('/trainer-profile/?trainer=' . $trainer->slug),
                        'image' => $trainer->photo_url,
                        'priceRange' => '$' . $trainer->hourly_rate . '/hour',
                        'telephone' => get_option('ptp_phone', ''),
                        'openingHoursSpecification' => array(
                            '@type' => 'OpeningHoursSpecification',
                            'dayOfWeek' => array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                            'opens' => '06:00',
                            'closes' => '21:00',
                        ),
                    );
                    
                    // Add location if available
                    if (!empty($trainer->locations)) {
                        $locations = is_string($trainer->locations) ? json_decode($trainer->locations, true) : $trainer->locations;
                        if (!empty($locations) && is_array($locations)) {
                            $first_loc = reset($locations);
                            $address = is_array($first_loc) ? ($first_loc['address'] ?? '') : $first_loc;
                            if ($address) {
                                $local_business['address'] = array(
                                    '@type' => 'PostalAddress',
                                    'streetAddress' => $address,
                                    'addressCountry' => 'US',
                                );
                            }
                        }
                    }
                    
                    if ($trainer->latitude && $trainer->longitude) {
                        $local_business['geo'] = array(
                            '@type' => 'GeoCoordinates',
                            'latitude' => $trainer->latitude,
                            'longitude' => $trainer->longitude,
                        );
                    }
                    
                    // Add rating if available
                    if ($trainer->average_rating && $trainer->review_count) {
                        $local_business['aggregateRating'] = array(
                            '@type' => 'AggregateRating',
                            'ratingValue' => $trainer->average_rating,
                            'reviewCount' => $trainer->review_count,
                        );
                    }
                    
                    echo '<script type="application/ld+json">' . json_encode($local_business, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
                }
            }
        }
        
        // BreadcrumbList schema
        $breadcrumbs = self::get_breadcrumbs();
        if (!empty($breadcrumbs)) {
            $breadcrumb_schema = array(
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => array(),
            );
            
            foreach ($breadcrumbs as $i => $crumb) {
                $breadcrumb_schema['itemListElement'][] = array(
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                );
            }
            
            echo '<script type="application/ld+json">' . json_encode($breadcrumb_schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        }
        
        // FAQ Schema for landing pages
        if ($page_slug === 'find-trainers' || $page_slug === 'become-a-trainer') {
            $faqs = self::get_page_faqs($page_slug);
            if (!empty($faqs)) {
                $faq_schema = array(
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => array(),
                );
                
                foreach ($faqs as $faq) {
                    $faq_schema['mainEntity'][] = array(
                        '@type' => 'Question',
                        'name' => $faq['question'],
                        'acceptedAnswer' => array(
                            '@type' => 'Answer',
                            'text' => $faq['answer'],
                        ),
                    );
                }
                
                echo '<script type="application/ld+json">' . json_encode($faq_schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
            }
        }
    }
    
    /**
     * Get breadcrumbs for current page
     */
    private static function get_breadcrumbs() {
        $page_slug = get_post_field('post_name', get_post());
        
        $breadcrumbs = array(
            array('name' => 'Home', 'url' => home_url()),
        );
        
        switch ($page_slug) {
            case 'find-trainers':
                $breadcrumbs[] = array('name' => 'Find Trainers', 'url' => home_url('/find-trainers/'));
                break;
                
            case 'trainer-profile':
                $breadcrumbs[] = array('name' => 'Find Trainers', 'url' => home_url('/find-trainers/'));
                $trainer_slug = isset($_GET['trainer']) ? sanitize_text_field($_GET['trainer']) : '';
                if ($trainer_slug) {
                    $trainer = PTP_Trainer::get_by_slug($trainer_slug);
                    if ($trainer) {
                        $breadcrumbs[] = array('name' => $trainer->display_name, 'url' => get_permalink() . '?trainer=' . $trainer->slug);
                    }
                }
                break;
                
            case 'become-a-trainer':
                $breadcrumbs[] = array('name' => 'Become a Trainer', 'url' => home_url('/become-a-trainer/'));
                break;
        }
        
        return $breadcrumbs;
    }
    
    /**
     * Get FAQs for page
     */
    private static function get_page_faqs($page_slug) {
        $faqs = array();
        
        if ($page_slug === 'find-trainers') {
            $faqs = array(
                array(
                    'question' => 'How do I book a training session?',
                    'answer' => 'Browse our verified trainers, select one that matches your needs, choose an available time slot, and complete your booking online. You\'ll receive a confirmation email immediately.',
                ),
                array(
                    'question' => 'Who are the PTP trainers?',
                    'answer' => 'All PTP trainers are verified NCAA Division 1 athletes or professional players. Each trainer undergoes a background check and verification process.',
                ),
                array(
                    'question' => 'What ages do you train?',
                    'answer' => 'PTP Training offers sessions for athletes ages 6-14. Our trainers specialize in youth development and work with all skill levels from beginner to elite.',
                ),
                array(
                    'question' => 'What is your cancellation policy?',
                    'answer' => 'You can cancel or reschedule your session up to 24 hours before the scheduled time for a full refund. Cancellations within 24 hours may be subject to a fee.',
                ),
            );
        }
        
        if ($page_slug === 'become-a-trainer') {
            $faqs = array(
                array(
                    'question' => 'What are the requirements to become a trainer?',
                    'answer' => 'Trainers must have played at the NCAA Division 1 level or higher, pass a background check, and demonstrate coaching ability. We also accept professional academy players.',
                ),
                array(
                    'question' => 'How much can I earn?',
                    'answer' => 'Trainers set their own rates, typically between $50-$100+ per hour. You earn up to 85% of each session fee as you build client relationships. Many trainers earn $500-$2000+ per week.',
                ),
                array(
                    'question' => 'How flexible is the schedule?',
                    'answer' => 'You have complete control over your availability. Set your own hours and accept sessions that fit your schedule.',
                ),
            );
        }
        
        return $faqs;
    }
    
    /**
     * Social share buttons shortcode
     */
    public static function social_share_buttons($atts) {
        $atts = shortcode_atts(array(
            'url' => get_permalink(),
            'title' => get_the_title(),
            'style' => 'buttons', // buttons, icons, minimal
        ), $atts);
        
        $url = urlencode($atts['url']);
        $title = urlencode($atts['title']);
        
        $shares = array(
            'facebook' => array(
                'url' => "https://www.facebook.com/sharer/sharer.php?u={$url}",
                'label' => 'Facebook',
                'icon' => 'facebook',
                'color' => '#1877F2',
            ),
            'twitter' => array(
                'url' => "https://twitter.com/intent/tweet?url={$url}&text={$title}",
                'label' => 'Twitter',
                'icon' => 'twitter',
                'color' => '#1DA1F2',
            ),
            'linkedin' => array(
                'url' => "https://www.linkedin.com/shareArticle?mini=true&url={$url}&title={$title}",
                'label' => 'LinkedIn',
                'icon' => 'linkedin',
                'color' => '#0A66C2',
            ),
            'whatsapp' => array(
                'url' => "https://wa.me/?text={$title}%20{$url}",
                'label' => 'WhatsApp',
                'icon' => 'whatsapp',
                'color' => '#25D366',
            ),
            'email' => array(
                'url' => "mailto:?subject={$title}&body=Check%20this%20out:%20{$url}",
                'label' => 'Email',
                'icon' => 'email',
                'color' => '#666666',
            ),
        );
        
        ob_start();
        ?>
        <div class="ptp-social-share ptp-social-share--<?php echo esc_attr($atts['style']); ?>">
            <?php foreach ($shares as $network => $data): ?>
                <a href="<?php echo esc_url($data['url']); ?>" 
                   class="ptp-share-btn ptp-share-btn--<?php echo $network; ?>"
                   style="--share-color: <?php echo $data['color']; ?>;"
                   target="_blank"
                   rel="noopener noreferrer"
                   onclick="window.open(this.href, 'share-<?php echo $network; ?>', 'width=600,height=400'); return false;">
                    <span class="ptp-share-icon"><?php echo self::get_social_icon($data['icon']); ?></span>
                    <?php if ($atts['style'] !== 'icons'): ?>
                        <span class="ptp-share-label"><?php echo esc_html($data['label']); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <style>
            .ptp-social-share { display: flex; gap: 10px; flex-wrap: wrap; }
            .ptp-share-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: var(--share-color); color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500; transition: opacity 0.2s; }
            .ptp-share-btn:hover { opacity: 0.9; color: #fff; }
            .ptp-share-icon svg { width: 18px; height: 18px; fill: currentColor; }
            .ptp-social-share--icons .ptp-share-btn { padding: 10px; border-radius: 50%; }
            .ptp-social-share--minimal .ptp-share-btn { background: transparent; color: var(--share-color); border: 1px solid currentColor; }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get social icon SVG
     */
    private static function get_social_icon($icon) {
        $icons = array(
            'facebook' => '<svg viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            'twitter' => '<svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            'linkedin' => '<svg viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
            'whatsapp' => '<svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>',
            'email' => '<svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
        );
        
        return $icons[$icon] ?? '';
    }
    
    /**
     * Add trainers to sitemap
     */
    public static function sitemap_add_trainers($args, $post_type) {
        // This would need custom sitemap provider for trainer profiles
        return $args;
    }
    
    /**
     * Generate sitemap XML for trainers
     */
    public static function generate_trainer_sitemap() {
        $trainers = PTP_Trainer::get_all(array('status' => 'active'));
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        foreach ($trainers as $trainer) {
            $url = home_url('/trainer/' . $trainer->slug . '/');
            $lastmod = date('Y-m-d', strtotime($trainer->updated_at));
            
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . esc_url($url) . "</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>0.8</priority>\n";
            $xml .= "  </url>\n";
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }
    
    /**
     * Add canonical URL
     */
    public static function add_canonical() {
        $page_slug = get_post_field('post_name', get_post());
        $canonical = get_permalink();
        
        if ($page_slug === 'trainer-profile') {
            $trainer_slug = isset($_GET['trainer']) ? sanitize_text_field($_GET['trainer']) : '';
            if ($trainer_slug) {
                $canonical = home_url('/trainer/' . $trainer_slug . '/');
            }
        }
        
        echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
    }
}
