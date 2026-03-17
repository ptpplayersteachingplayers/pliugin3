<?php
/**
 * PTP Monitor — Error Tracking, Alerting & Health Monitoring
 * v177.1 — Replaces basic PTP_Logger with production-grade monitoring
 * 
 * Features:
 *  - Severity-based logging (debug, info, warning, error, critical)
 *  - Database-backed log storage with auto-purge
 *  - Email alerts on critical failures (payments, webhooks, escrow)
 *  - Admin dashboard widget for recent errors
 *  - Rate-limited alerts (max 1 email per error type per hour)
 *  - Automatic Stripe key redaction
 */

defined('ABSPATH') || exit;

class PTP_Monitor {

    const TABLE = 'ptp_error_log';
    
    // Severity levels
    const DEBUG    = 'debug';
    const INFO     = 'info';
    const WARNING  = 'warning';
    const ERROR    = 'error';
    const CRITICAL = 'critical';

    // Categories
    const CAT_PAYMENT   = 'payment';
    const CAT_WEBHOOK   = 'webhook';
    const CAT_ESCROW    = 'escrow';
    const CAT_AUTH      = 'auth';
    const CAT_DATABASE  = 'database';
    const CAT_TEMPLATE  = 'template';
    const CAT_GENERAL   = 'general';

    // Alert cooldown per error type (seconds)
    const ALERT_COOLDOWN = 3600; // 1 hour

    // Max log entries before auto-purge
    const MAX_ENTRIES = 5000;
    
    // Days to keep log entries
    const RETENTION_DAYS = 30;

    private static $initialized = false;

    /**
     * Initialize monitoring
     */
    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;

        self::ensure_table();

        // Register admin page and widget
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_action('wp_dashboard_setup', array(__CLASS__, 'register_dashboard_widget'));
        
        // Daily cleanup cron
        add_action('ptp_monitor_cleanup', array(__CLASS__, 'cleanup_old_entries'));
        if (!wp_next_scheduled('ptp_monitor_cleanup')) {
            wp_schedule_event(time(), 'daily', 'ptp_monitor_cleanup');
        }

        // AJAX handler for admin dismiss
        add_action('wp_ajax_ptp_monitor_dismiss', array(__CLASS__, 'ajax_dismiss'));
        add_action('wp_ajax_ptp_monitor_clear_all', array(__CLASS__, 'ajax_clear_all'));
    }

    /**
     * Create log table if missing
     */
    private static function ensure_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        
        if (get_option('ptp_monitor_table_version', 0) >= 1) return;

        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            severity varchar(10) NOT NULL DEFAULT 'error',
            category varchar(20) NOT NULL DEFAULT 'general',
            message text NOT NULL,
            context longtext,
            source varchar(255) DEFAULT '',
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            ip_address varchar(45) DEFAULT '',
            url varchar(500) DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            dismissed tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY severity_idx (severity),
            KEY category_idx (category),
            KEY created_idx (created_at),
            KEY severity_cat_idx (severity, category, created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('ptp_monitor_table_version', 1);
    }

    // ═══════════════════════════════════════════
    // LOGGING METHODS
    // ═══════════════════════════════════════════

    /**
     * Core logging method
     */
    public static function log($severity, $category, $message, $context = array()) {
        global $wpdb;

        // Always write to error_log for server-level capture
        $prefix = strtoupper("[PTP {$severity}:{$category}]");
        $redacted = self::redact($message);
        ptp_log("{$prefix} {$redacted}");

        // Skip DB writes for debug level unless WP_DEBUG
        if ($severity === self::DEBUG && !defined('WP_DEBUG')) return;

        $table = $wpdb->prefix . self::TABLE;
        
        // Get caller info
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $source = '';
        if (isset($trace[1])) {
            $file = basename($trace[1]['file'] ?? '');
            $line = $trace[1]['line'] ?? 0;
            $func = $trace[1]['function'] ?? '';
            $source = "{$file}:{$line} {$func}()";
        }

        $wpdb->insert($table, array(
            'severity'   => $severity,
            'category'   => $category,
            'message'    => self::redact($message),
            'context'    => !empty($context) ? wp_json_encode(self::redact_array($context)) : null,
            'source'     => $source,
            'user_id'    => get_current_user_id() ?: null,
            'ip_address' => self::get_ip(),
            'url'        => isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 0, 500) : '',
            'created_at' => current_time('mysql'),
        ));

        // Send alert for critical/error in payment categories
        if (in_array($severity, array(self::CRITICAL, self::ERROR))) {
            if (in_array($category, array(self::CAT_PAYMENT, self::CAT_WEBHOOK, self::CAT_ESCROW))) {
                self::maybe_send_alert($severity, $category, $message, $context);
            }
        }

        // Auto-purge if over limit
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > self::MAX_ENTRIES) {
            self::cleanup_old_entries();
        }
    }

    /**
     * Convenience methods
     */
    public static function debug($category, $message, $context = array()) {
        self::log(self::DEBUG, $category, $message, $context);
    }

    public static function info($category, $message, $context = array()) {
        self::log(self::INFO, $category, $message, $context);
    }

    public static function warning($category, $message, $context = array()) {
        self::log(self::WARNING, $category, $message, $context);
    }

    public static function error($category, $message, $context = array()) {
        self::log(self::ERROR, $category, $message, $context);
    }

    public static function critical($category, $message, $context = array()) {
        self::log(self::CRITICAL, $category, $message, $context);
    }

    /**
     * Payment-specific helpers
     */
    public static function payment_error($message, $context = array()) {
        self::log(self::ERROR, self::CAT_PAYMENT, $message, $context);
    }

    public static function payment_critical($message, $context = array()) {
        self::log(self::CRITICAL, self::CAT_PAYMENT, $message, $context);
    }

    public static function webhook_error($message, $context = array()) {
        self::log(self::ERROR, self::CAT_WEBHOOK, $message, $context);
    }

    public static function escrow_error($message, $context = array()) {
        self::log(self::ERROR, self::CAT_ESCROW, $message, $context);
    }

    // ═══════════════════════════════════════════
    // ALERTING
    // ═══════════════════════════════════════════

    /**
     * Send email alert with rate limiting
     */
    private static function maybe_send_alert($severity, $category, $message, $context) {
        $alert_key = 'ptp_alert_' . md5($category . ':' . substr($message, 0, 100));
        
        // Check cooldown
        if (get_transient($alert_key)) return;
        set_transient($alert_key, true, self::ALERT_COOLDOWN);

        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $severity_upper = strtoupper($severity);
        $category_upper = strtoupper($category);

        $subject = "[{$site_name}] {$severity_upper} — {$category_upper} issue detected";

        $body = "A {$severity} {$category} issue was detected on {$site_name}.\n\n";
        $body .= "Message: {$message}\n\n";
        $body .= "Time: " . current_time('mysql') . "\n";
        $body .= "URL: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n";
        
        if (!empty($context)) {
            $body .= "\nContext:\n" . print_r(self::redact_array($context), true) . "\n";
        }
        
        $body .= "\nView all errors: " . admin_url('admin.php?page=ptp-monitor') . "\n";
        $body .= "\nThis alert will not repeat for this error type for " . (self::ALERT_COOLDOWN / 60) . " minutes.\n";

        wp_mail($admin_email, $subject, $body);
    }

    // ═══════════════════════════════════════════
    // SECURITY HELPERS
    // ═══════════════════════════════════════════

    /**
     * Redact sensitive data from strings
     */
    private static function redact($str) {
        if (!is_string($str)) $str = (string) $str;
        // Stripe keys
        $str = preg_replace('/(sk_live_|sk_test_|pk_live_|pk_test_|whsec_)[A-Za-z0-9]+/', '$1[REDACTED]', $str);
        // Bearer tokens
        $str = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer [REDACTED]', $str);
        // Email addresses in error context (partial redact)
        // Credit card numbers (just in case)
        $str = preg_replace('/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/', '[CARD_REDACTED]', $str);
        return $str;
    }

    /**
     * Redact sensitive values in arrays
     */
    private static function redact_array($arr) {
        if (!is_array($arr)) return $arr;
        $sensitive_keys = array('password', 'secret', 'token', 'key', 'card', 'cvv', 'ssn', 'api_key', 'webhook_secret');
        $redacted = array();
        foreach ($arr as $k => $v) {
            $key_lower = strtolower((string) $k);
            if (in_array($key_lower, $sensitive_keys)) {
                $redacted[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $redacted[$k] = self::redact_array($v);
            } elseif (is_string($v)) {
                $redacted[$k] = self::redact($v);
            } else {
                $redacted[$k] = $v;
            }
        }
        return $redacted;
    }

    private static function get_ip() {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
        return substr($ip, 0, 45);
    }

    // ═══════════════════════════════════════════
    // QUERY METHODS
    // ═══════════════════════════════════════════

    /**
     * Get recent errors for admin display
     */
    public static function get_entries($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $defaults = array(
            'severity'   => null,
            'category'   => null,
            'limit'      => 50,
            'offset'     => 0,
            'dismissed'  => 0,
            'since'      => null,
        );
        $args = wp_parse_args($args, $defaults);

        $where = array('1=1');
        $params = array();

        if ($args['severity']) {
            $where[] = 'severity = %s';
            $params[] = $args['severity'];
        }
        if ($args['category']) {
            $where[] = 'category = %s';
            $params[] = $args['category'];
        }
        if ($args['dismissed'] !== null) {
            $where[] = 'dismissed = %d';
            $params[] = (int) $args['dismissed'];
        }
        if ($args['since']) {
            $where[] = 'created_at >= %s';
            $params[] = $args['since'];
        }

        $where_sql = implode(' AND ', $where);
        $limit = intval($args['limit']);
        $offset = intval($args['offset']);

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get error counts by severity
     */
    public static function get_counts($since = null) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $where = 'dismissed = 0';
        if ($since) {
            $where .= $wpdb->prepare(' AND created_at >= %s', $since);
        }

        $results = $wpdb->get_results(
            "SELECT severity, COUNT(*) as count FROM {$table} WHERE {$where} GROUP BY severity"
        );

        $counts = array('debug' => 0, 'info' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0, 'total' => 0);
        foreach ($results as $row) {
            $counts[$row->severity] = (int) $row->count;
            $counts['total'] += (int) $row->count;
        }
        return $counts;
    }

    /**
     * Health check — returns status summary
     */
    public static function health_check() {
        $counts_24h = self::get_counts(gmdate('Y-m-d H:i:s', time() - 86400));
        
        $status = 'healthy';
        if ($counts_24h['critical'] > 0) $status = 'critical';
        elseif ($counts_24h['error'] > 5) $status = 'degraded';
        elseif ($counts_24h['warning'] > 20) $status = 'warning';

        return array(
            'status' => $status,
            'last_24h' => $counts_24h,
            'checked_at' => current_time('mysql'),
        );
    }

    // ═══════════════════════════════════════════
    // CLEANUP
    // ═══════════════════════════════════════════

    /**
     * Remove old entries
     */
    public static function cleanup_old_entries() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * 86400));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        ));

        // Also trim to MAX_ENTRIES if still over
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > self::MAX_ENTRIES) {
            $keep_id = $wpdb->get_var(
                "SELECT id FROM {$table} ORDER BY created_at DESC LIMIT 1 OFFSET " . self::MAX_ENTRIES
            );
            if ($keep_id) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d", $keep_id));
            }
        }
    }

    // ═══════════════════════════════════════════
    // ADMIN UI
    // ═══════════════════════════════════════════

    /**
     * Register admin menu page
     */
    public static function register_admin_page() {
        $counts = self::get_counts();
        $badge = $counts['critical'] + $counts['error'];
        $menu_title = 'Error Monitor' . ($badge > 0 ? " <span class='update-plugins count-{$badge}'><span class='plugin-count'>{$badge}</span></span>" : '');
        
        add_submenu_page(
            'ptp-dashboard',
            'PTP Error Monitor',
            $menu_title,
            'manage_options',
            'ptp-monitor',
            array(__CLASS__, 'render_admin_page')
        );
    }

    /**
     * Dashboard widget
     */
    public static function register_dashboard_widget() {
        wp_add_dashboard_widget('ptp_monitor_widget', 'PTP Platform Health', array(__CLASS__, 'render_dashboard_widget'));
    }

    public static function render_dashboard_widget() {
        $health = self::health_check();
        $counts = $health['last_24h'];
        $status_colors = array('healthy' => '#22c55e', 'warning' => '#f59e0b', 'degraded' => '#f97316', 'critical' => '#ef4444');
        $color = $status_colors[$health['status']] ?? '#6b7280';
        
        echo '<div style="margin:-12px -12px 0">';
        echo '<div style="padding:12px 16px;background:' . esc_attr($color) . '15;border-left:4px solid ' . esc_attr($color) . '">';
        echo '<strong style="color:' . esc_attr($color) . ';text-transform:uppercase;font-size:12px;letter-spacing:.03em">' . esc_html($health['status']) . '</strong>';
        echo '</div>';
        echo '<div style="padding:12px 16px;display:flex;gap:16px;font-size:13px">';
        
        if ($counts['critical'] > 0) echo '<span style="color:#ef4444">●  ' . esc_html($counts['critical']) . ' critical</span>';
        if ($counts['error'] > 0) echo '<span style="color:#f97316">●  ' . esc_html($counts['error']) . ' errors</span>';
        if ($counts['warning'] > 0) echo '<span style="color:#f59e0b">●  ' . esc_html($counts['warning']) . ' warnings</span>';
        if ($counts['total'] === 0) echo '<span style="color:#22c55e">No issues in last 24h</span>';
        
        echo '</div>';
        
        // Show last 3 critical/error entries
        $recent = self::get_entries(array('limit' => 3, 'since' => gmdate('Y-m-d H:i:s', time() - 86400)));
        if (!empty($recent)) {
            echo '<div style="padding:0 16px 12px;font-size:12px;color:#6b7280">';
            foreach ($recent as $entry) {
                $time_ago = human_time_diff(strtotime($entry->created_at), current_time('timestamp'));
                $sev_color = $entry->severity === 'critical' ? '#ef4444' : ($entry->severity === 'error' ? '#f97316' : '#6b7280');
                echo '<div style="padding:4px 0;border-top:1px solid #f3f4f6">';
                echo '<span style="color:' . esc_attr($sev_color) . ';font-weight:600">' . esc_html(strtoupper($entry->severity)) . '</span> ';
                echo '<span style="color:#9ca3af">[' . esc_html($entry->category) . ']</span> ';
                echo esc_html(substr($entry->message, 0, 80));
                echo ' <span style="color:#d1d5db">— ' . esc_html($time_ago) . ' ago</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '<div style="padding:8px 16px;border-top:1px solid #e5e7eb;text-align:right">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=ptp-monitor')) . '">View all →</a>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Full admin monitoring page
     */
    public static function render_admin_page() {
        $severity_filter = isset($_GET['severity']) ? sanitize_text_field($_GET['severity']) : null;
        $category_filter = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : null;
        $page_num = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;

        $entries = self::get_entries(array(
            'severity' => $severity_filter,
            'category' => $category_filter,
            'limit'    => $per_page,
            'offset'   => ($page_num - 1) * $per_page,
        ));

        $counts = self::get_counts();
        $health = self::health_check();
        ?>
        <div class="wrap">
            <h1>PTP Error Monitor</h1>
            
            <div style="display:flex;gap:12px;margin:16px 0">
                <?php foreach (array('critical' => '#ef4444', 'error' => '#f97316', 'warning' => '#f59e0b', 'info' => '#3b82f6', 'debug' => '#6b7280') as $sev => $col): ?>
                <a href="<?php echo esc_url(add_query_arg('severity', $sev)); ?>" 
                   style="padding:8px 16px;background:<?php echo $severity_filter === $sev ? $col . '22' : '#f9fafb'; ?>;border:1px solid <?php echo $severity_filter === $sev ? $col : '#e5e7eb'; ?>;border-radius:8px;text-decoration:none;color:#374151;font-size:13px">
                    <strong style="color:<?php echo esc_attr($col); ?>"><?php echo esc_html($counts[$sev]); ?></strong> <?php echo esc_html($sev); ?>
                </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url(remove_query_arg(array('severity', 'category'))); ?>" style="padding:8px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;color:#6b7280;font-size:13px">Show all</a>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" style="margin-left:auto">
                    <?php wp_nonce_field('ptp_monitor_clear', 'nonce'); ?>
                    <input type="hidden" name="action" value="ptp_monitor_clear_all">
                    <button type="submit" class="button" onclick="return confirm('Clear all log entries?')">Clear All Logs</button>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped" style="font-size:13px">
                <thead>
                    <tr>
                        <th style="width:60px">Severity</th>
                        <th style="width:80px">Category</th>
                        <th>Message</th>
                        <th style="width:180px">Source</th>
                        <th style="width:140px">Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:40px;color:#9ca3af">No log entries found</td></tr>
                    <?php else: foreach ($entries as $entry):
                        $sev_colors = array('critical' => '#ef4444', 'error' => '#f97316', 'warning' => '#f59e0b', 'info' => '#3b82f6', 'debug' => '#6b7280');
                        $col = $sev_colors[$entry->severity] ?? '#6b7280';
                    ?>
                    <tr>
                        <td><span style="background:<?php echo esc_attr($col); ?>18;color:<?php echo esc_attr($col); ?>;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase"><?php echo esc_html($entry->severity); ?></span></td>
                        <td style="color:#6b7280"><?php echo esc_html($entry->category); ?></td>
                        <td>
                            <?php echo esc_html($entry->message); ?>
                            <?php if ($entry->context): ?>
                            <details style="margin-top:4px"><summary style="font-size:11px;color:#9ca3af;cursor:pointer">Context</summary>
                            <pre style="font-size:11px;background:#f9fafb;padding:8px;border-radius:4px;margin-top:4px;max-height:200px;overflow:auto"><?php echo esc_html(json_encode(json_decode($entry->context), JSON_PRETTY_PRINT)); ?></pre>
                            </details>
                            <?php endif; ?>
                        </td>
                        <td style="color:#9ca3af;font-size:11px"><?php echo esc_html($entry->source); ?></td>
                        <td style="color:#6b7280;font-size:12px">
                            <?php echo esc_html(human_time_diff(strtotime($entry->created_at), current_time('timestamp'))); ?> ago
                            <div style="font-size:10px;color:#d1d5db"><?php echo esc_html($entry->created_at); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * AJAX: Clear all logs
     */
    public static function ajax_clear_all() {
        check_ajax_referer('ptp_monitor_clear', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . self::TABLE);
        
        wp_redirect(admin_url('admin.php?page=ptp-monitor&cleared=1'));
        exit;
    }

    /**
     * AJAX: Dismiss entry
     */
    public static function ajax_dismiss() {
        check_ajax_referer('ptp_monitor_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $id = intval($_POST['entry_id']);
        $wpdb->update($wpdb->prefix . self::TABLE, array('dismissed' => 1), array('id' => $id));
        wp_send_json_success();
    }

    // ═══════════════════════════════════════════
    // BACKWARD COMPATIBILITY
    // ═══════════════════════════════════════════

    /**
     * Drop-in replacement for PTP_Logger::log()
     */
    public static function legacy_log($message, $context = array()) {
        self::log(self::INFO, self::CAT_GENERAL, is_string($message) ? $message : wp_json_encode($message), $context);
    }
}

/**
 * Backward-compatible PTP_Logger wrapper
 */
if (!class_exists('PTP_Logger')) {
    class PTP_Logger {
        public static function log($message, $context = []) {
            PTP_Monitor::legacy_log($message, $context);
        }
    }
}
