<?php
$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

Auth::requireRole('admin');
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">System Users & Roles</h3>
        <button onclick="openAddUserModal()" class="btn btn-primary btn-sm">+ Create User</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    <tr><td colspan="7" style="text-align: center; padding: 20px;">Loading users...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- User Modal -->
<div id="userModal" class="modal-backdrop">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="userModalTitle">Create User</h3>
            <button type="button" class="modal-close" onclick="closeModal('userModal')">&times;</button>
        </div>
        <form id="userForm">
            <div class="modal-body">
                <input type="hidden" id="userId" name="id">

                <div class="form-group">
                    <label class="form-label" for="uUsername">Username</label>
                    <input type="text" id="uUsername" name="username" class="form-control" required placeholder="e.g. operator1">
                </div>

                <div class="form-group">
                    <label class="form-label" for="uFullName">Full Name</label>
                    <input type="text" id="uFullName" name="full_name" class="form-control" required placeholder="e.g. Jane Doe">
                </div>

                <div class="form-group">
                    <label class="form-label" for="uPassword">Password</label>
                    <input type="password" id="uPassword" name="password" class="form-control" placeholder="Leave empty to keep existing password">
                </div>

                <div class="form-group">
                    <label class="form-label" for="uRole">Role</label>
                    <select id="uRole" name="role" class="form-control">
                        <option value="operator">Operator (Manage contacts, templates, campaigns)</option>
                        <option value="admin">Administrator (Full system control, workers, settings)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="uStatus">Account Status</label>
                    <select id="uStatus" name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('userModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save User</button>
            </div>
        </form>
    </div>
</div>

<script>
async function loadUsers() {
    const res = await apiRequest('../api/users.php?action=list');
    const tbody = document.getElementById('usersTableBody');
    if (!res.success || !res.data) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--danger);">Failed to load users.</td></tr>';
        return;
    }

    tbody.innerHTML = res.data.map(u => `
        <tr>
            <td>#${u.id}</td>
            <td><strong>${escapeHtml(u.username)}</strong></td>
            <td>${escapeHtml(u.full_name)}</td>
            <td>
                <span class="badge badge-${u.role === 'admin' ? 'primary' : 'secondary'}">
                    ${u.role.toUpperCase()}
                </span>
            </td>
            <td>
                <span class="badge badge-${u.status === 'active' ? 'success' : 'danger'}">
                    ${u.status.toUpperCase()}
                </span>
            </td>
            <td style="font-size: 0.8rem; color: var(--slate-600);">${u.last_login_at ? escapeHtml(u.last_login_at) : 'Never'}</td>
            <td>
                <button class="btn btn-secondary btn-sm" onclick='editUser(${JSON.stringify(u)})'>Edit</button>
                ${u.id !== <?= (int)$_SESSION['user_id'] ?> ? `<button class="btn btn-danger btn-sm" onclick="deleteUser(${u.id})">Delete</button>` : ''}
            </td>
        </tr>
    `).join('');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function openAddUserModal() {
    document.getElementById('userForm').reset();
    document.getElementById('userId').value = '';
    document.getElementById('uUsername').readOnly = false;
    document.getElementById('uPassword').required = true;
    document.getElementById('userModalTitle').textContent = 'Create User';
    openModal('userModal');
}

function editUser(u) {
    document.getElementById('userId').value = u.id;
    document.getElementById('uUsername').value = u.username;
    document.getElementById('uUsername').readOnly = true;
    document.getElementById('uFullName').value = u.full_name;
    document.getElementById('uRole').value = u.role;
    document.getElementById('uStatus').value = u.status;
    document.getElementById('uPassword').value = '';
    document.getElementById('uPassword').required = false;
    document.getElementById('userModalTitle').textContent = 'Edit User #' + u.id;
    openModal('userModal');
}

async function deleteUser(id) {
    if (!confirm(`Are you sure you want to delete user #${id}?`)) return;
    const res = await apiRequest('../api/users.php?action=delete', 'POST', { id: id });
    if (res.success) loadUsers();
    else alert(res.message || 'Could not delete user.');
}

document.getElementById('userForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const res = await apiRequest('../api/users.php?action=save', 'POST', formData);
    if (res.success) {
        closeModal('userModal');
        loadUsers();
    } else {
        alert(res.message || 'Error saving user.');
    }
});

document.addEventListener('DOMContentLoaded', loadUsers);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
