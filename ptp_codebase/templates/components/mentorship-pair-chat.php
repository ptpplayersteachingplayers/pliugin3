<?php
/**
 * Mentorship Pair Chat v1.0
 * 
 * Dedicated messaging thread per mentorship pair.
 * Used by both parent and trainer dashboards.
 * 
 * DB: ptp_mentorship_messages (created by PTP_Mentorship_Recurring migration)
 * 
 * Include with:
 *   $dashboard_type = 'parent' or 'trainer';
 *   include('templates/components/mentorship-pair-chat.php');
 */
defined('ABSPATH') || exit;

$_chat_ajax  = admin_url('admin-ajax.php');
$_chat_nonce = wp_create_nonce('ptp_ajax_nonce');
?>

<style>
/* ── Chat Thread ── */
.mc-thread{background:#fff;border:1px solid #EAEAE6;border-radius:14px;overflow:hidden;margin-bottom:16px}
.mc-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #F0F0EE;cursor:pointer;-webkit-tap-highlight-color:transparent}
.mc-head-title{font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#0A0A0A;display:flex;align-items:center;gap:8px}
.mc-head-badge{width:18px;height:18px;border-radius:50%;background:#EF4444;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
.mc-body{display:none;max-height:360px;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding:12px 16px}
.mc-body.open{display:block}
.mc-empty{text-align:center;padding:24px;color:#A3A3A3;font-size:12px;line-height:1.5}
.mc-msg{margin-bottom:10px;display:flex;gap:8px;align-items:flex-end}
.mc-msg.mine{flex-direction:row-reverse}
.mc-msg-avatar{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;flex-shrink:0}
.mc-msg-bubble{max-width:75%;padding:10px 14px;border-radius:14px;font-size:13px;line-height:1.5;word-break:break-word}
.mc-msg.theirs .mc-msg-bubble{background:#F5F5F3;color:#0A0A0A;border-bottom-left-radius:4px}
.mc-msg.mine .mc-msg-bubble{background:#0A0A0A;color:#FCB900;border-bottom-right-radius:4px}
.mc-msg.system .mc-msg-bubble{background:#FCB90015;color:#92400E;border-radius:8px;font-size:11px;max-width:100%;text-align:center;margin:0 auto}
.mc-msg-time{font-size:9px;color:#A3A3A3;margin-top:2px;padding:0 4px}
.mc-msg.mine .mc-msg-time{text-align:right}
.mc-input-row{display:flex;gap:8px;padding:12px 16px;border-top:1px solid #F0F0EE;background:#FAFAF8}
.mc-input{flex:1;padding:10px 14px;border:2px solid #E5E5E3;border-radius:20px;font-size:14px;font-family:inherit;outline:none;resize:none;max-height:80px;line-height:1.4}
.mc-input:focus{border-color:#FCB900}
.mc-send{width:40px;height:40px;border-radius:50%;background:#FCB900;color:#0A0A0A;border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;-webkit-tap-highlight-color:transparent;transition:opacity .15s}
.mc-send:active{opacity:.7}
.mc-send:disabled{opacity:.3;cursor:not-allowed}
</style>

<!-- Chat Thread Component (rendered per pair) -->
<div class="mc-thread" id="mcThread" style="display:none">
    <div class="mc-head" onclick="mcToggle()">
        <div class="mc-head-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            Messages
            <span class="mc-head-badge" id="mcBadge" style="display:none">0</span>
        </div>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#A3A3A3" stroke-width="2" id="mcChevron" style="transition:transform .2s"><path d="M6 9l6 6 6-6"/></svg>
    </div>
    <div class="mc-body" id="mcBody">
        <div class="mc-empty" id="mcEmpty">Start the conversation. Messages stay between you and your mentor.</div>
        <div id="mcMessages"></div>
    </div>
    <div class="mc-input-row">
        <textarea class="mc-input" id="mcInput" rows="1" placeholder="Type a message..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();mcSend()}" oninput="mcAutoResize(this)"></textarea>
        <button class="mc-send" id="mcSendBtn" onclick="mcSend()" disabled>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
    </div>
</div>

<script>
(function(){
    var AJAX='<?php echo $_chat_ajax; ?>', NONCE='<?php echo $_chat_nonce; ?>';
    var _pairId=0, _role='<?php echo $dashboard_type; ?>', _isOpen=false, _pollTimer=null;
    var _userId=<?php echo get_current_user_id(); ?>;

    // Init chat for a pair
    window.mcInit=function(pairId){
        if(!pairId)return;
        _pairId=pairId;
        document.getElementById('mcThread').style.display='';
        mcLoadMessages();
        // Poll every 15s when chat is open
        clearInterval(_pollTimer);
        _pollTimer=setInterval(function(){if(_isOpen)mcLoadMessages(true)},15000);
    };

    window.mcToggle=function(){
        _isOpen=!_isOpen;
        document.getElementById('mcBody').classList.toggle('open',_isOpen);
        document.getElementById('mcChevron').style.transform=_isOpen?'rotate(180deg)':'';
        if(_isOpen){
            mcLoadMessages();
            mcMarkRead();
            var body=document.getElementById('mcBody');
            body.scrollTop=body.scrollHeight;
        }
    };

    function mcLoadMessages(silent){
        var fd=new FormData();
        fd.append('action','ptp_mentorship_get_messages');
        fd.append('nonce',NONCE);
        fd.append('pair_id',_pairId);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            if(!d.success)return;
            var msgs=d.data.messages||[];
            var unread=d.data.unread||0;
            var container=document.getElementById('mcMessages');
            var empty=document.getElementById('mcEmpty');
            var badge=document.getElementById('mcBadge');

            if(msgs.length===0){
                empty.style.display='';
                container.innerHTML='';
            } else {
                empty.style.display='none';
                var html='';
                msgs.forEach(function(m){
                    var isMine=(parseInt(m.sender_id)===_userId);
                    var isSystem=(m.sender_role==='system');
                    var cls=isSystem?'system':(isMine?'mine':'theirs');
                    var initial=m.sender_name?m.sender_name.charAt(0).toUpperCase():'?';
                    var avatarBg=isMine?'#FCB900':(_role==='trainer'?'#8B5CF6':'#22C55E');
                    var avatarColor=isMine?'#0A0A0A':'#fff';
                    
                    html+='<div class="mc-msg '+cls+'">';
                    if(!isSystem) html+='<div class="mc-msg-avatar" style="background:'+avatarBg+';color:'+avatarColor+'">'+initial+'</div>';
                    html+='<div>';
                    html+='<div class="mc-msg-bubble">'+escHtml(m.message)+'</div>';
                    html+='<div class="mc-msg-time">'+formatMsgTime(m.created_at)+'</div>';
                    html+='</div></div>';
                });
                container.innerHTML=html;
                if(!silent){
                    var body=document.getElementById('mcBody');
                    body.scrollTop=body.scrollHeight;
                }
            }

            if(unread>0){
                badge.textContent=unread;
                badge.style.display='';
            } else {
                badge.style.display='none';
            }
        });
    }

    window.mcSend=function(){
        var input=document.getElementById('mcInput');
        var msg=input.value.trim();
        if(!msg||!_pairId)return;
        
        var btn=document.getElementById('mcSendBtn');
        btn.disabled=true;
        input.value='';
        input.style.height='auto';

        var fd=new FormData();
        fd.append('action','ptp_mentorship_send_message');
        fd.append('nonce',NONCE);
        fd.append('pair_id',_pairId);
        fd.append('message',msg);
        fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(d){
            btn.disabled=false;
            if(d.success){
                mcLoadMessages();
            } else {
                input.value=msg; // restore on error
                if(typeof showToast==='function') showToast(d.data||'Failed to send','error');
            }
        }).catch(function(){btn.disabled=false;input.value=msg});

        // Enable send button based on input
        input.addEventListener('input',function(){
            btn.disabled=!input.value.trim();
        });
    };

    // Enable send on input
    document.getElementById('mcInput').addEventListener('input',function(){
        document.getElementById('mcSendBtn').disabled=!this.value.trim();
    });

    function mcMarkRead(){
        var fd=new FormData();
        fd.append('action','ptp_mentorship_mark_read');
        fd.append('nonce',NONCE);
        fd.append('pair_id',_pairId);
        fetch(AJAX,{method:'POST',body:fd});
    }

    window.mcAutoResize=function(el){
        el.style.height='auto';
        el.style.height=Math.min(80,el.scrollHeight)+'px';
    };

    function formatMsgTime(dt){
        var d=new Date(dt);
        var now=new Date();
        var diff=(now-d)/1000;
        if(diff<60)return'Just now';
        if(diff<3600)return Math.floor(diff/60)+'m ago';
        if(diff<86400)return Math.floor(diff/3600)+'h ago';
        if(diff<604800)return d.toLocaleDateString('en-US',{weekday:'short'})+' '+d.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
        return d.toLocaleDateString('en-US',{month:'short',day:'numeric'});
    }
    function escHtml(s){if(!s)return'';var d=document.createElement('div');d.textContent=s;return d.innerHTML}
})();
</script>
