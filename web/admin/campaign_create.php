<?php
$pageTitle = 'Create Campaign';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/db.php';

Auth::requireLogin();
$db = get_db();

$groups = $db->query("SELECT g.id, g.name, COUNT(cgm.contact_id) as count FROM contact_groups g LEFT JOIN contact_group_members cgm ON g.id = cgm.group_id GROUP BY g.id ORDER BY g.name ASC")->fetchAll();
$templates = $db->query("SELECT id, title, content FROM message_templates ORDER BY title ASC")->fetchAll();
$contacts = $db->query("SELECT id, name, phone, company FROM contacts WHERE status = 'active' ORDER BY name ASC LIMIT 200")->fetchAll();
$totalActiveContacts = (int)$db->query("SELECT COUNT(*) FROM contacts WHERE status = 'active'")->fetchColumn();
?>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-header">
        <h3 class="card-title">Create Messaging Campaign</h3>
        <a href="campaigns.php" class="btn btn-secondary btn-sm">&larr; Back to Campaigns</a>
    </div>
    <div class="card-body">
        <div id="campaignAlert" style="display: none;"></div>

        <form id="createCampaignForm">
            <!-- Step 1: Basic Info -->
            <div class="form-group">
                <label class="form-label" for="campName">Campaign Name</label>
                <input type="text" id="campName" name="name" class="form-control" required placeholder="e.g. October Newsletter Broadcast">
            </div>

            <!-- Step 2: Target Audience -->
            <div class="form-group">
                <label class="form-label">Target Audience</label>
                <div style="display: flex; gap: 20px; margin-bottom: 10px;">
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 0.9rem; cursor: pointer;">
                        <input type="radio" name="audience_type" value="all" checked onchange="toggleAudience(this.value)">
                        All Active Contacts (<?= $totalActiveContacts ?> contacts)
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 0.9rem; cursor: pointer;">
                        <input type="radio" name="audience_type" value="group" onchange="toggleAudience(this.value)">
                        By Contact Group
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 0.9rem; cursor: pointer;">
                        <input type="radio" name="audience_type" value="selected" onchange="toggleAudience(this.value)">
                        Select Specific Contacts
                    </label>
                </div>

                <div id="groupSelectDiv" style="display: none; margin-top: 10px;">
                    <label class="form-label" for="campGroupId">Select Group</label>
                    <select id="campGroupId" name="group_id" class="form-control">
                        <option value="">-- Choose Group --</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?> (<?= $g['count'] ?> members)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="contactSelectDiv" style="display: none; margin-top: 10px;">
                    <label class="form-label">Choose Contacts</label>
                    <div style="max-height: 180px; overflow-y: auto; border: 1px solid var(--slate-300); border-radius: 8px; padding: 10px;">
                        <?php foreach ($contacts as $c): ?>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; padding: 4px 0;">
                                <input type="checkbox" name="contact_ids[]" value="<?= $c['id'] ?>">
                                <span><strong><?= htmlspecialchars($c['name']) ?></strong> (<?= htmlspecialchars($c['phone']) ?>) <?= $c['company'] ? '— ' . htmlspecialchars($c['company']) : '' ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Step 3: Message Content -->
            <div class="form-group">
                <label class="form-label">Message Content</label>
                <div style="margin-bottom: 8px;">
                    <select id="templateSelect" name="template_id" class="form-control" onchange="applyTemplate(this.value)">
                        <option value="">-- Write Custom Message OR Select Template --</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= $t['id'] ?>" data-content="<?= htmlspecialchars($t['content']) ?>"><?= htmlspecialchars($t['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <textarea id="customMessage" name="custom_message" class="form-control" rows="4" placeholder="Hello {{name}}, we are glad to connect with {{company}}..." required></textarea>
                <div class="form-help">Variables supported: <code>{{name}}</code>, <code>{{phone}}</code>, <code>{{company}}</code></div>
            </div>

            <!-- Step 4: Optional Media Attachment -->
            <div class="form-group">
                <label class="form-label" for="mediaFileInput">Attachment (Optional Image or Document)</label>
                <input type="file" id="mediaFileInput" class="form-control" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">
                <input type="hidden" id="uploadedMediaId" name="media_id">
                <div id="mediaUploadStatus" style="font-size: 0.8rem; margin-top: 4px;"></div>
            </div>

            <!-- Step 5: Scheduling -->
            <div class="form-group">
                <label class="form-label" for="scheduledAt">Schedule Delivery (Optional)</label>
                <input type="datetime-local" id="scheduledAt" name="scheduled_at" class="form-control" style="max-width: 300px;">
                <div class="form-help">Leave empty to queue immediately. Input time is in <strong><?= htmlspecialchars(APP_TIMEZONE) ?></strong>.</div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                <a href="campaigns.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" id="btnSubmitCampaign" class="btn btn-primary">Create & Queue Campaign</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAudience(type) {
    document.getElementById('groupSelectDiv').style.display = type === 'group' ? 'block' : 'none';
    document.getElementById('contactSelectDiv').style.display = type === 'selected' ? 'block' : 'none';
}

function applyTemplate(templateId) {
    const sel = document.getElementById('templateSelect');
    const opt = sel.options[sel.selectedIndex];
    const content = opt ? opt.getAttribute('data-content') : '';
    if (content) {
        document.getElementById('customMessage').value = content;
    }
}

// Handle media file upload immediately via AJAX
document.getElementById('mediaFileInput').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;

    const statusDiv = document.getElementById('mediaUploadStatus');
    statusDiv.innerHTML = '<span style="color: var(--info);">Uploading attachment...</span>';

    const formData = new FormData();
    formData.append('file', file);

    const res = await apiRequest('../api/media.php?action=upload', 'POST', formData);
    if (res.success && res.media) {
        document.getElementById('uploadedMediaId').value = res.media.id;
        statusDiv.innerHTML = `<span style="color: var(--primary);">✓ Attached: <strong>${escapeHtml(res.media.original_name)}</strong></span>`;
    } else {
        document.getElementById('uploadedMediaId').value = '';
        statusDiv.innerHTML = `<span style="color: var(--danger);">✗ Upload failed: ${escapeHtml(res.message)}</span>`;
    }
});

document.getElementById('createCampaignForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertDiv = document.getElementById('campaignAlert');
    alertDiv.style.display = 'none';

    // Confirmation for bulk sending
    if (!confirm("Confirm launching this campaign? Messages will be queued for authorized, consent-based delivery.")) {
        return;
    }

    const submitBtn = document.getElementById('btnSubmitCampaign');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Queueing campaign...';

    const formData = new FormData(e.target);
    const res = await apiRequest('../api/campaigns.php?action=create', 'POST', formData);

    alertDiv.style.display = 'block';
    if (res.success) {
        alertDiv.className = 'alert alert-success';
        alertDiv.textContent = res.message;
        setTimeout(() => {
            window.location.href = 'campaigns.php';
        }, 1200);
    } else {
        alertDiv.className = 'alert alert-danger';
        alertDiv.textContent = res.message || 'Failed to create campaign.';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create & Queue Campaign';
    }
});

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
