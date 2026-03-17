<?php
/**
 * Admin Template: Parents CRM — v200 full rewrite
 * Modern design, AJAX player/booking detail, quick SMS, no emojis
 */
defined('ABSPATH') || exit;

$nonce = wp_create_nonce('ptp_admin_nonce');
$base_url = admin_url('admin.php?page=ptp-parents');
?>
<style>
/* ═══ PTP Parents CRM v200 ═══ */
.pc{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:1440px;margin:20px auto;padding:0 20px}
.pc *{box-sizing:border-box}
.pc-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.pc-hdr h1{font-size:22px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px}
.pc-hdr h1 svg{width:28px;height:28px;color:#FCB900}
.pc-btn{padding:8px 16px;font-size:13px;font-weight:600;border-radius:8px;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;line-height:1.4;transition:all .15s}
.pc-btn svg{width:14px;height:14px}
.pc-btn-gold{background:#FCB900;color:#0A0A0A}.pc-btn-gold:hover{background:#e5a800}
.pc-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db}.pc-btn-outline:hover{background:#f9fafb;border-color:#9ca3af}
.pc-btn-sm{padding:5px 10px;font-size:11px;border-radius:6px}

/* Stats */
.pc-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px}
.pc-stat{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 16px;text-align:center;transition:box-shadow .2s}
.pc-stat:hover{box-shadow:0 4px 12px rgba(0,0,0,.06)}
.pc-stat-icon{width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px}
.pc-stat-icon svg{width:16px;height:16px}
.pc-stat-n{font-size:26px;font-weight:700;color:#0A0A0A;line-height:1}
.pc-stat-l{font-size:10px;color:#6b7280;margin-top:4px;text-transform:uppercase;letter-spacing:.5px;font-weight:500}

/* Tabs */
.pc-tabs{display:flex;gap:2px;margin-bottom:16px;border-bottom:2px solid #e5e7eb;overflow-x:auto;-webkit-overflow-scrolling:touch}
.pc-tab{padding:10px 14px;font-size:13px;font-weight:500;color:#6b7280;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;transition:color .15s}
.pc-tab svg{width:14px;height:14px}
.pc-tab:hover{color:#111}.pc-tab.active{color:#0A0A0A;border-color:#FCB900;font-weight:600}
.pc-cnt{background:#e5e7eb;color:#374151;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600}
.pc-tab.active .pc-cnt{background:#FCB900;color:#0A0A0A}

/* Toolbar */
.pc-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px}
.pc-search{display:flex;gap:8px;align-items:center}
.pc-search input{padding:9px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;width:280px;max-width:100%;transition:border .2s}
.pc-search input:focus{outline:none;border-color:#FCB900;box-shadow:0 0 0 3px rgba(252,185,0,.12)}
.pc-sort select{padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;background:#fff}

/* Table */
.pc-tw{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -20px;padding:0 20px}
.pc-t{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);min-width:600px}
.pc-t th{background:#f9fafb;padding:11px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;border-bottom:1px solid #e5e7eb;white-space:nowrap}
.pc-t td{padding:11px 14px;border-bottom:1px solid #f3f4f6;font-size:13px;color:#374151;vertical-align:middle}
.pc-t tr:hover td{background:#fafafa}
.pc-t tr.dormant td{background:#fffbeb}

/* User cell */
.pc-user{display:flex;align-items:center;gap:10px}
.pc-av{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;color:#374151;background:#f3f4f6}
.pc-av-vip{background:linear-gradient(135deg,#7C3AED,#A78BFA);color:#fff}
.pc-uname{font-weight:600;color:#111;font-size:13px;cursor:pointer;transition:color .15s}.pc-uname:hover{color:#2563eb}
.pc-umeta{font-size:11px;color:#6b7280;margin-top:1px}

/* Tier badges */
.pc-tier{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600}
.pc-tier svg{width:12px;height:12px}
.pc-tier-vip{background:#ede9fe;color:#7C3AED}
.pc-tier-active{background:#d1fae5;color:#059669}
.pc-tier-engaged{background:#dbeafe;color:#2563eb}
.pc-tier-new{background:#f3f4f6;color:#6b7280}

/* Mini badges */
.pc-mini{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600}
.pc-mini svg{width:11px;height:11px}
.pc-mini-camp{background:#fef3c7;color:#92400e}
.pc-mini-ref{background:#d1fae5;color:#059669}

/* Modals */
.pc-overlay{display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.5);backdrop-filter:blur(2px);align-items:center;justify-content:center;padding:16px}
.pc-overlay.active{display:flex}
.pc-modal{background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);-webkit-overflow-scrolling:touch}
.pc-mhdr{padding:18px 22px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;border-radius:14px 14px 0 0}
.pc-mhdr h2{margin:0;font-size:18px;font-weight:700;display:flex;align-items:center;gap:8px}
.pc-mclose{background:none;border:none;cursor:pointer;color:#6b7280;padding:4px;border-radius:6px;display:flex}
.pc-mclose:hover{background:#f3f4f6;color:#111}
.pc-mclose svg{width:20px;height:20px}
.pc-mbody{padding:22px}
.pc-mfoot{padding:14px 22px;border-top:1px solid #e5e7eb;display:flex;gap:8px;flex-wrap:wrap;position:sticky;bottom:0;background:#fff;border-radius:0 0 14px 14px}

/* Detail rows */
.pc-sec{margin-bottom:20px}.pc-sec:last-child{margin-bottom:0}
.pc-sec-title{font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.pc-sec-title svg{width:14px;height:14px}
.pc-row{display:flex;padding:8px 0;border-bottom:1px solid #f3f4f6;gap:8px}.pc-row:last-child{border-bottom:none}
.pc-lbl{width:100px;font-weight:600;color:#6b7280;font-size:11px;flex-shrink:0;text-transform:uppercase;letter-spacing:.3px;padding-top:2px}
.pc-val{flex:1;color:#111;font-size:14px;line-height:1.5;min-width:0;word-break:break-word}

/* Player cards */
.pc-player{display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f9fafb;border-radius:8px;margin-bottom:6px}
.pc-player-icon{width:32px;height:32px;border-radius:50%;background:#FCB900;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.pc-player-icon svg{width:16px;height:16px;color:#0A0A0A}
.pc-player-name{font-weight:600;font-size:13px;color:#111}
.pc-player-meta{font-size:11px;color:#6b7280;margin-top:1px}

/* Booking rows */
.pc-bk{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;margin-bottom:4px;background:#f9fafb;flex-wrap:wrap}
.pc-bk-date{font-weight:600;font-size:12px;color:#111;min-width:90px}
.pc-bk-trainer{font-size:12px;color:#374151;flex:1;min-width:80px}
.pc-bk-amt{font-weight:700;font-size:12px;color:#059669}
.pc-bk-status{font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600}
.pc-bk-confirmed{background:#d1fae5;color:#059669}.pc-bk-completed{background:#dbeafe;color:#2563eb}
.pc-bk-pending{background:#fef3c7;color:#92400e}.pc-bk-cancelled{background:#fee2e2;color:#dc2626}

/* SMS compose */
.pc-sms textarea{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;font-size:13px;resize:vertical;min-height:60px;font-family:inherit}
.pc-sms textarea:focus{outline:none;border-color:#FCB900;box-shadow:0 0 0 3px rgba(252,185,0,.1)}

.pc-empty{text-align:center;padding:60px 20px;color:#9ca3af}
.pc-empty svg{width:48px;height:48px;margin-bottom:12px;color:#d1d5db}
.pc-empty h3{font-size:18px;color:#374151;margin:0 0 4px}.pc-empty p{margin:0}

.pc-spin{display:inline-block;width:14px;height:14px;border:2px solid #d1d5db;border-top-color:#FCB900;border-radius:50%;animation:pcspin .6s linear infinite}
@keyframes pcspin{to{transform:rotate(360deg)}}

@media(max-width:768px){.pc-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){
    .pc{padding:0 12px}
    .pc-stats{grid-template-columns:repeat(2,1fr);gap:8px}.pc-stat{padding:12px}.pc-stat-n{font-size:20px}
    .pc-tab{padding:8px 10px;font-size:12px}.pc-hdr h1{font-size:18px}
    .pc-overlay{padding:0;align-items:flex-end}.pc-modal{max-height:92vh;border-radius:16px 16px 0 0;max-width:100%}
    .pc-mhdr{border-radius:16px 16px 0 0}
    .pc-row{flex-direction:column;gap:2px}.pc-lbl{width:auto}
    .pc-tw{margin:0 -12px;padding:0 12px}.pc-search input{width:180px}
}
</style>

<div class="pc">
    <div class="pc-hdr">
        <h1>
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            Parents <span style="color:#FCB900">CRM</span>
        </h1>
        <div style="display:flex;gap:8px">
            <button class="pc-btn pc-btn-outline" onclick="pcExport()">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export CSV
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="pc-stats">
        <div class="pc-stat">
            <div class="pc-stat-icon" style="background:#f3f4f6"><svg fill="none" stroke="#374151" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="pc-stat-n"><?php echo number_format($stats->total_parents ?? 0); ?></div><div class="pc-stat-l">Total Parents</div>
        </div>
        <div class="pc-stat">
            <div class="pc-stat-icon" style="background:#ede9fe"><svg fill="none" stroke="#7C3AED" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></div>
            <div class="pc-stat-n" style="color:#7C3AED"><?php echo number_format($stats->vip_count ?? 0); ?></div><div class="pc-stat-l">VIP ($1k+)</div>
        </div>
        <div class="pc-stat">
            <div class="pc-stat-icon" style="background:#d1fae5"><svg fill="none" stroke="#059669" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
            <div class="pc-stat-n" style="color:#059669"><?php echo number_format($stats->active_count ?? 0); ?></div><div class="pc-stat-l">Active ($500+)</div>
        </div>
        <div class="pc-stat">
            <div class="pc-stat-icon" style="background:#dbeafe"><svg fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <div class="pc-stat-n" style="color:#2563eb"><?php echo number_format($stats->engaged_count ?? 0); ?></div><div class="pc-stat-l">Engaged ($200+)</div>
        </div>
        <div class="pc-stat">
            <div class="pc-stat-icon" style="background:#fef3c7"><svg fill="none" stroke="#F59E0B" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
            <div class="pc-stat-n" style="color:#F59E0B">$<?php echo number_format($stats->avg_ltv ?? 0); ?></div><div class="pc-stat-l">Avg LTV</div>
        </div>
        <div class="pc-stat">
            <div class="pc-stat-icon" style="background:#d1fae5"><svg fill="none" stroke="#059669" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
            <div class="pc-stat-n" style="color:#059669">$<?php echo number_format(($stats->total_ltv ?? 0) / 1000, 0); ?>k</div><div class="pc-stat-l">Total Revenue</div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="pc-tabs">
        <a href="<?php echo esc_url($base_url . '&sort=' . $sort); ?>" class="pc-tab <?php echo !$filter ? 'active' : ''; ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> All
        </a>
        <a href="<?php echo esc_url($base_url . '&filter=vip&sort=' . $sort); ?>" class="pc-tab <?php echo $filter === 'vip' ? 'active' : ''; ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> VIP <span class="pc-cnt"><?php echo $stats->vip_count ?? 0; ?></span>
        </a>
        <a href="<?php echo esc_url($base_url . '&filter=active&sort=' . $sort); ?>" class="pc-tab <?php echo $filter === 'active' ? 'active' : ''; ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Active
        </a>
        <a href="<?php echo esc_url($base_url . '&filter=engaged&sort=' . $sort); ?>" class="pc-tab <?php echo $filter === 'engaged' ? 'active' : ''; ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Engaged
        </a>
        <a href="<?php echo esc_url($base_url . '&filter=new&sort=' . $sort); ?>" class="pc-tab <?php echo $filter === 'new' ? 'active' : ''; ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/></svg> New
        </a>
        <a href="<?php echo esc_url($base_url . '&filter=dormant&sort=' . $sort); ?>" class="pc-tab <?php echo $filter === 'dormant' ? 'active' : ''; ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg> Dormant
        </a>
        <a href="<?php echo esc_url($base_url . '&filter=multi_player&sort=' . $sort); ?>" class="pc-tab <?php echo $filter === 'multi_player' ? 'active' : ''; ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg> Multi-Player
        </a>
        <a href="<?php echo esc_url($base_url . '&filter=camp_buyer&sort=' . $sort); ?>" class="pc-tab <?php echo $filter === 'camp_buyer' ? 'active' : ''; ?>">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Camp Buyers
        </a>
    </div>

    <!-- Toolbar -->
    <div class="pc-toolbar">
        <form method="get" class="pc-search">
            <input type="hidden" name="page" value="ptp-parents">
            <?php if ($filter): ?><input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>"><?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search name, email, phone...">
            <button type="submit" class="pc-btn pc-btn-outline pc-btn-sm"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Search</button>
        </form>
        <div class="pc-sort">
            <select onchange="location.href='<?php echo esc_url($base_url); ?>&filter=<?php echo $filter; ?>&sort='+this.value">
                <option value="ltv" <?php selected($sort, 'ltv'); ?>>Sort: Lifetime Value</option>
                <option value="recent" <?php selected($sort, 'recent'); ?>>Sort: Last Booking</option>
                <option value="bookings" <?php selected($sort, 'bookings'); ?>>Sort: Total Bookings</option>
                <option value="players" <?php selected($sort, 'players'); ?>>Sort: Player Count</option>
                <option value="created" <?php selected($sort, 'created'); ?>>Sort: Join Date</option>
            </select>
        </div>
    </div>

    <!-- Table -->
    <?php if ($parents): ?>
    <div class="pc-tw">
    <table class="pc-t">
        <thead><tr><th>Parent</th><th>Engagement</th><th>Revenue</th><th>Activity</th><th>Actions</th></tr></thead>
        <tbody>
        <?php
        $parents_js = [];
        foreach ($parents as $p):
            $days_since = $p->last_booking ? floor((time() - strtotime($p->last_booking)) / 86400) : null;
            $is_dormant = $days_since === null || $days_since > 60;
            $ltv = floatval($p->lifetime_value);
            if ($ltv >= 1000) { $tier = 'VIP'; $tc = 'vip'; }
            elseif ($ltv >= 500) { $tier = 'Active'; $tc = 'active'; }
            elseif ($ltv >= 200) { $tier = 'Engaged'; $tc = 'engaged'; }
            else { $tier = 'New'; $tc = 'new'; }
            $parents_js[] = ['id'=>intval($p->id),'name'=>$p->display_name??'','email'=>$p->user_email??'','phone'=>$p->phone??'','players'=>intval($p->player_count),'bookings'=>intval($p->total_bookings),'completed'=>intval($p->completed_bookings),'ltv'=>$ltv,'tier'=>$tier,'tc'=>$tc,'last_booking'=>$p->last_booking?date('M j, Y',strtotime($p->last_booking)):'Never','first_booking'=>$p->first_booking?date('M j, Y',strtotime($p->first_booking)):'-','days_since'=>$days_since,'camp_orders'=>intval($p->camp_orders),'referral_code'=>$p->referral_code??'','referral_count'=>intval($p->referral_count),'created'=>$p->created_at?date('M j, Y',strtotime($p->created_at)):'-','user_id'=>intval($p->user_id)];
        ?>
        <tr class="<?php echo $is_dormant ? 'dormant' : ''; ?>">
            <!-- PARENT: avatar + name + email + tier -->
            <td><div class="pc-user">
                <div class="pc-av <?php echo $ltv >= 1000 ? 'pc-av-vip' : ''; ?>"><?php echo strtoupper(substr($p->display_name, 0, 1)); ?></div>
                <div>
                    <div class="pc-uname" onclick="pcDetail(<?php echo intval($p->id); ?>)"><?php echo esc_html($p->display_name); ?></div>
                    <div class="pc-umeta"><?php echo esc_html($p->user_email ?: $p->phone ?: '-'); ?></div>
                    <div style="margin-top:2px"><span class="pc-tier pc-tier-<?php echo $tc; ?>" style="padding:2px 8px;font-size:10px"><?php if($tc==='vip'):?><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:10px;height:10px"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><?php endif;?> <?php echo $tier; ?></span></div>
                </div>
            </div></td>
            <!-- ENGAGEMENT: players + sessions + camps + referrals -->
            <td>
                <div style="font-size:12px"><strong><?php echo intval($p->player_count); ?></strong> player<?php echo $p->player_count != 1 ? 's' : ''; ?> &middot; <strong><?php echo intval($p->completed_bookings); ?></strong><span style="color:#9ca3af">/<?php echo intval($p->total_bookings); ?></span> sessions</div>
                <?php if($p->camp_orders > 0 || $p->referral_count > 0): ?>
                <div style="display:flex;gap:4px;margin-top:3px;flex-wrap:wrap">
                    <?php if($p->camp_orders>0):?><span class="pc-mini pc-mini-camp" style="font-size:10px;padding:2px 6px"><?php echo intval($p->camp_orders); ?> camp<?php echo $p->camp_orders != 1 ? 's' : ''; ?></span><?php endif;?>
                    <?php if($p->referral_count>0):?><span class="pc-mini pc-mini-ref" style="font-size:10px;padding:2px 6px"><?php echo intval($p->referral_count); ?> ref<?php echo $p->referral_count != 1 ? 's' : ''; ?></span><?php endif;?>
                </div>
                <?php endif; ?>
            </td>
            <!-- REVENUE: LTV -->
            <td><strong style="font-size:16px;color:<?php echo $ltv>=1000?'#7C3AED':($ltv>=500?'#059669':'#374151'); ?>">$<?php echo number_format($ltv,0); ?></strong></td>
            <!-- ACTIVITY: last booking + dormant warning -->
            <td>
                <?php if($p->last_booking):?>
                    <div style="font-size:12px"><?php echo date('M j, Y',strtotime($p->last_booking)); ?></div>
                    <?php if($is_dormant):?><div style="color:#dc2626;font-size:11px;font-weight:600"><?php echo $days_since; ?>d ago</div><?php endif;?>
                <?php else:?><span style="color:#9ca3af;font-size:12px">Never</span><?php endif;?>
            </td>
            <!-- ACTIONS -->
            <td><div style="display:flex;gap:4px">
                <a href="mailto:<?php echo esc_attr($p->user_email); ?>" class="pc-btn pc-btn-outline pc-btn-sm" title="Email"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:13px;height:13px"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></a>
                <?php if($p->phone):?><button class="pc-btn pc-btn-outline pc-btn-sm" onclick="pcSmsOpen(<?php echo intval($p->id); ?>)" title="SMS"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:13px;height:13px"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></button><?php endif;?>
                <button class="pc-btn pc-btn-outline pc-btn-sm" onclick="pcDetail(<?php echo intval($p->id); ?>)" title="View"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:13px;height:13px"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="pc-empty">
        <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <h3>No parents found</h3><p>Parent accounts appear here once they register or book.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ DETAIL MODAL ═══ -->
<div class="pc-overlay" id="pc-dtl-ov">
<div class="pc-modal">
    <div class="pc-mhdr"><h2 id="pc-dtl-t">Parent Details</h2><button class="pc-mclose" onclick="pcDtlClose()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></div>
    <div class="pc-mbody" id="pc-dtl-b"></div>
    <div class="pc-mfoot" id="pc-dtl-f"></div>
</div>
</div>

<!-- ═══ SMS MODAL ═══ -->
<div class="pc-overlay" id="pc-sms-ov">
<div class="pc-modal" style="max-width:480px">
    <div class="pc-mhdr">
        <h2><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Send SMS</h2>
        <button class="pc-mclose" onclick="pcSmsClose()"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="pc-mbody">
        <p style="font-size:13px;color:#6b7280;margin:0 0 4px">To: <strong id="pc-sms-to"></strong></p>
        <p style="font-size:12px;color:#9ca3af;margin:0 0 12px" id="pc-sms-ph"></p>
        <div class="pc-sms">
            <textarea id="pc-sms-msg" placeholder="Type your message..." oninput="document.getElementById('pc-sms-c').textContent=this.value.length+'/160'"></textarea>
            <div style="display:flex;gap:8px;margin-top:8px;justify-content:space-between;align-items:center">
                <span style="font-size:11px;color:#9ca3af" id="pc-sms-c">0/160</span>
                <button class="pc-btn pc-btn-gold" id="pc-sms-send" onclick="pcSmsSend()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send
                </button>
            </div>
            <div style="font-size:12px;font-weight:600;margin-top:6px" id="pc-sms-st"></div>
        </div>
        <div style="margin-top:14px">
            <p style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px">Quick Templates</p>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
                <button class="pc-btn pc-btn-outline pc-btn-sm" onclick="pcTpl('remind')">Session Reminder</button>
                <button class="pc-btn pc-btn-outline pc-btn-sm" onclick="pcTpl('rebook')">Rebook Nudge</button>
                <button class="pc-btn pc-btn-outline pc-btn-sm" onclick="pcTpl('camp')">Camp Promo</button>
                <button class="pc-btn pc-btn-outline pc-btn-sm" onclick="pcTpl('thanks')">Thank You</button>
            </div>
        </div>
    </div>
</div>
</div>

<script>
var pcP = <?php echo wp_json_encode($parents_js ?? []); ?>;
var pcN = '<?php echo esc_js($nonce); ?>';
var pcSmsId = 0;

function pcDtlClose(){document.getElementById('pc-dtl-ov').classList.remove('active')}
function pcSmsClose(){document.getElementById('pc-sms-ov').classList.remove('active')}

function pcDetail(id) {
    var p = pcP.find(function(x){return x.id==id});
    if(!p) return;
    var h = '';
    // Contact
    h += '<div class="pc-sec"><div class="pc-sec-title"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Contact</div>';
    h += R('Name','<strong>'+p.name+'</strong>');
    h += R('Email', p.email ? '<a href="mailto:'+p.email+'">'+p.email+'</a>' : '-');
    h += R('Phone', p.phone ? '<a href="tel:'+p.phone+'">'+p.phone+'</a>' : '-');
    h += R('Tier','<span class="pc-tier pc-tier-'+p.tc+'">'+p.tier+'</span>');
    h += R('LTV','<strong style="font-size:20px;color:'+(p.ltv>=1000?'#7C3AED':p.ltv>=500?'#059669':'#374151')+'">$'+p.ltv.toLocaleString()+'</strong>');
    h += R('Sessions','<strong>'+p.completed+'</strong> completed / '+p.bookings+' total');
    h += R('Last Active', p.last_booking + (p.days_since!==null&&p.days_since>60?' <span style="color:#dc2626;font-weight:600">('+p.days_since+'d ago)</span>':''));
    h += R('Joined', p.created);
    if(p.camp_orders>0) h += R('Camps','<span class="pc-mini pc-mini-camp">'+p.camp_orders+' orders</span>');
    if(p.referral_count>0) h += R('Referrals','<span class="pc-mini pc-mini-ref">'+p.referral_count+' referrals</span>');
    if(p.referral_code) h += R('Ref Code','<code style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-size:12px">'+p.referral_code+'</code>');
    h += '</div>';
    // Players
    h += '<div class="pc-sec"><div class="pc-sec-title"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg> Players</div><div id="pc-pl"><span class="pc-spin"></span> Loading...</div></div>';
    // Bookings
    h += '<div class="pc-sec"><div class="pc-sec-title"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Recent Bookings</div><div id="pc-bk"><span class="pc-spin"></span> Loading...</div></div>';

    document.getElementById('pc-dtl-b').innerHTML = h;
    document.getElementById('pc-dtl-t').textContent = p.name;
    var f = '<a href="mailto:'+p.email+'" class="pc-btn pc-btn-outline"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Email</a>';
    if(p.phone) f += '<button class="pc-btn pc-btn-gold" onclick="pcDtlClose();pcSmsOpen('+p.id+')"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Send SMS</button>';
    document.getElementById('pc-dtl-f').innerHTML = f;
    document.getElementById('pc-dtl-ov').classList.add('active');

    // AJAX load players + bookings
    jQuery.post(ajaxurl, {action:'ptp_admin_get_parent_detail', parent_id:id, nonce:pcN}, function(r){
        if(r.success && r.data){
            var d = r.data;
            if(d.players && d.players.length){
                var ph = '';
                d.players.forEach(function(pl){
                    var nm = (pl.first_name && pl.last_name) ? pl.first_name+' '+pl.last_name : pl.name;
                    var meta = [];
                    if(pl.age) meta.push('Age '+pl.age);
                    if(pl.skill_level) meta.push(pl.skill_level);
                    if(pl.position) meta.push(pl.position);
                    ph += '<div class="pc-player"><div class="pc-player-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/></svg></div><div><div class="pc-player-name">'+nm+'</div>'+(meta.length?'<div class="pc-player-meta">'+meta.join(' &middot; ')+'</div>':'')+'</div></div>';
                });
                document.getElementById('pc-pl').innerHTML = ph;
            } else { document.getElementById('pc-pl').innerHTML = '<span style="color:#9ca3af;font-size:12px">No players registered</span>'; }
            if(d.bookings && d.bookings.length){
                var bh = '';
                d.bookings.forEach(function(b){
                    var sc = b.status==='completed'?'pc-bk-completed':b.status==='confirmed'?'pc-bk-confirmed':b.status==='cancelled'?'pc-bk-cancelled':'pc-bk-pending';
                    bh += '<div class="pc-bk"><div class="pc-bk-date">'+b.date+'</div><div class="pc-bk-trainer">'+b.trainer+(b.location?' &middot; '+b.location:'')+'</div><div class="pc-bk-amt">$'+b.amount+'</div><span class="pc-bk-status '+sc+'">'+b.status+'</span></div>';
                });
                document.getElementById('pc-bk').innerHTML = bh;
            } else { document.getElementById('pc-bk').innerHTML = '<span style="color:#9ca3af;font-size:12px">No bookings yet</span>'; }
        }
    });
}
function R(l,v){return '<div class="pc-row"><div class="pc-lbl">'+l+'</div><div class="pc-val">'+v+'</div></div>';}

/* SMS */
function pcSmsOpen(id){
    var p = pcP.find(function(x){return x.id==id});
    if(!p||!p.phone) return;
    pcSmsId = id;
    document.getElementById('pc-sms-to').textContent = p.name;
    document.getElementById('pc-sms-ph').textContent = p.phone;
    document.getElementById('pc-sms-msg').value = '';
    document.getElementById('pc-sms-c').textContent = '0/160';
    document.getElementById('pc-sms-st').textContent = '';
    document.getElementById('pc-sms-ov').classList.add('active');
    setTimeout(function(){document.getElementById('pc-sms-msg').focus()},200);
}
function pcTpl(t){
    var p = pcP.find(function(x){return x.id==pcSmsId});
    if(!p) return;
    var fn = p.name.split(' ')[0];
    var m = {
        remind: 'Hi '+fn+'! Just a reminder about your upcoming PTP training session. See you on the field! - PTP Soccer',
        rebook: 'Hi '+fn+'! It\'s been a while since your last PTP session. Ready to get back on the field? Book at <?php echo home_url("/find-trainers/"); ?> - PTP',
        camp: 'Hi '+fn+'! PTP Summer Camps are filling up fast. World Cup comes to Philly this summer - train with D1 athletes! <?php echo home_url("/find-a-camp/"); ?> - PTP',
        thanks: 'Hi '+fn+'! Thank you for choosing PTP! We love watching your player grow. - PTP Soccer'
    };
    document.getElementById('pc-sms-msg').value = m[t]||'';
    document.getElementById('pc-sms-c').textContent = (m[t]||'').length+'/160';
}
function pcSmsSend(){
    var msg = document.getElementById('pc-sms-msg').value.trim();
    if(!msg){document.getElementById('pc-sms-st').innerHTML='<span style="color:#dc2626">Please enter a message</span>';return;}
    var btn = document.getElementById('pc-sms-send');
    btn.disabled=true; btn.innerHTML='<span class="pc-spin"></span> Sending...';
    jQuery.post(ajaxurl,{action:'ptp_admin_send_sms',parent_id:pcSmsId,message:msg,nonce:pcN},function(r){
        btn.disabled=false;
        btn.innerHTML='<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send';
        document.getElementById('pc-sms-st').innerHTML = r.success ? '<span style="color:#059669">SMS sent!</span>' : '<span style="color:#dc2626">'+(r.data&&r.data.message||'Failed')+'</span>';
        if(r.success) document.getElementById('pc-sms-msg').value = '';
    });
}

/* CSV */
function pcExport(){
    var csv = 'Name,Email,Phone,Tier,LTV,Bookings,Completed,Players,Last Booking,Joined\n';
    pcP.forEach(function(p){csv += [p.name,p.email,p.phone,p.tier,'$'+p.ltv,p.bookings,p.completed,p.players,p.last_booking,p.created].map(function(v){return '"'+(v||'')+'"'}).join(',')+'\n';});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
    a.download = 'ptp-parents-'+new Date().toISOString().slice(0,10)+'.csv';
    a.click();
}

/* Close */
document.getElementById('pc-dtl-ov').addEventListener('click',function(e){if(e.target===this)pcDtlClose()});
document.getElementById('pc-sms-ov').addEventListener('click',function(e){if(e.target===this)pcSmsClose()});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){pcDtlClose();pcSmsClose()}});
</script>
