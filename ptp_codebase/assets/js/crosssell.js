/**
 * PTP Cross-Sell & Upsell Engine
 * Mobile-first, conversion-optimized
 * @version 1.0.0
 */

(function($) {
    'use strict';

    window.ptpCrosssell = {
        
        // Configuration
        config: {
            ajaxUrl: typeof ptpCrosssell !== 'undefined' ? ptpCrosssell.ajaxUrl : '/wp-admin/admin-ajax.php',
            nonce: typeof ptpCrosssell !== 'undefined' ? ptpCrosssell.nonce : '',
            discounts: {
                bundle: 15,
                package3: 10,
                package5: 15,
                package10: 20
            }
        },

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initCarousels();
            this.checkUrlParams();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Package selection
            $(document).on('click', '.ptp-package-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var packageId = $btn.data('package');
                var trainerId = $btn.data('trainer');
                
                if (packageId && trainerId) {
                    self.selectPackage(packageId, trainerId);
                }
            });

            // Bundle creation
            $(document).on('click', '[data-action="create-bundle"]', function(e) {
                e.preventDefault();
                var trainerId = $(this).data('trainer-id');
                var campId = $(this).data('camp-id');
                
                if (trainerId && campId) {
                    self.createBundle(trainerId, campId);
                }
            });

            // Recommendation card clicks - tracking
            $(document).on('click', '.ptp-rec-card', function() {
                var $card = $(this);
                self.trackClick(
                    $card.data('type'),
                    $card.data('id'),
                    $card.closest('[data-context]').data('context') || 'unknown'
                );
            });

            // Modal close
            $(document).on('click', '.ptp-crosssell-modal__close, .ptp-crosssell-modal', function(e) {
                if (e.target === this) {
                    self.closeModal();
                }
            });

            // Escape key closes modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    self.closeModal();
                }
            });
        },

        /**
         * Initialize carousels
         */
        initCarousels: function() {
            var self = this;

            $('.ptp-carousel').each(function() {
                var $carousel = $(this);
                var $dots = $carousel.siblings('.ptp-carousel-dots');
                
                if (!$dots.length) {
                    var cardCount = Math.min($carousel.find('.ptp-rec-card').length, 6);
                    var dotsHtml = '<div class="ptp-carousel-dots">';
                    for (var i = 0; i < cardCount; i++) {
                        dotsHtml += '<span' + (i === 0 ? ' class="active"' : '') + '></span>';
                    }
                    dotsHtml += '</div>';
                    $carousel.after(dotsHtml);
                    $dots = $carousel.siblings('.ptp-carousel-dots');
                }

                $carousel.on('scroll', function() {
                    self.updateCarouselDots($carousel, $dots);
                });
            });
        },

        /**
         * Update carousel dots
         */
        updateCarouselDots: function($carousel, $dots) {
            var scrollLeft = $carousel.scrollLeft();
            var cardWidth = $carousel.find('.ptp-rec-card').first().outerWidth(true) || 236;
            var index = Math.round(scrollLeft / cardWidth);
            
            $dots.find('span').removeClass('active').eq(index).addClass('active');
        },

        /**
         * Check URL parameters for bundle/package
         */
        checkUrlParams: function() {
            var params = new URLSearchParams(window.location.search);
            
            if (params.get('bundle')) {
                this.showToast('Bundle discount applied!', 'success');
            }
            
            if (params.get('package')) {
                var sessions = params.get('package');
                this.showToast(sessions + '-session package selected!', 'success');
            }
        },

        /**
         * Select package
         */
        selectPackage: function(packageId, trainerId) {
            var self = this;
            
            $('.ptp-package-btn').removeClass('selected');
            $('[data-package="' + packageId + '"]').addClass('selected');

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_apply_package_upgrade',
                    nonce: this.config.nonce,
                    trainer_id: trainerId,
                    package: packageId
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast(response.data.message, 'success');
                        
                        if (response.data.redirect) {
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1000);
                        }
                    } else {
                        self.showToast(response.data || 'Error selecting package', 'error');
                    }
                },
                error: function() {
                    self.showToast('Network error. Please try again.', 'error');
                }
            });
        },

        /**
         * Create bundle
         */
        createBundle: function(trainerId, campId, sessions) {
            var self = this;
            sessions = sessions || 1;

            this.showLoading();

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_create_bundle',
                    nonce: this.config.nonce,
                    trainer_id: trainerId,
                    camp_id: campId,
                    sessions: sessions
                },
                success: function(response) {
                    self.hideLoading();
                    
                    if (response.success) {
                        self.showToast(response.data.message, 'success');
                        
                        if (response.data.redirect) {
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1200);
                        }
                    } else {
                        self.showToast(response.data || 'Error creating bundle', 'error');
                    }
                },
                error: function() {
                    self.hideLoading();
                    self.showToast('Network error. Please try again.', 'error');
                }
            });
        },

        /**
         * Load recommendations
         */
        loadRecommendations: function(context, callback) {
            var self = this;

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_get_recommendations',
                    nonce: this.config.nonce,
                    trainer_id: context.trainerId || '',
                    location: context.location || '',
                    just_booked: context.justBooked || '',
                    limit: context.limit || 4
                },
                success: function(response) {
                    if (response.success && callback) {
                        callback(response.data.recommendations);
                    }
                }
            });
        },

        /**
         * Track click
         */
        trackClick: function(type, id, context) {
            if (!type || !id) return;

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_track_crosssell_click',
                    source_type: 'crosssell',
                    target_type: type,
                    target_id: id,
                    context: context
                }
            });

            // Google Analytics
            if (typeof gtag === 'function') {
                gtag('event', 'select_item', {
                    item_list_name: 'crosssell_' + context,
                    items: [{
                        item_id: id,
                        item_category: type
                    }]
                });
            }
        },

        /**
         * Show modal
         */
        showModal: function(title, content) {
            var $modal = $('#ptp-crosssell-modal');
            
            if (!$modal.length) {
                $modal = $('<div id="ptp-crosssell-modal" class="ptp-crosssell-modal">' +
                    '<div class="ptp-crosssell-modal__content">' +
                        '<div class="ptp-crosssell-modal__header">' +
                            '<h3 class="ptp-crosssell-modal__title"></h3>' +
                            '<button class="ptp-crosssell-modal__close">&times;</button>' +
                        '</div>' +
                        '<div class="ptp-crosssell-modal__body"></div>' +
                    '</div>' +
                '</div>');
                $('body').append($modal);
            }

            $modal.find('.ptp-crosssell-modal__title').text(title);
            $modal.find('.ptp-crosssell-modal__body').html(content);
            
            $modal.addClass('active');
            $('body').css('overflow', 'hidden');
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('.ptp-crosssell-modal').removeClass('active');
            $('body').css('overflow', '');
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type) {
            type = type || 'info';
            
            var $toast = $('.ptp-toast');
            
            if (!$toast.length) {
                $toast = $('<div class="ptp-toast"></div>');
                $('body').append($toast);
            }

            $toast.removeClass('show ptp-toast--success ptp-toast--error ptp-toast--warning');
            
            var icon = '';
            if (type === 'success') icon = '✓ ';
            if (type === 'error') icon = '✕ ';
            if (type === 'warning') icon = '⚠ ';
            
            $toast.html(icon + message)
                  .addClass('ptp-toast--' + type);

            setTimeout(function() {
                $toast.addClass('show');
            }, 10);

            setTimeout(function() {
                $toast.removeClass('show');
            }, 3500);
        },

        /**
         * Show loading overlay
         */
        showLoading: function() {
            if (!$('.ptp-loading-overlay').length) {
                $('body').append(
                    '<div class="ptp-loading-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:400;display:flex;align-items:center;justify-content:center;">' +
                        '<div class="ptp-loading__spinner"></div>' +
                    '</div>'
                );
            }
        },

        /**
         * Hide loading overlay
         */
        hideLoading: function() {
            $('.ptp-loading-overlay').remove();
        },

        /**
         * Format currency
         */
        formatCurrency: function(amount) {
            return '$' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        },

        /**
         * Calculate savings
         */
        calculateSavings: function(originalPrice, discountPercent) {
            return originalPrice * (discountPercent / 100);
        },

        /**
         * Render recommendation cards
         */
        renderCards: function(recommendations, container) {
            var html = '<div class="ptp-recommendations">';
            
            recommendations.forEach(function(rec) {
                html += '<a href="' + rec.url + '" class="ptp-rec-card" data-type="' + rec.type + '" data-id="' + rec.id + '">';
                
                if (rec.image) {
                    html += '<div class="ptp-rec-card__image">';
                    html += '<img src="' + rec.image + '" alt="" loading="lazy">';
                    if (rec.badge) {
                        html += '<span class="ptp-rec-card__badge">' + rec.badge + '</span>';
                    }
                    html += '</div>';
                }
                
                html += '<div class="ptp-rec-card__content">';
                html += '<h4 class="ptp-rec-card__title">' + rec.name + '</h4>';
                
                html += '<div class="ptp-rec-card__footer">';
                html += '<span class="ptp-rec-card__price">' + rec.formatted_price + '</span>';
                
                if (rec.rating) {
                    html += '<span class="ptp-rec-card__rating">⭐ ' + rec.rating.toFixed(1) + '</span>';
                }
                
                html += '</div></div></a>';
            });
            
            html += '</div>';
            
            $(container).html(html);
        }
    };

    // Initialize on DOM ready
    $(function() {
        ptpCrosssell.init();
    });

})(jQuery);
