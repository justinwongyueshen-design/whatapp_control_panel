<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/queue.php';

$worker = Auth::authenticateWorker();
$db = get_db();

// Parse JSON request
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: [];

$whatsappStatus = in_array($data['whatsapp_status'] ?? '', ['disconnected', 'qr_ready', 'authenticated', 'connected'], true)
    ? $data['whatsapp_status']
    : 'disconnected';

$osInfo = substr(trim($data['os_info'] ?? ''), 0, 100);
$browserInfo = substr(trim($data['browser_info'] ?? ''), 0, 100);
$pythonVer = substr(trim($data['python_version'] ?? ''), 0, 50);
$workerVer = substr(trim($data['worker_version'] ?? ''), 0, 50);

// Update worker record
$stmt = $db->prepare("
    UPDATE workers 
    SET status = 'online',
        whatsapp_status = :wa_status,
        last_heartbeat_at = UTC_TIMESTAMP(),
        os_info = COALESCE(NULLIF(:os, ''), os_info),
        browser_info = COALESCE(NULLIF(:browser, ''), browser_info),
        python_version = COALESCE(NULLIF(:py, ''), python_version),
        worker_version = COALESCE(NULLIF(:ver, ''), worker_version)
    WHERE id = :id
");
$stmt->execute([
    ':wa_status' => $whatsappStatus,
    ':os'        => $osInfo,
    ':browser'   => $browserInfo,
    ':py'        => $pythonVer,
    ':ver'       => $workerVer,
    ':id'        => $worker['id']
]);

// Also run background stale job check opportunistically
QueueManager::recoverStaleJobs();

// Fetch operational settings to feed worker pacing
$setStmt = $db->query("
    SELECT setting_key, setting_value FROM system_settings 
    WHERE setting_key IN ('min_delay_seconds', 'max_delay_seconds', 'max_messages_per_batch', 'pause_between_batches_seconds')
");
$settings = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'success'    => true,
    'message'    => 'Heartbeat acknowledged',
    'worker_id'  => (int)$worker['id'],
    'server_utc' => gmdate('Y-m-d H:i:s'),
    'settings'   => [
        'min_delay_seconds'             => (int)($settings['min_delay_seconds'] ?? 5),
        'max_delay_seconds'             => (int)($settings['max_delay_seconds'] ?? 15),
        'max_messages_per_batch'        => (int)($settings['max_messages_per_batch'] ?? 20),
        'pause_between_batches_seconds' => (int)($settings['pause_between_batches_seconds'] ?? 60),
    ]
]);
