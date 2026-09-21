<?php
/**
 * Server-Side Global Rate Limiter
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class RateLimiter {
    /**
     * Check if global messaging limits have been reached
     *
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    public static function checkGlobalLimits(): array {
        try {
            $db = get_db();

            // Fetch settings
            $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_messages_per_hour', 'max_messages_per_day')");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $maxPerHour = isset($settings['max_messages_per_hour']) ? (int)$settings['max_messages_per_hour'] : 100;
            $maxPerDay = isset($settings['max_messages_per_day']) ? (int)$settings['max_messages_per_day'] : 500;

            // Check hourly sent count
            $stmtHour = $db->query("SELECT COUNT(*) FROM message_logs WHERE status = 'sent' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)");
            $sentLastHour = (int)$stmtHour->fetchColumn();
            if ($maxPerHour > 0 && $sentLastHour >= $maxPerHour) {
                return [
                    'allowed' => false,
                    'reason' => "Global rate limit reached: $sentLastHour messages sent in the last hour (max: $maxPerHour)."
                ];
            }

            // Check daily sent count
            $stmtDay = $db->query("SELECT COUNT(*) FROM message_logs WHERE status = 'sent' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)");
            $sentLastDay = (int)$stmtDay->fetchColumn();
            if ($maxPerDay > 0 && $sentLastDay >= $maxPerDay) {
                return [
                    'allowed' => false,
                    'reason' => "Global daily limit reached: $sentLastDay messages sent in the last 24 hours (max: $maxPerDay)."
                ];
            }

            return ['allowed' => true, 'reason' => null];
        } catch (Exception $e) {
            error_log("RateLimiter check error: " . $e->getMessage());
            // Fail open or closed? In security/throttling, allow but log
            return ['allowed' => true, 'reason' => null];
        }
    }
}
