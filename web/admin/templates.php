<?php
$pageTitle = 'Message Templates';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

Auth::requireLogin();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Message Templates</h3>
        <button onclick="openAddTemplateModal()" class="btn btn-primary btn-sm">+ Create Template</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Preview Content</th>
                        <th>Supported Variables</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="templatesTableBody">
                    <tr><td colspan="5" style="text-align: center; padding: 20px;">Loading templates...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Template Modal -->
<div id="templateModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 650px;">
        <div class="modal-header">
            <h3 class="modal-title" id="templateModalTitle">Create Message Template</h3>
            <button type="button" class="modal-close" onclick="closeModal('templateModal')">&times;</button>
        </div>
        <form id="templateForm">
            <div class="modal-body">
                <input type="hidden" id="templateId" name="id">

                <div class="form-group">
                    <label class="form-label" for="tplTitle">Template Title</label>
                    <input type="text" id="tplTitle" name="title" class="form-control" required placeholder="e.g. Booking Confirmation">
                </div>

                <div class="form-group">
                    <label class="form-label" for="tplContent">Message Content</label>
                    <textarea id="tplContent" name="content" class="form-control" rows="5" required placeholder="Hello {{name}}, your appointment with {{company}} is confirmed for tomorrow."></textarea>
                    <div class="form-help">
                        Available variables: <code>{{name}}</code>, <code>{{phone}}</code>, <code>{{company}}</code>. Click to insert:
                        <span style="display: inline-block; margin-left: 6px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertVar('{{name}}')">+ {{name}}</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertVar('{{phone}}')">+ {{phone}}</button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="insertVar('{{company}}')">+ {{company}}</button>
                        </span>
                    </div>
                </div>

                <!-- Live Preview Section -->
                <div class="form-group" style="margin-top: 20px;">
                    <label class="form-label">Live Preview (Sample Recipient: John Doe / Acme Corp)</label>
                    <div id="livePreviewBox" style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 14px; font-size: 0.9rem; min-height: 60px; color: #334155;">
                        Type message above to preview rendered text...
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('templateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Template</button>
            </div>
        </form>
    </div>
</div>

<script>
async function loadTemplates() {
    const res = await apiRequest('../api/templates.php?action=list');
    const tbody = document.getElementById('templatesTableBody');
    if (!res.success || !res.data || res.data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 24px; color: var(--slate-500);">No templates created yet.</td></tr>';
        return;
    }

    tbody.innerHTML = res.data.map(t => {
        const snippet = t.content.length > 80 ? t.content.substring(0, 80) + '...' : t.content;
        return `
            <tr>
                <td>#${t.id}</td>
                <td><strong>${escapeHtml(t.title)}</strong></td>
                <td><code style="white-space: pre-wrap; font-size: 0.82rem;">${escapeHtml(snippet)}</code></td>
                <td><code>{{name}}, {{phone}}, {{company}}</code></td>
                <td>
                    <button class="btn btn-secondary btn-sm" onclick='editTemplate(${JSON.stringify(t)})'>Edit</button>
                    <button class="btn btn-danger btn-sm" onclick="deleteTemplate(${t.id})">Delete</button>
                </td>
            </tr>
        `;
    }).join('');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function updateLivePreview() {
    const content = document.getElementById('tplContent').value;
    const preview = content
        .replace(/\{\{name\}\}/g, 'John Doe')
        .replace(/\{\{phone\}\}/g, '60123456789')
        .replace(/\{\{company\}\}/g, 'Acme Corp');

    document.getElementById('livePreviewBox').innerHTML = escapeHtml(preview).replace(/\n/g, '<br>') || '<em>Empty template</em>';
}

document.getElementById('tplContent').addEventListener('input', updateLivePreview);

function insertVar(varName) {
    const textarea = document.getElementById('tplContent');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, start) + varName + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + varName.length;
    updateLivePreview();
}

function openAddTemplateModal() {
    document.getElementById('templateForm').reset();
    document.getElementById('templateId').value = '';
    document.getElementById('templateModalTitle').textContent = 'Create Message Template';
    updateLivePreview();
    openModal('templateModal');
}

function editTemplate(t) {
    document.getElementById('templateId').value = t.id;
    document.getElementById('tplTitle').value = t.title;
    document.getElementById('tplContent').value = t.content;
    document.getElementById('templateModalTitle').textContent = 'Edit Template #' + t.id;
    updateLivePreview();
    openModal('templateModal');
}

async function deleteTemplate(id) {
    if (!confirm(`Are you sure you want to delete template #${id}?`)) return;
    const res = await apiRequest('../api/templates.php?action=delete', 'POST', { id: id });
    if (res.success) {
        loadTemplates();
    } else {
        alert(res.message || 'Failed to delete template.');
    }
}

document.getElementById('templateForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const res = await apiRequest('../api/templates.php?action=save', 'POST', formData);
    if (res.success) {
        if (res.warning) {
            alert(res.warning);
        }
        closeModal('templateModal');
        loadTemplates();
    } else {
        alert(res.message || 'Error saving template.');
    }
});

document.addEventListener('DOMContentLoaded', loadTemplates);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
