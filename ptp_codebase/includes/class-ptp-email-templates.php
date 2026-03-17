<?php
/**
 * PTP Email Templates v88
 * 
 * Beautiful, branded email templates for all transactional emails
 * 
 * Features:
 * - Responsive HTML templates
 * - PTP brand styling (gold/black)
 * - Dark mode support
 * - Mobile-optimized
 * - Preview in admin
 */

defined('ABSPATH') || exit;

class PTP_Email_Templates {
    
    private static $instance = null;
    
    // Brand colors
    const GOLD = '#FCB900';
    const BLACK = '#0A0A0A';
    const GRAY = '#6B7280';
    const WHITE = '#FFFFFF';
    const GREEN = '#22C55E';
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Filter all PTP emails through our template system
        add_filter('ptp_email_content', array($this, 'wrap_in_template'), 10, 3);
    }
    
    /**
     * Get base email template
     */
    public static function get_base_template() {
        // v221: All brand values from centralized helper
        $b = function_exists('ptp_email_brand') ? ptp_email_brand() : array();
        $logo_url  = $b['logo_url'] ?? '';
        $site_url  = $b['site_url'] ?? home_url();
        $year      = $b['year'] ?? date('Y');
        $company   = $b['company'] ?? 'PTP Soccer';
        $phone     = $b['support_phone'] ?? '';
        $tagline   = $b['tagline'] ?? '';
        
        return '<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
    <meta charset="utf-8">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings xmlns:o="urn:schemas-microsoft-com:office:office">
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <style>
        td,th,div,p,a,h1,h2,h3,h4,h5,h6 {font-family: "Segoe UI", sans-serif; mso-line-height-rule: exactly;}
    </style>
    <![endif]-->
    <title>{{subject}}</title>
    <style>
        :root {
            color-scheme: light dark;
            supported-color-schemes: light dark;
        }
        
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            word-break: break-word;
            -webkit-font-smoothing: antialiased;
            background-color: #f3f4f6;
        }
        
        .hover-bg-gold:hover {
            background-color: ' . self::GOLD . ' !important;
        }
        
        @media (prefers-color-scheme: dark) {
            .dark-bg { background-color: #1a1a1a !important; }
            .dark-text { color: #e5e5e5 !important; }
            .dark-text-muted { color: #9ca3af !important; }
        }
        
        @media (max-width: 600px) {
            .sm-w-full { width: 100% !important; }
            .sm-px-4 { padding-left: 16px !important; padding-right: 16px !important; }
            .sm-px-6 { padding-left: 24px !important; padding-right: 24px !important; }
            .sm-py-8 { padding-top: 32px !important; padding-bottom: 32px !important; }
            .sm-text-3xl { font-size: 30px !important; }
            .sm-leading-8 { line-height: 32px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; width: 100%; word-break: break-word; -webkit-font-smoothing: antialiased; background-color: #f3f4f6;">
    <div style="display: none; max-height: 0; overflow: hidden;">{{preview}}</div>
    <div role="article" aria-roledescription="email" aria-label="{{subject}}" lang="en">
        <table style="width: 100%; font-family: ui-sans-serif, system-ui, -apple-system, \'Segoe UI\', sans-serif;" cellpadding="0" cellspacing="0" role="none">
            <tr>
                <td align="center" style="background-color: #f3f4f6; padding: 24px;">
                    <table class="sm-w-full" style="width: 600px;" cellpadding="0" cellspacing="0" role="none">
                        <!-- Header -->
                        <tr>
                            <td style="padding: 24px 0; text-align: center;">
                                <a href="' . esc_url($site_url) . '">
                                    <img src="' . esc_url($logo_url) . '" width="120" alt="' . esc_attr($company) . '" style="max-width: 100%; vertical-align: middle; border: 0;">
                                </a>
                            </td>
                        </tr>
                        
                        <!-- Main Content -->
                        <tr>
                            <td class="sm-px-4" style="border-radius: 16px; background-color: #ffffff; padding: 0; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);">
                                {{content}}
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style="padding: 32px 24px; text-align: center;">
                                ' . ($phone ? '<p style="margin: 0 0 16px; font-size: 12px; color: #6b7280;">
                                    Questions? Reply to this email or text us at ' . esc_html($phone) . '
                                </p>' : '') . '
                                <p style="margin: 0 0 16px; font-size: 12px; color: #9ca3af;">
                                    ' . esc_html($company) . ($tagline ? ' &middot; ' . esc_html($tagline) : '') . '
                                </p>
                                <p style="margin: 0; font-size: 11px; color: #9ca3af;">
                                    <a href="' . esc_url($site_url) . '/unsubscribe/" style="color: #9ca3af; text-decoration: underline;">Unsubscribe</a>
                                    &nbsp;&middot;&nbsp;
                                    <a href="' . esc_url($site_url) . '/privacy/" style="color: #9ca3af; text-decoration: underline;">Privacy</a>
                                </p>
                                <p style="margin: 16px 0 0; font-size: 11px; color: #d1d5db;">
                                    &copy; ' . $year . ' ' . esc_html($company) . '. All rights reserved.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>';
    }
    
    /**
     * Wrap content in base template
     */
    public function wrap_in_template($content, $subject = '', $preview = '') {
        $template = self::get_base_template();
        $preview = $preview ?: wp_trim_words(strip_tags($content), 20);
        
        return str_replace(
            array('{{content}}', '{{subject}}', '{{preview}}'),
            array($content, esc_html($subject), esc_html($preview)),
            $template
        );
    }
    
    /**
     * Booking Confirmation Email
     */
    /**
     * Format phone number for display: (610) 671-4778
     */
    private static function format_phone($phone) {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
        if (strlen($digits) === 10) {
            return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        }
        return $phone; // Return as-is if not 10 digits
    }

    public static function booking_confirmation($booking, $trainer, $parent) {
        // v215: Robust null/zero date handling
        $raw_date = $booking->session_date ?? '';
        $date = 'TBD - Your trainer will confirm';
        if (!empty($raw_date) && $raw_date !== '0000-00-00' && strtotime($raw_date) > 0) {
            $date = date('l, F j, Y', strtotime($raw_date));
        }
        $raw_time = $booking->start_time ?? '';
        // v220: Normalize time (safety net for legacy "1:00" stored as 01:00:00)
        if (!empty($raw_time) && function_exists('ptp_normalize_session_time')) {
            $raw_time = ptp_normalize_session_time($raw_time) ?: $raw_time;
        }
        $time = 'TBD';
        if (!empty($raw_time) && $raw_time !== '00:00:00' && strtotime($raw_time) !== false) {
            $time = date('g:i A', strtotime($raw_time));
        }
        $location = '';
        // v225: Prefer the location parent selected during checkout
        if (!empty($booking->location)) {
            $location = $booking->location;
        } elseif (!empty($booking->trainer_location)) {
            $location = $booking->trainer_location;
        } elseif (!empty($booking->trainer_city)) {
            $location = $booking->trainer_city . (!empty($booking->trainer_state) ? ', ' . $booking->trainer_state : '');
        } elseif (!empty($trainer->location)) {
            $location = $trainer->location;
        }
        // v230: Fallback to trainer's training_locations JSON
        if (empty($location)) {
            $tl_raw = !empty($trainer->training_locations) ? $trainer->training_locations : '';
            if (empty($tl_raw) && !empty($booking->trainer_id)) {
                global $wpdb;
                $tl_raw = $wpdb->get_var($wpdb->prepare(
                    "SELECT training_locations FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $booking->trainer_id
                ));
            }
            if (!empty($tl_raw)) {
                $tl = json_decode($tl_raw, true);
                if (!is_array($tl)) $tl = json_decode(wp_unslash($tl_raw), true);
                if (is_array($tl)) {
                    foreach ($tl as $loc) {
                        if (is_array($loc) && !empty($loc['name'])) {
                            $location = $loc['name'];
                            break;
                        }
                    }
                }
            }
        }
        if (empty($location)) {
            $location = 'Location TBD';
        }
        // v236: Extract address from location_notes for confirmation email
        $location_address = '';
        if (!empty($booking->location_notes)) {
            $notes = $booking->location_notes;
            // Strip GPS coordinates, keep just the address
            $addr = preg_replace('/\s*\|\s*GPS:.*$/i', '', $notes);
            if ($addr && $addr !== $location) {
                $location_address = trim($addr);
            }
        }
        // v236: If no address yet, look up from trainer's training_locations by matching location name
        if (empty($location_address) && !empty($location) && $location !== 'Location TBD') {
            $tl_raw_email = '';
            if (!empty($trainer->training_locations)) {
                $tl_raw_email = $trainer->training_locations;
            } elseif (!empty($booking->trainer_id)) {
                global $wpdb;
                $tl_raw_email = $wpdb->get_var($wpdb->prepare(
                    "SELECT training_locations FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $booking->trainer_id
                ));
            }
            if (!empty($tl_raw_email)) {
                $tl_email = json_decode($tl_raw_email, true);
                if (!is_array($tl_email)) $tl_email = json_decode(wp_unslash($tl_raw_email), true);
                if (is_array($tl_email)) {
                    foreach ($tl_email as $tl_loc) {
                        if (is_array($tl_loc) && !empty($tl_loc['name']) && $tl_loc['name'] === $location && !empty($tl_loc['address'])) {
                            $location_address = trim($tl_loc['address']);
                            break;
                        }
                    }
                    // v236: If no exact name match, use first location with an address
                    if (empty($location_address)) {
                        foreach ($tl_email as $tl_loc) {
                            if (is_array($tl_loc) && !empty($tl_loc['address'])) {
                                $location_address = trim($tl_loc['address']);
                                break;
                            }
                        }
                    }
                }
            }
        }

        $trainer_name = $trainer->display_name;
        $trainer_photo = $trainer->photo_url ?: 'https://via.placeholder.com/80';
        $trainer_phone = !empty($trainer->phone) ? $trainer->phone : '';
        $amount = number_format($booking->total_amount, 2);
        $profile_url = home_url('/trainer/' . $trainer->slug . '/');
        $dashboard_url = home_url('/my-training/');
        
        // v132: Support multi-player sessions
        $players_data = isset($booking->players_data) ? $booking->players_data : array();
        $all_players = array();
        
        if (!empty($players_data) && is_array($players_data)) {
            foreach ($players_data as $p) {
                $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                if (!empty($name)) {
                    $all_players[] = $name;
                }
            }
        }
        
        // Fallback to single player
        if (empty($all_players)) {
            $player = $booking->player_name ?: 'Your player';
            $all_players[] = $player;
        }
        
        $player_count = count($all_players);
        $is_group = $player_count > 1;
        
        // Format player display
        if ($is_group) {
            // Multiple players - create a nice list
            $player_display_html = '';
            foreach ($all_players as $idx => $p_name) {
                $num = $idx + 1;
                $player_display_html .= '<span style="display:inline-block;background:rgba(252,185,0,0.1);border:1px solid rgba(252,185,0,0.3);padding:4px 10px;border-radius:4px;margin:2px 4px 2px 0;font-size:14px;"><strong style="color:' . self::GOLD . ';">' . $num . '.</strong> ' . esc_html($p_name) . '</span>';
            }
            $player_section = '
                <p style="margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af;">Players (' . $player_count . ')</p>
                <div style="margin-top: 8px;">' . $player_display_html . '</div>';
            $player_preview = $player_count . ' players';
            $player_subject = $all_players[0] . ' + ' . ($player_count - 1) . ' more';
        } else {
            $player = $all_players[0];
            $player_section = '
                <p style="margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af;">Player</p>
                <p style="margin: 4px 0 0; font-size: 15px; font-weight: 600; color: ' . self::BLACK . ';">' . esc_html($player) . '</p>';
            $player_preview = $player;
            $player_subject = $player;
        }
        
        // v235.5: Package info for email
        $pkg_name = '';
        $pkg_sessions = 1;
        $pkg_key_email = $booking->package_type ?? ($booking->session_type ?? 'single');
        if (class_exists('PTP_Packages')) {
            $pkg_def = PTP_Packages::get($pkg_key_email);
            $pkg_name = $pkg_def['name'] ?? '';
            $pkg_sessions = $pkg_def['sessions'] ?? 1;
        } else {
            $pkg_map = array('single' => '1 Session', 'pack3' => '3-Pack', 'pack5' => '5-Pack', 'pack10' => '10-Pack');
            $pkg_name = $pkg_map[$pkg_key_email] ?? '1 Session';
            $ses_map = array('single' => 1, 'pack3' => 3, 'pack5' => 5, 'pack10' => 10);
            $pkg_sessions = $ses_map[$pkg_key_email] ?? 1;
        }
        $show_package_info = ($pkg_sessions > 1);
        
        $content = '
        <!-- Hero -->
        <tr>
            <td style="background: linear-gradient(135deg, ' . self::BLACK . ' 0%, #1a1a1a 100%); padding: 40px 32px; text-align: center; border-radius: 16px 16px 0 0;">
                <div style="width: 64px; height: 64px; background: rgba(34, 197, 94, 0.1); border-radius: 50%; margin: 0 auto 20px; line-height: 64px;">
                    <span style="font-size: 28px;">&#10003;</span>
                </div>
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #ffffff; text-transform: uppercase; font-family: ui-sans-serif, system-ui, sans-serif;">
                    Booking <span style="color: ' . self::GOLD . ';">Confirmed!</span>
                </h1>
                <p style="margin: 8px 0 0; color: #9ca3af; font-size: 14px;">
                    ' . ($is_group ? esc_html($player_count) . ' players\' session is all set' : esc_html($all_players[0]) . '\'s session is all set') . '
                </p>
                ' . ($show_package_info ? '<p style="margin: 6px 0 0; color: ' . self::GOLD . '; font-size: 13px; font-weight: 600;">' . esc_html($pkg_name) . ' &mdash; ' . $pkg_sessions . ' sessions purchased</p>' : '') . '
            </td>
        </tr>
        
        <!-- Session Details -->
        <tr>
            <td class="sm-px-6" style="padding: 32px;">
                <!-- Trainer Card -->
                <table style="width: 100%; background: #f9fafb; border-radius: 12px; margin-bottom: 24px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 20px;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 80px; vertical-align: top;">
                                        <img src="' . esc_url($trainer_photo) . '" width="64" height="64" alt="" style="border-radius: 50%; border: 3px solid ' . self::GOLD . ';">
                                    </td>
                                    <td style="vertical-align: top; padding-left: 16px;">
                                        <p style="margin: 0 0 4px; font-weight: 700; font-size: 16px; color: ' . self::BLACK . '; text-transform: uppercase;">
                                            ' . esc_html($trainer_name) . '
                                        </p>
                                        <p style="margin: 0; font-size: 13px; color: #6b7280;">
                                            PTP Verified Trainer
                                        </p>
                                        ' . ($trainer_phone ? '
                                        <p style="margin: 6px 0 0; font-size: 13px; color: #374151;">
                                            <span style="color: ' . self::GOLD . '; font-weight: 600;">Phone:</span>
                                            <a href="tel:' . esc_attr(preg_replace('/[^0-9+]/', '', $trainer_phone)) . '" style="color: #374151; text-decoration: none;">' . esc_html(self::format_phone($trainer_phone)) . '</a>
                                        </p>' : '') . '
                                        <a href="' . esc_url($profile_url) . '" style="display: inline-block; margin-top: 8px; font-size: 12px; color: ' . self::GOLD . '; font-weight: 600; text-decoration: none;">
                                            View Profile →
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- Details Grid -->
                <table style="width: 100%;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 16px 0; border-bottom: 1px solid #e5e7eb;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 40px; vertical-align: top;">
                                        <span style="font-size: 20px;">📅</span>
                                    </td>
                                    <td>
                                        <p style="margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af;">Date</p>
                                        <p style="margin: 4px 0 0; font-size: 15px; font-weight: 600; color: ' . self::BLACK . ';">' . esc_html($date) . '</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 0; border-bottom: 1px solid #e5e7eb;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 40px; vertical-align: top;">
                                        <span style="font-size: 20px;">⏰</span>
                                    </td>
                                    <td>
                                        <p style="margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af;">Time</p>
                                        <p style="margin: 4px 0 0; font-size: 15px; font-weight: 600; color: ' . self::BLACK . ';">' . esc_html($time) . '</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 0; border-bottom: 1px solid #e5e7eb;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 40px; vertical-align: top;">
                                        <span style="font-size: 20px;">📍</span>
                                    </td>
                                    <td>
                                        <p style="margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af;">Location</p>
                                        <p style="margin: 4px 0 0; font-size: 15px; font-weight: 600; color: ' . self::BLACK . ';">' . esc_html($location) . '</p>
                                        ' . ($location_address ? '<p style="margin: 2px 0 0; font-size: 13px; color: #6b7280;">' . esc_html($location_address) . '</p>' : '') . '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 0;' . (!empty($booking->booking_number) ? ' border-bottom: 1px solid #e5e7eb;' : '') . '">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 40px; vertical-align: top;">
                                        <span style="font-size: 20px;">' . ($is_group ? '👥' : '⚽') . '</span>
                                    </td>
                                    <td>
                                        ' . $player_section . '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    ' . (!empty($booking->booking_number) ? '
                    <tr>
                        <td style="padding: 16px 0;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 40px; vertical-align: top;">
                                        <span style="font-size: 20px;">🎫</span>
                                    </td>
                                    <td>
                                        <p style="margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af;">Booking Code</p>
                                        <p style="margin: 4px 0 0; font-size: 18px; font-weight: 700; color: ' . self::BLACK . '; letter-spacing: 0.03em; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;">' . esc_html($booking->booking_number) . '</p>
                                        ' . ($show_package_info ? '<p style="margin: 6px 0 0; font-size: 12px; color: #6b7280;">Use this code to book your remaining ' . ($pkg_sessions - 1) . ' sessions</p>' : '<p style="margin: 6px 0 0; font-size: 12px; color: #6b7280;">Save this for your records</p>') . '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>' : '') . '
                </table>
                
                <!-- Payment -->
                <table style="width: 100%; background: #f9fafb; border-radius: 12px; margin-top: 24px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 20px;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                ' . ($is_group ? '
                                <tr>
                                    <td colspan="2" style="padding-bottom: 12px;">
                                        <span style="display:inline-block;background:rgba(252,185,0,0.15);color:#92400E;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;padding:4px 10px;border-radius:4px;">Group Session &mdash; ' . $player_count . ' Players</span>
                                    </td>
                                </tr>' : '') . '
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-size: 13px; color: #6b7280;">Amount Paid</p>
                                    </td>
                                    <td style="text-align: right;">
                                        <p style="margin: 0; font-size: 24px; font-weight: 700; color: ' . self::GREEN . ';">$' . esc_html($amount) . '</p>
                                    </td>
                                </tr>
                                ' . ($show_package_info ? '
                                <tr>
                                    <td colspan="2" style="padding-top: 6px;">
                                        <p style="margin: 0; font-size: 12px; color: #6b7280;">' . esc_html($pkg_name) . ' &mdash; ' . $pkg_sessions . ' sessions &middot; $' . number_format($booking->total_amount / $pkg_sessions, 0) . '/session</p>
                                    </td>
                                </tr>' : '') . '
                                ' . ($is_group ? '
                                <tr>
                                    <td colspan="2" style="padding-top: 8px;">
                                        <p style="margin: 0; font-size: 12px; color: #6b7280;">Group discount applied &mdash; ' . $player_count . ' players training together</p>
                                    </td>
                                </tr>' : '') . '
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- CTA -->
                <table style="width: 100%; margin-top: 32px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center;">
                            <a href="' . esc_url($dashboard_url) . '" style="display: inline-block; background: ' . self::GOLD . '; color: ' . self::BLACK . '; font-weight: 700; font-size: 14px; text-transform: uppercase; text-decoration: none; padding: 16px 40px; border-radius: 10px;">
                                View My Bookings
                            </a>
                        </td>
                    </tr>
                </table>
                
                <!-- Tips -->
                <table style="width: 100%; margin-top: 32px; border-top: 1px solid #e5e7eb;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding-top: 24px;">
                            <p style="margin: 0 0 12px; font-size: 13px; font-weight: 600; color: ' . self::BLACK . ';">Before Your Session:</p>
                            <ul style="margin: 0; padding: 0 0 0 20px; color: #6b7280; font-size: 13px; line-height: 1.8;">
                                <li>' . ($is_group ? 'Each player should bring' : 'Bring') . ' a ball, water, and cleats</li>
                                <li>Arrive 5 minutes early</li>
                                ' . ($is_group ? '<li>All ' . $player_count . ' players should be ready at the same time</li>' : '') . '
                                <li>Message your trainer if plans change</li>
                            </ul>
                        </td>
                    </tr>
                </table>
                
                <!-- v116: Share/Referral Section -->
                ' . self::get_email_share_section($parent, $trainer_name) . '
            </td>
        </tr>';
        
        $subject = "Booking Confirmed - {$player_subject} with {$trainer_name}";
        $preview = ($is_group ? $player_count . " players' " : $player_preview . "'s ") . "training session on {$date} at {$time} is confirmed!";
        
        return self::instance()->wrap_in_template($content, $subject, $preview);
    }
    
    /**
     * Session Reminder Email (24 hours before)
     */
    public static function session_reminder($booking, $trainer, $parent) {
        $raw_date = $booking->session_date ?? '';
        $date = (!empty($raw_date) && $raw_date !== '0000-00-00' && strtotime($raw_date) > 0) ? date('l, F j', strtotime($raw_date)) : 'TBD';
        $raw_time = $booking->start_time ?? '';
        // v220: Normalize time (safety net for legacy values)
        if (!empty($raw_time) && function_exists('ptp_normalize_session_time')) {
            $raw_time = ptp_normalize_session_time($raw_time) ?: $raw_time;
        }
        $time = (!empty($raw_time) && $raw_time !== '00:00:00') ? date('g:i A', strtotime($raw_time)) : 'TBD';
        // v225: Prefer the location parent selected during checkout
        $location = '';
        if (!empty($booking->location)) {
            $location = $booking->location;
        } elseif (!empty($booking->trainer_location)) {
            $location = $booking->trainer_location;
        } elseif (!empty($booking->trainer_city)) {
            $location = $booking->trainer_city . (!empty($booking->trainer_state) ? ', ' . $booking->trainer_state : '');
        } elseif (!empty($trainer->location)) {
            $location = $trainer->location;
        }
        // v230: Fallback to training_locations JSON
        if (empty($location) && !empty($booking->trainer_id)) {
            global $wpdb;
            $tl_raw = $wpdb->get_var($wpdb->prepare(
                "SELECT training_locations FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $booking->trainer_id
            ));
            if (!empty($tl_raw)) {
                $tl = json_decode($tl_raw, true);
                if (!is_array($tl)) $tl = json_decode(wp_unslash($tl_raw), true);
                if (is_array($tl)) {
                    foreach ($tl as $loc) {
                        if (is_array($loc) && !empty($loc['name'])) { $location = $loc['name']; break; }
                    }
                }
            }
        }
        if (empty($location)) {
            $location = 'Location TBD';
        }
        // v236: Extract address from location_notes for trainer email
        $location_address = '';
        if (!empty($booking->location_notes)) {
            $notes = $booking->location_notes;
            $addr = preg_replace('/\s*\|\s*GPS:.*$/i', '', $notes);
            if ($addr && $addr !== $location) {
                $location_address = trim($addr);
            }
        }
        $trainer_name = $trainer->display_name;
        $trainer_phone = $trainer->phone ?: '';
        $dashboard_url = home_url('/my-training/');
        
        // v132: Support multi-player sessions
        $players_data = isset($booking->players_data) ? $booking->players_data : array();
        $all_players = array();
        
        if (!empty($players_data) && is_array($players_data)) {
            foreach ($players_data as $p) {
                $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                if (!empty($name)) {
                    $all_players[] = $name;
                }
            }
        }
        
        // Fallback to single player
        if (empty($all_players)) {
            $player = $booking->player_name ?: 'Your player';
            $all_players[] = $player;
        }
        
        $player_count = count($all_players);
        $is_group = $player_count > 1;
        $player_text = $is_group ? ($player_count . " players' training") : (esc_html($all_players[0]) . "'s training");
        
        $content = '
        <!-- Hero -->
        <tr>
            <td style="background: linear-gradient(135deg, ' . self::GOLD . ' 0%, #E5A800 100%); padding: 40px 32px; text-align: center; border-radius: 16px 16px 0 0;">
                <div style="width: 64px; height: 64px; background: rgba(255,255,255,0.2); border-radius: 50%; margin: 0 auto 20px; line-height: 64px;">
                    <span style="font-size: 28px;">⚽</span>
                </div>
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: ' . self::BLACK . '; text-transform: uppercase;">
                    Session Tomorrow!
                </h1>
                <p style="margin: 8px 0 0; color: rgba(0,0,0,0.6); font-size: 14px;">
                    ' . $player_text . ' is almost here
                </p>
            </td>
        </tr>
        
        <!-- Details -->
        <tr>
            <td class="sm-px-6" style="padding: 32px;">
                <table style="width: 100%; background: #f9fafb; border-radius: 12px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: #9ca3af;">When</p>
                            <p style="margin: 0 0 16px; font-size: 18px; font-weight: 700; color: ' . self::BLACK . ';">' . esc_html($date) . ' at ' . esc_html($time) . '</p>
                            
                            <p style="margin: 0 0 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: #9ca3af;">Where</p>
                            <p style="margin: 0 0 ' . ($location_address ? '2' : '16') . 'px; font-size: 15px; color: ' . self::BLACK . ';">' . esc_html($location) . '</p>
                            ' . ($location_address ? '<p style="margin: 0 0 16px; font-size: 13px; color: #6b7280;">' . esc_html($location_address) . '</p>' : '') . '
                            
                            <p style="margin: 0 0 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: #9ca3af;">Trainer</p>
                            <p style="margin: 0 0 16px; font-size: 15px; font-weight: 600; color: ' . self::BLACK . ';">' . esc_html($trainer_name) . '</p>
                            
                            <p style="margin: 0 0 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: #9ca3af;">' . ($is_group ? 'Players (' . $player_count . ')' : 'Player') . '</p>';
        
        if ($is_group) {
            $content .= '<div style="margin-top: 8px;">';
            foreach ($all_players as $idx => $p_name) {
                $num = $idx + 1;
                $content .= '<span style="display:inline-block;background:rgba(252,185,0,0.15);border:1px solid rgba(252,185,0,0.4);padding:4px 10px;border-radius:4px;margin:2px 4px 2px 0;font-size:14px;"><strong style="color:#92400E;">' . $num . '.</strong> ' . esc_html($p_name) . '</span>';
            }
            $content .= '</div>';
        } else {
            $content .= '<p style="margin: 0; font-size: 15px; color: ' . self::BLACK . ';">' . esc_html($all_players[0]) . '</p>';
        }
        
        $content .= '
                        </td>
                    </tr>
                </table>
                
                <!-- Checklist -->
                <table style="width: 100%; margin-top: 24px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td>
                            <p style="margin: 0 0 12px; font-size: 14px; font-weight: 600; color: ' . self::BLACK . ';">Don\'t forget to bring:</p>
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="color: ' . self::GREEN . '; margin-right: 8px;">✓</span>
                                        <span style="font-size: 14px; color: #4b5563;">Soccer ball</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="color: ' . self::GREEN . '; margin-right: 8px;">✓</span>
                                        <span style="font-size: 14px; color: #4b5563;">Water bottle</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="color: ' . self::GREEN . '; margin-right: 8px;">✓</span>
                                        <span style="font-size: 14px; color: #4b5563;">Cleats & shin guards</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- CTA -->
                <table style="width: 100%; margin-top: 32px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center;">
                            <a href="' . esc_url($dashboard_url) . '" style="display: inline-block; background: ' . self::BLACK . '; color: #ffffff; font-weight: 700; font-size: 14px; text-transform: uppercase; text-decoration: none; padding: 16px 40px; border-radius: 10px;">
                                View Details
                            </a>
                        </td>
                    </tr>
                </table>
                
                <!-- Need to reschedule -->
                <table style="width: 100%; margin-top: 24px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center;">
                            <p style="margin: 0; font-size: 13px; color: #6b7280;">
                                Need to reschedule? <a href="' . esc_url($dashboard_url) . '" style="color: ' . self::GOLD . '; font-weight: 600;">Contact us</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>';
        
        $subject = "Tomorrow: {$player}'s Training with {$trainer_name}";
        $preview = "Don't forget - {$player} has training tomorrow at {$time}!";
        
        return self::instance()->wrap_in_template($content, $subject, $preview);
    }
    
    /**
     * Review Request Email (after session)
     */
    public static function review_request($booking, $trainer, $parent) {
        $player = $booking->player_name ?: 'Your player';
        $trainer_name = $trainer->display_name;
        $trainer_photo = $trainer->photo_url ?: 'https://via.placeholder.com/80';
        $review_url = home_url('/review/?booking=' . $booking->id);
        
        $content = '
        <!-- Hero -->
        <tr>
            <td style="padding: 40px 32px; text-align: center;">
                <h1 style="margin: 0; font-size: 24px; font-weight: 700; color: ' . self::BLACK . ';">
                    How was ' . esc_html($player) . '\'s session?
                </h1>
                <p style="margin: 12px 0 0; color: #6b7280; font-size: 15px;">
                    Your feedback helps other families find great trainers
                </p>
            </td>
        </tr>
        
        <!-- Trainer -->
        <tr>
            <td class="sm-px-6" style="padding: 0 32px 32px;">
                <table style="width: 100%; background: #f9fafb; border-radius: 12px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 24px; text-align: center;">
                            <img src="' . esc_url($trainer_photo) . '" width="80" height="80" alt="" style="border-radius: 50%; border: 3px solid ' . self::GOLD . '; margin-bottom: 16px;">
                            <p style="margin: 0; font-size: 18px; font-weight: 700; color: ' . self::BLACK . '; text-transform: uppercase;">
                                ' . esc_html($trainer_name) . '
                            </p>
                        </td>
                    </tr>
                </table>
                
                <!-- Stars -->
                <table style="width: 100%; margin-top: 24px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center;">
                            <p style="margin: 0 0 16px; font-size: 14px; color: #6b7280;">Tap to rate your experience</p>
                            <a href="' . esc_url($review_url) . '" style="text-decoration: none;">
                                <span style="font-size: 36px; letter-spacing: 8px; color: #d1d5db;">★★★★★</span>
                            </a>
                        </td>
                    </tr>
                </table>
                
                <!-- CTA -->
                <table style="width: 100%; margin-top: 32px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center;">
                            <a href="' . esc_url($review_url) . '" style="display: inline-block; background: ' . self::GOLD . '; color: ' . self::BLACK . '; font-weight: 700; font-size: 14px; text-transform: uppercase; text-decoration: none; padding: 16px 40px; border-radius: 10px;">
                                Leave a Review
                            </a>
                        </td>
                    </tr>
                </table>
                
                <table style="width: 100%; margin-top: 24px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                                Takes less than 30 seconds
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>';
        
        $subject = "How was {$player}'s session with {$trainer_name}?";
        $preview = "Share your feedback - it only takes 30 seconds";
        
        return self::instance()->wrap_in_template($content, $subject, $preview);
    }
    
    /**
     * Payout Notification Email (for trainers)
     */
    public static function payout_notification($trainer, $amount, $method = 'direct_deposit') {
        $trainer_name = explode(' ', $trainer->display_name)[0];
        $dashboard_url = home_url('/trainer-dashboard/');
        $method_label = $method === 'instant' ? 'Instant Transfer' : 'Direct Deposit';
        $arrival = $method === 'instant' ? 'within minutes' : 'in 1-2 business days';
        
        $content = '
        <!-- Hero -->
        <tr>
            <td style="background: linear-gradient(135deg, ' . self::GREEN . ' 0%, #16A34A 100%); padding: 40px 32px; text-align: center; border-radius: 16px 16px 0 0;">
                <div style="width: 64px; height: 64px; background: rgba(255,255,255,0.2); border-radius: 50%; margin: 0 auto 20px; line-height: 64px;">
                    <span style="font-size: 28px;">💰</span>
                </div>
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #ffffff; text-transform: uppercase;">
                    Payout Sent!
                </h1>
                <p style="margin: 8px 0 0; color: rgba(255,255,255,0.8); font-size: 14px;">
                    Your earnings are on the way, ' . esc_html($trainer_name) . '
                </p>
            </td>
        </tr>
        
        <!-- Amount -->
        <tr>
            <td class="sm-px-6" style="padding: 32px; text-align: center;">
                <p style="margin: 0 0 8px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: #9ca3af;">Amount</p>
                <p style="margin: 0; font-size: 48px; font-weight: 700; color: ' . self::GREEN . ';">$' . esc_html(number_format($amount, 2)) . '</p>
                
                <table style="width: 100%; max-width: 300px; margin: 32px auto 0; background: #f9fafb; border-radius: 12px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 20px;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-size: 12px; color: #6b7280;">Method</p>
                                        <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600; color: ' . self::BLACK . ';">' . esc_html($method_label) . '</p>
                                    </td>
                                    <td style="text-align: right;">
                                        <p style="margin: 0; font-size: 12px; color: #6b7280;">Expected</p>
                                        <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600; color: ' . self::BLACK . ';">' . esc_html(ucfirst($arrival)) . '</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- CTA -->
                <table style="width: 100%; margin-top: 32px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center;">
                            <a href="' . esc_url($dashboard_url) . '" style="display: inline-block; background: ' . self::BLACK . '; color: #ffffff; font-weight: 700; font-size: 14px; text-transform: uppercase; text-decoration: none; padding: 16px 40px; border-radius: 10px;">
                                View Dashboard
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>';
        
        $subject = "Payout Sent: \${$amount}";
        $preview = "Your earnings of \${$amount} are on the way!";
        
        return self::instance()->wrap_in_template($content, $subject, $preview);
    }
    
    /**
     * Welcome Email (new parent signup)
     */
    public static function welcome_parent($user, $parent) {
        $first_name = $user->first_name ?: explode(' ', $user->display_name)[0];
        $find_trainers_url = home_url('/find-trainers/');
        $camps_url = home_url('/ptp-find-a-camp/');
        
        $content = '
        <!-- Hero -->
        <tr>
            <td style="background: linear-gradient(135deg, ' . self::BLACK . ' 0%, #1a1a1a 100%); padding: 48px 32px; text-align: center; border-radius: 16px 16px 0 0;">
                <h1 style="margin: 0; font-size: 32px; font-weight: 700; color: #ffffff; text-transform: uppercase;">
                    Welcome to <span style="color: ' . self::GOLD . ';">PTP!</span>
                </h1>
                <p style="margin: 12px 0 0; color: #9ca3af; font-size: 15px;">
                    Teaching What Team Coaches Don\'t
                </p>
            </td>
        </tr>
        
        <!-- Content -->
        <tr>
            <td class="sm-px-6" style="padding: 32px;">
                <p style="margin: 0 0 20px; font-size: 16px; color: ' . self::BLACK . ';">
                    Hey ' . esc_html($first_name) . '! 👋
                </p>
                <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.7;">
                    Welcome to the PTP family! We\'re excited to help your player develop their skills with our verified coaches - current NCAA D1 athletes and elite college players.
                </p>
                
                <!-- What makes us different -->
                <table style="width: 100%; background: #f9fafb; border-radius: 12px; margin-bottom: 24px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 14px; font-weight: 700; color: ' . self::BLACK . '; text-transform: uppercase;">What Makes PTP Different</p>
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="color: ' . self::GOLD . '; margin-right: 10px;">⚽</span>
                                        <span style="font-size: 14px; color: #4b5563;">Pro & D1 college coaches only</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="color: ' . self::GOLD . '; margin-right: 10px;">📍</span>
                                        <span style="font-size: 14px; color: #4b5563;">Training at your preferred location</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="color: ' . self::GOLD . '; margin-right: 10px;">📱</span>
                                        <span style="font-size: 14px; color: #4b5563;">Easy booking & communication</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 0;">
                                        <span style="color: ' . self::GOLD . '; margin-right: 10px;">🏆</span>
                                        <span style="font-size: 14px; color: #4b5563;">Individual skill development focus</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- CTAs -->
                <table style="width: 100%;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center; padding-bottom: 16px;">
                            <a href="' . esc_url($find_trainers_url) . '" style="display: inline-block; background: ' . self::GOLD . '; color: ' . self::BLACK . '; font-weight: 700; font-size: 14px; text-transform: uppercase; text-decoration: none; padding: 16px 40px; border-radius: 10px; width: 80%; max-width: 280px; box-sizing: border-box;">
                                Find a Trainer
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align: center;">
                            <a href="' . esc_url($camps_url) . '" style="display: inline-block; background: #f3f4f6; color: ' . self::BLACK . '; font-weight: 600; font-size: 14px; text-decoration: none; padding: 14px 32px; border-radius: 10px;">
                                View Summer Camps →
                            </a>
                        </td>
                    </tr>
                </table>
                
                <!-- Help -->
                <table style="width: 100%; margin-top: 32px; border-top: 1px solid #e5e7eb;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding-top: 24px; text-align: center;">
                            <p style="margin: 0; font-size: 13px; color: #6b7280;">
                                Questions? Just reply to this email' . (ptp_email_brand('support_phone') ? ' or text us at<br>
                                <strong style="color: ' . self::BLACK . ';">' . esc_html(ptp_email_brand('support_phone')) . '</strong>' : '') . '
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>';
        
        $subject = "Welcome to PTP, {$first_name}! ⚽";
        $preview = "Find your perfect trainer and start developing skills today";
        
        return self::instance()->wrap_in_template($content, $subject, $preview);
    }
    
    /**
     * Trainer Application Approved Email
     */
    public static function trainer_approved($user, $trainer) {
        $first_name = explode(' ', $trainer->display_name)[0];
        $dashboard_url = home_url('/trainer-dashboard/');
        $onboarding_url = home_url('/trainer-onboarding/');
        
        $content = '
        <!-- Hero -->
        <tr>
            <td style="background: linear-gradient(135deg, ' . self::GREEN . ' 0%, #16A34A 100%); padding: 48px 32px; text-align: center; border-radius: 16px 16px 0 0;">
                <div style="width: 72px; height: 72px; background: rgba(255,255,255,0.2); border-radius: 50%; margin: 0 auto 20px; line-height: 72px;">
                    <span style="font-size: 32px;">🎉</span>
                </div>
                <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #ffffff; text-transform: uppercase;">
                    You\'re Approved!
                </h1>
                <p style="margin: 12px 0 0; color: rgba(255,255,255,0.9); font-size: 15px;">
                    Welcome to the PTP trainer team, ' . esc_html($first_name) . '
                </p>
            </td>
        </tr>
        
        <!-- Content -->
        <tr>
            <td class="sm-px-6" style="padding: 32px;">
                <p style="margin: 0 0 24px; font-size: 15px; color: #4b5563; line-height: 1.7;">
                    Congrats! Your application has been approved. You\'re now part of our team of elite trainers. Here\'s what to do next:
                </p>
                
                <!-- Steps -->
                <table style="width: 100%;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 16px 0; border-bottom: 1px solid #e5e7eb;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 40px; vertical-align: top;">
                                        <span style="display: inline-block; width: 28px; height: 28px; background: ' . self::GOLD . '; color: ' . self::BLACK . '; border-radius: 50%; text-align: center; line-height: 28px; font-weight: 700; font-size: 14px;">1</span>
                                    </td>
                                    <td>
                                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: ' . self::BLACK . ';">Complete Your Profile</p>
                                        <p style="margin: 4px 0 0; font-size: 13px; color: #6b7280;">Add your photo, bio, and credentials</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 0; border-bottom: 1px solid #e5e7eb;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 40px; vertical-align: top;">
                                        <span style="display: inline-block; width: 28px; height: 28px; background: ' . self::GOLD . '; color: ' . self::BLACK . '; border-radius: 50%; text-align: center; line-height: 28px; font-weight: 700; font-size: 14px;">2</span>
                                    </td>
                                    <td>
                                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: ' . self::BLACK . ';">Set Your Availability</p>
                                        <p style="margin: 4px 0 0; font-size: 13px; color: #6b7280;">Choose when you\'re available to train</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 0;">
                            <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width: 40px; vertical-align: top;">
                                        <span style="display: inline-block; width: 28px; height: 28px; background: ' . self::GOLD . '; color: ' . self::BLACK . '; border-radius: 50%; text-align: center; line-height: 28px; font-weight: 700; font-size: 14px;">3</span>
                                    </td>
                                    <td>
                                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: ' . self::BLACK . ';">Connect Stripe</p>
                                        <p style="margin: 4px 0 0; font-size: 13px; color: #6b7280;">Set up payments to get paid directly</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <!-- CTA -->
                <table style="width: 100%; margin-top: 32px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="text-align: center;">
                            <a href="' . esc_url($onboarding_url) . '" style="display: inline-block; background: ' . self::GOLD . '; color: ' . self::BLACK . '; font-weight: 700; font-size: 14px; text-transform: uppercase; text-decoration: none; padding: 16px 40px; border-radius: 10px;">
                                Complete Setup
                            </a>
                        </td>
                    </tr>
                </table>
                
                <!-- Earnings info -->
                <table style="width: 100%; background: #f9fafb; border-radius: 12px; margin-top: 32px;" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 24px; text-align: center;">
                            <p style="margin: 0 0 8px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: #9ca3af;">Tiered Earnings</p>
                            <p style="margin: 0; font-size: 24px; font-weight: 700; color: #D97706;">50%</p>
                            <p style="margin: 4px 0 0; font-size: 11px; color: #6b7280;">first session with new client</p>
                            <p style="margin: 12px 0 0; font-size: 36px; font-weight: 700; color: ' . self::GREEN . ';">75%</p>
                            <p style="margin: 4px 0 0; font-size: 11px; color: #6b7280;">repeat sessions</p>
                            <p style="margin: 16px 0 0; font-size: 12px; color: #9ca3af;">Build relationships, earn more!</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>';
        
        $subject = "You're Approved! Welcome to PTP 🎉";
        $preview = "Complete your profile and start training today";
        
        return self::instance()->wrap_in_template($content, $subject, $preview);
    }
    
    /**
     * Send email using template
     */
    public static function send($to, $subject, $template_html) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ptp_email_brand('from_training'),
        );
        
        return wp_mail($to, $subject, $template_html, $headers);
    }
    
    /**
     * v116: Get email share/referral section HTML
     * Adds "Forward to a friend" with referral link
     */
    public static function get_email_share_section($parent, $trainer_name = '') {
        // Get referral link - USE PTP_Referral_System first for checkout discount to work
        $user_id = is_object($parent) && isset($parent->user_id) ? $parent->user_id : 0;
        if (!$user_id) return '';
        
        $referral_code = '';
        if (class_exists('PTP_Referral_System')) {
            // This creates table record required for checkout discount
            $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
        }
        if (!$referral_code) {
            // Fallback to user meta (discount may not work)
            $referral_code = get_user_meta($user_id, 'ptp_referral_code', true);
        }
        if (!$referral_code) {
            $referral_code = strtoupper(substr(md5($user_id . 'ptp'), 0, 8));
        }
        
        $referral_link = home_url('/?ref=' . $referral_code);
        $trainer_first = $trainer_name ? explode(' ', $trainer_name)[0] : 'a trainer';
        
        return '
                <table style="width: 100%; margin-top: 32px; background: linear-gradient(135deg, #0A0A0A 0%, #1a1a1a 100%); border-radius: 12px; border: 2px solid ' . self::GOLD . ';" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding: 24px; text-align: center;">
                            <p style="margin: 0 0 8px; font-size: 18px; font-weight: 700; color: #ffffff;">📣 Know another soccer family?</p>
                            <p style="margin: 0 0 16px; font-size: 14px; color: #9ca3af;">
                                Share your trainer with friends — they get <strong style="color: ' . self::GOLD . ';">20% off</strong>, you get <strong style="color: ' . self::GOLD . ';">$25 credit</strong>
                            </p>
                            
                            <table style="width: 100%; max-width: 360px; margin: 0 auto;" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background: #222; border-radius: 8px; padding: 12px 16px;">
                                        <p style="margin: 0; font-size: 13px; color: #9ca3af; word-break: break-all;">
                                            <a href="' . esc_url($referral_link) . '" style="color: ' . self::GOLD . '; text-decoration: none;">' . esc_html($referral_link) . '</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="margin: 16px 0 0; font-size: 12px; color: #6b7280;">
                                Forward this email or share your link — message is pre-written!
                            </p>
                        </td>
                    </tr>
                </table>';
    }
}

// Initialize
PTP_Email_Templates::instance();
