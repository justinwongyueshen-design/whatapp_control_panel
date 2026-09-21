<?php
/**
 * WhatsApp Bot Control Panel Configuration
 * 
 * IMPORTANT: Never commit production credentials to version control.
 * Use environment variables for sensitive values.
 */

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'whatsapp_bot');
define('DB_CHARSET', 'utf8mb4');

// ============================================================================
// APPLICATION CONFIGURATION
// ============================================================================
define('APP_NAME', 'WhatsApp Bot Control Panel');
define('APP_VERSION', '1.0.0');
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', (APP_ENV === 'development'));
define('APP_TIMEZONE', 'Asia/Kuala_Lumpur');

// ============================================================================
// SECURITY CONFIGURATION
// ============================================================================
define('CSRF_TOKEN_LENGTH', 32);
define('SESSION_COOKIE_SECURE', (APP_ENV === 'production'));
define('SESSION_COOKIE_HTTPONLY', true);
define('SESSION_COOKIE_SAMESITE', 'Strict');
define('SESSION_TIMEOUT', 3600); // 1 hour

// ============================================================================
// FILE UPLOAD CONFIGURATION
// ============================================================================
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_TEMP_DIR', __DIR__ . '/../uploads/temp/');
define('MAX_UPLOAD_SIZE', 50 * 1024 * 1024); // 50MB
define('ALLOWED_MIME_TYPES', [
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/pdf',
    'application/msword',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
]);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx']);

// ============================================================================
// WORKER CONFIGURATION
// ============================================================================
define('WORKER_HEARTBEAT_TIMEOUT', 60); // seconds
define('WORKER_STALE_JOB_TIMEOUT', 300); // seconds
define('WORKER_TOKEN_LENGTH', 32);

// ============================================================================
// MESSAGE RATE LIMITING
// ============================================================================
define('MIN_DELAY_SECONDS', 5);
define('MAX_DELAY_SECONDS', 15);
define('MAX_MESSAGES_PER_BATCH', 50);
define('MAX_MESSAGES_PER_HOUR', 300);
define('MAX_MESSAGES_PER_DAY', 1000);
define('PAUSE_BETWEEN_BATCHES', 30);

// ============================================================================
// RETRY CONFIGURATION
// ============================================================================
define('MAX_RETRY_ATTEMPTS', 3);
define('RETRY_DELAYS', [5, 30, 300]); // seconds: immediate, then 30s, then 5min

// ============================================================================
// PATHS
// ============================================================================

define('APP_PATH', BASE_PATH . '/web');
define('INCLUDES_PATH', APP_PATH . '/includes');
define('ASSETS_PATH', APP_PATH . '/assets');
define('LOGS_PATH', BASE_PATH . '/logs');
define('TEMP_PATH', BASE_PATH . '/temp');

// ============================================================================
// API ENDPOINTS
// ============================================================================
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'http://localhost/api/v1');
define('API_TIMEOUT', 30); // seconds

// ============================================================================
// INITIALIZE SESSION IF NOT COMMAND LINE
// ============================================================================
if (php_sapi_name() !== 'cli') {
    session_name('whatsapp_bot_session');
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'domain' => '',
        'secure' => SESSION_COOKIE_SECURE,
        'httponly' => SESSION_COOKIE_HTTPONLY,
        'samesite' => SESSION_COOKIE_SAMESITE,
    ]);
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// ============================================================================
// TIMEZONE
// ============================================================================
date_default_timezone_set(APP_TIMEZONE);

// ============================================================================
// ERROR HANDLING
// ============================================================================
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', LOGS_PATH . '/php-errors.log');
}

// ============================================================================
// CREATE REQUIRED DIRECTORIES
// ============================================================================
$required_dirs = [UPLOAD_DIR, UPLOAD_TEMP_DIR, LOGS_PATH, TEMP_PATH];
foreach ($required_dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
