<?php
/**
 * PTP Mentorship Admin Page Template
 * 
 * Variables available from PTP_Mentorship_Admin::render_admin_page():
 *   $total_pairs, $active_pairs, $pending_videos, $interest_count
 *   $pairs, $status_filter, $status_colors, $all_statuses
 *   $trainers_all, $enabled_count
 */

if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
<h1 style="font-family:Georgia,serif;margin-bottom:20px">PTP Mentorship</h1>

<!-- Stats bar -->
<div style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap">
    <?php foreach (array(
        array('Total Pairs', $total_pairs, '#6B7280'),
        array('Active', $active_pairs, '#22C55E'),
        array('Pending Interest', $interest_count, '#8B5CF6'),
        array('Pending Videos', $pending_videos, '#F59E0B'),
    ) as $s): ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 20px;min-width:120px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06)">
        <div style="font-size:28px;font-weight:700;color:<?php echo $s[2]; ?>"><?php echo $s[1]; ?></div>
        <div style="font-size:12px;color:#6b7280;margin-top:2px"><?php echo $s[0]; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Trainer Mentorship Toggle -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,.06)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h3 style="margin:0;font-size:16px">Trainer Mentorship (<?php echo $enabled_count; ?>/<?php echo count($trainers_all); ?> enabled)</h3>
        <form method="post" style="margin:0">
            <?php wp_nonce_field('ptp_mentorship_admin'); ?>
            <button type="submit" name="ptp_bulk_enable_mentorship" value="1"
                    style="background:#FCB900;color:#0A0A0A;border:none;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer"
                    onclick="return confirm('Enable mentorship for ALL approved trainers?')">
                Enable All Trainers
            </button>
        </form>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($trainers_all as $t):
            $is_on = !empty($t->mentorship_enabled);
        ?>
        <form method="post" style="margin:0">
            <?php wp_nonce_field('ptp_mentorship_admin'); ?>
            <input type="hidden" name="toggle_trainer_id" value="<?php echo $t->id; ?>">
            <input type="hidden" name="toggle_enable" value="<?php echo $is_on ? 0 : 1; ?>">
            <button type="submit" name="ptp_toggle_mentorship" value="1"
                    style="display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid <?php echo $is_on ? '#22C55E' : '#d1d5db'; ?>;background:<?php echo $is_on ? '#F0FDF4' : '#f9fafb'; ?>;color:<?php echo $is_on ? '#166534' : '#6b7280'; ?>">
                <span style="width:8px;height:8px;border-radius:50%;background:<?php echo $is_on ? '#22C55E' : '#d1d5db'; ?>;display:inline-block"></span>
                <?php echo esc_html($t->display_name); ?>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
</div>

<!-- Status filter tabs -->
<div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
    <a href="<?php echo admin_url('admin.php?page=ptp-mentorship'); ?>"
       style="padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;background:<?php echo !$status_filter ? '#0A0A0A' : '#f3f4f6'; ?>;color:<?php echo !$status_filter ? '#fff' : '#374151'; ?>">
        All (<?php echo $total_pairs; ?>)
    </a>
    <?php
    global $wpdb;
    foreach ($all_statuses as $st):
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_mentorship_pairs WHERE status=%s", $st));
        ?>
    <a href="<?php echo admin_url('admin.php?page=ptp-mentorship&status_filter=' . $st); ?>"
       style="padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;background:<?php echo $status_filter === $st ? $status_colors[$st] : '#f3f4f6'; ?>;color:<?php echo $status_filter === $st ? '#fff' : '#374151'; ?>">
        <?php echo ucfirst(str_replace('_', ' ', $st)); ?> (<?php echo $count; ?>)
    </a>
    <?php endforeach; ?>
</div>

<!-- Pairs table -->
<table class="widefat striped" style="font-size:13px">
    <thead>
        <tr>
            <th>Pair</th><th>Package</th><th>Progress</th><th>Status</th><th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($pairs)): ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:#9ca3af">No mentorship pairs found.</td></tr>
    <?php else: foreach ($pairs as $p):
        $email = $p->parent_email_user ?: $p->parent_email;
        $color = $status_colors[$p->status] ?? '#6B7280';
    ?>
    <tr>
        <td>
            <strong><?php echo esc_html($p->trainer_name ?: 'Unknown'); ?></strong> &rarr; <?php echo esc_html($p->player_name ?: '—'); ?>
            <div style="font-size:11px;color:#6b7280;margin-top:2px"><?php echo esc_html($email ?: '—'); ?></div>
        </td>
        <td><?php echo esc_html(ucfirst($p->package_type ?: '—')); ?></td>
        <td>
            <strong><?php echo (int)$p->sessions_completed; ?></strong><span style="color:#9ca3af"> / <?php echo (int)$p->sessions_total; ?></span>
            <?php $pct = $p->sessions_total > 0 ? round(($p->sessions_completed / $p->sessions_total) * 100) : 0; ?>
            <div style="background:#e5e7eb;border-radius:4px;height:4px;width:60px;margin-top:4px"><div style="background:<?php echo $color; ?>;border-radius:4px;height:100%;width:<?php echo $pct; ?>%"></div></div>
        </td>
        <td>
            <span style="background:<?php echo $color; ?>20;color:<?php echo $color; ?>;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:600">
                <?php echo ucfirst(str_replace('_', ' ', $p->status)); ?>
            </span>
            <div style="font-size:10px;color:#9ca3af;margin-top:2px"><?php echo date('M j', strtotime($p->created_at)); ?></div>
        </td>
        <td>
            <form method="post" style="display:inline-flex;gap:6px;align-items:center">
                <?php wp_nonce_field('ptp_mentorship_admin'); ?>
                <input type="hidden" name="pair_id" value="<?php echo $p->id; ?>">
                <select name="new_status" style="font-size:11px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px">
                    <?php foreach ($all_statuses as $st): ?>
                    <option value="<?php echo $st; ?>" <?php selected($p->status, $st); ?>><?php echo ucfirst(str_replace('_', ' ', $st)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="ptp_change_pair_status" value="1"
                        style="background:#0A0A0A;color:#FCB900;border:none;padding:4px 10px;border-radius:4px;font-size:11px;cursor:pointer;font-weight:700">
                    Save
                </button>
            </form>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<?php // v233 L3: Pagination controls ?>
<?php if ($total_pages > 1): ?>
<div class="tablenav bottom" style="margin:12px 0 24px">
    <div class="tablenav-pages">
        <span class="displaying-num"><?php echo number_format($filtered_total); ?> items</span>
        <span class="pagination-links">
            <?php
            $base_url = admin_url('admin.php?page=ptp-mentorship');
            if ($status_filter) $base_url = add_query_arg('status_filter', $status_filter, $base_url);

            $prev_disabled = ($current_page <= 1) ? ' disabled' : '';
            $next_disabled = ($current_page >= $total_pages) ? ' disabled' : '';
            ?>
            <a class="first-page button<?php echo $prev_disabled; ?>" href="<?php echo esc_url(add_query_arg('paged', 1, $base_url)); ?>"><span>&laquo;</span></a>
            <a class="prev-page button<?php echo $prev_disabled; ?>" href="<?php echo esc_url(add_query_arg('paged', max(1, $current_page - 1), $base_url)); ?>"><span>&lsaquo;</span></a>
            <span class="paging-input">
                <span class="tablenav-paging-text"><?php echo $current_page; ?> of <span class="total-pages"><?php echo $total_pages; ?></span></span>
            </span>
            <a class="next-page button<?php echo $next_disabled; ?>" href="<?php echo esc_url(add_query_arg('paged', min($total_pages, $current_page + 1), $base_url)); ?>"><span>&rsaquo;</span></a>
            <a class="last-page button<?php echo $next_disabled; ?>" href="<?php echo esc_url(add_query_arg('paged', $total_pages, $base_url)); ?>"><span>&raquo;</span></a>
        </span>
    </div>
</div>
<?php endif; ?>

<!-- Pending Videos Section -->
<?php
$pending_vids = $wpdb->get_results("
    SELECT v.*, t.display_name AS trainer_name, pl.name AS player_name
    FROM {$wpdb->prefix}ptp_mentorship_videos v
    LEFT JOIN {$wpdb->prefix}ptp_mentorship_pairs mp ON v.pair_id = mp.id
    LEFT JOIN {$wpdb->prefix}ptp_trainers t ON mp.trainer_id = t.id
    LEFT JOIN {$wpdb->prefix}ptp_players pl ON mp.player_id = pl.id
    WHERE v.status = 'pending'
    ORDER BY v.created_at ASC
    LIMIT 50
");
if (!empty($pending_vids)): ?>
<h2 style="margin-top:32px">Pending Video Reviews (<?php echo count($pending_vids); ?>)</h2>
<table class="widefat striped" style="font-size:13px">
    <thead><tr><th>Submission</th><th>Note</th><th>Video</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($pending_vids as $v): ?>
    <tr>
        <td><strong><?php echo esc_html($v->trainer_name ?: '—'); ?></strong> &rarr; <?php echo esc_html($v->player_name ?: '—'); ?></td>
        <td style="max-width:200px;font-size:12px;color:#6b7280"><?php echo esc_html(substr($v->player_note, 0, 80)); ?><?php echo strlen($v->player_note) > 80 ? '...' : ''; ?></td>
        <td><a href="<?php echo esc_url($v->video_url); ?>" target="_blank" style="color:#2563eb;font-size:12px">View</a></td>
        <td style="font-size:12px;color:#6b7280"><?php echo date('M j, g:i A', strtotime($v->created_at)); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
