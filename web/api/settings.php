<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/logger.php';

Auth::requireRole('admin');
$db = get_db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

try {
    switch ($action) {
        case 'get':
            $stmt = $db->query("SELECT setting_key, setting_value, description FROM system_settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            echo json_encode(['success' => true, 'data' => $settings]);
            break;

        case 'save':
            require_csrf_token();
            $allowedKeys = [
                'min_delay_seconds', 'max_delay_seconds', 'max_messages_per_batch',
                'max_messages_per_hour', 'max_messages_per_day', 'pause_between_batches_seconds',
                'max_retry_attempts', 'worker_heartbeat_timeout_seconds', 'stale_job_timeout_seconds',
                'default_country_code', 'app_timezone'
            ];

            $db->beginTransaction();
            $upStmt = $db->prepare("UPDATE system_settings SET setting_value = :val WHERE setting_key = :key");

            foreach ($allowedKeys as $key) {
                if (isset($_POST[$key])) {
                    $val = trim((string)$_POST[$key]);
                    // Numeric validation for delay/limit fields
                    if ($key !== 'app_timezone') {
                        $num = (int)$val;
                        if ($num < 0) $num = 0;
                        $val = (string)$num;
                    }
                    $upStmt->execute([':val' => $val, ':key' => $key]);
                }
            }

            $db->commit();
            audit_log('settings_updated', 'System settings modified by admin');

            echo json_encode(['success' => true, 'message' => 'Settings updated successfully.']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
