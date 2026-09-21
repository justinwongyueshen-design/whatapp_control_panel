<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Authenticate worker Bearer token
$worker = Auth::authenticateWorker();

$mediaId = (int)($_GET['id'] ?? 0);
if ($mediaId <= 0) {
    http_response_code(400);
    die('Invalid media ID');
}

$db = get_db();
$stmt = $db->prepare("SELECT * FROM media_files WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $mediaId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('Media file not found');
}

$filePath = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($file['stored_filename']);

// Prevent path traversal
$realUploadDir = realpath(UPLOAD_DIR);
$realFilePath = realpath($filePath);

if (!$realFilePath || !str_starts_with($realFilePath, $realUploadDir) || !file_exists($realFilePath)) {
    http_response_code(404);
    die('Media file not found on storage');
}

header('Content-Type: ' . $file['mime_type']);
header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
header('Content-Length: ' . filesize($realFilePath));
readfile($realFilePath);
exit;
