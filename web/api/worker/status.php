<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

try {
    $db = get_db();

    // Mark workers offline if heartbeat older than timeout
    $stmtTimeout = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'worker_heartbeat_timeout_seconds' LIMIT 1");
    $timeoutSec = (int)($stmtTimeout->fetchColumn() ?: 60);

    $db->prepare("
        UPDATE workers 
        SET status = 'offline', whatsapp_status = 'disconnected'
        WHERE status = 'online' 
          AND (last_heartbeat_at IS NULL OR last_heartbeat_at < (UTC_TIMESTAMP() - INTERVAL :timeout SECOND))
    ")->execute([':timeout' => $timeoutSec]);

    // Aggregate worker status
    $wStmt = $db->query("
        SELECT 
            COUNT(*) as total_workers,
            SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online_workers,
            SUM(CASE WHEN status = 'online' AND whatsapp_status = 'connected' THEN 1 ELSE 0 END) as whatsapp_connected_workers,
            SUM(CASE WHEN status = 'online' AND whatsapp_status = 'qr_ready' THEN 1 ELSE 0 END) as qr_ready_workers
        FROM workers 
        WHERE status != 'disabled'
    ");
    $workerStats = $wStmt->fetch();

    // Aggregate job stats
    $jStmt = $db->query("
        SELECT 
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_jobs,
            SUM(CASE WHEN status = 'sent' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as sent_today,
            SUM(CASE WHEN status = 'failed' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as failed_today
        FROM message_jobs
    ");
    $jobStats = $jStmt->fetch();

    $onlineCount = (int)($workerStats['online_workers'] ?? 0);
    $waConnected = (int)($workerStats['whatsapp_connected_workers'] ?? 0) > 0;
    $qrReady = (int)($workerStats['qr_ready_workers'] ?? 0) > 0;

    echo json_encode([
        'success' => true,
        'data' => [
            'total_workers'      => (int)($workerStats['total_workers'] ?? 0),
            'online_workers'     => $onlineCount,
            'whatsapp_connected' => $waConnected,
            'qr_ready'           => $qrReady,
            'pending_jobs'       => (int)($jobStats['pending_jobs'] ?? 0),
            'processing_jobs'    => (int)($jobStats['processing_jobs'] ?? 0),
            'sent_today'         => (int)($jobStats['sent_today'] ?? 0),
            'failed_today'       => (int)($jobStats['failed_today'] ?? 0),
            'server_utc'         => gmdate('Y-m-d H:i:s')
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Status error: ' . $e->getMessage()]);
}
