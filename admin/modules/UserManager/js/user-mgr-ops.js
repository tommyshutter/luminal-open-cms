/**
 * Luminal CMS — User Manager Module JS
 *
 * @package    LuminalCMS
 * @module     UserManager
 * @file       /admin/modules/UserManager/js/user-mgr-ops.js
 */

const API_URL = '/admin/modules/UserManager/api.php';

// State
let users = [];
let editingUserId = null;
let deleteUserId = null;

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    // Move modals to document.body so they escape .content-area's
    // backdrop-filter stacking context (which traps position:fixed children)
    ['userModal', 'passwordModal', 'deleteModal'].forEach(id => {
        const el = document.getElementById(id);
        if (el) document.body.appendChild(el);
    });

    loadUsers();

    // Close dropdowns when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });
});

/**
 * Load all users from API
 */
async function loadUsers() {
    const userList = document.getElementById('userList');
    userList.innerHTML = '<div class="loading">Loading users...</div>';

    try {
        const response = await fetch(`${API_URL}?action=list`);
        const data = await response.json();

        if (data.success) {
            users = data.users;
            renderUserList();
        } else {
            userList.innerHTML = `<div class="loading">${data.error || 'Failed to load users'}</div>`;
        }
    } catch (error) {
        console.error('Error loading users:', error);
        userList.innerHTML = '<div class="loading">Error loading users. Please refresh the page.</div>';
    }
}

/**
 * Render the user list
 */
function renderUserList() {
    const userList = document.getElementById('userList');

    if (users.length === 0) {
        userList.innerHTML = `
            <div class="empty-state">
                <h3>No Users Found</h3>
                <p>Create your first user to get started.</p>
                <button class="btn btn-primary" onclick="openAddModal()">+ Add User</button>
            </div>
        `;
        return;
    }

    userList.innerHTML = users.map(user => {
        const initials = getInitials(user.display_name || user.email);
        const lastLogin = user.last_login ? formatDate(user.last_login) : 'Never';
        const isSelf = user.id === currentUserId;

        return `
            <div class="user-card ${user.status !== 'active' ? 'inactive' : ''}" data-id="${user.id}">
                <div class="user-avatar">${initials}</div>
                <div class="user-info">
                    <div class="user-email">${escapeHtml(user.email)}${isSelf ? ' (You)' : ''}</div>
                    <div class="user-meta">
                        <span class="role-badge ${user.role}">${formatRole(user.role)}</span>
                        <span class="separator">&#8226;</span>
                        <span class="status-badge ${user.status}">${user.status}</span>
                        <span class="separator">&#8226;</span>
                        <span>Last login: ${lastLogin}</span>
                    </div>
                </div>
                <div class="user-actions">
                    <button class="btn btn-secondary btn-sm" onclick="openEditModal('${user.id}')">Edit</button>
                    <button class="btn btn-secondary btn-sm" onclick="openPasswordModal('${user.id}', '${escapeHtml(user.email)}')">Password</button>
                    <div class="dropdown">
                        <button class="dropdown-toggle" onclick="toggleDropdown(this)">&#8942;</button>
                        <div class="dropdown-menu">
                            <button onclick="toggleUserStatus('${user.id}')">${user.status === 'active' ? 'Deactivate' : 'Activate'}</button>
                            <button onclick="resetUserPassword('${user.id}')">Reset Password</button>
                            ${!isSelf ? `<div class="divider"></div><button class="danger" onclick="openDeleteModal('${user.id}', '${escapeHtml(user.email)}')">Delete</button>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Toggle dropdown menu
 */
function toggleDropdown(button) {
    const menu = button.nextElementSibling;
    const isOpen = menu.classList.contains('show');

    // Close all dropdowns
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));

    // Toggle this one
    if (!isOpen) {
        menu.classList.add('show');
    }
}

/**
 * Open add user modal
 */
function openAddModal() {
    editingUserId = null;
    document.getElementById('modalTitle').textContent = 'Add User';
    document.getElementById('userForm').reset();
    document.getElementById('userId').value = '';
    document.getElementById('passwordGroup').style.display = 'block';
    document.getElementById('confirmPasswordGroup').style.display = 'block';
    document.getElementById('userPassword').required = true;
    document.getElementById('userConfirmPassword').required = true;
    document.getElementById('userModal').classList.add('show');
}

/**
 * Open edit user modal
 */
async function openEditModal(id) {
    editingUserId = id;
    document.getElementById('modalTitle').textContent = 'Edit User';

    try {
        const response = await fetch(`${API_URL}?action=get&id=${id}`);
        const data = await response.json();

        if (data.success) {
            const user = data.user;
            document.getElementById('userId').value = user.id;
            document.getElementById('userEmail').value = user.email;
            document.getElementById('userName').value = user.username || '';
            document.getElementById('userDisplayName').value = user.display_name;
            document.getElementById('userRole').value = user.role;
            document.getElementById('userStatus').value = user.status;

            // Platform-admin grants (superadmin-only — elements absent otherwise).
            const pa = document.getElementById('userPlatformAdmin');
            const ca = document.getElementById('userCmsAccess');
            if (pa) pa.checked = !!user.platform_admin;
            if (ca) ca.checked = (user.cms_access !== false); // absent = CMS access on

            // Hide password fields for edit
            document.getElementById('passwordGroup').style.display = 'none';
            document.getElementById('confirmPasswordGroup').style.display = 'none';
            document.getElementById('userPassword').required = false;
            document.getElementById('userConfirmPassword').required = false;

            document.getElementById('userModal').classList.add('show');
        } else {
            showToast(data.error || 'Failed to load user', 'error');
        }
    } catch (error) {
        console.error('Error loading user:', error);
        showToast('Error loading user data', 'error');
    }
}

/**
 * Close user modal
 */
function closeModal() {
    document.getElementById('userModal').classList.remove('show');
    editingUserId = null;
}

/**
 * Save user (create or update)
 */
async function saveUser(event) {
    event.preventDefault();

    const form = document.getElementById('userForm');
    const formData = new FormData(form);
    formData.append('action', editingUserId ? 'update' : 'create');

    // Validate password match for new users
    if (!editingUserId) {
        const password = formData.get('password');
        const confirmPassword = formData.get('confirm_password');

        if (password !== confirmPassword) {
            showToast('Passwords do not match', 'error');
            return;
        }
    }

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            showToast(data.message || 'User saved successfully', 'success');
            closeModal();
            loadUsers();
        } else {
            showToast(data.error || 'Failed to save user', 'error');
        }
    } catch (error) {
        console.error('Error saving user:', error);
        showToast('Error saving user', 'error');
    }
}

/**
 * Open password change modal
 */
function openPasswordModal(id, email) {
    document.getElementById('pwUserId').value = id;
    document.getElementById('pwUserEmail').textContent = email;
    document.getElementById('passwordForm').reset();
    document.getElementById('passwordModal').classList.add('show');
}

/**
 * Close password modal
 */
function closePasswordModal() {
    document.getElementById('passwordModal').classList.remove('show');
}

/**
 * Change user password
 */
async function changePassword(event) {
    event.preventDefault();

    const form = document.getElementById('passwordForm');
    const formData = new FormData(form);

    const newPassword = formData.get('new_password');
    const confirmPassword = formData.get('confirm_new_password');

    if (newPassword !== confirmPassword) {
        showToast('Passwords do not match', 'error');
        return;
    }

    formData.append('action', 'change_pass');

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            showToast(data.message || 'Password changed successfully', 'success');
            closePasswordModal();
        } else {
            showToast(data.error || 'Failed to change password', 'error');
        }
    } catch (error) {
        console.error('Error changing password:', error);
        showToast('Error changing password', 'error');
    }
}

/**
 * Open delete confirmation modal
 */
function openDeleteModal(id, email) {
    deleteUserId = id;
    document.getElementById('deleteUserEmail').textContent = email;
    document.getElementById('confirmDeleteBtn').onclick = () => deleteUser(id);
    document.getElementById('deleteModal').classList.add('show');
}

/**
 * Close delete modal
 */
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
    deleteUserId = null;
}

/**
 * Delete user
 */
async function deleteUser(id) {
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            showToast(data.message || 'User deleted successfully', 'success');
            closeDeleteModal();
            loadUsers();
        } else {
            showToast(data.error || 'Failed to delete user', 'error');
        }
    } catch (error) {
        console.error('Error deleting user:', error);
        showToast('Error deleting user', 'error');
    }
}

/**
 * Toggle user status (active/inactive)
 */
async function toggleUserStatus(id) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            showToast(data.message || 'Status updated', 'success');
            loadUsers();
        } else {
            showToast(data.error || 'Failed to update status', 'error');
        }
    } catch (error) {
        console.error('Error toggling status:', error);
        showToast('Error updating status', 'error');
    }
}

/**
 * Reset user password (generate temp password)
 */
async function resetUserPassword(id) {
    if (!confirm('This will generate a new temporary password for the user. Continue?')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'reset_pass');
    formData.append('id', id);

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Show temporary password in a special way
            const tempPwd = data.temp_password;
            alert(`Temporary password generated:\n\n${tempPwd}\n\nPlease share this securely with the user. They should change it after logging in.`);
            showToast('Password reset successfully', 'success');
        } else {
            showToast(data.error || 'Failed to reset password', 'error');
        }
    } catch (error) {
        console.error('Error resetting password:', error);
        showToast('Error resetting password', 'error');
    }
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast ${type} show`;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 4000);
}

/**
 * Get initials from name
 */
function getInitials(name) {
    return name
        .split(/[\s@]+/)
        .slice(0, 2)
        .map(part => part.charAt(0).toUpperCase())
        .join('');
}

/**
 * Format role for display
 */
function formatRole(role) {
    const roles = {
        superadmin: 'Super Admin',
        admin: 'Standard Admin',
        staff: 'Staff'
    };
    return roles[role] || role;
}

/**
 * Format date for display
 */
function formatDate(dateStr) {
    if (!dateStr) return 'Never';

    const date = new Date(dateStr);
    const now = new Date();
    const diff = now - date;

    // Less than 1 hour
    if (diff < 3600000) {
        const mins = Math.floor(diff / 60000);
        return mins <= 1 ? 'Just now' : `${mins}m ago`;
    }

    // Less than 24 hours
    if (diff < 86400000) {
        const hours = Math.floor(diff / 3600000);
        return `${hours}h ago`;
    }

    // Less than 7 days
    if (diff < 604800000) {
        const days = Math.floor(diff / 86400000);
        return `${days}d ago`;
    }

    // Otherwise show date
    return date.toLocaleDateString();
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ============================================================================
// PERMISSIONS TAB
// ============================================================================

/**
 * Switch between tabs
 */
function switchTab(tabName) {
    // Update tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabName);
    });

    // Update tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.toggle('active', content.id === `tab-${tabName}`);
    });

    // Update add user button visibility (only show on users tab)
    const addUserBtn = document.getElementById('addUserBtn');
    if (addUserBtn) {
        addUserBtn.style.display = tabName === 'users' ? 'inline-flex' : 'none';
    }

    // Load permissions when switching to permissions tab
    if (tabName === 'permissions') {
        loadPermissions();
    }
}

/**
 * Load permissions from API
 */
async function loadPermissions() {
    const matrix = document.getElementById('permissionsMatrix');
    matrix.innerHTML = '<div class="loading">Loading permissions...</div>';

    try {
        const response = await fetch(`${API_URL}?action=get_permissions`);
        const data = await response.json();

        if (data.success) {
            renderPermissionsMatrix(data.permissions, data.menu_structure);
        } else {
            matrix.innerHTML = `<div class="loading">${data.error || 'Failed to load permissions'}</div>`;
        }
    } catch (error) {
        console.error('Error loading permissions:', error);
        matrix.innerHTML = '<div class="loading">Error loading permissions. Please refresh the page.</div>';
    }
}

/**
 * Render permissions matrix table
 */
function renderPermissionsMatrix(permissions, menuStructure) {
    const matrix = document.getElementById('permissionsMatrix');

    const roles = ['superadmin', 'admin', 'staff'];
    const roleLabels = {
        superadmin: 'Super Admin',
        admin: 'Standard Admin',
        staff: 'Staff'
    };

    let html = '<table class="permissions-table">';

    // Header
    html += '<thead><tr><th>Menu Item</th>';
    roles.forEach(role => {
        html += `<th>${roleLabels[role]}</th>`;
    });
    html += '</tr></thead><tbody>';

    // Top-level menu items
    if (menuStructure.top) {
        menuStructure.top.forEach(item => {
            html += renderPermissionRow(item, roles, permissions, false);
        });
    }

    // Sections with items
    if (menuStructure.sections) {
        menuStructure.sections.forEach(section => {
            // Section header
            html += `<tr class="section-header">`;
            html += `<th colspan="${roles.length + 1}">${escapeHtml(section.title)}</th>`;
            html += `</tr>`;

            // Section items
            section.items.forEach(item => {
                html += renderPermissionRow(item, roles, permissions, true);
            });
        });
    }

    html += '</tbody></table>';
    matrix.innerHTML = html;
}

/**
 * Render a single permission row
 */
function renderPermissionRow(item, roles, permissions, indent) {
    const key = item.key;
    const label = escapeHtml(item.label);
    const className = indent ? 'menu-item-indent' : '';

    let html = `<tr><th class="${className}">${label}</th>`;

    roles.forEach(role => {
        const isChecked = isPermissionGranted(permissions[role], key);
        const disabled = role === 'superadmin' ? ' disabled' : '';
        const checked = isChecked ? ' checked' : '';

        html += `<td>`;
        html += `<input type="checkbox" ${checked}${disabled} `;
        html += `data-role="${role}" data-key="${key}" `;
        html += `onchange="togglePermission('${role}', '${key}', this.checked)">`;
        html += `</td>`;
    });

    html += '</tr>';
    return html;
}

/**
 * Check if a permission is granted
 */
function isPermissionGranted(perms, key) {
    if (!perms) return false;
    if (perms.includes('*')) return true;

    // Check for exact match or limited access
    return perms.some(perm => {
        const permKey = perm.split(':')[0];
        return permKey === key;
    });
}

/**
 * Toggle a single permission (update UI state)
 */
function togglePermission(role, key, checked) {
    // Just update the checkbox state - actual save happens when user clicks Save
}

/**
 * Save all permissions to API
 */
async function savePermissions() {
    const checkboxes = document.querySelectorAll('.permissions-table input[type="checkbox"]:not(:disabled)');

    // Build permissions object
    const permissions = {
        superadmin: ['*'], // Always wildcard
        admin: [],
        staff: []
    };

    checkboxes.forEach(cb => {
        const role = cb.dataset.role;
        const key = cb.dataset.key;

        if (cb.checked && role !== 'superadmin') {
            permissions[role].push(key);
        }
    });

    // Save to API
    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_permissions',
                permissions: permissions
            })
        });

        const data = await response.json();

        if (data.success) {
            showToast('Permissions saved successfully', 'success');
        } else {
            showToast(data.error || 'Failed to save permissions', 'error');
        }
    } catch (error) {
        console.error('Error saving permissions:', error);
        showToast('Error saving permissions', 'error');
    }
}
