<?php
/**
 * PTP Camps Cross-Sell Component v2.0.0
 * 
 * Simplified, mobile-first cross-sell component
 * Clean horizontal scroll on mobile, minimal design
 */

defined('ABSPATH') || exit;

/**
 * Render the camps cross-sell section
 */
function ptp_render_camps_crosssell($options = array()) {
    // v223.1: Removed dead if(!false) guard
    
    $defaults = array(
        'limit' => 4,
        'title' => 'Upcoming Camps & Clinics',
        'show_view_all' => true,
        'context' => 'general',
        'exclude_ids' => array(),
    );
    $opts = array_merge($defaults, $options);
    
    $camps = ptp_get_camps_clinics($opts['limit'], $opts['exclude_ids']);
    
    if (empty($camps)) {
        return;
    }
    
    $shop_url = home_url('/summer-camps/');
    ?>
    
    <style>
    .ptp-camps-simple {
        background: #0A0A0A;
        padding: 24px 16px 20px;
        font-family: 'Inter', -apple-system, sans-serif;
    }
    
    .ptp-camps-simple-inner {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    .ptp-camps-simple-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
    }
    
    .ptp-camps-simple-title {
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: #fff;
        margin: 0;
    }
    
    .ptp-camps-simple-viewall {
        font-family: 'Oswald', sans-serif;
        font-size: 11px;
        font-weight: 700;
        color: #FCB900;
        text-decoration: none;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    
    .ptp-camps-simple-viewall:hover {
        color: #fff;
    }
    
    .ptp-camps-simple-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
    }
    
    .ptp-camp-simple {
        background: #fff;
        border-radius: 6px;
        overflow: hidden;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        transition: transform 0.15s;
    }
    
    .ptp-camp-simple:hover {
        transform: translateY(-2px);
    }
    
    .ptp-camp-simple-img {
        width: 100%;
        height: 90px;
        object-fit: cover;
    }
    
    .ptp-camp-simple-body {
        padding: 8px 10px 10px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    
    .ptp-camp-simple-name {
        font-family: 'Oswald', sans-serif;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.01em;
        color: #0A0A0A;
        margin: 0;
        line-height: 1.25;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        height: 25px;
    }
    
    .ptp-camp-simple-date {
        font-size: 9px;
        color: #6B7280;
        margin-top: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .ptp-camp-simple-price {
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 700;
        color: #0A0A0A;
        margin-top: auto;
        padding-top: 4px;
    }
    
    .ptp-camp-simple-badge {
        position: absolute;
        top: 5px;
        right: 5px;
        background: #DC2626;
        color: #fff;
        font-size: 7px;
        font-weight: 700;
        padding: 2px 5px;
        border-radius: 2px;
        text-transform: uppercase;
    }
    
    .ptp-camp-simple-badge.new {
        background: #10B981;
    }
    
    .ptp-camp-simple-imgwrap {
        position: relative;
    }
    
    @media (max-width: 768px) {
        .ptp-camps-simple {
            padding: 20px 0 16px;
        }
        
        .ptp-camps-simple-header {
            padding: 0 16px;
            margin-bottom: 12px;
        }
        
        .ptp-camps-simple-grid {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding: 0 16px;
        }
        
        .ptp-camps-simple-grid::-webkit-scrollbar {
            display: none;
        }
        
        .ptp-camp-simple {
            flex: 0 0 130px;
            scroll-snap-align: start;
        }
        
        .ptp-camp-simple-img {
            height: 75px;
        }
        
        .ptp-camp-simple-body {
            padding: 6px 8px 8px;
        }
        
        .ptp-camp-simple-name {
            font-size: 9px;
            height: 22px;
        }
        
        .ptp-camp-simple-date {
            font-size: 8px;
        }
        
        .ptp-camp-simple-price {
            font-size: 13px;
        }
    }
    </style>
    
    <div class="ptp-camps-simple" data-context="<?php echo esc_attr($opts['context']); ?>">
        <div class="ptp-camps-simple-inner">
            <div class="ptp-camps-simple-header">
                <h2 class="ptp-camps-simple-title"><?php echo esc_html($opts['title']); ?></h2>
                <?php if ($opts['show_view_all']): ?>
                <a href="<?php echo esc_url($shop_url); ?>" class="ptp-camps-simple-viewall">
                    View All
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
                <?php endif; ?>
            </div>
            
            <div class="ptp-camps-simple-grid">
                <?php foreach ($camps as $product): 
                    $image = wp_get_attachment_image_url($product->get_image_id(), 'medium');
                    if (!$image) {
                        $image = PTP_PLUGIN_URL . 'assets/images/placeholder.jpg';
                    }
                    $price = $product->get_price();
                    $regular = $product->get_regular_price();
                    $on_sale = $product->is_on_sale();
                    $date = ptp_extract_camp_date($product);
                    $location = ptp_extract_camp_location($product);
                    $is_new = (time() - strtotime($product->get_date_created())) < (14 * 24 * 60 * 60);
                    $stock = $product->get_stock_quantity();
                    $in_stock = $product->is_in_stock();
                ?>
                <a href="<?php echo esc_url($product->get_permalink()); ?>" class="ptp-camp-simple" data-product-id="<?php echo $product->get_id(); ?>">
                    <div class="ptp-camp-simple-imgwrap">
                        <img class="ptp-camp-simple-img" src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($product->get_name()); ?>" loading="lazy">
                        <?php if ($on_sale): ?>
                        <span class="ptp-camp-simple-badge">Sale</span>
                        <?php elseif ($is_new): ?>
                        <span class="ptp-camp-simple-badge new">New</span>
                        <?php endif; ?>
                    </div>
                    <div class="ptp-camp-simple-body">
                        <div class="ptp-camp-simple-name"><?php echo esc_html($product->get_name()); ?></div>
                        <?php if ($date || $location): ?>
                        <div class="ptp-camp-simple-date">
                            <?php echo esc_html(trim($date . ($date && $location ? ' · ' : '') . $location)); ?>
                        </div>
                        <?php endif; ?>
                        <div class="ptp-camp-simple-price">$<?php echo number_format((float)$price); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Get camps and clinics products
 */
function ptp_get_camps_clinics($limit = 4, $exclude_ids = array()) {
    $camps = array();
    
    $args = array(
        'status' => 'publish',
        'limit' => $limit,
        'category' => array('camps', 'clinics', 'camp', 'clinic', 'winter-clinics', 'summer-camps'),
        'orderby' => 'date',
        'order' => 'DESC',
        'exclude' => $exclude_ids,
    );
    
    $camps = ptp_get_camp_products($args);
    
    if (empty($camps)) {
        $all_products = ptp_get_camp_products(array(
            'status' => 'publish',
            'limit' => 30,
            'orderby' => 'date',
            'order' => 'DESC',
            'exclude' => $exclude_ids,
        ));
        
        foreach ($all_products as $product) {
            $title_lower = strtolower($product->get_name());
            $cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'slugs'));
            
            $is_camp = strpos($title_lower, 'camp') !== false 
                    || strpos($title_lower, 'clinic') !== false
                    || array_intersect($cats, array('camps', 'clinics', 'camp', 'clinic'));
            
            if ($is_camp) {
                $camps[] = $product;
            }
            
            if (count($camps) >= $limit) break;
        }
    }
    
    return $camps;
}

/**
 * Extract date from product
 */
function ptp_extract_camp_date($product) {
    $date = get_post_meta($product->get_id(), '_event_date', true);
    if ($date) {
        return date('M j', strtotime($date));
    }
    
    $attributes = $product->get_attributes();
    if (isset($attributes['date']) || isset($attributes['pa_date'])) {
        $attr = $attributes['date'] ?? $attributes['pa_date'];
        if (is_object($attr) && method_exists($attr, 'get_options')) {
            $options = $attr->get_options();
            if (!empty($options)) {
                return is_array($options) ? reset($options) : $options;
            }
        }
    }
    
    $title = $product->get_name();
    if (preg_match('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s*\d{1,2}(?:st|nd|rd|th)?/i', $title, $match)) {
        return $match[0];
    }
    
    return '';
}

/**
 * Extract location from product
 */
function ptp_extract_camp_location($product) {
    $location = get_post_meta($product->get_id(), '_event_location', true);
    if ($location) {
        if (strlen($location) > 20) {
            $parts = explode(',', $location);
            return trim($parts[0]);
        }
        return $location;
    }
    
    $attributes = $product->get_attributes();
    if (isset($attributes['location']) || isset($attributes['pa_location'])) {
        $attr = $attributes['location'] ?? $attributes['pa_location'];
        if (is_object($attr) && method_exists($attr, 'get_options')) {
            $options = $attr->get_options();
            if (!empty($options)) {
                $loc = is_array($options) ? reset($options) : $options;
                if (strlen($loc) > 20) {
                    $parts = explode(',', $loc);
                    return trim($parts[0]);
                }
                return $loc;
            }
        }
    }
    
    return '';
}

/**
 * Shortcode for displaying camps cross-sell
 */
add_shortcode('ptp_camps_crosssell', function($atts) {
    $atts = shortcode_atts(array(
        'limit' => 4,
        'title' => 'Upcoming Camps & Clinics',
    ), $atts);
    
    ob_start();
    ptp_render_camps_crosssell($atts);
    return ob_get_clean();
});
