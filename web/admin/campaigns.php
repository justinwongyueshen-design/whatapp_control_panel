<?php
$pageTitle = 'Campaigns';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

Auth::requireLogin();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Campaigns Overview</h3>
        <a href="campaign_create.php" class="btn btn-primary btn-sm">+ New Campaign</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Campaign Name</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Statistics</th>
                        <th>Schedule</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="campaignsTableBody">
                    <tr><td colspan="8" style="text-align: center; padding: 24px;">Loading campaigns...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
async function loadCampaigns() {
    const res = await apiRequest('../api/campaigns.php?action=list');
    const tbody = document.getElementById('campaignsTableBody');
    if (!res.success || !res.data || res.data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 24px; color: var(--slate-500);">No campaigns created yet. Click "+ New Campaign" to get started.</td></tr>';
        return;
    }

    tbody.innerHTML = res.data.map(c => {
        const total = parseInt(c.total_jobs) || 0;
        const sent = parseInt(c.sent_jobs) || 0;
        const failed = parseInt(c.failed_jobs) || 0;
        const pending = parseInt(c.pending_jobs) || 0;
        const processing = parseInt(c.processing_jobs) || 0;
        const pct = total > 0 ? Math.round((sent / total) * 100) : 0;

        let statusClass = 'secondary';
        if (c.status === 'running') statusClass = 'primary';
        else if (c.status === 'completed') statusClass = 'success';
        else if (c.status === 'paused') statusClass = 'warning';
        else if (c.status === 'cancelled') statusClass = 'danger';

        let actionBtns = '';
        if (c.status === 'running' || c.status === 'queued') {
            actionBtns += `<button class="btn btn-secondary btn-sm" onclick="pauseCampaign(${c.id})">Pause</button> `;
        } else if (c.status === 'paused') {
            actionBtns += `<button class="btn btn-primary btn-sm" onclick="resumeCampaign(${c.id})">Resume</button> `;
        }

        if (c.status !== 'completed' && c.status !== 'cancelled') {
            actionBtns += `<button class="btn btn-danger btn-sm" onclick="cancelCampaign(${c.id})">Cancel</button>`;
        }

        return `
            <tr>
                <td>#${c.id}</td>
                <td>
                    <strong>${escapeHtml(c.name)}</strong>
                    ${c.media_name ? `<br><span style="font-size: 0.75rem; color: var(--primary);">📎 ${escapeHtml(c.media_name)}</span>` : ''}
                </td>
                <td><span class="badge badge-${statusClass}">${c.status.toUpperCase()}</span></td>
                <td style="min-width: 140px;">
                    <div class="progress" style="margin-bottom: 4px;">
                        <div class="progress-bar" style="width: ${pct}%;"></div>
                    </div>
                    <span style="font-size: 0.75rem; color: var(--slate-500);">${sent} / ${total} (${pct}%)</span>
                </td>
                <td style="font-size: 0.8rem;">
                    <span style="color: #15803d;">✓ ${sent} sent</span> | 
                    <span style="color: #b91c1c;">✗ ${failed} fail</span><br>
                    <span style="color: #64748b;">⏳ ${pending} pend | ⚡ ${processing} proc</span>
                </td>
                <td style="font-size: 0.82rem; color: var(--slate-600);">${c.scheduled_at ? escapeHtml(c.scheduled_at) : 'Immediate'}</td>
                <td style="font-size: 0.82rem; color: var(--slate-500);">${escapeHtml(c.created_at)}</td>
                <td>${actionBtns || '<span style="color: var(--slate-400);">—</span>'}</td>
            </tr>
        `;
    }).join('');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

async function pauseCampaign(id) {
    const res = await apiRequest('../api/campaigns.php?action=pause', 'POST', { id: id });
    if (res.success) loadCampaigns();
    else alert(res.message || 'Could not pause campaign');
}

async function resumeCampaign(id) {
    const res = await apiRequest('../api/campaigns.php?action=resume', 'POST', { id: id });
    if (res.success) loadCampaigns();
    else alert(res.message || 'Could not resume campaign');
}

async function cancelCampaign(id) {
    if (!confirm(`Cancel campaign #${id}? Remaining unsent messages will be cancelled immediately.`)) return;
    const res = await apiRequest('../api/campaigns.php?action=cancel', 'POST', { id: id });
    if (res.success) loadCampaigns();
    else alert(res.message || 'Could not cancel campaign');
}

document.addEventListener('DOMContentLoaded', () => {
    loadCampaigns();
    setInterval(loadCampaigns, 8000); // Poll campaign status
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
