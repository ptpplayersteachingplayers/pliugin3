/**
 * PTP Messaging System - v71
 * Standalone messaging JavaScript for /messages/ page
 * Real-time chat with mobile optimization
 */

(function() {
    'use strict';
    
    // ===========================================
    // CONFIGURATION
    // ===========================================
    
    const config = {
        ajax: window.ptpMsg?.ajax || '/wp-admin/admin-ajax.php',
        nonce: window.ptpMsg?.nonce || '',
        userId: window.ptpMsg?.userId || 0,
        pollInterval: window.ptpMsg?.pollInterval || 3000
    };
    
    // ===========================================
    // STATE
    // ===========================================
    
    const state = {
        currentConversation: null,
        lastMessageId: 0,
        pollTimer: null,
        isMobile: window.innerWidth < 768,
        isPolling: false
    };
    
    // ===========================================
    // DOM ELEMENTS
    // ===========================================
    
    let dom = {};
    
    function cacheDom() {
        dom = {
            container: document.querySelector('.ptp-messaging-container'),
            conversationsPanel: document.querySelector('.ptp-conversations-panel'),
            conversationsList: document.querySelector('.ptp-conversations-list'),
            chatPanel: document.querySelector('.ptp-chat-panel'),
            chatPlaceholder: document.querySelector('.ptp-chat-placeholder'),
            chatActive: document.querySelector('.ptp-chat-active'),
            messagesArea: document.querySelector('.ptp-messages-area'),
            messagesList: document.querySelector('.ptp-messages-list'),
            messageForm: document.querySelector('.ptp-message-form'),
            messageInput: document.querySelector('.ptp-message-input'),
            sendBtn: document.querySelector('.ptp-send-btn'),
            backBtn: document.querySelector('.ptp-back-btn'),
            chatName: document.querySelector('.ptp-chat-name'),
            chatAvatar: document.querySelector('.ptp-chat-avatar'),
            headerBadge: document.querySelector('.ptp-conversations-header .ptp-unread-badge')
        };
    }
    
    // ===========================================
    // INITIALIZATION
    // ===========================================
    
    function init() {
        cacheDom();
        
        if (!dom.container) {
            console.log('PTP Messaging: Container not found');
            return;
        }
        
        bindEvents();
        checkUrlParams();
        startUnreadPolling();
        
        // Handle resize
        window.addEventListener('resize', handleResize);
        handleResize();
        
        console.log('PTP Messaging initialized');
    }
    
    function bindEvents() {
        // Conversation clicks
        if (dom.conversationsList) {
            dom.conversationsList.addEventListener('click', handleConversationClick);
        }
        
        // Back button (mobile)
        if (dom.backBtn) {
            dom.backBtn.addEventListener('click', closeChat);
        }
        
        // Message form
        if (dom.messageForm) {
            dom.messageForm.addEventListener('submit', handleSendMessage);
        }
        
        // Message input
        if (dom.messageInput) {
            dom.messageInput.addEventListener('keydown', handleInputKeydown);
            dom.messageInput.addEventListener('input', autoResize);
        }
        
        // Browser navigation
        window.addEventListener('popstate', handlePopState);
    }
    
    function handleResize() {
        state.isMobile = window.innerWidth < 768;
    }
    
    function checkUrlParams() {
        const params = new URLSearchParams(window.location.search);
        const convId = params.get('conversation');
        
        if (convId) {
            const item = dom.conversationsList?.querySelector(`[data-conversation-id="${convId}"]`);
            if (item) {
                openConversation(convId, item);
            } else {
                // Conversation not in list, try to load it
                loadConversationById(convId);
            }
        }
    }
    
    function handlePopState() {
        const params = new URLSearchParams(window.location.search);
        const convId = params.get('conversation');
        
        if (convId && convId !== state.currentConversation) {
            const item = dom.conversationsList?.querySelector(`[data-conversation-id="${convId}"]`);
            if (item) {
                openConversation(convId, item, false);
            }
        } else if (!convId && state.currentConversation) {
            closeChat(null, false);
        }
    }
    
    // ===========================================
    // CONVERSATIONS
    // ===========================================
    
    function handleConversationClick(e) {
        const item = e.target.closest('.ptp-conversation-item');
        if (!item) return;
        
        const convId = item.dataset.conversationId;
        openConversation(convId, item);
    }
    
    async function openConversation(conversationId, item, updateHistory = true) {
        state.currentConversation = conversationId;
        state.lastMessageId = 0;
        
        // Update active states
        dom.conversationsList?.querySelectorAll('.ptp-conversation-item').forEach(el => {
            el.classList.remove('active');
        });
        
        if (item) {
            item.classList.add('active');
            item.classList.remove('has-unread');
            
            const unreadBadge = item.querySelector('.ptp-unread-count');
            if (unreadBadge) unreadBadge.remove();
        }
        
        // Show chat panel
        if (dom.chatPlaceholder) dom.chatPlaceholder.style.display = 'none';
        if (dom.chatActive) dom.chatActive.style.display = 'flex';
        
        if (state.isMobile && dom.chatPanel) {
            dom.chatPanel.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // Update URL
        if (updateHistory) {
            const url = new URL(window.location);
            url.searchParams.set('conversation', conversationId);
            history.pushState({ conversation: conversationId }, '', url);
        }
        
        // Load messages
        await loadMessages(conversationId);
        
        // Mark as read
        markConversationRead(conversationId);
        
        // Start polling for new messages
        startMessagePolling();
    }
    
    function closeChat(e, updateHistory = true) {
        if (e) e.preventDefault();
        
        if (dom.chatPanel) {
            dom.chatPanel.classList.remove('active');
        }
        document.body.style.overflow = '';
        
        dom.conversationsList?.querySelectorAll('.ptp-conversation-item').forEach(el => {
            el.classList.remove('active');
        });
        
        state.currentConversation = null;
        stopMessagePolling();
        
        // Update URL
        if (updateHistory) {
            const url = new URL(window.location);
            url.searchParams.delete('conversation');
            history.pushState({}, '', url);
        }
    }
    
    async function loadConversationById(conversationId) {
        // If conversation not in DOM, try to open it anyway
        openConversation(conversationId, null);
    }
    
    // ===========================================
    // MESSAGES
    // ===========================================
    
    async function loadMessages(conversationId) {
        if (!dom.messagesList) return;
        
        dom.messagesList.innerHTML = `
            <div class="ptp-loading-messages">
                <div class="ptp-spinner"></div>
            </div>
        `;
        
        try {
            const response = await ajax('ptp_get_messages', {
                conversation_id: conversationId
            });
            
            if (response.success) {
                renderMessages(response.data.messages);
                
                // Update chat header
                if (dom.chatName && response.data.other_name) {
                    dom.chatName.textContent = response.data.other_name;
                }
                
                // Update last message ID for polling
                if (response.data.messages.length > 0) {
                    state.lastMessageId = Math.max(...response.data.messages.map(m => parseInt(m.id)));
                }
                
                scrollToBottom();
            } else {
                dom.messagesList.innerHTML = '<p class="ptp-error-message">Failed to load messages</p>';
            }
        } catch (error) {
            console.error('Load messages error:', error);
            dom.messagesList.innerHTML = '<p class="ptp-error-message">Failed to load messages</p>';
        }
    }
    
    function renderMessages(messages) {
        if (!dom.messagesList) return;
        
        if (!messages || messages.length === 0) {
            dom.messagesList.innerHTML = `
                <p class="ptp-empty-messages">No messages yet. Start the conversation!</p>
            `;
            return;
        }
        
        dom.messagesList.innerHTML = messages.map(msg => createMessageHTML(msg)).join('');
    }
    
    function createMessageHTML(msg) {
        const isMine = msg.is_mine || msg.sender_id == config.userId;
        return `
            <div class="ptp-message ${isMine ? 'sent' : 'received'}" data-id="${msg.id}">
                <div class="ptp-message-bubble">${escapeHtml(msg.message)}</div>
                <div class="ptp-message-time">${formatTime(msg.created_at)}</div>
            </div>
        `;
    }
    
    function appendMessage(msg) {
        if (!dom.messagesList) return;
        
        // Remove empty state if present
        const emptyMsg = dom.messagesList.querySelector('.ptp-empty-messages');
        if (emptyMsg) emptyMsg.remove();
        
        // Check if message already exists
        if (dom.messagesList.querySelector(`[data-id="${msg.id}"]`)) {
            return;
        }
        
        const div = document.createElement('div');
        div.innerHTML = createMessageHTML(msg);
        dom.messagesList.appendChild(div.firstElementChild);
        
        scrollToBottom();
    }
    
    // ===========================================
    // SEND MESSAGE
    // ===========================================
    
    function handleInputKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendMessage(e);
        }
    }
    
    async function handleSendMessage(e) {
        e.preventDefault();
        
        if (!dom.messageInput || !state.currentConversation) return;
        
        const message = dom.messageInput.value.trim();
        if (!message) return;
        
        // Disable send button
        if (dom.sendBtn) dom.sendBtn.disabled = true;
        
        // Clear input
        dom.messageInput.value = '';
        autoResize.call(dom.messageInput);
        
        // Optimistic UI - add message immediately
        const tempId = 'temp-' + Date.now();
        appendMessage({
            id: tempId,
            message: message,
            is_mine: true,
            created_at: new Date().toISOString()
        });
        
        try {
            const response = await ajax('ptp_send_message', {
                conversation_id: state.currentConversation,
                message: message
            });
            
            if (response.success) {
                // Update temp message with real ID
                const tempMsg = dom.messagesList?.querySelector(`[data-id="${tempId}"]`);
                if (tempMsg) {
                    tempMsg.dataset.id = response.data.message_id;
                }
                state.lastMessageId = Math.max(state.lastMessageId, parseInt(response.data.message_id));
            } else {
                showError('Failed to send message');
            }
        } catch (error) {
            console.error('Send message error:', error);
            showError('Failed to send message');
        }
        
        // Re-enable send button
        if (dom.sendBtn) dom.sendBtn.disabled = false;
        dom.messageInput?.focus();
    }
    
    // ===========================================
    // POLLING
    // ===========================================
    
    function startMessagePolling() {
        stopMessagePolling();
        state.pollTimer = setInterval(pollNewMessages, config.pollInterval);
    }
    
    function stopMessagePolling() {
        if (state.pollTimer) {
            clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }
    
    async function pollNewMessages() {
        if (!state.currentConversation || state.isPolling) return;
        
        state.isPolling = true;
        
        try {
            const response = await ajax('ptp_get_new_messages', {
                conversation_id: state.currentConversation,
                last_id: state.lastMessageId
            });
            
            if (response.success && response.data.messages && response.data.messages.length > 0) {
                response.data.messages.forEach(msg => {
                    // Only add if not from current user (their messages are added optimistically)
                    if (!msg.is_mine) {
                        appendMessage(msg);
                    }
                    state.lastMessageId = Math.max(state.lastMessageId, parseInt(msg.id));
                });
            }
        } catch (error) {
            // Silent fail for polling
        }
        
        state.isPolling = false;
    }
    
    function startUnreadPolling() {
        // Poll for unread count every 30 seconds
        setInterval(updateUnreadCount, 30000);
    }
    
    async function updateUnreadCount() {
        try {
            const response = await ajax('ptp_get_unread_count');
            
            if (response.success && dom.headerBadge) {
                const count = response.data.count || 0;
                if (count > 0) {
                    dom.headerBadge.textContent = count;
                    dom.headerBadge.style.display = '';
                } else {
                    dom.headerBadge.style.display = 'none';
                }
            }
        } catch (error) {
            // Silent fail
        }
    }
    
    async function markConversationRead(conversationId) {
        try {
            await ajax('ptp_mark_read', { conversation_id: conversationId });
        } catch (error) {
            // Silent fail
        }
    }
    
    // ===========================================
    // UTILITIES
    // ===========================================
    
    async function ajax(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', config.nonce);
        
        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value);
        });
        
        const response = await fetch(config.ajax, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        
        return response.json();
    }
    
    function scrollToBottom() {
        if (dom.messagesArea) {
            requestAnimationFrame(() => {
                dom.messagesArea.scrollTop = dom.messagesArea.scrollHeight;
            });
        }
    }
    
    function autoResize() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatTime(dateStr) {
        if (!dateStr) return '';
        
        const date = new Date(dateStr);
        const now = new Date();
        const diff = now - date;
        
        // Less than 1 minute
        if (diff < 60000) return 'Just now';
        
        // Less than 1 hour
        if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
        
        // Less than 24 hours
        if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago';
        
        // Less than 7 days
        if (diff < 604800000) return Math.floor(diff / 86400000) + 'd ago';
        
        // Older
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric' 
        });
    }
    
    function showError(message) {
        // Simple error toast
        const toast = document.createElement('div');
        toast.className = 'ptp-toast ptp-toast-error';
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            background: #F44336;
            color: white;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            z-index: 500;
            opacity: 0;
            transition: opacity 0.3s;
        `;
        
        document.body.appendChild(toast);
        
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
        });
        
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    // ===========================================
    // TRAINER PROFILE MESSAGING (Public)
    // ===========================================
    
    // Handle contact form on trainer profiles
    window.ptpSendTrainerMessage = async function(trainerId, formData) {
        try {
            const action = config.userId ? 'ptp_send_public_message' : 'ptp_send_public_message_guest';
            
            const response = await ajax(action, {
                trainer_id: trainerId,
                ...formData
            });
            
            if (response.success) {
                // Redirect to messages
                window.location.href = `/messages/?conversation=${response.data.conversation_id}`;
            } else {
                showError(response.data?.message || 'Failed to send message');
            }
            
            return response;
        } catch (error) {
            console.error('Send trainer message error:', error);
            showError('Failed to send message');
            return { success: false };
        }
    };
    
    // ===========================================
    // START
    // ===========================================
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
