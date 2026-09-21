<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/template_engine.php';
require_once __DIR__ . '/../includes/logger.php';

Auth::requireLogin();
$db = get_db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $stmt = $db->query("SELECT * FROM message_templates ORDER BY id DESC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'save':
            require_csrf_token();
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');

            if (empty($title) || empty($content)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Title and content are required.']);
                exit;
            }

            $unsupported = TemplateEngine::findUnsupportedVariables($content);
            $variables = '{{name}},{{phone}},{{company}}';

            if ($id) {
                $stmt = $db->prepare("UPDATE message_templates SET title = :title, content = :content, variables = :variables WHERE id = :id");
                $stmt->execute([':title' => $title, ':content' => $content, ':variables' => $variables, ':id' => $id]);
                audit_log('template_updated', "Updated template #$id ($title)");
            } else {
                $stmt = $db->prepare("INSERT INTO message_templates (title, content, variables) VALUES (:title, :content, :variables)");
                $stmt->execute([':title' => $title, ':content' => $content, ':variables' => $variables]);
                $id = (int)$db->lastInsertId();
                audit_log('template_created', "Created template #$id ($title)");
            }

            $resp = [
                'success' => true,
                'message' => 'Template saved successfully.',
                'template_id' => $id
            ];
            if (!empty($unsupported)) {
                $resp['warning'] = 'Note: Unsupported variables detected: ' . implode(', ', $unsupported) . '. Supported variables are {{name}}, {{phone}}, {{company}}.';
            }

            echo json_encode($resp);
            break;

        case 'delete':
            require_csrf_token();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid template ID']);
                exit;
            }

            $stmt = $db->prepare("DELETE FROM message_templates WHERE id = :id");
            $stmt->execute([':id' => $id]);
            audit_log('template_deleted', "Deleted template #$id");

            echo json_encode(['success' => true, 'message' => 'Template deleted successfully.']);
            break;

        case 'preview':
            $content = $_POST['content'] ?? '';
            $sample = [
                'name'    => trim($_POST['name'] ?? 'John Doe'),
                'phone'   => trim($_POST['phone'] ?? '60123456789'),
                'company' => trim($_POST['company'] ?? 'Acme Corporation')
            ];
            $rendered = TemplateEngine::render($content, $sample);
            $html = TemplateEngine::renderHtmlPreview($content, $sample);

            echo json_encode([
                'success'  => true,
                'rendered' => $rendered,
                'html'     => $html
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
