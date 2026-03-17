<?php
/**
 * Admin Template: Trainers Management
 * Extracted from class-ptp-admin.php for clean separation
 * @since 196.2 — Mobile-first redesign, consistent FSA design system
 */
defined('ABSPATH') || exit;

$nonce = wp_create_nonce('ptp_admin_nonce');
$base_url = admin_url('admin.php?page=ptp-trainers');
?>
<style>
/* ═══ PTP Admin Design System (shared with FSA) ═══ */
.pta-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:1400px;margin:20px auto;padding:0 20px}
.pta-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.pta-hdr h1{font-size:22px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px}.pta-hdr h1 span{color:#FCB900}
.pta-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pta-btn{padding:8px 16px;font-size:13px;font-weight:600;border-radius:6px;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;line-height:1.4;transition:all .15s}
.pta-btn-gold{background:#FCB900;color:#0A0A0A}.pta-btn-gold:hover{background:#e5a800}
.pta-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db}.pta-btn-outline:hover{background:#f9fafb}
.pta-btn-green{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}.pta-btn-green:hover{background:#d1fae5}
.pta-btn-red{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}.pta-btn-red:hover{background:#fee2e2}
.pta-btn-blue{background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe}.pta-btn-blue:hover{background:#dbeafe}
.pta-btn-sm{padding:4px 10px;font-size:11px}
.pta-alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;font-weight:500}
.pta-alert-ok{background:#ecfdf5;color:#065f46}.pta-alert-warn{background:#fef3c7;color:#92400e}

/* Stats */
.pta-stats{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.pta-stat{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;flex:1;min-width:100px}
.pta-stat-n{font-size:28px;font-weight:700;color:#0A0A0A;line-height:1}
.pta-stat-l{font-size:11px;color:#6b7280;margin-top:4px;text-transform:uppercase;letter-spacing:.5px}
.pta-stat-gold .pta-stat-n{color:#FCB900}
.pta-stat-green .pta-stat-n{color:#059669}
.pta-stat-purple .pta-stat-n{color:#7C3AED}

/* Tabs */
.pta-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid #e5e7eb;padding-bottom:0;flex-wrap:wrap}
.pta-tab{padding:10px 16px;font-size:13px;font-weight:500;color:#6b7280;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap}
.pta-tab:hover{color:#111}.pta-tab.active{color:#0A0A0A;border-color:#FCB900;font-weight:600}
.pta-tab .cnt{background:#e5e7eb;color:#374151;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px}
.pta-tab.active .cnt{background:#FCB900;color:#0A0A0A}

/* Toolbar */
.pta-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px}
.pta-search{display:flex;gap:8px;align-items:center}
.pta-search input{padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;width:260px;max-width:100%}
.pta-search input:focus{outline:none;border-color:#FCB900;box-shadow:0 0 0 2px rgba(252,185,0,.15)}

/* Table */
.pta-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -20px;padding:0 20px}
.pta-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);min-width:650px}
.pta-table th{background:#f9fafb;padding:10px 12px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;border-bottom:1px solid #e5e7eb;white-space:nowrap}
.pta-table td{padding:10px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;vertical-align:middle}
.pta-table tr:hover td{background:#fafafa}
.pta-table tr.featured td{background:#FFFBEB}

/* User cell */
.pta-user{display:flex;align-items:center;gap:10px}
.pta-avatar{width:36px;height:36px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#374151;flex-shrink:0;overflow:hidden}
.pta-avatar img{width:100%;height:100%;object-fit:cover}
.pta-user-name{font-weight:600;color:#111;font-size:13px}
.pta-user-meta{font-size:11px;color:#6b7280}

/* Badges */
.pta-badge{display:inline-block;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.pta-badge-active{background:#d1fae5;color:#065f46}
.pta-badge-pending{background:#fef3c7;color:#92400e}
.pta-badge-inactive{background:#f3f4f6;color:#6b7280}
.pta-badge-suspended{background:#fee2e2;color:#991b1b}

/* Compliance dots */
.pta-comp{display:inline-flex;align-items:center;gap:2px;padding:2px 6px;border-radius:4px;font-size:10px;cursor:pointer}
.pta-comp-ok{background:#d1fae5;color:#065f46}
.pta-comp-wait{background:#fef3c7;color:#92400e}
.pta-comp-no{background:#fee2e2;color:#dc2626}

/* Modal overlay */
.pta-overlay{display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:16px}
/* Google Places autocomplete dropdown — must sit above modal overlay */
.pac-container{z-index:100010!important;border-radius:8px;border:2px solid #FCB900;box-shadow:0 8px 32px rgba(0,0,0,.2);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.pac-item{padding:10px 14px;min-height:44px;font-size:13px;cursor:pointer;border-bottom:1px solid #f3f4f6}
.pac-item:hover,.pac-item-selected{background:#FFF9E5}
.pac-item-query{font-weight:600}
.pac-icon{display:none}
.pta-overlay.active{display:flex}
.pta-modal{background:#fff;border-radius:12px;width:100%;max-width:900px;max-height:90vh;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);display:flex;flex-direction:column}
.pta-modal-sm{max-width:420px}
.pta-modal-hdr{padding:16px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.pta-modal-hdr h2{margin:0;font-size:17px;font-weight:700;display:flex;align-items:center;gap:10px}
.pta-modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:#6b7280;padding:0 4px;line-height:1}
.pta-modal-body{padding:0;overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch}
.pta-modal-foot{padding:14px 24px;border-top:1px solid #e5e7eb;display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap}

/* Modal tabs */
.pta-mtabs{display:flex;gap:0;border-bottom:1px solid #e5e7eb;padding:0 24px;flex-shrink:0;overflow-x:auto;-webkit-overflow-scrolling:touch}
.pta-mtab{padding:12px 14px;border:none;background:none;font-size:12px;font-weight:500;cursor:pointer;border-bottom:2px solid transparent;color:#6b7280;white-space:nowrap}
.pta-mtab.active{border-bottom-color:#FCB900;color:#0A0A0A;font-weight:600}

/* Form fields */
.pta-panel{padding:24px;display:none}
.pta-panel.active{display:block}
.pta-field{margin-bottom:14px}
.pta-field label{display:block;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.pta-input{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;font-family:inherit;box-sizing:border-box}
.pta-input:focus{outline:none;border-color:#FCB900;box-shadow:0 0 0 2px rgba(252,185,0,.15)}
textarea.pta-input{resize:vertical;min-height:70px}
.pta-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.pta-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.pta-hint{font-size:11px;color:#9ca3af;margin-top:3px}
.pta-check{display:flex;align-items:center;gap:8px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer}
.pta-check:hover{background:#f9fafb}
.pta-check input{flex-shrink:0}
.pta-check-label{font-weight:600;font-size:13px}.pta-check-desc{font-size:11px;color:#6b7280}

/* Stats grid in modal */
.pta-stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;background:#f9fafb;padding:16px;border-radius:8px}
.pta-stat-box{text-align:center;padding:10px;background:#fff;border-radius:6px;border:1px solid #e5e7eb}
.pta-stat-box-n{font-size:20px;font-weight:700}.pta-stat-box-l{font-size:10px;color:#6b7280;margin-top:2px}

/* Photo area */
.pta-photo{width:80px;height:80px;border-radius:50%;background:#f3f4f6;overflow:hidden;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px dashed #d1d5db;position:relative}
.pta-photo img{width:100%;height:100%;object-fit:cover}
.pta-photo:hover{border-color:#FCB900}

/* Toast message */
.pta-msg{display:none;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:500;margin-top:12px}
.pta-msg.show{display:block}
.pta-msg-ok{background:#ecfdf5;color:#065f46}
.pta-msg-err{background:#fef2f2;color:#dc2626}

.pta-empty{text-align:center;padding:60px 20px;color:#9ca3af}
.pta-empty h3{font-size:18px;color:#374151;margin-bottom:4px}

@media(max-width:600px){
    .pta-wrap{padding:0 12px}
    .pta-stats{gap:8px}.pta-stat{padding:12px;min-width:70px}.pta-stat-n{font-size:22px}
    .pta-tab{padding:8px 10px;font-size:12px}
    .pta-hdr h1{font-size:18px}
    .pta-overlay{padding:0;align-items:flex-end}
    .pta-modal{max-height:92vh;border-radius:16px 16px 0 0;max-width:100%}
    .pta-modal-hdr{border-radius:16px 16px 0 0}
    .pta-row,.pta-row3{grid-template-columns:1fr}
    .pta-stat-grid{grid-template-columns:repeat(2,1fr)}
    .pta-table-wrap{margin:0 -12px;padding:0 12px}
    .pta-search input{width:180px}
    .pta-mtab{padding:10px 10px;font-size:11px}
}
</style>

<div class="pta-wrap">
    <?php if (isset($_GET['trainer_deleted'])): ?><div class="pta-alert pta-alert-ok">Trainer deleted.</div><?php endif; ?>
    <?php if (isset($_GET['trainer_updated'])): ?><div class="pta-alert pta-alert-ok">Trainer updated.</div><?php endif; ?>

    <div class="pta-hdr">
        <h1><span>&#9889;</span> Trainers</h1>
    </div>

    <!-- Stats -->
    <div class="pta-stats">
        <div class="pta-stat"><div class="pta-stat-n"><?php echo $total; ?></div><div class="pta-stat-l">Total</div></div>
        <div class="pta-stat pta-stat-green"><div class="pta-stat-n"><?php echo isset($counts['active']) ? $counts['active']->count : 0; ?></div><div class="pta-stat-l">Active</div></div>
        <div class="pta-stat pta-stat-gold"><div class="pta-stat-n"><?php echo isset($counts['pending']) ? $counts['pending']->count : 0; ?></div><div class="pta-stat-l">Pending</div></div>
        <div class="pta-stat"><div class="pta-stat-n"><?php echo isset($counts['inactive']) ? $counts['inactive']->count : 0; ?></div><div class="pta-stat-l">Inactive</div></div>
    </div>

    <!-- Filter Tabs -->
    <div class="pta-tabs">
        <a href="<?php echo esc_url($base_url); ?>" class="pta-tab <?php echo !$status && !$filter ? 'active' : ''; ?>">All <span class="cnt"><?php echo $total; ?></span></a>
        <a href="<?php echo esc_url($base_url . '&status=active'); ?>" class="pta-tab <?php echo $status === 'active' && !$filter ? 'active' : ''; ?>">Active <span class="cnt"><?php echo isset($counts['active']) ? $counts['active']->count : 0; ?></span></a>
        <a href="<?php echo esc_url($base_url . '&status=pending'); ?>" class="pta-tab <?php echo $status === 'pending' ? 'active' : ''; ?>">Pending <span class="cnt"><?php echo isset($counts['pending']) ? $counts['pending']->count : 0; ?></span></a>
        <a href="<?php echo esc_url($base_url . '&status=inactive'); ?>" class="pta-tab <?php echo $status === 'inactive' ? 'active' : ''; ?>">Inactive</a>
        <?php if ($incomplete_count > 0): ?>
        <a href="<?php echo esc_url($base_url . '&filter=incomplete_onboarding'); ?>" class="pta-tab <?php echo $filter === 'incomplete_onboarding' ? 'active' : ''; ?>" style="color:#dc2626">Incomplete <span class="cnt" style="background:#dc2626;color:#fff"><?php echo $incomplete_count; ?></span></a>
        <?php endif; ?>
    </div>

    <!-- Toolbar -->
    <div class="pta-toolbar">
        <form method="get" class="pta-search">
            <input type="hidden" name="page" value="ptp-trainers">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search trainers...">
            <button type="submit" class="pta-btn pta-btn-outline pta-btn-sm">Search</button>
        </form>
    </div>

    <?php if ($filter === 'incomplete_onboarding'): ?>
    <div class="pta-alert pta-alert-warn" style="margin-bottom:16px">
        These trainers were approved but haven't finished their profile. Automatic reminders are sent at 24hrs, 3 days, 7 days, and 14 days.
    </div>
    <?php endif; ?>

    <!-- Table -->
    <?php if ($trainers): ?>
    <div class="pta-table-wrap">
    <table class="pta-table">
        <thead><tr>
            <th>Trainer</th>
            <th>Details</th>
            <th>Compliance</th>
            <th>Status</th>
            <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($trainers as $t):
            $has_safesport = !empty($t->safesport_doc_url);
            $safesport_verified = !empty($t->safesport_verified);
            $has_w9 = !empty($t->w9_submitted);
            $has_stripe = !empty($t->stripe_account_id) && !empty($t->stripe_charges_enabled);
            $background_verified = !empty($t->background_verified);
            $compliance_complete = $safesport_verified && $has_w9;
        ?>
        <tr class="<?php echo !empty($t->is_featured) ? 'featured' : ''; ?>">
            <td>
                <div class="pta-user">
                    <div class="pta-avatar">
                        <?php if (!empty($t->photo_url)): ?>
                            <img src="<?php echo esc_url($t->photo_url); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($t->display_name, 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="pta-user-name">
                            <?php echo esc_html($t->display_name); ?>
                            <?php if (!empty($t->is_featured)): ?><span title="Featured" style="color:#F59E0B">&#11088;</span><?php endif; ?>
                            <?php if (!empty($t->is_verified)): ?><span title="Verified" style="color:#10B981">&#10003;</span><?php endif; ?>
                        </div>
                        <div class="pta-user-meta"><?php echo esc_html($t->user_email); ?></div>
                    </div>
                </div>
            </td>
            <!-- DETAILS: location + rate + sessions -->
            <td>
                <div style="font-size:12px"><?php echo esc_html($t->location ?: '-'); ?></div>
                <div style="font-size:12px;margin-top:2px"><strong>$<?php echo number_format($t->hourly_rate, 0); ?></strong>/hr &middot; <?php echo intval($t->total_sessions); ?> sessions</div>
            </td>
            <td>
                <div style="display:flex;gap:3px;flex-wrap:wrap">
                    <span class="pta-comp <?php echo $safesport_verified ? 'pta-comp-ok' : ($has_safesport ? 'pta-comp-wait' : 'pta-comp-no'); ?>" title="SafeSport">&#128737;<?php echo $safesport_verified ? '&#10003;' : ($has_safesport ? '&#8987;' : '&#10007;'); ?></span>
                    <span class="pta-comp <?php echo $has_w9 ? 'pta-comp-ok' : 'pta-comp-no'; ?>" title="W-9">&#128203;<?php echo $has_w9 ? '&#10003;' : '&#10007;'; ?></span>
                    <span class="pta-comp <?php echo $background_verified ? 'pta-comp-ok' : 'pta-comp-wait'; ?>" title="Background">&#128269;<?php echo $background_verified ? '&#10003;' : '-'; ?></span>
                </div>
            </td>
            <td><span class="pta-badge pta-badge-<?php echo esc_attr($t->status); ?>"><?php echo ucfirst($t->status); ?></span></td>
            <td>
                <div style="display:flex;gap:4px;flex-wrap:wrap">
                    <button type="button" class="pta-btn pta-btn-blue pta-btn-sm ptp-edit-trainer" data-id="<?php echo $t->id; ?>">Edit</button>
                    <?php if ($t->status === 'active'): ?>
                        <a href="<?php echo wp_nonce_url($base_url . '&action=deactivate&id=' . $t->id, 'ptp_trainer_action'); ?>" class="pta-btn pta-btn-outline pta-btn-sm" title="Deactivate">&#9208;</a>
                    <?php elseif ($compliance_complete): ?>
                        <a href="<?php echo wp_nonce_url($base_url . '&action=activate&id=' . $t->id, 'ptp_trainer_action'); ?>" class="pta-btn pta-btn-green pta-btn-sm" title="Activate">&#9654;</a>
                    <?php endif; ?>
                    <a href="<?php echo home_url('/trainer/' . $t->slug . '/'); ?>" class="pta-btn pta-btn-outline pta-btn-sm" target="_blank" title="View Profile">&#128065;</a>
                    <button type="button" class="pta-btn pta-btn-outline pta-btn-sm ptp-compliance-menu" data-id="<?php echo $t->id; ?>" title="Compliance Emails">&#128231;</button>
                    <a href="<?php echo wp_nonce_url($base_url . '&action=delete&id=' . $t->id, 'ptp_trainer_action'); ?>" class="pta-btn pta-btn-red pta-btn-sm" onclick="return confirm('DELETE <?php echo esc_js($t->display_name); ?>?\n\nThis is permanent.')" title="Delete">&#128465;</a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="pta-empty"><h3>No trainers found</h3><p>Trainers appear here once they apply.</p></div>
    <?php endif; ?>
</div>

<!-- ═══ EDIT TRAINER MODAL ═══ -->
<div class="pta-overlay" id="etm-overlay">
<div class="pta-modal">
    <div class="pta-modal-hdr">
        <h2>
            <div class="pta-avatar" id="etm-hdr-avatar" style="width:32px;height:32px;font-size:12px"><img id="etm-hdr-photo" src="" style="display:none"></div>
            <span id="etm-hdr-name">Edit Trainer</span>
        </h2>
        <button type="button" class="pta-modal-close" onclick="etmClose()">&times;</button>
    </div>

    <div class="pta-mtabs" id="etm-tabs">
        <button type="button" class="pta-mtab active" data-tab="profile">Profile</button>
        <button type="button" class="pta-mtab" data-tab="experience">Experience</button>
        <button type="button" class="pta-mtab" data-tab="pricing">Pricing</button>
        <button type="button" class="pta-mtab" data-tab="social">Social</button>
        <button type="button" class="pta-mtab" data-tab="compliance">Compliance</button>
        <button type="button" class="pta-mtab" data-tab="payments">Payments</button>
        <button type="button" class="pta-mtab" data-tab="schedule">Schedule</button>
        <button type="button" class="pta-mtab" data-tab="admin">Admin</button>
    </div>

    <div class="pta-modal-body">
        <form id="etm-form">
        <input type="hidden" name="trainer_id" id="edit-trainer-id">

        <!-- PROFILE -->
        <div class="pta-panel active" data-panel="profile">
            <div style="display:flex;gap:16px;align-items:flex-start;margin-bottom:16px">
                <div class="pta-photo" onclick="document.getElementById('edit-photo-file').click()">
                    <img id="edit-photo-img" src="" style="display:none">
                    <span id="edit-photo-placeholder" style="font-size:10px;color:#9ca3af;text-align:center">Upload<br>Photo</span>
                </div>
                <input type="file" id="edit-photo-file" accept="image/*" style="display:none">
                <input type="hidden" name="photo_url" id="edit-photo_url">
                <div style="flex:1">
                    <div id="edit-photo-status" style="font-size:12px;margin-bottom:4px"></div>
                    <button type="button" id="edit-photo-remove" class="pta-btn pta-btn-red pta-btn-sm" style="display:none" onclick="document.getElementById('edit-photo-img').style.display='none';document.getElementById('edit-photo-placeholder').style.display='';document.getElementById('edit-photo_url').value='';this.style.display='none'">Remove Photo</button>
                </div>
            </div>
            <div class="pta-row">
                <div class="pta-field"><label>Display Name</label><input class="pta-input" name="display_name" id="edit-display_name" required></div>
                <div class="pta-field"><label>URL Slug</label><input class="pta-input" name="slug" id="edit-slug"><div class="pta-hint">/trainer/this-value/</div></div>
            </div>
            <div class="pta-row">
                <div class="pta-field"><label>Email</label><input class="pta-input" name="email" id="edit-email" type="email"></div>
                <div class="pta-field"><label>Phone</label><input class="pta-input" name="phone" id="edit-phone"></div>
            </div>
            <div class="pta-field"><label>Headline</label><input class="pta-input" name="headline" id="edit-headline" placeholder="e.g., NCAA D1 Midfielder | Villanova University"></div>
            <div class="pta-field"><label>Bio</label><textarea class="pta-input" name="bio" id="edit-bio" rows="4"></textarea><div class="pta-hint" id="edit-bio-count">0 chars</div></div>
            <div class="pta-field"><label>Why Do You Coach?</label><textarea class="pta-input" name="coaching_why" id="edit-coaching_why" rows="3"></textarea></div>
            <div class="pta-field"><label>Training Philosophy</label><textarea class="pta-input" name="training_philosophy" id="edit-training_philosophy" rows="3"></textarea></div>
        </div>

        <!-- EXPERIENCE -->
        <div class="pta-panel" data-panel="experience">
            <div class="pta-row">
                <div class="pta-field"><label>Playing Level</label>
                    <select class="pta-input" name="playing_level" id="edit-playing_level">
                        <option value="">Select...</option>
                        <option value="pro">Professional</option>
                        <option value="college_d1">NCAA D1</option>
                        <option value="college_d2">NCAA D2</option>
                        <option value="college_d3">NCAA D3</option>
                        <option value="academy">Academy / MLS Next</option>
                        <option value="semi_pro">Semi-Pro</option>
                    </select>
                </div>
                <div class="pta-field"><label>Position</label><input class="pta-input" name="position" id="edit-position"></div>
            </div>
            <div class="pta-row">
                <div class="pta-field"><label>College</label><input class="pta-input" name="college" id="edit-college"></div>
                <div class="pta-field"><label>Team / Club</label><input class="pta-input" name="team" id="edit-team"></div>
            </div>
            <div class="pta-row">
                <div class="pta-field"><label>Years Playing</label><input class="pta-input" name="experience_years" id="edit-experience_years" type="number" min="0" max="40"></div>
                <div class="pta-field"><label>Years Coaching</label><input class="pta-input" name="years_coaching" id="edit-years_coaching" type="number" min="0" max="40"></div>
            </div>
            <div class="pta-field"><label>Specialties</label><input class="pta-input" name="specialties" id="edit-specialties" placeholder="e.g., dribbling, shooting, 1v1"></div>
        </div>

        <!-- PRICING & LOCATION -->
        <div class="pta-panel" data-panel="pricing">
            <div class="pta-row3">
                <div class="pta-field"><label>Hourly Rate ($)</label><input class="pta-input" name="hourly_rate" id="edit-hourly_rate" type="number" min="0" step="5"></div>
                <div class="pta-field"><label>Lesson Lengths</label><input class="pta-input" name="lesson_lengths" id="edit-lesson_lengths" placeholder="60"></div>
                <div class="pta-field"><label>Max Participants</label><input class="pta-input" name="max_participants" id="edit-max_participants" type="number" min="1" max="10"></div>
            </div>
            <div class="pta-row">
                <div class="pta-field"><label>City</label><input class="pta-input" name="city" id="edit-city"></div>
                <div class="pta-field"><label>State</label><input class="pta-input" name="state" id="edit-state" maxlength="2" placeholder="PA"></div>
            </div>
            <div class="pta-field"><label>Location (Display)</label><input class="pta-input" name="location" id="edit-location" placeholder="e.g., Villanova, PA"></div>
            <div class="pta-row">
                <div class="pta-field"><label>Travel Radius (mi)</label><input class="pta-input" name="travel_radius" id="edit-travel_radius" type="number" min="0"></div>
                <div class="pta-field"></div>
            </div>
            <!-- v200: Google Maps + Location Checkboxes -->
            <div class="pta-field">
                <label>Training Locations</label>
                <p style="font-size:12px;color:#6B7280;margin:0 0 8px">Add locations where this trainer is available. Type an address to auto-fill coordinates.</p>
                <div id="pta-locations-map" style="width:100%;height:220px;border-radius:10px;border:1px solid #e5e7eb;margin-bottom:12px;overflow:hidden;background:#f3f4f6">
                    <iframe id="pta-map-iframe" width="100%" height="220" style="border:0;display:block" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" src=""></iframe>
                </div>
                <div id="pta-loc-list" style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px"></div>
                <div style="display:flex;gap:8px;align-items:center">
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:6px 14px" onclick="ptaAddLoc()">+ Add Location</button>
                    <div style="position:relative;flex:1">
                        <select id="pta-quick-add" style="width:100%;padding:6px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;color:#6B7280;background:#fff;cursor:pointer" onchange="ptaQuickAdd(this)">
                            <option value="">Quick add PTP location...</option>
                            <?php
                            $master_locs = PTP_Admin::get_ptp_training_locations();
                            foreach ($master_locs as $lk => $lv): ?>
                            <option value="<?php echo esc_attr(wp_json_encode($lv)); ?>"><?php echo esc_html($lv['name']); ?> — <?php echo esc_html($lv['address']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <!-- Hidden field carries the final JSON for the AJAX save -->
                <input type="hidden" name="training_locations" id="edit-training_locations">
                <input type="hidden" name="latitude" id="edit-latitude">
                <input type="hidden" name="longitude" id="edit-longitude">
            </div>
        </div>

        <!-- SOCIAL -->
        <div class="pta-panel" data-panel="social">
            <div class="pta-row">
                <div class="pta-field"><label>Instagram</label><input class="pta-input" name="instagram" id="edit-instagram" placeholder="@handle"></div>
                <div class="pta-field"><label>Facebook</label><input class="pta-input" name="facebook" id="edit-facebook"></div>
            </div>
            <div class="pta-field"><label>Twitter / X</label><input class="pta-input" name="twitter" id="edit-twitter"></div>
            <div class="pta-field"><label>Intro Video URL</label><input class="pta-input" name="intro_video_url" id="edit-intro_video_url" placeholder="https://youtube.com/..."></div>
            <div class="pta-field"><label>Cover Photo URL</label><input class="pta-input" name="cover_photo_url" id="edit-cover_photo_url"></div>

            <!-- v213: Gallery Images -->
            <div class="pta-field" style="margin-top:8px">
                <label>Gallery Images</label>
                <p style="font-size:12px;color:#6B7280;margin:0 0 8px">Action shots, training sessions, credentials. Drag to reorder. Shown on the trainer's public profile.</p>
                <div id="pta-gallery-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px;margin-bottom:10px"></div>
                <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:6px 14px" onclick="ptaAddGalleryImages()">+ Add Images</button>
                <input type="hidden" name="gallery" id="edit-gallery">
            </div>
        </div>

        <!-- COMPLIANCE -->
        <div class="pta-panel" data-panel="compliance">
            <div class="pta-check" style="border-color:#10b981;margin-bottom:12px">
                <input type="checkbox" name="safesport_verified" id="edit-safesport_verified" value="1">
                <div><div class="pta-check-label">&#128737; SafeSport Verified</div><div class="pta-check-desc" id="edit-safesport-meta"></div></div>
            </div>
            <div class="pta-row" style="margin-bottom:12px">
                <div class="pta-field"><label>SafeSport Expiry</label><input class="pta-input" name="safesport_expiry" id="edit-safesport_expiry" type="date"></div>
                <div class="pta-field"><label>SafeSport Doc URL</label><input class="pta-input" name="safesport_doc_url" id="edit-safesport_doc_url"></div>
            </div>
            <div class="pta-check" style="margin-bottom:12px">
                <input type="checkbox" name="background_verified" id="edit-background_verified" value="1">
                <div><div class="pta-check-label">&#128269; Background Check</div><div class="pta-check-desc" id="edit-background-meta"></div></div>
            </div>
            <div class="pta-field"><label>Background Doc URL</label><input class="pta-input" name="background_doc_url" id="edit-background_doc_url"></div>
            <div class="pta-check" style="margin-bottom:12px">
                <input type="checkbox" name="w9_submitted" id="edit-w9_submitted" value="1">
                <div><div class="pta-check-label">&#128203; W-9 Submitted</div><div class="pta-check-desc" id="edit-w9-meta"></div></div>
            </div>
            <div class="pta-check" style="margin-bottom:12px">
                <input type="checkbox" name="contractor_agreement_signed" id="edit-contractor_agreement_signed" value="1">
                <div><div class="pta-check-label">&#128221; Contractor Agreement</div><div class="pta-check-desc" id="edit-agreement-meta"></div></div>
            </div>
            <div class="pta-check">
                <input type="checkbox" name="is_verified" id="edit-is_verified" value="1">
                <div><div class="pta-check-label">&#10003; Verified Badge</div><div class="pta-check-desc">Shows verified checkmark on profile</div></div>
            </div>
        </div>

        <!-- PAYMENTS -->
        <div class="pta-panel" data-panel="payments">
            <div class="pta-field"><label>Payout Method</label>
                <select class="pta-input" name="payout_method" id="edit-payout_method">
                    <option value="stripe">Stripe</option>
                    <option value="venmo">Venmo</option>
                    <option value="paypal">PayPal</option>
                    <option value="zelle">Zelle</option>
                    <option value="cashapp">Cash App</option>
                </select>
            </div>
            <div style="background:#f9fafb;padding:16px;border-radius:8px;margin-bottom:14px;border:1px solid #e5e7eb" id="edit-stripe-info">
                <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:block">Stripe Connect</label>
                <div id="edit-stripe-details" style="font-size:13px">Not connected</div>
            </div>
            <div class="pta-row">
                <div class="pta-field"><label>Venmo</label><input class="pta-input" name="payout_venmo" id="edit-payout_venmo"></div>
                <div class="pta-field"><label>PayPal</label><input class="pta-input" name="payout_paypal" id="edit-payout_paypal"></div>
            </div>
            <div class="pta-row">
                <div class="pta-field"><label>Zelle</label><input class="pta-input" name="payout_zelle" id="edit-payout_zelle"></div>
                <div class="pta-field"><label>Cash App</label><input class="pta-input" name="payout_cashapp" id="edit-payout_cashapp"></div>
            </div>
        </div>

        <!-- ADMIN -->
        <!-- Schedule Panel -->
        <div class="pta-panel" data-panel="schedule">
            <div id="sched-container" style="padding:4px 0">
                <div style="text-align:center;padding:20px;color:#9ca3af">Loading schedule...</div>
            </div>
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e5e7eb">
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:8px">Block a Date</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                    <input type="date" id="sched-block-date" min="<?php echo date('Y-m-d'); ?>" class="pta-input" style="width:160px">
                    <input type="text" id="sched-block-reason" placeholder="Reason (optional)" class="pta-input" style="width:180px">
                    <button type="button" class="pta-btn pta-btn-outline" onclick="schedBlockDate()" style="height:36px">Block</button>
                </div>
                <div id="sched-blocked-list" style="margin-top:12px;max-height:200px;overflow-y:auto"></div>
            </div>
        </div>

        <div class="pta-panel" data-panel="admin">
            <div class="pta-row3">
                <div class="pta-field"><label>Status</label>
                    <select class="pta-input" name="status" id="edit-status">
                        <option value="pending">Pending</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div class="pta-field"><label>Sort Order</label><input class="pta-input" name="sort_order" id="edit-sort_order" type="number" min="0"></div>
                <div class="pta-field"><label>User ID</label><input class="pta-input" id="edit-user_id" readonly style="background:#f3f4f6"></div>
            </div>
            <div class="pta-row" style="margin-bottom:16px">
                <div class="pta-check" style="background:#fef9c3;border-color:#fde047">
                    <input type="checkbox" name="is_featured" id="edit-is_featured" value="1">
                    <div><div class="pta-check-label">&#11088; Featured Trainer</div><div class="pta-check-desc">Shows first in search</div></div>
                </div>
                <div class="pta-check" style="background:#f0fdf4;border-color:#bbf7d0">
                    <input type="checkbox" name="is_supercoach" id="edit-is_supercoach" value="1">
                    <div><div class="pta-check-label">&#127942; Supercoach</div><div class="pta-check-desc">Top performer badge</div></div>
                </div>
            </div>
            
            <!-- Account Management -->
            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-bottom:16px;background:#f9fafb">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#374151;margin-bottom:12px;display:flex;align-items:center;gap:6px">&#128272; Account Management</div>
                
                <!-- Set Email -->
                <div style="margin-bottom:12px">
                    <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:4px">Email / Login</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input class="pta-input" id="pta-new-email" placeholder="trainer@email.com" style="font-size:13px;padding:7px 10px;flex:1">
                        <button type="button" class="pta-btn pta-btn-blue" style="font-size:12px;padding:7px 14px;height:36px;white-space:nowrap" onclick="ptaUpdateEmail()">Set Email</button>
                    </div>
                    <div id="pta-email-result" style="display:none;margin-top:6px;font-size:12px;padding:6px 10px;border-radius:6px"></div>
                </div>
                
                <!-- Set Password -->
                <div style="margin-bottom:12px">
                    <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:4px">Password</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <div style="flex:1;position:relative">
                            <input class="pta-input" id="pta-custom-password" type="text" placeholder="Enter password or leave blank to auto-generate" style="font-size:13px;padding:7px 10px;padding-right:36px;width:100%">
                            <button type="button" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;padding:4px;color:#6b7280" onclick="ptaGeneratePassword()" title="Generate random password">&#127922;</button>
                        </div>
                        <button type="button" class="pta-btn pta-btn-blue" style="font-size:12px;padding:7px 14px;height:36px;white-space:nowrap" onclick="ptaSetPassword(false)">Set</button>
                        <button type="button" class="pta-btn pta-btn-gold" style="font-size:12px;padding:7px 14px;height:36px;white-space:nowrap" onclick="ptaSetPassword(true)">Set & Email</button>
                    </div>
                    <div id="pta-pw-result" style="display:none;padding:8px 12px;background:#fff;border:1px solid #d1d5db;border-radius:8px;font-size:13px;margin-top:6px">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <span id="pta-pw-result-text"></span>
                            <button type="button" class="pta-btn pta-btn-outline" style="font-size:11px;padding:3px 10px;height:auto" onclick="ptaCopyPassword()">Copy</button>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:8px;border-top:1px solid #e5e7eb">
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:7px 14px;color:#7c3aed;border-color:#c4b5fd" onclick="ptaImpersonate()">&#128065; Login As Trainer</button>
                </div>
            </div>
            
            <!-- Nudge Center -->
            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-bottom:16px;background:#fff">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#374151;margin-bottom:4px;display:flex;align-items:center;gap:6px">&#128227; Nudge Center</div>
                <div id="pta-last-nudge" style="font-size:11px;color:#9ca3af;margin-bottom:12px"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px">
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:8px 10px;justify-content:flex-start;gap:6px" onclick="ptaNudge('onboarding')">&#128221; Complete Onboarding</button>
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:8px 10px;justify-content:flex-start;gap:6px" onclick="ptaNudge('photo')">&#128247; Upload Photo</button>
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:8px 10px;justify-content:flex-start;gap:6px" onclick="ptaNudge('safesport')">&#128737; SafeSport</button>
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:8px 10px;justify-content:flex-start;gap:6px" onclick="ptaNudge('w9')">&#128203; Submit W-9</button>
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:8px 10px;justify-content:flex-start;gap:6px" onclick="ptaNudge('background')">&#128269; Background Check</button>
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:8px 10px;justify-content:flex-start;gap:6px" onclick="ptaNudge('stripe')">&#128179; Connect Stripe</button>
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:8px 10px;justify-content:flex-start;gap:6px" onclick="ptaNudge('availability')">&#128197; Set Availability</button>
                    <button type="button" class="pta-btn pta-btn-outline" style="font-size:12px;padding:8px 10px;justify-content:flex-start;gap:6px;color:#7c3aed;border-color:#c4b5fd" onclick="ptaNudgeCustom()">&#9998; Custom Message</button>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" id="pta-nudge-sms" style="accent-color:#FCB900"> Also send SMS</label>
                    <span id="pta-nudge-phone-hint" style="font-size:11px;color:#9ca3af"></span>
                </div>
                <div id="pta-nudge-result" style="display:none;margin-top:10px;font-size:12px;padding:8px 12px;border-radius:6px"></div>
            </div>
            
            <div class="pta-stat-grid" id="edit-stats-grid">
                <div class="pta-stat-box"><div class="pta-stat-box-n" id="edit-stat-sessions">0</div><div class="pta-stat-box-l">Sessions</div></div>
                <div class="pta-stat-box"><div class="pta-stat-box-n" id="edit-stat-earnings">$0</div><div class="pta-stat-box-l">Earnings</div></div>
                <div class="pta-stat-box"><div class="pta-stat-box-n" id="edit-stat-rating">0.0</div><div class="pta-stat-box-l">Rating (<span id="edit-stat-reviews">0</span>)</div></div>
                <div class="pta-stat-box"><div class="pta-stat-box-n" id="edit-stat-reliability">0%</div><div class="pta-stat-box-l">Reliability</div></div>
            </div>
            <div style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:11px;color:#6b7280">
                <div>Created: <span id="edit-stat-created">-</span></div>
                <div>Approved: <span id="edit-stat-approved">-</span></div>
                <div>Updated: <span id="edit-stat-updated">-</span></div>
                <div>ID: <span id="edit-stat-id">-</span></div>
            </div>
        </div>
        </form>
    </div>

    <div class="pta-modal-foot">
        <span style="font-size:12px;color:#9ca3af;flex:1" id="etm-save-status"></span>
        <a id="edit-view-profile-link" href="#" target="_blank" class="pta-btn pta-btn-outline">View Profile</a>
        <button type="button" class="pta-btn pta-btn-outline" onclick="etmClose()">Cancel</button>
        <button type="button" class="pta-btn pta-btn-gold" id="etm-save-btn" onclick="etmSave()">Save Changes</button>
    </div>
</div>
</div>

<!-- ═══ COMPLIANCE EMAIL MODAL ═══ -->
<div class="pta-overlay" id="comp-overlay">
<div class="pta-modal pta-modal-sm" style="padding:28px">
    <h2 style="margin:0 0 8px;font-size:18px">Send Compliance Request</h2>
    <p style="color:#6b7280;margin:0 0 20px;font-size:14px">Email the trainer to complete requirements.</p>
    <input type="hidden" id="compliance-trainer-id">
    <div style="display:flex;flex-direction:column;gap:10px">
        <button type="button" class="pta-btn pta-btn-outline" style="justify-content:flex-start" onclick="sendComplianceEmail('safesport')">&#128737; Request SafeSport</button>
        <button type="button" class="pta-btn pta-btn-outline" style="justify-content:flex-start" onclick="sendComplianceEmail('w9')">&#128203; Request W-9</button>
        <button type="button" class="pta-btn pta-btn-outline" style="justify-content:flex-start" onclick="sendComplianceEmail('background')">&#128269; Request Background Check</button>
    </div>
    <div style="margin-top:20px;text-align:right">
        <button type="button" class="pta-btn pta-btn-outline" onclick="document.getElementById('comp-overlay').classList.remove('active')">Close</button>
    </div>
</div>
</div>

<!-- ═══ CUSTOM NUDGE MODAL ═══ -->
<div class="pta-overlay" id="nudge-custom-overlay">
<div class="pta-modal pta-modal-sm" style="padding:28px">
    <h2 style="margin:0 0 8px;font-size:18px">&#9998; Custom Nudge</h2>
    <p style="color:#6b7280;margin:0 0:16px;font-size:14px">Send a custom message to this trainer via email (and optionally SMS).</p>
    <div style="margin-top:14px">
        <label style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:4px">Message</label>
        <textarea id="pta-custom-nudge-msg" class="pta-input" rows="4" placeholder="Hey! Just wanted to check in about..."></textarea>
    </div>
    <div style="margin-top:10px;display:flex;align-items:center;gap:8px">
        <label style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" id="pta-custom-nudge-sms" style="accent-color:#FCB900"> Also send SMS</label>
    </div>
    <div id="pta-custom-nudge-result" style="display:none;margin-top:10px;font-size:12px;padding:8px 12px;border-radius:6px"></div>
    <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="pta-btn pta-btn-outline" onclick="document.getElementById('nudge-custom-overlay').classList.remove('active')">Cancel</button>
        <button type="button" class="pta-btn pta-btn-gold" id="pta-custom-nudge-send" onclick="ptaSendCustomNudge()">Send Nudge</button>
    </div>
</div>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo $nonce; ?>';

    /* ═══ TAB SWITCHING ═══ */
    $(document).on('click', '.pta-mtab', function() {
        var tab = $(this).data('tab');
        $('.pta-mtab').removeClass('active');
        $(this).addClass('active');
        $('.pta-panel').removeClass('active');
        $('.pta-panel[data-panel="'+tab+'"]').addClass('active');
    });

    $('#edit-bio').on('input', function(){ $('#edit-bio-count').text(this.value.length + ' chars'); });

    /* ═══ PHOTO UPLOAD ═══ */
    $('#edit-photo-file').on('change', function() {
        var file = this.files[0]; if (!file) return;
        var tid = $('#edit-trainer-id').val(); if (!tid) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#edit-photo-img').attr('src', e.target.result).show();
            $('#etm-hdr-photo').attr('src', e.target.result).show();
            $('#edit-photo-placeholder').hide();
            $('#edit-photo-remove').show();
        };
        reader.readAsDataURL(file);
        $('#edit-photo-status').html('<span style="color:#F59E0B">Uploading...</span>');
        var fd = new FormData();
        fd.append('action', 'ptp_admin_upload_trainer_photo');
        fd.append('nonce', nonce);
        fd.append('trainer_id', tid);
        fd.append('photo', file);
        $.ajax({ url: ajaxurl, type: 'POST', data: fd, processData: false, contentType: false,
            success: function(r) {
                if (r.success) {
                    $('#edit-photo_url').val(r.data.photo_url);
                    $('#edit-photo-status').html('<span style="color:#059669">Uploaded!</span>');
                } else {
                    $('#edit-photo-status').html('<span style="color:#dc2626">'+r.data.message+'</span>');
                }
            },
            error: function() { $('#edit-photo-status').html('<span style="color:#dc2626">Upload failed</span>'); }
        });
    });

    /* ═══ LOAD TRAINER ═══ */
    $(document).on('click', '.ptp-edit-trainer', function() {
        var tid = $(this).data('id');
        $('#etm-save-status').text('Loading...');
        $('.pta-mtab').first().click();
        $.post(ajaxurl, { action: 'ptp_admin_get_trainer', trainer_id: tid, nonce: nonce }, function(res) {
            if (!res.success) { alert('Error loading trainer'); return; }
            var t = res.data;
            // Profile
            $('#edit-trainer-id').val(t.id);
            $('#edit-display_name').val(t.display_name||'');
            $('#edit-slug').val(t.slug||'');
            $('#edit-email').val(t.email||t.user_email||'');
            $('#edit-phone').val(t.phone||'');
            $('#edit-headline').val(t.headline||'');
            $('#edit-bio').val(t.bio||''); $('#edit-bio-count').text((t.bio||'').length+' chars');
            $('#edit-coaching_why').val(t.coaching_why||'');
            $('#edit-training_philosophy').val(t.training_philosophy||'');
            // Experience
            $('#edit-playing_level').val(t.playing_level||'');
            $('#edit-position').val(t.position||'');
            $('#edit-college').val(t.college||'');
            $('#edit-team').val(t.team||'');
            $('#edit-experience_years').val(t.experience_years||'');
            $('#edit-years_coaching').val(t.years_coaching||'');
            var s = t.specialties||''; if(s.charAt(0)==='['){try{s=JSON.parse(s).join(', ')}catch(e){}} $('#edit-specialties').val(s);
            // Pricing
            $('#edit-hourly_rate').val(t.hourly_rate||70);
            $('#edit-lesson_lengths').val(t.lesson_lengths||'60');
            $('#edit-max_participants').val(t.max_participants||1);
            $('#edit-city').val(t.city||'');
            $('#edit-state').val(t.state||'');
            $('#edit-travel_radius').val(t.travel_radius||15);
            $('#edit-location').val(t.location||'');
            $('#edit-training_locations').val(t.training_locations||'');
            $('#edit-latitude').val(t.latitude||'');
            $('#edit-longitude').val(t.longitude||'');
            // v200: Parse training_locations JSON and check matching location boxes
            ptaSetLocations(t.training_locations||'');
            // Social
            $('#edit-instagram').val(t.instagram||'');
            $('#edit-facebook').val(t.facebook||'');
            $('#edit-twitter').val(t.twitter||'');
            $('#edit-intro_video_url').val(t.intro_video_url||'');
            $('#edit-cover_photo_url').val(t.cover_photo_url||'');
            // v213: Gallery
            ptaSetGallery(t.gallery||'');
            // Compliance
            $('#edit-safesport_verified').prop('checked', t.safesport_verified==1);
            $('#edit-background_verified').prop('checked', t.background_verified==1);
            $('#edit-w9_submitted').prop('checked', t.w9_submitted==1);
            $('#edit-contractor_agreement_signed').prop('checked', t.contractor_agreement_signed==1);
            $('#edit-is_verified').prop('checked', t.is_verified==1);
            $('#edit-safesport_expiry').val(t.safesport_expiry||'');
            $('#edit-safesport_doc_url').val(t.safesport_doc_url||'');
            $('#edit-background_doc_url').val(t.background_doc_url||'');
            $('#edit-safesport-meta').text(t.safesport_verified==1?(t.safesport_expiry?'Exp: '+t.safesport_expiry:'Verified'):(t.safesport_requested_at?'Requested: '+t.safesport_requested_at.substring(0,10):'Not requested'));
            $('#edit-background-meta').text(t.background_verified==1?'Verified':(t.background_requested_at?'Requested: '+t.background_requested_at.substring(0,10):'Not requested'));
            $('#edit-w9-meta').text(t.w9_submitted==1?(t.w9_submitted_at?'Submitted: '+t.w9_submitted_at.substring(0,10):'Submitted'):(t.w9_requested_at?'Requested: '+t.w9_requested_at.substring(0,10):'Not requested'));
            $('#edit-agreement-meta').text(t.contractor_agreement_signed==1?(t.contractor_agreement_signed_at?'Signed: '+t.contractor_agreement_signed_at.substring(0,10):'Signed'):'Not signed');
            // Payments
            $('#edit-payout_method').val(t.payout_method||'stripe');
            $('#edit-payout_venmo').val(t.payout_venmo||'');
            $('#edit-payout_paypal').val(t.payout_paypal||'');
            $('#edit-payout_zelle').val(t.payout_zelle||'');
            $('#edit-payout_cashapp').val(t.payout_cashapp||'');
            var sh=''; if(t.stripe_account_id){sh='<div><strong>Account:</strong> '+t.stripe_account_id+'</div><div style="margin-top:4px">Charges: '+(t.stripe_charges_enabled==1?'<span style="color:#10B981">Enabled</span>':'<span style="color:#EF4444">Disabled</span>')+' &middot; Payouts: '+(t.stripe_payouts_enabled==1?'<span style="color:#10B981">Enabled</span>':'<span style="color:#EF4444">Disabled</span>')+' &middot; Onboarding: '+(t.stripe_onboarding_complete==1?'<span style="color:#10B981">Complete</span>':'<span style="color:#F59E0B">Incomplete</span>')+'</div>'}else{sh='<span style="color:#9CA3AF">Not connected</span>'}
            $('#edit-stripe-details').html(sh);
            // Admin
            $('#edit-status').val(t.status||'pending');
            $('#edit-sort_order').val(t.sort_order||0);
            $('#edit-user_id').val(t.user_id||'N/A');
            $('#edit-is_featured').prop('checked', t.is_featured==1);
            $('#edit-is_supercoach').prop('checked', t.is_supercoach==1);
            $('#edit-stat-sessions').text(t.total_sessions||0);
            $('#edit-stat-earnings').text('$'+parseFloat(t.total_earnings||0).toLocaleString());
            $('#edit-stat-rating').text(parseFloat(t.average_rating||0).toFixed(1));
            $('#edit-stat-reviews').text(t.review_count||0);
            $('#edit-stat-reliability').text((t.reliability_score||0)+'%');
            $('#edit-stat-created').text(t.created_at?t.created_at.substring(0,10):'-');
            $('#edit-stat-approved').text(t.approved_at?t.approved_at.substring(0,10):'-');
            $('#edit-stat-updated').text(t.updated_at?t.updated_at.substring(0,10):'-');
            $('#edit-stat-id').text(t.id);
            // Header
            $('#etm-hdr-name').text(t.display_name||'Edit Trainer');
            $('#edit-photo_url').val(t.photo_url||''); $('#edit-photo-status').html('');
            if(t.photo_url){$('#edit-photo-img').attr('src',t.photo_url).show();$('#etm-hdr-photo').attr('src',t.photo_url).show();$('#edit-photo-placeholder').hide();$('#edit-photo-remove').show()}
            else{$('#edit-photo-img').hide();$('#etm-hdr-photo').hide();$('#edit-photo-placeholder').show();$('#edit-photo-remove').hide()}
            var slug=t.slug||t.display_name.toLowerCase().replace(/\s+/g,'-').replace(/[^a-z0-9-]/g,'');
            $('#edit-view-profile-link').attr('href','<?php echo home_url('/trainer/'); ?>'+slug+'/');
            // v135: Account management fields
            $('#pta-new-email').val(t.email||t.user_email||'');
            $('#pta-custom-password').val('');
            $('#pta-pw-result').hide();
            $('#pta-email-result').hide();
            $('#pta-nudge-result').hide();
            lastResetPassword = '';
            currentTrainerPhone = t.phone||'';
            if (t.phone) {
                $('#pta-nudge-phone-hint').text('Phone: '+t.phone);
            } else {
                $('#pta-nudge-phone-hint').html('<span style="color:#dc2626">No phone on file</span>');
            }
            if (t.last_nudge_at && t.last_nudge_type) {
                var nd = new Date(t.last_nudge_at);
                var niceDate = nd.toLocaleDateString('en-US',{month:'short',day:'numeric'}) + ' ' + nd.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
                $('#pta-last-nudge').text('Last nudge: ' + t.last_nudge_type + ' — ' + niceDate);
            } else {
                $('#pta-last-nudge').text('No nudges sent yet');
            }
            $('#etm-save-status').text('');
            document.getElementById('etm-overlay').classList.add('active');
        });
    });

    /* ═══ SAVE TRAINER ═══ */
    window.etmSave = function() {
        var btn = $('#etm-save-btn');
        btn.prop('disabled',true).text('Saving...');
        var fd = new FormData(document.getElementById('etm-form'));
        fd.append('action','ptp_admin_update_trainer');
        fd.append('nonce', nonce);
        // Explicitly handle unchecked checkboxes
        ['safesport_verified','w9_submitted','background_verified','contractor_agreement_signed','is_verified','is_featured','is_supercoach'].forEach(function(f){
            if(!$('#edit-'+f).is(':checked')) fd.set(f,'0');
        });
        $.ajax({ url: ajaxurl, type: 'POST', data: fd, processData: false, contentType: false,
            success: function(r) {
                if(r.success){
                    $('#etm-save-status').html('<span style="color:#059669">Saved!</span>');
                    btn.text('Saved!');
                    setTimeout(function(){ btn.prop('disabled',false).text('Save Changes'); }, 2000);
                } else {
                    $('#etm-save-status').html('<span style="color:#dc2626">'+(r.data.message||'Error')+'</span>');
                    btn.prop('disabled',false).text('Save Changes');
                }
            },
            error: function() {
                $('#etm-save-status').html('<span style="color:#dc2626">Network error</span>');
                btn.prop('disabled',false).text('Save Changes');
            }
        });
    };
    window.etmClose = function(){ document.getElementById('etm-overlay').classList.remove('active'); };

    /* ═══ SCHEDULE MANAGEMENT ═══ */
    var schedLoaded = false;
    var schedTrainerId = 0;
    var dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    // Load schedule when tab clicked
    $(document).on('click', '.pta-mtab[data-tab="schedule"]', function() {
        schedTrainerId = $('#edit-trainer-id').val();
        if (!schedTrainerId) return;
        loadSchedule(schedTrainerId);
    });

    function loadSchedule(tid) {
        var c = document.getElementById('sched-container');
        c.innerHTML = '<div style="text-align:center;padding:20px"><span style="color:#9ca3af">Loading...</span></div>';
        $.post(ajaxurl, { action:'ptp_admin_get_trainer_schedule', trainer_id:tid, nonce:nonce }, function(r) {
            if (!r.success) { c.innerHTML = '<div style="color:#dc2626;padding:12px">Error loading schedule</div>'; return; }
            var s = r.data.schedule || {};
            var h = '<table style="width:100%;border-collapse:collapse;font-size:13px">';
            h += '<thead><tr style="background:#f9fafb"><th style="padding:8px 10px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;color:#6b7280">Day</th><th style="padding:8px 10px;width:60px;text-align:center;font-size:11px;font-weight:600;color:#6b7280">On</th><th style="padding:8px 10px;font-size:11px;font-weight:600;color:#6b7280">Start</th><th style="padding:8px 10px;font-size:11px;font-weight:600;color:#6b7280">End</th><th style="padding:8px 10px;width:70px"></th></tr></thead><tbody>';
            for (var d=0;d<7;d++) {
                var day = s[d] || {enabled:false,start:'09:00',end:'17:00'};
                h += '<tr id="sched-day-'+d+'" style="border-bottom:1px solid #f3f4f6">';
                h += '<td style="padding:8px 10px;font-weight:600">'+dayNames[d]+'</td>';
                h += '<td style="padding:8px 10px;text-align:center"><input type="checkbox" id="sched-on-'+d+'"'+(day.enabled?' checked':'')+' style="width:18px;height:18px;accent-color:#FCB900"></td>';
                h += '<td style="padding:8px 10px"><input type="time" id="sched-start-'+d+'" value="'+day.start+'" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:110px"></td>';
                h += '<td style="padding:8px 10px"><input type="time" id="sched-end-'+d+'" value="'+day.end+'" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:110px"></td>';
                h += '<td style="padding:8px 10px"><button type="button" class="pta-btn pta-btn-outline" style="font-size:11px;padding:4px 10px;height:28px" onclick="schedSaveDay('+d+')">Save</button></td>';
                h += '</tr>';
            }
            h += '</tbody></table>';
            if (r.data.gcal_connected) {
                h += '<div style="margin-top:10px;padding:8px 12px;background:#ecfdf5;border-radius:6px;font-size:12px;color:#065f46"><strong>Google Calendar:</strong> '+( r.data.gcal_email||'Connected')+(r.data.gcal_last_sync?' &middot; Synced: '+r.data.gcal_last_sync:'')+'</div>';
            }
            c.innerHTML = h;
            loadBlockedDates(tid);
        });
    }

    function loadBlockedDates(tid) {
        $.post(ajaxurl, { action:'ptp_admin_get_blocked_dates', trainer_id:tid, nonce:nonce }, function(r) {
            var el = document.getElementById('sched-blocked-list');
            if (!r.success||!r.data.blocked_dates||r.data.blocked_dates.length===0) { el.innerHTML='<div style="color:#9ca3af;font-size:12px;padding:4px 0">No blocked dates</div>'; return; }
            var h='';
            r.data.blocked_dates.forEach(function(b){
                var dt = new Date(b.date+'T12:00:00');
                var disp = dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
                h+='<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:13px">';
                h+='<span><strong>'+disp+'</strong>'+(b.reason?' &mdash; <span style="color:#6b7280">'+b.reason+'</span>':'')+'</span>';
                h+='<button type="button" class="pta-btn pta-btn-outline" style="font-size:11px;padding:2px 8px;height:24px;color:#dc2626;border-color:#fca5a5" onclick="schedUnblock(\''+b.date+'\')">Remove</button>';
                h+='</div>';
            });
            el.innerHTML=h;
        });
    }

    window.schedSaveDay = function(day) {
        var tid = $('#edit-trainer-id').val();
        var en = document.getElementById('sched-on-'+day).checked?'1':'0';
        var st = document.getElementById('sched-start-'+day).value||'09:00';
        var nd = document.getElementById('sched-end-'+day).value||'17:00';
        var row = document.getElementById('sched-day-'+day);
        var btn = row.querySelector('button');
        btn.textContent='...'; btn.disabled=true;
        $.post(ajaxurl, {action:'ptp_admin_save_trainer_schedule',trainer_id:tid,day:day,enabled:en,start:st,end:nd,nonce:nonce}, function(r){
            btn.disabled=false;
            if(r.success){btn.textContent='Saved!';row.style.background='#ecfdf5';setTimeout(function(){row.style.background='';btn.textContent='Save'},1200)}
            else{btn.textContent='Error';setTimeout(function(){btn.textContent='Save'},1500)}
        });
    };

    window.schedBlockDate = function() {
        var tid = $('#edit-trainer-id').val();
        var dt = $('#sched-block-date').val();
        var reason = $('#sched-block-reason').val();
        if(!dt){alert('Pick a date');return}
        $.post(ajaxurl,{action:'ptp_admin_block_trainer_date',trainer_id:tid,date:dt,reason:reason,nonce:nonce},function(r){
            if(r.success){$('#sched-block-date').val('');$('#sched-block-reason').val('');loadBlockedDates(tid)}
            else{alert(r.data&&r.data.message?r.data.message:'Failed')}
        });
    };

    window.schedUnblock = function(dt) {
        var tid = $('#edit-trainer-id').val();
        if(!confirm('Remove this blocked date?'))return;
        $.post(ajaxurl,{action:'ptp_admin_unblock_trainer_date',trainer_id:tid,date:dt,nonce:nonce},function(r){
            if(r.success) loadBlockedDates(tid);
        });
    };

    /* ═══ COMPLIANCE ═══ */
    $(document).on('click', '.ptp-compliance-menu', function() {
        $('#compliance-trainer-id').val($(this).data('id'));
        document.getElementById('comp-overlay').classList.add('active');
    });
    
    /* ═══ QUICK NUDGE from table row — opens edit modal on Admin tab ═══ */
    $(document).on('click', '.ptp-quick-nudge', function() {
        var tid = $(this).data('id');
        // Trigger the edit modal load
        $('.ptp-edit-trainer[data-id="'+tid+'"]').click();
        // Switch to admin tab after a brief delay for modal to load
        setTimeout(function() {
            $('.pta-mtab[data-tab="admin"]').click();
        }, 500);
    });
    window.sendComplianceEmail = function(type) {
        var tid = $('#compliance-trainer-id').val();
        var action = 'ptp_admin_send_' + type + '_request';
        $.post(ajaxurl, { action: action, trainer_id: tid, nonce: nonce }, function(r) {
            alert(r.success ? 'Email sent!' : (r.data.message || 'Failed'));
        });
    };

    /* ═══ v135: ACCOUNT MANAGEMENT ═══ */
    var currentTrainerPhone = '';
    var lastResetPassword = '';
    
    window.ptaSetPassword = function(sendEmail) {
        var tid = $('#edit-trainer-id').val();
        if (!tid) return;
        var customPw = $('#pta-custom-password').val().trim();
        var msg = sendEmail 
            ? 'Set password' + (customPw ? ' to "' + customPw + '"' : ' (auto-generated)') + ' and email credentials to this trainer?' 
            : 'Set password' + (customPw ? ' to "' + customPw + '"' : ' (auto-generated)') + '? You\'ll need to share it manually.';
        if (!confirm(msg)) return;
        var $res = $('#pta-pw-result');
        $res.show().find('#pta-pw-result-text').html('<span style="color:#F59E0B">Setting...</span>');
        $.post(ajaxurl, { action:'ptp_admin_reset_trainer_password', trainer_id:tid, send_email:sendEmail?1:0, custom_password:customPw, nonce:nonce }, function(r) {
            if (r.success) {
                lastResetPassword = r.data.password;
                $res.css({background:'#ecfdf5',borderColor:'#86efac'}).find('#pta-pw-result-text').html('<strong>Password set:</strong> <code style="background:#fff;padding:2px 8px;border-radius:4px;font-size:14px;user-select:all">' + r.data.password + '</code><br><span style="font-size:11px;color:#059669">' + r.data.message + '</span>');
            } else {
                $res.css({background:'#fef2f2',borderColor:'#fca5a5'}).find('#pta-pw-result-text').html('<span style="color:#dc2626">' + (r.data.message||'Failed') + '</span>');
            }
        });
    };
    
    window.ptaGeneratePassword = function() {
        var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
        var pw = '';
        for (var i = 0; i < 12; i++) pw += chars.charAt(Math.floor(Math.random() * chars.length));
        $('#pta-custom-password').val(pw).select();
    };
    
    window.ptaResetPassword = function(sendEmail) {
        // Legacy compat — redirect to new method
        $('#pta-custom-password').val('');
        ptaSetPassword(sendEmail);
    };
    
    window.ptaCopyPassword = function() {
        if (lastResetPassword) {
            navigator.clipboard.writeText(lastResetPassword).then(function() {
                $('#pta-pw-result').find('#pta-pw-result-text').append(' <span style="color:#059669;font-size:11px">Copied!</span>');
            });
        }
    };
    
    window.ptaUpdateEmail = function() {
        var tid = $('#edit-trainer-id').val();
        var newEmail = $('#pta-new-email').val().trim();
        if (!newEmail) { alert('Enter an email address'); return; }
        if (!confirm('Update this trainer\'s login email to ' + newEmail + '?')) return;
        var $res = $('#pta-email-result');
        $res.show().css({background:'#fef9c3',color:'#92400e'}).text('Updating...');
        $.post(ajaxurl, { action:'ptp_admin_update_trainer_email', trainer_id:tid, new_email:newEmail, nonce:nonce }, function(r) {
            if (r.success) {
                $res.css({background:'#ecfdf5',color:'#065f46'}).text(r.data.message);
                $('#edit-email').val(newEmail);
            } else {
                $res.css({background:'#fef2f2',color:'#991b1b'}).text(r.data.message||'Failed');
            }
        });
    };
    
    window.ptaImpersonate = function() {
        var tid = $('#edit-trainer-id').val();
        var name = $('#edit-display_name').val();
        if (!confirm('Login as ' + name + '?\n\nThis will open their trainer dashboard in a new tab. You\'ll be logged in as them for this browser session.')) return;
        $.post(ajaxurl, { action:'ptp_admin_impersonate_trainer', trainer_id:tid, nonce:nonce }, function(r) {
            if (r.success) {
                window.open(r.data.url, '_blank');
            } else {
                alert(r.data.message||'Failed');
            }
        });
    };
    
    /* ═══ v135: NUDGE SYSTEM ═══ */
    window.ptaNudge = function(type) {
        var tid = $('#edit-trainer-id').val();
        var name = $('#edit-display_name').val();
        var sms = $('#pta-nudge-sms').is(':checked') ? 1 : 0;
        
        var labels = {onboarding:'Complete Onboarding',photo:'Upload Photo',safesport:'SafeSport',w9:'W-9',background:'Background Check',stripe:'Connect Stripe',availability:'Set Availability'};
        if (!confirm('Send "' + (labels[type]||type) + '" nudge to ' + name + '?' + (sms ? '\n\n(Email + SMS)' : '\n\n(Email only)'))) return;
        
        var $res = $('#pta-nudge-result');
        $res.show().css({background:'#fef9c3',color:'#92400e'}).text('Sending...');
        
        $.post(ajaxurl, { action:'ptp_admin_nudge_trainer', trainer_id:tid, nudge_type:type, send_sms:sms, nonce:nonce }, function(r) {
            if (r.success) {
                $res.css({background:'#ecfdf5',color:'#065f46'}).text(r.data.message);
                $('#pta-last-nudge').text('Last nudge: ' + type + ' — just now');
            } else {
                $res.css({background:'#fef2f2',color:'#991b1b'}).text(r.data.message||'Failed');
            }
        });
    };
    
    window.ptaNudgeCustom = function() {
        $('#pta-custom-nudge-msg').val('');
        $('#pta-custom-nudge-result').hide();
        document.getElementById('nudge-custom-overlay').classList.add('active');
    };
    
    window.ptaSendCustomNudge = function() {
        var tid = $('#edit-trainer-id').val();
        var msg = $('#pta-custom-nudge-msg').val().trim();
        if (!msg) { alert('Enter a message'); return; }
        var sms = $('#pta-custom-nudge-sms').is(':checked') ? 1 : 0;
        var $btn = $('#pta-custom-nudge-send');
        var $res = $('#pta-custom-nudge-result');
        $btn.prop('disabled',true).text('Sending...');
        $res.show().css({background:'#fef9c3',color:'#92400e'}).text('Sending...');
        
        $.post(ajaxurl, { action:'ptp_admin_nudge_trainer', trainer_id:tid, nudge_type:'custom', custom_message:msg, send_sms:sms, nonce:nonce }, function(r) {
            $btn.prop('disabled',false).text('Send Nudge');
            if (r.success) {
                $res.css({background:'#ecfdf5',color:'#065f46'}).text(r.data.message);
                $('#pta-last-nudge').text('Last nudge: custom — just now');
            } else {
                $res.css({background:'#fef2f2',color:'#991b1b'}).text(r.data.message||'Failed');
            }
        });
    };

    /* ═══ CLOSE OVERLAYS ═══ */
    ['etm-overlay','comp-overlay','nudge-custom-overlay'].forEach(function(id){
        document.getElementById(id).addEventListener('click',function(e){if(e.target===this)this.classList.remove('active')});
    });
    $(document).on('keydown', function(e){ if(e.key==='Escape'){ etmClose(); document.getElementById('comp-overlay').classList.remove('active'); document.getElementById('nudge-custom-overlay').classList.remove('active'); }});

    /* ═══ v213: TRAINING LOCATIONS — Free-form repeater + Google Maps ═══ */
    var ptaLocs = []; // Array of {name, address, lat, lng}
    var ptaAutocompletes = {};

    // Load saved locations into the repeater
    window.ptaSetLocations = function(json) {
        ptaLocs = [];
        if (json) {
            try { ptaLocs = JSON.parse(json); } catch(e) {
                try { ptaLocs = JSON.parse(json.replace(/\\\\/g, '').replace(/\\"/g, '"')); } catch(e2) {}
            }
        }
        if (!Array.isArray(ptaLocs)) ptaLocs = [];
        ptaRender();
    };

    // Render the location list
    function ptaRender() {
        var $list = $('#pta-loc-list');
        $list.empty();
        ptaLocs.forEach(function(loc, i) {
            var hasCoords = loc.lat && loc.lng;
            var card = $('<div style="display:flex;gap:8px;align-items:flex-start;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;transition:border-color .2s">' +
                '<div style="flex:1;display:flex;flex-direction:column;gap:6px">' +
                    '<div style="display:flex;gap:8px">' +
                        '<input type="text" class="pta-input pta-loc-name" data-i="'+i+'" value="'+$('<span>').text(loc.name||'').html()+'" placeholder="Location name" style="flex:1;padding:6px 10px;font-size:13px;font-weight:600">' +
                        '<input type="text" class="pta-input pta-loc-addr" data-i="'+i+'" value="'+$('<span>').text(loc.address||'').html()+'" placeholder="Address (type to search)" style="flex:1.2;padding:6px 10px;font-size:13px">' +
                    '</div>' +
                    '<div style="display:flex;gap:6px;align-items:center;font-size:11px;color:#9CA3AF">' +
                        (hasCoords ? '<span style="background:#f0fdf4;color:#16a34a;padding:1px 6px;border-radius:4px;font-size:10px">'+Number(loc.lat).toFixed(4)+', '+Number(loc.lng).toFixed(4)+'</span>' : '<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;font-size:10px">No coordinates — type address to auto-fill</span>') +
                    '</div>' +
                '</div>' +
                '<button type="button" onclick="ptaRemoveLoc('+i+')" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:18px;padding:4px 6px;line-height:1;opacity:.5;transition:opacity .2s" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.5" title="Remove">&times;</button>' +
            '</div>');
            $list.append(card);
        });

        // Bind change handlers
        $list.find('.pta-loc-name').on('input', function() {
            ptaLocs[$(this).data('i')].name = $(this).val();
            ptaSyncHidden();
        });
        $list.find('.pta-loc-addr').on('input', function() {
            ptaLocs[$(this).data('i')].address = $(this).val();
            ptaSyncHidden();
        });

        // Init Places Autocomplete on address fields (separated for retry)
        ptaInitAutocompletes();

        ptaSyncHidden();
        ptaUpdateMap();
    }

    // Separate function: attach Google Places Autocomplete to all address fields
    // Can be called multiple times safely (skips already-initialized fields)
    function ptaInitAutocompletes() {
        if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
            // Places API not ready yet — poll for it
            if (!window._ptaPlacesPollActive) {
                window._ptaPlacesPollActive = true;
                var checks = 0;
                var timer = setInterval(function() {
                    checks++;
                    if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                        clearInterval(timer);
                        window._ptaPlacesPollActive = false;
                        ptaInitAutocompletes();
                    } else if (checks > 100) {
                        clearInterval(timer);
                        window._ptaPlacesPollActive = false;
                        console.warn('PTA: Google Places API failed to load after 20s');
                    }
                }, 200);
            }
            return;
        }
        var $list = $('#pta-loc-list');
        $list.find('.pta-loc-addr').each(function() {
            // Skip if already initialized
            if (this.dataset.pacInit === '1') return;
            var idx = parseInt($(this).data('i'));
            var el = this;
            try {
                var ac = new google.maps.places.Autocomplete(el, {
                    types: ['establishment', 'geocode'],
                    componentRestrictions: { country: 'us' },
                    fields: ['geometry', 'formatted_address', 'name', 'place_id']
                });
                ac.addListener('place_changed', function() {
                    var place = ac.getPlace();
                    if (place && place.geometry) {
                        ptaLocs[idx].lat = place.geometry.location.lat();
                        ptaLocs[idx].lng = place.geometry.location.lng();
                        if (place.formatted_address) {
                            ptaLocs[idx].address = place.formatted_address;
                            $(el).val(place.formatted_address);
                        }
                        if (place.place_id) ptaLocs[idx].place_id = place.place_id;
                        // Auto-fill name from place if name field is empty
                        if (!ptaLocs[idx].name && place.name) {
                            ptaLocs[idx].name = place.name;
                            $list.find('.pta-loc-name[data-i="'+idx+'"]').val(place.name);
                        }
                        ptaSyncHidden();
                        ptaRender(); // Re-render to show coords
                        ptaUpdateMap();
                    }
                });
                el.dataset.pacInit = '1';
            } catch(e) {
                console.warn('PTA: Autocomplete init failed for index', idx, e);
            }
        });
    }

    // Add blank location
    window.ptaAddLoc = function() {
        ptaLocs.push({name:'', address:'', lat:null, lng:null});
        ptaRender();
        // Focus the new name field
        $('#pta-loc-list .pta-loc-name').last().focus();
    };

    // Quick-add from PTP preset dropdown
    window.ptaQuickAdd = function(sel) {
        if (!sel.value) return;
        try {
            var loc = JSON.parse(sel.value);
            // Check if already added
            var exists = ptaLocs.some(function(l) { return l.name === loc.name; });
            if (!exists) {
                ptaLocs.push({name: loc.name, address: loc.address, lat: loc.lat, lng: loc.lng});
                ptaRender();
            }
        } catch(e) {}
        sel.value = '';
    };

    // Remove location
    window.ptaRemoveLoc = function(i) {
        ptaLocs.splice(i, 1);
        ptaRender();
    };

    // Sync to hidden field
    function ptaSyncHidden() {
        // Filter out completely empty rows
        var valid = ptaLocs.filter(function(l) { return l.name || l.address; });
        $('#edit-training_locations').val(valid.length ? JSON.stringify(valid) : '');
        // Auto-set lat/lng centroid
        var withCoords = valid.filter(function(l) { return l.lat && l.lng; });
        if (withCoords.length) {
            var avgLat = 0, avgLng = 0;
            withCoords.forEach(function(l) { avgLat += l.lat; avgLng += l.lng; });
            $('#edit-latitude').val((avgLat / withCoords.length).toFixed(6));
            $('#edit-longitude').val((avgLng / withCoords.length).toFixed(6));
        }
    }

    // Update map iframe embed
    var ptaGmapsKey = '<?php echo esc_js(get_option("ptp_google_maps_api_key", "") ?: get_option("ptp_google_maps_key", "")); ?>';
    function ptaUpdateMap() {
        var iframe = document.getElementById('pta-map-iframe');
        if (!iframe || !ptaGmapsKey) return;
        var withCoords = ptaLocs.filter(function(l) { return l.lat && l.lng; });
        if (withCoords.length === 0) {
            // Default: Philly area
            iframe.src = 'https://www.google.com/maps/embed/v1/view?key=' + ptaGmapsKey + '&center=40.02,-75.35&zoom=10';
        } else if (withCoords.length === 1) {
            // Single location — place pin
            var l = withCoords[0];
            var q = l.address ? encodeURIComponent(l.address) : (l.lat + ',' + l.lng);
            iframe.src = 'https://www.google.com/maps/embed/v1/place?key=' + ptaGmapsKey + '&q=' + q + '&zoom=13';
        } else {
            // Multiple — center on midpoint
            var avgLat = 0, avgLng = 0;
            withCoords.forEach(function(l) { avgLat += Number(l.lat); avgLng += Number(l.lng); });
            avgLat /= withCoords.length; avgLng /= withCoords.length;
            // Calculate zoom from spread
            var maxDist = 0;
            withCoords.forEach(function(l) {
                var d = Math.abs(l.lat - avgLat) + Math.abs(l.lng - avgLng);
                if (d > maxDist) maxDist = d;
            });
            var zoom = maxDist > 0.5 ? 9 : maxDist > 0.2 ? 10 : maxDist > 0.1 ? 11 : 12;
            iframe.src = 'https://www.google.com/maps/embed/v1/view?key=' + ptaGmapsKey + '&center=' + avgLat.toFixed(6) + ',' + avgLng.toFixed(6) + '&zoom=' + zoom;
        }
    }

    // Init map embed on load
    (function(){
        if (!ptaGmapsKey) {
            document.getElementById('pta-locations-map').innerHTML = '<div style="text-align:center;padding:16px"><p style="color:#6B7280;font-size:12px;margin:0">Google Maps API key not set.</p><p style="margin:4px 0 0"><a href="<?php echo admin_url("admin.php?page=ptp-settings"); ?>" class="button button-small">Go to Settings</a></p></div>';
            return;
        }
        ptaUpdateMap();
        // Load Places API for address autocomplete
        // Must check for google.maps.places specifically — google.maps may exist without Places
        var needsPlaces = (typeof google === 'undefined' || typeof google.maps === 'undefined' || typeof google.maps.places === 'undefined');
        if (needsPlaces) {
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + ptaGmapsKey + '&libraries=places&callback=ptaOnPlacesReady';
            s.async = true; s.defer = true;
            document.head.appendChild(s);
        } else {
            // Places already available — init autocompletes immediately
            ptaInitAutocompletes();
        }
    })();

    // Once Places API loads, re-render to attach autocomplete to address fields
    window.ptaOnPlacesReady = function() {
        ptaRender();
        // Also retry autocomplete init in case render didn't catch everything
        setTimeout(ptaInitAutocompletes, 100);
    };

    // Refresh map embed when Pricing tab is clicked
    $(document).on('click', '.pta-mtab[data-tab="pricing"]', function() {
        setTimeout(ptaUpdateMap, 200);
    });

    /* ═══ v213: GALLERY IMAGES ═══ */
    var ptaGallery = []; // Array of image URL strings

    window.ptaSetGallery = function(json) {
        ptaGallery = [];
        if (json) {
            try { ptaGallery = JSON.parse(json); } catch(e) {
                try { ptaGallery = JSON.parse(json.replace(/\\\\/g, '').replace(/\\"/g, '"')); } catch(e2) {}
            }
        }
        if (!Array.isArray(ptaGallery)) ptaGallery = [];
        ptaRenderGallery();
    };

    function ptaRenderGallery() {
        var $grid = $('#pta-gallery-grid');
        $grid.empty();
        ptaGallery.forEach(function(url, i) {
            var thumb = $('<div style="position:relative;aspect-ratio:1;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;background:#f3f4f6;cursor:grab" draggable="true" data-gi="'+i+'">' +
                '<img src="'+url+'" style="width:100%;height:100%;object-fit:cover" loading="lazy">' +
                '<button type="button" onclick="ptaRemoveGallery('+i+')" style="position:absolute;top:4px;right:4px;width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.65);border:none;color:#fff;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;padding:0">&times;</button>' +
            '</div>');
            $grid.append(thumb);
        });
        ptaSyncGallery();

        // Drag-and-drop reorder
        $grid.find('[draggable]').on('dragstart', function(e) {
            e.originalEvent.dataTransfer.setData('text/plain', $(this).data('gi'));
            $(this).css('opacity','.4');
        }).on('dragend', function() {
            $(this).css('opacity','1');
        }).on('dragover', function(e) {
            e.preventDefault();
            $(this).css('outline','2px solid #FCB900');
        }).on('dragleave', function() {
            $(this).css('outline','none');
        }).on('drop', function(e) {
            e.preventDefault();
            $(this).css('outline','none');
            var from = parseInt(e.originalEvent.dataTransfer.getData('text/plain'));
            var to = $(this).data('gi');
            if (from !== to) {
                var item = ptaGallery.splice(from, 1)[0];
                ptaGallery.splice(to, 0, item);
                ptaRenderGallery();
            }
        });
    }

    window.ptaAddGalleryImages = function() {
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            alert('Media library not available.');
            return;
        }
        var frame = wp.media({
            title: 'Select Gallery Images',
            button: { text: 'Add to Gallery' },
            multiple: true,
            library: { type: 'image' }
        });
        frame.on('select', function() {
            var selection = frame.state().get('selection');
            selection.each(function(attachment) {
                var url = attachment.attributes.sizes && attachment.attributes.sizes.large
                    ? attachment.attributes.sizes.large.url
                    : attachment.attributes.url;
                ptaGallery.push(url);
            });
            ptaRenderGallery();
        });
        frame.open();
    };

    window.ptaRemoveGallery = function(i) {
        ptaGallery.splice(i, 1);
        ptaRenderGallery();
    };

    function ptaSyncGallery() {
        $('#edit-gallery').val(ptaGallery.length ? JSON.stringify(ptaGallery) : '');
    }

    // v216: Send Stripe Express Dashboard link to trainer
    $(document).on('click', '.ptp-send-stripe-dash', function() {
        var btn = $(this);
        var id = btn.data('id');
        var name = btn.data('name');
        if (!confirm('Send Stripe Dashboard login link to ' + name + '?')) return;
        
        btn.prop('disabled', true).html('&#8987;');
        
        $.post(ajaxurl, {
            action: 'ptp_send_stripe_dashboard_link',
            trainer_id: id,
            nonce: '<?php echo wp_create_nonce('ptp_send_stripe_dash'); ?>'
        }, function(res) {
            if (res.success) {
                btn.html('&#9989;').css('color', '#059669');
                setTimeout(function() { btn.html('&#128179;').css('color', '').prop('disabled', false); }, 3000);
                alert('Stripe Dashboard link sent to ' + name + '!');
            } else {
                btn.html('&#128179;').prop('disabled', false);
                alert('Error: ' + (res.data || 'Could not send link'));
            }
        }).fail(function() {
            btn.html('&#128179;').prop('disabled', false);
            alert('Request failed. Try again.');
        });
    });
});
</script>
