<?php
/**
 * PTP Tax Reporting - 1099-NEC Generation
 * Handles IRS compliance for independent contractor payments
 */

defined('ABSPATH') || exit;

class PTP_Tax_Reporting {
    
    /**
     * Initialize tax reporting
     */
    public static function init() {
        // Add admin menu for tax reporting
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'), 20);
        
        // AJAX handlers
        add_action('wp_ajax_ptp_export_1099_data', array(__CLASS__, 'export_1099_data'));
        add_action('wp_ajax_ptp_generate_1099_preview', array(__CLASS__, 'generate_1099_preview'));
    }
    
    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'ptp-training',
            '1099 Tax Reports',
            '1099 Reports',
            'manage_ptp_payments',
            'ptp-tax-reports',
            array(__CLASS__, 'render_admin_page')
        );
    }
    
    /**
     * Get trainers who earned $600+ in a tax year
     */
    public static function get_1099_eligible_trainers($year = null) {
        global $wpdb;
        
        if (!$year) {
            $year = date('Y') - 1; // Default to previous year
        }
        
        $start_date = $year . '-01-01 00:00:00';
        $end_date = $year . '-12-31 23:59:59';
        
        // Get trainers with $600+ in completed payouts
        $trainers = $wpdb->get_results($wpdb->prepare("
            SELECT 
                t.id,
                t.user_id,
                t.display_name,
                t.legal_name,
                t.email,
                t.tax_id_last4,
                t.tax_id_type,
                t.tax_address_line1,
                t.tax_address_line2,
                t.tax_city,
                t.tax_state,
                t.tax_zip,
                t.w9_submitted,
                COALESCE(SUM(p.amount), 0) as total_paid
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->prefix}ptp_payouts p ON t.id = p.trainer_id 
                AND p.status = 'completed'
                AND p.processed_at BETWEEN %s AND %s
            GROUP BY t.id
            HAVING total_paid >= 600
            ORDER BY total_paid DESC
        ", $start_date, $end_date));
        
        return $trainers;
    }
    
    /**
     * Get trainer's annual earnings breakdown
     */
    public static function get_trainer_annual_earnings($trainer_id, $year) {
        global $wpdb;
        
        $start_date = $year . '-01-01 00:00:00';
        $end_date = $year . '-12-31 23:59:59';
        
        // Monthly breakdown
        $monthly = $wpdb->get_results($wpdb->prepare("
            SELECT 
                MONTH(processed_at) as month,
                SUM(amount) as total
            FROM {$wpdb->prefix}ptp_payouts
            WHERE trainer_id = %d
            AND status = 'completed'
            AND processed_at BETWEEN %s AND %s
            GROUP BY MONTH(processed_at)
            ORDER BY month
        ", $trainer_id, $start_date, $end_date));
        
        // Total
        $total = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM {$wpdb->prefix}ptp_payouts
            WHERE trainer_id = %d
            AND status = 'completed'
            AND processed_at BETWEEN %s AND %s
        ", $trainer_id, $start_date, $end_date));
        
        return array(
            'monthly' => $monthly,
            'total' => floatval($total)
        );
    }
    
    /**
     * Export 1099 data as CSV
     */
    public static function export_1099_data() {
        if (!current_user_can('manage_ptp_payments')) {
            wp_die('Unauthorized');
        }
        
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        $year = intval($_POST['year'] ?? (date('Y') - 1));
        $trainers = self::get_1099_eligible_trainers($year);
        
        // Build CSV
        $csv = array();
        $csv[] = array(
            'Trainer ID',
            'Legal Name',
            'Display Name',
            'Email',
            'Tax ID Type',
            'Tax ID (Last 4)',
            'Address Line 1',
            'Address Line 2',
            'City',
            'State',
            'ZIP',
            'Total Paid',
            'W-9 Submitted',
            '1099 Status'
        );
        
        foreach ($trainers as $t) {
            $csv[] = array(
                $t->id,
                $t->legal_name ?: $t->display_name,
                $t->display_name,
                $t->email,
                strtoupper($t->tax_id_type ?: 'SSN'),
                $t->tax_id_last4 ? '***-**-' . $t->tax_id_last4 : 'MISSING',
                $t->tax_address_line1 ?: 'MISSING',
                $t->tax_address_line2 ?: '',
                $t->tax_city ?: 'MISSING',
                $t->tax_state ?: 'MISSING',
                $t->tax_zip ?: 'MISSING',
                number_format($t->total_paid, 2),
                $t->w9_submitted ? 'Yes' : 'No',
                ($t->w9_submitted && $t->tax_id_last4 && $t->tax_address_line1) ? 'Ready' : 'Missing Info'
            );
        }
        
        // Output CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="ptp-1099-data-' . $year . '.csv"');
        
        $output = fopen('php://output', 'w');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
    
    /**
     * Generate IRS-compatible 1099-NEC data file
     * This creates a file format that can be imported into tax software
     */
    public static function generate_irs_file($year) {
        $trainers = self::get_1099_eligible_trainers($year);
        
        // IRS requires specific formatting
        // This is a simplified version - real implementation needs full IRS spec
        $data = array(
            'tax_year' => $year,
            'payer' => array(
                'name' => get_option('ptp_company_name', 'Players Teaching Players LLC'),
                'ein' => get_option('ptp_company_ein', ''),
                'address' => get_option('ptp_company_address', ''),
                'city' => get_option('ptp_company_city', ''),
                'state' => get_option('ptp_company_state', ''),
                'zip' => get_option('ptp_company_zip', ''),
            ),
            'recipients' => array()
        );
        
        foreach ($trainers as $t) {
            if (!$t->w9_submitted || !$t->tax_id_last4) {
                continue; // Skip trainers without W-9 info
            }
            
            $data['recipients'][] = array(
                'name' => $t->legal_name ?: $t->display_name,
                'tin_type' => $t->tax_id_type === 'ein' ? '1' : '0',
                'tin_last4' => $t->tax_id_last4,
                'address' => $t->tax_address_line1,
                'city' => $t->tax_city,
                'state' => $t->tax_state,
                'zip' => $t->tax_zip,
                'amount' => $t->total_paid,
                'box1_nec' => $t->total_paid, // Box 1: Nonemployee compensation
            );
        }
        
        return $data;
    }
    
    /**
     * Get summary stats for tax year
     */
    public static function get_year_summary($year) {
        global $wpdb;
        
        $start_date = $year . '-01-01 00:00:00';
        $end_date = $year . '-12-31 23:59:59';
        
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(DISTINCT trainer_id) as trainers_paid,
                COALESCE(SUM(amount), 0) as total_paid,
                COUNT(*) as payout_count
            FROM {$wpdb->prefix}ptp_payouts
            WHERE status = 'completed'
            AND processed_at BETWEEN %s AND %s
        ", $start_date, $end_date));
        
        $eligible = count(self::get_1099_eligible_trainers($year));
        
        return array(
            'trainers_paid' => intval($stats->trainers_paid ?? 0),
            'total_paid' => floatval($stats->total_paid ?? 0),
            'payout_count' => intval($stats->payout_count ?? 0),
            'trainers_1099_eligible' => $eligible,
            'threshold' => 600
        );
    }
    
    /**
     * Get trainers with missing tax info
     */
    public static function get_trainers_missing_tax_info($year = null) {
        $trainers = self::get_1099_eligible_trainers($year);
        $missing = array();
        
        foreach ($trainers as $t) {
            $issues = array();
            
            if (!$t->legal_name) $issues[] = 'Legal name';
            if (!$t->tax_id_last4) $issues[] = 'Tax ID';
            if (!$t->tax_address_line1) $issues[] = 'Address';
            if (!$t->tax_city) $issues[] = 'City';
            if (!$t->tax_state) $issues[] = 'State';
            if (!$t->tax_zip) $issues[] = 'ZIP';
            if (!$t->w9_submitted) $issues[] = 'W-9 certification';
            
            if (!empty($issues)) {
                $t->missing_fields = $issues;
                $missing[] = $t;
            }
        }
        
        return $missing;
    }
    
    /**
     * Render admin page
     */
    public static function render_admin_page() {
        $current_year = date('Y');
        $year = intval($_GET['year'] ?? ($current_year - 1));
        $years = range($current_year, $current_year - 5);
        
        $summary = self::get_year_summary($year);
        $trainers = self::get_1099_eligible_trainers($year);
        $missing_info = self::get_trainers_missing_tax_info($year);
        
        ?>
        <div class="wrap">
            <h1>📋 1099-NEC Tax Reports</h1>
            
            <div style="background: #fff; border-radius: 8px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="margin-top: 0;">Tax Year: 
                    <select id="tax-year-select" onchange="window.location.href='?page=ptp-tax-reports&year=' + this.value">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo $y; ?>" <?php selected($year, $y); ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                </h2>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                    <div style="background: #F3F4F6; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 32px; font-weight: 700; color: #111827;">$<?php echo number_format($summary['total_paid'], 0); ?></div>
                        <div style="color: #6B7280; font-size: 14px;">Total Paid to Trainers</div>
                    </div>
                    <div style="background: #F3F4F6; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 32px; font-weight: 700; color: #111827;"><?php echo $summary['trainers_paid']; ?></div>
                        <div style="color: #6B7280; font-size: 14px;">Trainers Paid</div>
                    </div>
                    <div style="background: #FEF3C7; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 32px; font-weight: 700; color: #92400E;"><?php echo $summary['trainers_1099_eligible']; ?></div>
                        <div style="color: #92400E; font-size: 14px;">1099-NEC Required ($600+)</div>
                    </div>
                    <div style="background: <?php echo count($missing_info) > 0 ? '#FEE2E2' : '#D1FAE5'; ?>; padding: 20px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 32px; font-weight: 700; color: <?php echo count($missing_info) > 0 ? '#DC2626' : '#065F46'; ?>;"><?php echo count($missing_info); ?></div>
                        <div style="color: <?php echo count($missing_info) > 0 ? '#DC2626' : '#065F46'; ?>; font-size: 14px;">Missing Tax Info</div>
                    </div>
                </div>
            </div>
            
            <?php if (count($missing_info) > 0): ?>
            <div style="background: #FEF2F2; border: 1px solid #FECACA; border-radius: 8px; padding: 20px; margin: 20px 0;">
                <h3 style="color: #DC2626; margin-top: 0;">⚠️ Trainers Missing Tax Information</h3>
                <p>The following trainers earned $600+ but are missing required W-9 information. They need to complete their tax info before you can issue 1099s.</p>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>Trainer</th>
                            <th>Email</th>
                            <th>Total Paid</th>
                            <th>Missing</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($missing_info as $t): ?>
                        <tr>
                            <td><strong><?php echo esc_html($t->display_name); ?></strong></td>
                            <td><?php echo esc_html($t->email); ?></td>
                            <td>$<?php echo number_format($t->total_paid, 2); ?></td>
                            <td><span style="color: #DC2626;"><?php echo implode(', ', $t->missing_fields); ?></span></td>
                            <td>
                                <a href="mailto:<?php echo esc_attr($t->email); ?>?subject=Action Required: Complete Your Tax Information&body=Hi <?php echo esc_attr($t->display_name); ?>,%0A%0AYou earned $<?php echo number_format($t->total_paid, 2); ?> with PTP in <?php echo $year; ?>, which means we need to issue you a 1099-NEC form.%0A%0APlease log in to your account and complete your tax information as soon as possible:%0A<?php echo home_url('/account/?tab=tax'); ?>%0A%0AThank you!" class="button">Send Reminder</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div style="background: #fff; border-radius: 8px; padding: 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0;">1099-NEC Eligible Trainers (≥$600)</h3>
                
                <div style="margin-bottom: 20px;">
                    <form method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" style="display: inline;">
                        <input type="hidden" name="action" value="ptp_export_1099_data">
                        <input type="hidden" name="year" value="<?php echo $year; ?>">
                        <?php wp_nonce_field('ptp_admin_nonce', 'nonce'); ?>
                        <button type="submit" class="button button-primary">📥 Export 1099 Data (CSV)</button>
                    </form>
                    <span style="color: #6B7280; margin-left: 10px; font-size: 13px;">Download data for import into your tax software</span>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Trainer</th>
                            <th>Legal Name</th>
                            <th>Tax ID</th>
                            <th>Address</th>
                            <th>Total Paid</th>
                            <th>W-9</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trainers)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px; color: #6B7280;">No trainers earned $600+ in <?php echo $year; ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($trainers as $t): 
                            $ready = $t->w9_submitted && $t->tax_id_last4 && $t->tax_address_line1;
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($t->display_name); ?></strong><br><small><?php echo esc_html($t->email); ?></small></td>
                            <td><?php echo esc_html($t->legal_name ?: '-'); ?></td>
                            <td>
                                <?php if ($t->tax_id_last4): ?>
                                    <?php echo strtoupper($t->tax_id_type ?: 'SSN'); ?>: ***-**-<?php echo esc_html($t->tax_id_last4); ?>
                                <?php else: ?>
                                    <span style="color: #DC2626;">Missing</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t->tax_address_line1): ?>
                                    <?php echo esc_html($t->tax_address_line1); ?><br>
                                    <?php echo esc_html($t->tax_city . ', ' . $t->tax_state . ' ' . $t->tax_zip); ?>
                                <?php else: ?>
                                    <span style="color: #DC2626;">Missing</span>
                                <?php endif; ?>
                            </td>
                            <td><strong>$<?php echo number_format($t->total_paid, 2); ?></strong></td>
                            <td>
                                <?php if ($t->w9_submitted): ?>
                                    <span style="color: #065F46;">✓ Yes</span>
                                <?php else: ?>
                                    <span style="color: #DC2626;">✗ No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ready): ?>
                                    <span style="background: #D1FAE5; color: #065F46; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Ready</span>
                                <?php else: ?>
                                    <span style="background: #FEE2E2; color: #DC2626; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 20px; margin: 20px 0;">
                <h3 style="color: #1E40AF; margin-top: 0;">ℹ️ 1099-NEC Filing Information</h3>
                <ul style="color: #1E40AF; margin: 0;">
                    <li><strong>Deadline:</strong> 1099-NEC forms must be filed with the IRS and sent to recipients by January 31st</li>
                    <li><strong>Threshold:</strong> Required for any independent contractor paid $600 or more in a calendar year</li>
                    <li><strong>Box 1:</strong> Report total nonemployee compensation (trainer payouts)</li>
                    <li><strong>E-file:</strong> If filing 10+ forms, IRS requires electronic filing</li>
                    <li><strong>State Filing:</strong> Many states have separate filing requirements</li>
                </ul>
            </div>
        </div>
        <?php
    }
}
