<?php
/**
 * Booking Wizard Template
 * 
 * Standalone booking flow for use via [ptp_booking_wizard] shortcode.
 * Matches the trainer profile booking panel style.
 * 
 * Expected variables from PTP_Booking_Wizard::render_wizard():
 *   $trainer - trainer DB row
 *   $atts    - shortcode attributes
 */
defined('ABSPATH') || exit;

$rate = intval($trainer->hourly_rate ?: 75);
$display_name = esc_html($trainer->display_name ?: 'Trainer');
$photo_url = !empty($trainer->photo_url) ? esc_url($trainer->photo_url) : '';
$trainer_id = intval($trainer->id);
$trainer_slug = esc_attr($trainer->slug);

// Locations
$locations = $trainer->training_locations ? json_decode($trainer->training_locations, true) : [];
if (!is_array($locations) && !empty($trainer->training_locations)) {
    $locations = json_decode(wp_unslash($trainer->training_locations), true);
}
if (!is_array($locations)) $locations = [];
$locations = array_values(array_filter($locations, function($l) { return !empty($l['name']); }));

// Available dates (next 30 days)
$available_dates = [];
if (class_exists('PTP_Availability_GCal_Bridge')) {
    $month1 = intval(date('n'));
    $year1 = intval(date('Y'));
    $real_dates = PTP_Availability_GCal_Bridge::get_real_available_dates($trainer_id, $month1, $year1);
    $month2 = $month1 === 12 ? 1 : $month1 + 1;
    $year2 = $month1 === 12 ? $year1 + 1 : $year1;
    $real_dates_next = PTP_Availability_GCal_Bridge::get_real_available_dates($trainer_id, $month2, $year2);
    $all_real = array_merge(array_keys($real_dates), array_keys($real_dates_next));
    sort($all_real);
    $cutoff = (new DateTime())->modify('+30 days')->format('Y-m-d');
    $today_str = date('Y-m-d');
    foreach ($all_real as $date_str) {
        if ($date_str <= $today_str || $date_str > $cutoff) continue;
        $available_dates[] = $date_str;
        if (count($available_dates) >= 14) break;
    }
} elseif (class_exists('PTP_Booking_Wizard')) {
    $dates = PTP_Booking_Wizard::get_available_dates($trainer_id);
    $available_dates = array_slice($dates, 0, 14);
}

// Packages
$packages = [];
if (class_exists('PTP_Booking_Wizard')) {
    $packages = PTP_Booking_Wizard::get_packages($trainer_id);
}
if (empty($packages)) {
    if (class_exists('PTP_Packages')) {
        $opts = PTP_Packages::build_options($rate);
        $packages = [];
        foreach ($opts as $k => $o) {
            $packages[] = ['key' => $k, 'label' => $o['name'], 'count' => $o['count'], 'price' => $o['price'], 'per' => $o['per'], 'save' => $o['save'] > 0 ? $o['discount'] : 0];
        }
    } else {
        $packages = [
            ['key' => 'single', 'label' => '1 Session', 'count' => 1, 'price' => $rate, 'per' => $rate, 'save' => 0],
            ['key' => '4pack', 'label' => '4 Sessions', 'count' => 4, 'price' => $rate * 4 * 0.9, 'per' => intval($rate * 0.9), 'save' => 10],
            ['key' => '8pack', 'label' => '8 Sessions', 'count' => 8, 'price' => $rate * 8 * 0.85, 'per' => intval($rate * 0.85), 'save' => 15],
        ];
    }
}

$checkout_url = home_url('/ptp-checkout/');
$ajax_url = admin_url('admin-ajax.php');
$profile_url = home_url('/trainer/' . $trainer_slug . '/');
?>

<div class="ptp-wizard" role="main" aria-label="Book a session with <?php echo $display_name; ?>">
    <style>
    .ptp-wizard{max-width:480px;margin:0 auto;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;color:#1a1a1a;padding:16px}
    .ptp-wizard *{box-sizing:border-box}
    .pw-trainer{display:flex;align-items:center;gap:12px;padding:16px;background:#fafafa;border-radius:12px;margin-bottom:20px;text-decoration:none;color:inherit}
    .pw-trainer:hover{background:#f0f0f0}
    .pw-avatar{width:48px;height:48px;border-radius:50%;object-fit:cover;background:#e5e5e5}
    .pw-name{font-size:16px;font-weight:600}
    .pw-rate{font-size:13px;color:#666;margin-top:2px}
    .pw-step{margin-bottom:24px}
    .pw-label{font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:#999;margin-bottom:10px}
    .pw-locs{display:flex;flex-direction:column;gap:8px}
    .pw-loc{padding:12px 14px;border:2px solid #e5e5e5;border-radius:10px;cursor:pointer;transition:all .15s;font-size:14px}
    .pw-loc:hover{border-color:#ccc}
    .pw-loc.sel{border-color:#FCB900;background:#FFFDF5}
    .pw-loc-name{font-weight:600}
    .pw-loc-addr{font-size:12px;color:#888;margin-top:2px}
    .pw-dates{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
    .pw-dates::-webkit-scrollbar{display:none}
    .pw-date{min-width:64px;padding:10px 8px;border:2px solid #e5e5e5;border-radius:10px;cursor:pointer;text-align:center;transition:all .15s;flex-shrink:0}
    .pw-date:hover{border-color:#ccc}
    .pw-date.sel{border-color:#FCB900;background:#FFFDF5}
    .pw-date-day{font-size:11px;color:#999;text-transform:uppercase}
    .pw-date-num{font-size:18px;font-weight:700;margin-top:2px}
    .pw-date-month{font-size:10px;color:#999;margin-top:1px}
    .pw-times{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}
    .pw-time{padding:10px;border:2px solid #e5e5e5;border-radius:8px;cursor:pointer;text-align:center;font-size:14px;font-weight:500;transition:all .15s}
    .pw-time:hover{border-color:#ccc}
    .pw-time.sel{border-color:#FCB900;background:#FFFDF5}
    .pw-time.off{opacity:.35;pointer-events:none;text-decoration:line-through}
    .pw-times-loading{text-align:center;padding:20px;color:#999;font-size:13px}
    .pw-pkgs{display:flex;flex-direction:column;gap:8px}
    .pw-pkg{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border:2px solid #e5e5e5;border-radius:10px;cursor:pointer;transition:all .15s}
    .pw-pkg:hover{border-color:#ccc}
    .pw-pkg.sel{border-color:#FCB900;background:#FFFDF5}
    .pw-pkg-info{font-weight:600;font-size:14px}
    .pw-pkg-price{text-align:right}
    .pw-pkg-total{font-weight:700;font-size:15px}
    .pw-pkg-per{font-size:11px;color:#888}
    .pw-pkg-save{font-size:11px;color:#16a34a;font-weight:600}
    .pw-cta{width:100%;padding:16px;background:#FCB900;color:#0a0a0a;border:none;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;transition:all .15s;font-family:inherit;margin-top:8px}
    .pw-cta:hover:not(:disabled){background:#e5a800;transform:translateY(-1px)}
    .pw-cta:active:not(:disabled){transform:scale(.98)}
    .pw-cta:disabled{opacity:.5;cursor:not-allowed}
    .pw-empty{color:#999;font-size:14px;text-align:center;padding:16px}
    .pw-link{display:block;text-align:center;margin-top:12px;color:#666;font-size:13px;text-decoration:none}
    .pw-link:hover{color:#1a1a1a}
    @media(max-width:380px){.pw-times{grid-template-columns:repeat(2,1fr)}}
    </style>

    <!-- Trainer Header -->
    <a href="<?php echo esc_url($profile_url); ?>" class="pw-trainer" aria-label="View <?php echo $display_name; ?>'s full profile">
        <?php if ($photo_url): ?>
            <img src="<?php echo $photo_url; ?>" class="pw-avatar" alt="<?php echo $display_name; ?>" loading="lazy">
        <?php else: ?>
            <div class="pw-avatar" style="display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:#666;background:#e5e5e5"><?php echo strtoupper(substr($display_name, 0, 1)); ?></div>
        <?php endif; ?>
        <div>
            <div class="pw-name"><?php echo $display_name; ?></div>
            <div class="pw-rate">Starting at $<?php echo $rate; ?>/session · View Profile →</div>
        </div>
    </a>

    <!-- Step 1: Location -->
    <div class="pw-step" id="pwStepLoc">
        <div class="pw-label" id="pw-loc-label">① Select Location</div>
        <div class="pw-locs" role="radiogroup" aria-labelledby="pw-loc-label">
            <?php if (!empty($locations)): ?>
                <?php foreach ($locations as $i => $loc): ?>
                    <div class="pw-loc" role="radio" aria-checked="false" tabindex="0"
                         data-loc="<?php echo esc_attr($loc['name']); ?>"
                         data-addr="<?php echo esc_attr($loc['address'] ?? ''); ?>"
                         data-lat="<?php echo esc_attr($loc['lat'] ?? ''); ?>"
                         data-lng="<?php echo esc_attr($loc['lng'] ?? ''); ?>">
                        <div class="pw-loc-name"><?php echo esc_html($loc['name']); ?></div>
                        <?php if (!empty($loc['address'])): ?>
                            <div class="pw-loc-addr"><?php echo esc_html($loc['address']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="pw-empty">Contact trainer to arrange location</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Step 2: Date -->
    <div class="pw-step" id="pwStepDate">
        <div class="pw-label" id="pw-date-label">② Select Date</div>
        <div class="pw-dates" role="radiogroup" aria-labelledby="pw-date-label">
            <?php if (!empty($available_dates)): ?>
                <?php foreach ($available_dates as $date_str): 
                    $dt = new DateTime($date_str);
                ?>
                    <div class="pw-date" role="radio" aria-checked="false" tabindex="0"
                         data-date="<?php echo esc_attr($date_str); ?>"
                         aria-label="<?php echo $dt->format('l, F j'); ?>">
                        <div class="pw-date-day"><?php echo $dt->format('D'); ?></div>
                        <div class="pw-date-num"><?php echo $dt->format('j'); ?></div>
                        <div class="pw-date-month"><?php echo $dt->format('M'); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="pw-empty">No available dates right now</div>
            <?php endif; ?>
        </div>

        <!-- Time slots (loaded via AJAX) -->
        <div id="pwTimeGrid" class="pw-times" role="radiogroup" aria-label="Available time slots" style="display:none"></div>
    </div>

    <!-- Step 3: Package -->
    <div class="pw-step" id="pwStepPkg">
        <div class="pw-label" id="pw-pkg-label">③ Select Package</div>
        <div class="pw-pkgs" role="radiogroup" aria-labelledby="pw-pkg-label">
            <?php foreach ($packages as $i => $pkg): 
                $key = $pkg['key'] ?? $pkg['slug'] ?? 'single';
                $count = intval($pkg['count'] ?? 1);
                $price = intval($pkg['price'] ?? $rate);
                $per = intval($pkg['per'] ?? $rate);
                $save = intval($pkg['save'] ?? 0);
                $label = $pkg['label'] ?? ($count === 1 ? '1 Session' : $count . ' Sessions');
            ?>
                <div class="pw-pkg <?php echo $i === 0 ? 'sel' : ''; ?>" 
                     role="radio" aria-checked="<?php echo $i === 0 ? 'true' : 'false'; ?>" tabindex="0"
                     data-pkg="<?php echo esc_attr($key); ?>"
                     data-price="<?php echo $price; ?>"
                     data-count="<?php echo $count; ?>"
                     data-per="<?php echo $per; ?>">
                    <div>
                        <div class="pw-pkg-info"><?php echo esc_html($label); ?></div>
                        <?php if ($save > 0): ?>
                            <div class="pw-pkg-save">Save <?php echo $save; ?>%</div>
                        <?php endif; ?>
                    </div>
                    <div class="pw-pkg-price">
                        <div class="pw-pkg-total">$<?php echo number_format($price); ?></div>
                        <?php if ($count > 1): ?>
                            <div class="pw-pkg-per">$<?php echo $per; ?>/each</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- CTA -->
    <button class="pw-cta" id="pwCTA" disabled aria-live="polite">Select Location</button>

    <a href="<?php echo esc_url($profile_url); ?>" class="pw-link">← View Full Profile</a>
</div>

<script>
(function(){
    var trainerId = <?php echo $trainer_id; ?>;
    var ajaxUrl = <?php echo json_encode($ajax_url); ?>;
    var checkoutUrl = <?php echo json_encode($checkout_url); ?>;

    var selLoc = '', selLocAddr = '', selLocLat = '', selLocLng = '';
    var selDate = '', selTime = '';
    var selPkg = '<?php echo esc_js($packages[0]['key'] ?? 'single'); ?>';
    var selPrice = <?php echo intval($packages[0]['price'] ?? $rate); ?>;

    var ctaBtn = document.getElementById('pwCTA');
    var timeGrid = document.getElementById('pwTimeGrid');

    /* Locations */
    document.querySelectorAll('.pw-loc').forEach(function(el){
        el.addEventListener('click', function(){
            document.querySelectorAll('.pw-loc').forEach(function(e){ e.classList.remove('sel'); e.setAttribute('aria-checked','false'); });
            el.classList.add('sel'); el.setAttribute('aria-checked','true');
            selLoc = el.dataset.loc;
            selLocAddr = el.dataset.addr || '';
            selLocLat = el.dataset.lat || '';
            selLocLng = el.dataset.lng || '';
            updateCTA();
        });
        el.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){e.preventDefault();el.click();}});
    });

    /* Dates */
    document.querySelectorAll('.pw-date').forEach(function(el){
        el.addEventListener('click', function(){
            document.querySelectorAll('.pw-date').forEach(function(e){ e.classList.remove('sel'); e.setAttribute('aria-checked','false'); });
            el.classList.add('sel'); el.setAttribute('aria-checked','true');
            selDate = el.dataset.date;
            selTime = '';
            loadTimes();
            updateCTA();
        });
        el.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' '){e.preventDefault();el.click();}});
    });

    /* Load time slots via AJAX */
    function loadTimes(){
        if(!selDate){timeGrid.style.display='none';return;}
        timeGrid.style.display='grid';
        timeGrid.innerHTML='<div class="pw-times-loading" style="grid-column:1/-1"><div style="display:inline-block;width:18px;height:18px;border:2px solid #e5e5e5;border-top-color:#FCB900;border-radius:50%;animation:pwspin .6s linear infinite"></div><style>@keyframes pwspin{to{transform:rotate(360deg)}}</style> Loading times...</div>';
        fetch(ajaxUrl+'?action=ptp_get_real_available_slots&trainer_id='+trainerId+'&date='+selDate)
        .then(function(r){return r.json();})
        .then(function(res){
            if(!res.success||!res.data.slots||!res.data.slots.length){
                timeGrid.innerHTML='<div style="grid-column:1/-1;color:#999;font-size:14px;text-align:center;padding:12px">No available times for this date</div>';
                return;
            }
            var html='';
            res.data.slots.forEach(function(s){
                html+='<div class="pw-time" role="radio" aria-checked="false" tabindex="0" data-time="'+s.time+'">'+s.display+'</div>';
            });
            timeGrid.innerHTML=html;
            timeGrid.querySelectorAll('.pw-time').forEach(function(el){
                el.addEventListener('click', function(){
                    timeGrid.querySelectorAll('.pw-time').forEach(function(e){e.classList.remove('sel');e.setAttribute('aria-checked','false');});
                    el.classList.add('sel'); el.setAttribute('aria-checked','true');
                    selTime=el.dataset.time;
                    updateCTA();
                });
                el.addEventListener('keydown', function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();el.click();}});
            });
        })
        .catch(function(){
            timeGrid.innerHTML='<div style="grid-column:1/-1;color:#c00;font-size:14px;text-align:center;padding:12px">Could not load times. Please try again.</div>';
        });
    }

    /* Packages */
    document.querySelectorAll('.pw-pkg').forEach(function(el){
        el.addEventListener('click', function(){
            document.querySelectorAll('.pw-pkg').forEach(function(e){e.classList.remove('sel');e.setAttribute('aria-checked','false');});
            el.classList.add('sel'); el.setAttribute('aria-checked','true');
            selPkg=el.dataset.pkg;
            selPrice=parseInt(el.dataset.price);
            updateCTA();
        });
        el.addEventListener('keydown', function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();el.click();}});
    });

    /* CTA state */
    function updateCTA(){
        if(!selLoc){ctaBtn.textContent='Select Location';ctaBtn.disabled=true;}
        else if(!selDate){ctaBtn.textContent='Select Date';ctaBtn.disabled=true;}
        else if(!selTime){ctaBtn.textContent='Select Time';ctaBtn.disabled=true;}
        else{ctaBtn.textContent='Book Now — $'+selPrice;ctaBtn.disabled=false;}
    }

    /* Submit */
    var isSubmitting=false;
    ctaBtn.addEventListener('click', function(){
        if(ctaBtn.disabled||isSubmitting)return;
        isSubmitting=true;
        var origText=ctaBtn.textContent;
        ctaBtn.textContent='Redirecting...';ctaBtn.style.opacity='.7';
        try{
            var data={trainer_id:trainerId,package:selPkg,date:selDate,time:selTime,location:selLoc,location_address:selLocAddr,location_lat:selLocLat,location_lng:selLocLng,group_size:1};
            var urlCode=new URLSearchParams(window.location.search).get('code');
            if(urlCode)data.free_code=urlCode.toUpperCase();
            try{sessionStorage.setItem('ptp_booking',JSON.stringify(data));}catch(e){}
            window.location.href=checkoutUrl+'?'+new URLSearchParams(data).toString();
        }catch(err){
            ctaBtn.textContent=origText;ctaBtn.style.opacity='1';isSubmitting=false;
            alert('Something went wrong. Please try again.');
        }
        setTimeout(function(){if(isSubmitting){ctaBtn.textContent=origText;ctaBtn.style.opacity='1';isSubmitting=false;}},5000);
    });
})();
</script>
<?php
