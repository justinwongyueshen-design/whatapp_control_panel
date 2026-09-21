<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/csrf.php';

echo json_encode([
    'success' => true,
    'csrf_token' => generate_csrf_token()
]);
