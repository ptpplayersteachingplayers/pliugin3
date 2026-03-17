/**
 * PTP Nonce Refresh System
 * Prevents form submissions from failing after long idle periods.
 * Silently refreshes the nonce every 30 minutes.
 */
(function($) {
    'use strict';

    if (typeof ptpNonceRefresh === 'undefined') return;

    var config = ptpNonceRefresh;
    var lastRefresh = Date.now();

    function refreshNonce() {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: { action: 'ptp_refresh_nonce' },
            success: function(response) {
                if (response.success && response.data && response.data.nonce) {
                    // Update the global nonce
                    config.nonce = response.data.nonce;

                    // Update ptp_ajax if it exists
                    if (typeof ptp_ajax !== 'undefined') {
                        ptp_ajax.nonce = response.data.nonce;
                    }

                    // Update any hidden nonce fields on the page
                    $('input[name="nonce"], input[name="_wpnonce"], input[name="ptp_nonce"]').each(function() {
                        $(this).val(response.data.nonce);
                    });

                    lastRefresh = Date.now();
                }
            }
        });
    }

    // Refresh on interval
    setInterval(function() {
        refreshNonce();
    }, config.refreshInterval || 1800000); // 30 minutes default

    // Also refresh when user returns to tab after being away
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            var elapsed = Date.now() - lastRefresh;
            // If more than 10 minutes have passed, refresh immediately
            if (elapsed > 600000) {
                refreshNonce();
            }
        }
    });

    // Force refresh if page has been open too long
    if (config.maxAge) {
        var pageLoadTime = Date.now();
        setInterval(function() {
            if (Date.now() - pageLoadTime > config.maxAge) {
                refreshNonce();
                pageLoadTime = Date.now(); // Reset
            }
        }, 60000); // Check every minute
    }

})(jQuery);
