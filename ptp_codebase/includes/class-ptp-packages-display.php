<?php
/**
 * PTP Packages & Referral Display v1.0.0
 * 
 * Improved UX for package selection and referral code display
 * Matches the PTP design system
 * 
 * @since 58.0.0
 */

defined('ABSPATH') || exit;

/**
 * Render improved package selection component
 * 
 * @param int $hourly_rate Base hourly rate
 * @param string $trainer_slug Trainer's slug for booking URL
 * @param array $options Additional options
 */
function ptp_render_package_selector($hourly_rate, $trainer_slug = '', $options = array()) {
    $rate = (int) $hourly_rate;
    
    if (class_exists('PTP_Packages')) {
        $packages = array();
        $badges = array('pack3' => null, 'pack5' => 'Most Popular', 'pack10' => 'Best Value');
        $highlights = array('pack5' => true);
        foreach (array('pack3','pack5','pack10') as $pk) {
            $pricing = PTP_Packages::calculate_price($rate, $pk);
            $packages[] = array(
                'id' => $pk,
                'name' => $pricing['sessions'] . ' Sessions',
                'sessions' => $pricing['sessions'],
                'discount' => $pricing['discount_pct'],
                'per_session' => round($pricing['per_session']),
                'total' => round($pricing['total']),
                'savings' => round($pricing['save']),
                'badge' => $badges[$pk] ?? null,
                'highlight' => !empty($highlights[$pk]),
            );
        }
    } else {
        $packages = array(
            array(
                'id' => '3pack',
                'name' => '3 Sessions',
                'sessions' => 3,
                'discount' => 10,
                'per_session' => round($rate * 0.90),
                'total' => round($rate * 0.90 * 3),
                'savings' => round($rate * 3 - ($rate * 0.90 * 3)),
                'badge' => null,
            ),
            array(
                'id' => '5pack',
                'name' => '5 Sessions',
                'sessions' => 5,
                'discount' => 15,
                'per_session' => round($rate * 0.85),
                'total' => round($rate * 0.85 * 5),
                'savings' => round($rate * 5 - ($rate * 0.85 * 5)),
                'badge' => 'Most Popular',
                'highlight' => true,
            ),
            array(
                'id' => '10pack',
                'name' => '10 Sessions',
                'sessions' => 10,
                'discount' => 20,
                'per_session' => round($rate * 0.80),
                'total' => round($rate * 0.80 * 10),
                'savings' => round($rate * 10 - ($rate * 0.80 * 10)),
                'badge' => 'Best Value',
            ),
        );
    }
    ?>
    
    <div class="ptp-pkg-section">
        <div class="ptp-pkg-header">
            <span class="ptp-pkg-icon">💰</span>
            <span class="ptp-pkg-title">SAVE WITH PACKAGES</span>
        </div>
        
        <div class="ptp-pkg-list">
            <?php foreach ($packages as $pkg): ?>
            <div class="ptp-pkg-row<?php echo !empty($pkg['highlight']) ? ' ptp-pkg-highlight' : ''; ?>" 
                 data-package="<?php echo esc_attr($pkg['id']); ?>"
                 data-sessions="<?php echo $pkg['sessions']; ?>"
                 data-price="<?php echo $pkg['total']; ?>"
                 onclick="selectPackageOption(this)">
                 
                <?php if (!empty($pkg['badge'])): ?>
                <div class="ptp-pkg-badge"><?php echo $pkg['badge'] === 'Most Popular' ? '★ ' : ''; ?><?php echo esc_html(strtoupper($pkg['badge'])); ?></div>
                <?php endif; ?>
                
                <div class="ptp-pkg-info">
                    <div class="ptp-pkg-name"><?php echo esc_html($pkg['name']); ?></div>
                    <div class="ptp-pkg-meta">$<?php echo $pkg['per_session']; ?>/session • Save $<?php echo $pkg['savings']; ?></div>
                </div>
                
                <div class="ptp-pkg-price">$<?php echo number_format($pkg['total']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <style>
    /* Package Selection Component */
    .ptp-pkg-section {
        background: #fff;
        border-radius: 16px;
        overflow: hidden;
        border: 2px solid #E5E7EB;
        margin: 24px 0;
    }
    
    .ptp-pkg-header {
        background: #0A0A0A;
        color: #FCB900;
        padding: 14px 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-family: 'Oswald', sans-serif;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.05em;
    }
    
    .ptp-pkg-icon {
        font-size: 18px;
    }
    
    .ptp-pkg-list {
        padding: 0;
    }
    
    .ptp-pkg-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid #F3F4F6;
        cursor: pointer;
        transition: all 0.15s ease;
        position: relative;
    }
    
    .ptp-pkg-row:last-child {
        border-bottom: none;
    }
    
    .ptp-pkg-row:hover {
        background: #FFFBEB;
    }
    
    .ptp-pkg-row.selected {
        background: #FEF3C7;
        border-left: 4px solid #FCB900;
    }
    
    .ptp-pkg-highlight {
        background: #FCB900;
    }
    
    .ptp-pkg-highlight:hover {
        background: #e5a800;
    }
    
    .ptp-pkg-highlight.selected {
        background: #FCB900;
    }
    
    .ptp-pkg-badge {
        position: absolute;
        top: 0;
        right: 20px;
        background: #0A0A0A;
        color: #FCB900;
        padding: 4px 12px;
        font-family: 'Oswald', sans-serif;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.05em;
        border-radius: 0 0 6px 6px;
    }
    
    .ptp-pkg-highlight .ptp-pkg-badge {
        background: #0A0A0A;
        color: #FCB900;
    }
    
    .ptp-pkg-info {
        flex: 1;
        min-width: 0;
    }
    
    .ptp-pkg-name {
        font-family: 'Oswald', sans-serif;
        font-size: 15px;
        font-weight: 700;
        text-transform: uppercase;
        color: #0A0A0A;
        margin-bottom: 2px;
    }
    
    .ptp-pkg-highlight .ptp-pkg-name {
        color: #0A0A0A;
    }
    
    .ptp-pkg-meta {
        font-size: 13px;
        color: #6B7280;
    }
    
    .ptp-pkg-highlight .ptp-pkg-meta {
        color: rgba(10, 10, 10, 0.7);
    }
    
    .ptp-pkg-price {
        font-family: 'Oswald', sans-serif;
        font-size: 20px;
        font-weight: 700;
        color: #0A0A0A;
    }
    
    @media (max-width: 480px) {
        .ptp-pkg-row {
            padding: 14px 16px;
        }
        .ptp-pkg-name {
            font-size: 14px;
        }
        .ptp-pkg-meta {
            font-size: 12px;
        }
        .ptp-pkg-price {
            font-size: 18px;
        }
    }
    </style>
    
    <script>
    function selectPackageOption(el) {
        // Remove selected from all
        document.querySelectorAll('.ptp-pkg-row').forEach(function(row) {
            row.classList.remove('selected');
        });
        
        // Select this one
        el.classList.add('selected');
        
        // Get package data
        var packageId = el.dataset.package;
        var sessions = parseInt(el.dataset.sessions);
        var price = parseInt(el.dataset.price);
        
        // Fire event for integration with booking system
        var event = new CustomEvent('ptp:packageSelected', {
            detail: {
                packageId: packageId,
                sessions: sessions,
                price: price
            }
        });
        document.dispatchEvent(event);
        
        // If there's a booking form, update it
        if (typeof updateBookingPackage === 'function') {
            updateBookingPackage(packageId, sessions, price);
        }
    }
    </script>
    <?php
}

/**
 * Render improved referral banner
 * 
 * @param int $user_id User ID (optional, defaults to current user)
 * @param array $options Display options
 */
function ptp_render_referral_banner_v2($user_id = null, $options = array()) {
    if (!is_user_logged_in()) return;
    
    $user_id = $user_id ?: get_current_user_id();
    
    // Get or generate referral code
    $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
    if (empty($referral_code)) {
        $user = get_userdata($user_id);
        $name_part = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $user->display_name), 0, 4));
        $referral_code = $name_part . rand(1000, 9999);
        update_user_meta($user_id, 'ptp_referral_code', $referral_code);
    }
    
    $share_url = home_url('/find-trainers/?ref=' . $referral_code);
    
    $defaults = array(
        'style' => 'dark', // 'dark' or 'light'
        'compact' => false,
        'trainer_name' => '', // optional trainer name for context
    );
    $opts = array_merge($defaults, $options);
    
    // Build share message
    $share_message = "Train with pro & college athletes near you! Use my code {$referral_code} for \$20 off: ";
    if (!empty($opts['trainer_name'])) {
        $share_message = "Check out this trainer ({$opts['trainer_name']}) on PTP! Use code {$referral_code} for \$20 off: ";
    }
    $share_text = rawurlencode($share_message);
    ?>
    
    <div class="ptp-ref-banner <?php echo $opts['style'] === 'dark' ? 'ptp-ref-dark' : 'ptp-ref-light'; ?> <?php echo $opts['compact'] ? 'ptp-ref-compact' : ''; ?>" data-referral-code="<?php echo esc_attr($referral_code); ?>">
        <div class="ptp-ref-content">
            <div class="ptp-ref-header">
                <span class="ptp-ref-icon">🎁</span>
                <span class="ptp-ref-title">GIVE $20, GET $25</span>
            </div>
            <p class="ptp-ref-text"><?php 
                if (!empty($opts['trainer_name'])) {
                    echo 'Share ' . esc_html($opts['trainer_name']) . ' with friends! They get $20 off, you get $25 credit.';
                } else {
                    echo 'Share your referral code with friends. They get $20 off, you get $25 credit!';
                }
            ?></p>
            
            <div class="ptp-ref-code-box">
                <code class="ptp-ref-code" id="ptp-referral-code"><?php echo esc_html($referral_code); ?></code>
                <button class="ptp-ref-copy" data-copy-referral="<?php echo esc_attr($referral_code); ?>" title="Copy code">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                    </svg>
                    <span class="copy-text">Copy</span>
                </button>
            </div>
            
            <div class="ptp-ref-share">
                <a href="sms:?body=<?php echo $share_text . rawurlencode($share_url); ?>" class="ptp-ref-share-btn ptp-ref-sms" data-share="sms">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                    </svg>
                    Text
                </a>
                <a href="https://wa.me/?text=<?php echo $share_text . rawurlencode($share_url); ?>" target="_blank" class="ptp-ref-share-btn ptp-ref-wa" data-share="whatsapp">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.789l4.923-1.46A11.93 11.93 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.75c-2.156 0-4.16-.68-5.803-1.836l-.416-.25-2.913.864.824-2.813-.273-.433A9.716 9.716 0 012.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75z"/>
                    </svg>
                    WhatsApp
                </a>
                <button class="ptp-ref-share-btn ptp-ref-more" data-share="native">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                    </svg>
                    Share
                </button>
            </div>
        </div>
    </div>
    
    <style>
    /* Referral Banner Component */
    .ptp-ref-banner {
        border-radius: 16px;
        overflow: hidden;
        margin: 24px 0;
    }
    
    .ptp-ref-dark {
        background: linear-gradient(135deg, #0A0A0A 0%, #1F2937 100%);
        color: #fff;
    }
    
    .ptp-ref-light {
        background: #FFFBEB;
        border: 2px solid #FCB900;
        color: #0A0A0A;
    }
    
    .ptp-ref-content {
        padding: 24px;
        text-align: center;
    }
    
    .ptp-ref-compact .ptp-ref-content {
        padding: 16px 20px;
    }
    
    .ptp-ref-header {
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    
    .ptp-ref-icon {
        font-size: 24px;
    }
    
    .ptp-ref-title {
        font-family: 'Oswald', sans-serif;
        font-size: 22px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    
    .ptp-ref-dark .ptp-ref-title {
        color: #FCB900;
    }
    
    .ptp-ref-text {
        font-size: 14px;
        margin: 0 0 16px;
        opacity: 0.9;
    }
    
    .ptp-ref-compact .ptp-ref-text {
        display: none;
    }
    
    .ptp-ref-code-box {
        display: inline-flex;
        align-items: center;
        gap: 0;
        background: rgba(252, 185, 0, 0.1);
        border: 2px solid #FCB900;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 16px;
    }
    
    .ptp-ref-code {
        font-family: 'Oswald', sans-serif;
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 3px;
        padding: 12px 20px;
        background: transparent;
    }
    
    .ptp-ref-dark .ptp-ref-code {
        color: #FCB900;
    }
    
    .ptp-ref-copy {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 12px 16px;
        background: #FCB900;
        border: none;
        cursor: pointer;
        font-family: 'Oswald', sans-serif;
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        color: #0A0A0A;
        transition: all 0.2s;
    }
    
    .ptp-ref-copy:hover {
        background: #e5a800;
    }
    
    .ptp-ref-copy.copied {
        background: #10B981;
        color: #fff;
    }
    
    .ptp-ref-share {
        display: flex;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .ptp-ref-share-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 10px 16px;
        border-radius: 8px;
        font-family: 'Oswald', sans-serif;
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        text-decoration: none;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
    }
    
    .ptp-ref-sms {
        background: #FCB900;
        color: #0A0A0A;
    }
    
    .ptp-ref-sms:hover {
        background: #e5a800;
    }
    
    .ptp-ref-wa {
        background: #25D366;
        color: #fff;
    }
    
    .ptp-ref-wa:hover {
        background: #20bd5a;
    }
    
    .ptp-ref-more {
        background: rgba(255,255,255,0.1);
        color: inherit;
    }
    
    .ptp-ref-dark .ptp-ref-more {
        color: #fff;
    }
    
    .ptp-ref-more:hover {
        background: rgba(255,255,255,0.2);
    }
    
    @media (max-width: 480px) {
        .ptp-ref-code {
            font-size: 20px;
            padding: 10px 16px;
            letter-spacing: 2px;
        }
        .ptp-ref-share-btn {
            padding: 8px 12px;
            font-size: 12px;
        }
    }
    </style>
    
    <script>
    function copyReferralCode() {
        var code = document.getElementById('ptp-referral-code').textContent;
        navigator.clipboard.writeText(code).then(function() {
            var btn = document.querySelector('.ptp-ref-copy');
            var text = document.getElementById('copy-text');
            btn.classList.add('copied');
            text.textContent = 'Copied!';
            setTimeout(function() {
                btn.classList.remove('copied');
                text.textContent = 'Copy';
            }, 2000);
        });
    }
    
    function shareReferral() {
        var code = document.getElementById('ptp-referral-code').textContent;
        var url = '<?php echo esc_js($share_url); ?>';
        var text = 'Train with pro & college athletes near you! Use my code ' + code + ' for $20 off: ';
        
        if (navigator.share) {
            navigator.share({
                title: 'Get $20 off PTP Training',
                text: text,
                url: url
            });
        } else {
            // Fallback - copy link
            navigator.clipboard.writeText(text + url);
            alert('Share link copied to clipboard!');
        }
    }
    </script>
    <?php
}

/**
 * Shortcode for package selector
 */
add_shortcode('ptp_packages', function($atts) {
    $atts = shortcode_atts(array(
        'rate' => 120,
        'trainer' => '',
    ), $atts);
    
    ob_start();
    ptp_render_package_selector(intval($atts['rate']), $atts['trainer']);
    return ob_get_clean();
});

/**
 * Shortcode for referral banner v2
 */
add_shortcode('ptp_referral_v2', function($atts) {
    $atts = shortcode_atts(array(
        'style' => 'dark',
        'compact' => false,
    ), $atts);
    
    ob_start();
    ptp_render_referral_banner_v2(null, array(
        'style' => $atts['style'],
        'compact' => filter_var($atts['compact'], FILTER_VALIDATE_BOOLEAN),
    ));
    return ob_get_clean();
});
