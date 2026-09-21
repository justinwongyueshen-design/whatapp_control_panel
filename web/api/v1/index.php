<?php
/**
 * Worker API - Bearer Token Authentication Required
 * All worker requests include: Authorization: Bearer <worker-token>
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

// Get bearer token
$token = Auth::getBearerToken();

if (!$token) {
    Response::unauthorized('Missing Authorization header');
}

// Verify worker token
$worker = Auth::verifyWorkerToken($token);

if (!$worker) {
    Response::unauthorized('Invalid or inactive worker token');
}

// Get request path
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$api_path = str_replace('/api/v1/', '', $request_uri);

try {
    switch ($api_path) {
        // ===== WORKER MANAGEMENT =====
        
        case 'worker/heartbeat':
            handleWorkerHeartbeat($worker);
            break;
            
        case 'worker/status':
            handleWorkerStatus($worker);
            break;
            
        // ===== JOB QUEUE =====
        
        case 'jobs/claim':
            handleClaimJob($worker);
            break;
            
        case 'jobs/report':
            handleReportJobResult($worker);
            break;
            
        // ===== MEDIA DOWNLOADS =====
        
        case 'media/download':
            handleMediaDownload($worker);
            break;
            
        // ===== DIAGNOSTICS =====
        
        case 'worker/diagnostics':
            handleDiagnostics($worker);
            break;
            
        default:
            Response::notFound('API endpoint not found');
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}

/**
 * Handle worker heartbeat
 */
function handleWorkerHeartbeat(array $worker): void {
    if (request_method() !== 'POST') {
        Response::error('Method not allowed', 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate heartbeat data
    $is_whatsapp_connected = filter_var($data['is_whatsapp_connected'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $browser_type = sanitize_input($data['browser_type'] ?? 'chrome');
    $python_version = sanitize_input($data['python_version'] ?? '');
    $worker_version = sanitize_input($data['worker_version'] ?? '');
    
    // Update worker heartbeat
    db()->execute(
        'UPDATE workers SET last_heartbeat = NOW(), is_whatsapp_connected = ?, 
         browser_type = ?, python_version = ?, worker_version = ? WHERE id = ?',
        [$is_whatsapp_connected, $browser_type, $python_version, $worker_version, $worker['id']]
    );
    
    Response::success(['status' => 'ok'], 'Heartbeat received');
}

/**
 * Handle get worker status
 */
function handleWorkerStatus(array $worker): void {
    if (request_method() !== 'GET') {
        Response::error('Method not allowed', 405);
    }
    
    Response::success([
        'worker_id' => $worker['id'],
        'name' => $worker['name'],
        'is_active' => (bool)$worker['is_active'],
        'is_whatsapp_connected' => (bool)$worker['is_whatsapp_connected'],
        'current_job_id' => $worker['current_job_id'],
        'last_heartbeat' => $worker['last_heartbeat'],
    ]);
}

/**
 * Handle claim job from queue
 */
function handleClaimJob(array $worker): void {
    if (request_method() !== 'POST') {
        Response::error('Method not allowed', 405);
    }
    
    // Claim next job for this worker
    $job = MessageQueue::claimJob($worker['id']);
    
    if (!$job) {
        Response::success(null, 'No jobs available');
        return;
    }
    
    Response::success([
        'job_id' => $job['id'],
        'campaign_id' => $job['campaign_id'],
        'recipient_phone' => $job['recipient_phone'],
        'recipient_name' => $job['recipient_name'],
        'message_content' => $job['message_content'],
        'media_file_id' => $job['media_file_id'],
        'attempt' => $job['attempts'] + 1,
        'max_attempts' => $job['max_attempts'],
    ]);
}

/**
 * Handle report job result
 */
function handleReportJobResult(array $worker): void {
    if (request_method() !== 'POST') {
        Response::error('Method not allowed', 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($data['job_id']) || empty($data['status'])) {
        Response::validationError(['job_id' => 'Required', 'status' => 'Required']);
    }
    
    $job_id = (int)$data['job_id'];
    $status = sanitize_input($data['status']);
    $error = sanitize_input($data['error'] ?? '');
    
    // Report job result (with worker ownership verification)
    $result = MessageQueue::reportJobResult($job_id, $worker['id'], $status, $error);
    
    if (!$result) {
        Response::error('Failed to report job result');
    }
    
    Response::success(['job_id' => $job_id], 'Job result recorded');
}

/**
 * Handle media file download
 */
function handleMediaDownload(array $worker): void {
    if (request_method() !== 'GET') {
        Response::error('Method not allowed', 405);
    }
    
    $media_id = (int)($_GET['media_id'] ?? 0);
    
    if (!$media_id) {
        Response::validationError(['media_id' => 'Required']);
    }
    
    // Fetch media file
    $media = db()->fetchOne(
        'SELECT * FROM media_files WHERE id = ?',
        [$media_id]
    );
    
    if (!$media) {
        Response::notFound('Media file not found');
    }
    
    $file_path = $media['storage_path'];
    
    if (!file_exists($file_path)) {
        error_log("Media file missing: $file_path");
        Response::error('Media file not found on storage');
    }
    
    if (!is_readable($file_path)) {
        error_log("Media file not readable: $file_path");
        Response::error('Cannot read media file');
    }
    
    // Serve file with security headers
    header('Content-Type: ' . $media['mime_type']);
    header('Content-Length: ' . $media['file_size']);
    header('Content-Disposition: attachment; filename="' . $media['stored_filename'] . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    readfile($file_path);
    exit;
}

/**
 * Handle worker diagnostics
 */
function handleDiagnostics(array $worker): void {
    if (request_method() !== 'POST') {
        Response::error('Method not allowed', 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Log diagnostic information
    $diagnostics = [
        'worker_id' => $worker['id'],
        'timestamp' => date('Y-m-d H:i:s'),
        'current_url' => $data['current_url'] ?? null,
        'page_title' => $data['page_title'] ?? null,
        'error_message' => sanitize_input($data['error_message'] ?? ''),
        'browser_log' => substr($data['browser_log'] ?? '', 0, 1000),
    ];
    
    error_log("Worker Diagnostic Report: " . json_encode($diagnostics));
    
    Response::success(['recorded' => true], 'Diagnostic information recorded');
}
