<?php
/**
 * PTP AI Insights — Admin Dashboard (Claude-powered)
 * 
 * Gives the founder/admin actionable insights across the entire platform:
 * revenue trends, trainer performance, at-risk families, recap rates,
 * profile completeness, booking patterns, and growth opportunities.
 * 
 * @version 222.1.0
 */

defined('ABSPATH') || exit;

class PTP_AI_Insights_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 20);
        add_action('wp_ajax_ptp_admin_ai_insights', array(__CLASS__, 'ajax_get_insights'));
    }

    public static function register_page() {
        add_submenu_page(
            'ptp-admin',
            'AI Insights',
            '🧠 AI Insights',
            'manage_options',
            'ptp-ai-insights',
            array(__CLASS__, 'render_page')
        );
    }

    // =========================================================================
    // RENDER PAGE
    // =========================================================================

    public static function render_page() {
        $api_key = self::get_api_key();
        $has_key = !empty($api_key);
        ?>
        <style>
            .ptp-ai-wrap { max-width:860px; margin:20px auto; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; }
            .ptp-ai-header { background:#0A0A0A; color:#fff; padding:28px 32px; border-radius:12px 12px 0 0; display:flex; align-items:center; justify-content:space-between; }
            .ptp-ai-header h1 { margin:0; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
            .ptp-ai-header h1 span { color:#FCB900; }
            .ptp-ai-body { background:#fff; border:1px solid #e5e7eb; border-top:none; padding:0; border-radius:0 0 12px 12px; }
            .ptp-ai-section { padding:24px 32px; border-bottom:1px solid #f3f4f6; }
            .ptp-ai-section:last-child { border-bottom:none; }
            .ptp-ai-section h3 { margin:0 0 16px; font-size:15px; font-weight:700; color:#111; display:flex; align-items:center; gap:8px; }
            .ptp-ai-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
            .ptp-ai-stat { background:#f9fafb; border-radius:8px; padding:16px; text-align:center; }
            .ptp-ai-stat-val { font-family:Oswald,sans-serif; font-size:24px; font-weight:700; color:#111; }
            .ptp-ai-stat-val.gold { color:#FCB900; }
            .ptp-ai-stat-val.green { color:#059669; }
            .ptp-ai-stat-val.red { color:#dc2626; }
            .ptp-ai-stat-lbl { font-size:11px; color:#6B7280; text-transform:uppercase; letter-spacing:0.5px; margin-top:4px; }
            .ptp-ai-btn { background:#FCB900; color:#0A0A0A; border:none; padding:14px 28px; font-weight:700; font-size:14px; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
            .ptp-ai-btn:hover { background:#e5a800; }
            .ptp-ai-btn:disabled { opacity:0.5; cursor:not-allowed; }
            .ptp-ai-tip { padding:16px 20px; border-bottom:1px solid #f3f4f6; display:flex; gap:12px; align-items:flex-start; }
            .ptp-ai-tip:last-child { border-bottom:none; }
            .ptp-ai-tip-icon { font-size:20px; flex-shrink:0; padding-top:2px; }
            .ptp-ai-tip-title { font-weight:700; font-size:14px; color:#111; margin-bottom:4px; }
            .ptp-ai-tip-title.high { color:#FCB900; }
            .ptp-ai-tip-body { font-size:13px; color:#555; line-height:1.6; }
            .ptp-ai-tip-priority { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:2px 8px; border-radius:4px; margin-left:8px; }
            .ptp-ai-tip-priority.high { background:rgba(252,185,0,0.15); color:#b8860b; }
            .ptp-ai-tip-priority.medium { background:#f3f4f6; color:#6B7280; }
            .ptp-ai-tip-priority.low { background:#f9fafb; color:#9CA3AF; }
            .ptp-ai-loading { text-align:center; padding:48px; }
            .ptp-ai-spinner { width:32px; height:32px; border:3px solid #e5e7eb; border-top-color:#FCB900; border-radius:50%; animation:ptpSpin 0.8s linear infinite; display:inline-block; }
            @keyframes ptpSpin { to { transform:rotate(360deg); } }
            .ptp-ai-empty { text-align:center; padding:40px; color:#9CA3AF; }
            .ptp-ai-time { font-size:12px; color:#9CA3AF; }
            .ptp-ai-key-form { display:flex; gap:8px; align-items:center; }
            .ptp-ai-key-form input { padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; width:320px; }
            .ptp-ai-key-form button { padding:8px 16px; background:#111; color:#fff; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; }
            @media(max-width:768px) { .ptp-ai-grid { grid-template-columns:repeat(2,1fr); } }
        </style>

        <div class="ptp-ai-wrap">
            <div class="ptp-ai-header">
                <h1><span>&#x1f9e0;</span> AI Insights</h1>
                <div class="ptp-ai-time" id="ptpAiTime"></div>
            </div>
            <div class="ptp-ai-body">

                <?php if (!$has_key): ?>
                <div class="ptp-ai-section">
                    <h3>Setup Required</h3>
                    <p style="color:#555;font-size:14px;margin:0 0 16px;">Enter your Anthropic API key to enable AI-powered insights.</p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('ptp_save_anthropic_key'); ?>
                        <input type="hidden" name="action" value="ptp_save_anthropic_key">
                        <div class="ptp-ai-key-form">
                            <input type="password" name="ptp_anthropic_api_key" placeholder="sk-ant-api03-..." autocomplete="off">
                            <button type="submit">Save Key</button>
                        </div>
                    </form>
                </div>
                <?php else: ?>

                <!-- Quick Stats (loaded immediately from DB) -->
                <div class="ptp-ai-section" id="ptpAiStats">
                    <h3>Platform Snapshot — Last 30 Days</h3>
                    <div class="ptp-ai-loading"><div class="ptp-ai-spinner"></div><p style="margin:12px 0 0;font-size:13px;color:#9CA3AF">Loading stats...</p></div>
                </div>

                <!-- AI Recommendations -->
                <div class="ptp-ai-section">
                    <h3>&#x26a1; What To Do Next</h3>
                    <div id="ptpAiTips">
                        <div style="text-align:center;padding:24px;">
                            <button class="ptp-ai-btn" onclick="fetchInsights()" id="ptpAiBtn">
                                <span>&#x1f9e0;</span> Analyze My Platform
                            </button>
                        </div>
                    </div>
                </div>

                <?php endif; ?>
            </div>
        </div>

        <?php if ($has_key): ?>
        <script>
        (function() {
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce = '<?php echo wp_create_nonce('ptp_nonce'); ?>';

            // Load stats immediately
            fetch(ajaxUrl + '?action=ptp_admin_ai_insights&just_stats=1&nonce=' + nonce)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success || !res.data.stats) return;
                var s = res.data.stats;
                var el = document.getElementById('ptpAiStats');
                el.innerHTML = '<h3>Platform Snapshot — Last 30 Days</h3>' +
                    '<div class="ptp-ai-grid">' +
                    stat(s.revenue_30d, 'Revenue', 'gold', true) +
                    stat(s.sessions_30d, 'Sessions') +
                    stat(s.active_trainers, 'Active Trainers') +
                    stat(s.unique_families, 'Families') +
                    '</div>' +
                    '<div class="ptp-ai-grid">' +
                    stat(s.repeat_rate + '%', 'Repeat Rate', s.repeat_rate >= 40 ? 'green' : 'red') +
                    stat(s.recap_rate + '%', 'Recap Rate', s.recap_rate >= 70 ? 'green' : 'red') +
                    stat(s.avg_rating + '★', 'Avg Rating', s.avg_rating >= 4.5 ? 'green' : '') +
                    stat(s.upcoming, 'Upcoming') +
                    '</div>';
            });

            function stat(val, label, cls, dollar) {
                var v = dollar ? '$' + parseInt(val).toLocaleString() : val;
                return '<div class="ptp-ai-stat"><div class="ptp-ai-stat-val ' + (cls||'') + '">' + v + '</div><div class="ptp-ai-stat-lbl">' + label + '</div></div>';
            }

            window.fetchInsights = function() {
                var btn = document.getElementById('ptpAiBtn');
                var tips = document.getElementById('ptpAiTips');
                tips.innerHTML = '<div class="ptp-ai-loading"><div class="ptp-ai-spinner"></div><p style="margin:12px 0 0;font-size:13px;color:#9CA3AF">Claude is analyzing your platform data...</p></div>';

                fetch(ajaxUrl, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: 'action=ptp_admin_ai_insights&nonce=' + nonce })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) {
                        tips.innerHTML = '<div class="ptp-ai-empty">' + (res.data || 'Error') + '<br><button class="ptp-ai-btn" onclick="fetchInsights()" style="margin-top:16px">Retry</button></div>';
                        return;
                    }
                    var items = res.data.insights;
                    if (!items || !items.length) {
                        tips.innerHTML = '<div class="ptp-ai-empty">No insights generated. Try again later.</div>';
                        return;
                    }
                    var html = '';
                    items.forEach(function(t) {
                        var p = t.priority || 'medium';
                        html += '<div class="ptp-ai-tip">';
                        html += '<div class="ptp-ai-tip-icon">' + (t.icon || '💡') + '</div>';
                        html += '<div><div class="ptp-ai-tip-title ' + p + '">' + (t.title||'Tip') + '<span class="ptp-ai-tip-priority ' + p + '">' + p + '</span></div>';
                        html += '<div class="ptp-ai-tip-body">' + (t.body||'') + '</div></div></div>';
                    });
                    html += '<div style="text-align:center;padding:16px"><button style="background:none;border:none;color:#9CA3AF;cursor:pointer;font-size:12px" onclick="fetchInsights()">&#x21bb; Refresh insights</button></div>';
                    tips.innerHTML = html;

                    var ts = res.data.generated_at;
                    if (ts) document.getElementById('ptpAiTime').textContent = 'Updated: ' + new Date(ts).toLocaleString();
                }).catch(function() {
                    tips.innerHTML = '<div class="ptp-ai-empty">Connection error<br><button class="ptp-ai-btn" onclick="fetchInsights()" style="margin-top:16px">Retry</button></div>';
                });
            };
        })();
        </script>
        <?php endif;
    }

    // =========================================================================
    // AJAX HANDLER
    // =========================================================================

    public static function ajax_get_insights() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Nonce check (supports both GET and POST)
        $nonce = $_REQUEST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'ptp_nonce')) {
            wp_send_json_error('Invalid nonce');
        }

        $stats = self::gather_platform_stats();

        // Quick stats mode (no Claude call)
        if (!empty($_GET['just_stats'])) {
            wp_send_json_success(array('stats' => $stats));
        }

        $api_key = self::get_api_key();
        if (empty($api_key)) {
            wp_send_json_error('API key not configured');
        }

        // Check cache (6 hour TTL)
        $cached = get_transient('ptp_admin_ai_insights');
        if ($cached) {
            wp_send_json_success(array(
                'insights' => $cached['insights'],
                'stats' => $stats,
                'generated_at' => $cached['generated_at'],
            ));
        }

        $prompt = self::build_prompt($stats);
        $insights = self::call_claude($api_key, $prompt);

        if (is_wp_error($insights)) {
            wp_send_json_error($insights->get_error_message());
        }

        $result = array(
            'insights' => $insights,
            'generated_at' => current_time('c'),
        );
        set_transient('ptp_admin_ai_insights', $result, 6 * HOUR_IN_SECONDS);

        wp_send_json_success(array_merge($result, array('stats' => $stats)));
    }

    // =========================================================================
    // GATHER PLATFORM-WIDE DATA
    // =========================================================================

    private static function gather_platform_stats() {
        global $wpdb;
        $p = $wpdb->prefix;

        // Revenue
        $rev_30d = floatval($wpdb->get_var("
            SELECT COALESCE(SUM(total_amount),0) FROM {$p}ptp_bookings 
            WHERE status='completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        "));
        $rev_prev = floatval($wpdb->get_var("
            SELECT COALESCE(SUM(total_amount),0) FROM {$p}ptp_bookings 
            WHERE status='completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) 
            AND session_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        "));
        $platform_rev = floatval($wpdb->get_var("
            SELECT COALESCE(SUM(total_amount - trainer_payout),0) FROM {$p}ptp_bookings 
            WHERE status='completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        "));

        // Sessions
        $sessions_30d = intval($wpdb->get_var("
            SELECT COUNT(*) FROM {$p}ptp_bookings 
            WHERE status='completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        "));
        $cancelled_30d = intval($wpdb->get_var("
            SELECT COUNT(*) FROM {$p}ptp_bookings 
            WHERE status='cancelled' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        "));
        $upcoming = intval($wpdb->get_var("
            SELECT COUNT(*) FROM {$p}ptp_bookings 
            WHERE session_date > CURDATE() AND status IN ('confirmed','pending')
        "));

        // Trainers
        $active_trainers = intval($wpdb->get_var("
            SELECT COUNT(DISTINCT trainer_id) FROM {$p}ptp_bookings 
            WHERE status='completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        "));
        $total_trainers = intval($wpdb->get_var("SELECT COUNT(*) FROM {$p}ptp_trainers WHERE status='active'"));

        // Trainer breakdown (top + bottom performers)
        $trainer_perf = $wpdb->get_results("
            SELECT t.display_name, t.id,
                   COUNT(b.id) AS sessions,
                   COALESCE(SUM(b.total_amount),0) AS revenue,
                   COUNT(DISTINCT b.parent_id) AS clients
            FROM {$p}ptp_trainers t
            LEFT JOIN {$p}ptp_bookings b ON t.id = b.trainer_id 
                AND b.status='completed' AND b.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            WHERE t.status = 'active'
            GROUP BY t.id
            ORDER BY sessions DESC
            LIMIT 10
        ");
        $trainers_arr = array();
        foreach ($trainer_perf as $t) {
            // Recap rate per trainer
            $recap_count = intval($wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$p}ptp_session_notes sn
                JOIN {$p}ptp_bookings b ON sn.booking_id = b.id
                WHERE b.trainer_id = %d AND b.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ", $t->id)));
            $trainers_arr[] = array(
                'name' => $t->display_name,
                'sessions' => intval($t->sessions),
                'revenue' => floatval($t->revenue),
                'clients' => intval($t->clients),
                'recap_rate' => $t->sessions > 0 ? round(($recap_count / $t->sessions) * 100) : 0,
            );
        }

        // Families
        $unique_fam = intval($wpdb->get_var("
            SELECT COUNT(DISTINCT parent_id) FROM {$p}ptp_bookings 
            WHERE status='completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        "));
        $new_fam = intval($wpdb->get_var("
            SELECT COUNT(DISTINCT parent_id) FROM {$p}ptp_bookings 
            WHERE status='completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND parent_id NOT IN (
                SELECT DISTINCT parent_id FROM {$p}ptp_bookings 
                WHERE status='completed' AND session_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            )
        "));
        $repeat_fam = intval($wpdb->get_var("
            SELECT COUNT(DISTINCT parent_id) FROM {$p}ptp_bookings 
            WHERE status='completed'
            AND parent_id IN (
                SELECT parent_id FROM {$p}ptp_bookings WHERE status='completed'
                GROUP BY parent_id HAVING COUNT(*) > 1
            )
            AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        "));
        $repeat_rate = $unique_fam > 0 ? round(($repeat_fam / $unique_fam) * 100) : 0;

        // At-risk families (booked before, nothing in 21+ days, within 90 days)
        $at_risk = $wpdb->get_results("
            SELECT pa.display_name AS name, MAX(b.session_date) AS last_session, 
                   COUNT(*) AS total_sessions, SUM(b.total_amount) AS ltv
            FROM {$p}ptp_bookings b
            JOIN {$p}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.status='completed'
            GROUP BY b.parent_id
            HAVING last_session < DATE_SUB(CURDATE(), INTERVAL 21 DAY)
            AND last_session >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            ORDER BY total_sessions DESC
            LIMIT 8
        ");
        $at_risk_arr = array();
        foreach ($at_risk as $ar) {
            $at_risk_arr[] = array(
                'name' => $ar->name,
                'days_since' => floor((time() - strtotime($ar->last_session)) / 86400),
                'sessions' => intval($ar->total_sessions),
                'ltv' => floatval($ar->ltv),
            );
        }

        // Recaps
        $total_completed_30d = $sessions_30d;
        $recaps_sent_30d = intval($wpdb->get_var("
            SELECT COUNT(*) FROM {$p}ptp_session_notes sn
            JOIN {$p}ptp_bookings b ON sn.booking_id = b.id
            WHERE b.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND b.status='completed'
        "));
        $recap_rate = $total_completed_30d > 0 ? round(($recaps_sent_30d / $total_completed_30d) * 100) : 0;

        // Reviews
        $avg_rating = floatval($wpdb->get_var("SELECT COALESCE(AVG(rating),0) FROM {$p}ptp_reviews WHERE rating > 0"));
        $reviews_30d = intval($wpdb->get_var("
            SELECT COUNT(*) FROM {$p}ptp_reviews WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        "));
        $unreplied = intval($wpdb->get_var("
            SELECT COUNT(*) FROM {$p}ptp_reviews 
            WHERE (trainer_reply IS NULL OR trainer_reply='') AND (trainer_response IS NULL OR trainer_response='')
        "));

        // Weekly revenue trend (last 6 weeks)
        $weekly = $wpdb->get_results("
            SELECT YEARWEEK(session_date,1) AS yw, 
                   COALESCE(SUM(total_amount),0) AS rev,
                   COUNT(*) AS sessions
            FROM {$p}ptp_bookings 
            WHERE status='completed' AND session_date >= DATE_SUB(CURDATE(), INTERVAL 42 DAY)
            GROUP BY yw ORDER BY yw
        ");
        $trends = array();
        foreach ($weekly as $w) {
            $trends[] = array('week' => $w->yw, 'revenue' => floatval($w->rev), 'sessions' => intval($w->sessions));
        }

        // Availability gaps (trainers with zero availability next 14 days)
        $no_avail = intval($wpdb->get_var("
            SELECT COUNT(*) FROM {$p}ptp_trainers t 
            WHERE t.status='active' 
            AND t.id NOT IN (
                SELECT DISTINCT trainer_id FROM {$p}ptp_availability 
                WHERE date >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
            )
        "));

        return array(
            'revenue_30d' => $rev_30d,
            'revenue_prev_30d' => $rev_prev,
            'platform_revenue_30d' => $platform_rev,
            'sessions_30d' => $sessions_30d,
            'cancelled_30d' => $cancelled_30d,
            'upcoming' => $upcoming,
            'active_trainers' => $active_trainers,
            'total_trainers' => $total_trainers,
            'trainers_no_availability' => $no_avail,
            'trainer_breakdown' => $trainers_arr,
            'unique_families' => $unique_fam,
            'new_families' => $new_fam,
            'repeat_rate' => $repeat_rate,
            'at_risk_families' => $at_risk_arr,
            'recap_rate' => $recap_rate,
            'avg_rating' => round($avg_rating, 1),
            'reviews_30d' => $reviews_30d,
            'unreplied_reviews' => $unreplied,
            'weekly_trends' => $trends,
        );
    }

    // =========================================================================
    // BUILD PROMPT
    // =========================================================================

    private static function build_prompt($s) {
        $rev_change = $s['revenue_prev_30d'] > 0
            ? round((($s['revenue_30d'] - $s['revenue_prev_30d']) / $s['revenue_prev_30d']) * 100) : 0;

        $trainers_text = '';
        foreach ($s['trainer_breakdown'] as $t) {
            $trainers_text .= "  - {$t['name']}: {$t['sessions']} sessions, \${$t['revenue']} rev, {$t['clients']} clients, {$t['recap_rate']}% recap rate\n";
        }

        $at_risk_text = '';
        foreach ($s['at_risk_families'] as $ar) {
            $at_risk_text .= "  - {$ar['name']}: {$ar['days_since']}d since last session, {$ar['sessions']} total sessions, \${$ar['ltv']} LTV\n";
        }

        $trends_text = '';
        foreach ($s['weekly_trends'] as $w) {
            $trends_text .= "  Week {$w['week']}: \${$w['revenue']} ({$w['sessions']} sessions)\n";
        }

        return "You are the AI business advisor for PTP Soccer, a youth soccer training marketplace. The founder Luke runs the platform. Analyze this platform data and give exactly 5-6 specific, actionable business recommendations. Be direct — tell Luke exactly what to do this week. Each recommendation should have a bold title (2-5 words), 2-3 sentences of specific advice with numbers, and a priority level.

Focus areas: revenue growth, trainer activation, family retention, operational fixes, and quick wins.

PLATFORM DATA — LAST 30 DAYS:

Revenue: \${$s['revenue_30d']} ({$rev_change}% vs prev 30d)
Platform take: \${$s['platform_revenue_30d']}
Sessions completed: {$s['sessions_30d']}
Cancelled: {$s['cancelled_30d']}
Upcoming booked: {$s['upcoming']}

Trainers: {$s['active_trainers']} active / {$s['total_trainers']} total
Trainers with NO availability next 14 days: {$s['trainers_no_availability']}

Families: {$s['unique_families']} active, {$s['new_families']} new, repeat rate {$s['repeat_rate']}%
At-risk families (21-90 days inactive):
{$at_risk_text}

Recaps sent: {$s['recap_rate']}% of sessions
Reviews: {$s['avg_rating']}/5 avg, {$s['reviews_30d']} new, {$s['unreplied_reviews']} unreplied

Trainer breakdown:
{$trainers_text}

Revenue trend (weekly):
{$trends_text}

Respond with a JSON array of objects: [{\"title\": \"...\", \"body\": \"...\", \"priority\": \"high|medium|low\", \"icon\": \"emoji\"}]
Only return the JSON array, nothing else.";
    }

    // =========================================================================
    // CLAUDE API CALL
    // =========================================================================

    private static function call_claude($api_key, $prompt) {
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 45,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode(array(
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 1000,
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt),
                ),
            )),
        ));

        if (is_wp_error($response)) {
            ptp_log('[PTP AI Insights] API error: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            ptp_log('[PTP AI Insights] API ' . $code . ': ' . $body);
            return new \WP_Error('api_error', 'AI temporarily unavailable (HTTP ' . $code . ')');
        }

        $decoded = json_decode($body, true);
        $text = $decoded['content'][0]['text'] ?? '';

        $insights = json_decode($text, true);
        if (!is_array($insights)) {
            if (preg_match('/\[.*\]/s', $text, $m)) {
                $insights = json_decode($m[0], true);
            }
        }

        if (!is_array($insights)) {
            ptp_log('[PTP AI Insights] Parse fail: ' . substr($text, 0, 500));
            return new \WP_Error('parse_error', 'Could not parse AI response');
        }

        return $insights;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function get_api_key() {
        $key = get_option('ptp_anthropic_api_key', '');
        if (empty($key) && defined('PTP_ANTHROPIC_API_KEY')) {
            $key = PTP_ANTHROPIC_API_KEY;
        }
        return $key;
    }
}

// Save API key handler
add_action('admin_post_ptp_save_anthropic_key', function() {
    check_admin_referer('ptp_save_anthropic_key');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    
    $key = sanitize_text_field($_POST['ptp_anthropic_api_key'] ?? '');
    update_option('ptp_anthropic_api_key', $key);
    
    wp_redirect(admin_url('admin.php?page=ptp-ai-insights&saved=1'));
    exit;
});

add_action('init', array('PTP_AI_Insights_Admin', 'init'));
