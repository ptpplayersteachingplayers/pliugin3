/**
 * PTP Camp Admin Scripts
 * @version 146.0.0
 */

(function($) {
    'use strict';

    // Config from localized script
    const config = window.ptpCampAdmin || {};

    $(document).ready(function() {
        // Initialize all handlers
        initModals();
        initExport();
        initSync();
    });

    /**
     * Initialize modal handlers
     */
    function initModals() {
        // Close modal on click outside or close button
        $(document).on('click', '.ptp-modal-close, .ptp-modal', function(e) {
            if (e.target === this) {
                $('.ptp-modal').hide();
            }
        });

        // Close modal on escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('.ptp-modal').hide();
            }
        });
    }

    /**
     * Initialize export functionality
     */
    function initExport() {
        $('#btn-export-orders').on('click', function(e) {
            e.preventDefault();
            $('#export-modal').show();
        });

        $('#export-form').on('submit', function(e) {
            e.preventDefault();
            
            const $btn = $(this).find('button[type="submit"]');
            $btn.text('Exporting...').prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ptp_export_camp_orders',
                    nonce: config.nonce,
                    status: $('[name="export_status"]').val(),
                    date_from: $('[name="date_from"]').val(),
                    date_to: $('[name="date_to"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        // Create and download CSV file
                        const blob = new Blob([response.data.csv], { type: 'text/csv;charset=utf-8;' });
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(url);
                        
                        $('#export-modal').hide();
                    } else {
                        alert('Export failed: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Export failed. Please try again.');
                },
                complete: function() {
                    $btn.text('Download CSV').prop('disabled', false);
                }
            });
        });
    }

    /**
     * Initialize Stripe sync functionality
     */
    function initSync() {
        $('#btn-sync-stripe').on('click', function() {
            const $btn = $(this);
            $btn.text('Syncing...').prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ptp_sync_stripe_products',
                    nonce: config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Sync failed: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Sync failed. Please check your Stripe API keys.');
                },
                complete: function() {
                    $btn.text('Sync from Stripe').prop('disabled', false);
                }
            });
        });
    }

    /**
     * Utility: Format currency
     */
    function formatCurrency(amount) {
        return '$' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }

    /**
     * Utility: Format date
     */
    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

})(jQuery);
