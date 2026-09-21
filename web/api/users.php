<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/logger.php';

Auth::requireRole('admin');
$db = get_db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $stmt = $db->query("SELECT id, username, full_name, role, status, last_login_at, created_at FROM users ORDER BY id ASC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'save':
            require_csrf_token();
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $username = strtolower(trim($_POST['username'] ?? ''));
            $fullName = trim($_POST['full_name'] ?? '');
            $role = in_array($_POST['role'] ?? '', ['admin', 'operator'], true) ? $_POST['role'] : 'operator';
            $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($fullName)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Username and full name are required.']);
                exit;
            }

            // Check username uniqueness
            $chkSql = "SELECT id FROM users WHERE username = :u" . ($id ? " AND id != :id" : "");
            $chkStmt = $db->prepare($chkSql);
            $chkParams = [':u' => $username];
            if ($id) $chkParams[':id'] = $id;
            $chkStmt->execute($chkParams);
            if ($chkStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Username is already taken.']);
                exit;
            }

            if ($id) {
                if (!empty($password)) {
                    $passHash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("UPDATE users SET full_name = :fn, role = :role, status = :status, password_hash = :p WHERE id = :id");
                    $stmt->execute([':fn' => $fullName, ':role' => $role, ':status' => $status, ':p' => $passHash, ':id' => $id]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET full_name = :fn, role = :role, status = :status WHERE id = :id");
                    $stmt->execute([':fn' => $fullName, ':role' => $role, ':status' => $status, ':id' => $id]);
                }
                audit_log('user_updated', "Updated user #$id ($username, $role)");
                echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
            } else {
                if (empty($password)) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Password is required for new users.']);
                    exit;
                }
                $passHash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, full_name, role, status) VALUES (:u, :p, :fn, :role, :status)");
                $stmt->execute([':u' => $username, ':p' => $passHash, ':fn' => $fullName, ':role' => $role, ':status' => $status]);
                $newId = (int)$db->lastInsertId();
                audit_log('user_created', "Created user #$newId ($username, $role)");
                echo json_encode(['success' => true, 'message' => 'User created successfully.', 'user_id' => $newId]);
            }
            break;

        case 'delete':
            require_csrf_token();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0 || $id === (int)$_SESSION['user_id']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Cannot delete your own account or invalid ID.']);
                exit;
            }

            $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $id]);
            audit_log('user_deleted', "Deleted user #$id");
            echo json_encode(['success' => true, 'message' => 'User deleted successfully.']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
