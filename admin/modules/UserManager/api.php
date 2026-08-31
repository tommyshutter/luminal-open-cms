<?php
/**
 * Luminal CMS — User Manager API
 *
 * AJAX handler for user CRUD operations, password management,
 * and access permission configuration.
 *
 * @package    LuminalCMS
 * @module     UserManager
 * @version    1.0.0
 * @file       /admin/modules/UserManager/api.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

header('Content-Type: application/json');

require_once SITE_ROOT . '/admin/auth.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check permission
if (!hasRole('admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$currentUser = getCurrentUser();
$currentRole = getUserRole();
$canManageAdmins = ($currentRole === 'superadmin');

try {
    switch ($action) {
        case 'list':
            um_handleList();
            break;

        case 'get':
            um_handleGet();
            break;

        case 'create':
            um_handleCreate();
            break;

        case 'update':
            um_handleUpdate();
            break;

        case 'delete':
            um_handleDelete();
            break;

        case 'change_pass':
            um_handleChangePassword();
            break;

        case 'toggle':
            um_handleToggleStatus();
            break;

        case 'reset_pass':
            um_handleResetPassword();
            break;

        case 'get_permissions':
            um_handleGetPermissions();
            break;

        case 'save_permissions':
            um_handleSavePermissions();
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * List all users (filtered by role permissions)
 */
function um_handleList() {
    global $currentRole, $canManageAdmins;

    $data = loadUsersData();
    $users = [];

    foreach ($data['users'] as $user) {
        // Admin users can only see staff
        if (!$canManageAdmins && in_array($user['role'], ['superadmin', 'admin'])) {
            continue;
        }

        // Remove sensitive data
        $users[] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'username' => $user['username'] ?? '',
            'display_name' => $user['display_name'],
            'role' => $user['role'],
            'status' => $user['status'],
            'last_login' => $user['last_login'],
            'created_at' => $user['created_at']
        ];
    }

    // Sort by role level then by display name
    usort($users, function($a, $b) {
        $roleOrder = ['superadmin' => 1, 'admin' => 2, 'staff' => 3];
        $aOrder = $roleOrder[$a['role']] ?? 99;
        $bOrder = $roleOrder[$b['role']] ?? 99;
        if ($aOrder !== $bOrder) return $aOrder - $bOrder;
        return strcasecmp($a['display_name'], $b['display_name']);
    });

    echo json_encode(['success' => true, 'users' => $users]);
}

/**
 * Get a single user
 */
function um_handleGet() {
    global $canManageAdmins;

    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        return;
    }

    $user = findUserById($id);
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Admin users can only see staff
    if (!$canManageAdmins && in_array($user['role'], ['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Remove sensitive data
    unset($user['password_hash']);
    unset($user['password_reset_token']);
    unset($user['password_reset_expires']);

    echo json_encode(['success' => true, 'user' => $user]);
}

/**
 * Create a new user
 */
function um_handleCreate() {
    global $canManageAdmins;

    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $role = $_POST['role'] ?? 'staff';
    $password = $_POST['password'] ?? '';
    $status = $_POST['status'] ?? 'active';

    // Validate required fields
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Valid email required']);
        return;
    }

    if (empty($displayName)) {
        echo json_encode(['success' => false, 'error' => 'Display name required']);
        return;
    }

    if (empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Password required']);
        return;
    }

    // Admin can only create staff users
    if (!$canManageAdmins && in_array($role, ['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'error' => 'You can only create staff users']);
        return;
    }

    // Auto-generate username from email if not provided
    if (empty($username)) {
        $username = explode('@', $email)[0];
    }

    // Platform-admin grants are superadmin-assignable only. A non-superadmin
    // creating staff gets the safe defaults (no platform admin; CMS access on).
    $platformAdmin = $canManageAdmins ? !empty($_POST['platform_admin']) : false;
    $cmsAccess     = $canManageAdmins ? !empty($_POST['cms_access'])     : true;

    $result = createUser([
        'email' => $email,
        'username' => $username,
        'display_name' => $displayName,
        'password' => $password,
        'role' => $role,
        'status' => $status,
        'platform_admin' => $platformAdmin,
        'cms_access' => $cmsAccess
    ]);

    if ($result['success']) {
        // Remove sensitive data from response
        unset($result['user']['password_hash']);
        echo json_encode(['success' => true, 'user' => $result['user'], 'message' => 'User created successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
}

/**
 * Update an existing user (not password)
 */
function um_handleUpdate() {
    global $canManageAdmins, $currentUser;

    $id = $_POST['id'] ?? '';
    if (empty($id)) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        return;
    }

    $data = loadUsersData();
    $userIndex = null;
    $user = null;

    foreach ($data['users'] as $index => $u) {
        if ($u['id'] === $id) {
            $userIndex = $index;
            $user = $u;
            break;
        }
    }

    if ($user === null) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Admin can only edit staff users
    if (!$canManageAdmins && in_array($user['role'], ['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Prevent self-demotion for the only superadmin
    if ($id === $currentUser['id'] && $currentUser['role'] === 'superadmin') {
        $newRole = $_POST['role'] ?? $user['role'];
        if ($newRole !== 'superadmin') {
            // Check if there are other superadmins
            $superadminCount = 0;
            foreach ($data['users'] as $u) {
                if ($u['role'] === 'superadmin' && $u['status'] === 'active') {
                    $superadminCount++;
                }
            }
            if ($superadminCount <= 1) {
                echo json_encode(['success' => false, 'error' => 'Cannot demote the only superadmin']);
                return;
            }
        }
    }

    // Update fields
    $email = trim($_POST['email'] ?? $user['email']);
    $username = trim($_POST['username'] ?? $user['username']);
    $displayName = trim($_POST['display_name'] ?? $user['display_name']);
    $role = $_POST['role'] ?? $user['role'];
    $status = $_POST['status'] ?? $user['status'];

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Valid email required']);
        return;
    }

    // Check email uniqueness (if changed)
    if (strtolower($email) !== strtolower($user['email'])) {
        foreach ($data['users'] as $u) {
            if ($u['id'] !== $id && strtolower($u['email']) === strtolower($email)) {
                echo json_encode(['success' => false, 'error' => 'Email already exists']);
                return;
            }
        }
    }

    // Admin can only set role to staff
    if (!$canManageAdmins && in_array($role, ['superadmin', 'admin'])) {
        $role = 'staff';
    }

    // Update user
    $data['users'][$userIndex]['email'] = strtolower($email);
    $data['users'][$userIndex]['username'] = $username;
    $data['users'][$userIndex]['display_name'] = $displayName;
    $data['users'][$userIndex]['role'] = $role;
    $data['users'][$userIndex]['status'] = $status;
    // Platform-admin grants — only a superadmin may change them. The edit form
    // always submits both checkboxes for superadmins, so an unchecked box is an
    // explicit false. Non-superadmins never see these and leave them untouched.
    if ($canManageAdmins) {
        $data['users'][$userIndex]['platform_admin'] = !empty($_POST['platform_admin']);
        $data['users'][$userIndex]['cms_access'] = !empty($_POST['cms_access']);
    }
    $data['users'][$userIndex]['updated_at'] = date('c');

    if (saveUsersData($data)) {
        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save user data']);
    }
}

/**
 * Delete a user
 */
function um_handleDelete() {
    global $canManageAdmins, $currentUser;

    $id = $_POST['id'] ?? '';
    if (empty($id)) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        return;
    }

    // Cannot delete yourself
    if ($id === $currentUser['id']) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
        return;
    }

    $data = loadUsersData();
    $userIndex = null;
    $user = null;

    foreach ($data['users'] as $index => $u) {
        if ($u['id'] === $id) {
            $userIndex = $index;
            $user = $u;
            break;
        }
    }

    if ($user === null) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Admin can only delete staff users
    if (!$canManageAdmins && in_array($user['role'], ['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Cannot delete the last superadmin
    if ($user['role'] === 'superadmin') {
        $superadminCount = 0;
        foreach ($data['users'] as $u) {
            if ($u['role'] === 'superadmin' && $u['status'] === 'active') {
                $superadminCount++;
            }
        }
        if ($superadminCount <= 1) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete the only superadmin']);
            return;
        }
    }

    // Remove user
    array_splice($data['users'], $userIndex, 1);

    if (saveUsersData($data)) {
        echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save user data']);
    }
}

/**
 * Change a user's password
 */
function um_handleChangePassword() {
    global $canManageAdmins, $currentUser;

    $id = $_POST['id'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if (empty($id)) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        return;
    }

    if (empty($newPassword)) {
        echo json_encode(['success' => false, 'error' => 'New password required']);
        return;
    }

    $data = loadUsersData();
    $userIndex = null;
    $user = null;

    foreach ($data['users'] as $index => $u) {
        if ($u['id'] === $id) {
            $userIndex = $index;
            $user = $u;
            break;
        }
    }

    if ($user === null) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Admin can only change staff passwords (and their own)
    if (!$canManageAdmins && $id !== $currentUser['id'] && in_array($user['role'], ['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Validate password strength
    $errors = validatePassword($newPassword);
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
        return;
    }

    // Update password
    $data['users'][$userIndex]['password_hash'] = hashPassword($newPassword);
    $data['users'][$userIndex]['updated_at'] = date('c');
    $data['users'][$userIndex]['password_reset_token'] = null;
    $data['users'][$userIndex]['password_reset_expires'] = null;

    if (saveUsersData($data)) {
        echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save user data']);
    }
}

/**
 * Toggle user status (active/inactive)
 */
function um_handleToggleStatus() {
    global $canManageAdmins, $currentUser;

    $id = $_POST['id'] ?? '';

    if (empty($id)) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        return;
    }

    // Cannot toggle yourself
    if ($id === $currentUser['id']) {
        echo json_encode(['success' => false, 'error' => 'Cannot change your own status']);
        return;
    }

    $data = loadUsersData();
    $userIndex = null;
    $user = null;

    foreach ($data['users'] as $index => $u) {
        if ($u['id'] === $id) {
            $userIndex = $index;
            $user = $u;
            break;
        }
    }

    if ($user === null) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Admin can only toggle staff users
    if (!$canManageAdmins && in_array($user['role'], ['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Cannot deactivate the last active superadmin
    if ($user['role'] === 'superadmin' && $user['status'] === 'active') {
        $activeSuperadmins = 0;
        foreach ($data['users'] as $u) {
            if ($u['role'] === 'superadmin' && $u['status'] === 'active') {
                $activeSuperadmins++;
            }
        }
        if ($activeSuperadmins <= 1) {
            echo json_encode(['success' => false, 'error' => 'Cannot deactivate the only active superadmin']);
            return;
        }
    }

    // Toggle status
    $newStatus = ($user['status'] === 'active') ? 'inactive' : 'active';
    $data['users'][$userIndex]['status'] = $newStatus;
    $data['users'][$userIndex]['updated_at'] = date('c');

    if (saveUsersData($data)) {
        echo json_encode(['success' => true, 'status' => $newStatus, 'message' => 'User status updated']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save user data']);
    }
}

/**
 * Generate a temporary password for a user
 */
function um_handleResetPassword() {
    global $canManageAdmins, $currentUser;

    $id = $_POST['id'] ?? '';

    if (empty($id)) {
        echo json_encode(['success' => false, 'error' => 'User ID required']);
        return;
    }

    $data = loadUsersData();
    $userIndex = null;
    $user = null;

    foreach ($data['users'] as $index => $u) {
        if ($u['id'] === $id) {
            $userIndex = $index;
            $user = $u;
            break;
        }
    }

    if ($user === null) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        return;
    }

    // Admin can only reset staff passwords
    if (!$canManageAdmins && in_array($user['role'], ['superadmin', 'admin'])) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }

    // Generate temporary password
    $tempPassword = um_generateTempPassword();

    // Update password
    $data['users'][$userIndex]['password_hash'] = hashPassword($tempPassword);
    $data['users'][$userIndex]['updated_at'] = date('c');

    if (saveUsersData($data)) {
        echo json_encode([
            'success' => true,
            'temp_password' => $tempPassword,
            'message' => 'Temporary password generated. Please share it securely with the user.'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save user data']);
    }
}

/**
 * Generate a random temporary password
 */
function um_generateTempPassword(): string {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $numbers = '23456789';
    $special = '!@#$%&*';

    $password = '';
    $password .= $upper[random_int(0, strlen($upper) - 1)];
    $password .= $lower[random_int(0, strlen($lower) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];

    $all = $upper . $lower . $numbers . $special;
    for ($i = 0; $i < 8; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    return str_shuffle($password);
}

// ============================================================================
// PERMISSIONS MANAGEMENT
// ============================================================================

/**
 * Get permissions configuration and menu structure
 */
function um_handleGetPermissions() {
    global $currentRole;

    // Only superadmin can access permissions
    if ($currentRole !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only superadmin can manage permissions']);
        return;
    }

    $config = loadAuthConfig();
    $permissions = $config['menu_permissions'] ?? [
        'superadmin' => ['*'],
        'admin' => [],
        'staff' => []
    ];

    // Build menu structure from admin_menu.php
    $menuStructure = um_buildMenuStructure();

    echo json_encode([
        'success' => true,
        'permissions' => $permissions,
        'menu_structure' => $menuStructure
    ]);
}

/**
 * Save permissions configuration
 */
function um_handleSavePermissions() {
    global $currentRole;

    // Only superadmin can save permissions
    if ($currentRole !== 'superadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Only superadmin can manage permissions']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $newPermissions = $input['permissions'] ?? null;

    if (!$newPermissions || !is_array($newPermissions)) {
        echo json_encode(['success' => false, 'error' => 'Invalid permissions data']);
        return;
    }

    // Load existing config
    $config = loadAuthConfig();

    // Update permissions
    $config['menu_permissions'] = $newPermissions;

    // Save to file
    $configFile = SITE_ROOT . '/admin/data/auth-config.json';
    $saved = file_put_contents(
        $configFile,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );

    if ($saved !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'Permissions saved successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save permissions']);
    }
}

/**
 * Build menu structure for permissions matrix
 */
function um_buildMenuStructure(): array {
    // Top-level items
    $top = [
        ['label' => 'Dashboard', 'key' => 'dashboard.php'],
        ['label' => 'Page', 'key' => 'page-manager.php'],
        ['label' => 'Menu Manager', 'key' => 'menu-manager.php'],
        ['label' => 'Content Stacks', 'key' => 'content-stacks'],
        ['label' => 'Gallery Manager', 'key' => 'gallery-manager.php'],
        ['label' => 'File Manager', 'key' => 'fm-admin.php'],
        ['label' => 'YouTube Playlist', 'key' => 'youtube-playlist-mgr.php'],
    ];

    // Sections
    $sections = [
        [
            'title' => 'Events Management',
            'items' => [
                ['label' => 'Events Manager', 'key' => 'events_manager'],
                ['label' => 'FB Events', 'key' => 'facebook_events'],
            ]
        ],
        [
            'title' => 'Ecommerce',
            'items' => [
                ['label' => 'Printful', 'key' => 'printful-mgr'],
                ['label' => 'My Store', 'key' => 'my-store'],
                ['label' => 'Payment Providers', 'key' => 'payment-providers'],
            ]
        ],
        [
            'title' => 'System Admin',
            'items' => [
                ['label' => 'Site Settings', 'key' => 'site-settings.php'],
                ['label' => 'Backgrounds', 'key' => 'background_mgr'],
                ['label' => 'Backups', 'key' => 'backup_admin.php'],
                ['label' => 'Media Thumbs', 'key' => 'media-thumbs-utility.php'],
                ['label' => 'Fonts', 'key' => 'font-manager.php'],
                ['label' => 'Updates', 'key' => 'update_manager.php'],
                ['label' => 'Mailgun', 'key' => 'mailgun_manager.php'],
                ['label' => 'Users', 'key' => 'user-mgr.php'],
            ]
        ],
    ];

    return [
        'top' => $top,
        'sections' => $sections
    ];
}
