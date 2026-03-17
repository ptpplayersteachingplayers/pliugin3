<?php
/**
 * PTP Social Media Integration
 * Handles Facebook, Instagram, Twitter sharing and auto-posting
 */

defined('ABSPATH') || exit;

class PTP_Social {
    
    private static $facebook_app_id;
    private static $facebook_app_secret;
    private static $instagram_access_token;
    private static $twitter_api_key;
    private static $twitter_api_secret;
    
    public static function init() {
        self::$facebook_app_id = get_option('ptp_facebook_app_id', '');
        self::$facebook_app_secret = get_option('ptp_facebook_app_secret', '');
        self::$instagram_access_token = get_option('ptp_instagram_access_token', '');
        self::$twitter_api_key = get_option('ptp_twitter_api_key', '');
        self::$twitter_api_secret = get_option('ptp_twitter_api_secret', '');
        
        // Add social meta tags
        add_action('wp_head', array(__CLASS__, 'output_social_meta'), 5);
        
        // Add sharing buttons to trainer profiles
        add_filter('ptp_trainer_profile_actions', array(__CLASS__, 'add_share_buttons'));
        
        // Auto-post new trainers (optional)
        add_action('ptp_trainer_approved', array(__CLASS__, 'auto_post_new_trainer'), 10, 1);
        
        // Shortcodes
        add_shortcode('ptp_share_buttons', array(__CLASS__, 'share_buttons_shortcode'));
        add_shortcode('ptp_social_follow', array(__CLASS__, 'social_follow_shortcode'));
    }
    
    /**
     * Output social meta tags
     */
    public static function output_social_meta() {
        // Facebook App ID
        if (self::$facebook_app_id) {
            echo '<meta property="fb:app_id" content="' . esc_attr(self::$facebook_app_id) . '">' . "\n";
        }
        
        // Twitter site handle
        $twitter_handle = get_option('ptp_twitter_handle', '');
        if ($twitter_handle) {
            echo '<meta name="twitter:site" content="@' . esc_attr($twitter_handle) . '">' . "\n";
        }
    }
    
    /**
     * Share buttons shortcode
     */
    public static function share_buttons_shortcode($atts) {
        $atts = shortcode_atts(array(
            'url' => '',
            'title' => '',
            'image' => '',
            'style' => 'default', // default, minimal, icons, floating
            'networks' => 'facebook,twitter,linkedin,whatsapp,email',
            'show_count' => 'false',
        ), $atts);
        
        $url = $atts['url'] ?: get_permalink();
        $title = $atts['title'] ?: get_the_title();
        $image = $atts['image'] ?: get_option('ptp_default_og_image', '');
        $networks = array_map('trim', explode(',', $atts['networks']));
        
        $share_urls = self::get_share_urls($url, $title, $image);
        
        ob_start();
        ?>
        <div class="ptp-share-buttons ptp-share-buttons--<?php echo esc_attr($atts['style']); ?>">
            <?php if ($atts['style'] === 'floating'): ?>
            <div class="ptp-share-label">Share</div>
            <?php endif; ?>
            
            <?php foreach ($networks as $network): ?>
                <?php if (isset($share_urls[$network])): ?>
                <a href="<?php echo esc_url($share_urls[$network]['url']); ?>" 
                   class="ptp-share-btn ptp-share-btn--<?php echo esc_attr($network); ?>"
                   data-network="<?php echo esc_attr($network); ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="Share on <?php echo esc_attr($share_urls[$network]['label']); ?>">
                    <?php echo self::get_network_icon($network); ?>
                    <?php if ($atts['style'] !== 'icons' && $atts['style'] !== 'floating'): ?>
                    <span class="ptp-share-text"><?php echo esc_html($share_urls[$network]['label']); ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <style>
        .ptp-share-buttons { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .ptp-share-buttons--floating { position: fixed; left: 0; top: 50%; transform: translateY(-50%); flex-direction: column; z-index: 1000; }
        .ptp-share-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 16px; border-radius: 6px; text-decoration: none; font-weight: 500; font-size: 14px; transition: all 0.2s; }
        .ptp-share-buttons--icons .ptp-share-btn,
        .ptp-share-buttons--floating .ptp-share-btn { padding: 12px; border-radius: 50%; }
        .ptp-share-btn svg { width: 20px; height: 20px; fill: currentColor; }
        .ptp-share-btn--facebook { background: #1877F2; color: #fff; }
        .ptp-share-btn--twitter { background: #000; color: #fff; }
        .ptp-share-btn--linkedin { background: #0A66C2; color: #fff; }
        .ptp-share-btn--whatsapp { background: #25D366; color: #fff; }
        .ptp-share-btn--email { background: #666; color: #fff; }
        .ptp-share-btn--instagram { background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888); color: #fff; }
        .ptp-share-btn--copy { background: #333; color: #fff; }
        .ptp-share-btn:hover { opacity: 0.9; transform: scale(1.05); }
        .ptp-share-buttons--minimal .ptp-share-btn { background: transparent; border: 1px solid currentColor; }
        .ptp-share-buttons--minimal .ptp-share-btn--facebook { color: #1877F2; }
        .ptp-share-buttons--minimal .ptp-share-btn--twitter { color: #000; }
        .ptp-share-label { font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; padding: 8px; background: #000; color: #fff; }
        @media (max-width: 768px) {
            .ptp-share-buttons--floating { position: static; transform: none; flex-direction: row; justify-content: center; }
        }
        </style>
        
        <script>
        document.querySelectorAll('.ptp-share-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                if (this.dataset.network === 'copy') {
                    e.preventDefault();
                    navigator.clipboard.writeText('<?php echo esc_js($url); ?>');
                    alert('Link copied!');
                    return;
                }
                if (this.dataset.network !== 'email') {
                    e.preventDefault();
                    window.open(this.href, 'share', 'width=600,height=400,scrollbars=yes');
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get share URLs for all networks
     */
    public static function get_share_urls($url, $title, $image = '') {
        $encoded_url = urlencode($url);
        $encoded_title = urlencode($title);
        
        return array(
            'facebook' => array(
                'url' => "https://www.facebook.com/sharer/sharer.php?u={$encoded_url}",
                'label' => 'Facebook',
            ),
            'twitter' => array(
                'url' => "https://twitter.com/intent/tweet?url={$encoded_url}&text={$encoded_title}",
                'label' => 'Twitter',
            ),
            'linkedin' => array(
                'url' => "https://www.linkedin.com/shareArticle?mini=true&url={$encoded_url}&title={$encoded_title}",
                'label' => 'LinkedIn',
            ),
            'whatsapp' => array(
                'url' => "https://wa.me/?text={$encoded_title}%20{$encoded_url}",
                'label' => 'WhatsApp',
            ),
            'email' => array(
                'url' => "mailto:?subject={$encoded_title}&body=Check%20this%20out:%20{$encoded_url}",
                'label' => 'Email',
            ),
            'pinterest' => array(
                'url' => "https://pinterest.com/pin/create/button/?url={$encoded_url}&description={$encoded_title}&media=" . urlencode($image),
                'label' => 'Pinterest',
            ),
            'telegram' => array(
                'url' => "https://t.me/share/url?url={$encoded_url}&text={$encoded_title}",
                'label' => 'Telegram',
            ),
            'copy' => array(
                'url' => '#',
                'label' => 'Copy Link',
            ),
        );
    }
    
    /**
     * Social follow buttons shortcode
     */
    public static function social_follow_shortcode($atts) {
        $atts = shortcode_atts(array(
            'style' => 'default', // default, minimal, icons
        ), $atts);
        
        $networks = array(
            'facebook' => get_option('ptp_facebook_url', ''),
            'instagram' => get_option('ptp_instagram_url', ''),
            'twitter' => get_option('ptp_twitter_url', ''),
            'youtube' => get_option('ptp_youtube_url', ''),
            'tiktok' => get_option('ptp_tiktok_url', ''),
        );
        
        $networks = array_filter($networks);
        
        if (empty($networks)) {
            return '';
        }
        
        ob_start();
        ?>
        <div class="ptp-social-follow ptp-social-follow--<?php echo esc_attr($atts['style']); ?>">
            <span class="ptp-follow-label">Follow Us</span>
            <div class="ptp-follow-links">
                <?php foreach ($networks as $network => $url): ?>
                <a href="<?php echo esc_url($url); ?>" 
                   class="ptp-follow-btn ptp-follow-btn--<?php echo esc_attr($network); ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   aria-label="Follow on <?php echo esc_attr(ucfirst($network)); ?>">
                    <?php echo self::get_network_icon($network); ?>
                    <?php if ($atts['style'] === 'default'): ?>
                    <span><?php echo esc_html(ucfirst($network)); ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <style>
        .ptp-social-follow { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .ptp-follow-label { font-weight: 600; color: #525252; }
        .ptp-follow-links { display: flex; gap: 8px; }
        .ptp-follow-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #f5f5f5; color: #333; text-decoration: none; border-radius: 6px; font-size: 14px; transition: all 0.2s; }
        .ptp-follow-btn svg { width: 18px; height: 18px; fill: currentColor; }
        .ptp-follow-btn:hover { background: #333; color: #fff; }
        .ptp-social-follow--icons .ptp-follow-btn { padding: 10px; border-radius: 50%; }
        .ptp-social-follow--minimal .ptp-follow-btn { background: transparent; padding: 8px; }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get network icon SVG
     */
    public static function get_network_icon($network) {
        $icons = array(
            'facebook' => '<svg viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            'twitter' => '<svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            'instagram' => '<svg viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>',
            'linkedin' => '<svg viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
            'youtube' => '<svg viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
            'tiktok' => '<svg viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
            'whatsapp' => '<svg viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>',
            'email' => '<svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
            'copy' => '<svg viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>',
            'telegram' => '<svg viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
            'pinterest' => '<svg viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.372 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"/></svg>',
        );
        
        return $icons[$network] ?? '';
    }
    
    /**
     * Add share buttons to trainer profile actions
     */
    public static function add_share_buttons($actions) {
        $actions['share'] = self::share_buttons_shortcode(array(
            'style' => 'icons',
            'networks' => 'facebook,twitter,whatsapp,copy',
        ));
        return $actions;
    }
    
    /**
     * Auto-post new trainer to social media
     */
    public static function auto_post_new_trainer($trainer_id) {
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return;
        
        $auto_post = get_option('ptp_auto_post_trainers', false);
        if (!$auto_post) return;
        
        $message = sprintf(
            "Welcome our newest trainer %s! 🎉⚽\n\n%s\n\nBook a session: %s",
            $trainer->display_name,
            $trainer->headline ?: "Elite training now available!",
            home_url('/trainer/' . $trainer->slug . '/')
        );
        
        // Post to Facebook (if configured)
        self::post_to_facebook($message, $trainer->photo_url);
        
        // Post to Twitter (if configured)  
        self::post_to_twitter($message);
    }
    
    /**
     * Post to Facebook Page
     */
    public static function post_to_facebook($message, $image = '') {
        $page_access_token = get_option('ptp_facebook_page_token', '');
        $page_id = get_option('ptp_facebook_page_id', '');
        
        if (!$page_access_token || !$page_id) return false;
        
        $url = "https://graph.facebook.com/{$page_id}/feed";
        
        $data = array(
            'message' => $message,
            'access_token' => $page_access_token,
        );
        
        if ($image) {
            $data['link'] = $image;
        }
        
        $response = wp_remote_post($url, array(
            'body' => $data,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('PTP Facebook Post Error: ' . $response->get_error_message());
            return false;
        }
        
        return true;
    }
    
    /**
     * Post to Twitter
     */
    public static function post_to_twitter($message) {
        // Twitter API v2 requires OAuth 2.0
        // This is a placeholder for full Twitter API integration
        $bearer_token = get_option('ptp_twitter_bearer_token', '');
        
        if (!$bearer_token) return false;
        
        $url = 'https://api.twitter.com/2/tweets';
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array('text' => substr($message, 0, 280))),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            ptp_log('PTP Twitter Post Error: ' . $response->get_error_message());
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate share URL for specific trainer
     */
    public static function get_trainer_share_url($trainer_id, $network = 'facebook') {
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) return '';
        
        $url = home_url('/trainer/' . $trainer->slug . '/');
        $title = "Train with {$trainer->display_name} - Elite Training";
        
        $urls = self::get_share_urls($url, $title, $trainer->photo_url);
        
        return $urls[$network]['url'] ?? '';
    }
    
    /**
     * Track social shares (analytics)
     */
    public static function track_share($network, $content_type, $content_id) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_social_shares',
            array(
                'network' => $network,
                'content_type' => $content_type,
                'content_id' => $content_id,
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%d', '%d', '%s')
        );
    }
    
    /**
     * Create social shares tracking table
     */
    public static function create_table() {
        global $wpdb;
        
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_social_shares (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            network varchar(50) NOT NULL,
            content_type varchar(50) NOT NULL,
            content_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY network (network),
            KEY content_type_id (content_type, content_id)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
