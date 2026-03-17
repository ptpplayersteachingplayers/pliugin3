<?php
/**
 * Shared Dashboard Navigation Component v177
 * 
 * Provides unified navigation between parent and trainer dashboards.
 * Drop into any dashboard template with:
 *   include(PTP_PLUGIN_DIR . 'templates/components/dashboard-nav.php');
 * 
 * Expected variables:
 *   $dashboard_type  - 'parent' | 'trainer'
 *   $first_name      - User's first name
 *   $user            - WP_User object (optional, auto-detected)
 *   $trainer         - trainer row (optional, for trainer dashboards)
 */
defined('ABSPATH') || exit;

// Auto-detect context
if (!isset($dashboard_type)) {
    $dashboard_type = 'parent';
}

if (!isset($user)) {
    $user = wp_get_current_user();
}

if (!isset($first_name)) {
    $first_name = $user->first_name ?: explode(' ', $user->display_name)[0];
}

// Detect if user is also a trainer
$is_also_trainer = false;
$trainer_slug = '';
if (class_exists('PTP_Trainer')) {
    $t = PTP_Trainer::get_by_user_id($user->ID);
    if ($t) {
        $is_also_trainer = true;
        $trainer_slug = $t->slug ?? '';
    }
}

$nav_items = [];

// Always show home
$nav_items[] = [
    'label' => 'Home',
    'url' => home_url('/'),
    'icon' => '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'active' => false,
];

// Parent dashboard
$nav_items[] = [
    'label' => 'Parent Dashboard',
    'url' => home_url('/parent-dashboard/'),
    'icon' => '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
    'active' => ($dashboard_type === 'parent'),
];

// Trainer dashboard (if applicable)
if ($is_also_trainer) {
    $nav_items[] = [
        'label' => 'Trainer Dashboard',
        'url' => home_url('/trainer-dashboard/'),
        'icon' => '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        'active' => ($dashboard_type === 'trainer'),
    ];
    
    // View my profile
    if ($trainer_slug) {
        $nav_items[] = [
            'label' => 'My Profile',
            'url' => home_url('/trainer/' . $trainer_slug . '/'),
            'icon' => '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'active' => false,
        ];
    }
}

// Find trainers / Browse camps
$nav_items[] = [
    'label' => 'Find Trainers',
    'url' => home_url('/find-trainers/'),
    'icon' => '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    'active' => false,
];

$nav_items[] = [
    'label' => 'Summer Camps',
    'url' => home_url('/ptp-find-a-camp/'),
    'icon' => '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/></svg>',
    'active' => false,
];
?>

<!-- Dashboard Quick-Nav Bar -->
<style>
.ptp-dash-nav {
    background: #fff;
    border-bottom: 1px solid #e5e5e5;
    position: sticky;
    top: 0;
    z-index: 100;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.ptp-dash-nav::-webkit-scrollbar { display: none; }

.ptp-dash-nav-inner {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 16px;
    min-width: min-content;
}

@media (min-width: 768px) {
    .ptp-dash-nav-inner {
        padding: 8px 5vw;
        gap: 6px;
    }
}

.ptp-dash-nav-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px;
    font-family: 'Inter', -apple-system, sans-serif;
    font-size: 13px;
    font-weight: 500;
    color: #666;
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.15s;
    flex-shrink: 0;
}

.ptp-dash-nav-item:hover {
    background: #f5f5f5;
    color: #1a1a1a;
}

.ptp-dash-nav-item:focus-visible {
    outline: 2px solid #FCB900;
    outline-offset: 2px;
}

.ptp-dash-nav-item.active {
    background: #FCB900;
    color: #0a0a0a;
    font-weight: 600;
}

.ptp-dash-nav-item svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.ptp-dash-nav-divider {
    width: 1px;
    height: 24px;
    background: #e5e5e5;
    flex-shrink: 0;
    margin: 0 4px;
}

.ptp-dash-nav-user {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #1a1a1a;
    flex-shrink: 0;
}

.ptp-dash-nav-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #FCB900;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    color: #0a0a0a;
}

@media (max-width: 640px) {
    .ptp-dash-nav-item span { display: none; }
    .ptp-dash-nav-item { padding: 8px 10px; }
    .ptp-dash-nav-user span { display: none; }
}
</style>

<nav class="ptp-dash-nav" aria-label="Dashboard navigation">
    <div class="ptp-dash-nav-inner" role="navigation">
        <?php foreach ($nav_items as $i => $item): ?>
            <?php if ($i === count($nav_items) - 2): ?>
                <div class="ptp-dash-nav-divider"></div>
            <?php endif; ?>
            <a href="<?php echo esc_url($item['url']); ?>" 
               class="ptp-dash-nav-item <?php echo $item['active'] ? 'active' : ''; ?>">
                <?php echo $item['icon']; ?>
                <span><?php echo esc_html($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
        
        <div class="ptp-dash-nav-user">
            <div class="ptp-dash-nav-avatar"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
            <span><?php echo esc_html($first_name); ?></span>
        </div>
    </div>
</nav>
