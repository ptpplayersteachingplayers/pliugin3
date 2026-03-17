/**
 * PTP Flow Engine JS v1.0.0
 * 
 * Frontend conversion tracking and flow optimization
 * 
 * @since 59.0.0
 */

(function() {
    'use strict';
    
    // Ensure ptpFlow is available
    if (typeof window.ptpFlow === 'undefined') {
        window.ptpFlow = {};
    }
    
    const Flow = {
        
        // Event names (sync with PHP constants)
        EVENTS: {
            VIEW_TRAINER: 'view_trainer',
            SELECT_PACKAGE: 'select_package',
            START_BOOKING: 'start_booking',
            VIEW_CAMP: 'view_camp',
            ADD_TO_CART: 'add_to_cart',
            BEGIN_CHECKOUT: 'begin_checkout',
            PURCHASE: 'purchase',
            REFERRAL_CLICK: 'referral_click',
            REFERRAL_SIGNUP: 'referral_signup',
            SHARE_CLICK: 'share_click',
            COPY_REFERRAL: 'copy_referral',
        },
        
        /**
         * Track event
         */
        track: function(event, data) {
            data = data || {};
            data.timestamp = Date.now();
            data.page_url = window.location.href;
            
            // Send to backend
            if (typeof window.ptpTrack === 'function') {
                window.ptpTrack(event, data);
            }
            
            // Also fire custom event for other listeners
            document.dispatchEvent(new CustomEvent('ptp:flow', {
                detail: { event: event, data: data }
            }));
            
            // Console log in dev mode
            if (window.ptpFlow.debug) {
                console.log('[PTP Flow]', event, data);
            }
        },
        
        /**
         * Track trainer profile view
         */
        trackTrainerView: function(trainerId, trainerSlug) {
            this.track(this.EVENTS.VIEW_TRAINER, {
                trainer_id: trainerId,
                trainer_slug: trainerSlug,
            });
        },
        
        /**
         * Track package selection
         */
        trackPackageSelect: function(packageId, sessions, price, trainerId) {
            this.track(this.EVENTS.SELECT_PACKAGE, {
                package_id: packageId,
                sessions: sessions,
                price: price,
                trainer_id: trainerId,
            });
        },
        
        /**
         * Track booking start
         */
        trackBookingStart: function(trainerId, packageType, date, time) {
            this.track(this.EVENTS.START_BOOKING, {
                trainer_id: trainerId,
                package_type: packageType,
                date: date,
                time: time,
            });
        },
        
        /**
         * Track camp view
         */
        trackCampView: function(productId, productName, price) {
            this.track(this.EVENTS.VIEW_CAMP, {
                product_id: productId,
                product_name: productName,
                price: price,
            });
        },
        
        /**
         * Track add to cart
         */
        trackAddToCart: function(productId, productName, price, quantity) {
            this.track(this.EVENTS.ADD_TO_CART, {
                product_id: productId,
                product_name: productName,
                price: price,
                quantity: quantity || 1,
            });
        },
        
        /**
         * Track share click
         */
        trackShare: function(platform, contentType, contentId) {
            this.track(this.EVENTS.SHARE_CLICK, {
                platform: platform,
                content_type: contentType,
                content_id: contentId,
            });
        },
        
        /**
         * Track referral code copy
         */
        trackReferralCopy: function(code) {
            this.track(this.EVENTS.COPY_REFERRAL, {
                referral_code: code,
            });
        },
        
        /**
         * Copy referral code to clipboard
         */
        copyReferralCode: function(code, callback) {
            const self = this;
            
            navigator.clipboard.writeText(code).then(function() {
                self.trackReferralCopy(code);
                if (typeof callback === 'function') {
                    callback(true);
                }
            }).catch(function(err) {
                // Fallback
                const textarea = document.createElement('textarea');
                textarea.value = code;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                
                try {
                    document.execCommand('copy');
                    self.trackReferralCopy(code);
                    if (typeof callback === 'function') {
                        callback(true);
                    }
                } catch (e) {
                    if (typeof callback === 'function') {
                        callback(false);
                    }
                }
                
                document.body.removeChild(textarea);
            });
        },
        
        /**
         * Get referral link
         */
        getReferralLink: function(code, path) {
            path = path || '/find-trainers/';
            const url = new URL(path, window.location.origin);
            url.searchParams.set('ref', code);
            return url.toString();
        },
        
        /**
         * Share via SMS
         */
        shareViaSMS: function(message) {
            const encoded = encodeURIComponent(message);
            window.location.href = 'sms:?body=' + encoded;
            this.trackShare('sms', 'referral', window.ptpFlow.referral_stats?.code || '');
        },
        
        /**
         * Share via WhatsApp
         */
        shareViaWhatsApp: function(message) {
            const encoded = encodeURIComponent(message);
            window.open('https://wa.me/?text=' + encoded, '_blank');
            this.trackShare('whatsapp', 'referral', window.ptpFlow.referral_stats?.code || '');
        },
        
        /**
         * Share via native share API
         */
        shareNative: function(title, text, url) {
            const self = this;
            
            if (navigator.share) {
                navigator.share({
                    title: title,
                    text: text,
                    url: url
                }).then(function() {
                    self.trackShare('native', 'referral', window.ptpFlow.referral_stats?.code || '');
                }).catch(function(err) {
                    // User cancelled or error
                });
            } else {
                // Fallback to copy
                this.copyReferralCode(url, function(success) {
                    if (success) {
                        alert('Link copied to clipboard!');
                    }
                });
            }
        },
        
        /**
         * Initialize auto-tracking
         */
        init: function() {
            const self = this;
            
            // Track trainer profile views
            const trainerEl = document.querySelector('[data-trainer-id]');
            if (trainerEl && window.location.pathname.includes('/trainer/')) {
                const trainerId = trainerEl.dataset.trainerId;
                const trainerSlug = trainerEl.dataset.trainerSlug || '';
                self.trackTrainerView(trainerId, trainerSlug);
            }
            
            // Track package selections
            document.addEventListener('click', function(e) {
                const pkg = e.target.closest('[data-package]');
                if (pkg) {
                    const pkgId = pkg.dataset.package;
                    const sessions = pkg.dataset.sessions || 1;
                    const price = pkg.dataset.price || 0;
                    const trainerId = document.querySelector('[data-trainer-id]')?.dataset.trainerId || '';
                    self.trackPackageSelect(pkgId, sessions, price, trainerId);
                }
            });
            
            // Track PTP Native add to cart
            document.body.addEventListener('added_to_cart', function(e, fragments, hash, button) {
                const form = button?.closest('form');
                if (form) {
                    const productId = form.querySelector('[name="add-to-cart"]')?.value || '';
                    const productName = document.querySelector('.product_title')?.textContent || '';
                    const price = document.querySelector('.price .ptp-native-Price-amount')?.textContent || '';
                    self.trackAddToCart(productId, productName, price, 1);
                }
            });
            
            // Track referral copy buttons
            document.querySelectorAll('[data-copy-referral]').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const code = this.dataset.copyReferral || window.ptpFlow.referral_stats?.code;
                    if (code) {
                        self.copyReferralCode(code, function(success) {
                            if (success) {
                                const original = btn.innerHTML;
                                btn.innerHTML = '✓ Copied!';
                                btn.classList.add('copied');
                                setTimeout(function() {
                                    btn.innerHTML = original;
                                    btn.classList.remove('copied');
                                }, 2000);
                            }
                        });
                    }
                });
            });
            
            // Track share buttons
            document.querySelectorAll('[data-share]').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    const platform = this.dataset.share;
                    const code = window.ptpFlow.referral_stats?.code || '';
                    const link = self.getReferralLink(code);
                    const message = 'I found amazing soccer trainers on PTP! Get 20% off your first session: ' + link;
                    
                    switch (platform) {
                        case 'sms':
                            e.preventDefault();
                            self.shareViaSMS(message);
                            break;
                        case 'whatsapp':
                            e.preventDefault();
                            self.shareViaWhatsApp(message);
                            break;
                        case 'native':
                            e.preventDefault();
                            self.shareNative('PTP Training', message, link);
                            break;
                    }
                });
            });
        }
    };
    
    // Expose to window
    window.ptpFlowEngine = Flow;
    
    // Auto-init when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            Flow.init();
        });
    } else {
        Flow.init();
    }
    
})();
