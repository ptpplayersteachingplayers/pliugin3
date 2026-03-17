<?php
/**
 * Profile Picture Upload Component v1.0.0
 *
 * A reusable, mobile-optimized profile picture upload component
 *
 * Features:
 * - Drag & drop support
 * - Camera capture on mobile
 * - Live preview with positioning
 * - Progress indicator
 * - Error handling with clear feedback
 * - Auto-save on upload
 * 
 * Usage: Include this file and call ptp_render_photo_upload($trainer, $options)
 */

// v223.1: Added ABSPATH guard
defined('ABSPATH') || exit;

/**
 * Render the profile photo upload component
 * 
 * @param object $trainer The trainer object with photo_url property
 * @param array $options Optional settings:
 *   - 'size' => 'small'|'medium'|'large' (default: 'medium')
 *   - 'show_tips' => true|false (default: true)
 *   - 'auto_save' => true|false (default: true)
 *   - 'container_class' => additional CSS class
 */
function ptp_render_photo_upload($trainer, $options = array()) {
    $defaults = array(
        'size' => 'medium',
        'show_tips' => true,
        'auto_save' => true,
        'container_class' => '',
    );
    $opts = array_merge($defaults, $options);
    
    $photo = !empty($trainer->photo_url) ? $trainer->photo_url : '';
    $has_photo = !empty($photo);
    $trainer_id = $trainer->id ?? 0;
    
    // Size mappings
    $sizes = array(
        'small' => array('preview' => 80, 'icon' => 32),
        'medium' => array('preview' => 140, 'icon' => 48),
        'large' => array('preview' => 200, 'icon' => 64),
    );
    $size = $sizes[$opts['size']] ?? $sizes['medium'];
    
    $nonce = wp_create_nonce('ptp_photo_upload');
    ?>
    
    <div class="ptp-photo-uploader <?php echo esc_attr($opts['container_class']); ?>" 
         data-trainer-id="<?php echo esc_attr($trainer_id); ?>"
         data-auto-save="<?php echo $opts['auto_save'] ? '1' : '0'; ?>">
        
        <style>
        .ptp-photo-uploader {
            --preview-size: <?php echo $size['preview']; ?>px;
            --icon-size: <?php echo $size['icon']; ?>px;
            font-family: 'Inter', -apple-system, sans-serif;
        }
        
        .ptp-photo-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        
        @media (min-width: 480px) {
            .ptp-photo-container {
                flex-direction: row;
                align-items: flex-start;
            }
        }
        
        /* Drop Zone */
        .ptp-photo-dropzone {
            position: relative;
            width: var(--preview-size);
            height: var(--preview-size);
            border-radius: 12px;
            border: 3px dashed #E5E7EB;
            background: #F9FAFB;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .ptp-photo-dropzone:hover {
            border-color: #FCB900;
            background: #FFFBEB;
        }
        
        .ptp-photo-dropzone.dragging {
            border-color: #FCB900;
            background: #FEF3C7;
            transform: scale(1.02);
        }
        
        .ptp-photo-dropzone.has-photo {
            border-style: solid;
            border-color: #FCB900;
        }
        
        .ptp-photo-dropzone.uploading {
            pointer-events: none;
        }
        
        /* Preview Image */
        .ptp-photo-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center top;
            display: none;
        }
        
        .ptp-photo-dropzone.has-photo .ptp-photo-preview {
            display: block;
        }
        
        .ptp-photo-dropzone.has-photo .ptp-photo-placeholder {
            display: none;
        }
        
        /* Placeholder */
        .ptp-photo-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            color: #9CA3AF;
            padding: 12px;
            text-align: center;
        }
        
        .ptp-photo-placeholder svg {
            width: var(--icon-size);
            height: var(--icon-size);
            color: #D1D5DB;
        }
        
        .ptp-photo-placeholder-text {
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
        }
        
        /* Hover Overlay */
        .ptp-photo-overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            border-radius: 9px;
        }
        
        .ptp-photo-dropzone:hover .ptp-photo-overlay,
        .ptp-photo-dropzone:focus-within .ptp-photo-overlay {
            opacity: 1;
        }
        
        .ptp-photo-dropzone.uploading .ptp-photo-overlay {
            opacity: 1;
            background: rgba(0, 0, 0, 0.8);
        }
        
        .ptp-photo-overlay-text {
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        
        /* Progress Ring */
        .ptp-photo-progress {
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        
        .ptp-photo-dropzone.uploading .ptp-photo-progress {
            display: flex;
        }
        
        .ptp-photo-dropzone.uploading .ptp-photo-overlay-text {
            display: none;
        }
        
        .ptp-progress-ring {
            width: 48px;
            height: 48px;
        }
        
        .ptp-progress-ring circle {
            fill: none;
            stroke-width: 4;
        }
        
        .ptp-progress-ring .bg {
            stroke: rgba(255, 255, 255, 0.2);
        }
        
        .ptp-progress-ring .progress {
            stroke: #FCB900;
            stroke-linecap: round;
            transform: rotate(-90deg);
            transform-origin: center;
            transition: stroke-dashoffset 0.3s;
        }
        
        .ptp-progress-text {
            color: #fff;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Actions Panel */
        .ptp-photo-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            text-align: center;
        }
        
        @media (min-width: 480px) {
            .ptp-photo-actions {
                text-align: left;
                flex: 1;
            }
        }
        
        /* Buttons */
        .ptp-photo-btns {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
        }
        
        @media (min-width: 480px) {
            .ptp-photo-btns {
                justify-content: flex-start;
            }
        }
        
        .ptp-photo-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 12px 16px;
            background: #0A0A0A;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .ptp-photo-btn:hover {
            background: #1F2937;
            transform: translateY(-1px);
        }
        
        .ptp-photo-btn:active {
            transform: translateY(0);
        }
        
        .ptp-photo-btn svg {
            width: 16px;
            height: 16px;
        }
        
        .ptp-photo-btn-secondary {
            background: #F3F4F6;
            color: #374151;
        }
        
        .ptp-photo-btn-secondary:hover {
            background: #E5E7EB;
        }
        
        .ptp-photo-btn-danger {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        .ptp-photo-btn-danger:hover {
            background: #FECACA;
        }
        
        /* Hidden file input */
        .ptp-photo-input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }
        
        /* Tips */
        .ptp-photo-tips {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 12px;
            color: #6B7280;
        }
        
        .ptp-photo-tip {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .ptp-photo-tip svg {
            width: 14px;
            height: 14px;
            color: #10B981;
            flex-shrink: 0;
        }
        
        /* Status Messages */
        .ptp-photo-status {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: none;
            align-items: center;
            gap: 8px;
        }
        
        .ptp-photo-status.visible {
            display: flex;
        }
        
        .ptp-photo-status.success {
            background: #D1FAE5;
            color: #059669;
        }
        
        .ptp-photo-status.error {
            background: #FEE2E2;
            color: #DC2626;
        }
        
        .ptp-photo-status svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }
        
        /* Mobile camera button */
        @media (max-width: 480px) {
            .ptp-photo-btn-camera {
                order: -1;
                width: 100%;
                justify-content: center;
                background: #FCB900;
                color: #0A0A0A;
            }
            
            .ptp-photo-btn-camera:hover {
                background: #E5A800;
            }
        }
        
        /* Size variations */
        .ptp-photo-uploader.size-small {
            --preview-size: 80px;
            --icon-size: 32px;
        }
        
        .ptp-photo-uploader.size-large {
            --preview-size: 200px;
            --icon-size: 64px;
        }
        </style>
        
        <div class="ptp-photo-container">
            <!-- Drop Zone / Preview -->
            <div class="ptp-photo-dropzone <?php echo $has_photo ? 'has-photo' : ''; ?>" 
                 id="ptp-dropzone"
                 onclick="document.getElementById('ptp-photo-input').click()"
                 tabindex="0"
                 role="button"
                 aria-label="Upload profile photo">
                
                <img class="ptp-photo-preview" 
                     id="ptp-photo-preview"
                     src="<?php echo $has_photo ? esc_url($photo) : ''; ?>" 
                     alt="Profile photo">
                
                <div class="ptp-photo-placeholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    <span class="ptp-photo-placeholder-text">
                        Drop photo here<br>or click to upload
                    </span>
                </div>
                
                <div class="ptp-photo-overlay">
                    <span class="ptp-photo-overlay-text">
                        <?php echo $has_photo ? 'Change Photo' : 'Upload Photo'; ?>
                    </span>
                    
                    <div class="ptp-photo-progress">
                        <svg class="ptp-progress-ring" viewBox="0 0 48 48">
                            <circle class="bg" cx="24" cy="24" r="20"/>
                            <circle class="progress" cx="24" cy="24" r="20" 
                                    stroke-dasharray="125.6" 
                                    stroke-dashoffset="125.6"
                                    id="ptp-progress-circle"/>
                        </svg>
                        <span class="ptp-progress-text" id="ptp-progress-text">0%</span>
                    </div>
                </div>
                
                <input type="file" 
                       class="ptp-photo-input" 
                       id="ptp-photo-input"
                       name="photo"
                       accept="image/jpeg,image/png,image/webp"
                       onchange="ptpHandlePhotoSelect(this)">
            </div>
            
            <!-- Actions -->
            <div class="ptp-photo-actions">
                <div class="ptp-photo-btns">
                    <!-- Camera button (prominent on mobile) -->
                    <button type="button" class="ptp-photo-btn ptp-photo-btn-camera" onclick="ptpOpenCamera()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
                            <circle cx="12" cy="13" r="4"/>
                        </svg>
                        Take Photo
                    </button>
                    
                    <!-- Upload button -->
                    <button type="button" class="ptp-photo-btn ptp-photo-btn-secondary" onclick="document.getElementById('ptp-photo-input').click()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        Upload
                    </button>
                    
                    <!-- Remove button (only shown when photo exists) -->
                    <button type="button" 
                            class="ptp-photo-btn ptp-photo-btn-danger" 
                            id="ptp-remove-btn"
                            onclick="ptpRemovePhoto()"
                            style="<?php echo $has_photo ? '' : 'display:none'; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                        </svg>
                        Remove
                    </button>
                </div>
                
                <!-- Status message -->
                <div class="ptp-photo-status" id="ptp-status"></div>
                
                <?php if ($opts['show_tips']): ?>
                <!-- Tips -->
                <div class="ptp-photo-tips">
                    <div class="ptp-photo-tip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        <span>High-quality headshot or action photo</span>
                    </div>
                    <div class="ptp-photo-tip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        <span>JPG, PNG, or WebP • Max 5MB</span>
                    </div>
                    <div class="ptp-photo-tip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        <span>Trainers with photos get 5x more bookings</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Hidden camera input -->
        <input type="file" 
               id="ptp-camera-input" 
               accept="image/*" 
               capture="user"
               style="display:none"
               onchange="ptpHandlePhotoSelect(this)">
    </div>
    
    <script>
    (function() {
        var dropzone = document.getElementById('ptp-dropzone');
        var preview = document.getElementById('ptp-photo-preview');
        var progressCircle = document.getElementById('ptp-progress-circle');
        var progressText = document.getElementById('ptp-progress-text');
        var statusEl = document.getElementById('ptp-status');
        var removeBtn = document.getElementById('ptp-remove-btn');
        var container = dropzone.closest('.ptp-photo-uploader');
        var trainerId = container.dataset.trainerId;
        var autoSave = container.dataset.autoSave === '1';
        
        // Drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
            });
        });
        
        ['dragenter', 'dragover'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function() {
                dropzone.classList.add('dragging');
            });
        });
        
        ['dragleave', 'drop'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function() {
                dropzone.classList.remove('dragging');
            });
        });
        
        dropzone.addEventListener('drop', function(e) {
            var files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFile(files[0]);
            }
        });
        
        // Keyboard support
        dropzone.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                document.getElementById('ptp-photo-input').click();
            }
        });
        
        // File selection handler
        window.ptpHandlePhotoSelect = function(input) {
            if (input.files && input.files[0]) {
                handleFile(input.files[0]);
            }
        };
        
        // Camera
        window.ptpOpenCamera = function() {
            var isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
            if (isMobile) {
                document.getElementById('ptp-camera-input').click();
            } else {
                // On desktop, just open file picker
                document.getElementById('ptp-photo-input').click();
            }
        };
        
        // Remove photo
        window.ptpRemovePhoto = function() {
            if (!confirm('Remove your profile photo?')) return;
            
            preview.src = '';
            dropzone.classList.remove('has-photo');
            removeBtn.style.display = 'none';
            
            if (autoSave && trainerId) {
                var formData = new FormData();
                formData.append('action', 'ptp_remove_trainer_photo');
                formData.append('trainer_id', trainerId);
                formData.append('nonce', '<?php echo $nonce; ?>');
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                }).then(function(r) { return r.json(); })
                .then(function(data) {
                    showStatus(data.success ? 'success' : 'error', 
                               data.success ? 'Photo removed' : (data.data?.message || 'Failed to remove photo'));
                });
            }
        };
        
        function handleFile(file) {
            // Validate
            if (!file.type.match(/^image\/(jpeg|png|webp)$/)) {
                showStatus('error', 'Please upload a JPG, PNG, or WebP image');
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                showStatus('error', 'Image must be under 5MB');
                return;
            }
            
            // Show preview immediately
            var reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                dropzone.classList.add('has-photo');
                removeBtn.style.display = '';
            };
            reader.readAsDataURL(file);
            
            // Upload if auto-save enabled
            if (autoSave && trainerId) {
                uploadPhoto(file);
            }
        }
        
        function uploadPhoto(file) {
            dropzone.classList.add('uploading');
            setProgress(0);
            
            var formData = new FormData();
            formData.append('action', 'ptp_upload_trainer_photo');
            formData.append('photo', file);
            formData.append('trainer_id', trainerId);
            formData.append('nonce', '<?php echo $nonce; ?>');
            
            var xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round((e.loaded / e.total) * 100);
                    setProgress(percent);
                }
            });
            
            xhr.addEventListener('load', function() {
                dropzone.classList.remove('uploading');
                
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        if (response.data?.url) {
                            preview.src = response.data.url;
                        }
                        showStatus('success', 'Photo saved!');
                    } else {
                        showStatus('error', response.data?.message || 'Upload failed');
                        // Revert preview if upload failed
                        if (!response.data?.url) {
                            preview.src = '';
                            dropzone.classList.remove('has-photo');
                            removeBtn.style.display = 'none';
                        }
                    }
                } catch (e) {
                    showStatus('error', 'Upload failed. Please try again.');
                }
            });
            
            xhr.addEventListener('error', function() {
                dropzone.classList.remove('uploading');
                showStatus('error', 'Network error. Please try again.');
            });
            
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
            xhr.send(formData);
        }
        
        function setProgress(percent) {
            var circumference = 125.6; // 2 * PI * 20
            var offset = circumference - (percent / 100 * circumference);
            progressCircle.style.strokeDashoffset = offset;
            progressText.textContent = percent + '%';
        }
        
        function showStatus(type, message) {
            statusEl.className = 'ptp-photo-status visible ' + type;
            statusEl.innerHTML = (type === 'success' 
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            ) + '<span>' + message + '</span>';
            
            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(function() {
                    statusEl.classList.remove('visible');
                }, 3000);
            }
        }
    })();
    </script>
    <?php
}

/**
 * AJAX handler for photo upload
 */
add_action('wp_ajax_ptp_upload_trainer_photo', 'ptp_ajax_upload_trainer_photo');
function ptp_ajax_upload_trainer_photo() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_photo_upload')) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }
    
    $trainer_id = intval($_POST['trainer_id'] ?? 0);
    if (!$trainer_id) {
        wp_send_json_error(array('message' => 'Invalid trainer'));
    }
    
    // Verify user has permission
    $trainer = PTP_Trainer::get($trainer_id);
    if (!$trainer || $trainer->user_id != get_current_user_id()) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    // Handle upload
    if (empty($_FILES['photo'])) {
        wp_send_json_error(array('message' => 'No file uploaded'));
    }
    
    $file = $_FILES['photo'];
    
    // Validate file type (server-side, not client-reported MIME)
    $allowed_image_types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    $file_check = wp_check_filetype(basename($file['name']), $allowed_image_types);
    if (empty($file_check['ext'])) {
        wp_send_json_error(array('message' => 'Invalid file type. Please upload JPG, PNG, or WebP.'));
    }
    
    // Validate file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        wp_send_json_error(array('message' => 'File too large. Maximum size is 5MB.'));
    }
    
    // Upload to WordPress media library
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $attachment_id = media_handle_upload('photo', 0);
    
    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => $attachment_id->get_error_message()));
    }
    
    // v214: Crop to square (center-crop) then resize for profile photo
    $file_path = get_attached_file($attachment_id);
    if ($file_path && file_exists($file_path)) {
        $editor = wp_get_image_editor($file_path);
        if (!is_wp_error($editor)) {
            $size = $editor->get_size();
            if ($size) {
                $w = $size['width'];
                $h = $size['height'];
                
                // Center-crop to square if not already square
                if ($w !== $h) {
                    $crop_size = min($w, $h);
                    $crop_x = intval(($w - $crop_size) / 2);
                    $crop_y = intval(($h - $crop_size) / 2);
                    $editor->crop($crop_x, $crop_y, $crop_size, $crop_size);
                }
                
                // Resize to 800x800 max
                $editor->resize(800, 800, true);
                $editor->set_quality(85);
                $saved = $editor->save($file_path);
                if (!is_wp_error($saved)) {
                    wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));
                }
            }
        }
    }
    
    // Get the URL
    $url = wp_get_attachment_url($attachment_id);
    
    // Update trainer
    global $wpdb;
    $table = $wpdb->prefix . 'ptp_trainers';
    $wpdb->update($table, array('photo_url' => $url), array('id' => $trainer_id));
    
    wp_send_json_success(array(
        'url' => $url,
        'attachment_id' => $attachment_id
    ));
}

/**
 * AJAX handler for photo removal
 */
add_action('wp_ajax_ptp_remove_trainer_photo', 'ptp_ajax_remove_trainer_photo');
function ptp_ajax_remove_trainer_photo() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_photo_upload')) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }
    
    $trainer_id = intval($_POST['trainer_id'] ?? 0);
    if (!$trainer_id) {
        wp_send_json_error(array('message' => 'Invalid trainer'));
    }
    
    $trainer = PTP_Trainer::get($trainer_id);
    if (!$trainer || $trainer->user_id != get_current_user_id()) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'ptp_trainers';
    $wpdb->update($table, array('photo_url' => ''), array('id' => $trainer_id));
    
    wp_send_json_success();
}

/**
 * v168: AJAX handler for camp announcement photo upload
 * This uploads the photo temporarily during checkout
 * The photo URL is stored in order_items when order is created
 */
add_action('wp_ajax_ptp_upload_camp_announcement_photo', 'ptp_ajax_upload_camp_announcement_photo');
add_action('wp_ajax_nopriv_ptp_upload_camp_announcement_photo', 'ptp_ajax_upload_camp_announcement_photo');
function ptp_ajax_upload_camp_announcement_photo() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_camp_photo_upload')) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }
    
    // Handle upload
    if (empty($_FILES['photo'])) {
        wp_send_json_error(array('message' => 'No file uploaded'));
    }
    
    $file = $_FILES['photo'];
    
    // Validate file type (server-side, not client-reported MIME)
    $allowed_image_types = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    $file_check = wp_check_filetype(basename($file['name']), $allowed_image_types);
    if (empty($file_check['ext'])) {
        wp_send_json_error(array('message' => 'Invalid file type. Please upload JPG, PNG, or WebP.'));
    }
    
    // Validate file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        wp_send_json_error(array('message' => 'File too large. Maximum size is 5MB.'));
    }
    
    // Upload to WordPress media library
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $attachment_id = media_handle_upload('photo', 0);
    
    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => $attachment_id->get_error_message()));
    }
    
    // Get the URL
    $url = wp_get_attachment_url($attachment_id);
    
    // Add metadata to identify this as a camp announcement photo
    update_post_meta($attachment_id, '_ptp_camp_announcement_photo', 1);
    update_post_meta($attachment_id, '_ptp_upload_timestamp', current_time('mysql'));
    
    wp_send_json_success(array(
        'url' => $url,
        'attachment_id' => $attachment_id
    ));
}
