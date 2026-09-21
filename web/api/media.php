<?php
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
            header('Content-Type: application/json');
            $stmt = $db->query("SELECT id, original_name, mime_type, file_size, created_at FROM media_files ORDER BY id DESC LIMIT 50");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'upload':
            header('Content-Type: application/json');
            require_csrf_token();

            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No file uploaded or file upload error.']);
                exit;
            }

            $tmpPath = $_FILES['file']['tmp_name'];
            $fileSize = (int)$_FILES['file']['size'];
            $origName = basename($_FILES['file']['name']);

            // 1. Validate File Size
            if ($fileSize > MAX_UPLOAD_BYTES) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'File exceeds maximum upload size of 16MB.']);
                exit;
            }

            // 2. Validate MIME Type on Server (never trust client header)
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);

            global $ALLOWED_MIME_TYPES;
            if (!array_key_exists($mimeType, $ALLOWED_MIME_TYPES)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => "Unsupported file type: $mimeType. Allowed: images, PDF, Word, Excel."]);
                exit;
            }

            $ext = $ALLOWED_MIME_TYPES[$mimeType];

            // 3. Generate randomized storage filename (prevents directory traversal and file execution)
            $storedFilename = bin2hex(random_bytes(16)) . '.' . $ext;
            $destination = UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedFilename;

            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            if (!move_uploaded_file($tmpPath, $destination)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to store uploaded file.']);
                exit;
            }

            // 4. Save Record in Database
            $stmt = $db->prepare("
                INSERT INTO media_files (original_name, stored_filename, mime_type, file_size, file_path, uploaded_by, created_at)
                VALUES (:orig, :stored, :mime, :size, :path, :uid, UTC_TIMESTAMP())
            ");
            $stmt->execute([
                ':orig'   => $origName,
                ':stored' => $storedFilename,
                ':mime'   => $mimeType,
                ':size'   => $fileSize,
                ':path'   => $storedFilename,
                ':uid'    => $_SESSION['user_id'] ?? null
            ]);
            $mediaId = (int)$db->lastInsertId();

            audit_log('media_uploaded', "Uploaded media file #$mediaId ($origName, $mimeType)");

            echo json_encode([
                'success' => true,
                'message' => 'File uploaded successfully.',
                'media'   => [
                    'id'            => $mediaId,
                    'original_name' => $origName,
                    'mime_type'     => $mimeType,
                    'file_size'     => $fileSize
                ]
            ]);
            break;

        case 'download':
            // Authenticated browser download for admin/operators
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                die('Invalid file ID');
            }

            $stmt = $db->prepare("SELECT * FROM media_files WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $file = $stmt->fetch();

            if (!$file) {
                http_response_code(404);
                die('File not found');
            }

            $filePath = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($file['stored_filename']);
            if (!file_exists($filePath)) {
                http_response_code(404);
                die('File missing from disk');
            }

            header('Content-Type: ' . $file['mime_type']);
            header('Content-Disposition: inline; filename="' . addslashes($file['original_name']) . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;

        default:
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
