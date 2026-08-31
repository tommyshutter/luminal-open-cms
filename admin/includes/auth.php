<?php
/**
 * @file SITE-ROOT/admin/auth.php
 * @version 2026.02.03.1200.EST
 * @description Admin authentication helper with role-based access control (RBAC).
 * Supports three roles: superadmin, admin, staff with menu filtering.
 */
require_once __DIR__ . '/session_boot.php';   // long-lived admin session params BEFORE session_start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Anchor for the Dashboard's session countdown. Stamped once per session so the clock
// reflects THIS session's real expiry (the cookie lifetime fixed at session creation),
// not a rolling value. Changing the duration on the Dashboard re-issues the cookie and
// rewrites this — see SiteSettings/api.php → set_session_hours.
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['admin_session_expires'])) {
    $_SESSION['admin_session_expires'] = time() + (defined('LUMINAL_SESSION_TTL') ? LUMINAL_SESSION_TTL : 43200);
}

// Step 1: Include the master utility file.
require_once dirname(__DIR__, 2) . '/utils.php';

// Step 2: Fail-safe for SITE_ROOT constant.
if (!defined('SITE_ROOT')) {
    http_response_code(500);
    die("<h1>FATAL CONFIGURATION ERROR</h1><p>The core application constant <code>SITE_ROOT</code> is not defined. The application cannot safely locate any files or directories.</p><p>Please check that <code>/utils.php</code> exists and correctly defines the <code>SITE_ROOT</code> constant. The application cannot continue without it.</p>");
}

// Global variable for messages.
global $admin_messages;
$admin_messages = [];

// ==========================================================================
// USER AUTHENTICATION & ROLE FUNCTIONS
// ==========================================================================

/**
 * Load users data from JSON file
 */
function loadUsersData(): array {
    $file = SITE_ROOT . '/admin/data/users.json';
    if (!file_exists($file)) {
        return ['users' => [], 'config' => [], 'login_attempts' => []];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : ['users' => [], 'config' => [], 'login_attempts' => []];
}

/**
 * Save users data to JSON file
 */
function saveUsersData(array $data): bool {
    $file = SITE_ROOT . '/admin/data/users.json';
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

/**
 * Load auth config from JSON file
 */
function loadAuthConfig(): array {
    $file = SITE_ROOT . '/admin/data/auth-config.json';
    if (!file_exists($file)) {
        return ['menu_permissions' => [], 'role_hierarchy' => []];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : ['menu_permissions' => [], 'role_hierarchy' => []];
}

/**
 * Get the current logged-in user data
 */
function getCurrentUser(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $data = loadUsersData();
    foreach ($data['users'] as $user) {
        if ($user['id'] === $_SESSION['user_id']) {
            return $user;
        }
    }
    return null;
}

/**
 * Get current user's role.
 * Falls back to 'superadmin' for legacy sessions (admin_logged_in but no user_role).
 */
function getUserRole(): string {
    if (isset($_SESSION['user_role'])) {
        return $_SESSION['user_role'];
    }
    // Legacy session — treat as superadmin so menu works until site adopts user manager
    if (!empty($_SESSION['admin_logged_in'])) {
        return 'superadmin';
    }
    return 'guest';
}

// ==========================================================================
// PLATFORM-ADMIN — generic, agnostic, site-scoped (2026-06-23)
// A "platform admin" controls THIS site's single platform extension (EduTech,
// Arena/financial, etc.) via ONE generic grant — modules never check a
// vertical-named capability. The grant is per-user (lives on this site's
// roster, so it cannot travel cross-site) and least-privilege: it does NOT
// imply CMS access (see has_cms_access) and is assignable only by a superadmin.
// ==========================================================================

/**
 * Is the given (or current) user a platform admin for THIS site?
 * Superadmin implicitly qualifies (superadmin ⊇ everything).
 */
function is_platform_admin(?array $user = null): bool {
    $user = $user ?? getCurrentUser();
    if (!$user) {
        // Legacy superadmin session with no user record still qualifies.
        return getUserRole() === 'superadmin';
    }
    if (($user['role'] ?? '') === 'superadmin') {
        return true;
    }
    return !empty($user['platform_admin']);
}

/**
 * Does the given (or current) user get CMS admin access?
 * Real CMS roles (superadmin/admin/staff) always do. A platform-only admin is
 * gated by an explicit per-user `cms_access` flag. ABSENT flag = true, so every
 * pre-existing user keeps current behavior (additive, no migration needed).
 */
function has_cms_access(?array $user = null): bool {
    $user = $user ?? getCurrentUser();
    if (!$user) {
        return getUserRole() === 'superadmin';
    }
    if (($user['role'] ?? '') === 'superadmin') {
        return true;
    }
    return array_key_exists('cms_access', $user) ? (bool)$user['cms_access'] : true;
}

/**
 * Human-readable label for THIS site's platform extension. Resolved per-site
 * like mm_brand(): site-settings.json `platform_name` → default "Platform".
 * Keeps the permission generic while the UI shows a contextual label
 * (e.g. "EduTech Admin" / "Arena Admin").
 */
function platform_name(): string {
    static $n = null;
    if ($n !== null) return $n;
    $name = '';
    $ssf = SITE_ROOT . '/admin/data/site-settings.json';
    if (is_file($ssf)) {
        $d = json_decode(file_get_contents($ssf), true);
        if (is_array($d) && !empty($d['platform_name'])) {
            $name = trim((string)$d['platform_name']);
        }
    }
    $n = ($name !== '') ? $name : 'Platform';
    return $n;
}

/**
 * Check if current user has at least the specified role level
 */
function hasRole(string $requiredRole): bool {
    $config = loadAuthConfig();
    $hierarchy = $config['role_hierarchy'] ?? [
        'superadmin' => 100,
        'admin' => 50,
        'staff' => 10,
        'guest' => 0
    ];

    $userRole = getUserRole();
    $userLevel = $hierarchy[$userRole] ?? 0;
    $requiredLevel = $hierarchy[$requiredRole] ?? 0;

    return $userLevel >= $requiredLevel;
}

/**
 * Require a minimum role level or redirect to login
 */
function requireRole(string $minRole): void {
    if (!hasRole($minRole)) {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /admin/login.php');
        } else {
            header('Location: /admin/dashboard.php?error=access_denied');
        }
        exit;
    }
}

/**
 * Check if user has permission for a specific menu key.
 * If auth-config.json doesn't exist yet, allow everything (pre-user-manager sites).
 */
function hasPermission(string $menuKey): bool {
    $userRole = getUserRole();
    if ($userRole === 'guest') {
        return false;
    }

    // If auth-config.json hasn't been created yet, grant full access (legacy behavior)
    $configFile = SITE_ROOT . '/admin/data/auth-config.json';
    if (!file_exists($configFile)) {
        return true;
    }

    $config = loadAuthConfig();
    $permissions = $config['menu_permissions'][$userRole] ?? [];

    // Superadmin has wildcard access
    if (in_array('*', $permissions, true)) {
        return true;
    }

    // Check for exact match or limited access
    foreach ($permissions as $perm) {
        $permKey = explode(':', $perm)[0]; // Handle "key:limited" format
        if ($permKey === $menuKey) {
            return true;
        }
    }

    return false;
}

/**
 * Check if a specific menu item should be shown
 */
function isMenuItemAllowed(string $key): bool {
    return hasPermission($key);
}

/**
 * Check if user has limited access to a menu item (admin role for certain items)
 */
function hasLimitedAccess(string $menuKey): bool {
    $userRole = getUserRole();
    $config = loadAuthConfig();
    $permissions = $config['menu_permissions'][$userRole] ?? [];

    foreach ($permissions as $perm) {
        if (strpos($perm, ':limited') !== false) {
            $permKey = explode(':', $perm)[0];
            if ($permKey === $menuKey) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Check if a menu section should be visible to current user
 */
function isSectionAllowed(string $sectionId): bool {
    $userRole = getUserRole();
    if ($userRole === 'superadmin') {
        return true;
    }

    $config = loadAuthConfig();
    $sectionPerms = $config['section_permissions'] ?? [];

    if (isset($sectionPerms[$sectionId])) {
        return in_array($userRole, $sectionPerms[$sectionId], true);
    }

    return true; // Default to visible if not explicitly restricted
}

/**
 * Check if a system admin item should be hidden from user
 */
function isSystemAdminItemRestricted(string $itemKey): bool {
    $userRole = getUserRole();
    if ($userRole === 'superadmin') {
        return false;
    }

    $config = loadAuthConfig();
    $restricted = $config['restricted_system_admin_items'][$userRole] ?? [];

    if (in_array('*', $restricted, true)) {
        return true; // All items restricted
    }

    return in_array($itemKey, $restricted, true);
}

/**
 * Validate password strength
 */
function validatePassword(string $password): array {
    $errors = [];
    $data = loadUsersData();
    $minLength = $data['config']['password_min_length'] ?? 8;

    if (strlen($password) < $minLength) {
        $errors[] = "Password must be at least {$minLength} characters long.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character.";
    }

    return $errors;
}

/**
 * Hash a password using bcrypt
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify a password against a hash
 */
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Generate a secure random token
 */
function generateSecureToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

/**
 * Generate a unique user ID
 */
function generateUserId(): string {
    return 'usr_' . bin2hex(random_bytes(8));
}

/**
 * Check if an identifier (email/username) is locked out
 */
function checkLoginAttempts(string $identifier): bool {
    $data = loadUsersData();
    $config = $data['config'] ?? [];
    $attempts = $data['login_attempts'][$identifier] ?? null;

    if (!$attempts) {
        return true; // No attempts, login allowed
    }

    $maxAttempts = $config['max_login_attempts'] ?? 5;
    $lockoutMinutes = $config['lockout_minutes'] ?? 15;

    // Check if lockout has expired
    if (isset($attempts['lockout_until'])) {
        if (time() < $attempts['lockout_until']) {
            return false; // Still locked out
        }
        // Lockout expired, reset attempts
        unset($data['login_attempts'][$identifier]);
        saveUsersData($data);
        return true;
    }

    // Check attempt count
    if (($attempts['count'] ?? 0) >= $maxAttempts) {
        // Lock the account
        $data['login_attempts'][$identifier]['lockout_until'] = time() + ($lockoutMinutes * 60);
        saveUsersData($data);
        return false;
    }

    return true;
}

/**
 * Record a login attempt
 */
function recordLoginAttempt(string $identifier, bool $success): void {
    $data = loadUsersData();

    if ($success) {
        // Clear attempts on successful login
        unset($data['login_attempts'][$identifier]);
    } else {
        // Increment failed attempts
        if (!isset($data['login_attempts'][$identifier])) {
            $data['login_attempts'][$identifier] = ['count' => 0, 'first_attempt' => time()];
        }
        $data['login_attempts'][$identifier]['count']++;
        $data['login_attempts'][$identifier]['last_attempt'] = time();
    }

    saveUsersData($data);
}

/**
 * Get remaining lockout time in minutes
 */
function getLockoutRemainingMinutes(string $identifier): int {
    $data = loadUsersData();
    $attempts = $data['login_attempts'][$identifier] ?? null;

    if (!$attempts || !isset($attempts['lockout_until'])) {
        return 0;
    }

    $remaining = $attempts['lockout_until'] - time();
    return $remaining > 0 ? ceil($remaining / 60) : 0;
}

/**
 * Find a user by email or username
 */
function findUserByIdentifier(string $identifier): ?array {
    $data = loadUsersData();
    $identifier = strtolower(trim($identifier));

    foreach ($data['users'] as $user) {
        if (strtolower($user['email']) === $identifier ||
            strtolower($user['username'] ?? '') === $identifier) {
            return $user;
        }
    }

    return null;
}

/**
 * Find a user by ID
 */
function findUserById(string $id): ?array {
    $data = loadUsersData();
    foreach ($data['users'] as $user) {
        if ($user['id'] === $id) {
            return $user;
        }
    }
    return null;
}

/**
 * Update user's last login time
 */
function updateLastLogin(string $userId): void {
    $data = loadUsersData();
    foreach ($data['users'] as &$user) {
        if ($user['id'] === $userId) {
            $user['last_login'] = date('c');
            break;
        }
    }
    saveUsersData($data);
}

/**
 * Check if any users exist (for first-run setup)
 */
function hasAnyUsers(): bool {
    $data = loadUsersData();
    return !empty($data['users']);
}

/**
 * Create a new user
 */
function createUser(array $userData): array {
    $data = loadUsersData();

    // Validate email uniqueness
    foreach ($data['users'] as $existing) {
        if (strtolower($existing['email']) === strtolower($userData['email'])) {
            return ['success' => false, 'error' => 'Email already exists.'];
        }
        if (!empty($userData['username']) &&
            strtolower($existing['username'] ?? '') === strtolower($userData['username'])) {
            return ['success' => false, 'error' => 'Username already exists.'];
        }
    }

    // Validate password
    $passwordErrors = validatePassword($userData['password']);
    if (!empty($passwordErrors)) {
        return ['success' => false, 'error' => implode(' ', $passwordErrors)];
    }

    $newUser = [
        'id' => generateUserId(),
        'email' => strtolower(trim($userData['email'])),
        'username' => trim($userData['username'] ?? ''),
        'password_hash' => hashPassword($userData['password']),
        'role' => $userData['role'] ?? 'staff',
        // Platform-admin grants (generic/agnostic/scoped — see is_platform_admin/has_cms_access).
        'platform_admin' => !empty($userData['platform_admin']),
        'cms_access' => array_key_exists('cms_access', $userData) ? (bool)$userData['cms_access'] : true,
        'display_name' => trim($userData['display_name'] ?? $userData['email']),
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'last_login' => null,
        'status' => 'active',
        'password_reset_token' => null,
        'password_reset_expires' => null,
        'settings' => [
            'notify_on_login' => false
        ]
    ];

    $data['users'][] = $newUser;

    if (saveUsersData($data)) {
        return ['success' => true, 'user' => $newUser];
    }

    return ['success' => false, 'error' => 'Failed to save user data.'];
}

// ==========================================================================
// FUNCTION DEFINITIONS
// ==========================================================================

function addAdminMessage($type, $text) {
    if (!isset($_SESSION['admin_messages'])) {
        $_SESSION['admin_messages'] = [];
    }
    $_SESSION['admin_messages'][] = ['type' => $type, 'text' => $text];
}

if (!function_exists('displayAdminMessages')) {
    function displayAdminMessages() {
        global $admin_messages;
        if (isset($_SESSION['admin_messages'])) {
            $admin_messages = array_merge($admin_messages, $_SESSION['admin_messages']);
            $_SESSION['admin_messages'] = [];
        }
        $output = '';
        if (!empty($admin_messages)) {
            $output .= '<div class="message-container active">';
            foreach ($admin_messages as $msg) {
                $type = htmlspecialchars($msg['type'] ?? 'info');
                $text = htmlspecialchars($msg['text'] ?? 'An unspecified message occurred.');
                $output .= '<div class="message-line ' . $type . '">' . $text . '</div>';
            }
            $output .= '</div>';
            $admin_messages = [];
        }
        return $output;
    }
}

function initializeSiteStructure() {
    $dirs = [

        SITE_ROOT . '/media/', 
        SITE_ROOT . '/media/images', 
        SITE_ROOT . '/media/images/backgrounds', 
        SITE_ROOT . '/media/images/left_panel',
        SITE_ROOT . '/media/images/right_panel',
        SITE_ROOT . '/media/images/logos', 
        SITE_ROOT . '/media/images/site', 
        SITE_ROOT . '/media/images/photo_gallery',
        SITE_ROOT . '/media/videos', 
        SITE_ROOT . '/media/videos/backgrounds', 
        SITE_ROOT . '/media/videos/left_panel',
        SITE_ROOT . '/media/videos/right_panel',
        SITE_ROOT . '/media/videos/logos', 
        SITE_ROOT . '/media/videos/site', 
        SITE_ROOT . '/media/videos/video_gallery',
        SITE_ROOT . '/admin/data/',
        SITE_ROOT . '/admin/data/caches',
        SITE_ROOT . '/admin/data/galleries',
        SITE_ROOT . '/admin/data/galleries/images',
        SITE_ROOT . '/admin/data/galleries/pdfs',
        SITE_ROOT . '/admin/data/galleries/videos',
        SITE_ROOT . '/admin/data/menu',
        SITE_ROOT . '/admin/data/pages',
        SITE_ROOT . '/admin/data/printful',
        SITE_ROOT . '/admin/data/thumbnails'
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                addAdminMessage('error', "Failed to create essential directory: " . htmlspecialchars($dir) . ". Check server permissions.");
            }
        }
        if (!is_writable($dir)) {
            addAdminMessage('warning', "Directory not writable: " . htmlspecialchars($dir) . ". Please set permissions (e.g., 755 or 777).");
        }
    }
}

/**
 * Initializes all necessary JSON data files for a new installation.
 * This version creates the new, separated settings files.
 */
function initializeJsonData() {
    $dataDir = SITE_ROOT . '/admin/data/';
    $files = [
        'meta_settings.json' => [
            'site_title' => 'The Site', 'site_name' => 'The Site', 'meta_description' => 'A website for artists and musicians.',
            'header_font_family' => 'Roboto', 'header_font_size' => '3.5', 'header_font_color' => '#ffffff',
            'header_font_bold' => false, 'header_font_italic' => false,
            'body_font_family' => 'Roboto', 'body_font_size' => '1.0', 'body_font_color' => '#ffffff',
            'body_font_bold' => false, 'body_font_italic' => false,
        ],
        'logo_settings.json' => [
            'enable_logo' => true, 'logo' => '', 'logo_max_width' => 150, 'logo_margin_bottom' => 0
        ],
        'header_settings.json' => [
            'full_width_header' => false, 'home_page_slug' => 'home.php'
        ],
        'menu_settings.json' => [
            'items' => [], 'menu_bg_color' => '#333333', 'menu_bg_opacity' => 1,
            'menu_item_bg_color' => '#555555', 'menu_item_hover_bg_color' => '#007bff',
            'menu_font_color' => '#ffffff', 'menu_font_hover_color' => '#ffffff',
            'menu_font_family' => 'Roboto', 'menu_font_size' => 1.1,
            'menu_font_bold' => false, 'menu_font_italic' => false
        ],
        'layout_settings.json' => [
            'left_col_disabled' => false, 'left_col_width' => 30, 'right_col_width' => 70, 'main_content_margin_top' => 20
        ],
        'background_settings.json' => [
            'active_mode' => 'image', 'background_sizing' => 'cover',
            'overlay' => ['color_rgba' => 'rgba(0,0,0,0.7)'],
            'single' => ['image_url' => '/media/images/backgrounds/default.jpg'],
            'slideshow' => ['playlist_name' => '', 'duration' => 5000],
            'video' => ['source_type' => null, 'url' => null, 'youtube_id' => null]
        ],
        'tour_dates.json' => [],
        'photos.json' => [],
        'videos.json' => [],
        'background_images.json' => []
    ];

    foreach ($files as $filename => $defaultContent) {
        if (!file_exists($dataDir . $filename)) {
            // Use saveJsonData from utils.php which handles JSON_PRETTY_PRINT and error logging
            if (!saveJsonData($filename, $defaultContent)) {
                 error_log("Auth Error: Failed to create or initialize JSON file: {$dataDir}{$filename}");
                 addAdminMessage('error', "Failed to create or initialize JSON file: " . htmlspecialchars($filename));
            }
        }
    }
}

function requireAuth() {
    // Check for new multi-user auth
    if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
        // Validate user still exists and is active
        $user = findUserById($_SESSION['user_id']);
        if ($user && $user['status'] === 'active') {
            return; // Authenticated
        }
        // User deleted or deactivated, clear session
        session_destroy();
        header('Location: /admin/login.php?error=session_invalid');
        exit;
    }

    // Legacy single-user check (will be removed after migration)
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return; // Legacy auth still valid during transition
    }

    header('Location: /admin/login.php');
    exit;
}

/**
 * Require a specific permission key. If the user lacks it, show a styled
 * "not authorized" callout instead of a blank page or silent redirect.
 */
function requirePermission(string $key, string $label = ''): void {
    if (hasPermission($key)) return;

    $role = htmlspecialchars(getUserRole(), ENT_QUOTES, 'UTF-8');
    $name = $label ?: htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    http_response_code(403);
    echo '<div style="max-width:600px;margin:40px auto;padding:24px 28px;background:#1e1e2e;border:2px solid #e05555;border-radius:10px;font-family:system-ui,sans-serif;color:#e0e0e0;">';
    echo '<h2 style="margin:0 0 10px;color:#f87171;">Not Authorized</h2>';
    echo '<p style="margin:0 0 8px;color:#ccc;">Your role <strong style="color:#fbbf24;">' . $role . '</strong> does not have access to <strong>' . $name . '</strong>.</p>';
    echo '<p style="margin:0;font-size:.85rem;color:#888;">Contact a superadmin to grant the <code style="background:#333;padding:2px 6px;border-radius:3px;">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</code> permission for your role.</p>';
    echo '</div>';
    exit;
}

// --- All original helper functions from your provided file are preserved below ---

function uploadMultipleFiles($files_input, $targetDir, $allowedMimeTypes, $maxSize = 25000000) {
    $uploadedFiles = ['success' => [], 'errors' => []];
    if (!is_dir($targetDir)) { if (!mkdir($targetDir, 0755, true)) { $uploadedFiles['errors'][] = "Upload directory does not exist and could not be created: " . htmlspecialchars($targetDir); return $uploadedFiles; } }
    if (!is_writable($targetDir)) { $uploadedFiles['errors'][] = "Upload directory is not writable. Check permissions for " . htmlspecialchars($targetDir); return $uploadedFiles; }
    $file_names = isset($files_input['name']) && is_array($files_input['name']) ? $files_input['name'] : [$files_input['name']];
    $file_tmps = isset($files_input['tmp_name']) && is_array($files_input['tmp_name']) ? $files_input['tmp_name'] : [$files_input['tmp_name']];
    $file_errors = isset($files_input['error']) && is_array($files_input['error']) ? $files_input['error'] : [$files_input['error']];
    $file_sizes = isset($files_input['size']) && is_array($files_input['size']) ? $files_input['size'] : [$files_input['size']];
    if (empty($file_names[0]) || $file_errors[0] === UPLOAD_ERR_NO_FILE) { $uploadedFiles['errors'][] = "No files selected for upload."; return $uploadedFiles; }
    foreach ($file_names as $index => $name) {
        $tmpName = $file_tmps[$index];
        if ($file_errors[$index] !== UPLOAD_ERR_OK) { $uploadedFiles['errors'][] = "Error uploading " . htmlspecialchars($name) . ": " . getUploadErrorMessage($file_errors[$index]); continue; }
        $actual_mime_type = mime_content_type($tmpName);
        if (!in_array($actual_mime_type, $allowedMimeTypes)) { $uploadedFiles['errors'][] = "Invalid file type for " . htmlspecialchars($name); continue; }
        if ($file_sizes[$index] > $maxSize) { $uploadedFiles['errors'][] = "File " . htmlspecialchars($name) . " is too large."; continue; }
        $newFilename = uniqid() . '_' . sanitizeFilename($name);
        $targetFilePath = rtrim($targetDir, '/') . '/' . $newFilename;
        if (move_uploaded_file($tmpName, $targetFilePath)) {
            $web_path_prefix = str_replace(SITE_ROOT, '', rtrim($targetDir, '/')) . '/';
            $uploadedFiles['success'][] = ['filename' => $newFilename, 'src' => $web_path_prefix . $newFilename];
        } else { $uploadedFiles['errors'][] = "Failed to move uploaded file " . htmlspecialchars($name); }
    }
    return $uploadedFiles;
}

function getUploadErrorMessage($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE: return 'File exceeds upload_max_filesize directive in php.ini.';
        case UPLOAD_ERR_FORM_SIZE: return 'File exceeds MAX_FILE_SIZE directive in form.';
        case UPLOAD_ERR_PARTIAL: return 'File was only partially uploaded.';
        case UPLOAD_ERR_NO_FILE: return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Missing a temporary folder.';
        case UPLOAD_ERR_CANT_WRITE: return 'Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION: return 'A PHP extension stopped the file upload.';
        default: return 'Unknown upload error.';
    }
}

function uploadSingleFile($file, $targetDir, $allowedMimeTypes, $maxSize = 250000000) {
    $uploadedFile = ['success' => null, 'error' => null];
    if (!is_dir($targetDir)) { if (!mkdir($targetDir, 0755, true)) { $uploadedFile['error'] = "Upload directory does not exist and could not be created."; return $uploadedFile; } }
    if (!is_writable($targetDir)) { $uploadedFile['error'] = "Upload directory is not writable. Check permissions."; return $uploadedFile; }
    if ($file['error'] !== UPLOAD_ERR_OK) { $uploadedFile['error'] = getUploadErrorMessage($file['error']); return $uploadedFile; }
    $actual_mime_type = mime_content_type($file['tmp_name']);
    if (!in_array($actual_mime_type, $allowedMimeTypes)) { $uploadedFile['error'] = "Invalid file type: " . htmlspecialchars($actual_mime_type); return $uploadedFile; }
    if ($file['size'] > $maxSize) { $uploadedFile['error'] = "File is too large. Max size is " . round($maxSize / 1000000, 2) . "MB."; return $uploadedFile; }
    $newFilename = uniqid() . '_' . sanitizeFilename($file['name']);
    $targetFilePath = rtrim($targetDir, '/') . '/' . $newFilename;
    if (move_uploaded_file($file['tmp_name'], $targetFilePath)) {
        $fileData = ['filename' => $newFilename, 'id' => uniqid('media_')];
        $web_path_prefix = str_replace(SITE_ROOT, '', rtrim($targetDir, '/')) . '/';
        $fileData['src'] = $web_path_prefix . $newFilename;
        if (str_starts_with($actual_mime_type, 'image/')) {
            list($width, $height) = @getimagesize($targetFilePath);
            $fileData['width'] = $width ?: 0;
            $fileData['height'] = $height ?: 0;
        } elseif (str_starts_with($actual_mime_type, 'video/')) {
            $fileData['title'] = pathinfo($file['name'], PATHINFO_FILENAME);
            $fileData['type'] = 'mp4';
            $fileData['video_url'] = $fileData['src'];
            $fileData['thumbnail'] = '';
        }
        $uploadedFile['success'] = $fileData;
    } else {
        $uploadedFile['error'] = "Failed to move uploaded file. Check permissions on " . htmlspecialchars($targetDir);
    }
    return $uploadedFile;
}

function deleteMediaItem($identifier, $filePathPrefix, $jsonFilename) {
    global $admin_messages;
    $data = loadJsonData($jsonFilename);
    if (!is_array($data)) { addAdminMessage('error', 'Failed to load media data for deletion from ' . htmlspecialchars($jsonFilename) . '.'); return ['success' => false, 'message' => 'Failed to load media data.']; }
    $deleted = false;
    $updatedData = [];
    foreach ($data as $item) {
        $item_matches = false;
        if ($jsonFilename === 'background_images.json') { if ((isset($item['id']) && $item['id'] === $identifier) || (isset($item['filename']) && $item['filename'] === $identifier)) { $item_matches = true; }
        } elseif ($jsonFilename === 'videos.json') { if ((isset($item['id']) && $item['id'] === $identifier) || (isset($item['video_url']) && $item['video_url'] === $identifier)) { $item_matches = true; } }
        if ($item_matches) {
            $is_local_file = false;
            $file_to_unlink = '';
            if ($jsonFilename === 'background_images.json' && isset($item['filename'])) { $file_to_unlink = $filePathPrefix . $item['filename']; $is_local_file = true;
            } elseif ($jsonFilename === 'videos.json' && isset($item['type']) && $item['type'] === 'mp4' && isset($item['filename'])) { $file_to_unlink = $filePathPrefix . $item['filename']; $is_local_file = true; }
            if ($is_local_file && file_exists($file_to_unlink)) { if (@unlink($file_to_unlink)) { addAdminMessage('success', 'Deleted physical file: ' . htmlspecialchars(basename($file_to_unlink))); } else { addAdminMessage('warning', 'Could not delete physical file: ' . htmlspecialchars(basename($file_to_unlink)) . '. Check permissions.'); }
            } elseif (!$is_local_file) { addAdminMessage('info', 'Removing external media entry: ' . htmlspecialchars($item['title'] ?? $identifier));
            } else { addAdminMessage('warning', 'Media file not found on disk, removing JSON entry: ' . htmlspecialchars(basename($file_to_unlink ?? $identifier))); }
            $deleted = true;
        } else { $updatedData[] = $item; }
    }
    if ($deleted) { if (saveJsonData($jsonFilename, array_values($updatedData))) { addAdminMessage('success', 'Media item reference removed from ' . htmlspecialchars($jsonFilename) . '.'); return ['success' => true, 'message' => 'Media item deleted.']; } else { addAdminMessage('error', 'Failed to save updated media data after deletion to ' . htmlspecialchars($jsonFilename) . '.'); return ['success' => false, 'message' => 'Failed to save updated media data.']; }
    } else { addAdminMessage('warning', 'Media item not found for deletion (ID/URL: ' . htmlspecialchars($identifier) . ').'); return ['success' => false, 'message' => 'Media item not found.']; }
}

function reorderMediaItems($ordered_identifiers, $jsonFilename) {
    global $admin_messages;
    $data = loadJsonData($jsonFilename);
    if (!is_array($data)) { addAdminMessage('error', 'Failed to load media data for reordering from ' . htmlspecialchars($jsonFilename) . '.'); return ['success' => false, 'message' => 'Failed to load media data.']; }
    $idMap = [];
    foreach ($data as $item) {
        if ($jsonFilename === 'background_images.json') { $key = $item['id'] ?? $item['filename'] ?? uniqid(); 
        } elseif ($jsonFilename === 'videos.json') { $key = $item['video_url'] ?? $item['id'] ?? uniqid(); 
        } else { $key = $item['id'] ?? uniqid(); }
        $idMap[$key] = $item;
    }
    $reorderedData = [];
    foreach ($ordered_identifiers as $identifier) {
        if (isset($idMap[$identifier])) { $reorderedData[] = $idMap[$identifier]; unset($idMap[$identifier]); }
    }
    foreach ($idMap as $remainingItem) {
        $reorderedData[] = $remainingItem;
        addAdminMessage('info', 'Reorder: Added un-ordered item ' . htmlspecialchars($remainingItem['filename'] ?? $remainingItem['video_url'] ?? $remainingItem['id'] ?? 'unknown') . ' to the end.');
    }
    if (saveJsonData($jsonFilename, array_values($reorderedData))) { addAdminMessage('success', 'Media items in ' . htmlspecialchars($jsonFilename) . ' reordered successfully.'); return ['success' => true, 'message' => 'Media items reordered.'];
    } else { addAdminMessage('error', 'Failed to save reordered media data to ' . htmlspecialchars($jsonFilename) . '.'); return ['success' => false, 'message' => 'Failed to save reordered media data.']; }
}

function synchronizeMediaFolder($relativeDirPath, $jsonFilename, $mediaType) {
    global $admin_messages;
    $absoluteDirPath = SITE_ROOT . $relativeDirPath;
    $report = ['added' => 0, 'removed' => 0];
    if (!is_dir($absoluteDirPath)) { addAdminMessage('error', "Cannot scan: Directory does not exist at {$absoluteDirPath}"); return $report; }
    $mediaData = loadJsonData($jsonFilename);
    if (!is_array($mediaData)) { $mediaData = []; }
    $diskFiles = array_diff(scandir($absoluteDirPath), ['.', '..']);
    $allowedExtensions = [];
    if ($mediaType === 'image') { $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    } elseif ($mediaType === 'video') { $allowedExtensions = ['mp4', 'webm']; }
    $filesInJson = [];
    $survivingMedia = [];
    foreach ($mediaData as $item) {
        $is_local_item = false;
        if ($mediaType === 'image' && isset($item['filename'])) { $is_local_item = true;
        } elseif ($mediaType === 'video' && isset($item['type']) && $item['type'] === 'mp4' && isset($item['filename'])) { $is_local_item = true; }
        if ($is_local_item) {
            if (in_array($item['filename'], $diskFiles)) { $survivingMedia[] = $item; $filesInJson[] = $item['filename'];
            } else { $report['removed']++; addAdminMessage('warning', "Removed reference to missing local file: " . htmlspecialchars($item['filename']) . " from JSON."); }
        } else { $survivingMedia[] = $item; }
    }
    foreach ($diskFiles as $filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, $allowedExtensions) && !in_array($filename, $filesInJson)) {
            $fullFilePath = $absoluteDirPath . $filename;
            $fileData = ['filename' => $filename, 'id' => uniqid('media_')];
            $item_web_path_prefix = str_replace(SITE_ROOT, '', rtrim($absoluteDirPath, '/')) . '/';
            if ($mediaType === 'image') {
                list($width, $height) = @getimagesize($fullFilePath);
                $fileData['width'] = $width ?: 0;
                $fileData['height'] = $height ?: 0;
                $fileData['src'] = $item_web_path_prefix . $filename;
            } else if ($mediaType === 'video') {
                $fileData['title'] = pathinfo($filename, PATHINFO_FILENAME);
                $fileData['type'] = 'mp4';
                $fileData['video_url'] = $item_web_path_prefix . $filename;
                $fileData['thumbnail'] = '';
            }
            $survivingMedia[] = $fileData;
            $report['added']++;
            addAdminMessage('info', "Added new file from disk: " . htmlspecialchars($filename));
        }
    }
    if ($report['added'] > 0 || $report['removed'] > 0) {
        if (saveJsonData($jsonFilename, array_values($survivingMedia))) { addAdminMessage('success', "Sync complete for {$relativeDirPath}: {$report['added']} added, {$report['removed']} removed.");
        } else { addAdminMessage('error', "Failed to save synchronized data for {$relativeDirPath}. Check permissions."); }
    } else { addAdminMessage('info', "Folder is already in sync: {$relativeDirPath}"); }
    return $report;
}

if (!function_exists('isValidUrl')) { function isValidUrl($url) { return filter_var($url, FILTER_VALIDATE_URL) !== false; }}
if (!function_exists('generateId')) { function generateId() { return uniqid('id_'); }}
if (!function_exists('getCurrentDate')) { function getCurrentDate() { return date('Y-m-d H:i:s'); }}

// SCRIPT EXECUTION
initializeSiteStructure();
initializeJsonData();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>