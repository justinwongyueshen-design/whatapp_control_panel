<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/queue.php';

// Authenticate worker Bearer token
$worker = Auth::authenticateWorker();

// Optional: check if worker reported WhatsApp connected
// (The prompt: "never continue sending if WhatsApp is not connected")
if ($worker['whatsapp_status'] !== 'connected') {
    // Return 200 with job null, stating WhatsApp must be connected
    echo json_encode([
        'success' => false,
        'message' => 'Worker WhatsApp status is not connected. Connect WhatsApp Web first.',
        'job'     => null
    ]);
    exit;
}

$job = QueueManager::claimNextJob((int)$worker['id']);

if ($job) {
    echo json_encode([
        'success' => true,
        'message' => 'Job claimed successfully',
        'job'     => $job
    ]);
} else {
    echo json_encode([
        'success' => true,
        'message' => 'No jobs currently claimable',
        'job'     => null
    ]);
}
