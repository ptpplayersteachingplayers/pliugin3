<?php
/**
 * PTP Trainer Loyalty System
 * 
 * Keeps trainers on platform through:
 * - Loyalty bonuses (increased payout after milestones)
 * - Supercoach status (premium positioning)
 * - Progress tracking (data lock-in)
 * - Package pre-payments (money already on platform)
 * - Quality scores (reputation tied to platform)
 * 
 * @since 59.3.0
 */

defined('ABSPATH') || exit;

class PTP_Trainer_Loyalty {
    
    // Loyalty tiers
    const TIER_BRONZE = 'bronze';      // 0-24 sessions
    const TIER_SILVER = 'silver';      // 25-49 sessions
    const TIER_GOLD = 'gold';          // 50-99 sessions
    const TIER_PLATINUM = 'platinum';  // 100+ sessions
    
    // Bonus percentages (added to base rate)
    // Note: Graduated base: 50%→50%→75%→75%→85% — bonuses apply to session 5+ ongoing rate
    const BONUS_BRONZE = 0.00;    // 85% base ongoing payout
    const BONUS_SILVER = 0.01;    // 86% ongoing payout
    const BONUS_GOLD = 0.02;      // 87% ongoing payout
    const BONUS_PLATINUM = 0.03;  // 88% ongoing payout
    const BONUS_SUPERCOACH = 0.02; // +2% for supercoach status (stackable)
    
    // Thresholds
    const SUPERCOACH_RATING = 4.9;
    const SUPERCOACH_MIN_REVIEWS = 10;
    const FAST_RESPONDER_HOURS = 6;
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Recalculate on session completion
        add_action('ptp_session_completed', array($this, 'on_session_completed'), 10, 2);
        add_action('ptp_review_submitted', array($this, 'check_supercoach_status'), 10, 2);
        
        // AJAX for trainer dashboard
        add_action('wp_ajax_ptp_get_loyalty_status', array($this, 'ajax_get_loyalty_status'));
        
        // Shortcode
        add_shortcode('ptp_loyalty_dashboard', array($this, 'shortcode_loyalty_dashboard'));
        
        // Filter payout calculations
        add_filter('ptp_trainer_payout_rate', array($this, 'apply_loyalty_bonus'), 10, 2);
    }
    
    /**
     * Get trainer's loyalty tier
     */
    public static function get_tier($trainer_id) {
        global $wpdb;
        
        $completed = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status = 'completed'
        ", $trainer_id));
        
        if ($completed >= 100) return self::TIER_PLATINUM;
        if ($completed >= 50) return self::TIER_GOLD;
        if ($completed >= 25) return self::TIER_SILVER;
        return self::TIER_BRONZE;
    }
    
    /**
     * Get tier info
     */
    public static function get_tier_info($tier) {
        $tiers = array(
            self::TIER_BRONZE => array(
                'name' => 'Bronze',
                'min_sessions' => 0,
                'bonus' => self::BONUS_BRONZE,
                'payout_rate' => 0.75 + self::BONUS_BRONZE,
                'color' => '#CD7F32',
                'icon' => '🥉',
                'next_tier' => self::TIER_SILVER,
                'next_at' => 25,
            ),
            self::TIER_SILVER => array(
                'name' => 'Silver',
                'min_sessions' => 25,
                'bonus' => self::BONUS_SILVER,
                'payout_rate' => 0.75 + self::BONUS_SILVER,
                'color' => '#C0C0C0',
                'icon' => '🥈',
                'next_tier' => self::TIER_GOLD,
                'next_at' => 50,
            ),
            self::TIER_GOLD => array(
                'name' => 'Gold',
                'min_sessions' => 50,
                'bonus' => self::BONUS_GOLD,
                'payout_rate' => 0.75 + self::BONUS_GOLD,
                'color' => '#FCB900',
                'icon' => '🥇',
                'next_tier' => self::TIER_PLATINUM,
                'next_at' => 100,
            ),
            self::TIER_PLATINUM => array(
                'name' => 'Platinum',
                'min_sessions' => 100,
                'bonus' => self::BONUS_PLATINUM,
                'payout_rate' => 0.75 + self::BONUS_PLATINUM,
                'color' => '#E5E4E2',
                'icon' => '💎',
                'next_tier' => null,
                'next_at' => null,
            ),
        );
        
        return $tiers[$tier] ?? $tiers[self::TIER_BRONZE];
    }
    
    /**
     * Get trainer's full loyalty status
     */
    public static function get_loyalty_status($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d
        ", $trainer_id));
        
        if (!$trainer) {
            return null;
        }
        
        // Session count
        $completed = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status = 'completed'
        ", $trainer_id));
        
        // Rating
        $rating = $wpdb->get_row($wpdb->prepare("
            SELECT AVG(rating) as avg, COUNT(*) as count
            FROM {$wpdb->prefix}ptp_reviews
            WHERE trainer_id = %d AND is_published = 1
        ", $trainer_id));
        
        // Current tier
        $tier = self::get_tier($trainer_id);
        $tier_info = self::get_tier_info($tier);
        
        // Check supercoach
        $is_supercoach = $rating->count >= self::SUPERCOACH_MIN_REVIEWS 
                         && $rating->avg >= self::SUPERCOACH_RATING;
        
        // Calculate effective payout rate
        $base_rate = 0.75;
        $tier_bonus = $tier_info['bonus'];
        $supercoach_bonus = $is_supercoach ? self::BONUS_SUPERCOACH : 0;
        $effective_rate = $base_rate + $tier_bonus + $supercoach_bonus;
        
        // Progress to next tier
        $progress = 0;
        $sessions_to_next = 0;
        if ($tier_info['next_tier']) {
            $sessions_to_next = $tier_info['next_at'] - $completed;
            $range = $tier_info['next_at'] - $tier_info['min_sessions'];
            $progress = (($completed - $tier_info['min_sessions']) / $range) * 100;
        } else {
            $progress = 100;
        }
        
        // Badges
        $badges = array();
        
        if ($is_supercoach) {
            $badges[] = array(
                'id' => 'supercoach',
                'name' => 'Supercoach',
                'icon' => '⭐',
                'color' => '#FCB900',
                'description' => '4.9+ rating with 10+ reviews'
            );
        }
        
        if (!empty($trainer->fast_responder)) {
            $badges[] = array(
                'id' => 'fast_responder',
                'name' => 'Fast Responder',
                'icon' => '⚡',
                'color' => '#3B82F6',
                'description' => 'Responds within 6 hours'
            );
        }
        
        if ($completed >= 100) {
            $badges[] = array(
                'id' => 'veteran',
                'name' => 'Veteran Trainer',
                'icon' => '🎖️',
                'color' => '#8B5CF6',
                'description' => '100+ sessions completed'
            );
        }
        
        // Earnings boost
        $monthly_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d AND status = 'completed'
            AND session_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", $trainer_id));
        
        $avg_session_rate = floatval($trainer->hourly_rate) ?: 120;
        $base_monthly = $monthly_sessions * $avg_session_rate * 0.75;
        $boosted_monthly = $monthly_sessions * $avg_session_rate * $effective_rate;
        $monthly_boost = $boosted_monthly - $base_monthly;
        
        return array(
            'trainer_id' => $trainer_id,
            'completed_sessions' => (int) $completed,
            'tier' => $tier,
            'tier_info' => $tier_info,
            'is_supercoach' => $is_supercoach,
            'avg_rating' => $rating->avg ? round($rating->avg, 2) : null,
            'review_count' => (int) $rating->count,
            'base_rate' => $base_rate,
            'tier_bonus' => $tier_bonus,
            'supercoach_bonus' => $supercoach_bonus,
            'effective_rate' => $effective_rate,
            'progress_to_next' => min(100, $progress),
            'sessions_to_next' => max(0, $sessions_to_next),
            'badges' => $badges,
            'monthly_sessions' => (int) $monthly_sessions,
            'monthly_boost' => round($monthly_boost, 2),
            'platform_benefits' => self::get_platform_benefits($trainer_id),
        );
    }
    
    /**
     * Get platform benefits summary (why stay on platform)
     */
    public static function get_platform_benefits($trainer_id) {
        global $wpdb;
        
        // Total earned through platform
        $total_earned = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(b.total_amount * 0.75) 
            FROM {$wpdb->prefix}ptp_bookings b
            WHERE b.trainer_id = %d AND b.status = 'completed'
        ", $trainer_id)) ?: 0;
        
        // Pending package sessions (money already on platform)
        $pending_package = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_bookings
            WHERE trainer_id = %d 
            AND status = 'confirmed'
            AND package_id IS NOT NULL
        ", $trainer_id)) ?: 0;
        
        // Session notes count
        $notes_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_session_notes
            WHERE trainer_id = %d
        ", $trainer_id)) ?: 0;
        
        // Reviews count
        $reviews = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_reviews
            WHERE trainer_id = %d AND is_published = 1
        ", $trainer_id)) ?: 0;
        
        // Repeat parents
        $repeat_parents = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT parent_id) FROM (
                SELECT parent_id, COUNT(*) as cnt
                FROM {$wpdb->prefix}ptp_bookings
                WHERE trainer_id = %d AND status = 'completed'
                GROUP BY parent_id
                HAVING cnt > 1
            ) as repeats
        ", $trainer_id)) ?: 0;
        
        return array(
            'total_earned' => round($total_earned, 2),
            'pending_package_sessions' => (int) $pending_package,
            'session_notes' => (int) $notes_count,
            'reviews' => (int) $reviews,
            'repeat_parents' => (int) $repeat_parents,
            'value_locked' => round($pending_package * 100 * 0.75, 2), // Approx value
        );
    }
    
    /**
     * Apply loyalty bonus to payout rate
     */
    public function apply_loyalty_bonus($rate, $trainer_id) {
        $status = self::get_loyalty_status($trainer_id);
        
        if ($status) {
            return $status['effective_rate'];
        }
        
        return $rate;
    }
    
    /**
     * On session completed - check for tier upgrades
     */
    public function on_session_completed($booking_id, $trainer_id) {
        global $wpdb;
        
        $status = self::get_loyalty_status($trainer_id);
        
        if (!$status) return;
        
        // Check for tier upgrade
        $current_tier = $wpdb->get_var($wpdb->prepare("
            SELECT loyalty_tier FROM {$wpdb->prefix}ptp_trainers WHERE id = %d
        ", $trainer_id));
        
        if ($current_tier !== $status['tier']) {
            // Tier upgrade!
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                array('loyalty_tier' => $status['tier']),
                array('id' => $trainer_id)
            );
            
            // Notify trainer
            $tier_info = $status['tier_info'];
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t
                 JOIN {$wpdb->prefix}users u ON t.user_id = u.ID
                 WHERE t.id = %d",
                $trainer_id
            ));
            
            if ($trainer && $trainer->user_email) {
                $subject = sprintf('🎉 You\'ve reached %s status!', $tier_info['name']);
                $message = sprintf(
                    "Congratulations %s!\n\n" .
                    "You've completed %d sessions and earned %s %s status!\n\n" .
                    "Your new payout rate: %d%%\n\n" .
                    "Keep training to unlock even more rewards.\n\n" .
                    "- The PTP Team",
                    $trainer->display_name,
                    $status['completed_sessions'],
                    $tier_info['icon'],
                    $tier_info['name'],
                    round($status['effective_rate'] * 100)
                );
                
                wp_mail($trainer->user_email, $subject, $message);
            }
        }
    }
    
    /**
     * Check and update supercoach status
     */
    public function check_supercoach_status($review_id, $rating) {
        global $wpdb;
        
        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT trainer_id FROM {$wpdb->prefix}ptp_reviews WHERE id = %d",
            $review_id
        ));
        
        if (!$review) return;
        
        $status = self::get_loyalty_status($review->trainer_id);
        
        if ($status && $status['is_supercoach']) {
            // Check if newly earned
            $already_supercoach = $wpdb->get_var($wpdb->prepare(
                "SELECT is_supercoach FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $review->trainer_id
            ));
            
            if (!$already_supercoach) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    array(
                        'is_supercoach' => 1,
                        'supercoach_awarded_at' => current_time('mysql')
                    ),
                    array('id' => $review->trainer_id)
                );
                
                // Notify trainer
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t
                     JOIN {$wpdb->prefix}users u ON t.user_id = u.ID
                     WHERE t.id = %d",
                    $review->trainer_id
                ));
                
                if ($trainer && $trainer->user_email) {
                    $subject = '⭐ You\'re now a Supercoach!';
                    $message = sprintf(
                        "Amazing work %s!\n\n" .
                        "You've earned Supercoach status with a %.2f rating from %d reviews!\n\n" .
                        "Your rewards:\n" .
                        "• +2%% bonus on all payouts (now %d%%)\n" .
                        "• Priority placement in search results\n" .
                        "• Supercoach badge on your profile\n\n" .
                        "Keep up the excellent training!\n\n" .
                        "- The PTP Team",
                        $trainer->display_name,
                        $status['avg_rating'],
                        $status['review_count'],
                        round($status['effective_rate'] * 100)
                    );
                    
                    wp_mail($trainer->user_email, $subject, $message);
                }
            }
        }
    }
    
    /**
     * AJAX get loyalty status
     */
    public function ajax_get_loyalty_status() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        
        // If no trainer_id, get current user's trainer record
        if (!$trainer_id) {
            global $wpdb;
            $trainer_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
                get_current_user_id()
            ));
        }
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        $status = self::get_loyalty_status($trainer_id);
        
        if (!$status) {
            wp_send_json_error(array('message' => 'Could not retrieve loyalty status'));
        }
        
        wp_send_json_success($status);
    }
    
    /**
     * Loyalty dashboard shortcode
     */
    public function shortcode_loyalty_dashboard($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to view your loyalty status.</p>';
        }
        
        global $wpdb;
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer_id) {
            return '<p>Trainer account not found.</p>';
        }
        
        $status = self::get_loyalty_status($trainer_id);
        
        if (!$status) {
            return '<p>Could not load loyalty status.</p>';
        }
        
        ob_start();
        ?>
        <div class="ptp-loyalty-dashboard">
            <style>
                .ptp-loyalty-dashboard{font-family:Inter,-apple-system,sans-serif;max-width:600px}
                .ptp-loyalty-header{text-align:center;padding:30px;background:#0A0A0A;color:#fff;margin-bottom:24px}
                .ptp-loyalty-tier-icon{font-size:48px;margin-bottom:10px}
                .ptp-loyalty-tier-name{font-family:Oswald,sans-serif;font-size:24px;text-transform:uppercase;letter-spacing:2px}
                .ptp-loyalty-rate{margin-top:10px;font-size:32px;font-weight:700;color:#FCB900}
                .ptp-loyalty-rate span{font-size:14px;color:#9CA3AF;font-weight:400}
                .ptp-loyalty-progress{background:#fff;border:2px solid #E5E5E5;padding:20px;margin-bottom:20px}
                .ptp-loyalty-progress-title{font-weight:600;margin-bottom:12px}
                .ptp-loyalty-progress-bar{height:12px;background:#E5E5E5;overflow:hidden}
                .ptp-loyalty-progress-fill{height:100%;background:#FCB900;transition:width 0.5s}
                .ptp-loyalty-progress-text{display:flex;justify-content:space-between;margin-top:8px;font-size:13px;color:#6B7280}
                .ptp-loyalty-badges{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
                .ptp-loyalty-badge{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:2px solid;font-size:13px;font-weight:600}
                .ptp-loyalty-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:20px}
                .ptp-loyalty-stat{background:#F9FAFB;padding:16px;text-align:center;border:1px solid #E5E5E5}
                .ptp-loyalty-stat-value{font-size:24px;font-weight:700;font-family:Oswald,sans-serif}
                .ptp-loyalty-stat-label{font-size:12px;color:#6B7280;text-transform:uppercase;letter-spacing:0.5px;margin-top:4px}
                .ptp-loyalty-benefits{background:#D1FAE5;border:2px solid #10B981;padding:20px}
                .ptp-loyalty-benefits h4{margin:0 0 12px;color:#065F46}
                .ptp-loyalty-benefits ul{margin:0;padding-left:20px;color:#047857;font-size:14px}
                .ptp-loyalty-benefits li{margin-bottom:6px}
                .ptp-loyalty-locked{background:#FEF3C7;border:2px solid #F59E0B;padding:16px;margin-top:20px;font-size:14px;color:#92400E}
            </style>
            
            <div class="ptp-loyalty-header">
                <div class="ptp-loyalty-tier-icon"><?php echo $status['tier_info']['icon']; ?></div>
                <div class="ptp-loyalty-tier-name"><?php echo $status['tier_info']['name']; ?> Trainer</div>
                <div class="ptp-loyalty-rate">
                    <?php echo round($status['effective_rate'] * 100); ?>%
                    <span>payout rate</span>
                </div>
            </div>
            
            <?php if (!empty($status['badges'])): ?>
            <div class="ptp-loyalty-badges">
                <?php foreach ($status['badges'] as $badge): ?>
                <span class="ptp-loyalty-badge" style="border-color:<?php echo $badge['color']; ?>;color:<?php echo $badge['color']; ?>">
                    <?php echo $badge['icon']; ?> <?php echo $badge['name']; ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($status['tier_info']['next_tier']): ?>
            <div class="ptp-loyalty-progress">
                <div class="ptp-loyalty-progress-title">
                    Progress to <?php echo self::get_tier_info($status['tier_info']['next_tier'])['name']; ?>
                </div>
                <div class="ptp-loyalty-progress-bar">
                    <div class="ptp-loyalty-progress-fill" style="width:<?php echo $status['progress_to_next']; ?>%"></div>
                </div>
                <div class="ptp-loyalty-progress-text">
                    <span><?php echo $status['completed_sessions']; ?> sessions</span>
                    <span><?php echo $status['sessions_to_next']; ?> more to unlock +<?php echo round(self::get_tier_info($status['tier_info']['next_tier'])['bonus'] * 100); ?>%</span>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="ptp-loyalty-stats">
                <div class="ptp-loyalty-stat">
                    <div class="ptp-loyalty-stat-value"><?php echo $status['completed_sessions']; ?></div>
                    <div class="ptp-loyalty-stat-label">Sessions Completed</div>
                </div>
                <div class="ptp-loyalty-stat">
                    <div class="ptp-loyalty-stat-value"><?php echo $status['avg_rating'] ? number_format($status['avg_rating'], 1) . '★' : '--'; ?></div>
                    <div class="ptp-loyalty-stat-label">Average Rating</div>
                </div>
                <div class="ptp-loyalty-stat">
                    <div class="ptp-loyalty-stat-value">$<?php echo number_format($status['platform_benefits']['total_earned']); ?></div>
                    <div class="ptp-loyalty-stat-label">Total Earned</div>
                </div>
                <div class="ptp-loyalty-stat">
                    <div class="ptp-loyalty-stat-value">+$<?php echo number_format($status['monthly_boost']); ?></div>
                    <div class="ptp-loyalty-stat-label">Monthly Bonus</div>
                </div>
            </div>
            
            <div class="ptp-loyalty-benefits">
                <h4>Your Platform Benefits</h4>
                <ul>
                    <li><strong><?php echo $status['platform_benefits']['reviews']; ?> reviews</strong> building your reputation</li>
                    <li><strong><?php echo $status['platform_benefits']['repeat_parents']; ?> repeat parents</strong> who keep booking you</li>
                    <li><strong><?php echo $status['platform_benefits']['session_notes']; ?> session notes</strong> tracking player progress</li>
                    <?php if ($status['platform_benefits']['pending_package_sessions'] > 0): ?>
                    <li><strong><?php echo $status['platform_benefits']['pending_package_sessions']; ?> pre-paid sessions</strong> (≈$<?php echo number_format($status['platform_benefits']['value_locked']); ?>) waiting</li>
                    <?php endif; ?>
                    <li>Instant same-day payouts</li>
                    <li>Automatic scheduling & reminders</li>
                </ul>
            </div>
            
            <?php if (!$status['is_supercoach'] && $status['review_count'] >= 5): ?>
            <div class="ptp-loyalty-locked">
                <strong>🔒 Supercoach Status</strong><br>
                Reach <?php echo self::SUPERCOACH_RATING; ?>★ rating with <?php echo self::SUPERCOACH_MIN_REVIEWS; ?>+ reviews to unlock +2% bonus payout and priority placement.
                <br><br>
                You have: <?php echo number_format($status['avg_rating'], 2); ?>★ from <?php echo $status['review_count']; ?> reviews
                <?php if ($status['avg_rating'] < self::SUPERCOACH_RATING): ?>
                <br>Need: <?php echo number_format(self::SUPERCOACH_RATING - $status['avg_rating'], 2); ?>★ improvement
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
PTP_Trainer_Loyalty::instance();
