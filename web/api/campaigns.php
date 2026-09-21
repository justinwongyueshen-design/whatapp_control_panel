<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/template_engine.php';
require_once __DIR__ . '/../includes/normalizer.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/logger.php';

Auth::requireLogin();
$db = get_db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            $stmt = $db->query("
                SELECT c.*, t.title as template_title, m.original_name as media_name, u.username as creator_name
                FROM campaigns c
                LEFT JOIN message_templates t ON c.template_id = t.id
                LEFT JOIN media_files m ON c.media_id = m.id
                LEFT JOIN users u ON c.created_by = u.id
                ORDER BY c.id DESC
            ");
            $campaigns = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $campaigns]);
            break;

        case 'create':
            require_csrf_token();
            $name = trim($_POST['name'] ?? '');
            $templateId = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
            $customMessage = trim($_POST['custom_message'] ?? '');
            $mediaId = !empty($_POST['media_id']) ? (int)$_POST['media_id'] : null;
            $audienceType = $_POST['audience_type'] ?? 'all'; // 'all', 'group', 'selected'
            $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
            $selectedContactIds = $_POST['contact_ids'] ?? [];
            $scheduledAtInput = trim($_POST['scheduled_at'] ?? ''); // in Asia/Kuala_Lumpur

            if (empty($name)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Campaign name is required.']);
                exit;
            }

            // Determine message template content
            $messageContent = $customMessage;
            if ($templateId) {
                $tStmt = $db->prepare("SELECT content FROM message_templates WHERE id = :id");
                $tStmt->execute([':id' => $templateId]);
                $tRow = $tStmt->fetch();
                if ($tRow) {
                    $messageContent = $tRow['content'];
                }
            }

            if (empty($messageContent)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Campaign message content or template is required.']);
                exit;
            }

            // Convert scheduled_at from Application Timezone to UTC
            $scheduledAtUtc = null;
            if (!empty($scheduledAtInput)) {
                try {
                    $dt = new DateTime($scheduledAtInput, new DateTimeZone(APP_TIMEZONE));
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $scheduledAtUtc = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Invalid schedule date format.']);
                    exit;
                }
            }

            // Fetch target contacts
            $contactParams = [];
            $contactSql = "SELECT id, name, phone, company FROM contacts WHERE status = 'active'";
            if ($audienceType === 'group' && $groupId) {
                $contactSql .= " AND id IN (SELECT contact_id FROM contact_group_members WHERE group_id = :gid)";
                $contactParams[':gid'] = $groupId;
            } elseif ($audienceType === 'selected' && is_array($selectedContactIds) && !empty($selectedContactIds)) {
                $inPlaceholders = implode(',', array_fill(0, count($selectedContactIds), '?'));
                $contactSql .= " AND id IN ($inPlaceholders)";
                $contactParams = array_map('intval', $selectedContactIds);
            }

            $cStmt = $db->prepare($contactSql);
            $cStmt->execute($contactParams);
            $recipients = $cStmt->fetchAll();

            if (empty($recipients)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'No active contacts matched the selected audience.']);
                exit;
            }

            $totalRecipients = count($recipients);

            // Begin atomic campaign and job creation
            $db->beginTransaction();

            $initialStatus = $scheduledAtUtc ? 'queued' : 'queued';

            $campInsert = $db->prepare("
                INSERT INTO campaigns (name, template_id, media_id, custom_message, status, total_jobs, pending_jobs, scheduled_at, created_by)
                VALUES (:name, :template_id, :media_id, :custom_message, :status, :total_jobs, :pending_jobs, :scheduled_at, :created_by)
            ");
            $campInsert->execute([
                ':name'           => $name,
                ':template_id'    => $templateId,
                ':media_id'       => $mediaId,
                ':custom_message' => $customMessage,
                ':status'         => $initialStatus,
                ':total_jobs'     => $totalRecipients,
                ':pending_jobs'   => $totalRecipients,
                ':scheduled_at'   => $scheduledAtUtc,
                ':created_by'     => $_SESSION['user_id'] ?? null
            ]);
            $campaignId = (int)$db->lastInsertId();

            // Insert jobs
            $jobInsert = $db->prepare("
                INSERT INTO message_jobs (campaign_id, contact_id, phone, rendered_message, media_id, status, scheduled_at, max_attempts)
                VALUES (:campaign_id, :contact_id, :phone, :rendered_message, :media_id, 'pending', :scheduled_at, 3)
            ");

            foreach ($recipients as $recipient) {
                $rendered = TemplateEngine::render($messageContent, $recipient);
                $jobInsert->execute([
                    ':campaign_id'      => $campaignId,
                    ':contact_id'       => $recipient['id'],
                    ':phone'            => $recipient['phone'],
                    ':rendered_message' => $rendered,
                    ':media_id'         => $mediaId,
                    ':scheduled_at'     => $scheduledAtUtc
                ]);
            }

            $db->commit();

            audit_log('campaign_created', "Created campaign #$campaignId '$name' with $totalRecipients jobs");

            echo json_encode([
                'success' => true,
                'message' => "Campaign '$name' created with $totalRecipients jobs queued.",
                'campaign_id' => $campaignId,
                'total_jobs' => $totalRecipients
            ]);
            break;

        case 'pause':
            require_csrf_token();
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("UPDATE campaigns SET status = 'paused' WHERE id = :id AND status IN ('queued', 'running')");
            $stmt->execute([':id' => $id]);
            audit_log('campaign_paused', "Paused campaign #$id");
            echo json_encode(['success' => true, 'message' => 'Campaign paused. Active running messages will finish; queued messages will pause.']);
            break;

        case 'resume':
            require_csrf_token();
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("UPDATE campaigns SET status = 'running' WHERE id = :id AND status = 'paused'");
            $stmt->execute([':id' => $id]);
            audit_log('campaign_resumed', "Resumed campaign #$id");
            echo json_encode(['success' => true, 'message' => 'Campaign resumed.']);
            break;

        case 'cancel':
            require_csrf_token();
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE campaigns SET status = 'cancelled' WHERE id = :id AND status != 'completed'");
            $stmt->execute([':id' => $id]);

            // Mark remaining pending jobs as cancelled
            $jobCancel = $db->prepare("UPDATE message_jobs SET status = 'cancelled' WHERE campaign_id = :id AND status = 'pending'");
            $jobCancel->execute([':id' => $id]);

            QueueManager::syncCampaignStats($id);
            $db->commit();
            audit_log('campaign_cancelled', "Cancelled campaign #$id");
            echo json_encode(['success' => true, 'message' => 'Campaign and remaining pending jobs cancelled.']);
            break;

        case 'quick_send':
            require_csrf_token();
            $phoneRaw = trim($_POST['phone'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $mediaId = !empty($_POST['media_id']) ? (int)$_POST['media_id'] : null;

            $phone = normalize_phone($phoneRaw);
            if (!$phone) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid recipient phone number.']);
                exit;
            }

            if (empty($message)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Message text cannot be empty.']);
                exit;
            }

            // Find or create contact
            $cStmt = $db->prepare("SELECT id, name FROM contacts WHERE phone = :phone LIMIT 1");
            $cStmt->execute([':phone' => $phone]);
            $contact = $cStmt->fetch();

            $contactId = null;
            if ($contact) {
                $contactId = $contact['id'];
                $rendered = TemplateEngine::render($message, $contact);
            } else {
                $insC = $db->prepare("INSERT INTO contacts (name, phone, status) VALUES (:name, :phone, 'active')");
                $insC->execute([':name' => 'Direct Contact ' . substr($phone, -4), ':phone' => $phone]);
                $contactId = (int)$db->lastInsertId();
                $rendered = $message;
            }

            $jobStmt = $db->prepare("
                INSERT INTO message_jobs (campaign_id, contact_id, phone, rendered_message, media_id, status, max_attempts)
                VALUES (NULL, :contact_id, :phone, :message, :media_id, 'pending', 3)
            ");
            $jobStmt->execute([
                ':contact_id' => $contactId,
                ':phone'      => $phone,
                ':message'    => $rendered,
                ':media_id'   => $mediaId
            ]);
            $jobId = (int)$db->lastInsertId();

            audit_log('quick_send_queued', "Queued direct message #$jobId to $phone");
            echo json_encode(['success' => true, 'message' => "Message queued for sending to $phone (Job #$jobId)."]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
