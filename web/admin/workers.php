<?php
$pageTitle = 'Workers & WhatsApp';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/db.php';

Auth::requireLogin();
$db = get_db();

$isAdmin = ($currentUser['role'] ?? '') === 'admin';

// Query all workers
$stmt = $db->query("SELECT * FROM workers ORDER BY id DESC");
$workers = $stmt->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Windows Automation Workers</h3>
        <?php if ($isAdmin): ?>
        <button onclick="openModal('addWorkerModal')" class="btn btn-primary btn-sm">+ Register New Worker</button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p style="font-size: 0.88rem; color: var(--slate-600); margin-bottom: 20px;">
            The worker runs as a persistent service on Windows with Playwright controlling Chrome/Edge. It polls the queue via Bearer-authenticated REST calls and executes messaging tasks.
        </p>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Worker ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>WhatsApp Status</th>
                        <th>Last Heartbeat</th>
                        <th>Current Job</th>
                        <th>Environment</th>
                        <?php if ($isAdmin): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($workers)): ?>
                        <tr><td colspan="<?= $isAdmin ? 8 : 7 ?>" style="text-align: center; padding: 24px; color: var(--slate-500);">No workers registered yet. Click "+ Register New Worker" to connect your first worker.</td></tr>
                    <?php else: ?>
                        <?php foreach ($workers as $w): 
                            $isOnline = $w['status'] === 'online' && !empty($w['last_heartbeat_at']) && (strtotime($w['last_heartbeat_at']) >= time() - 60);
                            $waStatus = $w['whatsapp_status'];
                        ?>
                        <tr>
                            <td><code><?= htmlspecialchars($w['worker_uuid']) ?></code></td>
                            <td><strong><?= htmlspecialchars($w['name']) ?></strong></td>
                            <td>
                                <?php if ($w['status'] === 'disabled'): ?>
                                    <span class="badge badge-danger">DISABLED</span>
                                <?php elseif ($isOnline): ?>
                                    <span class="badge badge-success">ONLINE</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">OFFLINE</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($waStatus === 'connected'): ?>
                                    <span class="badge badge-success">CONNECTED</span>
                                <?php elseif ($waStatus === 'qr_ready'): ?>
                                    <span class="badge badge-warning">QR SCAN READY</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">DISCONNECTED</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.82rem; color: var(--slate-600);">
                                <?= $w['last_heartbeat_at'] ? htmlspecialchars($w['last_heartbeat_at']) . ' UTC' : 'Never' ?>
                            </td>
                            <td>
                                <?= $w['current_job_id'] ? "<span class='badge badge-primary'>Job #{$w['current_job_id']}</span>" : '<span style="color: var(--slate-400);">Idle</span>' ?>
                            </td>
                            <td style="font-size: 0.78rem; color: var(--slate-600);">
                                <?= htmlspecialchars($w['os_info'] ?? 'Windows') ?> | 
                                Py: <?= htmlspecialchars($w['python_version'] ?? '3.12') ?> | 
                                v<?= htmlspecialchars($w['worker_version'] ?? '1.0.0') ?>
                            </td>
                            <?php if ($isAdmin): ?>
                            <td>
                                <button class="btn btn-secondary btn-sm" onclick="regenerateToken(<?= $w['id'] ?>)">Regen Token</button>
                                <?php if ($w['status'] === 'disabled'): ?>
                                    <button class="btn btn-primary btn-sm" onclick="toggleWorkerStatus(<?= $w['id'] ?>, 'offline')">Enable</button>
                                <?php else: ?>
                                    <button class="btn btn-warning btn-sm" onclick="toggleWorkerStatus(<?= $w['id'] ?>, 'disabled')">Disable</button>
                                <?php endif; ?>
                                <button class="btn btn-danger btn-sm" onclick="deleteWorker(<?= $w['id'] ?>)">Delete</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Register Worker Modal -->
<div id="addWorkerModal" class="modal-backdrop">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Register Automation Worker</h3>
            <button type="button" class="modal-close" onclick="closeModal('addWorkerModal')">&times;</button>
        </div>
        <form id="addWorkerForm">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="workerName">Worker Friendly Name</label>
                    <input type="text" id="workerName" name="name" class="form-control" placeholder="e.g. Office-PC-Worker-01" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addWorkerModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Generate Token</button>
            </div>
        </form>
    </div>
</div>

<!-- Show Token Modal (Shown only once) -->
<div id="tokenModal" class="modal-backdrop">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Worker Bearer Token</h3>
            <button type="button" class="modal-close" onclick="closeModal('tokenModal'); location.reload();">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning">
                <strong>Important:</strong> Copy this Bearer token now. It is stored securely hashed on the server and will not be displayed again.
            </div>
            <div class="form-group">
                <label class="form-label">Bearer Token</label>
                <input type="text" id="plainTokenDisplay" class="form-control" readonly style="font-family: monospace; font-size: 0.85rem; background-color: #f1f5f9;">
            </div>
            <p style="font-size: 0.85rem; color: var(--slate-600);">
                Paste this token into <code>worker/config.ini</code> under <code>[worker] token = ...</code>.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="copyToken()">Copy Token</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('tokenModal'); location.reload();">Done</button>
        </div>
    </div>
</div>

<script>
document.getElementById('addWorkerForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const res = await apiRequest('../api/worker/register.php?action=create', 'POST', formData);
    if (res.success && res.token) {
        closeModal('addWorkerModal');
        document.getElementById('plainTokenDisplay').value = res.token;
        openModal('tokenModal');
    } else {
        alert(res.message || 'Failed to register worker.');
    }
});

async function regenerateToken(workerId) {
    if (!confirm("Regenerate token for this worker? The old token will be immediately revoked.")) return;
    const res = await apiRequest('../api/worker/register.php?action=regenerate_token', 'POST', { worker_id: workerId });
    if (res.success && res.token) {
        document.getElementById('plainTokenDisplay').value = res.token;
        openModal('tokenModal');
    } else {
        alert(res.message || 'Failed to regenerate token.');
    }
}

async function toggleWorkerStatus(workerId, newStatus) {
    const res = await apiRequest('../api/worker/register.php?action=toggle_status', 'POST', { worker_id: workerId, status: newStatus });
    if (res.success) location.reload();
    else alert(res.message || 'Failed to change worker status.');
}

async function deleteWorker(workerId) {
    if (!confirm("Are you sure you want to remove this worker registration?")) return;
    const res = await apiRequest('../api/worker/register.php?action=delete', 'POST', { worker_id: workerId });
    if (res.success) location.reload();
    else alert(res.message || 'Failed to delete worker.');
}

function copyToken() {
    const el = document.getElementById('plainTokenDisplay');
    el.select();
    navigator.clipboard.writeText(el.value);
    alert('Token copied to clipboard!');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
