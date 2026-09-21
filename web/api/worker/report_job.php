<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/queue.php';

// Authenticate worker Bearer token
$worker = Auth::authenticateWorker();

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: [];

$jobId = (int)($data['job_id'] ?? 0);
$result = strtolower(trim($data['result'] ?? ''));
$errorMessage = !empty($data['error_message']) ? trim($data['error_message']) : null;

if ($jobId <= 0 || !in_array($result, ['sent', 'failed'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid parameters. Required: job_id (int) and result ("sent" or "failed").'
    ]);
    exit;
}

$report = QueueManager::reportJobResult($jobId, (int)$worker['id'], $result, $errorMessage);

http_response_code($report['code']);
echo json_encode([
    'success' => $report['success'],
    'message' => $report['message'],
    'job_id'  => $jobId,
    'result'  => $result
]);
