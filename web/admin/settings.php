<?php
$pageTitle = 'System Settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/db.php';

Auth::requireRole('admin');
$db = get_db();

$stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-header">
        <h3 class="card-title">Operational & Rate-Limit Settings</h3>
    </div>
    <div class="card-body">
        <div id="settingsAlert" style="display: none;"></div>

        <form id="settingsForm">
            <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 14px; color: var(--slate-700);">⏱️ Message Pacing & Delays</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" for="minDelay">Min Delay Between Messages (seconds)</label>
                    <input type="number" id="minDelay" name="min_delay_seconds" class="form-control" value="<?= htmlspecialchars($settings['min_delay_seconds'] ?? '5') ?>" min="1" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="maxDelay">Max Delay Between Messages (seconds)</label>
                    <input type="number" id="maxDelay" name="max_delay_seconds" class="form-control" value="<?= htmlspecialchars($settings['max_delay_seconds'] ?? '15') ?>" min="1" required>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" for="batchSize">Max Messages Per Batch</label>
                    <input type="number" id="batchSize" name="max_messages_per_batch" class="form-control" value="<?= htmlspecialchars($settings['max_messages_per_batch'] ?? '20') ?>" min="1" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="batchPause">Pause Between Batches (seconds)</label>
                    <input type="number" id="batchPause" name="pause_between_batches_seconds" class="form-control" value="<?= htmlspecialchars($settings['pause_between_batches_seconds'] ?? '60') ?>" min="5" required>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid var(--slate-200); margin: 24px 0;">

            <h4 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 14px; color: var(--slate-700);">🛡️ Safety Ceilings & Retries</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" for="maxHour">Max Messages Per Hour</label>
                    <input type="number" id="maxHour" name="max_messages_per_hour" class="form-control" value="<?= htmlspecialchars($settings['max_messages_per_hour'] ?? '100') ?>" min="1" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="maxDay">Max Messages Per Day</label>
                    <input type="number" id="maxDay" name="max_messages_per_day" class="form-control" value="<?= htmlspecialchars($settings['max_messages_per_day'] ?? '500') ?>" min="1" required>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" for="maxRetries">Max Delivery Retries</label>
                    <input type="number" id="maxRetries" name="max_retry_attempts" class="form-control" value="<?= htmlspecialchars($settings['max_retry_attempts'] ?? '3') ?>" min="1" max="10" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="defaultCountryCode">Default Calling Code</label>
                    <input type="text" id="defaultCountryCode" name="default_country_code" class="form-control" value="<?= htmlspecialchars($settings['default_country_code'] ?? '60') ?>" required>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" for="hbTimeout">Worker Heartbeat Timeout (seconds)</label>
                    <input type="number" id="hbTimeout" name="worker_heartbeat_timeout_seconds" class="form-control" value="<?= htmlspecialchars($settings['worker_heartbeat_timeout_seconds'] ?? '60') ?>" min="15" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="staleTimeout">Stale Job Recovery Timeout (seconds)</label>
                    <input type="number" id="staleTimeout" name="stale_job_timeout_seconds" class="form-control" value="<?= htmlspecialchars($settings['stale_job_timeout_seconds'] ?? '300') ?>" min="60" required>
                </div>
            </div>

            <div style="margin-top: 24px; text-align: right;">
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertDiv = document.getElementById('settingsAlert');
    alertDiv.style.display = 'none';

    const formData = new FormData(e.target);
    const res = await apiRequest('../api/settings.php?action=save', 'POST', formData);

    alertDiv.style.display = 'block';
    if (res.success) {
        alertDiv.className = 'alert alert-success';
        alertDiv.textContent = res.message;
        setTimeout(() => alertDiv.style.display = 'none', 3000);
    } else {
        alertDiv.className = 'alert alert-danger';
        alertDiv.textContent = res.message || 'Failed to save settings.';
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
