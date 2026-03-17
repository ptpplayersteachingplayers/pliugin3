<?php
/**
 * Admin Template: Reviews Management
 * @since 216.2
 */
defined('ABSPATH') || exit;

$nonce = wp_create_nonce('ptp_admin_nonce');
global $wpdb;

// Quick stats
$total_reviews = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews"));
$published_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews WHERE is_published = 1"));
$hidden_count = $total_reviews - $published_count;
$avg_rating = floatval($wpdb->get_var("SELECT AVG(rating) FROM {$wpdb->prefix}ptp_reviews WHERE is_published = 1") ?: 0);
$unreplied = intval($wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews 
     WHERE (trainer_reply IS NULL OR trainer_reply = '') AND (trainer_response IS NULL OR trainer_response = '')"
));

// Initial reviews load (page 1)
$reviews = $wpdb->get_results(
    "SELECT r.*, t.display_name as trainer_name, t.photo_url as trainer_photo,
            COALESCE(u.display_name, 'Parent') as parent_name
     FROM {$wpdb->prefix}ptp_reviews r
     LEFT JOIN {$wpdb->prefix}ptp_trainers t ON r.trainer_id = t.id
     LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
     ORDER BY r.created_at DESC
     LIMIT 20"
);

// Rating breakdown
$breakdown = $wpdb->get_results(
    "SELECT rating, COUNT(*) as cnt FROM {$wpdb->prefix}ptp_reviews WHERE is_published = 1 GROUP BY rating ORDER BY rating DESC"
);
$rating_counts = array(5=>0, 4=>0, 3=>0, 2=>0, 1=>0);
foreach ($breakdown as $b) $rating_counts[intval($b->rating)] = intval($b->cnt);
?>
<style>
/* ═══ PTP Admin Design System ═══ */
.pta-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:1400px;margin:20px auto;padding:0 20px}
.pta-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.pta-hdr h1{font-size:22px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px}.pta-hdr h1 span{color:#FCB900}
.pta-btn{padding:8px 16px;font-size:13px;font-weight:600;border-radius:6px;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;line-height:1.4;transition:all .15s}
.pta-btn-gold{background:#FCB900;color:#0A0A0A}.pta-btn-gold:hover{background:#e5a800}
.pta-btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db}.pta-btn-outline:hover{background:#f9fafb}
.pta-btn-green{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}.pta-btn-green:hover{background:#d1fae5}
.pta-btn-red{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}.pta-btn-red:hover{background:#fee2e2}
.pta-btn-sm{padding:4px 10px;font-size:11px}

/* Stats */
.pta-stats{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.pta-stat{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;flex:1;min-width:120px}
.pta-stat-n{font-size:28px;font-weight:700;color:#0A0A0A;line-height:1}
.pta-stat-l{font-size:11px;color:#6b7280;margin-top:4px;text-transform:uppercase;letter-spacing:.5px}
.pta-stat-gold .pta-stat-n{color:#FCB900}
.pta-stat-green .pta-stat-n{color:#059669}
.pta-stat-red .pta-stat-n{color:#dc2626}

/* Tabs */
.pta-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid #e5e7eb;padding-bottom:0;flex-wrap:wrap}
.pta-tab{padding:10px 16px;font-size:13px;font-weight:500;color:#6b7280;cursor:pointer;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap}
.pta-tab:hover{color:#111}.pta-tab.active{color:#0A0A0A;border-color:#FCB900;font-weight:600}
.pta-tab .cnt{background:#e5e7eb;color:#374151;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px}
.pta-tab.active .cnt{background:#FCB900;color:#0A0A0A}

/* Toolbar */
.pta-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px}
.pta-search{display:flex;gap:8px;align-items:center}
.pta-search input{padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;width:260px;max-width:100%}
.pta-search input:focus{outline:none;border-color:#FCB900;box-shadow:0 0 0 2px rgba(252,185,0,.15)}

/* Review cards */
.pta-rev-list{display:flex;flex-direction:column;gap:12px}
.pta-rev{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;transition:all .15s}
.pta-rev:hover{border-color:#d1d5db;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.pta-rev.hidden-rev{opacity:0.6;background:#fafafa}
.pta-rev-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:12px}
.pta-rev-left{display:flex;align-items:center;gap:12px;flex:1;min-width:0}
.pta-rev-avatar{width:44px;height:44px;border-radius:50%;background:#FEF3CD;color:#92400E;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;overflow:hidden}
.pta-rev-avatar img{width:100%;height:100%;object-fit:cover}
.pta-rev-info{min-width:0}
.pta-rev-trainer{font-weight:700;font-size:14px;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pta-rev-parent{font-size:12px;color:#6b7280;margin-top:1px}
.pta-rev-stars{color:#FCB900;font-size:15px;letter-spacing:1px}
.pta-rev-date{font-size:11px;color:#9ca3af;margin-top:2px}
.pta-rev-text{font-size:14px;color:#374151;line-height:1.6;margin-bottom:10px;font-style:italic}
.pta-rev-reply{background:#f9fafb;border:1px solid #f3f4f6;border-radius:6px;padding:10px 12px;margin-bottom:10px}
.pta-rev-reply-hd{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:3px}
.pta-rev-reply-txt{font-size:13px;color:#374151;line-height:1.5}
.pta-rev-actions{display:flex;gap:6px;flex-wrap:wrap}
.pta-rev-badge{display:inline-block;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.pta-rev-badge-pub{background:#d1fae5;color:#065f46}
.pta-rev-badge-hid{background:#fee2e2;color:#991b1b}
.pta-rev-badge-verified{background:#dbeafe;color:#1d4ed8}

/* Rating bars */
.pta-rating-bars{display:flex;gap:16px;align-items:center;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin-bottom:20px}
.pta-rating-avg{text-align:center;min-width:80px}
.pta-rating-avg-n{font-size:40px;font-weight:700;color:#0A0A0A;line-height:1}
.pta-rating-avg-s{color:#FCB900;font-size:16px;margin-top:2px}
.pta-rating-avg-l{font-size:11px;color:#6b7280;margin-top:4px}
.pta-bars{flex:1}
.pta-bar-row{display:flex;align-items:center;gap:8px;margin-bottom:4px}
.pta-bar-label{font-size:12px;font-weight:600;color:#6b7280;width:14px;text-align:right}
.pta-bar-track{flex:1;height:10px;background:#f3f4f6;border-radius:5px;overflow:hidden}
.pta-bar-fill{height:100%;background:#FCB900;border-radius:5px;transition:width 0.6s}
.pta-bar-ct{font-size:11px;color:#9ca3af;width:28px}

/* Pagination */
.pta-pagination{display:flex;justify-content:center;gap:6px;margin-top:20px}
.pta-page-btn{padding:8px 14px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-weight:500;cursor:pointer;background:#fff;color:#374151;transition:all .15s}
.pta-page-btn:hover{border-color:#FCB900;color:#0A0A0A}
.pta-page-btn.active{background:#FCB900;border-color:#FCB900;color:#0A0A0A;font-weight:700}
.pta-page-btn:disabled{opacity:.4;cursor:not-allowed}

/* Empty */
.pta-empty{text-align:center;padding:60px 20px;color:#6b7280}
.pta-empty svg{width:48px;height:48px;color:#d1d5db;margin-bottom:12px}
.pta-empty h3{font-size:16px;font-weight:600;color:#374151;margin-bottom:4px}
.pta-empty p{font-size:14px}

/* Loading */
.pta-loading{text-align:center;padding:40px}
.pta-spinner{width:24px;height:24px;border:3px solid #e5e7eb;border-top-color:#FCB900;border-radius:50%;animation:ptaSpin .6s linear infinite;display:inline-block}
@keyframes ptaSpin{to{transform:rotate(360deg)}}

/* Confirm dialog */
.pta-confirm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center}
.pta-confirm-overlay.active{display:flex}
.pta-confirm-box{background:#fff;border-radius:12px;padding:24px;max-width:360px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.pta-confirm-box h3{font-size:16px;margin-bottom:8px}
.pta-confirm-box p{font-size:14px;color:#6b7280;margin-bottom:20px}
.pta-confirm-btns{display:flex;gap:8px;justify-content:center}

@media(max-width:768px){
    .pta-stats{flex-wrap:wrap}.pta-stat{min-width:calc(50% - 8px)}
    .pta-rating-bars{flex-direction:column}
    .pta-rev-top{flex-direction:column;gap:10px}
}
</style>

<div class="pta-wrap">
    <div class="pta-hdr">
        <h1><span>★</span> Reviews Management</h1>
    </div>

    <!-- Stats -->
    <div class="pta-stats">
        <div class="pta-stat">
            <div class="pta-stat-n"><?php echo $total_reviews; ?></div>
            <div class="pta-stat-l">Total Reviews</div>
        </div>
        <div class="pta-stat pta-stat-gold">
            <div class="pta-stat-n"><?php echo number_format($avg_rating, 1); ?>★</div>
            <div class="pta-stat-l">Avg Rating</div>
        </div>
        <div class="pta-stat pta-stat-green">
            <div class="pta-stat-n"><?php echo $published_count; ?></div>
            <div class="pta-stat-l">Published</div>
        </div>
        <div class="pta-stat">
            <div class="pta-stat-n"><?php echo $unreplied; ?></div>
            <div class="pta-stat-l">Unreplied</div>
        </div>
        <div class="pta-stat pta-stat-red">
            <div class="pta-stat-n"><?php echo $hidden_count; ?></div>
            <div class="pta-stat-l">Hidden</div>
        </div>
    </div>

    <!-- Rating breakdown -->
    <div class="pta-rating-bars">
        <div class="pta-rating-avg">
            <div class="pta-rating-avg-n"><?php echo number_format($avg_rating, 1); ?></div>
            <div class="pta-rating-avg-s"><?php echo str_repeat('★', round($avg_rating)); ?><?php echo str_repeat('☆', 5 - round($avg_rating)); ?></div>
            <div class="pta-rating-avg-l"><?php echo $published_count; ?> reviews</div>
        </div>
        <div class="pta-bars">
            <?php for ($s = 5; $s >= 1; $s--):
                $ct = $rating_counts[$s];
                $pct = $published_count > 0 ? round(($ct / $published_count) * 100) : 0;
            ?>
            <div class="pta-bar-row">
                <div class="pta-bar-label"><?php echo $s; ?></div>
                <div class="pta-bar-track"><div class="pta-bar-fill" style="width:<?php echo $pct; ?>%"></div></div>
                <div class="pta-bar-ct"><?php echo $ct; ?></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- Tabs & Toolbar -->
    <div class="pta-tabs" id="reviewTabs">
        <button class="pta-tab active" data-filter="all" onclick="filterTab('all')">All <span class="cnt"><?php echo $total_reviews; ?></span></button>
        <button class="pta-tab" data-filter="published" onclick="filterTab('published')">Published <span class="cnt"><?php echo $published_count; ?></span></button>
        <button class="pta-tab" data-filter="hidden" onclick="filterTab('hidden')">Hidden <span class="cnt"><?php echo $hidden_count; ?></span></button>
        <button class="pta-tab" data-filter="unreplied" onclick="filterTab('unreplied')">Needs Reply <span class="cnt"><?php echo $unreplied; ?></span></button>
        <button class="pta-tab" data-filter="5" onclick="filterTab('5')">5★ <span class="cnt"><?php echo $rating_counts[5]; ?></span></button>
        <button class="pta-tab" data-filter="4" onclick="filterTab('4')">4★ <span class="cnt"><?php echo $rating_counts[4]; ?></span></button>
        <button class="pta-tab" data-filter="3" onclick="filterTab('3')">1-3★ <span class="cnt"><?php echo $rating_counts[1] + $rating_counts[2] + $rating_counts[3]; ?></span></button>
    </div>

    <div class="pta-toolbar">
        <div class="pta-search">
            <input type="text" id="reviewSearch" placeholder="Search by trainer, parent, or review text..." oninput="debounceSearch()">
        </div>
    </div>

    <!-- Reviews List -->
    <div id="reviewsList">
        <?php if (!empty($reviews)): ?>
        <div class="pta-rev-list">
            <?php foreach ($reviews as $rv):
                $has_reply = !empty($rv->trainer_reply) || !empty($rv->trainer_response);
                $reply_text = !empty($rv->trainer_reply) ? $rv->trainer_reply : (!empty($rv->trainer_response) ? $rv->trainer_response : '');
                $rv_initials = strtoupper(substr($rv->trainer_name ?: 'T', 0, 1));
                $rv_photo = $rv->trainer_photo ?: '';
                $rv_rating = max(1, min(5, intval($rv->rating)));
            ?>
            <div class="pta-rev <?php echo $rv->is_published ? '' : 'hidden-rev'; ?>" id="admin-rev-<?php echo $rv->id; ?>">
                <div class="pta-rev-top">
                    <div class="pta-rev-left">
                        <div class="pta-rev-avatar">
                            <?php if ($rv_photo): ?>
                            <img src="<?php echo esc_url($rv_photo); ?>" alt="">
                            <?php else: ?>
                            <?php echo $rv_initials; ?>
                            <?php endif; ?>
                        </div>
                        <div class="pta-rev-info">
                            <div class="pta-rev-trainer"><?php echo esc_html($rv->trainer_name ?: 'Unknown Trainer'); ?></div>
                            <div class="pta-rev-parent">by <?php echo esc_html($rv->parent_name); ?></div>
                        </div>
                    </div>
                    <div style="text-align:right;flex-shrink:0">
                        <div class="pta-rev-stars"><?php echo str_repeat('★', $rv_rating); ?><?php echo str_repeat('☆', 5 - $rv_rating); ?></div>
                        <div class="pta-rev-date"><?php echo esc_html(date('M j, Y', strtotime($rv->created_at))); ?></div>
                    </div>
                </div>

                <?php if (!empty($rv->review_text)): ?>
                <div class="pta-rev-text">"<?php echo esc_html($rv->review_text); ?>"</div>
                <?php endif; ?>

                <?php if ($has_reply): ?>
                <div class="pta-rev-reply">
                    <div class="pta-rev-reply-hd">Trainer Response</div>
                    <div class="pta-rev-reply-txt"><?php echo esc_html($reply_text); ?></div>
                </div>
                <?php endif; ?>

                <div class="pta-rev-actions">
                    <?php if ($rv->is_published): ?>
                    <span class="pta-rev-badge pta-rev-badge-pub">Published</span>
                    <?php else: ?>
                    <span class="pta-rev-badge pta-rev-badge-hid">Hidden</span>
                    <?php endif; ?>
                    <?php if (!empty($rv->is_verified)): ?>
                    <span class="pta-rev-badge pta-rev-badge-verified">Verified</span>
                    <?php endif; ?>
                    <?php if (!$has_reply): ?>
                    <span class="pta-rev-badge" style="background:#fef3c7;color:#92400e">No Reply</span>
                    <?php endif; ?>

                    <div style="margin-left:auto;display:flex;gap:6px">
                        <button class="pta-btn pta-btn-sm pta-btn-outline" onclick="toggleReview(<?php echo $rv->id; ?>, this)" title="<?php echo $rv->is_published ? 'Hide' : 'Publish'; ?>">
                            <?php echo $rv->is_published ? 'Hide' : 'Publish'; ?>
                        </button>
                        <button class="pta-btn pta-btn-sm pta-btn-red" onclick="deleteReview(<?php echo $rv->id; ?>)" title="Delete">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="pta-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <h3>No Reviews Yet</h3>
            <p>Reviews will appear here as parents rate their sessions.</p>
        </div>
        <?php endif; ?>
    </div>

    <div class="pta-pagination" id="pagination"></div>
</div>

<!-- Confirm dialog -->
<div class="pta-confirm-overlay" id="confirmOverlay">
    <div class="pta-confirm-box">
        <h3 id="confirmTitle">Confirm Action</h3>
        <p id="confirmText">Are you sure?</p>
        <div class="pta-confirm-btns">
            <button class="pta-btn pta-btn-outline" onclick="closeConfirm()">Cancel</button>
            <button class="pta-btn pta-btn-red" id="confirmAction" onclick="">Confirm</button>
        </div>
    </div>
</div>

<script>
var ADMIN_CONFIG = {
    ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
    nonce: '<?php echo esc_js($nonce); ?>'
};

var currentFilter = 'all';
var currentPage = 1;
var currentSearch = '';
var searchTimer = null;
var totalPages = <?php echo ceil($total_reviews / 20); ?>;

function filterTab(filter) {
    currentPage = 1;
    // Map the tab filter to the API filter
    currentFilter = (filter === '3') ? 'low' : filter;
    document.querySelectorAll('.pta-tab').forEach(function(t) {
        t.classList.toggle('active', t.dataset.filter === filter);
    });
    loadReviews();
}

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() {
        currentSearch = document.getElementById('reviewSearch').value.trim();
        currentPage = 1;
        loadReviews();
    }, 400);
}

function loadReviews() {
    var list = document.getElementById('reviewsList');
    list.innerHTML = '<div class="pta-loading"><div class="pta-spinner"></div></div>';

    var body = 'action=ptp_admin_get_reviews&nonce=' + ADMIN_CONFIG.nonce +
               '&page=' + currentPage +
               '&filter=' + encodeURIComponent(currentFilter) +
               '&search=' + encodeURIComponent(currentSearch);

    fetch(ADMIN_CONFIG.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success || !res.data.reviews.length) {
            list.innerHTML = '<div class="pta-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><h3>No reviews found</h3><p>Try adjusting your filters.</p></div>';
            document.getElementById('pagination').innerHTML = '';
            return;
        }
        renderReviews(res.data.reviews);
        renderPagination(res.data.pages, res.data.page);
    })
    .catch(function() {
        list.innerHTML = '<div class="pta-empty"><h3>Error loading reviews</h3><p>Please try again.</p></div>';
    });
}

function renderReviews(reviews) {
    var html = '<div class="pta-rev-list">';
    reviews.forEach(function(rv) {
        var reply = rv.trainer_reply || rv.trainer_response || '';
        var hasReply = reply.length > 0;
        var rating = Math.max(1, Math.min(5, parseInt(rv.rating)));
        var stars = '★'.repeat(rating) + '☆'.repeat(5 - rating);
        var initials = (rv.trainer_name || 'T')[0].toUpperCase();
        var photo = rv.trainer_photo || '';
        var date = rv.created_at ? new Date(rv.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '';
        var isPub = parseInt(rv.is_published) === 1;

        html += '<div class="pta-rev ' + (isPub ? '' : 'hidden-rev') + '" id="admin-rev-' + rv.id + '">';
        html += '<div class="pta-rev-top"><div class="pta-rev-left">';
        html += '<div class="pta-rev-avatar">' + (photo ? '<img src="' + photo + '" alt="">' : initials) + '</div>';
        html += '<div class="pta-rev-info"><div class="pta-rev-trainer">' + escHtml(rv.trainer_name || 'Unknown') + '</div>';
        html += '<div class="pta-rev-parent">by ' + escHtml(rv.parent_name || 'Parent') + '</div></div></div>';
        html += '<div style="text-align:right;flex-shrink:0"><div class="pta-rev-stars">' + stars + '</div>';
        html += '<div class="pta-rev-date">' + date + '</div></div></div>';

        if (rv.review_text) html += '<div class="pta-rev-text">"' + escHtml(rv.review_text) + '"</div>';

        if (hasReply) {
            html += '<div class="pta-rev-reply"><div class="pta-rev-reply-hd">Trainer Response</div>';
            html += '<div class="pta-rev-reply-txt">' + escHtml(reply) + '</div></div>';
        }

        html += '<div class="pta-rev-actions">';
        html += '<span class="pta-rev-badge ' + (isPub ? 'pta-rev-badge-pub' : 'pta-rev-badge-hid') + '">' + (isPub ? 'Published' : 'Hidden') + '</span>';
        if (!hasReply) html += '<span class="pta-rev-badge" style="background:#fef3c7;color:#92400e">No Reply</span>';
        html += '<div style="margin-left:auto;display:flex;gap:6px">';
        html += '<button class="pta-btn pta-btn-sm pta-btn-outline" onclick="toggleReview(' + rv.id + ', this)">' + (isPub ? 'Hide' : 'Publish') + '</button>';
        html += '<button class="pta-btn pta-btn-sm pta-btn-red" onclick="deleteReview(' + rv.id + ')">Delete</button>';
        html += '</div></div></div>';
    });
    html += '</div>';
    document.getElementById('reviewsList').innerHTML = html;
}

function renderPagination(pages, page) {
    if (pages <= 1) { document.getElementById('pagination').innerHTML = ''; return; }
    var html = '';
    html += '<button class="pta-page-btn" onclick="goPage(' + (page - 1) + ')" ' + (page <= 1 ? 'disabled' : '') + '>&laquo;</button>';
    for (var i = 1; i <= pages; i++) {
        if (pages > 7 && Math.abs(i - page) > 2 && i > 2 && i < pages - 1) {
            if (i === page - 3 || i === page + 3) html += '<button class="pta-page-btn" disabled>...</button>';
            continue;
        }
        html += '<button class="pta-page-btn ' + (i === page ? 'active' : '') + '" onclick="goPage(' + i + ')">' + i + '</button>';
    }
    html += '<button class="pta-page-btn" onclick="goPage(' + (page + 1) + ')" ' + (page >= pages ? 'disabled' : '') + '>&raquo;</button>';
    document.getElementById('pagination').innerHTML = html;
}

function goPage(p) {
    currentPage = p;
    loadReviews();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function toggleReview(id, btn) {
    btn.disabled = true;
    fetch(ADMIN_CONFIG.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_admin_toggle_review&nonce=' + ADMIN_CONFIG.nonce + '&review_id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        if (res.success) {
            var card = document.getElementById('admin-rev-' + id);
            var isPub = res.data.is_published;
            if (card) {
                card.classList.toggle('hidden-rev', !isPub);
                var badge = card.querySelector('.pta-rev-badge-pub, .pta-rev-badge-hid');
                if (badge) {
                    badge.className = 'pta-rev-badge ' + (isPub ? 'pta-rev-badge-pub' : 'pta-rev-badge-hid');
                    badge.textContent = isPub ? 'Published' : 'Hidden';
                }
            }
            btn.textContent = isPub ? 'Hide' : 'Publish';
        }
    })
    .catch(function() { btn.disabled = false; });
}

var pendingDeleteId = null;

function deleteReview(id) {
    pendingDeleteId = id;
    document.getElementById('confirmTitle').textContent = 'Delete Review';
    document.getElementById('confirmText').textContent = 'This will permanently remove this review. This cannot be undone.';
    document.getElementById('confirmAction').onclick = confirmDelete;
    document.getElementById('confirmOverlay').classList.add('active');
}

function confirmDelete() {
    if (!pendingDeleteId) return;
    closeConfirm();
    var card = document.getElementById('admin-rev-' + pendingDeleteId);
    if (card) { card.style.opacity = '0.3'; card.style.pointerEvents = 'none'; }

    fetch(ADMIN_CONFIG.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=ptp_admin_delete_review&nonce=' + ADMIN_CONFIG.nonce + '&review_id=' + pendingDeleteId
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success && card) {
            card.style.transition = 'all 0.3s';
            card.style.maxHeight = '0';
            card.style.overflow = 'hidden';
            card.style.padding = '0';
            card.style.margin = '0';
            card.style.borderWidth = '0';
            setTimeout(function() { card.remove(); }, 300);
        }
    });
    pendingDeleteId = null;
}

function closeConfirm() {
    document.getElementById('confirmOverlay').classList.remove('active');
}

function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
