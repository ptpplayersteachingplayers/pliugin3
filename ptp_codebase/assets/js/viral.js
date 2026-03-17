/**
 * PTP Viral Engine
 * Social sharing, referrals, and social proof
 * @version 1.0.1
 */

(function($) {
    'use strict';

    // Check if WordPress localized the script data
    var wpData = (typeof window.ptpViralData !== 'undefined') ? window.ptpViralData : 
                 (typeof ptpViral !== 'undefined' && ptpViral.ajaxUrl) ? ptpViral : null;
    
    window.ptpViral = {
        
        // Configuration - use localized data or fallbacks
        config: {
            ajaxUrl: wpData ? wpData.ajaxUrl : '/wp-admin/admin-ajax.php',
            nonce: wpData ? wpData.nonce : '',
            siteUrl: wpData ? wpData.siteUrl : window.location.origin,
            referralCode: wpData ? wpData.referralCode : '',
            rewards: {
                referrerCredit: 25,
                refereeDiscount: 20
            }
        },

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.checkReferralCode();
            // v126: Social proof AJAX handler disabled server-side, skip polling
            // this.initSocialProof();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            var self = this;

            // Copy referral link
            $(document).on('click', '[data-action="copy-referral"]', function(e) {
                e.preventDefault();
                self.copyReferralLink();
            });

            // Copy any link
            $(document).on('click', '[data-action="copy-link"]', function(e) {
                e.preventDefault();
                var url = $(this).data('url') || window.location.href;
                self.copyLink(url);
            });

            // Share button clicks
            $(document).on('click', '.ptp-share-btn', function(e) {
                var platform = $(this).data('platform');
                if (platform) {
                    self.trackShare(platform, 'button_click');
                }
            });

            // Open share modal
            $(document).on('click', '[data-action="open-share-modal"]', function(e) {
                e.preventDefault();
                self.openShareModal();
            });

            // Close share modal
            $(document).on('click', '.ptp-share-modal__close, .ptp-share-modal', function(e) {
                if (e.target === this) {
                    self.closeShareModal();
                }
            });

            // Open referral dashboard
            $(document).on('click', '[data-action="open-referral-modal"]', function(e) {
                e.preventDefault();
                self.openReferralModal();
            });

            // Redeem credit
            $(document).on('click', '[data-action="redeem-credit"]', function(e) {
                e.preventDefault();
                var amount = $(this).data('amount');
                self.redeemCredit(amount);
            });

            // Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    self.closeShareModal();
                    $('#ptp-referral-modal').hide();
                }
            });
        },

        /**
         * Check for referral code in URL
         */
        checkReferralCode: function() {
            var params = new URLSearchParams(window.location.search);
            var refCode = params.get('ref');
            
            if (refCode) {
                this.validateReferral(refCode);
            }
        },

        /**
         * Validate referral code
         */
        validateReferral: function(code) {
            var self = this;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_validate_referral',
                    code: code
                },
                success: function(response) {
                    if (response.success) {
                        self.showReferralBanner(response.data);
                    }
                }
            });
        },

        /**
         * Show referral discount banner
         */
        showReferralBanner: function(data) {
            var $banner = $('<div class="ptp-referral-banner" style="position:fixed;top:0;left:0;right:0;background:linear-gradient(135deg,#10B981,#059669);color:#fff;padding:12px 20px;text-align:center;z-index:300;font-weight:600;">' +
                '🎉 ' + data.message + ' - Referred by ' + data.referrer_name +
                '<button onclick="$(this).parent().slideUp()" style="background:none;border:none;color:#fff;float:right;cursor:pointer;font-size:20px;line-height:1;">&times;</button>' +
            '</div>');
            
            $('body').prepend($banner);
            
            // Add margin to body
            $('body').css('margin-top', $banner.outerHeight() + 'px');
            
            // Auto-hide after 10 seconds
            setTimeout(function() {
                $banner.slideUp(function() {
                    $('body').css('margin-top', '');
                    $banner.remove();
                });
            }, 10000);
        },

        /**
         * Copy referral link
         */
        copyReferralLink: function() {
            var link = this.config.siteUrl + '?ref=' + this.config.referralCode;
            this.copyToClipboard(link);
            this.showToast('Referral link copied!', 'success');
            
            // Update button text temporarily
            var $btn = $('[data-action="copy-referral"]');
            var originalText = $btn.text();
            $btn.text('Copied!').addClass('copied');
            setTimeout(function() {
                $btn.text(originalText).removeClass('copied');
            }, 2000);
        },

        /**
         * Copy any link
         */
        copyLink: function(url) {
            this.copyToClipboard(url);
            this.showToast('Link copied!', 'success');
        },

        /**
         * Copy to clipboard
         */
        copyToClipboard: function(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                // Fallback for older browsers
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
            }
        },

        /**
         * Track share
         */
        trackShare: function(platform, context) {
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_track_share',
                    platform: platform,
                    share_type: 'general',
                    context: context || ''
                }
            });

            // Google Analytics
            if (typeof gtag === 'function') {
                gtag('event', 'share', {
                    method: platform,
                    content_type: context || 'page'
                });
            }

            // Facebook Pixel
            if (typeof fbq === 'function') {
                fbq('track', 'Share', {
                    method: platform
                });
            }
        },

        /**
         * Open share modal
         */
        openShareModal: function() {
            var $modal = $('#ptp-share-modal');
            
            if (!$modal.length) {
                this.createShareModal();
                $modal = $('#ptp-share-modal');
            }

            // Update referral link
            var refLink = this.config.siteUrl + '?ref=' + this.config.referralCode;
            $modal.find('#share-modal-link').val(refLink);

            $modal.addClass('active').css('display', 'flex');
            $('body').css('overflow', 'hidden');
        },

        /**
         * Close share modal
         */
        closeShareModal: function() {
            $('#ptp-share-modal').removeClass('active').hide();
            $('body').css('overflow', '');
        },

        /**
         * Create share modal
         */
        createShareModal: function() {
            var refLink = this.config.siteUrl + '?ref=' + this.config.referralCode;
            var text = encodeURIComponent('Check out PTP Training! Use my link for ' + this.config.rewards.refereeDiscount + '% off:');
            
            var html = '<div id="ptp-share-modal" class="ptp-share-modal">' +
                '<div class="ptp-share-modal__content">' +
                    '<div class="ptp-share-modal__header">' +
                        '<h3 class="ptp-share-modal__title">🎯 Share & Earn</h3>' +
                        '<button class="ptp-share-modal__close">&times;</button>' +
                    '</div>' +
                    '<div class="ptp-share-modal__body">' +
                        '<div class="ptp-share-modal__emoji">💰</div>' +
                        '<h4 class="ptp-share-modal__headline">Earn $' + this.config.rewards.referrerCredit + ' for every friend!</h4>' +
                        '<p class="ptp-share-modal__description">Plus, they get ' + this.config.rewards.refereeDiscount + '% off their first booking</p>' +
                        
                        '<div class="ptp-share-buttons" style="margin-bottom:20px;">' +
                            '<a href="sms:?body=' + text + '%20' + encodeURIComponent(refLink) + '" class="ptp-share-btn ptp-share-btn--sms" data-platform="sms"><span>💬</span> Text</a>' +
                            '<a href="https://wa.me/?text=' + text + '%20' + encodeURIComponent(refLink) + '" target="_blank" class="ptp-share-btn ptp-share-btn--whatsapp" data-platform="whatsapp"><span>📱</span> WhatsApp</a>' +
                            '<a href="https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(refLink) + '" target="_blank" class="ptp-share-btn ptp-share-btn--facebook" data-platform="facebook"><span>👥</span> Facebook</a>' +
                        '</div>' +
                        
                        '<div style="border-top:1px solid #eee;padding-top:20px;">' +
                            '<p style="font-size:13px;color:#666;margin:0 0 8px;">Your referral link:</p>' +
                            '<div class="ptp-referral-link">' +
                                '<input type="text" id="share-modal-link" value="' + refLink + '" readonly>' +
                                '<button class="ptp-referral-link__btn" data-action="copy-referral">Copy</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
            
            $('body').append(html);
        },

        /**
         * Open referral dashboard modal
         */
        openReferralModal: function() {
            var self = this;
            var $modal = $('#ptp-referral-modal');
            
            $modal.css('display', 'flex');
            $('body').css('overflow', 'hidden');
            
            // Load stats
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_get_referral_stats',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.renderReferralDashboard(response.data);
                    }
                }
            });
        },

        /**
         * Render referral dashboard content
         */
        renderReferralDashboard: function(data) {
            var html = '<div class="ptp-referral-stats">' +
                '<div class="ptp-referral-stat ptp-referral-stat--credit">' +
                    '<div class="ptp-referral-stat__value">$' + data.credit_balance.toFixed(0) + '</div>' +
                    '<div class="ptp-referral-stat__label">Available Credit</div>' +
                '</div>' +
                '<div class="ptp-referral-stat ptp-referral-stat--referrals">' +
                    '<div class="ptp-referral-stat__value">' + data.total_referrals + '</div>' +
                    '<div class="ptp-referral-stat__label">Friends Referred</div>' +
                '</div>' +
                '<div class="ptp-referral-stat ptp-referral-stat--earned">' +
                    '<div class="ptp-referral-stat__value">$' + data.total_earned.toFixed(0) + '</div>' +
                    '<div class="ptp-referral-stat__label">Total Earned</div>' +
                '</div>' +
            '</div>' +
            
            '<div class="ptp-referral-share-card">' +
                '<h4 class="ptp-referral-share-card__title">🎯 Your Referral Link</h4>' +
                '<p class="ptp-referral-share-card__subtitle">Share to earn $' + this.config.rewards.referrerCredit + ' per friend!</p>' +
                '<div class="ptp-referral-link">' +
                    '<input type="text" value="' + data.referral_link + '" readonly>' +
                    '<button class="ptp-referral-link__btn" data-action="copy-referral">Copy</button>' +
                '</div>' +
            '</div>';
            
            if (data.credit_balance >= 10) {
                html += '<div class="ptp-redeem-credit">' +
                    '<h4 class="ptp-redeem-credit__title">Ready to use your credit?</h4>' +
                    '<p class="ptp-redeem-credit__desc">Convert your $' + data.credit_balance.toFixed(0) + ' credit into a discount code</p>' +
                    '<button class="ptp-redeem-credit__btn" data-action="redeem-credit" data-amount="' + data.credit_balance + '">Redeem Credit</button>' +
                '</div>';
            }
            
            $('#referral-modal-content').html(html);
        },

        /**
         * Redeem credit
         */
        redeemCredit: function(amount) {
            var self = this;
            
            if (!amount || amount < 10) {
                this.showToast('Minimum redemption is $10', 'error');
                return;
            }
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ptp_redeem_credit',
                    nonce: this.config.nonce,
                    amount: amount
                },
                beforeSend: function() {
                    $('[data-action="redeem-credit"]').text('Processing...').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        self.showToast('Coupon created: ' + response.data.coupon_code, 'success');
                        
                        // Show coupon code prominently
                        var $redeemSection = $('.ptp-redeem-credit');
                        $redeemSection.html(
                            '<h4 style="color:#059669;margin:0 0 8px;">✓ Coupon Created!</h4>' +
                            '<p style="margin:0 0 12px;">Use this code at checkout:</p>' +
                            '<div style="background:#0A0A0A;color:#FCB900;padding:16px;border-radius:8px;font-size:20px;font-weight:700;letter-spacing:2px;">' +
                                response.data.coupon_code +
                            '</div>' +
                            '<p style="font-size:12px;color:#666;margin:12px 0 0;">Expires in 30 days</p>'
                        );
                    } else {
                        self.showToast(response.data || 'Error redeeming credit', 'error');
                        $('[data-action="redeem-credit"]').text('Redeem Credit').prop('disabled', false);
                    }
                },
                error: function() {
                    self.showToast('Network error. Please try again.', 'error');
                    $('[data-action="redeem-credit"]').text('Redeem Credit').prop('disabled', false);
                }
            });
        },

        /**
         * Initialize social proof notifications
         */
        initSocialProof: function() {
            var self = this;
            var shown = 0;
            var maxShow = 3;
            var interval = 15000;
            
            // Don't show on checkout/cart
            if (window.location.href.indexOf('checkout') > -1 || 
                window.location.href.indexOf('cart') > -1) {
                return;
            }
            
            // Check if we have valid config before proceeding
            if (!self.config.ajaxUrl || !self.config.nonce) {
                console.log('PTP Social Proof: Missing configuration, skipping initialization');
                return;
            }
            
            function showProof() {
                if (shown >= maxShow) return;
                
                // Safety check for config
                if (!self.config.ajaxUrl || !self.config.nonce) return;
                
                $.ajax({
                    url: self.config.ajaxUrl,
                    type: 'GET',
                    data: {
                        action: 'ptp_get_social_proof',
                        nonce: self.config.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.notification) {
                            self.showSocialProofNotification(response.data.notification);
                            shown++;
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('PTP Social Proof: Request failed', status);
                    }
                });
            }
            
            // Start after 5 seconds
            setTimeout(function() {
                showProof();
                setInterval(showProof, interval);
            }, 5000);
        },

        /**
         * Show social proof notification
         */
        showSocialProofNotification: function(notification) {
            var $container = $('#ptp-social-proof');
            
            if (!$container.length) {
                $container = $('<div id="ptp-social-proof"></div>');
                $('body').append($container);
            }
            
            var imageUrl = notification.image || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(notification.title.split(' ')[0]) + '&background=FCB900&color=0A0A0A';
            
            var $notification = $('<div class="ptp-proof-notification">' +
                '<img src="' + imageUrl + '" alt="" class="ptp-proof-notification__image">' +
                '<div class="ptp-proof-notification__content">' +
                    '<div class="ptp-proof-notification__title">' + notification.title + '</div>' +
                    '<div class="ptp-proof-notification__subtitle">' + notification.subtitle + '</div>' +
                    '<div class="ptp-proof-notification__time">' + (notification.time || 'Just now') + '</div>' +
                '</div>' +
            '</div>');
            
            $container.append($notification);
            
            // Remove after animation
            setTimeout(function() {
                $notification.remove();
            }, 5000);
        },

        /**
         * Show toast notification
         */
        showToast: function(message, type) {
            type = type || 'info';
            
            var $existing = $('.ptp-viral-toast');
            if ($existing.length) {
                $existing.remove();
            }
            
            var icon = type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ';
            
            var $toast = $('<div class="ptp-viral-toast" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);' +
                'background:' + (type === 'success' ? '#10B981' : type === 'error' ? '#EF4444' : '#0A0A0A') + ';' +
                'color:#fff;padding:14px 24px;border-radius:8px;font-weight:600;z-index:99999;opacity:0;transition:all 0.3s;">' +
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
            }, 3500);
        },

        /**
         * Generate share URL with tracking
         */
        getShareUrl: function(platform, url) {
            url = url || window.location.href;
            
            // Add referral code if available
            if (this.config.referralCode && url.indexOf('ref=') === -1) {
                var separator = url.indexOf('?') > -1 ? '&' : '?';
                url += separator + 'ref=' + this.config.referralCode;
            }
            
            return url;
        },

        /**
         * Share via Web Share API (mobile)
         */
        nativeShare: function(title, text, url) {
            var self = this;
            url = this.getShareUrl('native', url);
            
            if (navigator.share) {
                navigator.share({
                    title: title || document.title,
                    text: text || '',
                    url: url
                }).then(function() {
                    self.trackShare('native', 'success');
                }).catch(function(err) {
                    console.log('Share cancelled:', err);
                });
            } else {
                // Fallback to modal
                this.openShareModal();
            }
        }
    };

    // Initialize on DOM ready
    $(function() {
        ptpViral.init();
    });

})(jQuery);
