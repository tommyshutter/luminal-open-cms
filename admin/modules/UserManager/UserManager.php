<?php
/**
 * Luminal CMS — User Manager Module
 *
 * User account management with role-based access control. Supports
 * superadmin, admin, and staff roles with permission matrix.
 *
 * @package    LuminalCMS
 * @module     UserManager
 * @version    1.0.0
 * @file       /admin/modules/UserManager/UserManager.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/auth.php';

requireAuth();

// Roster management is superadmin-only. Everyone below (admin, staff, …) is
// routed to their own self-service "My Account" panel instead of being bounced.
if (getUserRole() !== 'superadmin') {
    header('Location: /admin/modules/UserManager/my-account.php');
    exit;
}

$currentUser = getCurrentUser();
$userRole = getUserRole();
$hasLimited = hasLimitedAccess('user-mgr.php');

// Admin users can only manage staff (not other admins or superadmins)
$canManageAdmins = ($userRole === 'superadmin');

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">User Manager</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/UserManager/css/user-mgr.css') ?>">

    <div class="admin-container">
        <div class="page-header">
            <h1>User Management</h1>
            <button type="button" class="btn btn-primary" onclick="openAddModal()" id="addUserBtn">
                + Add User
            </button>
        </div>

        <nav class="user-mgr-tabs">
            <button class="tab-btn active" data-tab="users" onclick="switchTab('users')">Users</button>
            <?php if ($userRole === 'superadmin'): ?>
            <button class="tab-btn" data-tab="permissions" onclick="switchTab('permissions')">Access Privileges</button>
            <?php endif; ?>
        </nav>

        <div class="tab-content active" id="tab-users">
            <div class="content-card">
                <div class="user-list" id="userList">
                    <div class="loading">Loading users...</div>
                </div>
            </div>
        </div>

        <?php if ($userRole === 'superadmin'): ?>
        <div class="tab-content" id="tab-permissions">
            <div class="content-card">
                <div class="permissions-header">
                    <p class="help-text">Configure which menu items each role can access. Super Admin always has full access.</p>
                    <button type="button" class="btn btn-success" onclick="savePermissions()">Save Permissions</button>
                </div>
                <div class="permissions-matrix" id="permissionsMatrix">
                    <div class="loading">Loading permissions...</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal-overlay" id="userModal">
        <div class="modal">
            <div class="modal-header">
                <h2 id="modalTitle">Add User</h2>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="userForm" onsubmit="saveUser(event)">
                <input type="hidden" id="userId" name="id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="userEmail">Email Address *</label>
                        <input type="email" id="userEmail" name="email" required>
                    </div>

                    <div class="form-group">
                        <label for="userName">Username</label>
                        <input type="text" id="userName" name="username" placeholder="Optional - derived from email if blank">
                    </div>

                    <div class="form-group">
                        <label for="userDisplayName">Display Name *</label>
                        <input type="text" id="userDisplayName" name="display_name" required>
                    </div>

                    <div class="form-group">
                        <label for="userRole">Role *</label>
                        <select id="userRole" name="role" required>
                            <?php if ($canManageAdmins): ?>
                            <option value="superadmin">Super Admin</option>
                            <option value="admin">Standard Admin</option>
                            <?php endif; ?>
                            <option value="staff">Staff</option>
                        </select>
                    </div>

                    <div class="form-group password-group" id="passwordGroup">
                        <label for="userPassword">Password *</label>
                        <input type="password" id="userPassword" name="password">
                        <small class="help-text">Min 8 chars, include uppercase, lowercase, number, and special character.</small>
                    </div>

                    <div class="form-group password-group" id="confirmPasswordGroup">
                        <label for="userConfirmPassword">Confirm Password *</label>
                        <input type="password" id="userConfirmPassword" name="confirm_password">
                    </div>

                    <div class="form-group">
                        <label for="userStatus">Status</label>
                        <select id="userStatus" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <?php if ($canManageAdmins): ?>
                    <div class="form-group" id="platformGrantsGroup">
                        <label>Platform access <small class="help-text">(superadmin-grant only)</small></label>
                        <label class="um-grant-check">
                            <input type="checkbox" id="userPlatformAdmin" name="platform_admin" value="1">
                            <span>Platform Admin <small>— controls this site's <?php echo htmlspecialchars(platform_name(), ENT_QUOTES); ?> extension</small></span>
                        </label>
                        <label class="um-grant-check">
                            <input type="checkbox" id="userCmsAccess" name="cms_access" value="1" checked>
                            <span>CMS access <small>— can use the CMS admin (uncheck for a platform-only user)</small></span>
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="passwordModal">
        <div class="modal modal-sm">
            <div class="modal-header">
                <h2>Change Password</h2>
                <button type="button" class="modal-close" onclick="closePasswordModal()">&times;</button>
            </div>
            <form id="passwordForm" onsubmit="changePassword(event)">
                <input type="hidden" id="pwUserId" name="id">
                <div class="modal-body">
                    <p class="user-email-display" id="pwUserEmail"></p>

                    <div class="form-group">
                        <label for="newPassword">New Password *</label>
                        <input type="password" id="newPassword" name="new_password" required>
                    </div>

                    <div class="form-group">
                        <label for="confirmNewPassword">Confirm Password *</label>
                        <input type="password" id="confirmNewPassword" name="confirm_new_password" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closePasswordModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="deleteModal">
        <div class="modal modal-sm">
            <div class="modal-header">
                <h2>Delete User</h2>
                <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this user?</p>
                <p class="user-email-display" id="deleteUserEmail"></p>
                <p class="warning-text">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete User</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const currentUserId = '<?php echo htmlspecialchars($currentUser['id'] ?? '', ENT_QUOTES); ?>';
        const currentUserRole = '<?php echo htmlspecialchars($userRole, ENT_QUOTES); ?>';
        const canManageAdmins = <?php echo $canManageAdmins ? 'true' : 'false'; ?>;
    </script>
    <script src="<?= sc_asset('/admin/modules/UserManager/js/user-mgr-ops.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
