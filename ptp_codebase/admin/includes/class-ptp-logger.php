
<?php
if (!defined('ABSPATH')) exit;

class PTP_Logger {
    public static function log($message, $context = []) {
        if (!(defined('PTP_DEBUG') && PTP_DEBUG) && !(defined('WP_DEBUG') && WP_DEBUG)) return;
        if (!is_string($message)) $message = json_encode($message);
        $redacted = preg_replace('/(sk_live_[A-Za-z0-9]+|pk_live_[A-Za-z0-9]+|Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*)/', '[REDACTED]', $message);
        error_log('[PTP] ' . $redacted);
    }
}
