<?php
/**
 * PTP Image Optimizer
 * 
 * Handles responsive image generation, lazy loading, and WebP serving
 * Improves Core Web Vitals (LCP, CLS) for mobile
 * 
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class PTP_Image_Optimizer {
    
    private static $instance = null;
    
    // Standard responsive breakpoints
    private static $sizes = [
        'thumb' => 400,
        'small' => 640,
        'medium' => 800,
        'large' => 1200,
        'xlarge' => 1600,
        'full' => 2560
    ];
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Register custom image sizes
        add_action('after_setup_theme', [$this, 'register_image_sizes']);
        
        // Filter content images
        add_filter('the_content', [$this, 'add_responsive_images'], 20);
        
        // Add WebP support check
        add_action('wp_head', [$this, 'add_webp_detection'], 1);
    }
    
    /**
     * Register custom image sizes for WordPress
     */
    public function register_image_sizes() {
        foreach (self::$sizes as $name => $width) {
            add_image_size('ptp_' . $name, $width, 0, false);
        }
    }
    
    /**
     * Add WebP detection script
     */
    public function add_webp_detection() {
        ?>
        <script>
        (function(){
            var webp = new Image();
            webp.onload = webp.onerror = function(){
                document.documentElement.classList.add(webp.height == 2 ? 'webp' : 'no-webp');
            };
            webp.src = 'data:image/webp;base64,UklGRjoAAABXRUJQVlA4IC4AAACyAgCdASoCAAIALmk0mk0iIiIiIgBoSygABc6WWgAA/veff/0PP8bA//LwYAAA';
        })();
        </script>
        <?php
    }
    
    /**
     * Generate responsive image HTML
     * 
     * @param string $image_url Full image URL
     * @param string $alt Alt text
     * @param array $options Additional options
     * @return string HTML img tag with srcset
     */
    public static function responsive_img($image_url, $alt = '', $options = []) {
        $defaults = [
            'class' => '',
            'sizes' => '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw',
            'loading' => 'lazy',
            'decoding' => 'async',
            'aspect_ratio' => null, // e.g., '1/1', '16/9', '4/5'
            'placeholder' => true,
            'width' => null,
            'height' => null
        ];
        
        $opts = array_merge($defaults, $options);
        
        // Get attachment ID from URL
        $attachment_id = attachment_url_to_postid($image_url);
        
        if ($attachment_id) {
            // WordPress managed image - use built-in srcset
            return self::wp_responsive_img($attachment_id, $alt, $opts);
        }
        
        // External or unmanaged image - use CDN approach
        return self::cdn_responsive_img($image_url, $alt, $opts);
    }
    
    /**
     * Generate responsive image from WordPress attachment
     */
    private static function wp_responsive_img($attachment_id, $alt, $opts) {
        $image_src = wp_get_attachment_image_src($attachment_id, 'full');
        
        if (!$image_src) {
            return '';
        }
        
        $srcset = wp_get_attachment_image_srcset($attachment_id, 'full');
        $sizes = wp_get_attachment_image_sizes($attachment_id, 'full');
        
        // Override sizes if provided
        if (!empty($opts['sizes'])) {
            $sizes = $opts['sizes'];
        }
        
        // Build attributes
        $attrs = [
            'src' => esc_url($image_src[0]),
            'alt' => esc_attr($alt),
            'loading' => $opts['loading'],
            'decoding' => $opts['decoding'],
        ];
        
        if ($srcset) {
            $attrs['srcset'] = $srcset;
            $attrs['sizes'] = $sizes;
        }
        
        if ($opts['class']) {
            $attrs['class'] = esc_attr($opts['class']);
        }
        
        if ($opts['width']) {
            $attrs['width'] = intval($opts['width']);
        } elseif ($image_src[1]) {
            $attrs['width'] = $image_src[1];
        }
        
        if ($opts['height']) {
            $attrs['height'] = intval($opts['height']);
        } elseif ($image_src[2]) {
            $attrs['height'] = $image_src[2];
        }
        
        // Build tag
        $attr_string = '';
        foreach ($attrs as $key => $value) {
            $attr_string .= ' ' . $key . '="' . $value . '"';
        }
        
        // Add aspect ratio style if specified
        $style = '';
        if ($opts['aspect_ratio']) {
            $style = ' style="aspect-ratio: ' . esc_attr($opts['aspect_ratio']) . '; object-fit: cover;"';
        }
        
        return '<img' . $attr_string . $style . '>';
    }
    
    /**
     * Generate responsive image using URL manipulation (for CDN/external images)
     */
    private static function cdn_responsive_img($image_url, $alt, $opts) {
        // For external images, we can't generate srcset server-side
        // But we can add proper attributes for browser optimization
        
        $attrs = [
            'src' => esc_url($image_url),
            'alt' => esc_attr($alt),
            'loading' => $opts['loading'],
            'decoding' => $opts['decoding'],
        ];
        
        if ($opts['class']) {
            $attrs['class'] = esc_attr($opts['class']);
        }
        
        if ($opts['width']) {
            $attrs['width'] = intval($opts['width']);
        }
        
        if ($opts['height']) {
            $attrs['height'] = intval($opts['height']);
        }
        
        // Build tag
        $attr_string = '';
        foreach ($attrs as $key => $value) {
            $attr_string .= ' ' . $key . '="' . $value . '"';
        }
        
        // Add aspect ratio style if specified
        $style = '';
        if ($opts['aspect_ratio']) {
            $style = ' style="aspect-ratio: ' . esc_attr($opts['aspect_ratio']) . '; object-fit: cover;"';
        }
        
        return '<img' . $attr_string . $style . '>';
    }
    
    /**
     * Add responsive attributes to content images
     */
    public function add_responsive_images($content) {
        if (empty($content)) {
            return $content;
        }
        
        // Find all img tags without loading attribute
        $content = preg_replace_callback(
            '/<img([^>]+)>/i',
            function($matches) {
                $img = $matches[0];
                
                // Skip if already has loading attribute
                if (strpos($img, 'loading=') !== false) {
                    return $img;
                }
                
                // Add loading="lazy" and decoding="async"
                $img = str_replace('<img', '<img loading="lazy" decoding="async"', $img);
                
                return $img;
            },
            $content
        );
        
        return $content;
    }
    
    /**
     * Generate placeholder SVG for aspect ratio
     */
    public static function placeholder_svg($width, $height, $color = '#f3f4f6') {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
        $svg .= '<rect width="100%" height="100%" fill="' . $color . '"/>';
        $svg .= '</svg>';
        
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
    
    /**
     * Get optimized image URL for a specific size
     * If using a CDN like Cloudflare, this would add resize parameters
     */
    public static function get_sized_url($image_url, $width) {
        // Check if this is a WordPress upload
        $upload_dir = wp_upload_dir();
        
        if (strpos($image_url, $upload_dir['baseurl']) !== false) {
            // Try to get sized version
            $attachment_id = attachment_url_to_postid($image_url);
            
            if ($attachment_id) {
                // Find closest size
                $target_size = 'full';
                foreach (self::$sizes as $name => $size_width) {
                    if ($size_width >= $width) {
                        $target_size = 'ptp_' . $name;
                        break;
                    }
                }
                
                $sized = wp_get_attachment_image_src($attachment_id, $target_size);
                if ($sized) {
                    return $sized[0];
                }
            }
        }
        
        // Cloudflare image resizing (if enabled)
        // Uncomment if using Cloudflare Pro/Business with Image Resizing
        /*
        if (strpos($image_url, 'ptpsummercamps.com') !== false) {
            return str_replace(
                'ptpsummercamps.com/',
                'ptpsummercamps.com/cdn-cgi/image/width=' . $width . ',format=auto,quality=80/',
                $image_url
            );
        }
        */
        
        return $image_url;
    }
    
    /**
     * Generate picture element with WebP fallback
     */
    public static function picture($image_url, $alt = '', $options = []) {
        $defaults = [
            'class' => '',
            'sizes' => '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw',
            'loading' => 'lazy',
            'aspect_ratio' => null
        ];
        
        $opts = array_merge($defaults, $options);
        
        // Get attachment ID
        $attachment_id = attachment_url_to_postid($image_url);
        
        if (!$attachment_id) {
            // Fallback to simple img
            return self::responsive_img($image_url, $alt, $options);
        }
        
        $image_src = wp_get_attachment_image_src($attachment_id, 'full');
        $srcset = wp_get_attachment_image_srcset($attachment_id, 'full');
        
        // Check for WebP version
        $webp_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $image_url);
        $webp_path = str_replace(
            wp_upload_dir()['baseurl'],
            wp_upload_dir()['basedir'],
            $webp_url
        );
        
        $has_webp = file_exists($webp_path);
        
        $style = $opts['aspect_ratio'] ? ' style="aspect-ratio: ' . esc_attr($opts['aspect_ratio']) . ';"' : '';
        
        $html = '<picture' . ($opts['class'] ? ' class="' . esc_attr($opts['class']) . '-wrapper"' : '') . $style . '>';
        
        // WebP source if available
        if ($has_webp) {
            $webp_srcset = preg_replace('/\.(jpe?g|png)/i', '.webp', $srcset);
            $html .= '<source type="image/webp" srcset="' . esc_attr($webp_srcset) . '" sizes="' . esc_attr($opts['sizes']) . '">';
        }
        
        // Original format source
        $html .= '<source type="' . get_post_mime_type($attachment_id) . '" srcset="' . esc_attr($srcset) . '" sizes="' . esc_attr($opts['sizes']) . '">';
        
        // Fallback img
        $html .= '<img src="' . esc_url($image_src[0]) . '" alt="' . esc_attr($alt) . '" loading="' . $opts['loading'] . '" decoding="async"';
        if ($opts['class']) {
            $html .= ' class="' . esc_attr($opts['class']) . '"';
        }
        if ($opts['aspect_ratio']) {
            $html .= ' style="aspect-ratio: ' . esc_attr($opts['aspect_ratio']) . '; object-fit: cover;"';
        }
        $html .= '>';
        
        $html .= '</picture>';
        
        return $html;
    }
    
    /**
     * Shortcode for responsive images
     * Usage: [ptp_img src="url" alt="text" sizes="(max-width: 640px) 100vw, 50vw"]
     */
    public static function shortcode($atts) {
        $atts = shortcode_atts([
            'src' => '',
            'alt' => '',
            'class' => '',
            'sizes' => '(max-width: 640px) 100vw, 50vw',
            'loading' => 'lazy',
            'aspect_ratio' => '',
            'width' => '',
            'height' => ''
        ], $atts);
        
        if (empty($atts['src'])) {
            return '';
        }
        
        return self::responsive_img($atts['src'], $atts['alt'], [
            'class' => $atts['class'],
            'sizes' => $atts['sizes'],
            'loading' => $atts['loading'],
            'aspect_ratio' => $atts['aspect_ratio'] ?: null,
            'width' => $atts['width'] ?: null,
            'height' => $atts['height'] ?: null
        ]);
    }
}

// Initialize
add_action('init', function() {
    PTP_Image_Optimizer::instance();
    add_shortcode('ptp_img', ['PTP_Image_Optimizer', 'shortcode']);
});

/**
 * Helper function for templates
 */
function ptp_img($url, $alt = '', $options = []) {
    return PTP_Image_Optimizer::responsive_img($url, $alt, $options);
}

function ptp_picture($url, $alt = '', $options = []) {
    return PTP_Image_Optimizer::picture($url, $alt, $options);
}
