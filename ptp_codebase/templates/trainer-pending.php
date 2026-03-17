<?php
/**
 * Trainer Pending Page
 * Shows application status. Redirects approved trainers to onboarding.
 */
defined('ABSPATH') || exit;

// Must be logged in
if (!is_user_logged_in()) {
    wp_redirect(home_url('/login/?redirect_to=' . urlencode(home_url('/trainer-pending/'))));
    exit;
}

$current_user = wp_get_current_user();
$current_user_id = get_current_user_id();

// If they have ptp_trainer role, they've been approved — check for trainer record
if (in_array('ptp_trainer', (array) $current_user->roles)) {
    global $wpdb;
    $trainer = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", $current_user_id
    ));
    if ($trainer) {
        // Has trainer record — send to onboarding (or dashboard if already completed)
        $completed = get_user_meta($current_user_id, 'ptp_onboarding_completed', true);
        if ($completed) {
            wp_redirect(home_url('/trainer-dashboard/'));
        } else {
            wp_redirect(home_url('/trainer-onboarding/'));
        }
        exit;
    }
}

// Check application status
$app_status = 'pending';
$app_name = $current_user->display_name;
global $wpdb;
$app = $wpdb->get_row($wpdb->prepare(
    "SELECT status, name FROM {$wpdb->prefix}ptp_applications WHERE email = %s ORDER BY created_at DESC LIMIT 1",
    $current_user->user_email
));
if ($app) {
    $app_status = $app->status;
    $app_name = $app->name;
}

// Rejected — show different message
$is_rejected = ($app_status === 'rejected');

get_header();
?>

<div class="ptp-wrap" style="max-width: 600px; margin: 0 auto; padding: 80px 24px; text-align: center; font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif;">
    
    <?php if ($is_rejected): ?>
    <div style="font-size: 64px; margin-bottom: 24px;">📋</div>
    
    <h1 style="font-size: 32px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 16px !important;">
        Application Update
    </h1>
    
    <p style="color: #6B7280; font-size: 16px; line-height: 1.6; margin-bottom: 32px !important;">
        Thank you for your interest in becoming a PTP trainer. After reviewing your application, we're unable to move forward at this time. If you believe this was an error or your circumstances have changed, feel free to reach out.
    </p>
    
    <p style="color: #9CA3AF; font-size: 14px; margin-bottom: 16px !important;">
        Questions? Email us at <a href="mailto:<?php echo esc_attr(function_exists("ptp_email_brand") ? ptp_email_brand("support_email") : "info@ptpsummercamps.com"); ?>" style="color: #FCB900; text-decoration: none; font-weight: 600;"><?php echo esc_html(function_exists("ptp_email_brand") ? ptp_email_brand("support_email") : "info@ptpsummercamps.com"); ?></a>
    </p>
    
    <a href="<?php echo esc_url(home_url('/')); ?>" style="display: inline-block; padding: 14px 28px; background: #0A0A0A; color: #FFFFFF; font-weight: 600; font-size: 15px; border-radius: 12px; text-decoration: none;">
        Back to Home
    </a>
    
    <?php else: ?>
    <div style="font-size: 64px; margin-bottom: 24px;">⏳</div>
    
    <h1 style="font-size: 32px; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 16px !important;">
        Application Under Review
    </h1>
    
    <p style="color: #6B7280; font-size: 16px; line-height: 1.6; margin-bottom: 32px !important;">
        Thanks for applying to be a PTP trainer! We're reviewing your application and will get back to you within <strong>24-48 hours</strong>.
    </p>
    
    <div style="background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 16px; padding: 24px; margin-bottom: 32px; text-align: left;">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 12px !important;">What happens next?</h3>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="width: 28px; height: 28px; background: #D1FAE5; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">✓</div>
                <div>
                    <div style="font-weight: 600; font-size: 14px;">Application Submitted</div>
                    <div style="color: #6B7280; font-size: 13px;">We've received your application</div>
                </div>
            </div>
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="width: 28px; height: 28px; background: #FEF3C7; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">⏳</div>
                <div>
                    <div style="font-weight: 600; font-size: 14px;">Review in Progress</div>
                    <div style="color: #6B7280; font-size: 13px;">Our team is reviewing your background</div>
                </div>
            </div>
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="width: 28px; height: 28px; background: #F3F4F6; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">3</div>
                <div>
                    <div style="font-weight: 600; font-size: 14px;">Complete Onboarding</div>
                    <div style="color: #6B7280; font-size: 13px;">Set up your profile, rates, and availability</div>
                </div>
            </div>
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="width: 28px; height: 28px; background: #F3F4F6; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">4</div>
                <div>
                    <div style="font-weight: 600; font-size: 14px;">Start Training</div>
                    <div style="color: #6B7280; font-size: 13px;">Go live and start accepting bookings</div>
                </div>
            </div>
        </div>
    </div>
    
    <p style="color: #9CA3AF; font-size: 14px; margin-bottom: 16px !important;">
        Questions? Email us at <a href="mailto:<?php echo esc_attr(function_exists("ptp_email_brand") ? ptp_email_brand("support_email") : "info@ptpsummercamps.com"); ?>" style="color: #FCB900; text-decoration: none; font-weight: 600;"><?php echo esc_html(function_exists("ptp_email_brand") ? ptp_email_brand("support_email") : "info@ptpsummercamps.com"); ?></a>
    </p>
    
    <a href="<?php echo esc_url(home_url('/')); ?>" style="display: inline-block; padding: 14px 28px; background: #0A0A0A; color: #FFFFFF; font-weight: 600; font-size: 15px; border-radius: 12px; text-decoration: none;">
        Back to Home
    </a>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
