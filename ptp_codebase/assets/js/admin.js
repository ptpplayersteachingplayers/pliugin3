/* PTP Training Platform - Admin JavaScript */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Confirm dangerous actions
        $('a[href*="action=deactivate"], a[href*="action=reject"]').on('click', function(e) {
            if (!confirm('Are you sure you want to perform this action?')) {
                e.preventDefault();
            }
        });

        // Process payout confirmation
        $('a[href*="action=process"]').on('click', function(e) {
            if (!confirm('Are you sure you want to process this payout? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

})(jQuery);
