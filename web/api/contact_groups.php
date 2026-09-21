<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/logger.php';

Auth::requireLogin();
$db = get_db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $stmt = $db->query("
                SELECT g.*, COUNT(cgm.contact_id) as member_count
                FROM contact_groups g
                LEFT JOIN contact_group_members cgm ON g.id = cgm.group_id
                GROUP BY g.id
                ORDER BY g.name ASC
            ");
            $groups = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $groups]);
            break;

        case 'save':
            require_csrf_token();
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (empty($name)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Group name is required']);
                exit;
            }

            if ($id) {
                $stmt = $db->prepare("UPDATE contact_groups SET name = :name, description = :description WHERE id = :id");
                $stmt->execute([':name' => $name, ':description' => $description, ':id' => $id]);
                audit_log('group_updated', "Updated contact group #$id ($name)");
            } else {
                $stmt = $db->prepare("INSERT INTO contact_groups (name, description) VALUES (:name, :description)");
                $stmt->execute([':name' => $name, ':description' => $description]);
                $id = (int)$db->lastInsertId();
                audit_log('group_created', "Created contact group #$id ($name)");
            }

            echo json_encode(['success' => true, 'message' => 'Group saved successfully', 'group_id' => $id]);
            break;

        case 'delete':
            require_csrf_token();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid group ID']);
                exit;
            }

            $stmt = $db->prepare("DELETE FROM contact_groups WHERE id = :id");
            $stmt->execute([':id' => $id]);
            audit_log('group_deleted', "Deleted contact group #$id");

            echo json_encode(['success' => true, 'message' => 'Group deleted successfully']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
