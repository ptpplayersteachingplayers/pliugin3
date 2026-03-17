<?php
/**
 * PTP All-Access Pass v94 - Complete Membership System
 * 
 * Tier Structure:
 * - 2-Camp Pack: Entry point, try us out
 * - 3-Camp Pack: Popular summer bundle  
 * - All-Access: Full year-round development (the real goal)
 */

defined('ABSPATH') || exit;

class PTP_All_Access_Pass {
    
    private static $instance = null;
    
    // Component values for All-Access value stack
    private $components = [
        'camps' => ['name' => 'Summer Camps', 'qty' => 6, 'rate' => 525, 'icon' => '⚽', 'desc' => 'Full-day camps with D1 college coaches'],
        'private' => ['name' => 'Private Training', 'qty' => 12, 'rate' => 100, 'icon' => '🎯', 'desc' => '1-on-1 sessions'],
        'clinics' => ['name' => 'Skills Clinics', 'qty' => 6, 'rate' => 130, 'icon' => '🔥', 'desc' => 'Group skills year-round'],
        'video' => ['name' => 'Video Analysis', 'qty' => 4, 'rate' => 100, 'icon' => '📹', 'desc' => 'Game film breakdown', 'purchasable' => true],
        'mentorship' => ['name' => 'Mentorship Calls', 'qty' => 4, 'rate' => 100, 'icon' => '💪', 'desc' => 'Career guidance', 'purchasable' => true],
    ];
    
    // 3 TIERS: 2-Camp → 3-Camp → All-Access
    private $tiers = [
        '2camp' => [
            'name' => '2-Camp Pack',
            'price' => 945,
            'value' => 1050,
            'save' => 10,
            'camps' => 2, 'private' => 0, 'clinics' => 0, 'video' => 0, 'mentorship' => 0,
            'desc' => 'Perfect for trying us out',
            'per_camp' => 473,
        ],
        '3camp' => [
            'name' => '3-Camp Pack', 
            'price' => 1260,
            'value' => 1575,
            'save' => 20,
            'badge' => 'POPULAR',
            'camps' => 3, 'private' => 0, 'clinics' => 0, 'video' => 0, 'mentorship' => 0,
            'desc' => 'Best value for summer',
            'per_camp' => 420,
        ],
        'allaccess' => [
            'name' => 'All-Access Pass',
            'price' => 4000,
            'value' => 5930,
            'save' => 33,
            'highlight' => true,
            'badge' => 'BEST VALUE',
            'camps' => 6, 'private' => 12, 'clinics' => 6, 'video' => 4, 'mentorship' => 4,
            'desc' => 'Year-round development',
        ],
    ];
    
    // Payment plans for All-Access only
    private $payments = [
        'full' => ['price' => 3600, 'label' => 'Pay in Full', 'badge' => '10% OFF', 'desc' => 'Save $400'],
        'split' => ['price' => 2050, 'label' => '2 Payments', 'desc' => '$2,050 × 2'],
        'monthly' => ['price' => 350, 'label' => 'Monthly', 'desc' => '$350/mo × 12'],
    ];
    
    private $table_memberships;
    private $table_credits;
    private $table_purchases;
    
    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }
    
    public function __construct() {
        global $wpdb;
        $this->table_memberships = $wpdb->prefix . 'ptp_memberships';
        $this->table_credits = $wpdb->prefix . 'ptp_membership_credits';
        $this->table_purchases = $wpdb->prefix . 'ptp_service_purchases';
        
        add_action('init', [$this, 'init']);
        
        // Shortcodes
        add_shortcode('ptp_all_access', [$this, 'render_all_access_page']);
        add_shortcode('ptp_membership_tiers', [$this, 'render_tiers_page']);
        add_shortcode('ptp_video_analysis', [$this, 'render_video_page']);
        add_shortcode('ptp_mentorship', [$this, 'render_mentorship_page']);
        add_shortcode('ptp_member_dashboard', [$this, 'render_dashboard']);
        
        // AJAX
        add_action('wp_ajax_ptp_membership_checkout', [$this, 'ajax_checkout']);
        add_action('wp_ajax_nopriv_ptp_membership_checkout', [$this, 'ajax_checkout']);
        add_action('wp_ajax_ptp_service_purchase', [$this, 'ajax_service']);
        add_action('wp_ajax_nopriv_ptp_service_purchase', [$this, 'ajax_service']);
        
        // Assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Stripe webhook
        add_action('ptp_stripe_webhook_checkout.session.completed', [$this, 'handle_webhook']);
    }
    
    public function init() { $this->create_tables(); }
    
    private function create_tables() {
        if (get_option('ptp_aa_db') === '1.0') return;
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta("CREATE TABLE IF NOT EXISTS {$this->table_memberships} (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id bigint(20) UNSIGNED NOT NULL,
            tier varchar(50) DEFAULT 'allaccess',
            status enum('active','expired','cancelled','pending') DEFAULT 'pending',
            price_paid decimal(10,2),
            payment_plan varchar(20) DEFAULT 'full',
            stripe_customer_id varchar(255),
            stripe_subscription_id varchar(255),
            starts_at date, expires_at date,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id), KEY status (status)
        ) $c;");
        
        dbDelta("CREATE TABLE IF NOT EXISTS {$this->table_credits} (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            membership_id bigint(20) UNSIGNED,
            user_id bigint(20) UNSIGNED NOT NULL,
            credit_type varchar(50),
            credits_total int DEFAULT 0,
            credits_used int DEFAULT 0,
            credits_remaining int DEFAULT 0,
            KEY user_id (user_id)
        ) $c;");
        
        dbDelta("CREATE TABLE IF NOT EXISTS {$this->table_purchases} (
            id bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id bigint(20) UNSIGNED NOT NULL,
            service_type varchar(50),
            quantity int DEFAULT 1,
            amount decimal(10,2),
            stripe_session_id varchar(255),
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP
        ) $c;");
        
        update_option('ptp_aa_db', '1.0');
    }
    
    public function enqueue_assets() {
        global $post;
        if (!$post) return;
        
        $shortcodes = ['ptp_all_access', 'ptp_membership_tiers', 'ptp_video_analysis', 'ptp_mentorship', 'ptp_member_dashboard'];
        $has = false;
        foreach ($shortcodes as $sc) {
            if (has_shortcode($post->post_content, $sc)) { $has = true; break; }
        }
        if (!$has) return;
        
        wp_enqueue_style('ptp-aa-css', PTP_PLUGIN_URL . 'assets/css/ptp-all-access.css', [], PTP_VERSION);
        wp_enqueue_script('ptp-aa-js', PTP_PLUGIN_URL . 'assets/js/ptp-all-access.js', ['jquery'], PTP_VERSION, true);
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
        
        wp_localize_script('ptp-aa-js', 'ptpAA', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_aa'),
            'stripe_pk' => get_option('ptp_stripe_publishable_key', ''),
            'tiers' => $this->tiers,
            'payments' => $this->payments,
        ]);
    }
    
    // Getters
    public function get_tiers() { return $this->tiers; }
    public function get_components() { return $this->components; }
    public function get_payments() { return $this->payments; }
    
    // ================================================================
    // TIERS PAGE - The main comparison page
    // ================================================================
    
    public function render_tiers_page() {
        $tiers = $this->tiers;
        $c = $this->components;
        ob_start();
        ?>
        <div class="ptp-aa-page">
            <!-- Hero -->
            <section class="ptp-aa-hero">
                <h1>CHOOSE YOUR PASS</h1>
                <p>From summer camps to year-round development</p>
            </section>
            
            <!-- Tier Cards -->
            <section class="ptp-aa-tiers">
                <div class="ptp-aa-container">
                    <div class="ptp-tier-grid">
                        <?php foreach ($tiers as $key => $tier): 
                            $isAllAccess = ($key === 'allaccess');
                            $isHighlight = !empty($tier['highlight']);
                        ?>
                        <div class="ptp-tier-card<?php echo $isHighlight ? ' ptp-tier-hl' : ''; ?>">
                            <?php if (!empty($tier['badge'])): ?>
                                <span class="ptp-tier-badge"><?php echo esc_html($tier['badge']); ?></span>
                            <?php endif; ?>
                            
                            <h3 class="ptp-tier-name"><?php echo esc_html($tier['name']); ?></h3>
                            <p class="ptp-tier-desc"><?php echo esc_html($tier['desc']); ?></p>
                            
                            <div class="ptp-tier-pricing">
                                <span class="ptp-tier-value">$<?php echo number_format($tier['value']); ?> value</span>
                                <span class="ptp-tier-price">$<?php echo number_format($tier['price']); ?></span>
                                <span class="ptp-tier-save">SAVE <?php echo $tier['save']; ?>%</span>
                            </div>
                            
                            <?php if ($isAllAccess): ?>
                                <!-- All-Access shows full component list -->
                                <ul class="ptp-tier-list">
                                    <?php foreach ($c as $ck => $comp): 
                                        $qty = $tier[$ck] ?? 0;
                                        if ($qty > 0):
                                    ?>
                                    <li>
                                        <span class="ptp-tier-icon"><?php echo $comp['icon']; ?></span>
                                        <span><strong><?php echo $qty; ?></strong> <?php echo $comp['name']; ?></span>
                                    </li>
                                    <?php endif; endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <!-- Camp packs show simple camp count -->
                                <div class="ptp-tier-camps">
                                    <span class="ptp-camp-icon">⚽</span>
                                    <span class="ptp-camp-count"><?php echo $tier['camps']; ?></span>
                                    <span class="ptp-camp-label">Summer Camps</span>
                                    <span class="ptp-camp-rate">$<?php echo $tier['per_camp']; ?>/camp</span>
                                </div>
                            <?php endif; ?>
                            
                            <button class="ptp-btn <?php echo $isHighlight ? 'ptp-btn-gold' : 'ptp-btn-black'; ?> ptp-checkout-btn" 
                                    data-tier="<?php echo esc_attr($key); ?>">
                                <?php echo $isAllAccess ? 'GET ALL-ACCESS' : 'SELECT'; ?>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Upsell note -->
                    <div class="ptp-tier-note">
                        <p>💡 <strong>Want more than camps?</strong> All-Access includes private training, clinics, video analysis & mentorship calls</p>
                    </div>
                </div>
            </section>
        </div>
        
        <?php echo $this->render_checkout_modal(); ?>
        <?php
        return ob_get_clean();
    }
    
    // ================================================================
    // ALL-ACCESS PAGE - Detailed value stack for All-Access
    // ================================================================
    
    public function render_all_access_page() {
        $t = $this->tiers['allaccess'];
        $c = $this->components;
        $p = $this->payments;
        $value = $t['value'];
        $price = $t['price'];
        
        ob_start();
        ?>
        <div class="ptp-aa-page">
            <!-- Hero with price anchor -->
            <section class="ptp-aa-hero ptp-aa-hero-full">
                <span class="ptp-aa-label">LIMITED SPOTS • 2025 SEASON</span>
                <h1>ALL-ACCESS PASS</h1>
                <p class="ptp-aa-tagline">Year-round development with pros. Everything you need. One price.</p>
                
                <div class="ptp-aa-anchor">
                    <div class="ptp-anchor-item">
                        <span class="ptp-anchor-label">TOTAL VALUE</span>
                        <span class="ptp-anchor-strike">$<?php echo number_format($value); ?></span>
                    </div>
                    <div class="ptp-anchor-item ptp-anchor-main">
                        <span class="ptp-anchor-label">YOUR PRICE</span>
                        <span class="ptp-anchor-price">$<?php echo number_format($price); ?></span>
                    </div>
                    <div class="ptp-anchor-item">
                        <span class="ptp-anchor-badge">SAVE $<?php echo number_format($value - $price); ?></span>
                        <span class="ptp-anchor-pct"><?php echo $t['save']; ?>% OFF</span>
                    </div>
                </div>
                
                <a href="#payment" class="ptp-btn ptp-btn-gold ptp-btn-lg">GET YOUR PASS</a>
                <p class="ptp-aa-weekly">Just <strong>$<?php echo round($price / 52); ?>/week</strong> for elite development</p>
            </section>
            
            <!-- Value Stack -->
            <section class="ptp-aa-stack">
                <div class="ptp-aa-container">
                    <h2>WHAT'S INCLUDED</h2>
                    <p class="ptp-aa-sub">Everything you need for year-round development</p>
                    
                    <div class="ptp-stack-list">
                        <?php foreach ($c as $key => $comp): 
                            $qty = $t[$key] ?? 0;
                            if ($qty > 0):
                        ?>
                        <div class="ptp-stack-item">
                            <span class="ptp-stack-icon"><?php echo $comp['icon']; ?></span>
                            <div class="ptp-stack-info">
                                <span class="ptp-stack-name"><?php echo $comp['name']; ?></span>
                                <span class="ptp-stack-qty"><?php echo $qty; ?> <?php echo $qty > 1 ? 'sessions' : 'session'; ?> included</span>
                            </div>
                            <div class="ptp-stack-value">
                                <span class="ptp-stack-rate">$<?php echo $comp['rate']; ?>/session</span>
                                <span class="ptp-stack-total">$<?php echo number_format($comp['rate'] * $qty); ?></span>
                            </div>
                        </div>
                        <?php endif; endforeach; ?>
                    </div>
                    
                    <div class="ptp-stack-total">
                        <span>TOTAL VALUE</span>
                        <span>$<?php echo number_format($value); ?></span>
                    </div>
                </div>
            </section>
            
            <!-- Payment Options -->
            <section class="ptp-aa-payment" id="payment">
                <div class="ptp-aa-container">
                    <h2>CHOOSE YOUR PLAN</h2>
                    <p class="ptp-aa-sub">Flexible payment options to fit your budget</p>
                    
                    <div class="ptp-payment-grid">
                        <?php foreach ($p as $key => $plan): ?>
                        <div class="ptp-payment-card<?php echo $key === 'full' ? ' ptp-payment-hl' : ''; ?>" data-plan="<?php echo $key; ?>">
                            <?php if (!empty($plan['badge'])): ?>
                                <span class="ptp-payment-badge"><?php echo $plan['badge']; ?></span>
                            <?php endif; ?>
                            <h3><?php echo $plan['label']; ?></h3>
                            <div class="ptp-payment-price">
                                $<?php echo number_format($plan['price']); ?>
                                <?php if ($key !== 'full'): ?><span>/<?php echo $key === 'monthly' ? 'mo' : 'payment'; ?></span><?php endif; ?>
                            </div>
                            <p class="ptp-payment-desc"><?php echo $plan['desc']; ?></p>
                            <button class="ptp-btn ptp-btn-black ptp-checkout-btn" data-tier="allaccess" data-plan="<?php echo $key; ?>">
                                SELECT
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <p class="ptp-aa-compare"><a href="/membership-tiers/">← Compare all membership options</a></p>
                </div>
            </section>
            
            <!-- FAQ -->
            <section class="ptp-aa-faq">
                <div class="ptp-aa-container">
                    <h2>QUESTIONS?</h2>
                    <?php echo $this->render_faq(); ?>
                </div>
            </section>
            
            <!-- Final CTA -->
            <section class="ptp-aa-cta">
                <h2>READY TO TRANSFORM YOUR GAME?</h2>
                <p>Join 500+ families already training with pros</p>
                <a href="#payment" class="ptp-btn ptp-btn-gold ptp-btn-lg">GET YOUR ALL-ACCESS PASS</a>
            </section>
        </div>
        
        <?php echo $this->render_checkout_modal(); ?>
        <?php
        return ob_get_clean();
    }
    
    // ================================================================
    // INDIVIDUAL SERVICE PAGES
    // ================================================================
    
    public function render_video_page() { return $this->render_service_page('video'); }
    public function render_mentorship_page() { 
        // v216.3: Redirect to new mentorship system
        if (class_exists('PTP_Mentorship')) {
            return PTP_Mentorship::render_mentorship_hub();
        }
        return $this->render_service_page('mentorship'); 
    }
    
    private function render_service_page($key) {
        $s = $this->components[$key];
        $aa = $this->tiers['allaccess'];
        ob_start();
        ?>
        <div class="ptp-aa-page">
            <section class="ptp-svc-hero">
                <span class="ptp-svc-icon"><?php echo $s['icon']; ?></span>
                <h1><?php echo strtoupper($s['name']); ?></h1>
                <p><?php echo $s['desc']; ?></p>
            </section>
            
            <section class="ptp-svc-main">
                <div class="ptp-aa-container">
                    <div class="ptp-svc-box">
                        <div class="ptp-svc-price">
                            <span>$<?php echo $s['rate']; ?></span>/hour
                        </div>
                        <p>Expert-led session with actionable feedback</p>
                        
                        <div class="ptp-svc-qty">
                            <label>HOURS</label>
                            <div class="ptp-qty-wrap">
                                <button class="ptp-qty-btn" data-dir="-">−</button>
                                <input type="number" id="svc-qty" value="1" min="1" max="10">
                                <button class="ptp-qty-btn" data-dir="+">+</button>
                            </div>
                        </div>
                        
                        <div class="ptp-svc-total">Total: <span id="svc-total">$<?php echo $s['rate']; ?></span></div>
                        
                        <button class="ptp-btn ptp-btn-gold ptp-btn-full ptp-service-btn" 
                                data-service="<?php echo $key; ?>" 
                                data-rate="<?php echo $s['rate']; ?>">
                            BOOK NOW
                        </button>
                        
                        <div class="ptp-svc-upsell">
                            <p>💡 <strong>Get <?php echo $aa[$key]; ?> hours FREE</strong> with All-Access Pass</p>
                            <a href="/all-access-pass/">Learn more →</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        
        <?php echo $this->render_checkout_modal(); ?>
        <?php
        return ob_get_clean();
    }
    
    // ================================================================
    // MEMBER DASHBOARD
    // ================================================================
    
    public function render_dashboard() {
        if (!is_user_logged_in()) {
            return '<div class="ptp-login-msg"><p>Please <a href="/login/">log in</a> to view your membership.</p></div>';
        }
        
        $uid = get_current_user_id();
        $m = $this->get_membership($uid);
        $credits = $this->get_credits($uid);
        $c = $this->components;
        
        if (!$m) {
            ob_start();
            ?>
            <div class="ptp-no-membership">
                <h2>No Active Membership</h2>
                <p>Get started with a camp pack or go All-Access!</p>
                <a href="/membership-tiers/" class="ptp-btn ptp-btn-gold">VIEW OPTIONS</a>
            </div>
            <?php
            return ob_get_clean();
        }
        
        $tier = $this->tiers[$m->tier] ?? $this->tiers['allaccess'];
        
        ob_start();
        ?>
        <div class="ptp-aa-page">
            <section class="ptp-dash-header">
                <span class="ptp-dash-badge">ACTIVE MEMBER</span>
                <h1><?php echo esc_html($tier['name']); ?></h1>
                <p>Expires: <?php echo date('F j, Y', strtotime($m->expires_at)); ?></p>
            </section>
            
            <section class="ptp-dash-main">
                <div class="ptp-aa-container">
                    <h2>YOUR CREDITS</h2>
                    <div class="ptp-dash-grid">
                        <?php foreach ($c as $ck => $comp):
                            $cr = $credits[$ck] ?? null;
                            $total = $cr ? $cr->credits_total : 0;
                            $remain = $cr ? $cr->credits_remaining : 0;
                            if ($total == 0) continue;
                            $pct = ($remain / $total) * 100;
                        ?>
                        <div class="ptp-dash-card">
                            <div class="ptp-dash-card-head">
                                <span><?php echo $comp['icon']; ?></span>
                                <span><?php echo $comp['name']; ?></span>
                            </div>
                            <div class="ptp-dash-card-count">
                                <span class="ptp-dash-num"><?php echo $remain; ?></span>
                                <span class="ptp-dash-of">of <?php echo $total; ?> remaining</span>
                            </div>
                            <div class="ptp-dash-bar"><div class="ptp-dash-fill" style="width:<?php echo $pct; ?>%"></div></div>
                            <a href="/book-<?php echo $ck; ?>/" class="ptp-dash-action">BOOK NOW</a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // ================================================================
    // SHARED COMPONENTS
    // ================================================================
    
    private function render_faq() {
        $faqs = [
            ['q' => 'What ages is this for?', 'a' => 'Ages 6-18. Our coaches adapt training to each player\'s level.'],
            ['q' => 'When do camps run?', 'a' => 'Summer camps run June through August across PA, NJ, DE, MD, and NY.'],
            ['q' => 'Who are the coaches?', 'a' => 'Current NCAA D1 athletes and elite college players who don\'t just instruct—they PLAY with your kids.'],
            ['q' => 'Can I upgrade later?', 'a' => 'Yes! Buy a camp pack now, upgrade to All-Access anytime—we\'ll credit your purchase.'],
            ['q' => 'What if I need to cancel?', 'a' => 'Full refund within 30 days. After that, unused credits roll over.'],
        ];
        ob_start();
        ?>
        <div class="ptp-faq-list">
            <?php foreach ($faqs as $f): ?>
            <div class="ptp-faq-item">
                <button class="ptp-faq-q"><?php echo $f['q']; ?><span class="ptp-faq-toggle">+</span></button>
                <div class="ptp-faq-a"><p><?php echo $f['a']; ?></p></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private function render_checkout_modal() {
        ob_start();
        ?>
        <div class="ptp-modal" id="ptp-modal" style="display:none;">
            <div class="ptp-modal-overlay"></div>
            <div class="ptp-modal-box">
                <button class="ptp-modal-close">&times;</button>
                <div class="ptp-modal-content">
                    <h2>Complete Your Purchase</h2>
                    <div id="ptp-modal-form">
                        <div class="ptp-form-row">
                            <label>EMAIL</label>
                            <input type="email" id="ptp-email" placeholder="your@email.com" required>
                        </div>
                        <div class="ptp-checkout-summary">
                            <span id="ptp-item-name">All-Access Pass</span>
                            <span id="ptp-item-price">$4,000</span>
                        </div>
                        <button type="button" id="ptp-submit" class="ptp-btn ptp-btn-gold ptp-btn-full">
                            PROCEED TO PAYMENT
                        </button>
                        <p class="ptp-secure">🔒 Secure checkout powered by Stripe</p>
                    </div>
                    <div id="ptp-modal-loading" style="display:none;">
                        <div class="ptp-spinner"></div>
                        <p>Preparing checkout...</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    // ================================================================
    // DATABASE METHODS
    // ================================================================
    
    public function get_membership($uid) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_memberships} WHERE user_id=%d AND status='active' AND expires_at>=CURDATE() LIMIT 1",
            $uid
        ));
    }
    
    public function get_credits($uid) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_credits} WHERE user_id=%d", $uid));
        $out = [];
        foreach ($rows as $r) $out[$r->credit_type] = $r;
        return $out;
    }
    
    public function create_membership($uid, $tier_key, $data) {
        global $wpdb;
        $tier = $this->tiers[$tier_key] ?? $this->tiers['allaccess'];
        
        // Determine expiry (1 year for allaccess, end of summer for camp packs)
        $expires = ($tier_key === 'allaccess') 
            ? date('Y-m-d', strtotime('+1 year'))
            : date('Y-08-31'); // End of summer
        
        $wpdb->insert($this->table_memberships, [
            'user_id' => $uid,
            'tier' => $tier_key,
            'status' => 'active',
            'price_paid' => $data['amount'],
            'payment_plan' => $data['plan'] ?? 'full',
            'stripe_customer_id' => $data['customer_id'] ?? null,
            'stripe_subscription_id' => $data['subscription_id'] ?? null,
            'starts_at' => current_time('Y-m-d'),
            'expires_at' => $expires,
        ]);
        $mid = $wpdb->insert_id;
        
        // Create credits for each component
        foreach ($this->components as $ck => $comp) {
            $qty = $tier[$ck] ?? 0;
            if ($qty > 0) {
                $wpdb->insert($this->table_credits, [
                    'membership_id' => $mid,
                    'user_id' => $uid,
                    'credit_type' => $ck,
                    'credits_total' => $qty,
                    'credits_used' => 0,
                    'credits_remaining' => $qty,
                ]);
            }
        }
        
        $this->send_welcome_email($uid, $tier_key);
        return $mid;
    }
    
    private function send_welcome_email($uid, $tier_key) {
        $user = get_user_by('ID', $uid);
        if (!$user) return;
        
        $tier = $this->tiers[$tier_key];
        $subject = "🎉 Welcome to PTP {$tier['name']}!";
        $msg = "You're in!\n\nYour {$tier['name']} is now active.\n\n";
        
        if ($tier_key === 'allaccess') {
            $msg .= "View your dashboard: " . home_url('/my-membership/') . "\n\n";
        } else {
            $msg .= "You have {$tier['camps']} camp credits ready to use.\n\n";
            $msg .= "Book your camps: " . home_url('/ptp-find-a-camp/') . "\n\n";
        }
        
        $msg .= "Let's get to work!\n— The PTP Team";
        wp_mail($user->user_email, $subject, $msg);
    }
    
    // ================================================================
    // AJAX HANDLERS
    // ================================================================
    
    public function ajax_checkout() {
        check_ajax_referer('ptp_aa', 'nonce');
        
        $tier_key = sanitize_text_field($_POST['tier'] ?? 'allaccess');
        $plan = sanitize_text_field($_POST['plan'] ?? 'full');
        $email = sanitize_email($_POST['email'] ?? '');
        
        if (!isset($this->tiers[$tier_key])) {
            wp_send_json_error(['message' => 'Invalid tier']);
        }
        
        $tier = $this->tiers[$tier_key];
        
        // Determine price
        if ($tier_key === 'allaccess' && isset($this->payments[$plan])) {
            $amount = $this->payments[$plan]['price'];
        } else {
            $amount = $tier['price'];
            $plan = 'full'; // Camp packs are always full payment
        }
        
        $sk = get_option('ptp_stripe_secret_key');
        if (!$sk) {
            wp_send_json_error(['message' => 'Payment not configured']);
        }
        
        \Stripe\Stripe::setApiKey($sk);
        
        try {
            $mode = ($tier_key === 'allaccess' && $plan === 'monthly') ? 'subscription' : 'payment';
            
            $line_item = [
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => intval($amount * 100),
                    'product_data' => [
                        'name' => 'PTP ' . $tier['name'],
                        'description' => $plan === 'split' ? 'Payment 1 of 2' : null,
                    ],
                ],
                'quantity' => 1,
            ];
            
            if ($mode === 'subscription') {
                $line_item['price_data']['recurring'] = ['interval' => 'month'];
            }
            
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'mode' => $mode,
                'success_url' => home_url('/membership-thank-you/?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => home_url('/membership-tiers/'),
                'customer_email' => $email ?: null,
                'line_items' => [$line_item],
                'metadata' => [
                    'type' => 'membership',
                    'tier' => $tier_key,
                    'plan' => $plan,
                ],
            ]);
            
            wp_send_json_success(['url' => $session->url]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    public function ajax_service() {
        check_ajax_referer('ptp_aa', 'nonce');
        
        $service = sanitize_text_field($_POST['service'] ?? '');
        $qty = max(1, min(10, intval($_POST['quantity'] ?? 1)));
        $email = sanitize_email($_POST['email'] ?? '');
        
        if (!isset($this->components[$service]) || empty($this->components[$service]['purchasable'])) {
            wp_send_json_error(['message' => 'Invalid service']);
        }
        
        $comp = $this->components[$service];
        $amount = $comp['rate'] * $qty;
        
        $sk = get_option('ptp_stripe_secret_key');
        if (!$sk) {
            wp_send_json_error(['message' => 'Payment not configured']);
        }
        
        \Stripe\Stripe::setApiKey($sk);
        
        try {
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'success_url' => home_url('/booking-confirmed/?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => home_url('/' . $service . '/'),
                'customer_email' => $email ?: null,
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => intval($comp['rate'] * 100),
                        'product_data' => ['name' => 'PTP ' . $comp['name']],
                    ],
                    'quantity' => $qty,
                ]],
                'metadata' => [
                    'type' => 'service',
                    'service' => $service,
                    'quantity' => $qty,
                ],
            ]);
            
            wp_send_json_success(['url' => $session->url]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    // ================================================================
    // STRIPE WEBHOOK
    // ================================================================
    
    public function handle_webhook($session) {
        $meta = $session->metadata ?? new stdClass();
        $type = $meta->type ?? '';
        
        // Get or create user
        $email = $session->customer_details->email ?? '';
        $user = get_user_by('email', $email);
        $uid = $user ? $user->ID : wp_create_user($email, wp_generate_password(), $email);
        
        if ($type === 'membership') {
            $this->create_membership($uid, $meta->tier ?? 'allaccess', [
                'amount' => $session->amount_total / 100,
                'plan' => $meta->plan ?? 'full',
                'customer_id' => $session->customer,
                'subscription_id' => $session->subscription,
            ]);
        } elseif ($type === 'service') {
            global $wpdb;
            $wpdb->insert($this->table_purchases, [
                'user_id' => $uid,
                'service_type' => $meta->service,
                'quantity' => $meta->quantity ?? 1,
                'amount' => $session->amount_total / 100,
                'stripe_session_id' => $session->id,
                'status' => 'completed',
            ]);
            
            // Send confirmation email
            $comp = $this->components[$meta->service] ?? null;
            if ($comp) {
                $user = get_user_by('ID', $uid);
                wp_mail($user->user_email, "✅ Your {$comp['name']} is Booked!", 
                    "Thanks for booking!\n\nWe'll reach out within 24 hours to schedule your session.\n\n— The PTP Team");
            }
        }
    }
}

// Initialize
PTP_All_Access_Pass::instance();
