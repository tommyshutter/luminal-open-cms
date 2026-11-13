<?php
/**
 * Luminal CMS — Guard Adapter
 *
 * Provides thoht.io AdminPanel-compatible guard API backed by Luminal's
 * session-based authentication. Modules that call guard_require_auth(),
 * guard_user(), and guard_require_role() work identically in both systems.
 *
 * thoht.io uses JWT tokens; Luminal uses PHP sessions. This adapter
 * translates between the two so module code doesn't need to know which
 * system it's running in.
 *

 */
declare(strict_types=1);

if (defined('GUARD_LOADED')) {
    return;
}
define('GUARD_LOADED', true);

// ============================================================================
// LOAD LUMINAL AUTH
// ============================================================================

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/auth.php';

// ============================================================================
// API REQUEST DETECTION (matches thoht.io)
// ============================================================================

function guard_is_api_request(): bool {
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    if (strpos($accept, 'application/json') !== false) return true;
    $xr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($xr === 'xmlhttprequest') return true;
    // Check for common API patterns
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/api.php') !== false) return true;
    if (strpos($uri, 'action=') !== false && $_SERVER['REQUEST_METHOD'] === 'POST') return true;
    return false;
}

// ============================================================================
// ROLE NORMALIZATION
// ============================================================================

/**
 * Normalize Luminal role to thoht.io role for guard_user() output.
 * thoht.io modules check: $user['role'] === 'admin'
 * Luminal has: superadmin > admin > staff > guest
 * Mapping: superadmin → admin, admin → admin, staff → staff, guest → guest
 *
 * The original Luminal role is preserved in 'luminal_role' for code that needs it.
 */
function _guard_normalize_role(string $luminalRole): string {
    return ($luminalRole === 'superadmin') ? 'admin' : $luminalRole;
}

// ============================================================================
// GUARD FUNCTIONS (thoht.io-compatible API)
// ============================================================================

/**
 * Check if user is authenticated via Luminal session.
 * Populates $GLOBALS['GUARD_USER'] for guard_user() compatibility.
 */
function guard_is_authenticated(): bool {
    // New multi-user session
    if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
        $user = findUserById($_SESSION['user_id']);
        if ($user && ($user['status'] ?? 'active') === 'active') {
            $luminalRole = $user['role'] ?? 'staff';
            $GLOBALS['GUARD_USER'] = [
                'uid'          => $user['id'],
                'role'         => _guard_normalize_role($luminalRole),
                'luminal_role' => $luminalRole,
                'name'         => $user['display_name'] ?? $user['username'] ?? $user['email'] ?? 'Admin',
            ];
            return true;
        }
        // User file missing (e.g. no users.json) but session was set by login —
        // trust the session role so sites without users.json still work.
        if (!$user) {
            $luminalRole = $_SESSION['user_role'] ?? 'staff';
            $GLOBALS['GUARD_USER'] = [
                'uid'          => $_SESSION['user_id'],
                'role'         => _guard_normalize_role($luminalRole),
                'luminal_role' => $luminalRole,
                'name'         => $_SESSION['user_display_name'] ?? 'Admin',
            ];
            return true;
        }
    }

    // Legacy single-user session
    if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        $GLOBALS['GUARD_USER'] = [
            'uid'          => 'legacy',
            'role'         => 'admin',
            'luminal_role' => 'superadmin',
            'name'         => 'Admin',
        ];
        return true;
    }

    return false;
}

/**
 * Require authentication. Redirects to login or returns 401 JSON for APIs.
 * Matches thoht.io guard_require_auth() signature exactly.
 */
function guard_require_auth(): void {
    if (guard_is_authenticated()) {
        return;
    }

    if (guard_is_api_request()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: /admin/login.php');
    exit;
}

/**
 * Require platform-admin access for a platform-extension control panel.
 * Generic + agnostic + site-scoped: passes for full CMS admins (superadmin/admin —
 * no regression) OR any user holding this site's platform_admin grant. A bare
 * staff user without the grant is denied. The grant is assignable only by a
 * superadmin (see UserManager) and never names a vertical.
 */
function guard_require_platform_admin(): void {
    guard_require_auth();
    $u = guard_user();
    $role = $u['role'] ?? '';          // normalized: superadmin → admin
    if ($role === 'admin' || is_platform_admin()) {
        return;
    }
    if (guard_is_api_request()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(403);
    echo '<!doctype html><body style="background:#1a1a2e;color:#f87171;padding:40px;font-family:sans-serif"><h1>Access Denied</h1><p>Platform-admin access required.</p></body>';
    exit;
}

/**
 * Require a specific role. Uses Luminal's hierarchical role system:
 * superadmin (100) > admin (50) > staff (10) > guest (0)
 *
 * thoht.io's guard_require_role() does exact match only.
 * Our adapter is more permissive: 'admin' accepts admin AND superadmin.
 */
function guard_require_role(string $role): void {
    $user = guard_user();
    if (!$user) {
        guard_require_auth();
        $user = guard_user();
    }

    // Use Luminal's hierarchical role check
    if (hasRole($role)) {
        return;
    }

    if (guard_is_api_request()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: /admin/modules/Dashboard/Dashboard.php?error=not_authorized');
    exit;
}

/**
 * Get current authenticated user claims.
 * Returns array with {uid, role, name} or null if not authenticated.
 */
function guard_user(): ?array {
    return $GLOBALS['GUARD_USER'] ?? null;
}

// ============================================================================
// JSON RESPONSE HELPER (used by guard internally, matches thoht.io)
// ============================================================================

function guard_json(int $status, array $obj): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
