<?php
/**
 * Account API — self-service for the LOGGED-IN user only.
 *
 * Any authenticated (non-guest) user may update THEIR OWN credentials.
 * SECURITY: every action operates strictly on $_SESSION['user_id'] — it never
 * accepts or trusts a target user id from the request, so it cannot be used to
 * touch another account regardless of role. Identity changes (email/password)
 * require the current password (re-auth), which doubles as CSRF protection.
 *
 * Actions:
 *   me               — return the current user's safe profile (no secrets)
 *   update_profile   — change own display_name / email   (requires current password)
 *   change_password  — change own password               (requires current password)
 *
 * @module  UserManager
 * @file    /admin/modules/UserManager/account_api.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/auth.php';

header('Content-Type: application/json; charset=utf-8');

function acc_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
}

/* Auth gate: any logged-in user. No role requirement — this is self-service. */
if (empty($_SESSION['user_id'])) {
    acc_out(['success' => false, 'error' => 'Not authenticated.'], 401);
}

$me = getCurrentUser();
if (!$me) {
    acc_out(['success' => false, 'error' => 'Account not found.'], 401);
}

/* Always resolve the caller's OWN record from the store — never a passed id. */
function acc_load_self(): array {
    $data = loadUsersData();
    foreach ($data['users'] as $i => $u) {
        if (($u['id'] ?? null) === $_SESSION['user_id']) {
            return ['data' => $data, 'index' => $i, 'user' => $u];
        }
    }
    acc_out(['success' => false, 'error' => 'Account not found.'], 404);
    return []; // unreachable (acc_out exits) — satisfies return type
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'me':
        acc_out(['success' => true, 'user' => [
            'id'            => $me['id'] ?? '',
            'email'         => $me['email'] ?? '',
            'username'      => $me['username'] ?? '',
            'display_name'  => $me['display_name'] ?? '',
            'role'          => $me['role'] ?? 'staff',
            'platform_role' => $me['platform_role'] ?? null,
        ]]);
        break;

    case 'update_profile': {
        $current = (string)($_POST['current_password'] ?? '');
        $self = acc_load_self();
        $user = $self['user'];

        // Re-auth before any identity change.
        if (!verifyPassword($current, (string)($user['password_hash'] ?? ''))) {
            acc_out(['success' => false, 'error' => 'Current password is incorrect.'], 403);
        }

        $email       = strtolower(trim((string)($_POST['email'] ?? $user['email'] ?? '')));
        $displayName = trim((string)($_POST['display_name'] ?? $user['display_name'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            acc_out(['success' => false, 'error' => 'A valid email address is required.'], 400);
        }
        if ($displayName === '') {
            acc_out(['success' => false, 'error' => 'Display name is required.'], 400);
        }

        $data = $self['data'];

        // Email uniqueness across OTHER users.
        if ($email !== strtolower((string)($user['email'] ?? ''))) {
            foreach ($data['users'] as $u) {
                if (($u['id'] ?? null) !== ($user['id'] ?? null)
                    && strtolower((string)($u['email'] ?? '')) === $email) {
                    acc_out(['success' => false, 'error' => 'That email address is already in use.'], 409);
                }
            }
        }

        $idx = $self['index'];
        $data['users'][$idx]['email']        = $email;
        $data['users'][$idx]['display_name'] = $displayName;
        $data['users'][$idx]['updated_at']   = date('c');

        if (!saveUsersData($data)) {
            acc_out(['success' => false, 'error' => 'Failed to save changes.'], 500);
        }
        // Keep the live session display name in sync with the rail.
        $_SESSION['user_display_name'] = $displayName;
        acc_out(['success' => true, 'message' => 'Profile updated.']);
        break;
    }

    case 'change_password': {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $self = acc_load_self();
        $user = $self['user'];

        if (!verifyPassword($current, (string)($user['password_hash'] ?? ''))) {
            acc_out(['success' => false, 'error' => 'Current password is incorrect.'], 403);
        }
        if ($new === '') {
            acc_out(['success' => false, 'error' => 'New password is required.'], 400);
        }
        if ($new !== $confirm) {
            acc_out(['success' => false, 'error' => 'New passwords do not match.'], 400);
        }
        if ($new === $current) {
            acc_out(['success' => false, 'error' => 'New password must differ from your current password.'], 400);
        }
        $errors = validatePassword($new);
        if (!empty($errors)) {
            acc_out(['success' => false, 'error' => implode(' ', $errors)], 400);
        }

        $data = $self['data'];
        $idx  = $self['index'];
        $data['users'][$idx]['password_hash']          = hashPassword($new);
        $data['users'][$idx]['password_reset_token']   = null;
        $data['users'][$idx]['password_reset_expires'] = null;
        $data['users'][$idx]['updated_at']             = date('c');

        if (!saveUsersData($data)) {
            acc_out(['success' => false, 'error' => 'Failed to save new password.'], 500);
        }
        acc_out(['success' => true, 'message' => 'Password changed successfully.']);
        break;
    }

    default:
        acc_out(['success' => false, 'error' => 'Unknown action.'], 400);
}
