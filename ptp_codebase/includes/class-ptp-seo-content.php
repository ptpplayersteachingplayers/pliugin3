<?php
/**
 * PTP SEO Content Generator
 * 
 * Generates unique, keyword-rich content for location pages
 * Avoids duplicate content penalties with dynamic templates
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_SEO_Content {
    
    /**
     * Generate page content based on location/service
     */
    public static function generate_content($page_data) {
        $content = array();
        
        // Introduction paragraph
        $content['intro'] = self::generate_intro($page_data);
        
        // Why choose PTP section
        $content['why_ptp'] = self::generate_why_ptp($page_data);
        
        // What to expect section
        $content['what_to_expect'] = self::generate_what_to_expect($page_data);
        
        // Training types section
        $content['training_types'] = self::generate_training_types($page_data);
        
        // Local insights section
        $content['local_insights'] = self::generate_local_insights($page_data);
        
        // Call to action
        $content['cta'] = self::generate_cta($page_data);
        
        return $content;
    }
    
    /**
     * Generate intro paragraph
     */
    private static function generate_intro($data) {
        $location = self::get_location_string($data);
        $service = $data['service'] ? $data['service']['name'] : 'soccer training';
        
        $intros = array(
            "Looking for elite {$service} {$location}? PTP connects families with verified NCAA Division 1 athletes and professional players for personalized training sessions. Our trainers bring real game experience to every session, teaching what team coaches don't have time to cover.",
            
            "Transform your player's skills with {$service} {$location}. At PTP, we believe the best coaches are those who've competed at the highest levels. That's why every trainer on our platform is a verified D1 athlete or professional player ready to share their expertise.",
            
            "Discover the difference elite coaching makes with {$service} {$location}. PTP's network of NCAA D1 athletes and pro players offer personalized training that focuses on the individual skills your child needs to stand out on the field.",
        );
        
        return $intros[array_rand($intros)];
    }
    
    /**
     * Generate why choose PTP content
     */
    private static function generate_why_ptp($data) {
        $location = self::get_location_string($data);
        
        $benefits = array(
            array(
                'title' => 'Elite Trainers Only',
                'text' => "Every PTP trainer is a verified NCAA Division 1 athlete or professional player. No weekend warriors or self-proclaimed experts—only coaches who've competed at the highest levels of the sport.",
            ),
            array(
                'title' => 'Personalized Development',
                'text' => "Unlike team practices where coaches manage 15+ players, our 1-on-1 sessions focus entirely on your child's specific needs. We identify weaknesses, build on strengths, and create real improvement.",
            ),
            array(
                'title' => 'Flexible Scheduling',
                'text' => "Book sessions that work with your family's schedule. Our trainers offer morning, afternoon, and evening availability, plus weekend sessions. Train at local fields, parks, or your own backyard.",
            ),
            array(
                'title' => 'Real Results',
                'text' => "Our families report significant skill improvements after just a few sessions. With a 4.9-star rating across 500+ reviews, parents trust PTP to develop their players.",
            ),
        );
        
        return $benefits;
    }
    
    /**
     * Generate what to expect content
     */
    private static function generate_what_to_expect($data) {
        $service = $data['service'] ? $data['service']['slug'] : 'private-soccer-training';
        
        $expectations = array(
            'private-soccer-training' => array(
                'duration' => '60-90 minutes per session',
                'focus' => 'Individual skill development tailored to your player',
                'format' => 'One-on-one with your dedicated trainer',
                'equipment' => 'Trainer brings all necessary equipment',
                'feedback' => 'Post-session notes and progress tracking',
            ),
            'soccer-camps' => array(
                'duration' => 'Full week or half-day programs',
                'focus' => 'Comprehensive skill building and game play',
                'format' => 'Small groups led by multiple elite trainers',
                'equipment' => 'Jersey, player card, and skills passport included',
                'feedback' => 'Daily videos and MVP recognition',
            ),
            'soccer-clinics' => array(
                'duration' => '2-3 hour focused sessions',
                'focus' => 'Specific skills like finishing, defending, or speed',
                'format' => 'Small groups (8-12 players) for maximum touches',
                'equipment' => 'All equipment provided',
                'feedback' => 'Skill assessment and improvement tips',
            ),
            'group-soccer-training' => array(
                'duration' => '60-90 minutes per session',
                'focus' => 'Team dynamics and position-specific work',
                'format' => 'Groups of 2-4 players with similar skill levels',
                'equipment' => 'All equipment provided by trainer',
                'feedback' => 'Group progress reports',
            ),
            'goalkeeper-training' => array(
                'duration' => '60-90 minutes per session',
                'focus' => 'Shot stopping, distribution, positioning, communication',
                'format' => 'One-on-one or small groups of goalkeepers',
                'equipment' => 'Specialized GK equipment provided',
                'feedback' => 'Video analysis and technique review',
            ),
            'youth-soccer-training' => array(
                'duration' => '45-60 minutes for younger players',
                'focus' => 'Age-appropriate skill building and fun',
                'format' => 'Patient, encouraging 1-on-1 instruction',
                'equipment' => 'Age-appropriate balls and equipment',
                'feedback' => 'Parent communication and progress updates',
            ),
        );
        
        return $expectations[$service] ?? $expectations['private-soccer-training'];
    }
    
    /**
     * Generate training types content
     */
    private static function generate_training_types($data) {
        return array(
            array(
                'name' => 'Technical Skills',
                'skills' => array('Ball control', 'First touch', 'Dribbling', 'Passing accuracy', 'Shooting technique'),
            ),
            array(
                'name' => 'Tactical Awareness',
                'skills' => array('Positioning', 'Game reading', 'Decision making', 'Space creation', 'Defensive shape'),
            ),
            array(
                'name' => 'Physical Development',
                'skills' => array('Speed and agility', 'Endurance', 'Balance', 'Coordination', 'Strength'),
            ),
            array(
                'name' => 'Mental Game',
                'skills' => array('Confidence', 'Focus', 'Composure under pressure', 'Leadership', 'Communication'),
            ),
        );
    }
    
    /**
     * Generate local insights content
     */
    private static function generate_local_insights($data) {
        if (!$data['city'] && !$data['state']) {
            return null;
        }
        
        $insights = array();
        
        if ($data['city']) {
            $city = $data['city']['name'];
            $state = $data['state']['abbr'];
            
            $insights['headline'] = "Soccer Training in {$city}, {$state}";
            $insights['text'] = "{$city} is home to a vibrant youth soccer community. Our local trainers understand the competitive landscape of {$state} club soccer and what it takes to stand out. Whether you're preparing for high school tryouts, club team selections, or just want to improve your game, our {$city}-based trainers bring local expertise and connections.";
            
            // Add nearby areas
            if (!empty($data['nearby_cities'])) {
                $nearby_names = array_slice(array_column($data['nearby_cities'], 'name'), 0, 5);
                $insights['nearby'] = "We also serve nearby areas including " . implode(', ', $nearby_names) . " and surrounding communities.";
            }
        } elseif ($data['state']) {
            $state = $data['state']['name'];
            
            $insights['headline'] = "Soccer Training Across {$state}";
            $insights['text'] = "{$state} has a rich soccer tradition with competitive club and high school programs. Our network of elite trainers spans the state, bringing D1 and professional experience to players in every region. From major metros to suburban communities, PTP has trainers ready to develop the next generation of {$state} soccer talent.";
        }
        
        return $insights;
    }
    
    /**
     * Generate CTA content
     */
    private static function generate_cta($data) {
        $location = self::get_location_string($data);
        $service = $data['service'] ? strtolower($data['service']['short']) : 'training';
        
        $ctas = array(
            array(
                'headline' => "Ready to Start Training?",
                'text' => "Browse our roster of elite trainers {$location} and book your first session today. Most families see improvement within just a few sessions.",
                'button' => "Find Your Trainer",
            ),
            array(
                'headline' => "Take Your Game to the Next Level",
                'text' => "Join 500+ families who trust PTP for their soccer development. Book {$service} {$location} with a verified NCAA D1 athlete or pro player.",
                'button' => "Get Started",
            ),
            array(
                'headline' => "Start Your Development Journey",
                'text' => "The best players never stop learning. Connect with an elite trainer {$location} and see what personalized coaching can do for your game.",
                'button' => "Browse Trainers",
            ),
        );
        
        return $ctas[array_rand($ctas)];
    }
    
    /**
     * Get location string for content
     */
    private static function get_location_string($data) {
        if ($data['city'] && $data['state']) {
            return "in {$data['city']['name']}, {$data['state']['abbr']}";
        } elseif ($data['state']) {
            return "in {$data['state']['name']}";
        }
        return "near you";
    }
    
    /**
     * Generate meta description
     */
    public static function generate_meta_description($data) {
        $location = self::get_location_string($data);
        $service = $data['service'] ? $data['service']['name'] : 'soccer training';
        
        $descriptions = array(
            "Find elite {$service} {$location}. Book verified NCAA D1 athletes and pro players for personalized sessions. 4.9★ rating, 500+ families trained.",
            "Book {$service} {$location} with NCAA D1 athletes & pro players. Personalized 1-on-1 sessions. Flexible scheduling. Trusted by 500+ families.",
            "{$service} {$location} from verified elite coaches. NCAA D1 athletes & pros teaching what team coaches don't. Book your session today.",
        );
        
        return $descriptions[array_rand($descriptions)];
    }
    
    /**
     * Generate keywords
     */
    public static function generate_keywords($data) {
        $keywords = array(
            'soccer training',
            'private soccer lessons',
            'youth soccer coach',
            '1 on 1 soccer training',
            'soccer skills training',
        );
        
        if ($data['city']) {
            $city = strtolower($data['city']['name']);
            $keywords[] = "soccer training {$city}";
            $keywords[] = "soccer coach {$city}";
            $keywords[] = "private soccer {$city}";
        }
        
        if ($data['state']) {
            $state = strtolower($data['state']['name']);
            $keywords[] = "soccer training {$state}";
            $keywords[] = "{$state} soccer camps";
        }
        
        if ($data['service']) {
            $keywords = array_merge($keywords, $data['service']['keywords']);
        }
        
        return array_unique($keywords);
    }
    
    /**
     * Generate structured data for rich snippets
     */
    public static function generate_rich_snippets($data) {
        $location = self::get_location_string($data);
        
        $snippets = array(
            'review_snippet' => array(
                'rating' => '4.9',
                'count' => '500+',
                'text' => "Rated 4.9/5 by parents {$location}",
            ),
            'price_snippet' => array(
                'range' => '$65 - $150',
                'text' => 'per hour',
            ),
            'availability_snippet' => array(
                'text' => 'Book online • Instant confirmation',
            ),
        );
        
        return $snippets;
    }
}
