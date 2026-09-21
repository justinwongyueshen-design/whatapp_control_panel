<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/db.php';

Auth::requireLogin();
$db = get_db();

// 1. Fetch Stats
$stats = [
    'online_workers' => 0,
    'wa_connected'   => false,
    'pending_queue'  => 0,
    'sent_today'     => 0,
    'failed_today'   => 0,
    'active_camps'   => 0
];

try {
    $wStmt = $db->query("
        SELECT 
            SUM(CASE WHEN status = 'online' AND (last_heartbeat_at >= UTC_TIMESTAMP() - INTERVAL 60 SECOND) THEN 1 ELSE 0 END) as online_w,
            SUM(CASE WHEN status = 'online' AND whatsapp_status = 'connected' AND (last_heartbeat_at >= UTC_TIMESTAMP() - INTERVAL 60 SECOND) THEN 1 ELSE 0 END) as wa_conn
        FROM workers WHERE status != 'disabled'
    ");
    $wRow = $wStmt->fetch();
    $stats['online_workers'] = (int)($wRow['online_w'] ?? 0);
    $stats['wa_connected'] = (int)($wRow['wa_conn'] ?? 0) > 0;

    $jStmt = $db->query("
        SELECT 
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'sent' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as sent_24h,
            SUM(CASE WHEN status = 'failed' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as failed_24h
        FROM message_jobs
    ");
    $jRow = $jStmt->fetch();
    $stats['pending_queue'] = (int)($jRow['pending'] ?? 0);
    $stats['sent_today'] = (int)($jRow['sent_24h'] ?? 0);
    $stats['failed_today'] = (int)($jRow['failed_24h'] ?? 0);

    $cStmt = $db->query("SELECT COUNT(*) FROM campaigns WHERE status IN ('queued', 'running')");
    $stats['active_camps'] = (int)$cStmt->fetchColumn();

    // Recent message logs
    $recentStmt = $db->query("
        SELECT l.*, j.phone, c.name as campaign_name, w.name as worker_name
        FROM message_logs l
        LEFT JOIN message_jobs j ON l.job_id = j.id
        LEFT JOIN campaigns c ON l.campaign_id = c.id
        LEFT JOIN workers w ON l.worker_id = w.id
        ORDER BY l.id DESC LIMIT 8
    ");
    $recentLogs = $recentStmt->fetchAll();

    // Active campaigns preview
    $activeCampStmt = $db->query("
        SELECT * FROM campaigns WHERE status IN ('queued', 'running', 'paused') ORDER BY id DESC LIMIT 5
    ");
    $activeCampaigns = $activeCampStmt->fetchAll();

    // Templates for quick send
    $tStmt = $db->query("SELECT id, title FROM message_templates ORDER BY title ASC");
    $templates = $tStmt->fetchAll();
} catch (Exception $e) {
    error_log("Dashboard query error: " . $e->getMessage());
}
?>

<!-- Stat Cards -->
<div class="grid-4">
    <div class="stat-card">
        <div class="stat-icon" style="background-color: <?= $stats['online_workers'] > 0 ? '#ecfdf5' : '#fee2e2' ?>; color: <?= $stats['online_workers'] > 0 ? '#059669' : '#dc2626' ?>;">
            ⚡
        </div>
        <div class="stat-info">
            <span class="stat-value"><?= $stats['online_workers'] ?></span>
            <span class="stat-label">Active Workers</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background-color: <?= $stats['wa_connected'] ? '#ecfdf5' : '#fef3c7' ?>; color: <?= $stats['wa_connected'] ? '#059669' : '#b45309' ?>;">
            💬
        </div>
        <div class="stat-info">
            <span class="stat-value"><?= $stats['wa_connected'] ? 'Connected' : 'Offline' ?></span>
            <span class="stat-label">WhatsApp Web Status</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background-color: #eff6ff; color: #2563eb;">
            ⏳
        </div>
        <div class="stat-info">
            <span class="stat-value"><?= $stats['pending_queue'] ?></span>
            <span class="stat-label">Queue Pending</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background-color: #f5f3ff; color: #7c3aed;">
            📬
        </div>
        <div class="stat-info">
            <span class="stat-value"><?= $stats['sent_today'] ?></span>
            <span class="stat-label">Messages Sent Today</span>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 28px;">
    <!-- Active Campaigns Progress -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Active Campaigns</h3>
            <a href="<?= BASE_URL ?>/admin/campaign_create.php" class="btn btn-primary btn-sm">+ New Campaign</a>
        </div>
        <div class="card-body">
            <?php if (empty($activeCampaigns)): ?>
                <p style="color: var(--slate-500); font-size: 0.9rem; text-align: center; padding: 20px 0;">No running or queued campaigns right now.</p>
            <?php else: ?>
                <?php foreach ($activeCampaigns as $camp): 
                    $pct = $camp['total_jobs'] > 0 ? round(($camp['sent_jobs'] / $camp['total_jobs']) * 100) : 0;
                ?>
                <div style="margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid var(--slate-200);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-weight: 600; font-size: 0.92rem;"><?= htmlspecialchars($camp['name']) ?></span>
                        <span class="badge badge-<?= $camp['status'] === 'running' ? 'primary' : ($camp['status'] === 'paused' ? 'warning' : 'secondary') ?>">
                            <?= ucfirst($camp['status']) ?>
                        </span>
                    </div>
                    <div class="progress" style="margin-bottom: 6px;">
                        <div class="progress-bar" style="width: <?= $pct ?>%;"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.78rem; color: var(--slate-500);">
                        <span>Sent: <?= $camp['sent_jobs'] ?> / <?= $camp['total_jobs'] ?> (<?= $pct ?>%)</span>
                        <span>Pending: <?= $camp['pending_jobs'] ?> | Failed: <?= $camp['failed_jobs'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Send Single Message -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">⚡ Quick Direct Send</h3>
        </div>
        <div class="card-body">
            <form id="quickSendForm">
                <div class="form-group">
                    <label class="form-label" for="qs_phone">Recipient Phone</label>
                    <input type="text" id="qs_phone" name="phone" class="form-control" placeholder="e.g. 0123456789 or 60123456789" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="qs_message">Message Text</label>
                    <textarea id="qs_message" name="message" class="form-control" rows="3" placeholder="Hello, this is a direct notification..." required></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">Queue Message</button>
            </form>
            <div id="quickSendAlert" style="margin-top: 12px; display: none;"></div>
        </div>
    </div>
</div>

<!-- Recent Activity Log Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Recent Activity Logs</h3>
        <a href="<?= BASE_URL ?>/admin/logs.php" class="btn btn-secondary btn-sm">View All Logs</a>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>Recipient</th>
                    <th>Status</th>
                    <th>Campaign</th>
                    <th>Worker</th>
                    <th>Error / Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--slate-400); padding: 24px;">No message activity recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td>#<?= $log['job_id'] ?></td>
                        <td><?= htmlspecialchars($log['created_at']) ?></td>
                        <td><strong><?= htmlspecialchars($log['recipient_phone']) ?></strong></td>
                        <td>
                            <span class="badge badge-<?= $log['status'] === 'sent' ? 'success' : ($log['status'] === 'failed' ? 'danger' : ($log['status'] === 'claimed' ? 'info' : 'warning')) ?>">
                                <?= ucfirst($log['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($log['campaign_name'] ?? 'Direct Send') ?></td>
                        <td><?= htmlspecialchars($log['worker_name'] ?? 'Queue') ?></td>
                        <td><?= htmlspecialchars($log['error_message'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('quickSendForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertDiv = document.getElementById('quickSendAlert');
    alertDiv.style.display = 'none';

    const formData = new FormData(e.target);
    const data = await apiRequest('../api/campaigns.php?action=quick_send', 'POST', formData);

    alertDiv.style.display = 'block';
    if (data.success) {
        alertDiv.className = 'alert alert-success';
        alertDiv.textContent = data.message;
        e.target.reset();
        setTimeout(() => location.reload(), 1500);
    } else {
        alertDiv.className = 'alert alert-danger';
        alertDiv.textContent = data.message || 'Failed to queue message.';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
