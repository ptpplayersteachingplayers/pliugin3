<?php
/**
 * Trainer Contact Form Component - v71
 * Embeddable contact form for trainer profiles
 * 
 * Usage: <?php include PTP_PLUGIN_DIR . 'templates/components/trainer-contact-form.php'; ?>
 * Required: $trainer variable with trainer data
 */

defined('ABSPATH') || exit;

if (!isset($trainer) || !$trainer) {
    return;
}

$trainer_id = $trainer->id;
$is_logged_in = is_user_logged_in();
$current_user = wp_get_current_user();
?>

<div class="ptp-trainer-contact-form" id="contact-form">
    <div class="ptp-contact-header">
        <h3>Message <?php echo esc_html($trainer->display_name); ?></h3>
        <p>Have questions? Send a message and get a response within 24 hours.</p>
    </div>
    
    <form class="ptp-contact-form" data-trainer-id="<?php echo esc_attr($trainer_id); ?>">
        <?php wp_nonce_field('ptp_nonce', 'ptp_contact_nonce'); ?>
        
        <?php if (!$is_logged_in): ?>
            <div class="ptp-form-row">
                <div class="ptp-form-group">
                    <label class="ptp-label" for="contact-name">Your Name</label>
                    <input type="text" id="contact-name" name="name" class="ptp-input" required placeholder="John Smith">
                </div>
                <div class="ptp-form-group">
                    <label class="ptp-label" for="contact-email">Email</label>
                    <input type="email" id="contact-email" name="email" class="ptp-input" required placeholder="john@example.com">
                </div>
            </div>
            <div class="ptp-form-group">
                <label class="ptp-label" for="contact-phone">Phone (optional)</label>
                <input type="tel" id="contact-phone" name="phone" class="ptp-input" placeholder="(555) 123-4567">
            </div>
        <?php else: ?>
            <input type="hidden" name="name" value="<?php echo esc_attr($current_user->display_name); ?>">
            <input type="hidden" name="email" value="<?php echo esc_attr($current_user->user_email); ?>">
            <p class="ptp-logged-in-as">
                Sending as <strong><?php echo esc_html($current_user->display_name); ?></strong>
            </p>
        <?php endif; ?>
        
        <div class="ptp-form-group">
            <label class="ptp-label" for="contact-message">Message</label>
            <textarea id="contact-message" name="message" class="ptp-textarea" required rows="4" 
                      placeholder="Hi <?php echo esc_attr($trainer->display_name); ?>, I'm interested in training sessions..."></textarea>
        </div>
        
        <button type="submit" class="ptp-btn ptp-btn-primary ptp-btn-block">
            <span class="btn-text">Send Message</span>
            <span class="btn-loading" style="display:none;">
                <svg class="ptp-spinner-inline" width="20" height="20" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="30 70" />
                </svg>
                Sending...
            </span>
        </button>
    </form>
    
    <div class="ptp-contact-success" style="display:none;">
        <div class="ptp-success-icon">✓</div>
        <h4>Message Sent!</h4>
        <p>Your message has been delivered. <?php echo esc_html($trainer->display_name); ?> will respond soon.</p>
        <a href="<?php echo home_url('/messages/'); ?>" class="ptp-btn ptp-btn-primary">View Messages</a>
    </div>
    
    <div class="ptp-contact-error" style="display:none;">
        <p class="error-message"></p>
        <button type="button" class="ptp-btn ptp-btn-outline ptp-try-again">Try Again</button>
    </div>
</div>

<style>
.ptp-trainer-contact-form {
    background: #fff;
    border: 2px solid #0A0A0A;
    padding: 24px;
}

.ptp-contact-header {
    margin-bottom: 20px;
}

.ptp-contact-header h3 {
    margin: 0 0 8px;
    font-family: 'Oswald', sans-serif;
    font-size: 20px;
    font-weight: 600;
    text-transform: uppercase;
    color: #0A0A0A;
}

.ptp-contact-header p {
    margin: 0;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    color: #666;
}

.ptp-logged-in-as {
    padding: 12px 16px;
    background: #F5F5F5;
    border: 1px solid #E5E5E5;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    margin-bottom: 16px;
}

.ptp-contact-success,
.ptp-contact-error {
    text-align: center;
    padding: 40px 20px;
}

.ptp-success-icon {
    width: 60px;
    height: 60px;
    margin: 0 auto 16px;
    background: #4CAF50;
    color: #fff;
    font-size: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ptp-contact-success h4 {
    font-family: 'Oswald', sans-serif;
    font-size: 24px;
    font-weight: 600;
    margin: 0 0 8px;
}

.ptp-contact-success p {
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    color: #666;
    margin: 0 0 20px;
}

.ptp-contact-error {
    background: #FFEBEE;
    border: 2px solid #F44336;
}

.ptp-contact-error .error-message {
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    color: #C62828;
    margin: 0 0 16px;
}

.ptp-spinner-inline {
    animation: ptp-spin-inline 0.8s linear infinite;
    margin-right: 8px;
}

@keyframes ptp-spin-inline {
    to { transform: rotate(360deg); }
}

/* Mobile */
@media (max-width: 639px) {
    .ptp-trainer-contact-form {
        padding: 20px 16px;
    }
    
    .ptp-form-row {
        flex-direction: column;
        gap: 16px !important;
    }
}
</style>

<script>
(function() {
    const form = document.querySelector('.ptp-contact-form');
    if (!form) return;
    
    const trainerId = form.dataset.trainerId;
    const submitBtn = form.querySelector('button[type="submit"]');
    const btnText = submitBtn.querySelector('.btn-text');
    const btnLoading = submitBtn.querySelector('.btn-loading');
    const formContainer = form.parentElement;
    const successDiv = formContainer.querySelector('.ptp-contact-success');
    const errorDiv = formContainer.querySelector('.ptp-contact-error');
    const errorMessage = errorDiv.querySelector('.error-message');
    const tryAgainBtn = errorDiv.querySelector('.ptp-try-again');
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Show loading
        submitBtn.disabled = true;
        btnText.style.display = 'none';
        btnLoading.style.display = 'inline-flex';
        
        const formData = {
            name: form.querySelector('[name="name"]').value,
            email: form.querySelector('[name="email"]').value,
            phone: form.querySelector('[name="phone"]')?.value || '',
            message: form.querySelector('[name="message"]').value
        };
        
        try {
            const data = new FormData();
            data.append('action', '<?php echo $is_logged_in ? "ptp_send_public_message" : "ptp_send_public_message_guest"; ?>');
            data.append('nonce', '<?php echo wp_create_nonce("ptp_nonce"); ?>');
            data.append('trainer_id', trainerId);
            data.append('name', formData.name);
            data.append('email', formData.email);
            data.append('phone', formData.phone);
            data.append('message', formData.message);
            
            const response = await fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            });
            
            const result = await response.json();
            
            if (result.success) {
                form.style.display = 'none';
                successDiv.style.display = 'block';
                
                // Update success link with conversation ID
                if (result.data?.conversation_id) {
                    const msgLink = successDiv.querySelector('a');
                    if (msgLink) {
                        msgLink.href = '/messages/?conversation=' + result.data.conversation_id;
                    }
                }
            } else {
                form.style.display = 'none';
                errorDiv.style.display = 'block';
                errorMessage.textContent = result.data?.message || 'Failed to send message. Please try again.';
            }
        } catch (error) {
            console.error('Contact form error:', error);
            form.style.display = 'none';
            errorDiv.style.display = 'block';
            errorMessage.textContent = 'An error occurred. Please try again.';
        }
        
        // Reset button
        submitBtn.disabled = false;
        btnText.style.display = 'inline';
        btnLoading.style.display = 'none';
    });
    
    // Try again button
    if (tryAgainBtn) {
        tryAgainBtn.addEventListener('click', function() {
            errorDiv.style.display = 'none';
            form.style.display = 'block';
        });
    }
})();
</script>
