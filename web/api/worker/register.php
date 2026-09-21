<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/logger.php';

// Registration can be done from Admin UI (requires admin role & CSRF)
// or auto-provisioned if secret setup key matches
$db = get_db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'create';

try {
    switch ($action) {
        case 'create':
            // Admin must be logged in to create a worker
            Auth::requireRole('admin');
            require_csrf_token();

            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                $name = 'Worker-' . substr(bin2hex(random_bytes(3)), 0, 6);
            }

            // Generate cryptographic credentials
            $workerUuid = 'wkr_' . bin2hex(random_bytes(12));
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = password_hash($plainToken, PASSWORD_BCRYPT);

            $stmt = $db->prepare("
                INSERT INTO workers (worker_uuid, name, token_hash, status, whatsapp_status, created_at)
                VALUES (:uuid, :name, :token_hash, 'offline', 'disconnected', UTC_TIMESTAMP())
            ");
            $stmt->execute([
                ':uuid'       => $workerUuid,
                ':name'       => $name,
                ':token_hash' => $tokenHash
            ]);
            $workerId = (int)$db->lastInsertId();

            audit_log('worker_created', "Registered worker #$workerId ($name, $workerUuid)");

            // Return token ONCE to the user
            echo json_encode([
                'success'     => true,
                'message'     => 'Worker created successfully. Save this token immediately as it cannot be shown again.',
                'worker_id'   => $workerId,
                'worker_uuid' => $workerUuid,
                'name'        => $name,
                'token'       => $plainToken
            ]);
            break;

        case 'regenerate_token':
            Auth::requireRole('admin');
            require_csrf_token();

            $workerId = (int)($_POST['worker_id'] ?? 0);
            if ($workerId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid worker ID']);
                exit;
            }

            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = password_hash($plainToken, PASSWORD_BCRYPT);

            $stmt = $db->prepare("UPDATE workers SET token_hash = :token_hash, status = 'offline' WHERE id = :id");
            $stmt->execute([':token_hash' => $tokenHash, ':id' => $workerId]);

            audit_log('worker_token_regenerated', "Regenerated bearer token for worker #$workerId");

            echo json_encode([
                'success'   => true,
                'message'   => 'Token regenerated. Update your worker configuration with this new token.',
                'worker_id' => $workerId,
                'token'     => $plainToken
            ]);
            break;

        case 'toggle_status':
            Auth::requireRole('admin');
            require_csrf_token();

            $workerId = (int)($_POST['worker_id'] ?? 0);
            $newStatus = in_array($_POST['status'] ?? '', ['offline', 'disabled'], true) ? $_POST['status'] : 'disabled';

            $stmt = $db->prepare("UPDATE workers SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $workerId]);

            audit_log('worker_status_changed', "Set worker #$workerId status to $newStatus");
            echo json_encode(['success' => true, 'message' => "Worker status changed to $newStatus."]);
            break;

        case 'delete':
            Auth::requireRole('admin');
            require_csrf_token();

            $workerId = (int)($_POST['worker_id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM workers WHERE id = :id");
            $stmt->execute([':id' => $workerId]);

            audit_log('worker_deleted', "Deleted worker #$workerId");
            echo json_encode(['success' => true, 'message' => 'Worker deleted successfully.']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
