<?php
/**
 * WhatsApp Bot Control Panel - Automated Verification Test Suite
 */

require_once __DIR__ . '/../web/includes/config.php';
require_once __DIR__ . '/../web/includes/db.php';
require_once __DIR__ . '/../web/includes/auth.php';
require_once __DIR__ . '/../web/includes/csrf.php';
require_once __DIR__ . '/../web/includes/normalizer.php';
require_once __DIR__ . '/../web/includes/template_engine.php';
require_once __DIR__ . '/../web/includes/queue.php';
require_once __DIR__ . '/../web/includes/rate_limiter.php';

$testResults = [];

function assert_test(string $name, bool $condition, string $expected, string $actual): void {
    global $testResults;
    $status = $condition ? 'PASS' : 'FAIL';
    $testResults[] = [
        'test'     => $name,
        'expected' => $expected,
        'actual'   => $actual,
        'status'   => $status
    ];
    echo sprintf("[%s] %s (Expected: %s | Actual: %s)\n", $status, $name, $expected, $actual);
}

echo "====================================================================\n";
echo "RUNNING WHATSAPP BOT CONTROL PANEL VERIFICATION SUITE\n";
echo "====================================================================\n\n";

$db = get_db();

// ---------------------------------------------------------------------
// TEST GROUP 1: AUTHENTICATION & SESSIONS
// ---------------------------------------------------------------------
echo "--- 1. Authentication & Security Tests ---\n";

// Valid Admin Login
$loginAdmin = Auth::login('admin', 'Admin@123456');
assert_test(
    "Admin Valid Login",
    $loginAdmin['success'] === true && $_SESSION['role'] === 'admin',
    "success=true, role=admin",
    "success=" . ($loginAdmin['success'] ? 'true' : 'false') . ", role=" . ($_SESSION['role'] ?? 'none')
);

// Invalid Password Rejection
$loginFail = Auth::login('admin', 'WrongPassword!');
assert_test(
    "Invalid Password Rejection",
    $loginFail['success'] === false,
    "success=false",
    "success=" . ($loginFail['success'] ? 'true' : 'false')
);

// Valid Operator Login
$loginOp = Auth::login('operator', 'Operator@123456');
assert_test(
    "Operator Valid Login",
    $loginOp['success'] === true && $_SESSION['role'] === 'operator',
    "success=true, role=operator",
    "success=" . ($loginOp['success'] ? 'true' : 'false') . ", role=" . ($_SESSION['role'] ?? 'none')
);

// CSRF Verification
$token = generate_csrf_token();
$validCsrf = verify_csrf_token($token);
$invalidCsrf = verify_csrf_token('fake_token_value_123');
assert_test(
    "CSRF Token Validation",
    $validCsrf === true && $invalidCsrf === false,
    "valid=true, invalid=false",
    "valid=" . ($validCsrf ? 'true' : 'false') . ", invalid=" . ($invalidCsrf ? 'true' : 'false')
);

// ---------------------------------------------------------------------
// TEST GROUP 2: PHONE NORMALIZATION
// ---------------------------------------------------------------------
echo "\n--- 2. Phone Number Normalization Tests ---\n";

$norm1 = normalize_phone('012-345 6789'); // Local Malaysian
assert_test("Normalize Local '012-345 6789'", $norm1 === '60123456789', '60123456789', (string)$norm1);

$norm2 = normalize_phone('+60 19-876 5432'); // Leading plus
assert_test("Normalize Leading Plus '+60 19-876 5432'", $norm2 === '60198765432', '60198765432', (string)$norm2);

$norm3 = normalize_phone('0060 17 111 2233'); // Leading 00
assert_test("Normalize Leading 00 '0060 17 111 2233'", $norm3 === '60171112233', '60171112233', (string)$norm3);

$norm4 = normalize_phone('123'); // Too short
assert_test("Reject Too Short Number '123'", $norm4 === false, 'false', $norm4 === false ? 'false' : (string)$norm4);

// ---------------------------------------------------------------------
// TEST GROUP 3: TEMPLATE ENGINE
// ---------------------------------------------------------------------
echo "\n--- 3. Template Engine Safe Variable Replacement ---\n";

$template = "Hello {{name}}, welcome to {{company}}! Your registered phone is {{phone}}.";
$contact = [
    'name'    => 'Sarah Connor',
    'phone'   => '60129998888',
    'company' => 'Cyberdyne Systems'
];
$rendered = TemplateEngine::render($template, $contact);
$expectedRendered = "Hello Sarah Connor, welcome to Cyberdyne Systems! Your registered phone is 60129998888.";
assert_test("Render Template Supported Variables", $rendered === $expectedRendered, $expectedRendered, $rendered);

// Missing optional variable handling
$contactPartial = ['name' => 'John', 'phone' => '60120000000'];
$renderedPartial = TemplateEngine::render("Hi {{name}} at {{company}}", $contactPartial);
assert_test("Missing Variable Safe Substitution", $renderedPartial === "Hi John at ", "Hi John at ", $renderedPartial);

// ---------------------------------------------------------------------
// TEST GROUP 4: ATOMIC QUEUE CLAIMING & CONCURRENCY
// ---------------------------------------------------------------------
echo "\n--- 4. Queue Concurrency, Claims & State Tests ---\n";

// Create test workers: Worker A and Worker B
$uuidA = 'test_worker_a_' . time();
$tokenA = 'test_token_a_' . bin2hex(random_bytes(16));
$hashA = password_hash($tokenA, PASSWORD_BCRYPT);
$db->prepare("INSERT INTO workers (worker_uuid, name, token_hash, status, whatsapp_status) VALUES (?, 'Worker A', ?, 'online', 'connected')")
   ->execute([$uuidA, $hashA]);
$workerA_Id = (int)$db->lastInsertId();

$uuidB = 'test_worker_b_' . time();
$tokenB = 'test_token_b_' . bin2hex(random_bytes(16));
$hashB = password_hash($tokenB, PASSWORD_BCRYPT);
$db->prepare("INSERT INTO workers (worker_uuid, name, token_hash, status, whatsapp_status) VALUES (?, 'Worker B', ?, 'online', 'connected')")
   ->execute([$uuidB, $hashB]);
$workerB_Id = (int)$db->lastInsertId();

// Create test contact & job
$db->prepare("INSERT INTO contacts (name, phone, status) VALUES ('Queue Test Contact', '60199999999', 'active') ON DUPLICATE KEY UPDATE name = VALUES(name)")->execute();
$cId = (int)$db->query("SELECT id FROM contacts WHERE phone = '60199999999'")->fetchColumn();

$db->prepare("INSERT INTO message_jobs (contact_id, phone, rendered_message, status, attempts, max_attempts) VALUES (?, '60199999999', 'Queue concurrency test', 'pending', 0, 3)")
   ->execute([$cId]);
$testJobId = (int)$db->lastInsertId();

// Worker A claims job
$claimedByA = QueueManager::claimNextJob($workerA_Id);
assert_test(
    "Worker A Claims Job Atomically",
    $claimedByA !== null && (int)$claimedByA['id'] === $testJobId,
    "claimed_id=$testJobId",
    "claimed_id=" . ($claimedByA['id'] ?? 'null')
);

// Worker B immediately attempts to claim next job (should be null, job already processing!)
$claimedByB = QueueManager::claimNextJob($workerB_Id);
assert_test(
    "Worker B Blocked from Claiming Already Processing Job",
    $claimedByB === null || (int)$claimedByB['id'] !== $testJobId,
    "claimed_id != $testJobId",
    "claimed_id=" . ($claimedByB['id'] ?? 'null')
);

// ---------------------------------------------------------------------
// TEST GROUP 5: STRICT REPORTING & OWNERSHIP ENFORCEMENT
// ---------------------------------------------------------------------
echo "\n--- 5. Worker Ownership Enforcement on Job Reporting ---\n";

// Worker B attempts to report for Worker A's job! (Must be rejected with 403 Forbidden!)
$hijackReport = QueueManager::reportJobResult($testJobId, $workerB_Id, 'sent');
assert_test(
    "Worker B Cannot Report Result for Worker A's Job",
    $hijackReport['code'] === 403,
    "HTTP 403 Forbidden",
    "HTTP " . $hijackReport['code'] . " (" . $hijackReport['message'] . ")"
);

// Legitimate report by Worker A (Success)
$validReport = QueueManager::reportJobResult($testJobId, $workerA_Id, 'sent');
assert_test(
    "Worker A Reports Result Successfully",
    $validReport['code'] === 200,
    "HTTP 200 OK",
    "HTTP " . $validReport['code'] . " (" . $validReport['message'] . ")"
);

// Verify Job Status in DB
$checkJob = $db->query("SELECT status, sent_at FROM message_jobs WHERE id = $testJobId")->fetch();
assert_test(
    "Job Status Updated to 'sent'",
    $checkJob['status'] === 'sent' && !empty($checkJob['sent_at']),
    "status=sent, sent_at!=null",
    "status=" . $checkJob['status'] . ", sent_at=" . ($checkJob['sent_at'] ?? 'null')
);

// ---------------------------------------------------------------------
// TEST GROUP 6: RETRIES & STALE JOB RECOVERY
// ---------------------------------------------------------------------
echo "\n--- 6. Retries & Stale Processing Job Recovery ---\n";

// Create a job that fails on attempt 1 (should requeue for retry)
$db->prepare("INSERT INTO message_jobs (contact_id, phone, rendered_message, status, attempts, max_attempts) VALUES (?, '60199999999', 'Retry test', 'pending', 0, 3)")
   ->execute([$cId]);
$retryJobId = (int)$db->lastInsertId();

$claimRetry = QueueManager::claimNextJob($workerA_Id);
$failReport = QueueManager::reportJobResult((int)$claimRetry['id'], $workerA_Id, 'failed', 'Temporary network glitch');

$checkRetry = $db->query("SELECT status, attempts, last_error FROM message_jobs WHERE id = $retryJobId")->fetch();
assert_test(
    "Failed Job Under Max Attempts Requeued to 'pending'",
    $checkRetry['status'] === 'pending' && (int)$checkRetry['attempts'] === 1,
    "status=pending, attempts=1",
    "status=" . $checkRetry['status'] . ", attempts=" . $checkRetry['attempts']
);

// Simulate Stale Processing Job (Worker crashed, heartbeat expired, claimed > 300s ago)
$db->prepare("
    UPDATE message_jobs 
    SET status = 'processing', 
        worker_id = ?, 
        claimed_at = (UTC_TIMESTAMP() - INTERVAL 400 SECOND) 
    WHERE id = ?
")->execute([$workerA_Id, $retryJobId]);

// Make Worker A stale
$db->prepare("
    UPDATE workers 
    SET status = 'offline', 
        last_heartbeat_at = (UTC_TIMESTAMP() - INTERVAL 120 SECOND) 
    WHERE id = ?
")->execute([$workerA_Id]);

// Run stale recovery
$recoveredCount = QueueManager::recoverStaleJobs();
$checkStale = $db->query("SELECT status, worker_id, last_error FROM message_jobs WHERE id = $retryJobId")->fetch();
assert_test(
    "Stale Abandoned Job Successfully Recovered and Requeued",
    $checkStale['status'] === 'pending' && $checkStale['worker_id'] === null,
    "status=pending, worker_id=null",
    "status=" . $checkStale['status'] . ", worker_id=" . ($checkStale['worker_id'] ?? 'null')
);

// ---------------------------------------------------------------------
// TEST GROUP 7: CLEANUP
// ---------------------------------------------------------------------
// Remove test rows
$db->exec("DELETE FROM message_jobs WHERE contact_id = $cId");
$db->exec("DELETE FROM contacts WHERE id = $cId");
$db->exec("DELETE FROM workers WHERE id IN ($workerA_Id, $workerB_Id)");

echo "\n====================================================================\n";
$total = count($testResults);
$passed = count(array_filter($testResults, fn($r) => $r['status'] === 'PASS'));
$failed = $total - $passed;
echo sprintf("TEST SUMMARY: %d Total | %d Passed | %d Failed\n", $total, $passed, $failed);
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
