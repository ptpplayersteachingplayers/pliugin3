<?php
/**
 * PTP FAQ Class v34.0.0
 * 
 * Handles FAQ management for both admin-level (global) FAQs
 * and trainer-specific FAQs on profiles.
 */
defined('ABSPATH') || exit;

class PTP_FAQ {
    
    /**
     * Get active admin FAQs (global FAQs shown on all trainer profiles)
     */
    public static function get_admin_faqs() {
        $all = self::get_all_admin_faqs();
        return array_filter($all, function($faq) {
            return !empty($faq['active']);
        });
    }
    
    /**
     * Get all admin FAQs including inactive
     */
    public static function get_all_admin_faqs() {
        $faqs = get_option('ptp_admin_faqs', array());
        if (!is_array($faqs)) {
            $faqs = array();
        }
        // Sort by order
        usort($faqs, function($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });
        return $faqs;
    }
    
    /**
     * Save all admin FAQs
     */
    public static function save_admin_faqs($faqs) {
        if (!is_array($faqs)) {
            return false;
        }
        return update_option('ptp_admin_faqs', $faqs);
    }
    
    /**
     * Add a new admin FAQ
     */
    public static function add_admin_faq($question, $answer, $order = 0) {
        $faqs = self::get_all_admin_faqs();
        $faqs[] = array(
            'id' => uniqid('faq_'),
            'question' => sanitize_text_field($question),
            'answer' => wp_kses_post($answer),
            'order' => intval($order),
            'active' => true,
            'created_at' => current_time('mysql')
        );
        return self::save_admin_faqs($faqs);
    }
    
    /**
     * Update an admin FAQ by ID
     */
    public static function update_admin_faq($id, $data) {
        $faqs = self::get_all_admin_faqs();
        foreach ($faqs as $key => $faq) {
            if ($faq['id'] === $id) {
                if (isset($data['question'])) {
                    $faqs[$key]['question'] = sanitize_text_field($data['question']);
                }
                if (isset($data['answer'])) {
                    $faqs[$key]['answer'] = wp_kses_post($data['answer']);
                }
                if (isset($data['order'])) {
                    $faqs[$key]['order'] = intval($data['order']);
                }
                if (isset($data['active'])) {
                    $faqs[$key]['active'] = (bool)$data['active'];
                }
                return self::save_admin_faqs($faqs);
            }
        }
        return false;
    }
    
    /**
     * Delete an admin FAQ by ID
     */
    public static function delete_admin_faq($id) {
        $faqs = self::get_all_admin_faqs();
        $faqs = array_filter($faqs, function($faq) use ($id) {
            return $faq['id'] !== $id;
        });
        return self::save_admin_faqs(array_values($faqs));
    }
    
    /**
     * Get active trainer FAQs
     */
    public static function get_trainer_faqs($trainer_id) {
        $all = self::get_all_trainer_faqs($trainer_id);
        return array_filter($all, function($faq) {
            return !empty($faq['active']);
        });
    }
    
    /**
     * Get all trainer FAQs including inactive
     */
    public static function get_all_trainer_faqs($trainer_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_trainers';
        
        $faqs_json = $wpdb->get_var($wpdb->prepare(
            "SELECT trainer_faqs FROM $table WHERE id = %d",
            $trainer_id
        ));
        
        if (empty($faqs_json)) {
            return array();
        }
        
        $faqs = json_decode($faqs_json, true);
        if (!is_array($faqs)) {
            return array();
        }
        
        // Sort by order
        usort($faqs, function($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });
        
        return $faqs;
    }
    
    /**
     * Save all trainer FAQs
     */
    public static function save_trainer_faqs($trainer_id, $faqs) {
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_trainers';
        
        if (!is_array($faqs)) {
            return false;
        }
        
        $result = $wpdb->update(
            $table,
            array('trainer_faqs' => json_encode($faqs)),
            array('id' => $trainer_id),
            array('%s'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * Add a new trainer FAQ
     */
    public static function add_trainer_faq($trainer_id, $question, $answer, $order = 0) {
        $faqs = self::get_all_trainer_faqs($trainer_id);
        $faqs[] = array(
            'id' => uniqid('tfaq_'),
            'question' => sanitize_text_field($question),
            'answer' => wp_kses_post($answer),
            'order' => intval($order),
            'active' => true,
            'created_at' => current_time('mysql')
        );
        return self::save_trainer_faqs($trainer_id, $faqs);
    }
    
    /**
     * Update a trainer FAQ by ID
     */
    public static function update_trainer_faq($trainer_id, $faq_id, $data) {
        $faqs = self::get_all_trainer_faqs($trainer_id);
        foreach ($faqs as $key => $faq) {
            if ($faq['id'] === $faq_id) {
                if (isset($data['question'])) {
                    $faqs[$key]['question'] = sanitize_text_field($data['question']);
                }
                if (isset($data['answer'])) {
                    $faqs[$key]['answer'] = wp_kses_post($data['answer']);
                }
                if (isset($data['order'])) {
                    $faqs[$key]['order'] = intval($data['order']);
                }
                if (isset($data['active'])) {
                    $faqs[$key]['active'] = (bool)$data['active'];
                }
                return self::save_trainer_faqs($trainer_id, $faqs);
            }
        }
        return false;
    }
    
    /**
     * Delete a trainer FAQ by ID
     */
    public static function delete_trainer_faq($trainer_id, $faq_id) {
        $faqs = self::get_all_trainer_faqs($trainer_id);
        $faqs = array_filter($faqs, function($faq) use ($faq_id) {
            return $faq['id'] !== $faq_id;
        });
        return self::save_trainer_faqs($trainer_id, array_values($faqs));
    }
    
    /**
     * Get default admin FAQs
     */
    public static function get_default_admin_faqs() {
        return array(
            array(
                'id' => 'default_1',
                'question' => 'What should my child bring to training?',
                'answer' => 'Players should bring appropriate equipment for their sport, water bottle, and comfortable athletic clothing appropriate for the weather.',
                'order' => 1,
                'active' => true,
                'created_at' => current_time('mysql')
            ),
            array(
                'id' => 'default_2',
                'question' => 'What is your cancellation policy?',
                'answer' => 'Sessions can be cancelled or rescheduled free of charge up to 24 hours before the scheduled time. Cancellations within 24 hours may be subject to a fee.',
                'order' => 2,
                'active' => true,
                'created_at' => current_time('mysql')
            ),
            array(
                'id' => 'default_3',
                'question' => 'How long are training sessions?',
                'answer' => 'Standard training sessions are 1 hour long. This includes warm-up, focused skill work, and a brief cool-down period.',
                'order' => 3,
                'active' => true,
                'created_at' => current_time('mysql')
            ),
            array(
                'id' => 'default_4',
                'question' => 'What ages do you train?',
                'answer' => 'Our trainers work with players of all ages, typically from 5 years old through high school and beyond. Each trainer may have specific age preferences listed on their profile.',
                'order' => 4,
                'active' => true,
                'created_at' => current_time('mysql')
            ),
            array(
                'id' => 'default_5',
                'question' => 'Can I stay and watch the session?',
                'answer' => 'Absolutely! Parents are welcome to stay and watch training sessions. We encourage you to see the progress your child makes.',
                'order' => 5,
                'active' => true,
                'created_at' => current_time('mysql')
            )
        );
    }
    
    /**
     * Initialize default admin FAQs if none exist
     */
    public static function maybe_init_defaults() {
        $existing = get_option('ptp_admin_faqs');
        if (empty($existing)) {
            self::save_admin_faqs(self::get_default_admin_faqs());
        }
    }
}
