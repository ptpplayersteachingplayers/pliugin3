<?php
/**
 * PTP Thank You Page v171
 * 
 * Full-width, mobile-first camp confirmation.
 * Uses site header/footer for consistent branding.
 */

defined('ABSPATH') || exit;

global $wpdb;

// Get order ID from URL
$order_id = isset($_GET['order']) ? intval($_GET['order']) : (isset($_GET['order_id']) ? intval($_GET['order_id']) : 0);

// Initialize all variables
$camp_order = null;
$camp_order_items = array();
$order_number = '';
$total_amount = 0;
$parent_first_name = '';
$parent_last_name = '';
$parent_email = '';
$parent_phone = '';
$camper_first_name = '';
$camper_last_name = '';
$camper_name = '';
$camper_age = '';
$camp_name = '';
$camp_location = '';
$camp_dates = '';
$camp_time = '9AM - 3PM';

// Load camp order from database
if ($order_id > 0) {
    $camp_order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_unified_camp_orders WHERE id = %d",
        $order_id
    ));
    
    if ($camp_order) {
        // Order info
        $order_number = $camp_order->order_number ?: 'PTP-' . strtoupper(substr(md5($order_id), 0, 8));
        $total_amount = floatval($camp_order->total_amount ?: 0);
        
        // Parent/billing info
        $parent_first_name = $camp_order->billing_first_name ?? '';
        $parent_last_name = $camp_order->billing_last_name ?? '';
        $parent_email = $camp_order->billing_email ?? '';
        $parent_phone = $camp_order->billing_phone ?? '';
        
        // Get order items
        $camp_order_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_camp_order_items WHERE order_id = %d",
            $order_id
        ));
        
        // Extract camp and camper details from first item
        if (!empty($camp_order_items)) {
            $item = $camp_order_items[0];
            
            // Camp info - try multiple possible column names
            $camp_name = '';
            foreach (['camp_name', 'product_name', 'name', 'title'] as $col) {
                if (!empty($item->$col)) { $camp_name = $item->$col; break; }
            }
            
            $camp_location = '';
            foreach (['camp_location', 'location', 'venue', 'address'] as $col) {
                if (!empty($item->$col)) { $camp_location = $item->$col; break; }
            }
            
            $camp_dates = '';
            foreach (['camp_dates', 'dates', 'date_range', 'start_date'] as $col) {
                if (!empty($item->$col)) { $camp_dates = $item->$col; break; }
            }
            
            $camp_time = '';
            foreach (['camp_time', 'time', 'schedule', 'hours'] as $col) {
                if (!empty($item->$col)) { $camp_time = $item->$col; break; }
            }
            if (empty($camp_time)) $camp_time = '9AM - 3PM';
            
            // Camper info
            $camper_first_name = $item->camper_first_name ?? '';
            $camper_last_name = $item->camper_last_name ?? '';
            $camper_age = $item->camper_age ?? $item->age ?? '';
        }
        
        // Fallback camper name to parent if not in items
        if (empty($camper_first_name)) {
            $camper_first_name = $parent_first_name;
            $camper_last_name = $parent_last_name;
        }
        
        $camper_name = trim($camper_first_name . ' ' . $camper_last_name);
    }
}

// Display values
$camper_display = $camper_first_name ?: 'Your Player';
$camper_upper = strtoupper($camper_first_name ?: "YOU'RE");

// Generate referral code
$referral_code = strtoupper(substr(preg_replace('/[^a-z]/i', '', $camper_first_name ?: 'PTP'), 0, 4)) . '-' . strtoupper(substr(md5($order_id . 'ptp'), 0, 4));

// Check if announcement already submitted
$announcement_submitted = $order_id ? get_transient('ptp_announce_' . $order_id) : false;

// Share text
$share_text = $camper_first_name ? "{$camper_first_name} is locked in for PTP Soccer Camp!" : "Just registered for PTP Soccer Camp!";

// Get WordPress header
get_header();
?>

<!-- v216: Google Ads Purchase Conversion Tracking -->
<script async src="https://www.googletagmanager.com/gtag/js?id=AW-16949170445"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', 'AW-16949170445');
gtag('event', 'conversion', {
    'send_to': 'AW-16949170445/vxJCCNnsgfcbEI2i_5E_',
    'transaction_id': '<?php echo esc_js($order_id ?: ''); ?>'
});
</script>

<style>
/* ===== FORCE FULL WIDTH ===== */
html { overflow-x: hidden !important; }
.ptp-ty-fullwidth {
    width: 100% !important;
    max-width: 100% !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
    position: relative !important;
    left: 0 !important;
    right: 0 !important;
    background: #0A0A0A !important;
    min-height: 100vh;
    padding: 0 !important;
    box-sizing: border-box !important;
}

/* Override any theme constraints */
.ptp-ty-fullwidth,
.ptp-ty-fullwidth * {
    box-sizing: border-box !important;
}

body .ptp-ty-fullwidth {
    width: 100% !important;
    max-width: none !important;
}

/* ===== HERO ===== */
.ptp-ty-hero {
    text-align: center;
    padding: 48px 20px 32px;
    width: 100%;
}

.ptp-ty-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #FCB900;
    color: #0A0A0A;
    padding: 8px 20px;
    font-family: 'Oswald', sans-serif;
    font-weight: 600;
    font-size: 12px;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 20px;
}

.ptp-ty-title {
    font-family: 'Oswald', sans-serif;
    font-size: clamp(36px, 10vw, 64px);
    font-weight: 700;
    line-height: 1.05;
    margin: 0 0 16px;
    text-transform: uppercase;
    color: #FFFFFF;
}

.ptp-ty-title .gold { color: #FCB900; }

.ptp-ty-subtitle {
    color: #888888;
    font-size: 14px;
    margin: 0;
}

.ptp-ty-subtitle strong { color: #FFFFFF; }

/* ===== CONTAINER ===== */
.ptp-ty-container {
    width: 100%;
    max-width: 520px;
    margin: 0 auto;
    padding: 0 16px 60px;
}

/* ===== CARDS ===== */
.ptp-ty-card {
    background: #1A1A1A;
    border: 1px solid #333;
    margin-bottom: 16px;
    width: 100%;
}

.ptp-ty-card-header {
    background: #FCB900;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.ptp-ty-card-header.dark {
    background: #111111;
    border-bottom: 1px solid #333;
}

.ptp-ty-card-icon { font-size: 18px; }

.ptp-ty-card-title {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #0A0A0A;
    margin: 0;
}

.ptp-ty-card-header.dark .ptp-ty-card-title { color: #FFFFFF; }

.ptp-ty-card-subtitle {
    font-size: 11px;
    opacity: 0.7;
    color: #0A0A0A;
    margin: 2px 0 0;
}

.ptp-ty-card-header.dark .ptp-ty-card-subtitle { color: #888888; }

.ptp-ty-card-body { padding: 16px; }

/* ===== DETAILS GRID ===== */
.ptp-ty-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.ptp-ty-detail {
    min-width: 0;
}

.ptp-ty-detail-label {
    font-family: 'Oswald', sans-serif;
    font-size: 10px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #888888;
    margin-bottom: 4px;
}

.ptp-ty-detail-value {
    font-size: 14px;
    font-weight: 500;
    color: #FFFFFF;
    word-break: break-word;
}

.ptp-ty-detail-value.gold {
    color: #FCB900;
    font-family: 'Oswald', sans-serif;
    font-size: 20px;
    font-weight: 700;
}

/* ===== SHARE BUTTONS ===== */
.ptp-ty-share-btns {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
}

.ptp-ty-share-btn {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 8px;
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: opacity 0.2s;
}

.ptp-ty-share-btn:hover { opacity: 0.9; }

.ptp-ty-share-btn.instagram {
    background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
    color: white;
}

.ptp-ty-share-btn.copy {
    background: #111111;
    color: #FFFFFF;
    border: 1px solid #444;
}

/* ===== INPUT ===== */
.ptp-ty-ig-input {
    display: flex;
    border: 1px solid #444;
    margin-bottom: 12px;
    background: #111111;
}

.ptp-ty-ig-prefix {
    padding: 12px 14px;
    font-weight: 600;
    color: #888888;
    border-right: 1px solid #444;
    flex-shrink: 0;
}

.ptp-ty-ig-field {
    flex: 1;
    min-width: 0;
    border: none;
    padding: 12px 14px;
    font-size: 15px;
    font-family: inherit;
    outline: none;
    background: transparent;
    color: #FFFFFF;
}

.ptp-ty-ig-field::placeholder { color: #666; }

/* ===== BUTTONS ===== */
.ptp-ty-btn {
    display: block;
    width: 100%;
    padding: 14px 20px;
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}

.ptp-ty-btn-gold {
    background: #FCB900;
    color: #0A0A0A;
}

.ptp-ty-btn-gold:hover { background: #E5A800; }

.ptp-ty-btn-outline {
    background: transparent;
    color: #FFFFFF;
    border: 1px solid #444;
}

.ptp-ty-btn-outline:hover {
    background: #FFFFFF;
    color: #0A0A0A;
}

/* ===== UPSELL ===== */
.ptp-ty-upsell {
    background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 100%);
    border: 2px solid #FCB900;
    padding: 24px 16px;
    text-align: center;
    margin-bottom: 16px;
}

.ptp-ty-upsell-tag {
    display: inline-block;
    background: #FCB900;
    color: #0A0A0A;
    padding: 6px 14px;
    font-family: 'Oswald', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
    margin-bottom: 12px;
}

.ptp-ty-upsell-title {
    font-family: 'Oswald', sans-serif;
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 8px;
    text-transform: uppercase;
    color: #FFFFFF;
}

.ptp-ty-upsell-desc {
    color: #888888;
    font-size: 13px;
    margin: 0 0 16px;
    line-height: 1.5;
}

.ptp-ty-upsell-stats {
    display: flex;
    justify-content: center;
    gap: 32px;
    margin-bottom: 16px;
}

.ptp-ty-stat { text-align: center; }

.ptp-ty-stat-num {
    font-family: 'Oswald', sans-serif;
    font-size: 32px;
    font-weight: 700;
    color: #FCB900;
    line-height: 1;
}

.ptp-ty-stat-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #888888;
    margin-top: 4px;
}

/* ===== REFERRAL ===== */
.ptp-ty-referral-code {
    background: #111111;
    border: 2px dashed #444;
    padding: 14px 20px;
    font-family: 'Oswald', sans-serif;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: 3px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 8px;
    color: #FFFFFF;
}

.ptp-ty-referral-code:hover {
    border-color: #FCB900;
    background: #1a1a1a;
}

.ptp-ty-referral-desc {
    font-size: 12px;
    color: #888888;
    text-align: center;
    margin: 0;
}

/* ===== SUCCESS ===== */
.ptp-ty-success {
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid #22C55E;
    padding: 14px;
    text-align: center;
    color: #22C55E;
    font-weight: 600;
    font-size: 14px;
}

/* ===== FOOTER ===== */
.ptp-ty-footer {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 8px;
}

@media (min-width: 480px) {
    .ptp-ty-footer { flex-direction: row; }
    .ptp-ty-footer .ptp-ty-btn { flex: 1; }
}

/* ===== CONTACT ===== */
.ptp-ty-contact {
    text-align: center;
    font-size: 13px;
    color: #666;
    margin-top: 24px;
    padding-bottom: 20px;
}

.ptp-ty-contact a {
    color: #FCB900;
    text-decoration: none;
}

.ptp-ty-contact .brand {
    display: block;
    color: #888;
    margin-top: 4px;
}
</style>

<div class="ptp-ty-fullwidth">

    <section class="ptp-ty-hero">
        <div class="ptp-ty-badge">✓ CONFIRMED</div>
        <h1 class="ptp-ty-title">
            <span class="gold"><?php echo esc_html($camper_upper); ?></span><br>
            <?php echo $camper_first_name ? 'IS' : 'ALL'; ?> LOCKED IN 🔥
        </h1>
        <?php if ($parent_email): ?>
        <p class="ptp-ty-subtitle">Confirmation sent to <strong><?php echo esc_html($parent_email); ?></strong></p>
        <?php endif; ?>
    </section>

    <div class="ptp-ty-container">
        
        <!-- Camp Details Card -->
        <div class="ptp-ty-card">
            <div class="ptp-ty-card-header">
                <span class="ptp-ty-card-icon">📋</span>
                <div>
                    <div class="ptp-ty-card-title">Camp Details</div>
                    <div class="ptp-ty-card-subtitle">Order <?php echo esc_html($order_number); ?></div>
                </div>
            </div>
            <div class="ptp-ty-card-body">
                <div class="ptp-ty-details">
                    
                    <div class="ptp-ty-detail">
                        <div class="ptp-ty-detail-label">Camp</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camp_name ?: 'PTP Soccer Camp'); ?></div>
                    </div>
                    
                    <div class="ptp-ty-detail">
                        <div class="ptp-ty-detail-label">Time</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camp_time); ?></div>
                    </div>
                    
                    <?php if ($camp_location): ?>
                    <div class="ptp-ty-detail">
                        <div class="ptp-ty-detail-label">Location</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camp_location); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($camp_dates): ?>
                    <div class="ptp-ty-detail">
                        <div class="ptp-ty-detail-label">Dates</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camp_dates); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($camper_name): ?>
                    <div class="ptp-ty-detail">
                        <div class="ptp-ty-detail-label">Camper</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camper_name); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="ptp-ty-detail">
                        <div class="ptp-ty-detail-label">Total Paid</div>
                        <div class="ptp-ty-detail-value gold">$<?php echo number_format($total_amount, 2); ?></div>
                    </div>
                    
                </div>
            </div>
        </div>
        
        <!-- Share the News -->
        <div class="ptp-ty-card" id="share-card">
            <div class="ptp-ty-card-header">
                <span class="ptp-ty-card-icon">📸</span>
                <div>
                    <div class="ptp-ty-card-title">Share the News</div>
                    <div class="ptp-ty-card-subtitle">Let everyone know <?php echo esc_html($camper_display); ?> is training with pros</div>
                </div>
            </div>
            <div class="ptp-ty-card-body">
                <?php if ($announcement_submitted): ?>
                <div class="ptp-ty-success">✓ Submitted! We'll tag you when we post.</div>
                <?php else: ?>
                
                <div class="ptp-ty-share-btns">
                    <a href="https://www.instagram.com/<?php echo esc_attr(function_exists("ptp_email_brand") ? ptp_email_brand("instagram") : "ptp.training"); ?>/" target="_blank" class="ptp-ty-share-btn instagram">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                        @ptp.training
                    </a>
                    <button onclick="ptpCopyShare()" class="ptp-ty-share-btn copy" id="copy-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                        Copy & Share
                    </button>
                </div>
                
                <p style="font-size:12px;color:#888;margin-bottom:12px;text-align:center;">
                    Want us to feature <?php echo esc_html($camper_display); ?> on our Instagram? Enter your handle:
                </p>
                
                <div class="ptp-ty-ig-input">
                    <div class="ptp-ty-ig-prefix">@</div>
                    <input type="text" class="ptp-ty-ig-field" id="ig-handle" placeholder="yourhandle">
                </div>
                
                <button class="ptp-ty-btn ptp-ty-btn-gold" id="submit-btn" onclick="ptpSubmitAnnouncement()">
                    Submit for Feature
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Trainer Upsell -->
        <div class="ptp-ty-upsell">
            <div class="ptp-ty-upsell-tag">⚡ CAMP PREP SPECIAL</div>
            <h2 class="ptp-ty-upsell-title">Level Up Before Camp</h2>
            <p class="ptp-ty-upsell-desc">
                Kids who add 1-on-1 training improve 3x faster. Work with a D1 college coach before camp starts.
            </p>
            <div class="ptp-ty-upsell-stats">
                <div class="ptp-ty-stat">
                    <div class="ptp-ty-stat-num">47</div>
                    <div class="ptp-ty-stat-label">Parents Added Training</div>
                </div>
                <div class="ptp-ty-stat">
                    <div class="ptp-ty-stat-num">8:1</div>
                    <div class="ptp-ty-stat-label">Coach Ratio</div>
                </div>
            </div>
            <a href="<?php echo home_url('/find-trainers/'); ?>" class="ptp-ty-btn ptp-ty-btn-gold">
                🎯 Book a Trainer Now
            </a>
            <p style="font-size:11px;color:#888;margin-top:10px;">Use code <strong style="color:#FCB900;">CAMP15</strong> for 15% off</p>
        </div>
        
        <!-- Referral -->
        <div class="ptp-ty-card">
            <div class="ptp-ty-card-header dark">
                <span class="ptp-ty-card-icon">🎁</span>
                <div>
                    <div class="ptp-ty-card-title">Give $25, Get $25</div>
                </div>
            </div>
            <div class="ptp-ty-card-body">
                <div class="ptp-ty-referral-code" onclick="ptpCopyCode(this)"><?php echo esc_html($referral_code); ?></div>
                <p class="ptp-ty-referral-desc">Tap to copy • Friends save $25, you get $25 credit</p>
            </div>
        </div>
        
        <!-- Footer Actions -->
        <div class="ptp-ty-footer">
            <a href="<?php echo home_url('/my-account/'); ?>" class="ptp-ty-btn ptp-ty-btn-gold">My Dashboard</a>
            <a href="<?php echo home_url('/ptp-find-a-camp/'); ?>" class="ptp-ty-btn ptp-ty-btn-outline">More Camps</a>
        </div>
        
        <!-- Contact Info -->
        <div class="ptp-ty-contact">
            Questions? Call or text <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', function_exists('ptp_email_brand') ? ptp_email_brand('support_phone') : '')); ?>"><?php echo esc_html(function_exists('ptp_email_brand') ? ptp_email_brand('support_phone') : ''); ?></a>
            <span class="brand"><?php echo esc_html(function_exists('ptp_email_brand') ? ptp_email_brand('tagline') : 'Players Teaching Players'); ?></span>
        </div>
        
    </div>

</div>

<script>
var ptpShareText = '<?php echo esc_js($share_text); ?> 🔥⚽ The coaches actually PLAY with the kids. Check it out: <?php echo home_url(); ?>';

function ptpCopyShare() {
    navigator.clipboard.writeText(ptpShareText).then(function() {
        var btn = document.getElementById('copy-btn');
        btn.innerHTML = '✓ Copied!';
        setTimeout(function() {
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copy & Share';
        }, 2000);
    });
}

function ptpCopyCode(el) {
    var code = el.textContent.trim();
    navigator.clipboard.writeText(code).then(function() {
        var original = el.textContent;
        el.textContent = '✓ COPIED!';
        el.style.borderColor = '#FCB900';
        setTimeout(function() {
            el.textContent = original;
            el.style.borderColor = '#444';
        }, 2000);
    });
}

function ptpSubmitAnnouncement() {
    var handle = document.getElementById('ig-handle').value.trim();
    var btn = document.getElementById('submit-btn');
    
    if (!handle) {
        alert('Please enter your Instagram handle');
        return;
    }
    
    btn.textContent = 'Submitting...';
    btn.disabled = true;
    
    var formData = new FormData();
    formData.append('action', 'ptp_submit_announcement');
    formData.append('order_id', '<?php echo intval($order_id); ?>');
    formData.append('ig_handle', handle);
    formData.append('camper_name', '<?php echo esc_js($camper_name); ?>');
    formData.append('camp_name', '<?php echo esc_js($camp_name); ?>');
    formData.append('nonce', '<?php echo wp_create_nonce('ptp_announcement'); ?>');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            document.getElementById('share-card').querySelector('.ptp-ty-card-body').innerHTML = 
                '<div class="ptp-ty-success">✓ Submitted! We\'ll tag @' + handle + ' when we post.</div>';
        } else {
            btn.textContent = 'Submit for Feature';
            btn.disabled = false;
            alert(data.data || 'Error submitting');
        }
    })
    .catch(function() {
        btn.textContent = 'Submit for Feature';
        btn.disabled = false;
    });
}
</script>

<?php
get_footer();
