<?php
/**
 * PTP Database Migrations — Versioned Schema Management
 * v177.0.3
 * 
 * Replaces ad-hoc ALTER TABLE calls scattered across class files.
 * Migrations run once, are tracked in wp_options, and are idempotent.
 * 
 * Usage: Add new migrations to get_migrations() in order.
 * They run automatically on plugin load when version is behind.
 */

defined('ABSPATH') || exit;

class PTP_Migrations {

    const OPTION_KEY = 'ptp_migration_version';
    const LOG_OPTION = 'ptp_migration_log';

    /**
     * Run any pending migrations
     */
    public static function init() {
        $current = (int) get_option(self::OPTION_KEY, 0);
        $migrations = self::get_migrations();
        $latest = count($migrations);

        if ($current >= $latest) return; // Up to date

        global $wpdb;

        $log = get_option(self::LOG_OPTION, array());

        for ($i = $current; $i < $latest; $i++) {
            $migration = $migrations[$i];
            $version = $i + 1;
            $name = $migration['name'];
            $start = microtime(true);

            try {
                // Run the migration
                call_user_func($migration['up']);
                
                $elapsed = round((microtime(true) - $start) * 1000);
                $log[] = array(
                    'version'    => $version,
                    'name'       => $name,
                    'status'     => 'success',
                    'ran_at'     => current_time('mysql'),
                    'elapsed_ms' => $elapsed,
                );

                update_option(self::OPTION_KEY, $version);

                if (class_exists('PTP_Monitor')) {
                    PTP_Monitor::info('database', "Migration #{$version} ran: {$name} ({$elapsed}ms)");
                }

            } catch (Exception $e) {
                $log[] = array(
                    'version' => $version,
                    'name'    => $name,
                    'status'  => 'FAILED',
                    'error'   => $e->getMessage(),
                    'ran_at'  => current_time('mysql'),
                );

                if (class_exists('PTP_Monitor')) {
                    PTP_Monitor::critical('database', "Migration #{$version} FAILED: {$name} — {$e->getMessage()}");
                }

                // Stop running further migrations on failure
                break;
            }
        }

        update_option(self::LOG_OPTION, $log);
    }

    /**
     * Get migration status for admin display
     */
    public static function get_status() {
        $current = (int) get_option(self::OPTION_KEY, 0);
        $migrations = self::get_migrations();
        $log = get_option(self::LOG_OPTION, array());

        return array(
            'current_version' => $current,
            'latest_version'  => count($migrations),
            'pending'         => count($migrations) - $current,
            'migrations'      => $migrations,
            'log'             => $log,
        );
    }

    // ═══════════════════════════════════════════
    // MIGRATION DEFINITIONS
    // ═══════════════════════════════════════════
    // 
    // Add new migrations AT THE END of this array.
    // Each migration has:
    //   'name' => Description
    //   'up'   => Callable that applies the change
    //   'note' => Rollback instructions (manual, for documentation)
    //
    // Migrations MUST be idempotent (safe to run twice).
    // Use "IF NOT EXISTS" / "IF EXISTS" in all DDL.
    // ═══════════════════════════════════════════

    private static function get_migrations() {
        return array(

            // ─── Migration 1: Add trainer philosophy columns ───
            array(
                'name' => 'Add coaching_why, training_philosophy, training_policy to ptp_trainers',
                'note' => 'Rollback: ALTER TABLE DROP COLUMN coaching_why, training_philosophy, training_policy',
                'up'   => function() {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ptp_trainers';
                    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

                    $additions = array(
                        'coaching_why'        => 'text',
                        'training_philosophy' => 'text',
                        'training_policy'     => 'text',
                    );

                    foreach ($additions as $col => $type) {
                        if (!in_array($col, $cols)) {
                            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
                        }
                    }
                },
            ),

            // ─── Migration 2: Create error log table ───
            array(
                'name' => 'Create ptp_error_log table for monitoring',
                'note' => 'Rollback: DROP TABLE IF EXISTS {prefix}ptp_error_log',
                'up'   => function() {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ptp_error_log';
                    $charset = $wpdb->get_charset_collate();
                    
                    $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
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
                        KEY created_idx (created_at)
                    ) {$charset}");
                },
            ),

            // ─── Migration 3: Add escrow audit columns ───
            array(
                'name' => 'Add dispute_resolved_by and resolution_notes to ptp_escrow',
                'note' => 'Rollback: ALTER TABLE DROP COLUMN dispute_resolved_by, resolution_notes',
                'up'   => function() {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ptp_escrow';
                    
                    // Only run if table exists
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                    if (!$exists) return;
                    
                    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

                    if (!in_array('dispute_resolved_by', $cols)) {
                        $wpdb->query("ALTER TABLE {$table} ADD COLUMN dispute_resolved_by bigint(20) UNSIGNED DEFAULT NULL");
                    }
                    if (!in_array('resolution_notes', $cols)) {
                        $wpdb->query("ALTER TABLE {$table} ADD COLUMN resolution_notes text");
                    }
                },
            ),

            // ─── Migration 4: Index bookings for dashboard performance ───
            array(
                'name' => 'Add composite index on ptp_bookings for dashboard queries',
                'note' => 'Rollback: DROP INDEX idx_bookings_trainer_status ON {prefix}ptp_bookings',
                'up'   => function() {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ptp_bookings';
                    
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                    if (!$exists) return;

                    // Check if index exists
                    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_bookings_trainer_status'");
                    if (empty($indexes)) {
                        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
                        if (in_array('trainer_id', $cols) && in_array('status', $cols) && in_array('session_date', $cols)) {
                            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_bookings_trainer_status (trainer_id, status, session_date)");
                        }
                    }
                },
            ),

            // ─── Migration 5: Index escrow for payout queries ───
            array(
                'name' => 'Add composite index on ptp_escrow for payout processing',
                'note' => 'Rollback: DROP INDEX idx_escrow_status_trainer ON {prefix}ptp_escrow',
                'up'   => function() {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ptp_escrow';
                    
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                    if (!$exists) return;

                    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_escrow_status_trainer'");
                    if (empty($indexes)) {
                        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
                        if (in_array('status', $cols) && in_array('trainer_id', $cols)) {
                            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_escrow_status_trainer (status, trainer_id, created_at)");
                        }
                    }
                },
            ),

            // ═══ ADD NEW MIGRATIONS BELOW THIS LINE ═══

            // ─── Migration 6: Add graduated fee columns to ptp_escrow ───
            array(
                'name' => 'Add fee_rate and session_number to ptp_escrow for graduated fee schedule',
                'note' => 'Rollback: ALTER TABLE DROP COLUMN fee_rate, session_number',
                'up'   => function() {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ptp_escrow';
                    
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                    if (!$exists) return;

                    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
                    if (!in_array('fee_rate', $cols)) {
                        $wpdb->query("ALTER TABLE {$table} ADD COLUMN fee_rate decimal(4,2) DEFAULT 0.15 AFTER trainer_amount");
                    }
                    if (!in_array('session_number', $cols)) {
                        $wpdb->query("ALTER TABLE {$table} ADD COLUMN session_number int UNSIGNED DEFAULT 1 AFTER fee_rate");
                    }
                },
            ),

            // ─── Migration 7: Fix training_locations JSON corrupted by WP magic quotes ───
            array(
                'name' => 'Fix backslash-corrupted training_locations JSON from admin AJAX saves',
                'note' => 'One-time cleanup: strips backslash-escaped quotes from training_locations column',
                'up'   => function() {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ptp_trainers';
                    
                    // Find trainers with backslash-corrupted JSON (contains \" literal)
                    $corrupted = $wpdb->get_results(
                        "SELECT id, training_locations FROM {$table} WHERE training_locations LIKE '%\\\\\"%' AND training_locations != ''"
                    );
                    
                    $fixed = 0;
                    foreach ($corrupted as $row) {
                        $clean = wp_unslash($row->training_locations);
                        // Verify it's now valid JSON
                        $decoded = json_decode($clean, true);
                        if (is_array($decoded) && !empty($decoded)) {
                            // Re-encode to ensure clean JSON
                            $wpdb->update($table, 
                                array('training_locations' => wp_json_encode($decoded)), 
                                array('id' => $row->id)
                            );
                            $fixed++;
                        }
                    }
                    
                    if ($fixed > 0) {
                        ptp_log("[PTP Migration 7] Fixed {$fixed} trainer(s) with corrupted training_locations JSON");
                    }
                },
            ),

            // Migration 8: Add years_coaching column to ptp_trainers
            array(
                'name' => 'Add years_coaching column to ptp_trainers',
                'note' => 'Onboarding collects years coaching — column was missing from schema',
                'up'   => function() {
                    global $wpdb;
                    $table = $wpdb->prefix . 'ptp_trainers';
                    $col = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'years_coaching'");
                    if (empty($col)) {
                        $wpdb->query("ALTER TABLE {$table} ADD COLUMN years_coaching int(11) DEFAULT 0 AFTER experience_years");
                    }
                },
            ),

        );
    }

    // ═══════════════════════════════════════════
    // ADMIN UI
    // ═══════════════════════════════════════════

    public static function register_admin_page() {
        add_submenu_page(
            'ptp-dashboard',
            'Database Migrations',
            'Migrations',
            'manage_options',
            'ptp-migrations',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function render_admin_page() {
        $status = self::get_status();
        ?>
        <div class="wrap">
            <h1>Database Migrations</h1>

            <div style="display:flex;gap:16px;margin:16px 0">
                <div style="padding:12px 20px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px">
                    <div style="font-size:24px;font-weight:700;color:#166534"><?php echo esc_html($status['current_version']); ?></div>
                    <div style="font-size:12px;color:#6b7280">Current Version</div>
                </div>
                <div style="padding:12px 20px;background:<?php echo $status['pending'] > 0 ? '#fef3c7' : '#f9fafb'; ?>;border:1px solid <?php echo $status['pending'] > 0 ? '#fde68a' : '#e5e7eb'; ?>;border-radius:8px">
                    <div style="font-size:24px;font-weight:700;color:<?php echo $status['pending'] > 0 ? '#92400e' : '#6b7280'; ?>"><?php echo esc_html($status['pending']); ?></div>
                    <div style="font-size:12px;color:#6b7280">Pending</div>
                </div>
            </div>

            <h3>Migration History</h3>
            <table class="wp-list-table widefat fixed striped" style="font-size:13px">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Migration</th>
                        <th style="width:80px">Status</th>
                        <th style="width:160px">Run At</th>
                        <th style="width:80px">Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($status['migrations'] as $i => $m):
                        $version = $i + 1;
                        $log_entry = null;
                        foreach ($status['log'] as $l) {
                            if ($l['version'] === $version) $log_entry = $l;
                        }
                        $ran = $version <= $status['current_version'];
                    ?>
                    <tr>
                        <td><?php echo esc_html($version); ?></td>
                        <td><?php echo esc_html($m['name']); ?></td>
                        <td>
                            <?php if ($ran): ?>
                                <span style="color:#22c55e;font-weight:600">✓ Done</span>
                            <?php else: ?>
                                <span style="color:#f59e0b;font-weight:600">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#6b7280"><?php echo esc_html($log_entry['ran_at'] ?? '—'); ?></td>
                        <td style="color:#6b7280"><?php echo esc_html(isset($log_entry['elapsed_ms']) ? $log_entry['elapsed_ms'] . 'ms' : '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($status['migrations'])): ?>
                    <tr><td colspan="5" style="text-align:center;padding:20px;color:#9ca3af">No migrations defined</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($status['pending'] > 0): ?>
            <p style="margin-top:16px;color:#6b7280">Pending migrations will run automatically on next page load. To run them now, deactivate and reactivate the plugin.</p>
            <?php endif; ?>
        </div>
        <?php
    }
}
