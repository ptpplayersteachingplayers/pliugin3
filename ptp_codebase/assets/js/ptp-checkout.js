/**
 * PTP Checkout JavaScript - v71
 * AJAX cart, Stripe Elements, mobile-optimized
 */

(function() {
    'use strict';
    
    // Config from localized script
    const config = window.ptpCheckout || {};
    
    // State
    let stripe = null;
    let cardElement = null;
    let processing = false;
    
    /**
     * Extract UTM attribution from sessionStorage → cookies → URL params
     * Matches camps plugin PTP_Camps_Attribution cookie format
     */
    function getUtmAttribution() {
        const utm = {};
        const fields = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'];
        
        // 1. Try sessionStorage (set by camps plugin attribution.js)
        try {
            fields.forEach(f => { const v = sessionStorage.getItem('ptp_' + f); if (v) utm[f] = v; });
            const ci = sessionStorage.getItem('ptp_click_id'); if (ci) utm.click_id = ci;
            const lp = sessionStorage.getItem('ptp_landing_page'); if (lp) utm.landing_page = lp;
        } catch(e) {}
        
        // 2. Try ptp_lt / ptp_ft cookies (camps plugin attribution cookies)
        if (!utm.utm_source) {
            try {
                const getCk = n => { const m = document.cookie.match(new RegExp('(^| )' + n + '=([^;]+)')); return m ? decodeURIComponent(m[2]) : ''; };
                const lt = getCk('ptp_lt') || getCk('ptp_ft');
                if (lt) {
                    const parsed = JSON.parse(lt);
                    if (parsed) {
                        fields.forEach(f => { if (parsed[f]) utm[f] = parsed[f]; });
                        if (parsed.click_id || parsed.fbclid) utm.click_id = parsed.click_id || parsed.fbclid;
                        if (parsed.landing_page) utm.landing_page = parsed.landing_page;
                    }
                }
            } catch(e) {}
        }
        
        // 3. Fallback to URL params (in case of direct click-through)
        if (!utm.utm_source) {
            try {
                const sp = new URLSearchParams(location.search);
                fields.forEach(f => { const v = sp.get(f); if (v) utm[f] = v; });
                const fbclid = sp.get('fbclid'); if (fbclid) utm.click_id = fbclid;
                const gclid = sp.get('gclid'); if (gclid) utm.click_id = gclid;
            } catch(e) {}
        }
        
        if (!utm.landing_page) utm.landing_page = location.pathname;
        return utm;
    }
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        // Initialize Stripe if on checkout page
        if (config.stripeKey && document.getElementById('card-element')) {
            initStripe();
        }
        
        // Bind cart events
        bindCartEvents();
        
        // Bind checkout events
        bindCheckoutEvents();
    }
    
    // =========================================
    // STRIPE INITIALIZATION
    // =========================================
    
    function initStripe() {
        stripe = Stripe(config.stripeKey);
        
        const elements = stripe.elements({
            fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500&display=swap' }]
        });
        
        cardElement = elements.create('card', {
            style: {
                base: {
                    fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, sans-serif',
                    fontSize: '16px',
                    color: '#0A0A0A',
                    '::placeholder': { color: '#999' },
                    iconColor: '#0A0A0A'
                },
                invalid: { 
                    color: '#C62828',
                    iconColor: '#C62828'
                }
            },
            hidePostalCode: true
        });
        
        cardElement.mount('#card-element');
        
        cardElement.on('change', (event) => {
            const errorDiv = document.getElementById('card-errors');
            if (errorDiv) {
                errorDiv.textContent = event.error ? event.error.message : '';
            }
        });
        
        cardElement.on('focus', () => {
            document.querySelector('.ptp-card-element')?.classList.add('focused');
        });
        
        cardElement.on('blur', () => {
            document.querySelector('.ptp-card-element')?.classList.remove('focused');
        });
    }
    
    // =========================================
    // CART FUNCTIONS
    // =========================================
    
    function bindCartEvents() {
        // Add to cart buttons
        document.querySelectorAll('[data-add-to-cart]').forEach(btn => {
            btn.addEventListener('click', handleAddToCart);
        });
        
        // Mini cart toggle
        document.getElementById('mini-cart-toggle')?.addEventListener('click', toggleMiniCart);
        
        // Coupon form
        document.getElementById('apply-coupon-btn')?.addEventListener('click', applyCoupon);
        document.getElementById('coupon-code')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyCoupon();
            }
        });
    }
    
    async function handleAddToCart(e) {
        e.preventDefault();
        
        const btn = e.currentTarget;
        const productId = btn.dataset.addToCart || btn.dataset.productId;
        
        if (!productId) return;
        
        // Get additional data
        const form = btn.closest('form');
        const data = {
            product_id: productId,
            quantity: 1
        };
        
        // Collect form data if present
        if (form) {
            const formData = new FormData(form);
            formData.forEach((value, key) => {
                data[key] = value;
            });
        }
        
        // Show loading
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="ptp-spinner-small"></span>';
        
        try {
            const response = await ajax('ptp_add_to_cart', data);
            
            if (response.success) {
                // Update cart count
                updateCartCount(response.data.cart_count);
                
                // Show success
                btn.innerHTML = '✓ Added';
                btn.classList.add('added');
                
                // Show mini cart or redirect
                if (config.cartUrl && btn.dataset.redirect !== 'false') {
                    setTimeout(() => {
                        window.location.href = config.cartUrl;
                    }, 500);
                } else {
                    showMiniCart();
                }
            } else {
                throw new Error(response.data?.message || 'Could not add to cart');
            }
        } catch (error) {
            showToast(error.message, 'error');
            btn.innerHTML = originalText;
        }
        
        btn.disabled = false;
    }
    
    function updateCartCount(count) {
        document.querySelectorAll('.ptp-cart-count').forEach(el => {
            el.textContent = count;
            el.style.display = count > 0 ? 'flex' : 'none';
        });
    }
    
    async function applyCoupon() {
        const input = document.getElementById('coupon-code');
        const msg = document.getElementById('coupon-message');
        const code = input?.value.trim();
        
        if (!code) {
            showMessage(msg, 'Please enter a coupon code', 'error');
            return;
        }
        
        const btn = document.getElementById('apply-coupon-btn');
        btn.disabled = true;
        
        try {
            const response = await ajax('ptp_apply_coupon', { coupon_code: code });
            
            if (response.success) {
                showMessage(msg, response.data.message || 'Coupon applied!', 'success');
                
                // Update cart totals
                if (typeof renderCart === 'function') {
                    renderCart(response.data);
                } else {
                    location.reload();
                }
            } else {
                showMessage(msg, response.data?.message || 'Invalid coupon', 'error');
            }
        } catch (error) {
            showMessage(msg, 'Error applying coupon', 'error');
        }
        
        btn.disabled = false;
    }
    
    // =========================================
    // CHECKOUT FUNCTIONS
    // =========================================
    
    function bindCheckoutEvents() {
        const form = document.getElementById('ptp-checkout-form');
        if (!form) return;
        
        form.addEventListener('submit', handleCheckout);
        
        // Real-time validation
        form.querySelectorAll('.ptp-input[required]').forEach(input => {
            input.addEventListener('blur', () => validateField(input));
            input.addEventListener('input', () => clearError(input));
        });
    }
    
    async function handleCheckout(e) {
        e.preventDefault();
        
        if (processing) return;
        
        const form = e.target;
        
        // Validate form
        if (!validateForm(form)) {
            scrollToFirstError(form);
            return;
        }
        
        processing = true;
        showProcessing(true);
        
        try {
            // Step 1: Create Payment Intent (with UTM attribution)
            const intentResponse = await ajax('ptp_create_payment_intent', getUtmAttribution());
            
            if (!intentResponse.success) {
                throw new Error(intentResponse.data?.message || 'Payment initialization failed');
            }
            
            // Step 2: Confirm card payment with Stripe
            const { error, paymentIntent } = await stripe.confirmCardPayment(
                intentResponse.data.clientSecret,
                {
                    payment_method: {
                        card: cardElement,
                        billing_details: getBillingDetails(form)
                    },
                    return_url: window.location.origin + '/checkout/order-received/'
                }
            );
            
            if (error) {
                throw new Error(error.message);
            }
            
            // Step 3: Process order on server
            const formData = new FormData(form);
            const orderData = {};
            formData.forEach((value, key) => orderData[key] = value);
            orderData.payment_intent_id = paymentIntent.id;
            
            // Add UTM attribution to order data
            const utmData = getUtmAttribution();
            Object.keys(utmData).forEach(k => { if (!orderData[k]) orderData[k] = utmData[k]; });
            
            const orderResponse = await ajax('ptp_process_checkout', orderData);
            
            if (orderResponse.success) {
                // Redirect to thank you page
                window.location.href = orderResponse.data.redirect;
            } else {
                throw new Error(orderResponse.data?.message || 'Order processing failed');
            }
            
        } catch (error) {
            showError(error.message);
            showProcessing(false);
            processing = false;
        }
    }
    
    function getBillingDetails(form) {
        return {
            name: `${form.billing_first_name?.value || ''} ${form.billing_last_name?.value || ''}`.trim(),
            email: form.billing_email?.value || '',
            phone: form.billing_phone?.value || '',
            address: {
                line1: form.billing_address_1?.value || '',
                line2: form.billing_address_2?.value || '',
                city: form.billing_city?.value || '',
                state: form.billing_state?.value || '',
                postal_code: form.billing_postcode?.value || '',
                country: form.billing_country?.value || 'US'
            }
        };
    }
    
    function validateForm(form) {
        let valid = true;
        
        form.querySelectorAll('[required]').forEach(input => {
            if (!validateField(input)) {
                valid = false;
            }
        });
        
        // Validate terms checkbox
        const terms = form.querySelector('input[name="terms"]');
        if (terms && !terms.checked) {
            valid = false;
            showToast('Please agree to the terms and conditions', 'error');
        }
        
        return valid;
    }
    
    function validateField(input) {
        const value = input.value.trim();
        const errorEl = input.parentElement.querySelector('.ptp-error-msg');
        
        // Required check
        if (input.required && !value) {
            setError(input, errorEl, 'This field is required');
            return false;
        }
        
        // Email validation
        if (input.type === 'email' && value && !isValidEmail(value)) {
            setError(input, errorEl, 'Please enter a valid email');
            return false;
        }
        
        // Phone validation
        if (input.type === 'tel' && value && !isValidPhone(value)) {
            setError(input, errorEl, 'Please enter a valid phone number');
            return false;
        }
        
        clearError(input);
        return true;
    }
    
    function setError(input, errorEl, message) {
        input.classList.add('error');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
    }
    
    function clearError(input) {
        input.classList.remove('error');
        const errorEl = input.parentElement.querySelector('.ptp-error-msg');
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.style.display = 'none';
        }
    }
    
    function scrollToFirstError(form) {
        const firstError = form.querySelector('.ptp-input.error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstError.focus();
        }
    }
    
    function showProcessing(show) {
        const overlay = document.getElementById('processing-overlay');
        if (overlay) {
            overlay.style.display = show ? 'flex' : 'none';
        }
        
        document.querySelectorAll('#submit-btn, #submit-btn-mobile').forEach(btn => {
            btn.disabled = show;
            const text = btn.querySelector('.btn-text');
            const loading = btn.querySelector('.btn-loading');
            if (text) text.style.display = show ? 'none' : 'inline';
            if (loading) loading.style.display = show ? 'inline-flex' : 'none';
        });
    }
    
    function showError(message) {
        const errorDiv = document.getElementById('card-errors');
        if (errorDiv) {
            errorDiv.textContent = message;
        }
        showToast(message, 'error');
    }
    
    // =========================================
    // UTILITIES
    // =========================================
    
    async function ajax(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', config.nonce);
        
        Object.keys(data).forEach(key => {
            formData.append(key, data[key]);
        });
        
        const response = await fetch(config.ajax, {
            method: 'POST',
            body: formData
        });
        
        return response.json();
    }
    
    function showMessage(el, message, type) {
        if (!el) return;
        el.textContent = message;
        el.className = `ptp-coupon-message ${type}`;
        el.style.display = 'block';
    }
    
    function showToast(message, type = 'info') {
        const existing = document.querySelector('.ptp-toast');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.className = `ptp-toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        requestAnimationFrame(() => toast.classList.add('show'));
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
    
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }
    
    function isValidPhone(phone) {
        const cleaned = phone.replace(/\D/g, '');
        return cleaned.length >= 10;
    }
    
    // =========================================
    // MINI CART
    // =========================================
    
    function toggleMiniCart() {
        const miniCart = document.getElementById('mini-cart');
        if (miniCart) {
            miniCart.classList.toggle('active');
        }
    }
    
    function showMiniCart() {
        const miniCart = document.getElementById('mini-cart');
        if (miniCart) {
            miniCart.classList.add('active');
            setTimeout(() => miniCart.classList.remove('active'), 3000);
        }
    }
    
    // =========================================
    // EXPOSE GLOBAL FUNCTIONS
    // =========================================
    
    window.ptpCart = {
        addToCart: handleAddToCart,
        applyCoupon: applyCoupon,
        updateCount: updateCartCount,
        showToast: showToast
    };
    
})();
