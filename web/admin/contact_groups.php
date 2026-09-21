<?php
$pageTitle = 'Contact Groups';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

Auth::requireLogin();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Contact Groups</h3>
        <button onclick="openAddGroupModal()" class="btn btn-primary btn-sm">+ Create Group</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Group Name</th>
                        <th>Description</th>
                        <th>Members Count</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="groupsTableBody">
                    <tr><td colspan="5" style="text-align: center; padding: 20px;">Loading groups...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Group Modal -->
<div id="groupModal" class="modal-backdrop">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="groupModalTitle">Create Contact Group</h3>
            <button type="button" class="modal-close" onclick="closeModal('groupModal')">&times;</button>
        </div>
        <form id="groupForm">
            <div class="modal-body">
                <input type="hidden" id="groupId" name="id">

                <div class="form-group">
                    <label class="form-label" for="groupName">Group Name</label>
                    <input type="text" id="groupName" name="name" class="form-control" required placeholder="e.g. VIP Customers">
                </div>

                <div class="form-group">
                    <label class="form-label" for="groupDesc">Description</label>
                    <textarea id="groupDesc" name="description" class="form-control" rows="3" placeholder="Optional notes regarding this audience"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('groupModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Group</button>
            </div>
        </form>
    </div>
</div>

<script>
async function loadGroups() {
    const res = await apiRequest('../api/contact_groups.php?action=list');
    const tbody = document.getElementById('groupsTableBody');
    if (!res.success || !res.data || res.data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 24px; color: var(--slate-500);">No contact groups defined yet.</td></tr>';
        return;
    }

    tbody.innerHTML = res.data.map(g => `
        <tr>
            <td>#${g.id}</td>
            <td><strong>${escapeHtml(g.name)}</strong></td>
            <td>${escapeHtml(g.description || '—')}</td>
            <td><span class="badge badge-info">${g.member_count} contacts</span></td>
            <td>
                <button class="btn btn-secondary btn-sm" onclick='editGroup(${JSON.stringify(g)})'>Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteGroup(${g.id})">Delete</button>
            </td>
        </tr>
    `).join('');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function openAddGroupModal() {
    document.getElementById('groupForm').reset();
    document.getElementById('groupId').value = '';
    document.getElementById('groupModalTitle').textContent = 'Create Contact Group';
    openModal('groupModal');
}

function editGroup(g) {
    document.getElementById('groupId').value = g.id;
    document.getElementById('groupName').value = g.name;
    document.getElementById('groupDesc').value = g.description || '';
    document.getElementById('groupModalTitle').textContent = 'Edit Group #' + g.id;
    openModal('groupModal');
}

async function deleteGroup(id) {
    if (!confirm(`Are you sure you want to delete group #${id}? Contacts in this group will remain intact.`)) return;
    const res = await apiRequest('../api/contact_groups.php?action=delete', 'POST', { id: id });
    if (res.success) {
        loadGroups();
    } else {
        alert(res.message || 'Failed to delete group.');
    }
}

document.getElementById('groupForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const res = await apiRequest('../api/contact_groups.php?action=save', 'POST', formData);
    if (res.success) {
        closeModal('groupModal');
        loadGroups();
    } else {
        alert(res.message || 'Error saving group.');
    }
});

document.addEventListener('DOMContentLoaded', loadGroups);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
