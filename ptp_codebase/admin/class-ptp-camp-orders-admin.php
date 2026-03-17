<?php
/**
 * PTP Camp Orders Admin v216.1
 * 
 * Unified admin: queries BOTH ptp_camp_bookings AND ptp_unified_camp_orders.
 * Shows all customer data, Stripe sync status, backfill tools, CSV export.
 */
defined('ABSPATH') || exit;

class PTP_Camp_Orders_Admin {
    private static $instance = null;
    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_pages'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ptp_admin_get_camp_order', array($this, 'ajax_get_order'));
        add_action('wp_ajax_ptp_admin_update_camp_order', array($this, 'ajax_update_order'));
        add_action('wp_ajax_ptp_admin_refund_camp_order', array($this, 'ajax_refund_order'));
        add_action('wp_ajax_ptp_admin_sync_stripe_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_ptp_admin_export_camp_orders', array($this, 'ajax_export_orders'));
        add_action('wp_ajax_ptp_admin_import_woo_camps', array($this, 'ajax_import_woo_camps'));
        add_action('wp_ajax_ptp_admin_push_to_stripe', array($this, 'ajax_push_to_stripe'));
        add_action('wp_ajax_ptp_admin_camp_booking_detail', array($this, 'ajax_booking_detail'));
    }
    
    public function add_menu_pages() {
        add_submenu_page('ptp-dashboard', 'Camp Orders', 'Camp Orders', 'manage_options', 'ptp-camp-orders', array($this, 'render_orders_page'));
        add_submenu_page('ptp-dashboard', 'Camp Products', 'Camp Products', 'manage_options', 'ptp-camp-products', array($this, 'render_products_page'));
    }
    
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'ptp-camp') === false) return;
        wp_enqueue_script('ptp-admin-camp', PTP_PLUGIN_URL . 'assets/js/admin-camp.js', array('jquery'), PTP_VERSION, true);
        wp_localize_script('ptp-admin-camp', 'ptpAdminCamp', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_admin_nonce'),
        ));
    }

    // ================================================================
    // UNIFIED QUERY
    // ================================================================
    private function get_all_bookings($args = array()) {
        global $wpdb;
        $d = wp_parse_args($args, array('status'=>'','search'=>'','camp_id'=>0,'per_page'=>25,'page'=>1));
        $offset = ($d['page'] - 1) * $d['per_page'];
        $cb = $wpdb->prefix . 'ptp_camp_bookings';
        $uo = $wpdb->prefix . 'ptp_unified_camp_orders';
        $has_cb = $wpdb->get_var("SHOW TABLES LIKE '{$cb}'") === $cb;
        $has_uo = $wpdb->get_var("SHOW TABLES LIKE '{$uo}'") === $uo;
        $queries = array(); $counts = array();

        if ($has_cb) {
            $w = array("1=1");
            if ($d['status']) $w[] = $wpdb->prepare("b.status=%s",$d['status']);
            if ($d['camp_id']) $w[] = $wpdb->prepare("b.camp_id=%d",$d['camp_id']);
            if ($d['search']) { $s='%'.$wpdb->esc_like($d['search']).'%'; $w[] = $wpdb->prepare("(b.customer_email LIKE %s OR b.customer_name LIKE %s OR b.camper_name LIKE %s OR b.stripe_payment_id LIKE %s)",$s,$s,$s,$s); }
            $wh = implode(' AND ',$w);
            $queries[] = "(SELECT b.id,'camp_bookings' AS source,b.camp_id,b.customer_email,b.customer_name,b.customer_phone,b.camper_name,b.camper_dob,b.camper_shirt,b.emergency_contact,b.emergency_phone,b.base_price,b.discount_amount,b.amount_paid,b.coupon_code,b.referral_code,b.stripe_payment_id AS stripe_pi,b.status,b.how_found_us,b.utm_source,b.utm_medium,b.utm_campaign,b.created_at AS booked_at FROM {$cb} b WHERE {$wh})";
            $counts[] = "(SELECT COUNT(*) FROM {$cb} b WHERE {$wh})";
        }
        if ($has_uo) {
            $w = array("1=1");
            if ($d['status']) $w[] = $wpdb->prepare("o.status=%s",$d['status']);
            if ($d['search']) { $s='%'.$wpdb->esc_like($d['search']).'%'; $w[] = $wpdb->prepare("(o.billing_email LIKE %s OR o.billing_first_name LIKE %s OR o.billing_last_name LIKE %s OR o.stripe_payment_intent_id LIKE %s)",$s,$s,$s,$s); }
            $wh = implode(' AND ',$w);
            $queries[] = "(SELECT o.id,'unified_orders' AS source,0 AS camp_id,o.billing_email AS customer_email,CONCAT(o.billing_first_name,' ',o.billing_last_name) AS customer_name,o.billing_phone AS customer_phone,'' AS camper_name,'' AS camper_dob,'' AS camper_shirt,'' AS emergency_contact,'' AS emergency_phone,o.subtotal_amount AS base_price,o.discount_amount,o.total_amount AS amount_paid,o.coupon_code_used AS coupon_code,o.referral_code_used AS referral_code,o.stripe_payment_intent_id AS stripe_pi,o.status,'' AS how_found_us,'' AS utm_source,'' AS utm_medium,'' AS utm_campaign,o.created_at AS booked_at FROM {$uo} o WHERE {$wh})";
            $counts[] = "(SELECT COUNT(*) FROM {$uo} o WHERE {$wh})";
        }
        if (empty($queries)) return array('bookings'=>array(),'total'=>0);
        
        $union = implode(" UNION ALL ", $queries);
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM ({$union}) AS combined ORDER BY booked_at DESC LIMIT %d OFFSET %d", $d['per_page'], $offset));
        $total = count($counts) === 1 ? intval($wpdb->get_var($counts[0])) : intval($wpdb->get_var("SELECT (" . implode(") + (", $counts) . ")"));
        return array('bookings' => $results ?: array(), 'total' => $total);
    }

    private function get_stats() {
        global $wpdb;
        $cb = $wpdb->prefix . 'ptp_camp_bookings';
        $uo = $wpdb->prefix . 'ptp_unified_camp_orders';
        $s = array('total_revenue'=>0,'total_bookings'=>0,'confirmed'=>0,'refunded'=>0,'unique_families'=>0,'avg_order'=>0,'today_revenue'=>0,'today_bookings'=>0);

        if ($wpdb->get_var("SHOW TABLES LIKE '{$cb}'") === $cb) {
            $r = $wpdb->get_row("SELECT COUNT(*) cnt,SUM(amount_paid) rev,SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) conf,SUM(CASE WHEN status='refunded' THEN 1 ELSE 0 END) ref,COUNT(DISTINCT customer_email) fam,SUM(CASE WHEN DATE(created_at)=CURDATE() THEN amount_paid ELSE 0 END) trev,SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) tcnt FROM {$cb}");
            if ($r) { $s['total_bookings']+=intval($r->cnt); $s['total_revenue']+=floatval($r->rev); $s['confirmed']+=intval($r->conf); $s['refunded']+=intval($r->ref); $s['unique_families']+=intval($r->fam); $s['today_revenue']+=floatval($r->trev); $s['today_bookings']+=intval($r->tcnt); }
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '{$uo}'") === $uo) {
            $r = $wpdb->get_row("SELECT COUNT(*) cnt,SUM(total_amount) rev,SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) conf,SUM(CASE WHEN status='refunded' THEN 1 ELSE 0 END) ref,COUNT(DISTINCT billing_email) fam,SUM(CASE WHEN DATE(created_at)=CURDATE() THEN total_amount ELSE 0 END) trev,SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) tcnt FROM {$uo}");
            if ($r) { $s['total_bookings']+=intval($r->cnt); $s['total_revenue']+=floatval($r->rev); $s['confirmed']+=intval($r->conf); $s['refunded']+=intval($r->ref); $s['unique_families']+=intval($r->fam); $s['today_revenue']+=floatval($r->trev); $s['today_bookings']+=intval($r->tcnt); }
        }
        if ($s['total_bookings'] > 0) $s['avg_order'] = $s['total_revenue'] / $s['total_bookings'];
        return $s;
    }

    private function get_camp_names() {
        global $wpdb;
        $names = array();
        $sp = $wpdb->prefix . 'ptp_stripe_products';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$sp}'") === $sp) {
            foreach ($wpdb->get_results("SELECT id, name FROM {$sp} WHERE product_type='camp' ORDER BY name") as $r) $names[$r->id] = $r->name;
        }
        foreach (get_posts(array('post_type'=>'ptp_camp','posts_per_page'=>-1,'post_status'=>'any')) as $c) {
            if (!isset($names[$c->ID])) $names[$c->ID] = $c->post_title;
        }
        return $names;
    }

    private function camp_name($id) {
        if (!$id) return '—';
        $p = get_post($id);
        if ($p && $p->post_type === 'ptp_camp') return $p->post_title;
        global $wpdb;
        $n = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}ptp_stripe_products WHERE id=%d",$id));
        return $n ?: 'Camp #'.$id;
    }

    // ================================================================
    // RENDER
    // ================================================================
    public function render_orders_page() {
        if (isset($_GET['action']) && $_GET['action'] === 'export') { $this->export_csv(); return; }
        $status = sanitize_text_field($_GET['status'] ?? '');
        $search = sanitize_text_field($_GET['s'] ?? '');
        $camp_id = intval($_GET['camp_id'] ?? 0);
        $paged = max(1, intval($_GET['paged'] ?? 1));

        $data = $this->get_all_bookings(array('status'=>$status,'search'=>$search,'camp_id'=>$camp_id,'per_page'=>25,'page'=>$paged));
        $bookings = $data['bookings']; $total = $data['total']; $total_pages = ceil($total / 25);
        $stats = $this->get_stats();
        $camp_names = $this->get_camp_names();
        $tab = sanitize_text_field($_GET['tab'] ?? 'bookings');
        ?>
        <div class="wrap ptp-ca">
            <h1 class="wp-heading-inline" style="font-weight:700;">Camp Orders</h1>
            <a href="<?php echo esc_url(add_query_arg('action','export')); ?>" class="page-title-action">Export CSV</a>
            <button type="button" id="ptp-bf-btn" class="page-title-action" style="background:#FCB900;color:#0A0A0A;border-color:#e0a800;">Sync Stripe Customers</button>
            <hr class="wp-header-end">

            <!-- Tabs -->
            <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a href="<?php echo esc_url(add_query_arg('tab','bookings')); ?>" class="nav-tab <?php echo $tab==='bookings'?'nav-tab-active':''; ?>">Bookings</a>
                <a href="<?php echo esc_url(add_query_arg('tab','abandoned')); ?>" class="nav-tab <?php echo $tab==='abandoned'?'nav-tab-active':''; ?>">Abandoned Cart Recovery</a>
            </nav>

            <?php if ($tab === 'abandoned'): ?>
                <?php if (class_exists('PTP_Abandoned_Cart_Recovery')) PTP_Abandoned_Cart_Recovery::render_admin_tab(); ?>
            <?php else: ?>

            <div class="ptp-sr"><?php
                $cards = array(
                    array('$'.number_format($stats['total_revenue'],0),'Total Revenue'),
                    array($stats['total_bookings'],'Total Bookings'),
                    array($stats['unique_families'],'Unique Families'),
                    array('$'.number_format($stats['avg_order'],0),'Avg Order'),
                    array($stats['today_bookings'],'Today','gold'),
                    array('$'.number_format($stats['today_revenue'],0),'Today Rev','gold'),
                );
                foreach ($cards as $c) {
                    $bdr = isset($c[2]) ? 'border-left:3px solid #FCB900;' : '';
                    echo "<div class='ptp-sc' style='{$bdr}'><span class='v'>{$c[0]}</span><span class='l'>{$c[1]}</span></div>";
                }
            ?></div>

            <form method="get" class="ptp-fb">
                <input type="hidden" name="page" value="ptp-camp-orders">
                <select name="status"><option value="">All Statuses</option>
                    <option value="confirmed" <?php selected($status,'confirmed');?>>Confirmed</option>
                    <option value="completed" <?php selected($status,'completed');?>>Completed</option>
                    <option value="refunded" <?php selected($status,'refunded');?>>Refunded</option>
                    <option value="cancelled" <?php selected($status,'cancelled');?>>Cancelled</option>
                </select>
                <select name="camp_id"><option value="">All Camps</option>
                    <?php foreach ($camp_names as $cid=>$cn): ?><option value="<?php echo $cid;?>" <?php selected($camp_id,$cid);?>><?php echo esc_html($cn);?></option><?php endforeach; ?>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search);?>" placeholder="Search email, name, PI...">
                <button type="submit" class="button">Filter</button>
                <?php if ($status||$search||$camp_id): ?><a href="<?php echo admin_url('admin.php?page=ptp-camp-orders');?>" class="button">Clear</a><?php endif; ?>
                <span style="margin-left:auto;font-size:13px;color:#888;"><?php echo $total;?> result<?php echo $total!==1?'s':'';?></span>
            </form>

            <table class="wp-list-table widefat striped ptp-bt">
                <thead><tr>
                    <th style="width:40px">ID</th><th>Parent</th><th>Camper</th><th>Camp</th><th>Amount</th><th>Status</th><th>Stripe PI</th><th>Source</th><th>Date</th>
                </tr></thead>
                <tbody>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="9" style="text-align:center;padding:40px;color:#999;">No bookings found.</td></tr>
                <?php else: foreach ($bookings as $b):
                    $cl = $this->camp_name($b->camp_id);
                    $sl = $b->source==='camp_bookings'?'Native':'Unified';
                    $sc = $b->source==='camp_bookings'?'#3b82f6':'#8b5cf6';
                    $st = $b->status; if($st==='completed')$st='confirmed';
                    $hs = !empty($b->stripe_pi) && strpos($b->stripe_pi,'pi_')===0;
                    $su = $hs?'https://dashboard.stripe.com/payments/'.$b->stripe_pi:'';
                    $hd = $b->emergency_contact||$b->utm_source||$b->how_found_us||$b->referral_code;
                ?>
                    <tr class="ptp-br" data-id="<?php echo $b->id;?>-<?php echo $b->source;?>">
                        <td><strong><?php echo $b->id;?></strong></td>
                        <td><strong><?php echo esc_html($b->customer_name);?></strong><br><a href="mailto:<?php echo esc_attr($b->customer_email);?>" style="font-size:12px;"><?php echo esc_html($b->customer_email);?></a><?php if($b->customer_phone):?><br><span style="font-size:11px;color:#888;"><?php echo esc_html($b->customer_phone);?></span><?php endif;?></td>
                        <td><?php if($b->camper_name):?><strong><?php echo esc_html($b->camper_name);?></strong><?php if($b->camper_shirt):?><br><span style="font-size:11px;color:#888;">Shirt: <?php echo esc_html($b->camper_shirt);?></span><?php endif;if($b->camper_dob):?><br><span style="font-size:11px;color:#888;">DOB: <?php echo esc_html($b->camper_dob);?></span><?php endif;else:?><span style="color:#ccc;">—</span><?php endif;?></td>
                        <td style="max-width:180px;"><?php echo esc_html($cl);?></td>
                        <td><strong>$<?php echo number_format($b->amount_paid,2);?></strong><?php if($b->discount_amount>0):?><br><span style="font-size:11px;color:#10b981;">-$<?php echo number_format($b->discount_amount,2);?></span><?php endif;if($b->coupon_code):?><br><span style="font-size:11px;color:#d97706;"><?php echo esc_html($b->coupon_code);?></span><?php endif;?></td>
                        <td><span class="ptp-bg ptp-bg-<?php echo esc_attr($st);?>"><?php echo ucfirst($b->status);?></span></td>
                        <td style="font-size:12px;"><?php if($hs):?><a href="<?php echo esc_url($su);?>" target="_blank" style="font-family:monospace;"><?php echo substr($b->stripe_pi,0,15);?>...</a><?php else:?><span style="color:#ccc;">—</span><?php endif;?></td>
                        <td><span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;color:#fff;background:<?php echo $sc;?>;"><?php echo $sl;?></span></td>
                        <td style="font-size:12px;"><?php echo date('M j, Y',strtotime($b->booked_at));?><br><span style="color:#888;"><?php echo date('g:i a',strtotime($b->booked_at));?></span></td>
                    </tr>
                    <?php if($hd):?><tr class="ptp-dr" style="display:none;" data-p="<?php echo $b->id;?>-<?php echo $b->source;?>"><td colspan="9" style="background:#f9f9f9;padding:10px 16px;border-top:none;"><div style="display:flex;gap:24px;flex-wrap:wrap;font-size:12px;"><?php
                        if($b->emergency_contact) echo "<div><strong>Emergency:</strong> ".esc_html($b->emergency_contact)." ".esc_html($b->emergency_phone)."</div>";
                        if($b->referral_code) echo "<div><strong>Referral:</strong> ".esc_html($b->referral_code)."</div>";
                        if($b->how_found_us) echo "<div><strong>How found:</strong> ".esc_html($b->how_found_us)."</div>";
                        if($b->utm_source) echo "<div><strong>UTM:</strong> ".esc_html($b->utm_source)."/".esc_html($b->utm_medium)."/".esc_html($b->utm_campaign)."</div>";
                    ?></div></td></tr><?php endif; ?>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom"><div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total;?> items</span>
                <?php if($paged>1):?><a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged',$paged-1));?>">&lsaquo;</a><?php endif;?>
                <span class="paging-input"><?php echo $paged;?> of <?php echo $total_pages;?></span>
                <?php if($paged<$total_pages):?><a class="next-page button" href="<?php echo esc_url(add_query_arg('paged',$paged+1));?>">&rsaquo;</a><?php endif;?>
            </div></div>
            <?php endif; ?>

            <?php endif; /* end bookings tab */ ?>

            <!-- Backfill Modal -->
            <div id="ptp-bfm" style="display:none;">
                <div class="ptp-mo"></div>
                <div class="ptp-mb">
                    <h2 style="margin-top:0;">Sync Stripe Customers</h2>
                    <p>Scans all payment records. For each PaymentIntent: attaches a Stripe Customer (find or create by email), fills receipt_email, standardizes metadata.</p>
                    <div id="ptp-bfs"><button type="button" id="ptp-bfsc" class="button button-primary" style="background:#FCB900;border-color:#e0a800;color:#0A0A0A;font-weight:600;">Scan Records</button></div>
                    <div id="ptp-bfp" style="display:none;"><div class="ptp-pb"><div class="ptp-pf"></div></div><p class="ptp-pt"></p></div>
                    <div id="ptp-bfr" style="display:none;"></div>
                    <button type="button" class="ptp-mc button" style="margin-top:12px;">Close</button>
                </div>
            </div>
        </div>

        <style>
        .ptp-ca{max-width:1400px}.ptp-sr{display:flex;gap:0;margin:16px 0;background:#fff;border:1px solid #ddd;border-radius:8px;overflow:hidden}.ptp-sc{flex:1;padding:14px 16px;text-align:center;border-right:1px solid #eee}.ptp-sc:last-child{border-right:none}.ptp-sc .v{display:block;font-size:26px;font-weight:700;color:#1d2327;line-height:1.2}.ptp-sc .l{font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px}
        .ptp-fb{display:flex;align-items:center;gap:8px;background:#fff;padding:12px 16px;border:1px solid #ddd;border-radius:8px;margin-bottom:16px;flex-wrap:wrap}.ptp-fb select,.ptp-fb input[type=search]{padding:6px 10px}.ptp-fb input[type=search]{min-width:220px}
        .ptp-bt th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#888;font-weight:600}.ptp-bt td{vertical-align:top}
        .ptp-br{cursor:pointer}.ptp-br:hover{background:#fefce8!important}
        .ptp-bg{display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600}.ptp-bg-confirmed{background:#d1fae5;color:#065f46}.ptp-bg-completed{background:#d1fae5;color:#065f46}.ptp-bg-refunded{background:#fee2e2;color:#991b1b}.ptp-bg-cancelled{background:#f3f4f6;color:#6b7280}.ptp-bg-pending{background:#fef3c7;color:#92400e}
        .ptp-mo{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:100000}.ptp-mb{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:12px;padding:28px;max-width:520px;width:90%;z-index:100001;box-shadow:0 20px 60px rgba(0,0,0,.3)}
        .ptp-pb{height:8px;background:#eee;border-radius:4px;overflow:hidden;margin:12px 0}.ptp-pf{height:100%;background:#FCB900;border-radius:4px;transition:width .3s;width:0%}.ptp-pt{font-size:13px;color:#666}
        </style>

        <script>
        jQuery(function($){
            $('.ptp-br').on('click',function(e){if($(e.target).is('a'))return;var id=$(this).data('id');$('[data-p="'+id+'"]').toggle();});
            $('#ptp-bf-btn').on('click',function(){$('#ptp-bfm').show();});
            $('.ptp-mc,.ptp-mo').on('click',function(){$('#ptp-bfm').hide();});
            
            $('#ptp-bfsc').on('click',function(){
                var $b=$(this);$b.prop('disabled',true).text('Scanning...');
                $.post(ptpAdminCamp.ajaxUrl,{action:'ptp_stripe_backfill_scan',_ajax_nonce:ptpAdminCamp.nonce},function(r){
                    if(r.success){
                        var d=r.data,h='<p><strong>'+d.total_records+'</strong> payment records found.</p>';
                        if(d.preview&&d.preview.length){
                            h+='<table style="width:100%;font-size:12px;border-collapse:collapse;margin:8px 0;"><tr><th style="text-align:left;padding:4px;">PI</th><th style="text-align:left;padding:4px;">Email</th><th style="padding:4px;">Customer?</th><th style="padding:4px;">Receipt?</th></tr>';
                            d.preview.forEach(function(p){h+='<tr><td style="padding:4px;font-family:monospace;font-size:11px;">'+(p.pi_id||'').substring(0,15)+'...</td><td style="padding:4px;">'+(p.email||'—')+'</td><td style="padding:4px;text-align:center;">'+(p.has_customer?'<span style="color:green;">&#10003;</span>':'<span style="color:red;">&#10007;</span>')+'</td><td style="padding:4px;text-align:center;">'+(p.has_receipt?'<span style="color:green;">&#10003;</span>':'<span style="color:red;">&#10007;</span>')+'</td></tr>';});
                            h+='</table>';
                        }
                        h+='<button type="button" id="ptp-bfgo" class="button button-primary" style="background:#FCB900;border-color:#e0a800;color:#0A0A0A;font-weight:600;margin-top:8px;">Run Backfill ('+d.total_records+' records)</button>';
                        $('#ptp-bfs').html(h);
                        $('#ptp-bfgo').on('click',function(){$(this).remove();$('#ptp-bfp').show();runB(0,d.total_records,d.batch_size||25);});
                    } else { $('#ptp-bfs').html('<p style="color:red;">Scan failed</p>'); }
                    $b.prop('disabled',false).text('Scan Records');
                });
            });
            
            function runB(off,tot,bs){
                $.post(ptpAdminCamp.ajaxUrl,{action:'ptp_stripe_backfill_run',_ajax_nonce:ptpAdminCamp.nonce,offset:off,batch_size:bs},function(r){
                    if(r.success){
                        var d=r.data,pct=Math.min(100,Math.round(((off+d.processed)/tot)*100)),t=d.totals||{};
                        $('.ptp-pf').css('width',pct+'%');
                        $('.ptp-pt').text('Progress: '+pct+'% — Updated: '+(t.updated||0)+', OK: '+(t.already_ok||0)+', Errors: '+((t.errors||[]).length));
                        if(d.has_more&&d.processed>0){runB(d.next_offset,tot,bs);}
                        else{$('#ptp-bfr').html('<h3 style="color:#065f46;margin:0 0 8px;">Complete</h3><p>Scanned: '+(t.scanned||0)+'<br>Updated: <strong>'+(t.updated||0)+'</strong><br>Already OK: '+(t.already_ok||0)+'<br>Skipped: '+(t.skipped||0)+(((t.errors||[]).length>0)?'<br><span style="color:red;">Errors: '+t.errors.length+'</span>':'')+'</p>').show();}
                    }
                });
            }
        });
        </script>
        <?php
    }

    // ================================================================
    // CSV EXPORT
    // ================================================================
    private function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $data = $this->get_all_bookings(array('per_page'=>10000,'page'=>1));
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="ptp-camp-bookings-'.date('Y-m-d').'.csv"');
        $out = fopen('php://output','w');
        fputcsv($out,array('ID','Source','Camp ID','Camp Name','Parent Email','Parent Name','Phone','Camper Name','Camper DOB','Shirt','Emergency Contact','Emergency Phone','Base Price','Discount','Amount Paid','Coupon','Referral','Stripe PI','Status','How Found','UTM Source','UTM Medium','UTM Campaign','Booked At'));
        foreach ($data['bookings'] as $b) {
            fputcsv($out,array($b->id,$b->source,$b->camp_id,$this->camp_name($b->camp_id),$b->customer_email,$b->customer_name,$b->customer_phone,$b->camper_name,$b->camper_dob,$b->camper_shirt,$b->emergency_contact,$b->emergency_phone,$b->base_price,$b->discount_amount,$b->amount_paid,$b->coupon_code,$b->referral_code,$b->stripe_pi,$b->status,$b->how_found_us,$b->utm_source,$b->utm_medium,$b->utm_campaign,$b->booked_at));
        }
        fclose($out); exit;
    }

    // ================================================================
    // AJAX STUBS (preserved)
    // ================================================================
    public function ajax_get_order() { check_ajax_referer('ptp_admin_nonce'); if(!current_user_can('manage_options'))wp_send_json_error('Unauthorized'); $id=intval($_POST['order_id']??0); if(!$id)wp_send_json_error('Missing ID'); if(class_exists('PTP_Camp_Orders')){$o=PTP_Camp_Orders::get_order($id);if($o)wp_send_json_success(array('order'=>$o));} global $wpdb; $b=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_camp_bookings WHERE id=%d",$id)); if($b)wp_send_json_success(array('booking'=>$b)); wp_send_json_error('Not found'); }
    public function ajax_update_order() { check_ajax_referer('ptp_admin_nonce'); wp_send_json_error('Not implemented'); }
    public function ajax_refund_order() { check_ajax_referer('ptp_admin_nonce'); wp_send_json_error('Not implemented'); }
    public function ajax_sync_products() { check_ajax_referer('ptp_admin_nonce'); wp_send_json_success(array('message'=>'Sync complete')); }
    public function ajax_export_orders() { check_ajax_referer('ptp_admin_nonce'); $this->export_csv(); }
    public function ajax_import_woo_camps() { check_ajax_referer('ptp_admin_nonce'); wp_send_json_error('Deprecated'); }
    public function ajax_push_to_stripe() { check_ajax_referer('ptp_admin_nonce'); wp_send_json_error('Not implemented'); }
    public function ajax_booking_detail() { check_ajax_referer('ptp_admin_nonce'); $this->ajax_get_order(); }

    // ================================================================
    // PRODUCTS PAGE
    // ================================================================
    public function render_products_page() {
        global $wpdb;
        $t=$wpdb->prefix.'ptp_stripe_products';
        if($wpdb->get_var("SHOW TABLES LIKE '{$t}'")!==$t){echo '<div class="wrap"><h1>Camp Products</h1><p>Table not initialized.</p></div>';return;}
        $products=$wpdb->get_results("SELECT * FROM {$t} WHERE product_type='camp' ORDER BY name");
        ?><div class="wrap"><h1>Camp Products (Stripe)</h1>
        <table class="wp-list-table widefat striped"><thead><tr><th>ID</th><th>Name</th><th>Price</th><th>Location</th><th>Dates</th><th>Stripe Product</th><th>Status</th></tr></thead><tbody>
        <?php foreach($products as $p):?><tr><td><?php echo $p->id;?></td><td><strong><?php echo esc_html($p->name);?></strong></td><td>$<?php echo number_format(($p->price_cents??0)/100,2);?></td><td><?php echo esc_html($p->camp_location??'');?></td><td><?php echo esc_html($p->camp_dates??'');?></td><td style="font-family:monospace;font-size:12px;"><?php echo esc_html($p->stripe_product_id??'');?></td><td><?php echo esc_html($p->status??'active');?></td></tr><?php endforeach;?>
        </tbody></table></div><?php
    }
}
