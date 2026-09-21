<?php
/**
 * Audit and Message Logging Helper
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function audit_log(string $action, ?string $details = null, ?int $userId = null, ?int $workerId = null): void {
    try {
        $db = get_db();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        
        // Scrub sensitive tokens/passwords if present in details
        $scrubbedDetails = $details;
        if ($scrubbedDetails) {
            $scrubbedDetails = preg_replace('/(password|token|secret)[\'"]?\s*[:=]\s*[\'"]?([^\s\'",}]+)/i', '$1: [REDACTED]', $scrubbedDetails);
        }

        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, worker_id, action, details, ip_address, created_at)
            VALUES (:user_id, :worker_id, :action, :details, :ip, UTC_TIMESTAMP())
        ");
        $stmt->execute([
            ':user_id'   => $userId ?? ($_SESSION['user_id'] ?? null),
            ':worker_id' => $workerId,
            ':action'    => substr($action, 0, 100),
            ':details'   => $scrubbedDetails,
            ':ip'        => $ip
        ]);
    } catch (Exception $e) {
        error_log("Audit log failure: " . $e->getMessage());
    }
}

function message_log(int $jobId, ?int $campaignId, ?int $workerId, string $recipientPhone, string $status, ?string $errorMessage = null, int $attempt = 1): void {
    try {
        $db = get_db();
        $stmt = $db->prepare("
            INSERT INTO message_logs (job_id, campaign_id, worker_id, recipient_phone, status, error_message, attempt_number, created_at)
            VALUES (:job_id, :campaign_id, :worker_id, :recipient_phone, :status, :error_message, :attempt_number, UTC_TIMESTAMP())
        ");
        $stmt->execute([
            ':job_id'          => $jobId,
            ':campaign_id'     => $campaignId,
            ':worker_id'       => $workerId,
            ':recipient_phone' => $recipientPhone,
            ':status'          => $status,
            ':error_message'   => $errorMessage,
            ':attempt_number'  => $attempt
        ]);
    } catch (Exception $e) {
        error_log("Message log failure: " . $e->getMessage());
    }
}
