<?php
/**
 * Bootstrap/Initialize the Application
 * Include this file at the start of every PHP request
 */

// Define BASE_PATH early
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Load configuration
require_once BASE_PATH . '/config.php';

// Autoload required classes
$required_classes = [
    'Database' => 'Database.php',
    'Auth' => 'Auth.php',
    'Validator' => 'Validator.php',
    'Response' => 'Validator.php',
    'MessageTemplate' => 'MessageQueue.php',
    'MessageQueue' => 'MessageQueue.php',
];

foreach ($required_classes as $class => $file) {
    $file_path = __DIR__ . '/' . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    }
}

// Set default error handler (don't expose details in production)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (APP_DEBUG) {
        echo "<pre>Error [$errno]: $errstr\n  File: $errfile\n  Line: $errline</pre>";
    } else {
        error_log("Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

// Set default exception handler
set_exception_handler(function(Throwable $e) {
    if (APP_DEBUG) {
        echo "<pre>Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "</pre>";
    } else {
        error_log("Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        echo "An error occurred. Please contact administrator.";
    }
    exit;
});

// Register shutdown function to handle fatal errors
register_shutdown_function(function() {
    $last_error = error_get_last();
    if ($last_error && in_array($last_error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Fatal error: " . $last_error['message'] . " in " . $last_error['file'] . ":" . $last_error['line']);
    }
});

// Prevent direct access to includes folder
if (basename($_SERVER['SCRIPT_FILENAME']) === 'bootstrap.php') {
    http_response_code(403);
    die('Access denied');
}
