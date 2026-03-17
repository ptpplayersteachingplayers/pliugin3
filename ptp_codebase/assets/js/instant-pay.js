/**
 * PTP Instant Pay
 * Trainer earnings dashboard and payouts
 * @version 1.0.0
 */

(function($) {
    'use strict';

    window.ptpInstantPay = {
        
        // Configuration
        config: {
            ajaxUrl: typeof ptpInstantPay !== 'undefined' ? ptpInstantPay.ajaxUrl : '/wp-admin/admin-ajax.php',
            nonce: typeof ptpInstantPay !== 'undefined' ? ptpInstantPay.nonce : '',
            minPayout: typeof ptpInstantPay !== 'undefined' ? ptpInstantPay.minPayout : 1,
            instantFee: typeof ptpInstantPay !== 'undefined' ? ptpInstantPay.instantFee : 1
        },

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initCharts();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;

            // Period selector change
            $(document).on('change', '#earnings-period', function() {
                self.updateChart($(this).val());
            });

            // Settings changes
            $(document).on('change', '#auto-payout, #payout-threshold', function() {
                self.updateSettings();
            });

            // Stripe connect button
            $(document).on('click', '[data-action="stripe-connect"]', function(e) {
                e.preventDefault();
                self.startStripeConnect();
            });

            // Stripe dashboard button
            $(document).on('click', '[data-action="stripe-dashboard"]', function(e) {
                e.preventDefault();
                self.openStripeDashboard();
            });
        },

        /**
         * Initialize charts
         */
        initCharts: function() {
            // Charts are rendered server-side, but we can enhance them here
            this.animateChartBars();
        },

        /**
         * Animate chart bars on load
         */
        animateChartBars: function() {
            $('.ptp-earnings-chart__fill').each(function(i) {
                var $bar = $(this);
                var height = $bar.css('height');
                
                $bar.css('height', '4px');
                
                setTimeout(function() {
                    $bar.css('height', height);
                }, i * 100);
            });
        },

        /**
         * Request instant payout
         */
        requestPayout: function(amount) {
            var self = this;
            
            amount = amount || 0;
            
            if (amount < this.config.minPayout) {
                this.showToast('Minimum payout is $' + this.config.minPayout, 'error');
                return;
            }
            
            // Calculate net after fee
            var netAmount = amount - this.config.instantFee;
            
            // Confirm with user
            var confirmMsg = 'Request instant payout of $' + amount.toFixed(2) + '?\n\n' +
                'Fee: $' + this.config.instantFee.toFixed(2) + '\n' +
                'You\'ll receive: $' + netAmount.toFixed(2);
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Show loading
            var $btn = $('.ptp-instant-payout-btn');
            var originalText = $btn.html();
            $btn.html('<span class="ptp-loading__spinner" style="width:20px;height:20px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:8px;"></span> Processing...').prop('disabled', true);
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_request_instant_payout',
                    nonce: this.config.nonce,
                    amount: amount
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast(response.data.message, 'success');
                        
                        // Confetti effect
                        self.showConfetti();
                        
                        // Refresh page after delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        self.showToast(response.data || 'Error processing payout', 'error');
                        $btn.html(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    self.showToast('Network error. Please try again.', 'error');
                    $btn.html(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Update earnings chart
         */
        updateChart: function(period) {
            var self = this;
            var $chart = $('#earnings-chart');
            
            // Show loading state
            $chart.css('opacity', '0.5');
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_get_earnings_breakdown',
                    nonce: this.config.nonce,
                    period: period
                },
                success: function(response) {
                    if (response.success) {
                        self.renderChart(response.data.breakdown);
                    }
                    $chart.css('opacity', '1');
                },
                error: function() {
                    $chart.css('opacity', '1');
                }
            });
        },

        /**
         * Render chart with new data
         */
        renderChart: function(data) {
            var $chart = $('#earnings-chart');
            
            if (!data || !data.length) {
                $chart.html('<p style="text-align:center;color:#666;padding:40px;">No earnings data</p>');
                return;
            }
            
            // Find max value for scaling
            var maxAmount = Math.max.apply(Math, data.map(function(d) { return d.amount; })) || 1;
            
            var html = '';
            data.forEach(function(item) {
                var height = Math.max(4, (item.amount / maxAmount) * 160);
                
                html += '<div class="ptp-earnings-chart__bar">';
                if (item.amount > 0) {
                    html += '<span class="ptp-earnings-chart__value">$' + item.amount.toFixed(0) + '</span>';
                }
                html += '<div class="ptp-earnings-chart__fill" style="height:' + height + 'px;"></div>';
                html += '<span class="ptp-earnings-chart__label">' + item.label + '</span>';
                html += '</div>';
            });
            
            $chart.html(html);
            
            // Animate
            setTimeout(function() {
                $('.ptp-earnings-chart__fill').each(function(i) {
                    var $bar = $(this);
                    var height = $bar.css('height');
                    $bar.css('height', '4px');
                    setTimeout(function() {
                        $bar.css('height', height);
                    }, i * 50);
                });
            }, 10);
        },

        /**
         * Update payout settings
         */
        updateSettings: function() {
            var self = this;
            
            var autoEnabled = $('#auto-payout').is(':checked');
            var threshold = parseFloat($('#payout-threshold').val()) || 50;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_update_payout_schedule',
                    nonce: this.config.nonce,
                    auto_enabled: autoEnabled ? 1 : 0,
                    threshold: threshold
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast('Settings updated!', 'success');
                    }
                }
            });
        },

        /**
         * Start Stripe Connect onboarding
         */
        startStripeConnect: function() {
            var self = this;
            
            var $btn = $('[data-action="stripe-connect"]');
            $btn.text('Connecting...').prop('disabled', true);
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_start_stripe_connect',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success && response.data.url) {
                        window.location.href = response.data.url;
                    } else {
                        self.showToast(response.data || 'Error connecting to Stripe', 'error');
                        $btn.text('Connect Bank Account').prop('disabled', false);
                    }
                },
                error: function() {
                    self.showToast('Network error. Please try again.', 'error');
                    $btn.text('Connect Bank Account').prop('disabled', false);
                }
            });
        },

        /**
         * Open Stripe Dashboard
         */
        openStripeDashboard: function() {
            var self = this;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_get_stripe_dashboard_link',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success && response.data.url) {
                        window.open(response.data.url, '_blank');
                    } else {
                        self.showToast(response.data || 'Error opening Stripe dashboard', 'error');
                    }
                }
            });
        },

        /**
         * Load earnings data
         */
        loadEarningsData: function(period, callback) {
            var self = this;
            period = period || 'all';
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_get_earnings_data',
                    nonce: this.config.nonce,
                    period: period
                },
                success: function(response) {
                    if (response.success && callback) {
                        callback(response.data);
                    }
                }
            });
        },

        /**
         * Load payout history
         */
        loadPayoutHistory: function(callback) {
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_get_payout_history',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success && callback) {
                        callback(response.data.history);
                    }
                }
            });
        },

        /**
         * Show confetti effect
         */
        showConfetti: function() {
            // Simple confetti using CSS
            var colors = ['#FCB900', '#10B981', '#3B82F6', '#EF4444', '#8B5CF6'];
            var $container = $('<div class="ptp-confetti-container" style="position:fixed;inset:0;pointer-events:none;z-index:700;overflow:hidden;"></div>');
            $('body').append($container);
            
            for (var i = 0; i < 50; i++) {
                var $confetti = $('<div style="position:absolute;width:10px;height:10px;background:' + colors[i % colors.length] + ';' +
                    'left:' + Math.random() * 100 + '%;top:-20px;border-radius:2px;' +
                    'animation:confettiFall ' + (2 + Math.random() * 2) + 's ease-out forwards;' +
                    'animation-delay:' + (Math.random() * 0.5) + 's;"></div>');
                $container.append($confetti);
            }
            
            // Add animation keyframes if not exists
            if (!$('#ptp-confetti-style').length) {
                $('head').append('<style id="ptp-confetti-style">' +
                    '@keyframes confettiFall {' +
                        '0% { transform: translateY(0) rotate(0deg); opacity: 1; }' +
                        '100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }' +
                    '}' +
                '</style>');
            }
            
            // Remove after animation
            setTimeout(function() {
                $container.remove();
            }, 4000);
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type) {
            type = type || 'info';
            
            var $existing = $('.ptp-pay-toast');
            if ($existing.length) {
                $existing.remove();
            }
            
            var bgColor = type === 'success' ? '#10B981' : type === 'error' ? '#EF4444' : '#0A0A0A';
            var icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
            
            var $toast = $('<div class="ptp-pay-toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);' +
                'background:' + bgColor + ';color:#fff;padding:14px 24px;border-radius:8px;font-weight:600;z-index:700;' +
                'opacity:0;transition:all 0.3s;box-shadow:0 4px 20px rgba(0,0,0,0.2);">' +
                icon + ' ' + message +
            '</div>');
            
            $('body').append($toast);
            
            setTimeout(function() {
                $toast.css({
                    transform: 'translateX(-50%) translateY(0)',
                    opacity: 1
                });
            }, 10);
            
            setTimeout(function() {
                $toast.css({
                    transform: 'translateX(-50%) translateY(100px)',
                    opacity: 0
                });
                setTimeout(function() { $toast.remove(); }, 300);
            }, 4000);
        },

        /**
         * Format currency
         */
        formatCurrency: function(amount) {
            return '$' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }
    };

    // Initialize on DOM ready
    $(function() {
        ptpInstantPay.init();
    });

})(jQuery);
