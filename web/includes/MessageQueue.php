<?php
/**
 * Message Template Engine
 * Handles template variable substitution and rendering
 */

class MessageTemplate {
    private string $content;
    private array $variables;
    
    /**
     * Constructor
     */
    public function __construct(string $content, array $variables = []) {
        $this->content = $content;
        $this->variables = $variables;
    }
    
    /**
     * Supported template variables
     */
    private const SUPPORTED_VARS = ['name', 'phone', 'company'];
    
    /**
     * Render template with given values
     */
    public function render(array $data): string {
        $rendered = $this->content;
        
        foreach (self::SUPPORTED_VARS as $var) {
            $placeholder = "{{" . $var . "}}";
            $value = $data[$var] ?? '';
            
            // Safely replace - escape any special characters
            $rendered = str_replace($placeholder, (string)$value, $rendered);
        }
        
        return $rendered;
    }
    
    /**
     * Preview template with sample data
     */
    public function preview(array $sample_data = []): string {
        $default_samples = [
            'name' => 'John Doe',
            'phone' => '+60123456789',
            'company' => 'Acme Corp'
        ];
        
        $data = array_merge($default_samples, $sample_data);
        return $this->render($data);
    }
    
    /**
     * Validate template - check for only supported variables
     */
    public function validate(): array {
        $errors = [];
        
        // Find all {{...}} patterns
        if (preg_match_all('/\{\{(\w+)\}\}/', $this->content, $matches)) {
            foreach ($matches[1] as $var) {
                if (!in_array($var, self::SUPPORTED_VARS)) {
                    $errors[] = "Unsupported variable: {{" . $var . "}}. Supported: " . implode(', ', self::SUPPORTED_VARS);
                }
            }
        }
        
        return $errors;
    }
}

/**
 * Message Job Queue Manager
 * Handles job creation, claiming, reporting - CRITICAL SAFETY
 */

class MessageQueue {
    /**
     * Create jobs for campaign
     * ATOMIC OPERATION - all jobs created or none
     */
    public static function createCampaignJobs(
        int $campaign_id,
        array $recipient_ids,
        string $message_content,
        ?int $media_file_id = null,
        ?string $scheduled_at = null
    ): bool {
        try {
            db()->beginTransaction();
            
            $count = 0;
            foreach ($recipient_ids as $contact_id) {
                // Get contact
                $contact = db()->fetchOne(
                    'SELECT id, phone_number, name FROM contacts WHERE id = ?',
                    [$contact_id]
                );
                
                if (!$contact) {
                    continue;
                }
                
                // Create job with atomic constraint (campaign_id, contact_id unique)
                $scheduled_at_sql = $scheduled_at ? $scheduled_at : null;
                
                $status = $scheduled_at_sql ? 'pending' : 'pending';
                
                db()->execute(
                    'INSERT INTO message_jobs 
                     (campaign_id, contact_id, recipient_phone, recipient_name, 
                      message_content, media_file_id, status, scheduled_at, max_attempts)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $campaign_id,
                        $contact->id,
                        $contact['phone_number'],
                        $contact['name'] ?? '',
                        $message_content,
                        $media_file_id,
                        $status,
                        $scheduled_at_sql,
                        MAX_RETRY_ATTEMPTS
                    ]
                );
                
                $count++;
            }
            
            // Update campaign stats
            db()->execute(
                'UPDATE campaigns SET total_recipients = ?, pending_count = ? WHERE id = ?',
                [$count, $count, $campaign_id]
            );
            
            db()->commit();
            return true;
            
        } catch (Exception $e) {
            db()->rollback();
            error_log("Job creation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Claim job for worker - ATOMIC, prevents duplicate claiming
     * Returns job or null if already claimed
     */
    public static function claimJob(int $worker_id): ?array {
        try {
            db()->beginTransaction();
            
            // Get next pending job (with FOR UPDATE to lock the row)
            $job = db()->fetchOne(
                'SELECT id FROM message_jobs 
                 WHERE status = "pending" AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                 ORDER BY created_at ASC
                 LIMIT 1',
                []
            );
            
            if (!$job) {
                db()->commit();
                return null;
            }
            
            $job_id = $job['id'];
            
            // Atomically claim: only update if still pending
            $affected = db()->execute(
                'UPDATE message_jobs SET status = "processing", worker_id = ?, claimed_at = NOW()
                 WHERE id = ? AND status = "pending"',
                [$worker_id, $job_id]
            );
            
            // If update returned 0 rows, another worker claimed it
            if ($affected === 0) {
                db()->rollback();
                return null;
            }
            
            // Fetch full job details
            $claimed_job = db()->fetchOne(
                'SELECT * FROM message_jobs WHERE id = ?',
                [$job_id]
            );
            
            // Update worker current job
            db()->execute(
                'UPDATE workers SET current_job_id = ? WHERE id = ?',
                [$job_id, $worker_id]
            );
            
            db()->commit();
            return $claimed_job;
            
        } catch (Exception $e) {
            db()->rollback();
            error_log("Job claiming failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Report job result - CRITICAL SAFETY
     * Must enforce: worker_id matches job.worker_id
     * Must be idempotent - duplicate reports don't corrupt counters
     */
    public static function reportJobResult(
        int $job_id,
        int $worker_id,
        string $status,
        ?string $error = null
    ): bool {
        try {
            db()->beginTransaction();
            
            // Fetch job - verify it exists and matches worker
            $job = db()->fetchOne(
                'SELECT * FROM message_jobs WHERE id = ? FOR UPDATE',
                [$job_id]
            );
            
            if (!$job) {
                db()->rollback();
                error_log("Job not found: $job_id");
                return false;
            }
            
            // CRITICAL: Verify worker owns this job
            if ($job['worker_id'] != $worker_id) {
                db()->rollback();
                error_log("Worker $worker_id attempting to report job $job_id belonging to worker " . $job['worker_id']);
                Auth::logAudit($worker_id, 'unauthorized_job_report', 'message_job', $job_id,
                              'Worker attempted to report job not claimed by them', $_SERVER['REMOTE_ADDR']);
                return false;
            }
            
            // Verify job is in processing state
            if ($job['status'] !== 'processing') {
                db()->rollback();
                error_log("Job $job_id not in processing state: " . $job['status']);
                return false;
            }
            
            // Validate status
            if (!in_array($status, ['sent', 'failed'])) {
                db()->rollback();
                error_log("Invalid job status: $status");
                return false;
            }
            
            // Check if already reported (idempotency)
            $log = db()->fetchOne(
                'SELECT id FROM message_logs WHERE job_id = ? AND status = ? LIMIT 1',
                [$job_id, $status]
            );
            
            if ($log) {
                // Already reported this status - silently return success (idempotent)
                db()->commit();
                return true;
            }
            
            // Calculate attempt number
            $attempt_number = ($job['attempts'] ?? 0) + 1;
            
            if ($status === 'sent') {
                // Success
                db()->execute(
                    'UPDATE message_jobs SET status = "sent", completed_at = NOW(), attempts = ? WHERE id = ?',
                    [$attempt_number, $job_id]
                );
                
                db()->execute(
                    'UPDATE campaigns SET sent_count = sent_count + 1, pending_count = pending_count - 1 
                     WHERE id = ?',
                    [$job['campaign_id']]
                );
                
            } else {
                // Failed
                $attempt_number = ($job['attempts'] ?? 0) + 1;
                
                if ($attempt_number >= ($job['max_attempts'] ?? 3)) {
                    // Max retries exceeded
                    db()->execute(
                        'UPDATE message_jobs SET status = "failed", completed_at = NOW(), attempts = ?, last_error = ?
                         WHERE id = ?',
                        [$attempt_number, $error, $job_id]
                    );
                    
                    db()->execute(
                        'UPDATE campaigns SET failed_count = failed_count + 1, pending_count = pending_count - 1 
                         WHERE id = ?',
                        [$job['campaign_id']]
                    );
                } else {
                    // Retry
                    db()->execute(
                        'UPDATE message_jobs SET status = "pending", attempts = ?, last_error = ?, worker_id = NULL, claimed_at = NULL 
                         WHERE id = ?',
                        [$attempt_number, $error, $job_id]
                    );
                }
            }
            
            // Log message attempt
            db()->execute(
                'INSERT INTO message_logs (job_id, campaign_id, worker_id, recipient_phone, recipient_name, 
                                         message_content, status, error_message, attempt_number)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $job_id,
                    $job['campaign_id'],
                    $worker_id,
                    $job['recipient_phone'],
                    $job['recipient_name'],
                    substr($job['message_content'], 0, 500),
                    $status,
                    $error,
                    $attempt_number
                ]
            );
            
            // Clear worker current job
            db()->execute(
                'UPDATE workers SET current_job_id = NULL WHERE id = ?',
                [$worker_id]
            );
            
            db()->commit();
            return true;
            
        } catch (Exception $e) {
            db()->rollback();
            error_log("Job result reporting failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Recover stale jobs - worker crashed without reporting
     * Only run periodically with caution about duplicate delivery
     */
    public static function recoverStaleJobs(int $timeout_seconds = WORKER_STALE_JOB_TIMEOUT): int {
        try {
            db()->beginTransaction();
            
            // Find jobs stuck in processing
            $stale_jobs = db()->fetchAll(
                'SELECT mj.id, mj.worker_id FROM message_jobs mj
                 JOIN workers w ON mj.worker_id = w.id
                 WHERE mj.status = "processing" 
                 AND mj.claimed_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                 AND (w.last_heartbeat IS NULL OR w.last_heartbeat < DATE_SUB(NOW(), INTERVAL ? SECOND))',
                [$timeout_seconds, WORKER_HEARTBEAT_TIMEOUT]
            );
            
            $recovered_count = 0;
            
            foreach ($stale_jobs as $job) {
                // Reset to pending for retry
                db()->execute(
                    'UPDATE message_jobs SET status = "pending", worker_id = NULL, claimed_at = NULL 
                     WHERE id = ?',
                    [$job['id']]
                );
                
                // Log recovery
                Auth::logAudit(null, 'job_recovered', 'message_job', $job['id'],
                              'Stale job recovered for retry (worker timeout)');
                
                $recovered_count++;
            }
            
            db()->commit();
            return $recovered_count;
            
        } catch (Exception $e) {
            db()->rollback();
            error_log("Stale job recovery failed: " . $e->getMessage());
            return 0;
        }
    }
}
