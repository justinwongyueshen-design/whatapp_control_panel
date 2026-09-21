<?php
$pageTitle = 'Contacts';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/db.php';

Auth::requireLogin();
$db = get_db();

// Fetch contact groups for filter and modals
$groups = $db->query("SELECT id, name FROM contact_groups ORDER BY name ASC")->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Manage Contacts</h3>
        <div style="display: flex; gap: 10px;">
            <a href="../api/contacts.php?action=export_csv" class="btn btn-secondary btn-sm">📥 Export CSV</a>
            <button onclick="openModal('csvImportModal')" class="btn btn-secondary btn-sm">📄 Import CSV</button>
            <button onclick="openAddContactModal()" class="btn btn-primary btn-sm">+ Add Contact</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Filter Bar -->
        <div class="filter-bar">
            <input type="text" id="searchInput" class="form-control" style="max-width: 260px;" placeholder="Search name, phone, company...">
            
            <select id="groupFilter" class="form-control" style="max-width: 180px;">
                <option value="">All Groups</option>
                <?php foreach ($groups as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="statusFilter" class="form-control" style="max-width: 140px;">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>

            <button onclick="loadContacts()" class="btn btn-primary btn-sm">Filter</button>
            <button onclick="resetFilters()" class="btn btn-secondary btn-sm">Reset</button>
        </div>

        <!-- Contacts Table -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Company</th>
                        <th>Groups</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="contactsTableBody">
                    <tr><td colspan="7" style="text-align: center; padding: 20px;">Loading contacts...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit Contact Modal -->
<div id="contactModal" class="modal-backdrop">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="contactModalTitle">Add Contact</h3>
            <button type="button" class="modal-close" onclick="closeModal('contactModal')">&times;</button>
        </div>
        <form id="contactForm">
            <div class="modal-body">
                <input type="hidden" id="contactId" name="id">

                <div class="form-group">
                    <label class="form-label" for="contactName">Full Name</label>
                    <input type="text" id="contactName" name="name" class="form-control" required placeholder="e.g. John Doe">
                </div>

                <div class="form-group">
                    <label class="form-label" for="contactPhone">Phone Number</label>
                    <input type="text" id="contactPhone" name="phone" class="form-control" required placeholder="e.g. 0123456789 or 60123456789">
                    <div class="form-help">Automatically normalized with default country code (+60).</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="contactCompany">Company / Organization</label>
                    <input type="text" id="contactCompany" name="company" class="form-control" placeholder="Optional company name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="contactStatus">Status</label>
                    <select id="contactStatus" name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Assign to Groups</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <?php foreach ($groups as $g): ?>
                            <label style="font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                                <input type="checkbox" name="group_ids[]" value="<?= $g['id'] ?>">
                                <?= htmlspecialchars($g['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('contactModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Contact</button>
            </div>
        </form>
    </div>
</div>

<!-- CSV Import Modal -->
<div id="csvImportModal" class="modal-backdrop">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Import Contacts from CSV</h3>
            <button type="button" class="modal-close" onclick="closeModal('csvImportModal')">&times;</button>
        </div>
        <form id="csvImportForm" enctype="multipart/form-data">
            <div class="modal-body">
                <p style="font-size: 0.85rem; color: var(--slate-600); margin-bottom: 14px;">
                    Upload a CSV file containing columns: <strong>name, phone, company</strong> (header row required). Numbers will be automatically normalized.
                </p>

                <div class="form-group">
                    <label class="form-label" for="csvFile">CSV File</label>
                    <input type="file" id="csvFile" name="csv_file" class="form-control" accept=".csv" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="targetGroupId">Assign to Group (Optional)</label>
                    <select id="targetGroupId" name="target_group_id" class="form-control">
                        <option value="">No Group</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="csvAlert" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('csvImportModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Import Contacts</button>
            </div>
        </form>
    </div>
</div>

<script>
async function loadContacts() {
    const search = document.getElementById('searchInput').value;
    const group = document.getElementById('groupFilter').value;
    const status = document.getElementById('statusFilter').value;

    const url = `../api/contacts.php?action=list&search=${encodeURIComponent(search)}&group_id=${encodeURIComponent(group)}&status=${encodeURIComponent(status)}`;
    const res = await apiRequest(url);

    const tbody = document.getElementById('contactsTableBody');
    if (!res.success || !res.data || res.data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 24px; color: var(--slate-500);">No contacts found matching criteria.</td></tr>';
        return;
    }

    tbody.innerHTML = res.data.map(c => `
        <tr>
            <td>#${c.id}</td>
            <td><strong>${escapeHtml(c.name)}</strong></td>
            <td><code>${escapeHtml(c.phone)}</code></td>
            <td>${escapeHtml(c.company || '—')}</td>
            <td><span style="font-size: 0.8rem; color: var(--slate-600);">${escapeHtml(c.group_names || 'None')}</span></td>
            <td>
                <span class="badge badge-${c.status === 'active' ? 'success' : 'secondary'}">
                    ${c.status.toUpperCase()}
                </span>
            </td>
            <td>
                <button class="btn btn-secondary btn-sm" onclick='editContact(${JSON.stringify(c)})'>Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteContact(${c.id})">Delete</button>
            </td>
        </tr>
    `).join('');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('groupFilter').value = '';
    document.getElementById('statusFilter').value = '';
    loadContacts();
}

function openAddContactModal() {
    document.getElementById('contactForm').reset();
    document.getElementById('contactId').value = '';
    document.getElementById('contactModalTitle').textContent = 'Add Contact';
    openModal('contactModal');
}

function editContact(c) {
    document.getElementById('contactId').value = c.id;
    document.getElementById('contactName').value = c.name;
    document.getElementById('contactPhone').value = c.phone;
    document.getElementById('contactCompany').value = c.company || '';
    document.getElementById('contactStatus').value = c.status;
    document.getElementById('contactModalTitle').textContent = 'Edit Contact #' + c.id;
    openModal('contactModal');
}

async function deleteContact(id) {
    if (!confirm(`Are you sure you want to delete contact #${id}?`)) return;
    const res = await apiRequest('../api/contacts.php?action=delete', 'POST', { id: id });
    if (res.success) {
        loadContacts();
    } else {
        alert(res.message || 'Failed to delete contact.');
    }
}

document.getElementById('contactForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const res = await apiRequest('../api/contacts.php?action=save', 'POST', formData);
    if (res.success) {
        closeModal('contactModal');
        loadContacts();
    } else {
        alert(res.message || 'Error saving contact');
    }
});

document.getElementById('csvImportForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const alertBox = document.getElementById('csvAlert');
    alertBox.style.display = 'none';

    const formData = new FormData(e.target);
    const res = await apiRequest('../api/contacts.php?action=import_csv', 'POST', formData);

    alertBox.style.display = 'block';
    if (res.success) {
        alertBox.className = 'alert alert-success';
        alertBox.textContent = res.message;
        setTimeout(() => {
            closeModal('csvImportModal');
            loadContacts();
        }, 1500);
    } else {
        alertBox.className = 'alert alert-danger';
        alertBox.textContent = res.message || 'Failed to import CSV.';
    }
});

document.addEventListener('DOMContentLoaded', loadContacts);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
