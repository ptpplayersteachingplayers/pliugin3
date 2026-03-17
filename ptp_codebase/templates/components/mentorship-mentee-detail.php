<?php
/**
 * Mentee Detail View v1.0
 * 
 * Full-screen bottom sheet for trainer dashboard.
 * Shows complete mentee profile: sessions timeline, goals, videos,
 * parent contact, recurring schedule, training plan, notes.
 * 
 * Loaded via AJAX when trainer clicks a mentee card.
 * Include in trainer dashboard template.
 * 
 * Expected: $dashboard_type = 'trainer'
 */
defined('ABSPATH') || exit;
if (($dashboard_type ?? '') !== 'trainer') return;

$_ajax  = admin_url('admin-ajax.php');
$_nonce = wp_create_nonce('ptp_ajax_nonce');
$_day_names = array('Sun','Mon','Tue','Wed','Thu','Fri','Sat');
$_day_full  = array('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday');
?>

<style>
/* ── Mentee Detail Sheet ── */
.md-overlay{position:fixed;inset:0;background:rgba(0,0,0,0);z-index:9997;pointer-events:none;transition:background .3s}
.md-overlay.open{background:rgba(0,0,0,.6);pointer-events:auto}
.md-detail{position:fixed;inset:0;z-index:9998;background:#F8F8F6;transform:translateY(100%);transition:transform .4s cubic-bezier(.32,.72,0,1);overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
.md-detail.open{transform:translateY(0)}
@media(min-width:640px){.md-detail{left:50%;width:100%;max-width:520px;transform:translateX(-50%) translateY(100%);border-radius:20px 20px 0 0;top:40px}.md-detail.open{transform:translateX(-50%) translateY(0)}}
.md-detail-head{position:sticky;top:0;background:#0A0A0A;color:#fff;padding:16px 20px;z-index:2;display:flex;align-items:center;gap:12px}
.md-detail-close{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;border:none;cursor:pointer;flex-shrink:0;-webkit-tap-highlight-color:transparent;color:#fff}
.md-detail-body{padding:16px 16px 80px}
.md-detail-card{background:#fff;border:1px solid #EAEAE6;border-radius:12px;padding:14px 16px;margin-bottom:12px}
.md-detail-label{font-family:'Oswald',sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#737373;margin-bottom:10px}
.md-detail-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F0F0EE;font-size:13px}
.md-detail-row:last-child{border-bottom:none}
.md-detail-row-label{color:#737373;font-size:12px}
.md-detail-row-val{font-weight:600;color:#0A0A0A}

/* Timeline */
.md-tl{position:relative;padding-left:32px}
.md-tl::before{content:'';position:absolute;left:11px;top:10px;bottom:10px;width:2px;background:#EAEAE6}
.md-tl-item{position:relative;padding-bottom:16px}
.md-tl-item:last-child{padding-bottom:0}
.md-tl-dot{position:absolute;left:-32px;top:4px;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700}
.md-tl-dot.completed{background:#22C55E;color:#fff}
.md-tl-dot.scheduled{background:#FCB900;color:#0A0A0A}
.md-tl-dot.missed{background:#EF4444;color:#fff}
.md-tl-date{font-size:10px;color:#A3A3A3;margin-bottom:2px}
.md-tl-title{font-size:13px;font-weight:600;color:#0A0A0A}
.md-tl-meta{font-size:11px;color:#737373;line-height:1.5;margin-top:2px}

/* Recurring Schedule */
.md-recur-days{display:flex;gap:4px}
.md-recur-day{width:36px;height:36px;border-radius:50%;border:2px solid #E5E5E3;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;color:#A3A3A3;cursor:pointer;transition:all .15s;-webkit-tap-highlight-color:transparent}
.md-recur-day.active{background:#FCB900;border-color:#FCB900;color:#0A0A0A;transform:scale(1.1)}
.md-recur-day:active{transform:scale(.95)}
</style>

<!-- Mentee Detail Sheet -->
<div class="md-overlay" id="mdOverlay" onclick="mdClose()"></div>
<div class="md-detail" id="mdDetail">
    <div class="md-detail-head">
        <button class="md-detail-close" onclick="mdClose()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <div style="flex:1;min-width:0">
            <div style="font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" id="mdPlayerName">Loading...</div>
            <div style="font-size:11px;color:#A3A3A3" id="mdSubtitle"></div>
        </div>
        <div id="mdPkgBadge" style="flex-shrink:0"></div>
    </div>
    <div class="md-detail-body" id="mdBody">
        <div style="text-align:center;padding:40px;color:#A3A3A3;font-size:13px">Loading mentee data...</div>
    </div>
</div>

<script>
(function(){
    var AJAX='<?php echo $_ajax; ?>', NONCE='<?php echo $_nonce; ?>';
    var DAY_NAMES=<?php echo json_encode($_day_names); ?>;
    var DAY_FULL=<?php echo json_encode($_day_full); ?>;

    window.mdOpen=function(pairId){
        // Rescue chat thread before overwriting body
        var _ct=document.getElementById('mcThread');
        if(_ct&&_ct.parentElement!==document.body){document.body.appendChild(_ct);_ct.style.display='none'}

        document.getElementById('mdOverlay').classList.add('open');
        document.getElementById('mdDetail').classList.add('open');
        document.body.style.overflow='hidden';
        document.getElementById('mdBody').innerHTML='<div style="text-align:center;padding:40px;color:#A3A3A3;font-size:13px">Loading...</div>';
        document.getElementById('mdPlayerName').textContent='Loading...';
        document.getElementById('mdSubtitle').textContent='';
        document.getElementById('mdPkgBadge').innerHTML='';

        // Fetch full data
        var fd=new FormData();
        fd.append('action','ptp_mentorship_mentee_detail');
        fd.append('nonce',NONCE);
        fd.append('pair_id',pairId);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(!d.success){document.getElementById('mdBody').innerHTML='<p style="color:#EF4444;text-align:center;padding:40px">'+d.data+'</p>';return}
            renderMenteeDetail(d.data);
        }).catch(function(){document.getElementById('mdBody').innerHTML='<p style="color:#EF4444;text-align:center;padding:40px">Failed to load</p>'});
    };
    window.mdClose=function(){
        // Rescue chat thread before detail body gets overwritten
        var chatThread=document.getElementById('mcThread');
        if(chatThread){
            document.body.appendChild(chatThread);
            chatThread.style.display='none';
        }
        document.getElementById('mdDetail').classList.remove('open');
        setTimeout(function(){document.getElementById('mdOverlay').classList.remove('open');document.body.style.overflow=''},400);
    };

    function renderMenteeDetail(data){
        var p=data.pair, pl=data.player, pa=data.parent, sessions=data.sessions, goals=data.goals, videos=data.videos;
        var sessLeft=Math.max(0,p.sessions_total-p.sessions_completed);
        var pct=Math.min(100,Math.round((p.sessions_completed/Math.max(1,p.sessions_total))*100));
        var pkgColors={single:'#3B82F6',kickstart:'#525252',development:'#FCB900',elite:'#0A0A0A'};
        var pkgColor=pkgColors[p.package_type]||'#737373';

        document.getElementById('mdPlayerName').textContent=pl.name||'Player';
        document.getElementById('mdSubtitle').textContent=(pa.name||'Parent')+' \u00b7 '+(pl.age?'Age '+pl.age:'')+(pl.position?' \u00b7 '+pl.position:'');
        document.getElementById('mdPkgBadge').innerHTML='<span style="font-family:Oswald,sans-serif;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:3px 10px;border-radius:10px;background:'+(p.package_type==='elite'?'#0A0A0A':'rgba(255,255,255,.1)')+';color:'+(p.package_type==='elite'?'#FCB900':'#fff')+'">'+(p.package_type||'dev').toUpperCase()+'</span>';

        var html='';

        // Progress
        html+='<div class="md-detail-card">';
        html+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><span style="font-size:12px;font-weight:600">Package Progress</span><span style="font-family:Oswald,sans-serif;font-size:13px;font-weight:700;color:'+(sessLeft<=2?'#EF4444':'#0A0A0A')+'">'+p.sessions_completed+' / '+p.sessions_total+'</span></div>';
        html+='<div style="height:6px;background:#EAEAE6;border-radius:3px;overflow:hidden"><div style="height:100%;width:'+pct+'%;background:'+(sessLeft<=2?'#EF4444':'#22C55E')+';border-radius:3px"></div></div>';
        html+='<div style="display:flex;justify-content:space-between;font-size:10px;color:#A3A3A3;margin-top:6px"><span>'+sessLeft+' sessions left</span><span>Since '+(p.started_at?new Date(p.started_at).toLocaleDateString('en-US',{month:'short',day:'numeric'}):'—')+'</span></div>';
        html+='</div>';

        // Parent Contact
        html+='<div class="md-detail-card">';
        html+='<div class="md-detail-label">Parent Contact</div>';
        html+='<div class="md-detail-row"><span class="md-detail-row-label">Name</span><span class="md-detail-row-val">'+(pa.name||'—')+'</span></div>';
        html+='<div class="md-detail-row"><span class="md-detail-row-label">Email</span><span class="md-detail-row-val">'+(pa.email?'<a href="mailto:'+pa.email+'" style="color:#FCB900;text-decoration:none">'+pa.email+'</a>':'—')+'</span></div>';
        html+='<div class="md-detail-row"><span class="md-detail-row-label">Phone</span><span class="md-detail-row-val">'+(pa.phone?'<a href="tel:'+pa.phone+'" style="color:#FCB900;text-decoration:none">'+pa.phone+'</a>':'—')+'</span></div>';
        html+='</div>';

        // Recurring Schedule
        html+='<div class="md-detail-card">';
        html+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><span class="md-detail-label" style="margin:0">Recurring Schedule</span>';
        if(p.recurring_enabled){
            html+='<button onclick="mdPauseRecurring('+p.id+')" style="font-size:10px;color:#EF4444;background:none;border:1px solid #EF444440;padding:3px 8px;border-radius:6px;cursor:pointer;font-weight:600">Pause</button>';
        }
        html+='</div>';
        html+='<div class="md-recur-days" id="mdRecurDays">';
        for(var d=0;d<7;d++){
            html+='<div class="md-recur-day'+(p.recurring_enabled&&parseInt(p.recurring_day)===d?' active':'')+'" data-day="'+d+'" onclick="mdPickRecurDay(this,'+d+','+p.id+')">'+DAY_NAMES[d]+'</div>';
        }
        html+='</div>';
        html+='<div style="display:flex;gap:8px;margin-top:10px">';
        html+='<input type="time" id="mdRecurTime" value="'+(p.recurring_time||'16:00')+'" style="flex:1;padding:8px;border:2px solid #E5E5E3;border-radius:8px;font-size:14px;font-family:inherit">';
        html+='<select id="mdRecurDuration" style="padding:8px;border:2px solid #E5E5E3;border-radius:8px;font-size:14px;font-family:inherit">';
        [30,45,60].forEach(function(m){html+='<option value="'+m+'"'+(parseInt(p.recurring_duration||45)===m?' selected':'')+'>'+m+' min</option>'});
        html+='</select>';
        html+='</div>';
        if(p.recurring_enabled){
            html+='<div style="margin-top:8px;font-size:11px;color:#22C55E;font-weight:600">Active: Every '+DAY_FULL[p.recurring_day]+' at '+formatTime(p.recurring_time)+'</div>';
        } else {
            html+='<div style="margin-top:8px;font-size:11px;color:#A3A3A3">Pick a day to auto-schedule weekly sessions.</div>';
        }
        html+='</div>';

        // Session Timeline
        html+='<div class="md-detail-card">';
        html+='<div class="md-detail-label">Session Timeline</div>';
        if(sessions.length){
            html+='<div class="md-tl">';
            sessions.forEach(function(s,i){
                var status=s.status;
                var dotClass=status==='completed'?'completed':(status==='no_show'?'missed':'scheduled');
                var dt=new Date(s.scheduled_at);
                html+='<div class="md-tl-item"><div class="md-tl-dot '+dotClass+'">'+(status==='completed'?'&#10003;':(status==='no_show'?'&#10007;':(s.session_number||i+1)))+'</div>';
                html+='<div class="md-tl-date">'+dt.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'})+' \u00b7 '+dt.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'})+' \u00b7 '+s.duration_minutes+'min</div>';
                html+='<div class="md-tl-title">'+(s.title||'1:1 Session')+'</div>';
                if(s.action_item) html+='<div class="md-tl-meta" style="background:#FFFBEB;border-left:3px solid #F59E0B;padding:4px 8px;border-radius:0 4px 4px 0;margin-top:4px">Mission: '+escHtml(s.action_item)+'</div>';
                if(s.trainer_notes) html+='<div class="md-tl-meta" style="font-style:italic">'+escHtml(s.trainer_notes.substring(0,120))+(s.trainer_notes.length>120?'...':'')+'</div>';
                if(status==='scheduled'){
                    html+='<div style="margin-top:6px;display:flex;gap:4px">';
                    html+='<button class="mt-pill mt-pill-green" onclick="mdClose();mtOpenSessionComplete('+s.id+','+p.id+',\''+escJs(pl.name||'Player')+'\','+(parseInt(p.sessions_completed)+1)+','+s.duration_minutes+')">Complete</button>';
                    if(s.meeting_url) html+='<a href="'+s.host_url+'" target="_blank" class="mt-pill mt-pill-gold" style="text-decoration:none">Join</a>';
                    html+='</div>';
                }
                html+='</div>';
            });
            html+='</div>';
        } else {
            html+='<div style="text-align:center;padding:16px;color:#A3A3A3;font-size:12px">No sessions yet. Set a recurring schedule above or schedule one manually.</div>';
        }
        html+='</div>';

        // Goals
        html+='<div class="md-detail-card">';
        html+='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><span class="md-detail-label" style="margin:0">Goals</span><button onclick="mdClose();openMentorshipGoalModal('+p.id+')" style="font-size:10px;background:#FCB900;color:#0A0A0A;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;font-weight:700;font-family:Oswald,sans-serif;text-transform:uppercase">+ Add</button></div>';
        if(goals.length){
            goals.forEach(function(g){
                var typeColors={game:'#FCB900',mental:'#60A5FA',identity:'#EF4444',life:'#22C55E'};
                var c=typeColors[g.goal_type]||'#FCB900';
                html+='<div style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-bottom:1px solid #F0F0EE">';
                html+='<div style="width:8px;height:8px;border-radius:50%;background:'+c+';flex-shrink:0;margin-top:5px"></div>';
                html+='<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:600;color:'+(g.status==='completed'?'#A3A3A3':'#0A0A0A')+';'+(g.status==='completed'?'text-decoration:line-through':'')+'">'+escHtml(g.title)+'</div>';
                if(g.target_date) html+='<div style="font-size:10px;color:#A3A3A3;margin-top:1px">Target: '+new Date(g.target_date).toLocaleDateString('en-US',{month:'short',day:'numeric'})+'</div>';
                html+='</div></div>';
            });
        } else {
            html+='<div style="text-align:center;padding:12px;color:#A3A3A3;font-size:12px">No goals yet.</div>';
        }
        html+='</div>';

        // Videos
        if(videos.length){
            html+='<div class="md-detail-card">';
            html+='<div class="md-detail-label">Video Reviews</div>';
            videos.forEach(function(v){
                html+='<div style="padding:8px 0;border-bottom:1px solid #F0F0EE">';
                html+='<div style="display:flex;justify-content:space-between;align-items:center">';
                html+='<span style="font-size:12px;font-weight:600;color:#0A0A0A">'+(v.player_note?escHtml(v.player_note.substring(0,50)):'Video')+'</span>';
                html+='<span style="font-size:10px;font-weight:600;color:'+(v.status==='reviewed'?'#22C55E':'#F59E0B')+'">'+v.status.charAt(0).toUpperCase()+v.status.slice(1)+'</span>';
                html+='</div>';
                html+='<div style="font-size:10px;color:#A3A3A3;margin-top:2px">'+new Date(v.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric'})+'</div>';
                if(v.status==='pending') html+='<button class="mt-pill mt-pill-gold" style="margin-top:6px" onclick="mdClose();openVideoReview('+v.id+',\''+escJs(v.video_url)+'\')">Review</button>';
                html+='</div>';
            });
            html+='</div>';
        }

        // Notes (private trainer notes)
        html+='<div class="md-detail-card">';
        html+='<div class="md-detail-label">Private Notes</div>';
        html+='<textarea id="mdPrivateNotes" style="width:100%;min-height:80px;padding:10px;border:2px solid #E5E5E3;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;line-height:1.5" placeholder="Your private notes about this mentee — not shared with parent...">'+(escHtml(p.notes||''))+'</textarea>';
        html+='<button onclick="mdSaveNotes('+p.id+')" style="margin-top:8px;padding:8px 16px;background:#0A0A0A;color:#FCB900;border:none;border-radius:8px;font-family:Oswald,sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;cursor:pointer">Save Notes</button>';
        html+='</div>';

        // Chat container — mcThread gets moved here
        html+='<div id="mdChatAnchor"></div>';

        // Actions
        html+='<div style="display:flex;gap:8px;flex-wrap:wrap">';
        if(pa.email) html+='<a href="mailto:'+pa.email+'" class="mt-pill mt-pill-ghost" style="text-decoration:none;flex:1;justify-content:center">Email Parent</a>';
        if(pa.phone) html+='<a href="sms:'+pa.phone+'" class="mt-pill mt-pill-ghost" style="text-decoration:none;flex:1;justify-content:center">Text Parent</a>';
        html+='<button class="mt-pill mt-pill-ghost" onclick="mdClose();switchTab(\'mentorship\')" style="flex:1;justify-content:center">Back</button>';
        html+='</div>';

        document.getElementById('mdBody').innerHTML=html;

        // Move chat thread into detail view and init for this pair
        var chatThread=document.getElementById('mcThread');
        var chatAnchor=document.getElementById('mdChatAnchor');
        if(chatThread && chatAnchor){
            chatAnchor.appendChild(chatThread);
            chatThread.style.display='';
            if(typeof mcInit==='function') mcInit(p.id);
        }
    }

    // Recurring day picker
    var _mdSelectedDay=-1;
    window.mdPickRecurDay=function(el,day,pairId){
        document.querySelectorAll('#mdRecurDays .md-recur-day').forEach(function(d){d.classList.remove('active')});
        el.classList.add('active');
        _mdSelectedDay=day;
        // Auto-save
        var time=document.getElementById('mdRecurTime').value;
        var dur=document.getElementById('mdRecurDuration').value;
        var fd=new FormData();
        fd.append('action','ptp_mentorship_set_recurring');
        fd.append('nonce',NONCE);
        fd.append('pair_id',pairId);
        fd.append('recurring_day',day);
        fd.append('recurring_time',time);
        fd.append('recurring_duration',dur);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(d.success) showToast(d.data.message,'success');
            else showToast(d.data||'Error','error');
        });
    };

    window.mdPauseRecurring=function(pairId){
        var fd=new FormData();
        fd.append('action','ptp_mentorship_pause_recurring');
        fd.append('nonce',NONCE);
        fd.append('pair_id',pairId);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(d.success){showToast(d.data.message,'success');setTimeout(function(){mdOpen(pairId)},500)}
            else showToast(d.data||'Error','error');
        });
    };

    window.mdSaveNotes=function(pairId){
        var notes=document.getElementById('mdPrivateNotes').value;
        var fd=new FormData();
        fd.append('action','ptp_mentorship_save_notes');
        fd.append('nonce',NONCE);
        fd.append('pair_id',pairId);
        fd.append('notes',notes);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(d.success) showToast('Notes saved','success');
            else showToast(d.data||'Error','error');
        });
    };

    function formatTime(t){if(!t)return'';var parts=t.split(':');var h=parseInt(parts[0]);var m=parts[1];var ampm=h>=12?'PM':'AM';h=h%12||12;return h+':'+m+' '+ampm}
    function escHtml(s){if(!s)return'';var d=document.createElement('div');d.textContent=s;return d.innerHTML}
    function escJs(s){return(s||'').replace(/'/g,"\\'")}
})();
</script>
