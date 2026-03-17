<?php
/**
 * Messaging v89 - Full messaging interface with conversation view
 */
defined('ABSPATH') || exit;
if (!is_user_logged_in()) { wp_redirect(home_url('/login/')); exit; }

// Load conversations from database
$conversations = array();
if (class_exists('PTP_Messaging_V71')) {
    $conversations = PTP_Messaging_V71::get_conversations_for_user(get_current_user_id());
}

// Check if user is trainer or parent
global $wpdb;
$user_id = get_current_user_id();
$trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d", $user_id));
$parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d", $user_id));
$is_trainer = !empty($trainer);
$is_parent = !empty($parent);

// Check for conversation param (open specific conversation)
$active_conversation_id = intval($_GET['conversation'] ?? 0);
$nonce = wp_create_nonce('ptp_nonce');

get_header();
?>
<style>
:root{--gold:#FCB900;--black:#0A0A0A;--gray:#F5F5F5;--gray-dark:#525252;--green:#22C55E;--radius:16px}
*{box-sizing:border-box}
/* v133.2: Hide scrollbar */
html,body{scrollbar-width:none;-ms-overflow-style:none}
html::-webkit-scrollbar,body::-webkit-scrollbar{display:none;width:0}
.ptp-msg{font-family:Inter,-apple-system,sans-serif;background:var(--gray);min-height:100vh;min-height:100dvh}
.ptp-msg h1,.ptp-msg h2,.ptp-msg h3{font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;margin:0}
.ptp-msg a{color:inherit;text-decoration:none}

/* Layout - Mobile first */
.ptp-msg-container{display:flex;flex-direction:column;height:calc(100vh - 60px);height:calc(100dvh - 60px)}
@media(min-width:768px){.ptp-msg-container{flex-direction:row;height:calc(100vh - 80px)}}

/* Sidebar / Conversation List */
.ptp-msg-sidebar{background:#fff;border-bottom:1px solid #e5e5e5;flex-shrink:0}
@media(min-width:768px){.ptp-msg-sidebar{width:320px;border-right:1px solid #e5e5e5;border-bottom:none;overflow-y:auto}}
.ptp-msg-sidebar-header{padding:20px;border-bottom:1px solid #e5e5e5}
.ptp-msg-title{font-size:24px}
.ptp-msg-list{overflow-y:auto}
@media(max-width:767px){.ptp-msg-list{max-height:35vh}}
.ptp-msg-item{display:flex;gap:12px;padding:14px 20px;border-bottom:1px solid #f0f0f0;cursor:pointer;transition:.15s}
.ptp-msg-item:hover,.ptp-msg-item.active{background:var(--gray)}
.ptp-msg-item.active{border-left:3px solid var(--gold)}
.ptp-msg-item.unread{background:#FFF9E6}
.ptp-msg-avatar{width:44px;height:44px;border-radius:50%;border:2px solid var(--gold);overflow:hidden;flex-shrink:0}
.ptp-msg-avatar img{width:100%;height:100%;object-fit:cover}
.ptp-msg-info{flex:1;min-width:0}
.ptp-msg-name{font-family:Oswald,sans-serif;font-size:13px;font-weight:600;text-transform:uppercase;display:flex;justify-content:space-between;align-items:center}
.ptp-msg-time{font-size:10px;color:var(--gray-dark);font-family:Inter;font-weight:400;text-transform:none}
.ptp-msg-preview{font-size:12px;color:var(--gray-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:3px}
.ptp-msg-unread-badge{width:8px;height:8px;background:var(--gold);border-radius:50%;flex-shrink:0}

/* Main Chat Area */
.ptp-msg-main{flex:1;display:flex;flex-direction:column;background:#fff;min-height:0}
.ptp-msg-header{padding:16px 20px;border-bottom:1px solid #e5e5e5;display:flex;align-items:center;gap:12px;flex-shrink:0}
.ptp-msg-header-back{display:none;padding:8px;margin:-8px;margin-right:4px}
@media(max-width:767px){.ptp-msg-header-back{display:block}}
.ptp-msg-header-avatar{width:40px;height:40px;border-radius:50%;border:2px solid var(--gold);overflow:hidden}
.ptp-msg-header-avatar img{width:100%;height:100%;object-fit:cover}
.ptp-msg-header-name{font-family:Oswald,sans-serif;font-size:15px;font-weight:600;text-transform:uppercase}
.ptp-msg-header-status{font-size:11px;color:var(--gray-dark)}

/* Messages */
.ptp-msg-messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:12px;min-height:0}
.ptp-message{max-width:80%;padding:12px 16px;border-radius:16px;font-size:14px;line-height:1.4}
.ptp-message.sent{background:var(--gold);color:var(--black);align-self:flex-end;border-bottom-right-radius:4px}
.ptp-message.received{background:var(--gray);align-self:flex-start;border-bottom-left-radius:4px}
.ptp-message-time{font-size:10px;color:var(--gray-dark);margin-top:4px;text-align:right}
.ptp-message.sent .ptp-message-time{color:rgba(0,0,0,.5)}

/* Input Area */
.ptp-msg-input{padding:16px 20px;border-top:1px solid #e5e5e5;flex-shrink:0;padding-bottom:calc(16px + env(safe-area-inset-bottom,0px))}
@media(min-width:768px){.ptp-msg-input{padding-bottom:16px}}
.ptp-msg-input-form{display:flex;gap:12px;align-items:center}
.ptp-msg-input-field{flex:1;padding:12px 16px;font-size:15px;border:2px solid #e5e5e5;border-radius:24px;outline:none;transition:.2s}
.ptp-msg-input-field:focus{border-color:var(--gold)}
.ptp-msg-send{width:44px;height:44px;background:var(--gold);border:none;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ptp-msg-send:hover{background:#E5A800}
.ptp-msg-send:disabled{opacity:.5;cursor:not-allowed}
.ptp-msg-send svg{width:20px;height:20px}

/* Empty States */
.ptp-msg-empty{text-align:center;padding:60px 24px;flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center}
.ptp-msg-empty-icon{width:72px;height:72px;background:#e5e5e5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:20px}
.ptp-msg-empty-icon svg{width:36px;height:36px;stroke:#999}
.ptp-msg-empty h2{font-size:20px;margin-bottom:8px}
.ptp-msg-empty p{color:var(--gray-dark);font-size:14px;margin-bottom:24px}
.ptp-msg-empty-btn{display:inline-block;padding:14px 28px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;border-radius:10px}

/* Mobile Nav */
.ptp-mobile-nav{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e5e5e5;padding:10px 20px;padding-bottom:calc(10px + env(safe-area-inset-bottom,0px));display:flex;justify-content:space-around;z-index:999}
@media(min-width:768px){.ptp-mobile-nav{display:none}}
.ptp-mobile-nav a{display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 12px;color:var(--gray-dark);font-size:10px;font-weight:600;text-transform:uppercase}
.ptp-mobile-nav a.active{color:var(--gold)}
.ptp-mobile-nav a svg{width:22px;height:22px}

/* Hide mobile nav when in conversation on mobile */
.ptp-msg.in-conversation .ptp-mobile-nav{display:none}
.ptp-msg.in-conversation .ptp-msg-sidebar{display:none}
@media(min-width:768px){
    .ptp-msg.in-conversation .ptp-msg-sidebar{display:block}
}

/* No conversation selected */
.ptp-msg-placeholder{flex:1;display:flex;align-items:center;justify-content:center;text-align:center;padding:40px}
.ptp-msg-placeholder-text{color:var(--gray-dark);font-size:15px}
</style>

<div class="ptp-msg <?php echo $active_conversation_id ? 'in-conversation' : ''; ?>" id="ptpMsg">
<div class="ptp-msg-container">
    <!-- Sidebar with conversation list -->
    <div class="ptp-msg-sidebar">
        <div class="ptp-msg-sidebar-header">
            <h1 class="ptp-msg-title">MESSAGES</h1>
        </div>
        
        <?php if(empty($conversations)): ?>
        <div class="ptp-msg-empty" style="padding:40px 20px">
            <div class="ptp-msg-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
            <h2>NO MESSAGES</h2>
            <p>Book a session to start chatting</p>
            <a href="<?php echo home_url('/find-trainers/'); ?>" class="ptp-msg-empty-btn">Find Trainers</a>
        </div>
        <?php else: ?>
        <div class="ptp-msg-list" id="conversationList">
            <?php foreach($conversations as $c): 
                $unread = isset($c->unread_count) ? intval($c->unread_count) : 0;
                $other_name = $c->other_name ?? 'Contact';
                $other_photo = $c->other_photo ?? '';
                $fallback_photo = 'https://ui-avatars.com/api/?name=' . urlencode(substr($other_name, 0, 1)) . '&size=100&background=FCB900&color=0A0A0A';
            ?>
            <div class="ptp-msg-item <?php echo $unread > 0 ? 'unread' : ''; ?> <?php echo $c->id == $active_conversation_id ? 'active' : ''; ?>" 
                 data-id="<?php echo esc_attr($c->id); ?>"
                 data-name="<?php echo esc_attr($other_name); ?>"
                 data-photo="<?php echo esc_attr($other_photo ?: $fallback_photo); ?>"
                 onclick="openConversation(<?php echo $c->id; ?>)">
                <div class="ptp-msg-avatar">
                    <img src="<?php echo esc_url($other_photo ?: $fallback_photo); ?>" alt="">
                </div>
                <div class="ptp-msg-info">
                    <div class="ptp-msg-name">
                        <?php echo esc_html($other_name); ?>
                        <span class="ptp-msg-time"><?php echo $c->last_message_at ? date('M j', strtotime($c->last_message_at)) : ''; ?></span>
                    </div>
                    <p class="ptp-msg-preview"><?php echo esc_html($c->last_message ?? 'Start a conversation'); ?></p>
                </div>
                <?php if($unread > 0): ?><div class="ptp-msg-unread-badge"></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Main chat area -->
    <div class="ptp-msg-main" id="chatMain">
        <?php if($active_conversation_id): ?>
        <!-- Active conversation -->
        <div class="ptp-msg-header" id="chatHeader">
            <a href="<?php echo home_url('/messages/'); ?>" class="ptp-msg-header-back">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </a>
            <div class="ptp-msg-header-avatar"><img src="" alt="" id="chatHeaderPhoto"></div>
            <div>
                <div class="ptp-msg-header-name" id="chatHeaderName">Loading...</div>
                <div class="ptp-msg-header-status">Active now</div>
            </div>
        </div>
        <div class="ptp-msg-messages" id="chatMessages">
            <div style="text-align:center;color:var(--gray-dark);padding:40px">Loading messages...</div>
        </div>
        <div class="ptp-msg-input">
            <form class="ptp-msg-input-form" id="sendForm" onsubmit="sendMessage(event)">
                <input type="text" class="ptp-msg-input-field" id="messageInput" placeholder="Type a message..." autocomplete="off">
                <button type="submit" class="ptp-msg-send" id="sendBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </form>
        </div>
        <?php else: ?>
        <!-- No conversation selected -->
        <div class="ptp-msg-placeholder">
            <div class="ptp-msg-placeholder-text">Select a conversation to start messaging</div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php if(!$active_conversation_id): ?>
<nav class="ptp-mobile-nav">
    <a href="<?php echo home_url($is_trainer ? '/trainer-dashboard/' : '/my-training/'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>Home</a>
    <a href="<?php echo home_url('/find-trainers/'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>Find</a>
    <a href="<?php echo home_url('/messages/'); ?>" class="active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Chat</a>
    <a href="<?php echo home_url('/account/'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Account</a>
</nav>
<?php endif; ?>

<script>
var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
var nonce = '<?php echo $nonce; ?>';
var currentUserId = <?php echo get_current_user_id(); ?>;
var isTrainer = <?php echo $is_trainer ? 'true' : 'false'; ?>;
var activeConversationId = <?php echo $active_conversation_id ?: 'null'; ?>;
var pollInterval = null;

// Open a conversation
function openConversation(id) {
    // On mobile, update URL and reload
    if (window.innerWidth < 768) {
        window.location.href = '<?php echo home_url('/messages/'); ?>?conversation=' + id;
        return;
    }
    
    // Desktop: Load inline
    activeConversationId = id;
    
    // Update sidebar active state
    document.querySelectorAll('.ptp-msg-item').forEach(function(item) {
        item.classList.remove('active', 'unread');
    });
    var activeItem = document.querySelector('.ptp-msg-item[data-id="' + id + '"]');
    if (activeItem) {
        activeItem.classList.add('active');
    }
    
    // Update header
    var name = activeItem ? activeItem.dataset.name : 'Chat';
    var photo = activeItem ? activeItem.dataset.photo : '';
    
    // Build chat UI if not exists
    var main = document.getElementById('chatMain');
    if (!document.getElementById('chatHeader')) {
        main.innerHTML = '<div class="ptp-msg-header" id="chatHeader"><div class="ptp-msg-header-avatar"><img src="' + photo + '" alt="" id="chatHeaderPhoto"></div><div><div class="ptp-msg-header-name" id="chatHeaderName">' + name + '</div><div class="ptp-msg-header-status">Active now</div></div></div><div class="ptp-msg-messages" id="chatMessages"><div style="text-align:center;color:var(--gray-dark);padding:40px">Loading...</div></div><div class="ptp-msg-input"><form class="ptp-msg-input-form" id="sendForm" onsubmit="sendMessage(event)"><input type="text" class="ptp-msg-input-field" id="messageInput" placeholder="Type a message..." autocomplete="off"><button type="submit" class="ptp-msg-send" id="sendBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button></form></div>';
    } else {
        document.getElementById('chatHeaderName').textContent = name;
        document.getElementById('chatHeaderPhoto').src = photo;
    }
    
    loadMessages(id);
    startPolling();
}

// Load messages for a conversation
function loadMessages(conversationId) {
    var formData = new FormData();
    formData.append('action', 'ptp_get_messages');
    formData.append('conversation_id', conversationId);
    formData.append('nonce', nonce);
    
    fetch(ajaxUrl, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var container = document.getElementById('chatMessages');
        if (!container) return;
        
        if (data.success && data.data.messages && data.data.messages.length > 0) {
            container.innerHTML = '';
            
            // Update header name from AJAX response
            if (data.data.other_name) {
                var nameEl = document.getElementById('chatHeaderName');
                if (nameEl && (nameEl.textContent === 'Loading...' || nameEl.textContent === 'Chat')) {
                    nameEl.textContent = data.data.other_name;
                }
            }
            
            data.data.messages.forEach(function(msg) {
                var isSent = msg.is_mine;
                var div = document.createElement('div');
                div.className = 'ptp-message ' + (isSent ? 'sent' : 'received');
                div.innerHTML = escapeHtml(msg.message) + '<div class="ptp-message-time">' + formatTime(msg.created_at) + '</div>';
                container.appendChild(div);
            });
            container.scrollTop = container.scrollHeight;
            
            // Mark as read
            markRead(conversationId);
        } else {
            container.innerHTML = '<div style="text-align:center;color:var(--gray-dark);padding:40px">No messages yet. Start the conversation!</div>';
        }
    })
    .catch(function(err) {
        console.error('Load messages error:', err);
    });
}

// Send a message
function sendMessage(e) {
    e.preventDefault();
    var input = document.getElementById('messageInput');
    var btn = document.getElementById('sendBtn');
    var message = input.value.trim();
    
    if (!message || !activeConversationId) return;
    
    btn.disabled = true;
    input.disabled = true;
    
    var formData = new FormData();
    formData.append('action', 'ptp_send_message');
    formData.append('conversation_id', activeConversationId);
    formData.append('message', message);
    formData.append('nonce', nonce);
    
    fetch(ajaxUrl, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            input.value = '';
            loadMessages(activeConversationId);
        } else {
            alert(data.data?.message || 'Error sending message');
        }
    })
    .catch(function(err) {
        console.error('Send error:', err);
        alert('Connection error. Please try again.');
    })
    .finally(function() {
        btn.disabled = false;
        input.disabled = false;
        input.focus();
    });
}

// Mark conversation as read
function markRead(conversationId) {
    var formData = new FormData();
    formData.append('action', 'ptp_mark_read');
    formData.append('conversation_id', conversationId);
    formData.append('nonce', nonce);
    fetch(ajaxUrl, { method: 'POST', body: formData });
}

// Poll for new messages
function startPolling() {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(function() {
        if (activeConversationId) {
            checkNewMessages();
        }
    }, 5000);
}

function checkNewMessages() {
    var formData = new FormData();
    formData.append('action', 'ptp_get_new_messages');
    formData.append('conversation_id', activeConversationId);
    formData.append('nonce', nonce);
    
    fetch(ajaxUrl, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.data.has_new) {
            loadMessages(activeConversationId);
        }
    });
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Format timestamp
function formatTime(timestamp) {
    var date = new Date(timestamp);
    var now = new Date();
    var diff = now - date;
    
    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return Math.floor(diff/60000) + 'm ago';
    if (diff < 86400000) return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    return date.toLocaleDateString([], {month: 'short', day: 'numeric'});
}

// Initialize
if (activeConversationId) {
    loadMessages(activeConversationId);
    startPolling();
    
    // Update header from sidebar data
    var activeItem = document.querySelector('.ptp-msg-item[data-id="' + activeConversationId + '"]');
    if (activeItem) {
        document.getElementById('chatHeaderName').textContent = activeItem.dataset.name;
        document.getElementById('chatHeaderPhoto').src = activeItem.dataset.photo;
    }
}

// Focus input when conversation opens
document.getElementById('messageInput')?.focus();
</script>
<?php get_footer(); ?>
