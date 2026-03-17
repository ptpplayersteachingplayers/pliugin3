<?php
/**
 * Quick Profile Editor Component v1.0.0
 *
 * A modal-based quick editor for trainer profiles
 *
 * Can be triggered from dashboard header or settings tab
 * 
 * Features:
 * - Photo upload with the improved component
 * - Quick edit for key fields (headline, bio, rate)
 * - Auto-save on changes
 * - Mobile-optimized modal
 * 
 * Usage: ptp_render_quick_profile_editor($trainer)
 */

// v223.1: Added ABSPATH guard
defined('ABSPATH') || exit;

// Include the photo upload component
if (!function_exists('ptp_render_photo_upload')) {
    require_once dirname(__FILE__) . '/class-ptp-photo-upload.php';
}

/**
 * Render the quick profile editor trigger and modal
 * 
 * @param object $trainer The trainer object
 * @param array $options Optional settings
 */
function ptp_render_quick_profile_editor($trainer, $options = array()) {
    $defaults = array(
        'trigger_style' => 'button', // 'button', 'avatar', 'link'
        'trigger_text' => 'Edit Profile',
    );
    $opts = array_merge($defaults, $options);
    
    $photo = $trainer->photo_url ?: PTP_Images::avatar($trainer->display_name, 200);
    $nonce = wp_create_nonce('ptp_quick_edit');
    ?>
    
    <style>
    /* Quick Edit Trigger */
    .ptp-qe-trigger {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .ptp-qe-trigger-avatar {
        position: relative;
    }
    
    .ptp-qe-trigger-avatar img {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        border: 2px solid #FCB900;
        object-fit: cover;
    }
    
    .ptp-qe-trigger-avatar::after {
        content: '';
        position: absolute;
        bottom: -2px;
        right: -2px;
        width: 18px;
        height: 18px;
        background: #FCB900 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%230A0A0A' stroke-width='3'%3E%3Cpath d='M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z'/%3E%3C/svg%3E") center no-repeat;
        border-radius: 50%;
        border: 2px solid #0A0A0A;
    }
    
    .ptp-qe-trigger-avatar:hover img {
        opacity: 0.8;
    }
    
    .ptp-qe-trigger-btn {
        padding: 10px 16px;
        background: rgba(255,255,255,0.1);
        color: #fff;
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
    }
    
    .ptp-qe-trigger-btn:hover {
        background: rgba(255,255,255,0.15);
        border-color: #FCB900;
    }
    
    /* Modal Overlay */
    .ptp-qe-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.6);
        z-index: 10000;
        align-items: flex-end;
        justify-content: center;
        padding: 0;
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
    }
    
    .ptp-qe-overlay.active {
        display: flex;
    }
    
    @media (min-width: 640px) {
        .ptp-qe-overlay {
            align-items: center;
            padding: 20px;
        }
    }
    
    /* Modal */
    .ptp-qe-modal {
        background: #fff;
        width: 100%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
        border-radius: 20px 20px 0 0;
        box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.2);
        animation: slideUp 0.3s ease;
        font-family: 'Inter', -apple-system, sans-serif;
    }
    
    @media (min-width: 640px) {
        .ptp-qe-modal {
            border-radius: 20px;
            animation: fadeIn 0.2s ease;
        }
    }
    
    @keyframes slideUp {
        from { transform: translateY(100%); }
        to { transform: translateY(0); }
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: scale(0.95); }
        to { opacity: 1; transform: scale(1); }
    }
    
    /* Modal Header */
    .ptp-qe-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px;
        border-bottom: 1px solid #E5E7EB;
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 10;
    }
    
    .ptp-qe-title {
        font-family: 'Oswald', sans-serif;
        font-size: 18px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    
    .ptp-qe-close {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #F3F4F6;
        border: none;
        border-radius: 50%;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .ptp-qe-close:hover {
        background: #E5E7EB;
    }
    
    /* Modal Body */
    .ptp-qe-body {
        padding: 20px;
    }
    
    /* Sections */
    .ptp-qe-section {
        margin-bottom: 24px;
    }
    
    .ptp-qe-section:last-child {
        margin-bottom: 0;
    }
    
    .ptp-qe-section-title {
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6B7280;
        margin-bottom: 12px;
    }
    
    /* Form Fields */
    .ptp-qe-field {
        margin-bottom: 16px;
    }
    
    .ptp-qe-field:last-child {
        margin-bottom: 0;
    }
    
    .ptp-qe-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 6px;
    }
    
    .ptp-qe-input {
        width: 100%;
        padding: 12px 14px;
        border: 2px solid #E5E7EB;
        border-radius: 10px;
        font-size: 15px;
        font-family: inherit;
        transition: border-color 0.2s;
    }
    
    .ptp-qe-input:focus {
        outline: none;
        border-color: #FCB900;
    }
    
    .ptp-qe-input::placeholder {
        color: #9CA3AF;
    }
    
    .ptp-qe-textarea {
        min-height: 100px;
        resize: vertical;
    }
    
    .ptp-qe-hint {
        font-size: 12px;
        color: #6B7280;
        margin-top: 4px;
    }
    
    /* Rate field with prefix */
    .ptp-qe-rate-field {
        position: relative;
    }
    
    .ptp-qe-rate-prefix {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 16px;
        font-weight: 700;
        color: #6B7280;
    }
    
    .ptp-qe-rate-field .ptp-qe-input {
        padding-left: 28px;
        padding-right: 60px;
    }
    
    .ptp-qe-rate-suffix {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 13px;
        color: #6B7280;
    }
    
    /* Save Status */
    .ptp-qe-save-status {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        font-weight: 600;
        color: #6B7280;
        transition: all 0.2s;
        margin-top: 8px;
    }
    
    .ptp-qe-save-status.saving {
        color: #F59E0B;
    }
    
    .ptp-qe-save-status.saved {
        color: #059669;
    }
    
    .ptp-qe-save-status svg {
        width: 14px;
        height: 14px;
    }
    
    /* Modal Footer */
    .ptp-qe-footer {
        padding: 16px 20px;
        border-top: 1px solid #E5E7EB;
        display: flex;
        gap: 12px;
        background: #F9FAFB;
    }
    
    .ptp-qe-btn {
        flex: 1;
        padding: 14px;
        border: none;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .ptp-qe-btn-primary {
        background: #FCB900;
        color: #0A0A0A;
    }
    
    .ptp-qe-btn-primary:hover {
        background: #E5A800;
    }
    
    .ptp-qe-btn-secondary {
        background: #fff;
        color: #374151;
        border: 1px solid #E5E7EB;
    }
    
    .ptp-qe-btn-secondary:hover {
        background: #F3F4F6;
    }
    
    /* View Profile Link */
    .ptp-qe-view-link {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 12px;
        background: #F3F4F6;
        border-radius: 10px;
        color: #374151;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
        margin-top: 16px;
    }
    
    .ptp-qe-view-link:hover {
        background: #E5E7EB;
        color: #0A0A0A;
    }
    
    .ptp-qe-view-link svg {
        width: 16px;
        height: 16px;
    }
    
    /* Location Fields */
    .ptp-qe-location-row {
        position: relative;
        padding: 12px;
        background: #F9FAFB;
        border: 1px solid #E5E7EB;
        border-radius: 10px;
        margin-bottom: 10px;
    }
    
    .ptp-qe-location-row .ptp-qe-input {
        background: #fff;
    }
    
    .ptp-qe-loc-remove {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 24px;
        height: 24px;
        background: #EF4444;
        color: #fff;
        border: none;
        border-radius: 50%;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .ptp-qe-loc-remove:hover {
        background: #DC2626;
    }
    
    .ptp-qe-add-loc-btn {
        width: 100%;
        padding: 10px;
        background: #fff;
        border: 2px dashed #E5E7EB;
        border-radius: 10px;
        color: #6B7280;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 8px;
    }
    
    .ptp-qe-add-loc-btn:hover {
        border-color: #FCB900;
        color: #0A0A0A;
        background: #FFFBEB;
    }
    </style>
    
    <!-- Trigger -->
    <?php if ($opts['trigger_style'] === 'avatar'): ?>
    <div class="ptp-qe-trigger ptp-qe-trigger-avatar" onclick="ptpOpenQuickEdit()" title="Edit Profile">
        <img src="<?php echo esc_url($photo); ?>" alt="">
    </div>
    <?php elseif ($opts['trigger_style'] === 'link'): ?>
    <a href="#" class="ptp-qe-trigger" onclick="ptpOpenQuickEdit(); return false;">
        <?php echo esc_html($opts['trigger_text']); ?>
    </a>
    <?php else: ?>
    <button type="button" class="ptp-qe-trigger ptp-qe-trigger-btn" onclick="ptpOpenQuickEdit()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
        </svg>
        <?php echo esc_html($opts['trigger_text']); ?>
    </button>
    <?php endif; ?>
    
    <!-- Modal Overlay -->
    <div class="ptp-qe-overlay" id="ptp-qe-overlay" onclick="if(event.target===this) ptpCloseQuickEdit()">
        <div class="ptp-qe-modal">
            <div class="ptp-qe-header">
                <h2 class="ptp-qe-title">Edit Profile</h2>
                <button type="button" class="ptp-qe-close" onclick="ptpCloseQuickEdit()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            
            <div class="ptp-qe-body">
                <!-- Photo Section -->
                <div class="ptp-qe-section">
                    <div class="ptp-qe-section-title">Profile Photo</div>
                    <?php ptp_render_photo_upload($trainer, array('size' => 'medium', 'show_tips' => false)); ?>
                </div>
                
                <!-- Basic Info -->
                <div class="ptp-qe-section">
                    <div class="ptp-qe-section-title">Basic Info</div>
                    
                    <div class="ptp-qe-field">
                        <label class="ptp-qe-label">Headline</label>
                        <input type="text" 
                               class="ptp-qe-input" 
                               id="ptp-qe-headline"
                               value="<?php echo esc_attr($trainer->headline ?? ''); ?>"
                               placeholder="e.g., D1 Midfielder | Youth Development Specialist"
                               onchange="ptpQuickSave('headline', this.value)">
                        <p class="ptp-qe-hint">Shows under your name on your profile</p>
                    </div>
                    
                    <div class="ptp-qe-field">
                        <label class="ptp-qe-label">Bio</label>
                        <textarea class="ptp-qe-input ptp-qe-textarea" 
                                  id="ptp-qe-bio"
                                  placeholder="Tell families about your playing experience and coaching style..."
                                  onchange="ptpQuickSave('bio', this.value)"><?php echo esc_textarea($trainer->bio ?? ''); ?></textarea>
                    </div>
                </div>
                
                <!-- Pricing -->
                <div class="ptp-qe-section">
                    <div class="ptp-qe-section-title">Pricing</div>
                    
                    <div class="ptp-qe-field">
                        <label class="ptp-qe-label">Hourly Rate</label>
                        <div class="ptp-qe-rate-field">
                            <span class="ptp-qe-rate-prefix">$</span>
                            <input type="number" 
                                   class="ptp-qe-input" 
                                   id="ptp-qe-rate"
                                   value="<?php echo esc_attr($trainer->hourly_rate ?? 80); ?>"
                                   min="40"
                                   max="200"
                                   step="5"
                                   onchange="ptpQuickSave('hourly_rate', this.value)">
                            <span class="ptp-qe-rate-suffix">/hour</span>
                        </div>
                        <p class="ptp-qe-hint">Recommended: $60-120 based on experience</p>
                    </div>
                </div>
                
                <!-- Why I Coach -->
                <div class="ptp-qe-section">
                    <div class="ptp-qe-section-title">🏆 Why I Coach</div>
                    
                    <div class="ptp-qe-field">
                        <label class="ptp-qe-label">Your Coaching "Why"</label>
                        <textarea class="ptp-qe-input ptp-qe-textarea" 
                                  id="ptp-qe-coaching-why"
                                  placeholder="What drives you to train? Share your story - injury comeback, love of teaching, giving back to the game..."
                                  onchange="ptpQuickSave('coaching_why', this.value)"><?php echo esc_textarea($trainer->coaching_why ?? ''); ?></textarea>
                        <p class="ptp-qe-hint">Families connect with trainers who have a compelling story</p>
                    </div>
                </div>
                
                <!-- Training Philosophy -->
                <div class="ptp-qe-section">
                    <div class="ptp-qe-section-title">📋 Training Philosophy</div>
                    
                    <div class="ptp-qe-field">
                        <label class="ptp-qe-label">What Makes Your Sessions Different?</label>
                        <textarea class="ptp-qe-input ptp-qe-textarea" 
                                  id="ptp-qe-training-philosophy"
                                  placeholder="What do you focus on? Technical skills, game IQ, confidence building? What's your approach?"
                                  onchange="ptpQuickSave('training_philosophy', this.value)"><?php echo esc_textarea($trainer->training_philosophy ?? ''); ?></textarea>
                        <p class="ptp-qe-hint">Help families understand your unique approach</p>
                    </div>
                </div>
                
                <!-- Training Locations -->
                <div class="ptp-qe-section">
                    <div class="ptp-qe-section-title">📍 Training Locations</div>
                    
                    <div class="ptp-qe-field">
                        <label class="ptp-qe-label">Where do you train?</label>
                        <div id="ptp-qe-locations">
                            <?php 
                            $locations = $trainer->training_locations ? json_decode($trainer->training_locations, true) : array();
                            if (empty($locations)) {
                                $locations = array(array('name' => '', 'address' => ''));
                            }
                            foreach ($locations as $i => $loc):
                            ?>
                            <div class="ptp-qe-location-row" data-index="<?php echo $i; ?>">
                                <input type="text" 
                                       class="ptp-qe-input ptp-qe-loc-name" 
                                       placeholder="Field name (e.g., Villanova Stadium)"
                                       value="<?php echo esc_attr($loc['name'] ?? ''); ?>"
                                       style="margin-bottom:6px">
                                <input type="text" 
                                       class="ptp-qe-input ptp-qe-loc-addr" 
                                       placeholder="Full address for Google Maps"
                                       value="<?php echo esc_attr($loc['address'] ?? ''); ?>">
                                <?php if ($i > 0): ?>
                                <button type="button" class="ptp-qe-loc-remove" onclick="ptpRemoveLocation(this)">✕</button>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="ptp-qe-add-loc-btn" onclick="ptpAddLocation()">+ Add Another Location</button>
                        <p class="ptp-qe-hint">Add field addresses - they'll appear on a Google Map on your profile</p>
                    </div>
                </div>
                
                <!-- Training Policy -->
                <div class="ptp-qe-section">
                    <div class="ptp-qe-section-title">📜 Training Policy (Optional)</div>
                    
                    <div class="ptp-qe-field">
                        <label class="ptp-qe-label">Custom Cancellation/Rescheduling Policy</label>
                        <textarea class="ptp-qe-input ptp-qe-textarea" 
                                  id="ptp-qe-training-policy"
                                  placeholder="Leave blank for standard policy (24hr cancellation, weather rescheduling). Add custom rules here if needed."
                                  onchange="ptpQuickSave('training_policy', this.value)"><?php echo esc_textarea($trainer->training_policy ?? ''); ?></textarea>
                        <p class="ptp-qe-hint">Standard policy: 24hr cancel, no-show charged, weather reschedules free</p>
                    </div>
                </div>
                
                <!-- Save Status -->
                <div class="ptp-qe-save-status" id="ptp-qe-status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    All changes saved
                </div>
                
                <!-- View Profile Link -->
                <a href="<?php echo esc_url(home_url('/trainer/' . ($trainer->slug ?: sanitize_title($trainer->display_name)) . '/')); ?>" 
                   class="ptp-qe-view-link" 
                   target="_blank">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                        <polyline points="15 3 21 3 21 9"/>
                        <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    View Public Profile
                </a>
            </div>
            
            <div class="ptp-qe-footer">
                <button type="button" class="ptp-qe-btn ptp-qe-btn-secondary" onclick="ptpCloseQuickEdit()">
                    Close
                </button>
                <a href="<?php echo esc_url(home_url('/trainer-onboarding/?edit=1')); ?>" class="ptp-qe-btn ptp-qe-btn-primary" style="text-decoration:none;text-align:center">
                    Full Editor
                </a>
            </div>
        </div>
    </div>
    
    <script>
    (function() {
        var trainerId = <?php echo (int)$trainer->id; ?>;
        var nonce = '<?php echo $nonce; ?>';
        var saveTimeout = null;
        
        window.ptpOpenQuickEdit = function() {
            document.getElementById('ptp-qe-overlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        };
        
        window.ptpCloseQuickEdit = function() {
            document.getElementById('ptp-qe-overlay').classList.remove('active');
            document.body.style.overflow = '';
        };
        
        window.ptpQuickSave = function(field, value) {
            var status = document.getElementById('ptp-qe-status');
            status.className = 'ptp-qe-save-status saving';
            status.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Saving...';
            
            // Debounce
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(function() {
                var formData = new FormData();
                formData.append('action', 'ptp_quick_save_trainer');
                formData.append('trainer_id', trainerId);
                formData.append('field', field);
                formData.append('value', value);
                formData.append('nonce', nonce);
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        status.className = 'ptp-qe-save-status saved';
                        status.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Saved!';
                    } else {
                        status.className = 'ptp-qe-save-status';
                        status.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Error saving';
                    }
                })
                .catch(function() {
                    status.className = 'ptp-qe-save-status';
                    status.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Network error';
                });
            }, 500);
        };
        
        // Location management
        var locationIndex = document.querySelectorAll('.ptp-qe-location-row').length;
        
        window.ptpAddLocation = function() {
            var container = document.getElementById('ptp-qe-locations');
            var newRow = document.createElement('div');
            newRow.className = 'ptp-qe-location-row';
            newRow.setAttribute('data-index', locationIndex);
            newRow.innerHTML = '<input type="text" class="ptp-qe-input ptp-qe-loc-name" placeholder="Field name (e.g., Villanova Stadium)" style="margin-bottom:6px">' +
                '<input type="text" class="ptp-qe-input ptp-qe-loc-addr" placeholder="Full address for Google Maps">' +
                '<button type="button" class="ptp-qe-loc-remove" onclick="ptpRemoveLocation(this)">✕</button>';
            container.appendChild(newRow);
            locationIndex++;
            
            // Add change listeners
            newRow.querySelectorAll('.ptp-qe-input').forEach(function(inp) {
                inp.addEventListener('change', ptpSaveLocations);
            });
        };
        
        window.ptpRemoveLocation = function(btn) {
            btn.closest('.ptp-qe-location-row').remove();
            ptpSaveLocations();
        };
        
        window.ptpSaveLocations = function() {
            var rows = document.querySelectorAll('.ptp-qe-location-row');
            var locations = [];
            rows.forEach(function(row) {
                var name = row.querySelector('.ptp-qe-loc-name').value.trim();
                var addr = row.querySelector('.ptp-qe-loc-addr').value.trim();
                if (name || addr) {
                    locations.push({name: name, address: addr});
                }
            });
            ptpQuickSave('training_locations', JSON.stringify(locations));
        };
        
        // Attach change listeners to existing location inputs
        document.querySelectorAll('.ptp-qe-location-row .ptp-qe-input').forEach(function(inp) {
            inp.addEventListener('change', ptpSaveLocations);
        });
        
        // Close on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('ptp-qe-overlay').classList.contains('active')) {
                ptpCloseQuickEdit();
            }
        });
    })();
    </script>
    
    <style>
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .ptp-qe-save-status .spin {
        animation: spin 1s linear infinite;
    }
    </style>
    <?php
}

/**
 * AJAX handler for quick save
 */
add_action('wp_ajax_ptp_quick_save_trainer', 'ptp_ajax_quick_save_trainer');
function ptp_ajax_quick_save_trainer() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_quick_edit')) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }
    
    $trainer_id = intval($_POST['trainer_id'] ?? 0);
    $field = sanitize_key($_POST['field'] ?? '');
    $value = $_POST['value'] ?? '';
    
    if (!$trainer_id || !$field) {
        wp_send_json_error(array('message' => 'Invalid request'));
    }
    
    // Verify permission
    $trainer = PTP_Trainer::get($trainer_id);
    if (!$trainer || $trainer->user_id != get_current_user_id()) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    // Allowed fields
    $allowed_fields = array('headline', 'bio', 'hourly_rate', 'college', 'team', 'coaching_why', 'training_philosophy', 'training_policy', 'training_locations');
    if (!in_array($field, $allowed_fields)) {
        wp_send_json_error(array('message' => 'Invalid field'));
    }
    
    // Sanitize value based on field
    if ($field === 'hourly_rate') {
        $value = max(40, min(200, intval($value)));
    } elseif (in_array($field, array('bio', 'coaching_why', 'training_philosophy', 'training_policy'))) {
        $value = sanitize_textarea_field($value);
    } elseif ($field === 'training_locations') {
        // Validate JSON
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            $value = '[]';
        } else {
            // Sanitize each location — preserve lat/lng/place_id from Google Places
            $clean = array();
            foreach ($decoded as $loc) {
                if (is_array($loc)) {
                    $clean[] = array(
                        'name'     => sanitize_text_field($loc['name'] ?? ''),
                        'address'  => sanitize_text_field($loc['address'] ?? ''),
                        'lat'      => isset($loc['lat']) && is_numeric($loc['lat']) ? floatval($loc['lat']) : null,
                        'lng'      => isset($loc['lng']) && is_numeric($loc['lng']) ? floatval($loc['lng']) : null,
                        'place_id' => sanitize_text_field($loc['place_id'] ?? ''),
                    );
                }
            }
            $value = wp_json_encode($clean);
        }
    } else {
        $value = sanitize_text_field($value);
    }
    
    // Update
    global $wpdb;
    $table = $wpdb->prefix . 'ptp_trainers';
    $result = $wpdb->update($table, array($field => $value), array('id' => $trainer_id));
    
    if ($result === false) {
        wp_send_json_error(array('message' => 'Database error'));
    }
    
    wp_send_json_success();
}
