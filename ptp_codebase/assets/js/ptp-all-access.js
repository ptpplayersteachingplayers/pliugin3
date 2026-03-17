/**
 * PTP All-Access Pass JS v94
 * Handles checkout flows, modals, FAQ, quantity controls
 */
(function() {
    'use strict';
    
    const PTP = {
        // State
        selectedTier: null,
        selectedPlan: 'full',
        selectedService: null,
        serviceQty: 1,
        
        // Tier prices (fallback if not passed from PHP)
        tierPrices: {
            '2camp': { name: '2-Camp Pack', price: 945 },
            '3camp': { name: '3-Camp Pack', price: 1260 },
            'allaccess': { name: 'All-Access Pass', price: 4000 }
        },
        paymentPrices: {
            'full': 3600,
            'split': 2050,
            'monthly': 350
        },
        
        init: function() {
            // Merge with PHP config if available
            if (typeof ptpAA !== 'undefined') {
                if (ptpAA.tiers) {
                    Object.keys(ptpAA.tiers).forEach(k => {
                        this.tierPrices[k] = {
                            name: ptpAA.tiers[k].name,
                            price: ptpAA.tiers[k].price
                        };
                    });
                }
                if (ptpAA.payments) {
                    Object.keys(ptpAA.payments).forEach(k => {
                        this.paymentPrices[k] = ptpAA.payments[k].price;
                    });
                }
            }
            
            this.bindEvents();
            this.initFAQ();
            this.initQuantity();
        },
        
        bindEvents: function() {
            // Tier checkout buttons
            document.querySelectorAll('.ptp-checkout-btn').forEach(btn => {
                btn.addEventListener('click', e => this.handleTierClick(e));
            });
            
            // Service purchase buttons
            document.querySelectorAll('.ptp-service-btn').forEach(btn => {
                btn.addEventListener('click', e => this.handleServiceClick(e));
            });
            
            // Modal controls
            document.querySelectorAll('.ptp-modal-close, .ptp-modal-overlay').forEach(el => {
                el.addEventListener('click', () => this.closeModal());
            });
            
            // Submit button
            const submit = document.getElementById('ptp-submit');
            if (submit) {
                submit.addEventListener('click', () => this.submitCheckout());
            }
            
            // Smooth scroll for anchor links
            document.querySelectorAll('a[href^="#"]').forEach(a => {
                a.addEventListener('click', e => {
                    const target = document.querySelector(a.getAttribute('href'));
                    if (target) {
                        e.preventDefault();
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
            
            // ESC to close modal
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') this.closeModal();
            });
        },
        
        initFAQ: function() {
            document.querySelectorAll('.ptp-faq-q').forEach(btn => {
                btn.addEventListener('click', () => {
                    const item = btn.closest('.ptp-faq-item');
                    const isActive = item.classList.contains('active');
                    
                    // Close all
                    document.querySelectorAll('.ptp-faq-item').forEach(i => {
                        i.classList.remove('active');
                    });
                    
                    // Open clicked if wasn't active
                    if (!isActive) {
                        item.classList.add('active');
                    }
                });
            });
        },
        
        initQuantity: function() {
            // Quantity buttons
            document.querySelectorAll('.ptp-qty-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const dir = btn.dataset.dir;
                    const input = document.getElementById('svc-qty');
                    if (!input) return;
                    
                    let val = parseInt(input.value) || 1;
                    val = (dir === '+') ? Math.min(val + 1, 10) : Math.max(val - 1, 1);
                    input.value = val;
                    this.serviceQty = val;
                    this.updateServiceTotal();
                });
            });
            
            // Quantity input change
            const input = document.getElementById('svc-qty');
            if (input) {
                input.addEventListener('change', () => {
                    let val = parseInt(input.value) || 1;
                    val = Math.max(1, Math.min(10, val));
                    input.value = val;
                    this.serviceQty = val;
                    this.updateServiceTotal();
                });
            }
        },
        
        updateServiceTotal: function() {
            const btn = document.querySelector('.ptp-service-btn');
            const total = document.getElementById('svc-total');
            if (btn && total) {
                const rate = parseInt(btn.dataset.rate) || 100;
                total.textContent = '$' + (rate * this.serviceQty).toLocaleString();
            }
        },
        
        handleTierClick: function(e) {
            const btn = e.target.closest('.ptp-checkout-btn');
            this.selectedTier = btn.dataset.tier || 'allaccess';
            this.selectedPlan = btn.dataset.plan || 'full';
            this.selectedService = null;
            
            const tier = this.tierPrices[this.selectedTier] || this.tierPrices.allaccess;
            let price, label;
            
            if (this.selectedTier === 'allaccess' && this.selectedPlan) {
                // All-Access with payment plan
                price = this.paymentPrices[this.selectedPlan] || tier.price;
                const planLabels = {
                    'full': 'Pay in Full (10% off)',
                    'split': 'Payment 1 of 2',
                    'monthly': 'Monthly'
                };
                label = tier.name + ' - ' + (planLabels[this.selectedPlan] || '');
            } else {
                // Camp packs - simple one-time
                price = tier.price;
                label = tier.name;
            }
            
            this.updateModalSummary(label, price);
            this.openModal();
        },
        
        handleServiceClick: function(e) {
            const btn = e.target.closest('.ptp-service-btn');
            this.selectedService = btn.dataset.service;
            this.selectedTier = null;
            
            const rate = parseInt(btn.dataset.rate) || 100;
            const qty = this.serviceQty || 1;
            const names = {
                'video': 'Video Analysis',
                'mentorship': 'Mentorship Calls'
            };
            
            const label = names[this.selectedService] + ' (' + qty + ' hr' + (qty > 1 ? 's' : '') + ')';
            const price = rate * qty;
            
            this.updateModalSummary(label, price);
            this.openModal();
        },
        
        updateModalSummary: function(label, price) {
            const nameEl = document.getElementById('ptp-item-name');
            const priceEl = document.getElementById('ptp-item-price');
            if (nameEl) nameEl.textContent = label;
            if (priceEl) priceEl.textContent = '$' + price.toLocaleString();
        },
        
        openModal: function() {
            const modal = document.getElementById('ptp-modal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                
                // Focus email input
                setTimeout(() => {
                    const email = document.getElementById('ptp-email');
                    if (email) email.focus();
                }, 100);
            }
        },
        
        closeModal: function() {
            const modal = document.getElementById('ptp-modal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        },
        
        submitCheckout: function() {
            const emailInput = document.getElementById('ptp-email');
            const email = emailInput ? emailInput.value.trim() : '';
            
            // Validate email
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address');
                if (emailInput) emailInput.focus();
                return;
            }
            
            this.showLoading(true);
            
            const data = new FormData();
            data.append('nonce', ptpAA.nonce);
            data.append('email', email);
            
            if (this.selectedService) {
                // Service purchase
                data.append('action', 'ptp_service_purchase');
                data.append('service', this.selectedService);
                data.append('quantity', this.serviceQty);
            } else {
                // Membership/tier checkout
                data.append('action', 'ptp_membership_checkout');
                data.append('tier', this.selectedTier);
                data.append('plan', this.selectedPlan);
            }
            
            fetch(ptpAA.ajax, {
                method: 'POST',
                body: data
            })
            .then(r => r.json())
            .then(res => {
                if (res.success && res.data.url) {
                    // Redirect to Stripe
                    window.location.href = res.data.url;
                } else {
                    this.showLoading(false);
                    alert(res.data?.message || 'Something went wrong. Please try again.');
                }
            })
            .catch(err => {
                this.showLoading(false);
                console.error('Checkout error:', err);
                alert('Connection error. Please check your internet and try again.');
            });
        },
        
        showLoading: function(show) {
            const form = document.getElementById('ptp-modal-form');
            const loading = document.getElementById('ptp-modal-loading');
            if (form) form.style.display = show ? 'none' : 'block';
            if (loading) loading.style.display = show ? 'block' : 'none';
        }
    };
    
    // Initialize when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => PTP.init());
    } else {
        PTP.init();
    }
})();
