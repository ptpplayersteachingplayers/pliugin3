<?php
/**
 * Session Confirmation Component
 * Displays pending confirmations for both parents and trainers
 * PTP v25.1 - Escrow System
 */
defined('ABSPATH') || exit;

class PTP_Session_Confirmation {
    
    /**
     * Render pending confirmations for parent
     */
    public static function render_parent_pending($parent_id) {
        global $wpdb;
        
        // Get sessions awaiting parent confirmation
        $pending = $wpdb->get_results($wpdb->prepare("
            SELECT e.*, b.session_date, b.start_time, b.location,
                   t.display_name as trainer_name, t.photo_url as trainer_photo,
                   p.name as player_name
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            WHERE e.parent_id = %d 
            AND e.status = 'session_complete'
            ORDER BY e.trainer_completed_at DESC
        ", $parent_id));
        
        if (empty($pending)) {
            return '';
        }
        
        ob_start();
        ?>
        <div class="ptp-confirm-sessions">
            <div class="ptp-confirm-header">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    Confirm Your Sessions
                </h3>
                <p>These sessions have been marked complete by trainers. Please confirm to release payment.</p>
            </div>
            
            <?php foreach ($pending as $session): 
                $release_time = strtotime($session->release_eligible_at);
                $hours_left = max(0, round(($release_time - time()) / 3600, 1));
                $trainer_photo = $session->trainer_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($session->trainer_name) . '&size=80&background=FCB900&color=0A0A0A&bold=true';
            ?>
            <div class="ptp-confirm-card" data-booking="<?php echo $session->booking_id; ?>">
                <div class="ptp-confirm-info">
                    <img src="<?php echo esc_url($trainer_photo); ?>" alt="" class="ptp-confirm-photo">
                    <div class="ptp-confirm-details">
                        <div class="ptp-confirm-trainer"><?php echo esc_html($session->trainer_name); ?></div>
                        <div class="ptp-confirm-meta">
                            <?php echo esc_html($session->player_name); ?> • 
                            <?php echo date('M j', strtotime($session->session_date)); ?> at 
                            <?php echo date('g:i A', strtotime($session->start_time)); ?>
                        </div>
                        <div class="ptp-confirm-amount">$<?php echo number_format($session->total_amount, 2); ?></div>
                    </div>
                </div>
                
                <div class="ptp-confirm-timer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Auto-confirms in <?php echo $hours_left; ?> hours
                </div>
                
                <div class="ptp-confirm-actions">
                    <button type="button" class="ptp-btn-confirm" onclick="confirmSession(<?php echo $session->booking_id; ?>)">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        Confirm & Release Payment
                    </button>
                    <button type="button" class="ptp-btn-dispute" onclick="showDisputeForm(<?php echo $session->booking_id; ?>)">
                        Report Issue
                    </button>
                </div>
                
                <!-- Hidden dispute form -->
                <div class="ptp-dispute-form" id="dispute-form-<?php echo $session->booking_id; ?>" style="display: none;">
                    <div class="ptp-dispute-header">
                        <h4>Report an Issue</h4>
                        <p>Please describe what went wrong with this session.</p>
                    </div>
                    <textarea class="ptp-dispute-reason" id="dispute-reason-<?php echo $session->booking_id; ?>" 
                              placeholder="e.g., Session didn't happen, trainer was late, session was cut short..."></textarea>
                    <div class="ptp-dispute-actions">
                        <button type="button" class="ptp-btn-cancel" onclick="hideDisputeForm(<?php echo $session->booking_id; ?>)">Cancel</button>
                        <button type="button" class="ptp-btn-submit-dispute" onclick="submitDispute(<?php echo $session->booking_id; ?>)">Submit Dispute</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <style>
        .ptp-confirm-sessions {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            border: 2px solid #F59E0B;
        }
        
        .ptp-confirm-header {
            margin-bottom: 16px;
        }
        
        .ptp-confirm-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 700;
            color: #92400E;
            margin: 0 0 8px;
        }
        
        .ptp-confirm-header p {
            font-size: 14px;
            color: #B45309;
            margin: 0;
        }
        
        .ptp-confirm-card {
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .ptp-confirm-card:last-child {
            margin-bottom: 0;
        }
        
        .ptp-confirm-info {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 12px;
        }
        
        .ptp-confirm-photo {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #FCB900;
        }
        
        .ptp-confirm-details {
            flex: 1;
        }
        
        .ptp-confirm-trainer {
            font-weight: 700;
            font-size: 16px;
            color: #111827;
        }
        
        .ptp-confirm-meta {
            font-size: 13px;
            color: #6B7280;
            margin: 2px 0;
        }
        
        .ptp-confirm-amount {
            font-size: 18px;
            font-weight: 800;
            color: #10B981;
        }
        
        .ptp-confirm-timer {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #D97706;
            background: #FEF3C7;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        
        .ptp-confirm-actions {
            display: flex;
            gap: 10px;
        }
        
        .ptp-btn-confirm {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            background: #10B981;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .ptp-btn-confirm:hover {
            background: #059669;
        }
        
        .ptp-btn-dispute {
            padding: 12px 16px;
            background: #fff;
            color: #6B7280;
            border: 2px solid #E5E7EB;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .ptp-btn-dispute:hover {
            border-color: #EF4444;
            color: #EF4444;
        }
        
        .ptp-dispute-form {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #E5E7EB;
        }
        
        .ptp-dispute-header h4 {
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 4px;
            color: #111827;
        }
        
        .ptp-dispute-header p {
            font-size: 13px;
            color: #6B7280;
            margin: 0 0 12px;
        }
        
        .ptp-dispute-reason {
            width: 100%;
            padding: 12px;
            border: 2px solid #E5E7EB;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 80px;
            margin-bottom: 12px;
        }
        
        .ptp-dispute-reason:focus {
            outline: none;
            border-color: #EF4444;
        }
        
        .ptp-dispute-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .ptp-btn-cancel {
            padding: 10px 16px;
            background: #F3F4F6;
            color: #374151;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .ptp-btn-submit-dispute {
            padding: 10px 16px;
            background: #EF4444;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        
        .ptp-btn-submit-dispute:hover {
            background: #DC2626;
        }
        </style>
        
        <script>
        function confirmSession(bookingId) {
            if (!confirm('Confirm this session was completed satisfactorily? Payment will be released to the trainer.')) {
                return;
            }
            
            const card = document.querySelector('[data-booking="' + bookingId + '"]');
            const btn = card.querySelector('.ptp-btn-confirm');
            btn.disabled = true;
            btn.innerHTML = 'Confirming...';
            
            const formData = new FormData();
            formData.append('action', 'ptp_parent_confirm_session');
            formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
            formData.append('booking_id', bookingId);
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    card.innerHTML = '<div style="text-align:center;padding:20px;color:#10B981;"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg><p style="margin:8px 0 0;font-weight:700;">Session Confirmed!</p></div>';
                    setTimeout(() => card.remove(), 2000);
                } else {
                    alert(data.data?.message || 'Error confirming session');
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Confirm & Release Payment';
                }
            })
            .catch(() => {
                alert('Error confirming session');
                btn.disabled = false;
            });
        }
        
        function showDisputeForm(bookingId) {
            document.getElementById('dispute-form-' + bookingId).style.display = 'block';
        }
        
        function hideDisputeForm(bookingId) {
            document.getElementById('dispute-form-' + bookingId).style.display = 'none';
        }
        
        function submitDispute(bookingId) {
            const reason = document.getElementById('dispute-reason-' + bookingId).value.trim();
            
            if (!reason) {
                alert('Please describe the issue');
                return;
            }
            
            if (!confirm('Submit this dispute? Our team will review and contact both parties within 24-48 hours.')) {
                return;
            }
            
            const form = document.getElementById('dispute-form-' + bookingId);
            const btn = form.querySelector('.ptp-btn-submit-dispute');
            btn.disabled = true;
            btn.innerHTML = 'Submitting...';
            
            const formData = new FormData();
            formData.append('action', 'ptp_parent_dispute_session');
            formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
            formData.append('booking_id', bookingId);
            formData.append('reason', reason);
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const card = document.querySelector('[data-booking="' + bookingId + '"]');
                    card.innerHTML = '<div style="text-align:center;padding:20px;color:#D97706;"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><p style="margin:8px 0 0;font-weight:700;">Dispute Submitted</p><p style="font-size:13px;color:#92400E;">We\'ll review within 24-48 hours</p></div>';
                } else {
                    alert(data.data?.message || 'Error submitting dispute');
                    btn.disabled = false;
                    btn.innerHTML = 'Submit Dispute';
                }
            })
            .catch(() => {
                alert('Error submitting dispute');
                btn.disabled = false;
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render sessions awaiting trainer completion mark
     */
    public static function render_trainer_pending($trainer_id) {
        global $wpdb;
        
        // Get past sessions that need to be marked complete
        $pending = $wpdb->get_results($wpdb->prepare("
            SELECT e.*, b.session_date, b.start_time, b.end_time, b.location,
                   p.name as player_name,
                   pa.display_name as parent_name
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.trainer_id = %d 
            AND e.status = 'holding'
            AND CONCAT(b.session_date, ' ', b.end_time) < NOW()
            ORDER BY b.session_date DESC
        ", $trainer_id));
        
        if (empty($pending)) {
            return '';
        }
        
        ob_start();
        ?>
        <div class="ptp-complete-sessions">
            <div class="ptp-complete-header">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Mark Sessions Complete
                </h3>
                <p>These sessions are past their scheduled time. Mark them complete to get paid.</p>
            </div>
            
            <?php foreach ($pending as $session): ?>
            <div class="ptp-complete-card" data-booking="<?php echo $session->booking_id; ?>">
                <div class="ptp-complete-info">
                    <div class="ptp-complete-player"><?php echo esc_html($session->player_name ?: 'Player'); ?></div>
                    <div class="ptp-complete-meta">
                        <?php echo date('l, M j', strtotime($session->session_date)); ?> • 
                        <?php echo date('g:i A', strtotime($session->start_time)); ?>
                    </div>
                    <div class="ptp-complete-earnings">
                        You'll earn: <strong>$<?php echo number_format($session->trainer_amount, 2); ?></strong>
                    </div>
                </div>
                
                <button type="button" class="ptp-btn-complete" onclick="markSessionComplete(<?php echo $session->booking_id; ?>)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Mark Complete
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        
        <style>
        .ptp-complete-sessions {
            background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            border: 2px solid #3B82F6;
        }
        
        .ptp-complete-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 700;
            color: #1E40AF;
            margin: 0 0 8px;
        }
        
        .ptp-complete-header p {
            font-size: 14px;
            color: #1E3A8A;
            margin: 0 0 16px;
        }
        
        .ptp-complete-card {
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .ptp-complete-card:last-child {
            margin-bottom: 0;
        }
        
        .ptp-complete-player {
            font-weight: 700;
            font-size: 15px;
            color: #111827;
        }
        
        .ptp-complete-meta {
            font-size: 13px;
            color: #6B7280;
        }
        
        .ptp-complete-earnings {
            font-size: 14px;
            color: #374151;
            margin-top: 4px;
        }
        
        .ptp-complete-earnings strong {
            color: #10B981;
        }
        
        .ptp-btn-complete {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: #FCB900;
            color: #0E0F11;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        
        .ptp-btn-complete:hover {
            background: #E5A800;
            transform: translateY(-1px);
        }
        </style>
        
        <script>
        function markSessionComplete(bookingId) {
            if (!confirm('Confirm this training session was completed?')) {
                return;
            }
            
            const card = document.querySelector('.ptp-complete-card[data-booking="' + bookingId + '"]');
            const btn = card.querySelector('.ptp-btn-complete');
            btn.disabled = true;
            btn.innerHTML = 'Processing...';
            
            const formData = new FormData();
            formData.append('action', 'ptp_trainer_complete_session');
            formData.append('nonce', '<?php echo wp_create_nonce('ptp_nonce'); ?>');
            formData.append('booking_id', bookingId);
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    card.innerHTML = '<div style="flex:1;color:#10B981;font-weight:600;"><svg width="16" height="16" style="vertical-align:middle;margin-right:6px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Marked Complete - awaiting parent confirmation</div>';
                    setTimeout(() => card.remove(), 3000);
                } else {
                    alert(data.data?.message || 'Error marking complete');
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Mark Complete';
                }
            })
            .catch(() => {
                alert('Error marking complete');
                btn.disabled = false;
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render escrow status indicator for a booking
     */
    public static function render_escrow_status($booking_id) {
        $escrow = PTP_Escrow::get_status($booking_id);
        
        if (!$escrow) {
            return '';
        }
        
        $status_labels = array(
            'holding' => array('label' => 'Payment Secured', 'color' => '#3B82F6', 'bg' => '#DBEAFE'),
            'session_complete' => array('label' => 'Awaiting Confirmation', 'color' => '#D97706', 'bg' => '#FEF3C7'),
            'confirmed' => array('label' => 'Confirmed', 'color' => '#10B981', 'bg' => '#D1FAE5'),
            'disputed' => array('label' => 'Under Review', 'color' => '#EF4444', 'bg' => '#FEE2E2'),
            'released' => array('label' => 'Payment Released', 'color' => '#10B981', 'bg' => '#D1FAE5'),
            'refunded' => array('label' => 'Refunded', 'color' => '#6B7280', 'bg' => '#F3F4F6'),
        );
        
        $status = $status_labels[$escrow->status] ?? array('label' => ucfirst($escrow->status), 'color' => '#6B7280', 'bg' => '#F3F4F6');
        
        return sprintf(
            '<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:%s;color:%s;border-radius:20px;font-size:12px;font-weight:600;">
                <span style="width:8px;height:8px;border-radius:50%%;background:%s;"></span>
                %s
            </span>',
            esc_attr($status['bg']),
            esc_attr($status['color']),
            esc_attr($status['color']),
            esc_html($status['label'])
        );
    }
}
