<?php
/**
 * Reviews Class
 */

defined('ABSPATH') || exit;

class PTP_Reviews {
    
    public static function get($review_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_reviews WHERE id = %d",
            $review_id
        ));
    }
    
    public static function get_by_booking($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_reviews WHERE booking_id = %d",
            $booking_id
        ));
    }
    
    public static function create($booking_id, $rating, $review = '') {
        global $wpdb;
        
        $booking = PTP_Booking::get($booking_id);
        if (!$booking) {
            return new WP_Error('invalid_booking', 'Booking not found');
        }
        
        if ($booking->status !== 'completed') {
            return new WP_Error('not_completed', 'Can only review completed sessions');
        }
        
        // Check if already reviewed
        $existing = self::get_by_booking($booking_id);
        if ($existing) {
            return new WP_Error('already_reviewed', 'This session has already been reviewed');
        }
        
        $wpdb->insert($wpdb->prefix . 'ptp_reviews', array(
            'booking_id' => $booking_id,
            'trainer_id' => $booking->trainer_id,
            'parent_id' => $booking->parent_id,
            'rating' => max(1, min(5, intval($rating))),
            'review' => sanitize_textarea_field($review),
        ));
        
        $review_id = $wpdb->insert_id;
        
        // Update trainer stats
        PTP_Trainer::update_stats($booking->trainer_id);
        
        // Fire action for Google review prompt (triggers after 4-5 star reviews)
        do_action('ptp_review_created', $review_id, intval($rating));
        
        return $review_id;
    }
    
    public static function add_response($review_id, $trainer_id, $response) {
        global $wpdb;
        
        $review = self::get($review_id);
        if (!$review || $review->trainer_id != $trainer_id) {
            return new WP_Error('unauthorized', 'Cannot respond to this review');
        }
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_reviews',
            array(
                'trainer_response' => sanitize_textarea_field($response),
                'response_at' => current_time('mysql'),
            ),
            array('id' => $review_id)
        );
    }
    
    public static function get_trainer_reviews($trainer_id, $limit = 10, $offset = 0) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.display_name as parent_name
             FROM {$wpdb->prefix}ptp_reviews r
             JOIN {$wpdb->prefix}ptp_parents p ON r.parent_id = p.id
             WHERE r.trainer_id = %d AND r.is_published = 1
             ORDER BY r.created_at DESC
             LIMIT %d OFFSET %d",
            $trainer_id, $limit, $offset
        ));
    }
    
    public static function get_trainer_stats($trainer_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_reviews,
                AVG(rating) as average_rating,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
             FROM {$wpdb->prefix}ptp_reviews
             WHERE trainer_id = %d AND is_published = 1",
            $trainer_id
        ));
    }
}
