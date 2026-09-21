<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/normalizer.php';
require_once __DIR__ . '/../includes/logger.php';

Auth::requireLogin();
$db = get_db();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

/**
 * Formula injection sanitizer
 */
function sanitize_csv_field(string $value): string {
    $firstChar = substr($value, 0, 1);
    if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }
    return $value;
}

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            $groupId = !empty($_GET['group_id']) ? (int)$_GET['group_id'] : null;
            $status = $_GET['status'] ?? null;

            $params = [];
            $sql = "
                SELECT c.*, GROUP_CONCAT(g.name SEPARATOR ', ') as group_names
                FROM contacts c
                LEFT JOIN contact_group_members cgm ON c.id = cgm.contact_id
                LEFT JOIN contact_groups g ON cgm.group_id = g.id
                WHERE 1=1
            ";

            if (!empty($search)) {
                $sql .= " AND (c.name LIKE :search OR c.phone LIKE :search OR c.company LIKE :search)";
                $params[':search'] = "%$search%";
            }

            if ($groupId) {
                $sql .= " AND c.id IN (SELECT contact_id FROM contact_group_members WHERE group_id = :group_id)";
                $params[':group_id'] = $groupId;
            }

            if ($status && in_array($status, ['active', 'inactive'], true)) {
                $sql .= " AND c.status = :status";
                $params[':status'] = $status;
            }

            $sql .= " GROUP BY c.id ORDER BY c.id DESC LIMIT 100";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $contacts = $stmt->fetchAll();

            echo json_encode(['success' => true, 'data' => $contacts]);
            break;

        case 'save':
            require_csrf_token();
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $name = trim($_POST['name'] ?? '');
            $rawPhone = trim($_POST['phone'] ?? '');
            $company = trim($_POST['company'] ?? '');
            $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
            $groupIds = $_POST['group_ids'] ?? [];

            if (empty($name) || empty($rawPhone)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Name and phone number are required.']);
                exit;
            }

            $phone = normalize_phone($rawPhone);
            if (!$phone) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid phone number. Ensure it contains a valid country code (e.g. 60123456789).']);
                exit;
            }

            // Check duplicate phone
            $dupSql = "SELECT id FROM contacts WHERE phone = :phone" . ($id ? " AND id != :id" : "");
            $dupStmt = $db->prepare($dupSql);
            $dupParams = [':phone' => $phone];
            if ($id) $dupParams[':id'] = $id;
            $dupStmt->execute($dupParams);
            if ($dupStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'A contact with this phone number already exists.']);
                exit;
            }

            $name = sanitize_csv_field($name);
            $company = sanitize_csv_field($company);

            $db->beginTransaction();
            if ($id) {
                $stmt = $db->prepare("UPDATE contacts SET name = :name, phone = :phone, company = :company, status = :status WHERE id = :id");
                $stmt->execute([':name' => $name, ':phone' => $phone, ':company' => $company, ':status' => $status, ':id' => $id]);
                audit_log('contact_updated', "Updated contact #$id ($phone)");
            } else {
                $stmt = $db->prepare("INSERT INTO contacts (name, phone, company, status) VALUES (:name, :phone, :company, :status)");
                $stmt->execute([':name' => $name, ':phone' => $phone, ':company' => $company, ':status' => $status]);
                $id = (int)$db->lastInsertId();
                audit_log('contact_created', "Created contact #$id ($phone)");
            }

            // Update group assignments
            if (is_array($groupIds)) {
                $delStmt = $db->prepare("DELETE FROM contact_group_members WHERE contact_id = :id");
                $delStmt->execute([':id' => $id]);

                if (!empty($groupIds)) {
                    $insStmt = $db->prepare("INSERT IGNORE INTO contact_group_members (group_id, contact_id) VALUES (:group_id, :contact_id)");
                    foreach ($groupIds as $gid) {
                        $insStmt->execute([':group_id' => (int)$gid, ':contact_id' => $id]);
                    }
                }
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Contact saved successfully', 'contact_id' => $id]);
            break;

        case 'delete':
            require_csrf_token();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid contact ID']);
                exit;
            }

            $stmt = $db->prepare("DELETE FROM contacts WHERE id = :id");
            $stmt->execute([':id' => $id]);
            audit_log('contact_deleted', "Deleted contact #$id");

            echo json_encode(['success' => true, 'message' => 'Contact deleted successfully']);
            break;

        case 'import_csv':
            require_csrf_token();
            if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please upload a valid CSV file.']);
                exit;
            }

            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, 'r');
            if (!$handle) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Could not read uploaded CSV file.']);
                exit;
            }

            $header = fgetcsv($handle);
            if (!$header) {
                fclose($handle);
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Empty CSV file.']);
                exit;
            }

            // Map columns
            $colMap = [];
            foreach ($header as $idx => $col) {
                $colName = strtolower(trim($col));
                if (in_array($colName, ['name', 'full_name', 'fullname'])) $colMap['name'] = $idx;
                if (in_array($colName, ['phone', 'mobile', 'phone_number', 'whatsapp'])) $colMap['phone'] = $idx;
                if (in_array($colName, ['company', 'organization'])) $colMap['company'] = $idx;
            }

            if (!isset($colMap['phone'])) {
                fclose($handle);
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => "CSV must have a 'phone' column header."]);
                exit;
            }

            $targetGroupId = !empty($_POST['target_group_id']) ? (int)$_POST['target_group_id'] : null;

            $db->beginTransaction();
            $insContact = $db->prepare("INSERT INTO contacts (name, phone, company, status) VALUES (:name, :phone, :company, 'active') ON DUPLICATE KEY UPDATE name = VALUES(name), company = VALUES(company)");
            $getContactId = $db->prepare("SELECT id FROM contacts WHERE phone = :phone LIMIT 1");
            $insGroupMember = $db->prepare("INSERT IGNORE INTO contact_group_members (group_id, contact_id) VALUES (:group_id, :contact_id)");

            $imported = 0;
            $skipped = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $phoneRaw = isset($colMap['phone']) ? ($row[$colMap['phone']] ?? '') : '';
                $nameRaw = isset($colMap['name']) ? ($row[$colMap['name']] ?? '') : '';
                $companyRaw = isset($colMap['company']) ? ($row[$colMap['company']] ?? '') : '';

                $normPhone = normalize_phone($phoneRaw);
                if (!$normPhone) {
                    $skipped++;
                    continue;
                }

                $name = sanitize_csv_field(trim($nameRaw) ?: 'Customer ' . substr($normPhone, -4));
                $company = sanitize_csv_field(trim($companyRaw));

                $insContact->execute([
                    ':name' => $name,
                    ':phone' => $normPhone,
                    ':company' => $company
                ]);

                if ($targetGroupId) {
                    $getContactId->execute([':phone' => $normPhone]);
                    $cid = $getContactId->fetchColumn();
                    if ($cid) {
                        $insGroupMember->execute([':group_id' => $targetGroupId, ':contact_id' => $cid]);
                    }
                }

                $imported++;
            }

            fclose($handle);
            $db->commit();
            audit_log('contacts_imported_csv', "Imported $imported contacts, skipped $skipped invalid");

            echo json_encode([
                'success' => true,
                'message' => "Successfully imported $imported contacts ($skipped skipped).",
                'imported' => $imported,
                'skipped' => $skipped
            ]);
            break;

        case 'export_csv':
            $stmt = $db->query("SELECT name, phone, company, status FROM contacts ORDER BY id ASC");
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="contacts_export_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Phone', 'Company', 'Status']);
            while ($row = $stmt->fetch()) {
                fputcsv($out, [
                    sanitize_csv_field($row['name']),
                    $row['phone'],
                    sanitize_csv_field($row['company'] ?? ''),
                    $row['status']
                ]);
            }
            fclose($out);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
