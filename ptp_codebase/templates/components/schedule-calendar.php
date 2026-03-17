<?php
/**
 * Trainer Schedule Calendar Component - v71
 * Interactive calendar with availability management
 */

defined('ABSPATH') || exit;

$user_id = get_current_user_id();
?>

<div class="ptp-schedule-container" id="schedule-container">
    
    <!-- Header -->
    <div class="ptp-schedule-header">
        <div class="ptp-schedule-title">
            <h2>Weekly Availability</h2>
            <p>Set your recurring weekly schedule</p>
        </div>
        <div class="ptp-schedule-actions">
            <button class="ptp-btn ptp-btn-outline ptp-btn-sm" id="sync-gcal-btn">
                <span class="btn-icon">📅</span>
                <span class="btn-text">Connect Google Calendar</span>
            </button>
            <button class="ptp-btn ptp-btn-primary" id="save-schedule-btn">
                Save Schedule
            </button>
        </div>
    </div>
    
    <!-- Google Calendar Status -->
    <div class="ptp-gcal-status" id="gcal-status" style="display: none;">
        <div class="ptp-gcal-connected">
            <span class="ptp-status-dot"></span>
            <span>Google Calendar connected</span>
            <button class="ptp-link-btn" id="gcal-sync-now">Sync Now</button>
            <button class="ptp-link-btn ptp-danger" id="gcal-disconnect">Disconnect</button>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="ptp-quick-actions">
        <button class="ptp-chip" data-preset="morning">Morning (6am-12pm)</button>
        <button class="ptp-chip" data-preset="afternoon">Afternoon (12pm-6pm)</button>
        <button class="ptp-chip" data-preset="evening">Evening (6pm-10pm)</button>
        <button class="ptp-chip" data-preset="full">Full Day (8am-8pm)</button>
        <button class="ptp-chip ptp-chip-outline" data-preset="clear">Clear All</button>
    </div>
    
    <!-- Weekly Schedule Grid -->
    <div class="ptp-week-grid" id="week-grid">
        <!-- Days populated by JavaScript -->
    </div>
    
    <!-- Add Block Modal -->
    <div class="ptp-modal-overlay" id="add-block-modal">
        <div class="ptp-modal ptp-modal-sm">
            <div class="ptp-modal-header">
                <h3 id="modal-title">Add Time Block</h3>
                <button class="ptp-modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="ptp-modal-body">
                <div class="ptp-form-group">
                    <label class="ptp-label">Type</label>
                    <select id="block-type" class="ptp-select">
                        <option value="available">Available</option>
                        <option value="unavailable">Unavailable / Break</option>
                    </select>
                </div>
                <div class="ptp-form-row">
                    <div class="ptp-form-group">
                        <label class="ptp-label">Start Time</label>
                        <select id="block-start" class="ptp-select"></select>
                    </div>
                    <div class="ptp-form-group">
                        <label class="ptp-label">End Time</label>
                        <select id="block-end" class="ptp-select"></select>
                    </div>
                </div>
            </div>
            <div class="ptp-modal-footer">
                <button class="ptp-btn ptp-btn-outline" onclick="closeModal()">Cancel</button>
                <button class="ptp-btn ptp-btn-primary" id="save-block-btn">Add Block</button>
            </div>
        </div>
    </div>
</div>

<style>
/* ===========================================
   SCHEDULE CALENDAR STYLES
   =========================================== */

.ptp-schedule-container {
    background: #fff;
    border: 2px solid #0A0A0A;
}

.ptp-schedule-header {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 20px;
    background: #0A0A0A;
}

@media (min-width: 640px) {
    .ptp-schedule-header {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }
}

.ptp-schedule-title h2 {
    margin: 0;
    font-family: 'Oswald', sans-serif;
    font-size: 24px;
    font-weight: 600;
    text-transform: uppercase;
    color: #FCB900;
}

.ptp-schedule-title p {
    margin: 4px 0 0;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    color: #999;
}

.ptp-schedule-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

/* Google Calendar Status */
.ptp-gcal-status {
    padding: 12px 20px;
    background: #E8F5E9;
    border-bottom: 2px solid #0A0A0A;
}

.ptp-gcal-connected {
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
}

.ptp-status-dot {
    width: 8px;
    height: 8px;
    background: #4CAF50;
    border-radius: 50%;
}

.ptp-link-btn {
    background: none;
    border: none;
    color: #0A0A0A;
    font-size: 13px;
    text-decoration: underline;
    cursor: pointer;
}

.ptp-link-btn.ptp-danger {
    color: #C62828;
}

/* Quick Actions */
.ptp-quick-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 16px 20px;
    background: #F5F5F5;
    border-bottom: 2px solid #0A0A0A;
}

.ptp-chip {
    padding: 8px 16px;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 500;
    background: #FCB900;
    border: 2px solid #0A0A0A;
    cursor: pointer;
    transition: all 0.15s;
}

.ptp-chip:hover {
    background: #0A0A0A;
    color: #FCB900;
}

.ptp-chip-outline {
    background: #fff;
}

/* Week Grid */
.ptp-week-grid {
    display: grid;
    grid-template-columns: 1fr;
}

@media (min-width: 768px) {
    .ptp-week-grid {
        grid-template-columns: repeat(7, 1fr);
    }
}

.ptp-day-column {
    border-right: 1px solid #E5E5E5;
    border-bottom: 1px solid #E5E5E5;
}

@media (min-width: 768px) {
    .ptp-day-column:last-child {
        border-right: none;
    }
    
    .ptp-day-column {
        border-bottom: none;
    }
}

.ptp-day-header {
    padding: 12px 16px;
    background: #FAFAFA;
    border-bottom: 2px solid #0A0A0A;
    text-align: center;
}

.ptp-day-name {
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    color: #0A0A0A;
}

.ptp-day-blocks {
    min-height: 200px;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ptp-time-block {
    padding: 10px 12px;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    border: 2px solid;
    position: relative;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ptp-time-block.available {
    background: #E8F5E9;
    border-color: #4CAF50;
    color: #2E7D32;
}

.ptp-time-block.unavailable {
    background: #FFEBEE;
    border-color: #F44336;
    color: #C62828;
}

.ptp-time-block.google-busy {
    background: #E3F2FD;
    border-color: #2196F3;
    color: #1565C0;
}

.ptp-block-time {
    font-weight: 500;
}

.ptp-block-remove {
    width: 24px;
    height: 24px;
    background: transparent;
    border: none;
    color: inherit;
    font-size: 16px;
    cursor: pointer;
    opacity: 0.6;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ptp-block-remove:hover {
    opacity: 1;
}

.ptp-add-block-btn {
    width: 100%;
    padding: 10px;
    background: #fff;
    border: 2px dashed #CCC;
    font-family: 'Oswald', sans-serif;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    color: #666;
    cursor: pointer;
    transition: all 0.15s;
}

.ptp-add-block-btn:hover {
    border-color: #FCB900;
    color: #0A0A0A;
    background: #FFF9E6;
}

/* Modal */
.ptp-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s;
}

.ptp-modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.ptp-modal {
    width: 100%;
    max-width: 400px;
    background: #fff;
    border: 2px solid #0A0A0A;
}

.ptp-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: #0A0A0A;
}

.ptp-modal-header h3 {
    margin: 0;
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 600;
    text-transform: uppercase;
    color: #FCB900;
}

.ptp-modal-close {
    background: none;
    border: none;
    color: #FCB900;
    font-size: 24px;
    cursor: pointer;
}

.ptp-modal-body {
    padding: 20px;
}

.ptp-modal-footer {
    display: flex;
    gap: 12px;
    padding: 16px 20px;
    border-top: 2px solid #0A0A0A;
}

.ptp-modal-footer .ptp-btn {
    flex: 1;
}

/* Form Elements */
.ptp-form-group {
    margin-bottom: 16px;
}

.ptp-form-row {
    display: flex;
    gap: 16px;
}

.ptp-form-row .ptp-form-group {
    flex: 1;
}

.ptp-label {
    display: block;
    margin-bottom: 8px;
    font-family: 'Oswald', sans-serif;
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
}

.ptp-select {
    width: 100%;
    padding: 12px 16px;
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    border: 2px solid #0A0A0A;
    background: #fff;
    cursor: pointer;
}

.ptp-select:focus {
    outline: none;
    border-color: #FCB900;
}

/* Buttons */
.ptp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 44px;
    padding: 10px 20px;
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    border: 2px solid #0A0A0A;
    cursor: pointer;
    transition: all 0.15s;
}

.ptp-btn-sm {
    min-height: 40px;
    padding: 8px 16px;
    font-size: 13px;
}

.ptp-btn-primary {
    background: #FCB900;
    color: #0A0A0A;
}

.ptp-btn-primary:hover {
    background: #0A0A0A;
    color: #FCB900;
}

.ptp-btn-outline {
    background: transparent;
    color: #FCB900;
    border-color: #FCB900;
}

.ptp-btn-outline:hover {
    background: #FCB900;
    color: #0A0A0A;
}

.ptp-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.btn-icon {
    font-size: 16px;
}

/* Toast */
.ptp-toast {
    position: fixed;
    bottom: 100px;
    left: 50%;
    transform: translateX(-50%) translateY(20px);
    padding: 12px 24px;
    background: #0A0A0A;
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    border: 2px solid #0A0A0A;
    opacity: 0;
    transition: all 0.3s;
    z-index: 10000;
}

.ptp-toast.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}

.ptp-toast.success {
    background: #4CAF50;
    border-color: #4CAF50;
}

.ptp-toast.error {
    background: #F44336;
    border-color: #F44336;
}
</style>

<script>
(function() {
    'use strict';
    
    const config = {
        ajax: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('ptp_nonce'); ?>'
    };
    
    const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    
    // Schedule state
    let schedule = {};
    let currentDay = null;
    let editingBlock = null;
    
    // Initialize
    document.addEventListener('DOMContentLoaded', init);
    
    function init() {
        loadSchedule();
        checkGoogleCalendar();
        bindEvents();
        populateTimeSelects();
    }
    
    function bindEvents() {
        // Save schedule
        document.getElementById('save-schedule-btn')?.addEventListener('click', saveSchedule);
        
        // Google Calendar
        document.getElementById('sync-gcal-btn')?.addEventListener('click', connectGoogleCalendar);
        document.getElementById('gcal-sync-now')?.addEventListener('click', syncGoogleCalendar);
        document.getElementById('gcal-disconnect')?.addEventListener('click', disconnectGoogleCalendar);
        
        // Quick presets
        document.querySelectorAll('.ptp-chip[data-preset]').forEach(btn => {
            btn.addEventListener('click', () => applyPreset(btn.dataset.preset));
        });
        
        // Modal
        document.getElementById('save-block-btn')?.addEventListener('click', saveBlock);
    }
    
    async function loadSchedule() {
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_get_availability_blocks&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                schedule = data.data.schedule || {};
                renderWeekGrid();
            }
        } catch (error) {
            console.error('Load schedule error:', error);
        }
    }
    
    function renderWeekGrid() {
        const grid = document.getElementById('week-grid');
        
        grid.innerHTML = DAYS.map((day, index) => {
            const blocks = schedule[index] || [];
            
            return `
                <div class="ptp-day-column" data-day="${index}">
                    <div class="ptp-day-header">
                        <div class="ptp-day-name">${day.substring(0, 3)}</div>
                    </div>
                    <div class="ptp-day-blocks">
                        ${blocks.map((block, blockIndex) => renderBlock(block, index, blockIndex)).join('')}
                        <button class="ptp-add-block-btn" onclick="openAddModal(${index})">+ Add</button>
                    </div>
                </div>
            `;
        }).join('');
    }
    
    function renderBlock(block, day, index) {
        const typeClass = block.type || 'available';
        const isGoogle = block.type === 'google_busy';
        
        return `
            <div class="ptp-time-block ${typeClass}" data-day="${day}" data-index="${index}">
                <span class="ptp-block-time">${formatTime(block.start)} - ${formatTime(block.end)}</span>
                ${!isGoogle ? `<button class="ptp-block-remove" onclick="removeBlock(${day}, ${index})">&times;</button>` : ''}
            </div>
        `;
    }
    
    function formatTime(time) {
        if (!time) return '';
        const [hours, minutes] = time.split(':');
        const h = parseInt(hours);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 || 12;
        return `${h12}:${minutes || '00'} ${ampm}`;
    }
    
    window.openAddModal = function(day) {
        currentDay = day;
        editingBlock = null;
        
        document.getElementById('modal-title').textContent = `Add Block - ${DAYS[day]}`;
        document.getElementById('block-type').value = 'available';
        document.getElementById('block-start').value = '09:00';
        document.getElementById('block-end').value = '17:00';
        
        document.getElementById('add-block-modal').classList.add('active');
    };
    
    window.closeModal = function() {
        document.getElementById('add-block-modal').classList.remove('active');
        currentDay = null;
        editingBlock = null;
    };
    
    function saveBlock() {
        const type = document.getElementById('block-type').value;
        const start = document.getElementById('block-start').value;
        const end = document.getElementById('block-end').value;
        
        if (!schedule[currentDay]) {
            schedule[currentDay] = [];
        }
        
        schedule[currentDay].push({ type, start, end });
        
        // Sort by start time
        schedule[currentDay].sort((a, b) => a.start.localeCompare(b.start));
        
        renderWeekGrid();
        closeModal();
    }
    
    window.removeBlock = function(day, index) {
        if (schedule[day]) {
            schedule[day].splice(index, 1);
            renderWeekGrid();
        }
    };
    
    async function saveSchedule() {
        const btn = document.getElementById('save-schedule-btn');
        btn.disabled = true;
        btn.textContent = 'Saving...';
        
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_save_availability_blocks&nonce=${config.nonce}&schedule=${encodeURIComponent(JSON.stringify(schedule))}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                showToast('Schedule saved!', 'success');
            } else {
                showToast('Save failed', 'error');
            }
        } catch (error) {
            showToast('Save failed', 'error');
        }
        
        btn.disabled = false;
        btn.textContent = 'Save Schedule';
    }
    
    function applyPreset(preset) {
        const presets = {
            morning: { start: '06:00', end: '12:00' },
            afternoon: { start: '12:00', end: '18:00' },
            evening: { start: '18:00', end: '22:00' },
            full: { start: '08:00', end: '20:00' }
        };
        
        if (preset === 'clear') {
            schedule = {};
        } else {
            const times = presets[preset];
            DAYS.forEach((_, index) => {
                schedule[index] = [{ type: 'available', start: times.start, end: times.end }];
            });
        }
        
        renderWeekGrid();
    }
    
    function populateTimeSelects() {
        const times = [];
        for (let h = 6; h <= 22; h++) {
            times.push(`${h.toString().padStart(2, '0')}:00`);
            times.push(`${h.toString().padStart(2, '0')}:30`);
        }
        
        const startSelect = document.getElementById('block-start');
        const endSelect = document.getElementById('block-end');
        
        times.forEach(time => {
            startSelect.innerHTML += `<option value="${time}">${formatTime(time)}</option>`;
            endSelect.innerHTML += `<option value="${time}">${formatTime(time)}</option>`;
        });
        
        startSelect.value = '09:00';
        endSelect.value = '17:00';
    }
    
    // Google Calendar functions
    async function checkGoogleCalendar() {
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_get_calendar_status&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success && data.data.connected) {
                document.getElementById('gcal-status').style.display = 'block';
                document.getElementById('sync-gcal-btn').querySelector('.btn-text').textContent = 'Connected';
            }
        } catch (error) {
            console.error('Check gcal error:', error);
        }
    }
    
    async function connectGoogleCalendar() {
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_google_connect&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success && data.data.auth_url) {
                window.location.href = data.data.auth_url;
            } else {
                showToast(data.data?.message || 'Connection failed', 'error');
            }
        } catch (error) {
            showToast('Connection failed', 'error');
        }
    }
    
    async function syncGoogleCalendar() {
        showToast('Syncing...', 'info');
        
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_google_sync&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                showToast('Calendar synced!', 'success');
                loadSchedule();
            } else {
                showToast('Sync failed', 'error');
            }
        } catch (error) {
            showToast('Sync failed', 'error');
        }
    }
    
    async function disconnectGoogleCalendar() {
        if (!confirm('Disconnect Google Calendar?')) return;
        
        try {
            await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_google_disconnect&nonce=${config.nonce}`
            });
            
            document.getElementById('gcal-status').style.display = 'none';
            document.getElementById('sync-gcal-btn').querySelector('.btn-text').textContent = 'Connect Google Calendar';
            showToast('Disconnected', 'success');
            loadSchedule();
        } catch (error) {
            showToast('Error disconnecting', 'error');
        }
    }
    
    function showToast(message, type = 'info') {
        const existing = document.querySelector('.ptp-toast');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.className = `ptp-toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
})();
</script>
