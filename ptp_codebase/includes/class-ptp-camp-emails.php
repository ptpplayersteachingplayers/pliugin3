<?php
/**
 * PTP Camp Emails - Email notifications for camp orders
 * 
 * Handles all camp-related email notifications without PTP Native.
 * 
 * @version 154.0.0
 * @since 146.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Emails {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Nothing to hook for now, all methods are static
    }
    
    /**
     * Send order confirmation email
     */
    public static function send_order_confirmation($order_id) {
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->billing_email;
        
        // Get first camper name for personalized subject
        $camper_first = '';
        if (!empty($order->items)) {
            $camper_first = $order->items[0]->camper_first_name ?? $order->billing_first_name;
        }
        
        $subject = $camper_first . " is Locked In! - " . ptp_email_brand('company') . " Camp Confirmation";
        
        // Generate referral code if not exists
        $referral_code = !empty($order->referral_code_generated) 
            ? $order->referral_code_generated 
            : strtoupper(substr(preg_replace('/[^a-z]/i', '', $camper_first ?: 'PTP'), 0, 4)) . '-' . strtoupper(substr(md5($order_id . 'ptp'), 0, 4));
        
        // Start email
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#0A0A0A;font-family:Helvetica,Arial,sans-serif;color:#FFFFFF;">
    
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#0A0A0A;">
        <tr>
            <td align="center" style="padding:16px;">
                <table role="presentation" width="480" cellspacing="0" cellpadding="0" border="0" style="max-width:480px;width:100%;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="padding:20px 0;text-align:center;border-bottom:1px solid #222;">
                            ' . (ptp_email_brand('logo_url') 
                                ? '<img src="' . esc_url(ptp_email_brand('logo_url')) . '" alt="' . esc_attr(ptp_email_brand('company')) . '" width="120" style="max-width:120px;height:auto;">'
                                : '<span style="font-size:32px;font-weight:700;color:#FFFFFF;letter-spacing:3px;">' . esc_html(ptp_email_brand('company')) . '</span>') . '
                        </td>
                    </tr>
                    
                    <!-- Hero -->
                    <tr>
                        <td style="padding:40px 16px 32px;text-align:center;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                                <tr>
                                    <td style="background-color:#FCB900;padding:8px 20px;">
                                        <span style="font-size:12px;font-weight:700;color:#0A0A0A;letter-spacing:2px;">✓ CONFIRMED</span>
                                    </td>
                                </tr>
                            </table>
                            <h1 style="margin:20px 0 16px;font-size:42px;font-weight:700;color:#FFFFFF;line-height:1;">
                                <span style="color:#FCB900;">' . esc_html(strtoupper($camper_first)) . '</span><br>IS LOCKED IN 🔥
                            </h1>
                            <p style="margin:0;font-size:14px;color:#888888;">
                                Confirmation for <strong style="color:#FFFFFF;">' . esc_html($to) . '</strong>
                            </p>
                        </td>
                    </tr>';
        
        // Camp Details Card
        $html .= '
                    <!-- Camp Details -->
                    <tr>
                        <td style="padding:0 16px 16px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#1A1A1A;border:1px solid #333;">
                                <tr>
                                    <td style="background-color:#FCB900;padding:14px 16px;">
                                        <span style="font-size:18px;margin-right:8px;">📋</span>
                                        <span style="font-size:14px;font-weight:700;color:#0A0A0A;letter-spacing:1px;">CAMP DETAILS</span>
                                        <span style="font-size:11px;color:#0A0A0A;opacity:0.7;margin-left:8px;">Order ' . esc_html($order->order_number) . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px;">';
        
        // Loop through items
        foreach ($order->items as $item) {
            $html .= '
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom:16px;">
                                            <tr>
                                                <td style="padding:8px 0;" width="50%" valign="top">
                                                    <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">CAMP</p>
                                                    <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($item->camp_name ?: $item->product_name ?: ptp_email_brand('company') . ' Camp') . '</p>
                                                </td>
                                                <td style="padding:8px 0;" width="50%" valign="top">
                                                    <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">LOCATION</p>
                                                    <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($item->camp_location ?: 'See details below') . '</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;" width="50%" valign="top">
                                                    <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">DATES</p>
                                                    <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($item->camp_dates ?: 'See confirmation') . '</p>
                                                </td>
                                                <td style="padding:8px 0;" width="50%" valign="top">
                                                    <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">TIME</p>
                                                    <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html($item->camp_time ?: '9AM - 3PM') . '</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;" width="50%" valign="top">
                                                    <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">CAMPER</p>
                                                    <p style="margin:0;font-size:14px;font-weight:500;color:#FFFFFF;">' . esc_html(trim($item->camper_first_name . ' ' . $item->camper_last_name)) . '</p>
                                                </td>
                                                <td style="padding:8px 0;" width="50%" valign="top">
                                                    <p style="margin:0 0 4px;font-size:10px;font-weight:600;letter-spacing:1px;color:#888888;">TOTAL PAID</p>
                                                    <p style="margin:0;font-size:18px;font-weight:700;color:#FCB900;">$' . number_format($order->total_amount, 2) . '</p>
                                                </td>
                                            </tr>
                                        </table>';
        }
        
        $html .= '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
        
        // Trainer Upsell
        $html .= '
                    <!-- Trainer Upsell -->
                    <tr>
                        <td style="padding:0 16px 16px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 100%);border:2px solid #FCB900;">
                                <tr>
                                    <td style="padding:24px 16px;text-align:center;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                                            <tr>
                                                <td style="background-color:#FCB900;padding:6px 14px;">
                                                    <span style="font-size:11px;font-weight:700;color:#0A0A0A;letter-spacing:1px;">⚡ CAMP PREP SPECIAL</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <h2 style="margin:12px 0 8px;font-size:22px;font-weight:700;color:#FFFFFF;">LEVEL UP BEFORE CAMP</h2>
                                        <p style="margin:0 0 16px;font-size:13px;color:#888888;line-height:1.5;">
                                            Kids who add 1-on-1 training improve 3x faster. Work with a D1 college coach before camp starts.
                                        </p>
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin-bottom:16px;">
                                            <tr>
                                                <td style="text-align:center;padding:0 20px;">
                                                    <p style="margin:0;font-size:28px;font-weight:700;color:#FCB900;">47</p>
                                                    <p style="margin:0;font-size:10px;letter-spacing:1px;color:#888888;">PARENTS ADDED</p>
                                                </td>
                                                <td style="text-align:center;padding:0 20px;">
                                                    <p style="margin:0;font-size:28px;font-weight:700;color:#FCB900;">8:1</p>
                                                    <p style="margin:0;font-size:10px;letter-spacing:1px;color:#888888;">COACH RATIO</p>
                                                </td>
                                            </tr>
                                        </table>
                                        <a href="' . esc_url(home_url('/find-trainers/')) . '" style="display:inline-block;background-color:#FCB900;color:#0A0A0A;padding:14px 32px;font-size:14px;font-weight:700;text-decoration:none;letter-spacing:1px;">
                                            🎯 BOOK A TRAINER NOW
                                        </a>
                                        <p style="margin:10px 0 0;font-size:11px;color:#888888;">
                                            Use code <strong style="color:#FCB900;">CAMP15</strong> for 15% off
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
        
        // Share the News
        $html .= '
                    <!-- Share the News -->
                    <tr>
                        <td style="padding:0 16px 16px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#1A1A1A;border:1px solid #333;">
                                <tr>
                                    <td style="background-color:#FCB900;padding:14px 16px;">
                                        <span style="font-size:18px;margin-right:8px;">📸</span>
                                        <span style="font-size:14px;font-weight:700;color:#0A0A0A;letter-spacing:1px;">SHARE THE NEWS</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px;text-align:center;">
                                        <p style="margin:0 0 16px;font-size:13px;color:#888888;">
                                            Want ' . esc_html($camper_first) . ' featured on our Instagram? Reply to this email with your IG handle!
                                        </p>
                                        <a href="https://instagram.com/' . esc_attr(ptp_email_brand('instagram')) . '" style="display:inline-block;background:linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);color:#FFFFFF;padding:12px 24px;font-size:13px;font-weight:700;text-decoration:none;letter-spacing:0.5px;">
                                            FOLLOW @' . esc_html(strtoupper(ptp_email_brand('instagram'))) . '
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
        
        // Referral Code
        $html .= '
                    <!-- Referral -->
                    <tr>
                        <td style="padding:0 16px 16px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#1A1A1A;border:1px solid #333;">
                                <tr>
                                    <td style="background-color:#111111;padding:14px 16px;border-bottom:1px solid #333;">
                                        <span style="font-size:18px;margin-right:8px;">🎁</span>
                                        <span style="font-size:14px;font-weight:700;color:#FFFFFF;letter-spacing:1px;">GIVE $25, GET $25</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px;text-align:center;">
                                        <div style="background-color:#111111;border:2px dashed #444;padding:14px 20px;margin-bottom:8px;">
                                            <span style="font-size:20px;font-weight:700;color:#FFFFFF;letter-spacing:3px;">' . esc_html($referral_code) . '</span>
                                        </div>
                                        <p style="margin:0;font-size:12px;color:#888888;">
                                            Share with friends • They save $25, you get $25 credit
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
        
        // CTA Button
        $html .= '
                    <!-- CTA -->
                    <tr>
                        <td style="padding:0 16px 24px;text-align:center;">
                            <a href="' . esc_url(home_url('/my-account/')) . '" style="display:inline-block;background-color:#FCB900;color:#0A0A0A;padding:16px 48px;font-size:14px;font-weight:700;text-decoration:none;letter-spacing:1px;">
                                VIEW MY DASHBOARD
                            </a>
                        </td>
                    </tr>';
        
        // Footer - v221: Use shared footer
        $html .= self::get_email_footer();
        
        $customer_sent = self::send($to, $subject, $html);
        
        // v154: Also send admin notification
        self::send_admin_notification($order);
        
        return $customer_sent;
    }
    
    /**
     * Send admin notification for new camp order
     * v154: Added to ensure admin is notified of all camp registrations
     */
    public static function send_admin_notification($order) {
        if (!$order) {
            return false;
        }
        
        $admin_email = get_option('admin_email');
        $additional_admin = get_option('ptp_camp_admin_email', '');
        
        // Build recipient list
        $recipients = array($admin_email);
        if ($additional_admin && $additional_admin !== $admin_email) {
            $recipients[] = $additional_admin;
        }
        $recipients = apply_filters('ptp_camp_admin_notification_recipients', $recipients, $order);
        
        $camper_count = count($order->items);
        
        // v187.1: Check if any camper opted into Instagram feature
        $has_instagram = false;
        foreach ($order->items as $item) {
            if (!empty($item->photo_consent) && !empty($item->announcement_photo_url)) {
                $has_instagram = true;
                break;
            }
        }
        
        $ig_flag = $has_instagram ? ' [IG FEATURE]' : '';
        $subject = "[New Camp Order] #{$order->order_number} - {$camper_count} camper" . ($camper_count > 1 ? 's' : '') . " - \${$order->total_amount}" . $ig_flag;
        
        $html = self::get_email_header('New Camp Registration');
        
        $html .= '<p style="font-size: 16px; color: #333;">A new camp registration has been received.</p>';
        
        // Order info
        $html .= '<div style="background: #f8f9fa; border-radius: 8px; padding: 20px; margin: 20px 0;">';
        $html .= '<h2 style="margin: 0 0 15px 0; color: #0A0A0A; font-size: 18px;">Order Details</h2>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Order #:</strong> ' . esc_html($order->order_number) . '</p>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Date:</strong> ' . date('F j, Y g:i A', strtotime($order->created_at)) . '</p>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Total:</strong> $' . number_format($order->total_amount, 2) . '</p>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Payment:</strong> ' . esc_html($order->payment_method ?? 'Stripe') . '</p>';
        if (!empty($order->stripe_payment_intent_id)) {
            $html .= '<p style="margin: 5px 0; color: #666;"><strong>Stripe PI:</strong> ' . esc_html($order->stripe_payment_intent_id) . '</p>';
        }
        // v213: Discount/referral/attribution in admin email
        if (!empty($order->discount_code)) {
            $html .= '<p style="margin: 5px 0; color: #666;"><strong>Discount Code:</strong> ' . esc_html($order->discount_code) . ' (-$' . number_format($order->discount_amount, 2) . ')</p>';
        }
        if (!empty($order->referral_code_used)) {
            $html .= '<p style="margin: 5px 0; color: #666;"><strong>Referral Used:</strong> ' . esc_html($order->referral_code_used) . '</p>';
        }
        if (!empty($order->notes) && strpos($order->notes, 'Found via:') !== false) {
            preg_match('/Found via:\s*(.+)/', $order->notes, $matches);
            if (!empty($matches[1])) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Source:</strong> ' . esc_html(trim($matches[1])) . '</p>';
            }
        }
        $html .= '</div>';
        
        // Parent/Billing info
        $html .= '<div style="background: #f8f9fa; border-radius: 8px; padding: 20px; margin: 20px 0;">';
        $html .= '<h2 style="margin: 0 0 15px 0; color: #0A0A0A; font-size: 18px;">Parent / Billing</h2>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Name:</strong> ' . esc_html($order->billing_first_name . ' ' . $order->billing_last_name) . '</p>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Email:</strong> <a href="mailto:' . esc_attr($order->billing_email) . '">' . esc_html($order->billing_email) . '</a></p>';
        if (!empty($order->billing_phone)) {
            $html .= '<p style="margin: 5px 0; color: #666;"><strong>Phone:</strong> ' . esc_html($order->billing_phone) . '</p>';
        // v213: Emergency contact in admin email
        if (!empty($order->emergency_name)) {
            $html .= '<p style="margin: 10px 0 5px; color: #666; border-top: 1px solid #e5e7eb; padding-top: 10px;"><strong>Emergency Contact:</strong> ' . esc_html($order->emergency_name) . '</p>';
            if (!empty($order->emergency_phone)) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Emergency Phone:</strong> ' . esc_html($order->emergency_phone) . '</p>';
            }
            if (!empty($order->emergency_relation)) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Relationship:</strong> ' . esc_html($order->emergency_relation) . '</p>';
            }
        }
        }
        $html .= '</div>';
        
        // Camper details
        $html .= '<h2 style="color: #0A0A0A; font-size: 18px; margin: 30px 0 15px 0;">Registered Campers (' . $camper_count . ')</h2>';
        
        foreach ($order->items as $item) {
            $html .= '<div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin: 10px 0;">';
            $html .= '<h3 style="margin: 0 0 10px 0; color: #0A0A0A;">' . esc_html($item->camper_first_name . ' ' . $item->camper_last_name) . '</h3>';
            $html .= '<p style="margin: 5px 0; color: #666;"><strong>Camp:</strong> ' . esc_html($item->camp_name) . '</p>';
            if ($item->camp_dates) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Dates:</strong> ' . esc_html($item->camp_dates) . '</p>';
            }
            if ($item->camp_location) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Location:</strong> ' . esc_html($item->camp_location) . '</p>';
            }
            if (!empty($item->camper_age)) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Age:</strong> ' . esc_html($item->camper_age) . '</p>';
            }
            if (!empty($item->camper_shirt_size)) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Shirt Size:</strong> ' . esc_html($item->camper_shirt_size) . '</p>';
            }
            
            // Add-ons
            $addons = array();
            if ($item->care_bundle) $addons[] = 'Before + After Care';
            if ($item->jersey) $addons[] = 'Camp Jersey';
            if (!empty($addons)) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Add-ons:</strong> ' . implode(', ', $addons) . '</p>';
            }
            
            // Medical/allergies/notes
            $medical = trim(($item->medical_conditions ?? '') . ' ' . ($item->allergies ?? ''));
            if (!empty($medical)) {
                $html .= '<p style="margin: 5px 0; color: #c00;"><strong>⚠ Medical/Allergies:</strong> ' . esc_html($medical) . '</p>';
            }
            if (!empty($item->special_needs)) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Notes:</strong> ' . esc_html($item->special_needs) . '</p>';
            }
            
            $html .= '<p style="margin: 5px 0; color: #666;"><strong>Price:</strong> $' . number_format($item->base_price ?? $item->final_price ?? 0, 2) . '</p>';
            $html .= '</div>';
        }
        
        // v187.1: Instagram Feature Alert — prominent banner if any camper opted in
        $instagram_optins = array();
        foreach ($order->items as $item) {
            if (!empty($item->photo_consent) && !empty($item->announcement_photo_url)) {
                $instagram_optins[] = $item;
            }
        }
        
        if (!empty($instagram_optins)) {
            $html .= '<div style="background: linear-gradient(135deg, #833AB4, #E1306C, #F77737); border-radius: 8px; padding: 3px; margin: 20px 0;">';
            $html .= '<div style="background: #fff; border-radius: 6px; padding: 20px;">';
            $html .= '<h2 style="margin: 0 0 5px 0; color: #E1306C; font-size: 18px;">📸 INSTAGRAM FEATURE REQUESTED</h2>';
            $html .= '<p style="margin: 0 0 15px 0; font-size: 13px; color: #666;">This parent opted in to have their child featured on @' . esc_html(ptp_email_brand('instagram')) . '</p>';
            
            foreach ($instagram_optins as $ig_item) {
                $camper_name = trim(($ig_item->camper_first_name ?? '') . ' ' . ($ig_item->camper_last_name ?? ''));
                $html .= '<div style="background: #FFF5F7; border: 1px solid #FECDD3; border-radius: 6px; padding: 12px; margin: 8px 0;">';
                $html .= '<p style="margin: 0 0 5px; color: #333;"><strong>Camper:</strong> ' . esc_html($camper_name) . '</p>';
                $html .= '<p style="margin: 0 0 5px; color: #333;"><strong>Camp:</strong> ' . esc_html($ig_item->camp_name ?? '') . '</p>';
                if (!empty($ig_item->instagram_handle)) {
                    $handle = ltrim($ig_item->instagram_handle, '@');
                    $html .= '<p style="margin: 0 0 5px; color: #E1306C;"><strong>Instagram:</strong> <a href="https://instagram.com/' . esc_attr($handle) . '" style="color: #E1306C;">@' . esc_html($handle) . '</a></p>';
                }
                if (!empty($ig_item->announcement_photo_url)) {
                    $html .= '<p style="margin: 0 0 5px; color: #333;"><strong>Photo:</strong> <a href="' . esc_url($ig_item->announcement_photo_url) . '" style="color: #E1306C;">View Uploaded Photo</a></p>';
                    $html .= '<img src="' . esc_url($ig_item->announcement_photo_url) . '" alt="Camper photo" style="max-width: 200px; height: auto; border-radius: 8px; margin-top: 8px; border: 2px solid #E1306C;">';
                }
                $html .= '</div>';
            }
            
            $html .= '</div></div>';
        }
        
        // Discount summary if any
        if (!empty($order->discount_amount) && $order->discount_amount > 0) {
            $html .= '<div style="background: #ECFDF5; border-radius: 8px; padding: 15px; margin: 20px 0;">';
            $html .= '<p style="margin: 0; color: #065F46;"><strong>Discounts Applied:</strong> ';
            $html .= '-$' . number_format($order->discount_amount, 2);
            if (!empty($order->discount_type)) {
                $html .= ' (' . esc_html(str_replace(',', ', ', $order->discount_type)) . ')';
            }
            if (!empty($order->discount_code)) {
                $html .= ' | Code: ' . esc_html($order->discount_code);
            }
            $html .= '</p>';
            $html .= '</div>';
        }
        
        // Admin link
        $admin_url = admin_url('admin.php?page=ptp-camp-orders&action=view&order_id=' . $order->id);
        $html .= '<div style="text-align: center; margin: 30px 0;">';
        $html .= '<a href="' . esc_url($admin_url) . '" style="background: #FCB900; color: #0A0A0A; padding: 12px 30px; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-block;">View Order in Admin</a>';
        $html .= '</div>';
        
        $html .= self::get_email_footer();
        
        $sent = false;
        foreach ($recipients as $recipient) {
            $result = self::send($recipient, $subject, $html);
            if ($result) $sent = true;
        }
        
        return $sent;
    }
    
    /**
     * Send reminder email (1 week before)
     */
    public static function send_camp_reminder($order_id, $days_before = 7) {
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->billing_email;
        $subject = ptp_email_brand('company') . " Camp Reminder - See You Soon!";
        
        $html = self::get_email_header('Camp is Coming Up!');
        
        $html .= '<p style="font-size: 16px; color: #333;">Hi ' . esc_html($order->billing_first_name) . ',</p>';
        $html .= '<p style="font-size: 16px; color: #333;">Just a friendly reminder that camp is coming up in ' . $days_before . ' days!</p>';
        
        // Camper list
        $html .= '<h2 style="color: #0A0A0A; font-size: 18px; margin: 30px 0 15px 0;">Your Campers</h2>';
        
        foreach ($order->items as $item) {
            $html .= '<div style="background: #f8f9fa; border-radius: 8px; padding: 15px; margin: 10px 0;">';
            $html .= '<p style="margin: 0;"><strong>' . esc_html($item->camper_first_name) . '</strong> - ' . esc_html($item->camp_name) . '</p>';
            if ($item->camp_dates) {
                $html .= '<p style="margin: 5px 0 0 0; color: #666; font-size: 14px;">' . esc_html($item->camp_dates) . ' at ' . esc_html($item->camp_location) . '</p>';
            }
            $html .= '</div>';
        }
        
        // Check-in info
        $html .= '<div style="background: #DBEAFE; border-radius: 8px; padding: 20px; margin: 30px 0;">';
        $html .= '<h2 style="margin: 0 0 15px 0; color: #1E40AF; font-size: 18px;">Check-In Information</h2>';
        $html .= '<p style="margin: 0; color: #1E40AF;">Please arrive 15 minutes early on the first day for check-in. Look for the ' . esc_html(ptp_email_brand('company')) . ' tent at the main entrance.</p>';
        $html .= '</div>';
        
        $html .= self::get_email_footer();
        
        return self::send($to, $subject, $html);
    }
    
    /**
     * Send cancellation/refund email
     */
    public static function send_cancellation_email($order_id, $refund_amount = 0) {
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->billing_email;
        $subject = ptp_email_brand('company') . " Camp Registration Cancelled - Order #{$order->order_number}";
        
        $html = self::get_email_header('Registration Cancelled');
        
        $html .= '<p style="font-size: 16px; color: #333;">Hi ' . esc_html($order->billing_first_name) . ',</p>';
        $html .= '<p style="font-size: 16px; color: #333;">Your camp registration (Order #' . esc_html($order->order_number) . ') has been cancelled.</p>';
        
        if ($refund_amount > 0) {
            $html .= '<div style="background: #D1FAE5; border-radius: 8px; padding: 20px; margin: 20px 0;">';
            $html .= '<h2 style="margin: 0 0 10px 0; color: #065F46; font-size: 18px;">Refund Issued</h2>';
            $html .= '<p style="margin: 0; color: #065F46;">A refund of <strong>$' . number_format($refund_amount, 2) . '</strong> has been issued to your original payment method. Please allow 5-10 business days for the refund to appear.</p>';
            $html .= '</div>';
        }
        
        $html .= '<p style="font-size: 14px; color: #666; margin-top: 30px;">We hope to see you at a future camp! If you have any questions, please contact us at <a href="mailto:' . esc_attr(ptp_email_brand('support_email')) . '" style="color: #FCB900;">' . esc_html(ptp_email_brand('support_email')) . '</a></p>';
        
        $html .= self::get_email_footer();
        
        return self::send($to, $subject, $html);
    }
    
    /**
     * Send email
     */
    private static function send($to, $subject, $html) {
        $b = function_exists('ptp_email_brand') ? ptp_email_brand() : array();
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ($b['from_camps'] ?? 'PTP <' . get_option('admin_email') . '>'),
            'Reply-To: ' . ($b['from_email'] ?? get_option('admin_email')),
        );
        
        $sent = wp_mail($to, $subject, $html, $headers);
        
        if (!$sent) {
            ptp_log("[PTP Camp Emails] Failed to send email to $to: $subject");
        } else {
            ptp_log("[PTP Camp Emails] Email sent to $to: $subject");
        }
        
        return $sent;
    }
    
    /**
     * Get email header HTML - Black PTP Design
     */
    private static function get_email_header($title = '') {
        $b = function_exists('ptp_email_brand') ? ptp_email_brand() : array();
        $logo_url = $b['logo_url'] ?? '';
        $company  = $b['company'] ?? 'PTP';
        $logo_html = $logo_url 
            ? '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($company) . '" width="120" style="max-width:120px;height:auto;">'
            : '<span style="font-size:32px;font-weight:700;color:#FFFFFF;letter-spacing:3px;">' . esc_html($company) . '</span>';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#0A0A0A;font-family:Helvetica,Arial,sans-serif;color:#FFFFFF;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#0A0A0A;">
        <tr>
            <td align="center" style="padding:16px;">
                <table role="presentation" width="480" cellspacing="0" cellpadding="0" border="0" style="max-width:480px;width:100%;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="padding:20px 0;text-align:center;border-bottom:1px solid #222;">
                            ' . $logo_html . '
                        </td>
                    </tr>';
        
        if ($title) {
            $html .= '
                    <!-- Hero -->
                    <tr>
                        <td style="padding:40px 16px 32px;text-align:center;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center">
                                <tr>
                                    <td style="background-color:#FCB900;padding:8px 20px;">
                                        <span style="font-size:12px;font-weight:700;color:#0A0A0A;letter-spacing:2px;">✓ CONFIRMED</span>
                                    </td>
                                </tr>
                            </table>
                            <h1 style="margin:20px 0 0;font-size:32px;font-weight:700;color:#FFFFFF;line-height:1.1;">' . esc_html($title) . '</h1>
                        </td>
                    </tr>';
        }
        
        $html .= '
                    <!-- Content Start -->
                    <tr>
                        <td style="padding:0 16px;">';
        
        return $html;
    }
    
    /**
     * Get email footer HTML - Black PTP Design
     */
    private static function get_email_footer() {
        $b = function_exists('ptp_email_brand') ? ptp_email_brand() : array();
        $phone     = $b['support_phone'] ?? '';
        $company   = $b['company'] ?? 'PTP Soccer';
        $tagline   = $b['tagline'] ?? '';
        $site_url  = $b['site_url'] ?? home_url();
        $instagram = $b['instagram'] ?? '';
        
        $html = '
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 16px;text-align:center;border-top:1px solid #222;">
                            ' . ($phone ? '<p style="margin:0 0 8px;font-size:13px;color:#FFFFFF;">
                                Questions? Call or text <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $phone)) . '" style="color:#FCB900;text-decoration:none;">' . esc_html($phone) . '</a>
                            </p>' : '') . '
                            <p style="margin:0 0 16px;font-size:12px;color:#888888;">
                                ' . esc_html($company) . ($tagline ? ' &bull; ' . esc_html($tagline) : '') . '
                            </p>
                            <p style="margin:0;font-size:11px;color:#666666;">
                                <a href="' . esc_url($site_url) . '" style="color:#666666;text-decoration:underline;">' . esc_html(str_replace(array('https://', 'http://'), '', $site_url)) . '</a>' . ($instagram ? ' &bull; 
                                <a href="https://instagram.com/' . esc_attr($instagram) . '" style="color:#666666;text-decoration:underline;">@' . esc_html($instagram) . '</a>' : '') . '
                            </p>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        
        return $html;
    }
}

// Initialize
PTP_Camp_Emails::instance();
