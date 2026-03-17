/**
 * PTP App Control Center JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize color pickers
    $('.ptp-color-picker').wpColorPicker({
        change: function(event, ui) {
            updateColorPreview();
        }
    });
    
    // Update color preview
    function updateColorPreview() {
        var primary = $('input[name="ptp_color_primary"]').val();
        var secondary = $('input[name="ptp_color_secondary"]').val();
        
        $('.preview-button.primary').css({
            'background-color': primary,
            'border-color': secondary,
            'color': secondary
        });
        
        $('.preview-button.secondary').css({
            'border-color': secondary,
            'color': secondary
        });
        
        $('.preview-card').css({
            'border-color': secondary,
            'box-shadow': '4px 4px 0 ' + primary
        });
    }
    
    // Media uploader
    $('.ptp-upload-btn').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var targetInput = $('#' + button.data('target'));
        var previewImg = $('#' + button.data('preview'));
        
        var frame = wp.media({
            title: 'Select Image',
            button: { text: 'Use This Image' },
            multiple: false
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            targetInput.val(attachment.url);
            previewImg.attr('src', attachment.url);
        });
        
        frame.open();
    });
    
    // Onboarding screens sortable
    $('#onboarding-screens').sortable({
        handle: '.screen-handle',
        placeholder: 'ptp-onboarding-screen ui-sortable-placeholder',
        update: function() {
            updateScreenIndexes();
        }
    });
    
    function updateScreenIndexes() {
        $('#onboarding-screens .ptp-onboarding-screen').each(function(i) {
            $(this).attr('data-index', i);
        });
    }
    
    // Screen image upload
    $(document).on('click', '.screen-upload-btn', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var screen = button.closest('.ptp-onboarding-screen');
        var imageInput = screen.find('.screen-image-url');
        var previewImg = screen.find('.screen-image');
        
        var frame = wp.media({
            title: 'Select Screen Image',
            button: { text: 'Use This Image' },
            multiple: false
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            imageInput.val(attachment.url);
            previewImg.attr('src', attachment.url);
            updatePhonePreview(0);
        });
        
        frame.open();
    });
    
    // Remove screen
    $(document).on('click', '.screen-remove', function(e) {
        e.preventDefault();
        if (confirm('Remove this onboarding screen?')) {
            $(this).closest('.ptp-onboarding-screen').remove();
            updateScreenIndexes();
        }
    });
    
    // Add new screen
    $('#add-onboarding-screen').on('click', function() {
        var index = $('#onboarding-screens .ptp-onboarding-screen').length;
        var html = `
            <div class="ptp-onboarding-screen" data-index="${index}">
                <div class="screen-handle">
                    <span class="dashicons dashicons-menu"></span>
                </div>
                <div class="screen-preview">
                    <img src="https://via.placeholder.com/160x280?text=Add+Image" class="screen-image">
                </div>
                <div class="screen-fields">
                    <input type="text" class="screen-title" placeholder="Title" value="">
                    <textarea class="screen-subtitle" placeholder="Subtitle"></textarea>
                    <input type="text" class="screen-button" placeholder="Button Text" value="Next">
                    <input type="hidden" class="screen-image-url" value="">
                    <button type="button" class="button screen-upload-btn">Change Image</button>
                </div>
                <div class="screen-actions">
                    <button type="button" class="button screen-remove" title="Remove"><span class="dashicons dashicons-trash"></span></button>
                </div>
            </div>
        `;
        $('#onboarding-screens').append(html);
    });
    
    // Update phone preview when screen fields change
    $(document).on('change keyup', '.screen-title, .screen-subtitle, .screen-button', function() {
        var screen = $(this).closest('.ptp-onboarding-screen');
        var index = screen.data('index');
        if (index === 0) {
            updatePhonePreview(0);
        }
    });
    
    function updatePhonePreview(index) {
        var screen = $('#onboarding-screens .ptp-onboarding-screen').eq(index);
        if (!screen.length) return;
        
        var title = screen.find('.screen-title').val();
        var subtitle = screen.find('.screen-subtitle').val();
        var button = screen.find('.screen-button').val();
        var image = screen.find('.screen-image-url').val();
        
        if (image) {
            $('#phone-preview-screen .preview-bg').attr('src', image);
        }
        $('#phone-preview-screen .preview-title').text(title);
        $('#phone-preview-screen .preview-subtitle').text(subtitle);
        $('#phone-preview-screen .preview-btn').text(button);
    }
    
    // Save onboarding screens
    $('#save-onboarding').on('click', function() {
        var screens = [];
        
        $('#onboarding-screens .ptp-onboarding-screen').each(function() {
            screens.push({
                id: 'screen_' + $(this).data('index'),
                title: $(this).find('.screen-title').val(),
                subtitle: $(this).find('.screen-subtitle').val(),
                button_text: $(this).find('.screen-button').val(),
                image: $(this).find('.screen-image-url').val()
            });
        });
        
        var $status = $('#onboarding-save-status');
        $status.text('Saving...').css('color', '#666');
        
        $.post(ptpAppControl.ajaxUrl, {
            action: 'ptp_save_app_onboarding_screens',
            nonce: ptpAppControl.nonce,
            screens: screens
        }, function(response) {
            if (response.success) {
                $status.text('✓ Saved!').css('color', '#16a34a');
            } else {
                $status.text('✗ Error: ' + response.data).css('color', '#dc3545');
            }
            setTimeout(function() { $status.text(''); }, 3000);
        });
    });
    
    // Push notification form
    $('#push-notification-form').on('submit', function(e) {
        e.preventDefault();
        
        var $btn = $('#send-push-btn');
        var $result = $('#push-result');
        
        $btn.prop('disabled', true).text('Sending...');
        
        $.post(ptpAppControl.ajaxUrl, {
            action: 'ptp_send_push_notification',
            nonce: ptpAppControl.nonce,
            title: $('#push-title').val(),
            message: $('#push-message').val(),
            audience: $('#push-audience').val(),
            link: $('#push-link').val()
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-megaphone"></span> Send Push Notification');
            
            if (response.success) {
                $result.removeClass('notice-error').addClass('notice notice-success')
                    .html('<p>✓ Notification sent to ' + response.data.sent + ' devices!</p>')
                    .show();
                    
                // Clear form
                $('#push-title, #push-message, #push-link').val('');
            } else {
                $result.removeClass('notice-success').addClass('notice notice-error')
                    .html('<p>✗ Error: ' + response.data + '</p>')
                    .show();
            }
            
            setTimeout(function() { $result.fadeOut(); }, 5000);
        });
    });
    
    // Initialize Google Maps for test
    if (typeof google !== 'undefined' && $('#test-map').length) {
        var testMap = new google.maps.Map(document.getElementById('test-map'), {
            center: { lat: 39.9526, lng: -75.1652 },
            zoom: 10,
            styles: [
                { featureType: 'poi', stylers: [{ visibility: 'off' }] }
            ]
        });
        
        // Add PTP marker
        new google.maps.Marker({
            position: { lat: 39.9526, lng: -75.1652 },
            map: testMap,
            title: 'Philadelphia'
        });
    }
    
    // Camp form - Google Maps
    if (typeof google !== 'undefined' && $('#camp-map').length) {
        var campLat = parseFloat($('#camp-lat').val()) || 39.9526;
        var campLng = parseFloat($('#camp-lng').val()) || -75.1652;
        
        var campMap = new google.maps.Map(document.getElementById('camp-map'), {
            center: { lat: campLat, lng: campLng },
            zoom: 13
        });
        
        var campMarker = new google.maps.Marker({
            position: { lat: campLat, lng: campLng },
            map: campMap,
            draggable: true
        });
        
        // Update coords when marker dragged
        google.maps.event.addListener(campMarker, 'dragend', function() {
            var pos = campMarker.getPosition();
            $('#camp-lat').val(pos.lat());
            $('#camp-lng').val(pos.lng());
        });
        
        // Places autocomplete
        var addressInput = document.getElementById('camp-address');
        if (addressInput) {
            var autocomplete = new google.maps.places.Autocomplete(addressInput, {
                componentRestrictions: { country: 'us' },
                fields: ['address_components', 'geometry', 'formatted_address']
            });
            
            autocomplete.addListener('place_changed', function() {
                var place = autocomplete.getPlace();
                
                if (place.geometry) {
                    var lat = place.geometry.location.lat();
                    var lng = place.geometry.location.lng();
                    
                    $('#camp-lat').val(lat);
                    $('#camp-lng').val(lng);
                    
                    campMap.setCenter({ lat: lat, lng: lng });
                    campMarker.setPosition({ lat: lat, lng: lng });
                    
                    // Parse address components
                    place.address_components.forEach(function(comp) {
                        if (comp.types.includes('locality')) {
                            $('input[name="city"]').val(comp.long_name);
                        }
                        if (comp.types.includes('administrative_area_level_1')) {
                            $('select[name="state"]').val(comp.short_name);
                        }
                        if (comp.types.includes('postal_code')) {
                            $('input[name="zip"]').val(comp.short_name);
                        }
                    });
                }
            });
        }
    }
    
    // Camp image upload
    $(document).on('click', '.ptp-camps-admin .ptp-upload-btn', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var targetInput = $('#' + button.data('target'));
        var previewImg = $('#' + button.data('preview'));
        
        var frame = wp.media({
            title: 'Select Camp Image',
            button: { text: 'Use This Image' },
            multiple: false
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            targetInput.val(attachment.id);
            previewImg.attr('src', attachment.url);
        });
        
        frame.open();
    });
    
})(jQuery);
