<?php
/**
 * Asynchronous Queue Subsystem - Atomic Claiming, Reporting, & Stale Recovery
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/rate_limiter.php';

class QueueManager {
    /**
     * Recover stale processing jobs abandoned by unresponsive workers
     */
    public static function recoverStaleJobs(): int {
        $db = get_db();
        try {
            // Get configurable timeout thresholds
            $stmt = $db->query("
                SELECT setting_key, setting_value FROM system_settings 
                WHERE setting_key IN ('stale_job_timeout_seconds', 'worker_heartbeat_timeout_seconds')
            ");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $staleTimeout = isset($settings['stale_job_timeout_seconds']) ? (int)$settings['stale_job_timeout_seconds'] : 300;
            $heartbeatTimeout = isset($settings['worker_heartbeat_timeout_seconds']) ? (int)$settings['worker_heartbeat_timeout_seconds'] : 60;

            // Find jobs in 'processing' where claimed_at is older than staleTimeout AND worker heartbeat is stale or worker offline
            $findSql = "
                SELECT j.id, j.campaign_id, j.worker_id, j.phone, j.attempts, j.max_attempts
                FROM message_jobs j
                LEFT JOIN workers w ON j.worker_id = w.id
                WHERE j.status = 'processing'
                  AND j.claimed_at < (UTC_TIMESTAMP() - INTERVAL :staleTimeout SECOND)
                  AND (
                      w.id IS NULL 
                      OR w.status != 'online' 
                      OR w.last_heartbeat_at IS NULL 
                      OR w.last_heartbeat_at < (UTC_TIMESTAMP() - INTERVAL :heartbeatTimeout SECOND)
                  )
            ";
            $findStmt = $db->prepare($findSql);
            $findStmt->execute([
                ':staleTimeout' => $staleTimeout,
                ':heartbeatTimeout' => $heartbeatTimeout
            ]);
            $staleJobs = $findStmt->fetchAll();

            $recoveredCount = 0;
            foreach ($staleJobs as $job) {
                if ($job['attempts'] < $job['max_attempts']) {
                    // Requeue job for retry
                    $upStmt = $db->prepare("
                        UPDATE message_jobs 
                        SET status = 'pending', worker_id = NULL, claimed_at = NULL,
                            last_error = 'Stale processing timeout: worker unresponsive, requeued'
                        WHERE id = :id AND status = 'processing'
                    ");
                    $upStmt->execute([':id' => $job['id']]);
                    if ($upStmt->rowCount() > 0) {
                        message_log($job['id'], $job['campaign_id'], $job['worker_id'], $job['phone'], 'retrying', 'Stale job recovered', $job['attempts']);
                        audit_log('stale_job_requeued', "Job #{$job['id']} recovered and requeued", null, $job['worker_id']);
                        $recoveredCount++;
                    }
                } else {
                    // Max attempts exceeded
                    $upStmt = $db->prepare("
                        UPDATE message_jobs 
                        SET status = 'failed', worker_id = NULL,
                            last_error = 'Stale processing timeout: max attempts reached while worker was unresponsive'
                        WHERE id = :id AND status = 'processing'
                    ");
                    $upStmt->execute([':id' => $job['id']]);
                    if ($upStmt->rowCount() > 0) {
                        message_log($job['id'], $job['campaign_id'], $job['worker_id'], $job['phone'], 'failed', 'Stale job failed: max attempts reached', $job['attempts']);
                        audit_log('stale_job_failed', "Job #{$job['id']} marked failed after exceeding attempts", null, $job['worker_id']);
                        $recoveredCount++;
                    }
                }

                if (!empty($job['campaign_id'])) {
                    self::syncCampaignStats((int)$job['campaign_id']);
                }
            }

            return $recoveredCount;
        } catch (Exception $e) {
            error_log("Stale jobs recovery failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Atomically claim the next pending job for a worker
     *
     * @param int $workerId
     * @return array|null The claimed job details or null
     */
    public static function claimNextJob(int $workerId): ?array {
        $db = get_db();

        // 1. First recover any stale jobs
        self::recoverStaleJobs();

        // 2. Check global rate limit
        $limitCheck = RateLimiter::checkGlobalLimits();
        if (!$limitCheck['allowed']) {
            return null;
        }

        // 3. Begin atomic transaction
        $db->beginTransaction();

        try {
            // Find candidate job using row locking with FOR UPDATE SKIP LOCKED
            $candidateSql = "
                SELECT j.*, m.original_name as media_original_name, m.stored_filename as media_stored_name, m.mime_type as media_mime_type, m.file_size as media_file_size
                FROM message_jobs j
                LEFT JOIN campaigns c ON j.campaign_id = c.id
                LEFT JOIN media_files m ON j.media_id = m.id
                WHERE j.status = 'pending'
                  AND (j.scheduled_at IS NULL OR j.scheduled_at <= UTC_TIMESTAMP())
                  AND (j.campaign_id IS NULL OR c.status IN ('queued', 'running'))
                ORDER BY j.id ASC
                LIMIT 1
                FOR UPDATE
            ";

            $stmt = $db->query($candidateSql);
            $job = $stmt->fetch();

            if (!$job) {
                $db->commit();
                return null;
            }

            $jobId = (int)$job['id'];
            $newAttempts = (int)$job['attempts'] + 1;

            // Claim the job
            $claimStmt = $db->prepare("
                UPDATE message_jobs 
                SET status = 'processing',
                    worker_id = :worker_id,
                    claimed_at = UTC_TIMESTAMP(),
                    attempts = :attempts
                WHERE id = :job_id AND status = 'pending'
            ");
            $claimStmt->execute([
                ':worker_id' => $workerId,
                ':attempts'  => $newAttempts,
                ':job_id'    => $jobId
            ]);

            if ($claimStmt->rowCount() === 0) {
                // Another transaction got it first
                $db->rollBack();
                return null;
            }

            // Update worker status and current job
            $workerStmt = $db->prepare("
                UPDATE workers 
                SET current_job_id = :job_id,
                    status = 'online',
                    last_heartbeat_at = UTC_TIMESTAMP()
                WHERE id = :worker_id
            ");
            $workerStmt->execute([
                ':job_id'    => $jobId,
                ':worker_id' => $workerId
            ]);

            // Update campaign status and counters if applicable
            if (!empty($job['campaign_id'])) {
                $campStmt = $db->prepare("
                    UPDATE campaigns 
                    SET status = 'running' 
                    WHERE id = :camp_id AND status = 'queued'
                ");
                $campStmt->execute([':camp_id' => $job['campaign_id']]);
                self::syncCampaignStats((int)$job['campaign_id']);
            }

            // Log claim event
            message_log($jobId, $job['campaign_id'], $workerId, $job['phone'], 'claimed', null, $newAttempts);

            $db->commit();

            // Prepare payload for worker
            return [
                'id'               => $jobId,
                'campaign_id'      => $job['campaign_id'],
                'phone'            => $job['phone'],
                'rendered_message' => $job['rendered_message'],
                'media'            => !empty($job['media_id']) ? [
                    'id'            => (int)$job['media_id'],
                    'original_name' => $job['media_original_name'],
                    'mime_type'     => $job['media_mime_type'],
                    'file_size'     => (int)$job['media_file_size']
                ] : null,
                'attempt'          => $newAttempts,
                'max_attempts'     => (int)$job['max_attempts']
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error in claimNextJob: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Report job completion or failure with strict worker ownership verification
     *
     * @param int $jobId
     * @param int $workerId
     * @param string $result 'sent' or 'failed'
     * @param string|null $errorMessage
     * @return array ['success' => bool, 'message' => string, 'code' => int]
     */
    public static function reportJobResult(int $jobId, int $workerId, string $result, ?string $errorMessage = null): array {
        $db = get_db();
        $db->beginTransaction();

        try {
            // Strict query: verify job exists, is currently processing, and belongs to THIS worker
            $stmt = $db->prepare("
                SELECT * FROM message_jobs 
                WHERE id = :job_id 
                FOR UPDATE
            ");
            $stmt->execute([':job_id' => $jobId]);
            $job = $stmt->fetch();

            if (!$job) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Job not found', 'code' => 404];
            }

            // Verify ownership
            if ((int)$job['worker_id'] !== $workerId) {
                $db->rollBack();
                audit_log('unauthorized_report_attempt', "Worker #$workerId tried to report Job #$jobId owned by worker #" . ($job['worker_id'] ?? 'none'), null, $workerId);
                return ['success' => false, 'message' => 'Forbidden: This job is not claimed by your worker ID', 'code' => 403];
            }

            // Verify status is processing (idempotency check)
            if ($job['status'] !== 'processing') {
                $db->rollBack();
                return [
                    'success' => true, 
                    'message' => "Job #$jobId was already reported as '{$job['status']}'",
                    'code' => 200
                ];
            }

            $campaignId = !empty($job['campaign_id']) ? (int)$job['campaign_id'] : null;

            if ($result === 'sent') {
                $upStmt = $db->prepare("
                    UPDATE message_jobs 
                    SET status = 'sent', 
                        sent_at = UTC_TIMESTAMP(), 
                        last_error = NULL
                    WHERE id = :job_id
                ");
                $upStmt->execute([':job_id' => $jobId]);

                message_log($jobId, $campaignId, $workerId, $job['phone'], 'sent', null, (int)$job['attempts']);
            } else {
                // Failed
                $attempts = (int)$job['attempts'];
                $maxAttempts = (int)$job['max_attempts'];

                if ($attempts < $maxAttempts) {
                    // Requeue for retry with backoff delay (30 seconds)
                    $upStmt = $db->prepare("
                        UPDATE message_jobs 
                        SET status = 'pending',
                            worker_id = NULL,
                            claimed_at = NULL,
                            scheduled_at = (UTC_TIMESTAMP() + INTERVAL 30 SECOND),
                            last_error = :error
                        WHERE id = :job_id
                    ");
                    $upStmt->execute([
                        ':job_id' => $jobId,
                        ':error'  => $errorMessage ?: 'Message sending failed, scheduled for retry'
                    ]);

                    message_log($jobId, $campaignId, $workerId, $job['phone'], 'retrying', $errorMessage, $attempts);
                } else {
                    // Max attempts exceeded -> permanent failure
                    $upStmt = $db->prepare("
                        UPDATE message_jobs 
                        SET status = 'failed',
                            last_error = :error
                        WHERE id = :job_id
                    ");
                    $upStmt->execute([
                        ':job_id' => $jobId,
                        ':error'  => $errorMessage ?: 'Message sending permanently failed'
                    ]);

                    message_log($jobId, $campaignId, $workerId, $job['phone'], 'failed', $errorMessage, $attempts);
                }
            }

            // Clear current job on worker
            $workerUpStmt = $db->prepare("
                UPDATE workers 
                SET current_job_id = NULL,
                    last_heartbeat_at = UTC_TIMESTAMP()
                WHERE id = :worker_id AND current_job_id = :job_id
            ");
            $workerUpStmt->execute([
                ':worker_id' => $workerId,
                ':job_id'    => $jobId
            ]);

            // Sync campaign statistics
            if ($campaignId) {
                self::syncCampaignStats($campaignId);
            }

            $db->commit();
            return ['success' => true, 'message' => "Job #$jobId successfully updated to $result", 'code' => 200];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error in reportJobResult: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while reporting job result', 'code' => 500];
        }
    }

    /**
     * Synchronize campaign statistics directly from message_jobs table
     */
    public static function syncCampaignStats(int $campaignId): void {
        $db = get_db();
        try {
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing
                FROM message_jobs
                WHERE campaign_id = :camp_id
            ");
            $stmt->execute([':camp_id' => $campaignId]);
            $stats = $stmt->fetch();

            if (!$stats) {
                return;
            }

            $total = (int)$stats['total'];
            $sent = (int)$stats['sent'];
            $failed = (int)$stats['failed'];
            $pending = (int)$stats['pending'];
            $processing = (int)$stats['processing'];

            // Determine if campaign should transition to completed
            $statusClause = "";
            if ($total > 0 && ($pending + $processing) === 0) {
                $statusClause = ", status = 'completed'";
            }

            $upSql = "
                UPDATE campaigns 
                SET total_jobs = :total,
                    sent_jobs = :sent,
                    failed_jobs = :failed,
                    pending_jobs = :pending,
                    processing_jobs = :processing
                    $statusClause
                WHERE id = :camp_id AND status != 'cancelled'
            ";
            $upStmt = $db->prepare($upSql);
            $upStmt->execute([
                ':total'      => $total,
                ':sent'       => $sent,
                ':failed'     => $failed,
                ':pending'    => $pending,
                ':processing' => $processing,
                ':camp_id'    => $campaignId
            ]);
        } catch (Exception $e) {
            error_log("Error syncing campaign stats: " . $e->getMessage());
        }
    }
}
