<?php
$pageTitle = 'Logs & Audits';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/db.php';

Auth::requireLogin();
$db = get_db();

$tab = $_GET['tab'] ?? 'messages';
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

// Message logs
if ($tab === 'messages') {
    $mSql = "
        SELECT l.*, c.name as campaign_name, w.name as worker_name
        FROM message_logs l
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN workers w ON l.worker_id = w.id
        WHERE 1=1
    ";
    $mParams = [];
    if (!empty($search)) {
        $mSql .= " AND (l.recipient_phone LIKE :s OR l.error_message LIKE :s OR c.name LIKE :s)";
        $mParams[':s'] = "%$search%";
    }
    if (!empty($statusFilter)) {
        $mSql .= " AND l.status = :st";
        $mParams[':st'] = $statusFilter;
    }
    $mSql .= " ORDER BY l.id DESC LIMIT 100";
    $mStmt = $db->prepare($mSql);
    $mStmt->execute($mParams);
    $msgLogs = $mStmt->fetchAll();
} else {
    // Audit logs
    $aSql = "
        SELECT a.*, u.username, w.name as worker_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN workers w ON a.worker_id = w.id
        WHERE 1=1
    ";
    $aParams = [];
    if (!empty($search)) {
        $aSql .= " AND (a.action LIKE :s OR a.details LIKE :s OR a.ip_address LIKE :s)";
        $aParams[':s'] = "%$search%";
    }
    $aSql .= " ORDER BY a.id DESC LIMIT 100";
    $aStmt = $db->prepare($aSql);
    $aStmt->execute($aParams);
    $auditLogs = $aStmt->fetchAll();
}
?>

<div class="card">
    <div class="card-header">
        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="?tab=messages" class="btn btn-<?= $tab === 'messages' ? 'primary' : 'secondary' ?> btn-sm">Message Delivery Logs</a>
            <a href="?tab=audit" class="btn btn-<?= $tab === 'audit' ? 'primary' : 'secondary' ?> btn-sm">System Audit Trail</a>
        </div>
    </div>
    <div class="card-body">
        <!-- Filter Form -->
        <form method="GET" class="filter-bar">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="text" name="search" class="form-control" style="max-width: 280px;" placeholder="Search keywords..." value="<?= htmlspecialchars($search) ?>">

            <?php if ($tab === 'messages'): ?>
            <select name="status" class="form-control" style="max-width: 160px;">
                <option value="">All Statuses</option>
                <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
                <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                <option value="retrying" <?= $statusFilter === 'retrying' ? 'selected' : '' ?>>Retrying</option>
                <option value="claimed" <?= $statusFilter === 'claimed' ? 'selected' : '' ?>>Claimed</option>
            </select>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="?tab=<?= htmlspecialchars($tab) ?>" class="btn btn-secondary btn-sm">Reset</a>
        </form>

        <?php if ($tab === 'messages'): ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Timestamp (UTC)</th>
                        <th>Recipient</th>
                        <th>Status</th>
                        <th>Attempt</th>
                        <th>Campaign</th>
                        <th>Worker</th>
                        <th>Error Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($msgLogs)): ?>
                        <tr><td colspan="8" style="text-align: center; padding: 24px; color: var(--slate-500);">No message logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($msgLogs as $l): ?>
                        <tr>
                            <td>#<?= $l['id'] ?></td>
                            <td style="font-size: 0.8rem;"><?= htmlspecialchars($l['created_at']) ?></td>
                            <td><strong><?= htmlspecialchars($l['recipient_phone']) ?></strong></td>
                            <td>
                                <span class="badge badge-<?= $l['status'] === 'sent' ? 'success' : ($l['status'] === 'failed' ? 'danger' : ($l['status'] === 'claimed' ? 'info' : 'warning')) ?>">
                                    <?= strtoupper($l['status']) ?>
                                </span>
                            </td>
                            <td>#<?= $l['attempt_number'] ?></td>
                            <td><?= htmlspecialchars($l['campaign_name'] ?? 'Direct Send') ?></td>
                            <td><?= htmlspecialchars($l['worker_name'] ?? 'Queue') ?></td>
                            <td style="font-size: 0.8rem; color: #b91c1c;"><?= htmlspecialchars($l['error_message'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Timestamp (UTC)</th>
                        <th>Action</th>
                        <th>User / Worker</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($auditLogs)): ?>
                        <tr><td colspan="6" style="text-align: center; padding: 24px; color: var(--slate-500);">No audit records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($auditLogs as $a): ?>
                        <tr>
                            <td>#<?= $a['id'] ?></td>
                            <td style="font-size: 0.8rem;"><?= htmlspecialchars($a['created_at']) ?></td>
                            <td><code><?= htmlspecialchars($a['action']) ?></code></td>
                            <td><?= htmlspecialchars($a['username'] ?? ($a['worker_name'] ? 'Worker: ' . $a['worker_name'] : 'System')) ?></td>
                            <td style="font-size: 0.85rem;"><?= htmlspecialchars($a['details'] ?? '—') ?></td>
                            <td><code><?= htmlspecialchars($a['ip_address'] ?? '127.0.0.1') ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
