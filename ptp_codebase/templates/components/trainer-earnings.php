<?php
/**
 * Trainer Earnings Component - v71
 * Stripe Connect integration with payout management
 */

defined('ABSPATH') || exit;

$user_id = get_current_user_id();
?>

<div class="ptp-earnings-container" id="earnings-container">
    
    <!-- Header -->
    <div class="ptp-earnings-header">
        <div class="ptp-earnings-title">
            <h2>Earnings & Payouts</h2>
            <p>Track your earnings and request payouts</p>
        </div>
    </div>
    
    <!-- Stripe Connect Status -->
    <div class="ptp-connect-status" id="connect-status">
        <div class="ptp-connect-loading">
            <div class="ptp-spinner"></div>
            <p>Checking payment setup...</p>
        </div>
    </div>
    
    <!-- Earnings Stats -->
    <div class="ptp-earnings-stats" id="earnings-stats" style="display: none;">
        <div class="ptp-stat-card ptp-stat-highlight">
            <div class="ptp-stat-value" id="stat-available">$0.00</div>
            <div class="ptp-stat-label">Available for Payout</div>
        </div>
        <div class="ptp-stat-card">
            <div class="ptp-stat-value" id="stat-pending">$0.00</div>
            <div class="ptp-stat-label">Pending</div>
        </div>
        <div class="ptp-stat-card">
            <div class="ptp-stat-value" id="stat-month">$0.00</div>
            <div class="ptp-stat-label">This Month</div>
        </div>
        <div class="ptp-stat-card">
            <div class="ptp-stat-value" id="stat-total">$0.00</div>
            <div class="ptp-stat-label">Total Earned</div>
        </div>
    </div>
    
    <!-- Payout Button -->
    <div class="ptp-payout-section" id="payout-section" style="display: none;">
        <button class="ptp-btn ptp-btn-primary ptp-btn-lg" id="request-payout-btn" disabled>
            Request Payout
        </button>
        <p class="ptp-payout-note">Minimum payout: $25.00</p>
    </div>
    
    <!-- Recent Transactions -->
    <div class="ptp-transactions" id="transactions-section" style="display: none;">
        <h3>Recent Transactions</h3>
        <div class="ptp-transactions-list" id="transactions-list">
            <!-- Populated by JavaScript -->
        </div>
    </div>
</div>

<style>
.ptp-earnings-container {
    max-width: 900px;
}

.ptp-earnings-header {
    margin-bottom: 24px;
}

.ptp-earnings-title h2 {
    margin: 0;
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    font-weight: 600;
    text-transform: uppercase;
}

.ptp-earnings-title p {
    margin: 4px 0 0;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    color: #666;
}

/* Connect Status */
.ptp-connect-status {
    margin-bottom: 24px;
}

.ptp-connect-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 40px;
    background: #fff;
    border: 2px solid #0A0A0A;
    text-align: center;
}

.ptp-connect-card {
    background: #fff;
    border: 2px solid #0A0A0A;
    padding: 24px;
}

.ptp-connect-card.connected {
    border-color: #4CAF50;
    background: linear-gradient(135deg, #E8F5E9 0%, #fff 100%);
}

.ptp-connect-card.not-connected {
    border-color: #FF9800;
    background: linear-gradient(135deg, #FFF3E0 0%, #fff 100%);
}

.ptp-connect-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}

.ptp-connect-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    background: #0A0A0A;
    color: #FCB900;
}

.ptp-connect-title {
    flex: 1;
}

.ptp-connect-title h3 {
    margin: 0;
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 600;
}

.ptp-connect-title p {
    margin: 4px 0 0;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    color: #666;
}

.ptp-connect-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

/* Stats */
.ptp-earnings-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

@media (min-width: 640px) {
    .ptp-earnings-stats {
        grid-template-columns: repeat(4, 1fr);
    }
}

.ptp-stat-card {
    background: #fff;
    border: 2px solid #0A0A0A;
    padding: 20px;
    text-align: center;
}

.ptp-stat-highlight {
    background: linear-gradient(135deg, #FCB900 0%, #FFD54F 100%);
}

.ptp-stat-value {
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
}

.ptp-stat-label {
    font-family: 'Inter', sans-serif;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #666;
    margin-top: 8px;
}

.ptp-stat-highlight .ptp-stat-label {
    color: #0A0A0A;
}

/* Payout Section */
.ptp-payout-section {
    margin-bottom: 24px;
    text-align: center;
}

.ptp-payout-note {
    margin: 12px 0 0;
    font-family: 'Inter', sans-serif;
    font-size: 13px;
    color: #666;
}

/* Transactions */
.ptp-transactions h3 {
    font-family: 'Oswald', sans-serif;
    font-size: 20px;
    font-weight: 600;
    text-transform: uppercase;
    margin: 0 0 16px;
}

.ptp-transactions-list {
    background: #fff;
    border: 2px solid #0A0A0A;
}

.ptp-transaction {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    border-bottom: 1px solid #E5E5E5;
}

.ptp-transaction:last-child {
    border-bottom: none;
}

.ptp-transaction-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: #F5F5F5;
}

.ptp-transaction-info {
    flex: 1;
}

.ptp-transaction-desc {
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 500;
}

.ptp-transaction-date {
    font-family: 'Inter', sans-serif;
    font-size: 12px;
    color: #666;
}

.ptp-transaction-amount {
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 600;
}

.ptp-transaction-amount.positive {
    color: #2E7D32;
}

.ptp-transaction-status {
    font-family: 'Inter', sans-serif;
    font-size: 11px;
    text-transform: uppercase;
    padding: 4px 8px;
}

.ptp-transaction-status.pending {
    background: #FFF3E0;
    color: #E65100;
}

.ptp-transaction-status.available {
    background: #E8F5E9;
    color: #2E7D32;
}

.ptp-transaction-status.paid {
    background: #E3F2FD;
    color: #1565C0;
}

/* Buttons */
.ptp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 48px;
    padding: 12px 24px;
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    border: 2px solid #0A0A0A;
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
}

.ptp-btn-primary {
    background: #FCB900;
    color: #0A0A0A;
}

.ptp-btn-primary:hover {
    background: #0A0A0A;
    color: #FCB900;
}

.ptp-btn-outline {
    background: transparent;
    color: #0A0A0A;
}

.ptp-btn-outline:hover {
    background: #0A0A0A;
    color: #FCB900;
}

.ptp-btn-lg {
    min-height: 56px;
    padding: 16px 32px;
    font-size: 16px;
}

.ptp-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.ptp-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid #E5E5E5;
    border-top-color: #FCB900;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.ptp-empty {
    text-align: center;
    padding: 40px;
    font-family: 'Inter', sans-serif;
    color: #666;
}
</style>

<script>
(function() {
    'use strict';
    
    const config = {
        ajax: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('ptp_nonce'); ?>'
    };
    
    // Initialize
    checkStripeStatus();
    
    async function checkStripeStatus() {
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_stripe_account_status&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                renderConnectStatus(data.data);
                
                if (data.data.connected && data.data.payouts_enabled) {
                    loadEarnings();
                }
            }
        } catch (error) {
            console.error('Stripe status error:', error);
        }
    }
    
    function renderConnectStatus(status) {
        const container = document.getElementById('connect-status');
        
        if (status.connected && status.payouts_enabled) {
            container.innerHTML = `
                <div class="ptp-connect-card connected">
                    <div class="ptp-connect-header">
                        <div class="ptp-connect-icon">✓</div>
                        <div class="ptp-connect-title">
                            <h3>Payments Enabled</h3>
                            <p>Your Stripe account is ready to receive payments</p>
                        </div>
                    </div>
                    <div class="ptp-connect-actions">
                        <button class="ptp-btn ptp-btn-outline" onclick="openStripeDashboard()">
                            Open Stripe Dashboard
                        </button>
                    </div>
                </div>
            `;
        } else if (status.connected && !status.details_submitted) {
            container.innerHTML = `
                <div class="ptp-connect-card not-connected">
                    <div class="ptp-connect-header">
                        <div class="ptp-connect-icon">⚠️</div>
                        <div class="ptp-connect-title">
                            <h3>Complete Setup</h3>
                            <p>Finish setting up your Stripe account to receive payments</p>
                        </div>
                    </div>
                    <div class="ptp-connect-actions">
                        <button class="ptp-btn ptp-btn-primary" onclick="openStripeDashboard()">
                            Complete Setup
                        </button>
                    </div>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="ptp-connect-card not-connected">
                    <div class="ptp-connect-header">
                        <div class="ptp-connect-icon">💳</div>
                        <div class="ptp-connect-title">
                            <h3>Connect Stripe</h3>
                            <p>Set up your payment account to receive earnings from sessions</p>
                        </div>
                    </div>
                    <div class="ptp-connect-actions">
                        <button class="ptp-btn ptp-btn-primary" onclick="connectStripe()">
                            Connect with Stripe
                        </button>
                    </div>
                </div>
            `;
        }
    }
    
    async function loadEarnings() {
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_get_earnings&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                renderEarnings(data.data);
            }
        } catch (error) {
            console.error('Load earnings error:', error);
        }
    }
    
    function renderEarnings(earnings) {
        // Show sections
        document.getElementById('earnings-stats').style.display = 'grid';
        document.getElementById('payout-section').style.display = 'block';
        document.getElementById('transactions-section').style.display = 'block';
        
        // Update stats
        document.getElementById('stat-available').textContent = formatCurrency(earnings.available);
        document.getElementById('stat-pending').textContent = formatCurrency(earnings.pending);
        document.getElementById('stat-month').textContent = formatCurrency(earnings.this_month);
        document.getElementById('stat-total').textContent = formatCurrency(earnings.total);
        
        // Enable payout button if available >= 25
        const payoutBtn = document.getElementById('request-payout-btn');
        if (earnings.available >= 25) {
            payoutBtn.disabled = false;
            payoutBtn.textContent = `Request Payout (${formatCurrency(earnings.available)})`;
            payoutBtn.onclick = requestPayout;
        } else {
            payoutBtn.disabled = true;
            payoutBtn.textContent = 'Request Payout';
        }
        
        // Render transactions
        renderTransactions(earnings.transactions);
    }
    
    function renderTransactions(transactions) {
        const container = document.getElementById('transactions-list');
        
        if (!transactions || transactions.length === 0) {
            container.innerHTML = '<div class="ptp-empty">No transactions yet</div>';
            return;
        }
        
        container.innerHTML = transactions.map(tx => `
            <div class="ptp-transaction">
                <div class="ptp-transaction-icon">${tx.status === 'paid' ? '✓' : '⏳'}</div>
                <div class="ptp-transaction-info">
                    <div class="ptp-transaction-desc">
                        ${tx.parent_name ? `Session with ${tx.parent_name}` : 'Training Session'}
                    </div>
                    <div class="ptp-transaction-date">${formatDate(tx.created_at)}</div>
                </div>
                <div class="ptp-transaction-amount positive">+${formatCurrency(tx.trainer_amount)}</div>
                <span class="ptp-transaction-status ${tx.status}">${tx.status}</span>
            </div>
        `).join('');
    }
    
    window.connectStripe = async function() {
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_stripe_connect_start&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success && data.data.connect_url) {
                window.location.href = data.data.connect_url;
            } else {
                alert(data.data?.message || 'Connection failed');
            }
        } catch (error) {
            alert('Connection failed');
        }
    };
    
    window.openStripeDashboard = async function() {
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_stripe_dashboard_link&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success && data.data.url) {
                window.open(data.data.url, '_blank');
            }
        } catch (error) {
            alert('Could not open dashboard');
        }
    };
    
    async function requestPayout() {
        if (!confirm('Request payout for your available balance?')) return;
        
        const btn = document.getElementById('request-payout-btn');
        btn.disabled = true;
        btn.textContent = 'Processing...';
        
        try {
            const response = await fetch(config.ajax, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=ptp_request_payout&nonce=${config.nonce}`
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert(data.data.message);
                loadEarnings();
            } else {
                alert(data.data?.message || 'Payout failed');
            }
        } catch (error) {
            alert('Payout failed');
        }
        
        btn.disabled = false;
    }
    
    function formatCurrency(amount) {
        return '$' + parseFloat(amount || 0).toFixed(2);
    }
    
    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
})();
</script>
