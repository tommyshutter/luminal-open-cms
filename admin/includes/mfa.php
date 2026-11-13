<?php
/**
 * Luminal CMS — two-step sign-in with an authenticator app.
 *
 * Every admin sign-in path (password, Google, provision reset, first setup) calls
 * lum_login_finish($user, …). It does NOT create the admin session itself unless the
 * second step is satisfied; until then the visitor holds only $_SESSION['mfa_pending']
 * and every page that checks $_SESSION['user_id'] sees nobody. /admin/mfa.php asks for
 * the 6-digit code (or a recovery code), or enrols the person on first use, then calls
 * lum_session_set().
 *
 * Codes: RFC 6238 TOTP, SHA-1, 30 s, 6 digits, ±1 step, a step is never accepted twice.
 * The secret is sealed with the site's credential vault. Recovery codes are stored hashed.
 * "Remember this computer" = a 30-day cookie signed with a per-site key.
 * admin/data/mfa.json {"enforce": false} turns the second step off for one site.
 * Root CLI reset: php admin/scripts/mfa-reset.php EMAIL
 */
declare(strict_types=1);

if (!function_exists('mfa_required')) {

defined('MFA_PENDING_TTL') || define('MFA_PENDING_TTL', 600);          // seconds to finish the second step
defined('MFA_DEVICE_DAYS') || define('MFA_DEVICE_DAYS', 30);
defined('MFA_MAX_FAILS') || define('MFA_MAX_FAILS', 5);
defined('MFA_FAIL_WINDOW') || define('MFA_FAIL_WINDOW', 600);          // seconds
defined('MFA_DEVICE_COOKIE') || define('MFA_DEVICE_COOKIE', 'lum_mfa_device');

function mfa_settings(): array {
    $f = SITE_ROOT . '/admin/data/mfa.json';
    $j = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    return ['enforce' => !array_key_exists('enforce', $j) || !empty($j['enforce'])];
}

/** Clients go to their portal and never hold an admin session, so they are not asked. */
function mfa_required(array $user): bool {
    if (function_exists('is_client_user') && is_client_user($user)) return false;
    return mfa_settings()['enforce'];
}

function mfa_enrolled(array $user): bool {
    return !empty($user['mfa']['totp']);
}

/* ── base32 + TOTP ─────────────────────────────────────────────────────────── */

function mfa_b32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function mfa_b32_decode(string $b32): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32)); $bits = ''; $out = '';
    foreach (str_split($b32) as $c) { $v = strpos($alpha, $c); if ($v === false) continue; $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT); }
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function mfa_new_secret(): string { return mfa_b32_encode(random_bytes(20)); }

function mfa_code_at(string $b32, int $step): string {
    $key = mfa_b32_decode($b32);
    $h = hash_hmac('sha1', pack('N*', 0) . pack('N*', $step), $key, true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | ((ord($h[$o + 1]) & 0xff) << 16) | ((ord($h[$o + 2]) & 0xff) << 8) | (ord($h[$o + 3]) & 0xff);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** The matching step, or null. Never accepts a step at or before $lastStep (no replay). */
function mfa_verify_code(string $b32, string $code, int $lastStep = 0, ?int $now = null): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $t = intdiv($now ?? time(), 30);
    foreach ([0, -1, 1] as $d) {
        $s = $t + $d;
        if ($s <= $lastStep) continue;
        if (hash_equals(mfa_code_at($b32, $s), $code)) return $s;
    }
    return null;
}

function mfa_otpauth_uri(string $b32, string $account, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
         . '?secret=' . $b32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/* ── sealed secret, recovery codes ─────────────────────────────────────────── */

function mfa_load_vault(): void {
    $v = SITE_ROOT . '/admin/config/cred_vault.php';
    if (!function_exists('cred_vault_seal') && is_file($v)) require_once $v;
}

function mfa_seal(string $plain): string {
    mfa_load_vault();
    return (function_exists('cred_vault_available') && cred_vault_available()) ? cred_vault_seal($plain) : $plain;
}

function mfa_reveal(string $v): string {
    mfa_load_vault();
    return function_exists('cred_vault_reveal') ? cred_vault_reveal($v) : $v;
}

function mfa_secret_of(array $user): string { return mfa_reveal((string)($user['mfa']['totp'] ?? '')); }

/** Ten codes like 4F7K-29QM; returns the plain list (shown once) and stores only hashes. */
function mfa_new_recovery_codes(): array {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $out = [];
    for ($i = 0; $i < 10; $i++) {
        $c = '';
        for ($j = 0; $j < 8; $j++) $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        $out[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return $out;
}

/* ── users.json helpers ────────────────────────────────────────────────────── */

function mfa_update_user(string $uid, callable $fn): ?array {
    $data = loadUsersData(); $found = null;
    foreach ($data['users'] as $i => $u) {
        if (($u['id'] ?? '') === $uid) { $data['users'][$i] = $fn($u); $found = $data['users'][$i]; break; }
    }
    if ($found !== null) saveUsersData($data);
    return $found;
}

function mfa_save_enrolment(string $uid, string $b32, array $recoveryPlain, int $step): ?array {
    $hashes = array_map(fn($c) => password_hash(strtoupper(str_replace('-', '', $c)), PASSWORD_DEFAULT), $recoveryPlain);
    return mfa_update_user($uid, function ($u) use ($b32, $hashes, $step) {
        $u['mfa'] = ['totp' => mfa_seal($b32), 'enrolled_at' => gmdate('c'), 'recovery' => $hashes,
                     'last_step' => $step, 'fails' => 0, 'fail_at' => 0];
        return $u;
    });
}

/** Too many wrong codes recently? Returns minutes to wait, or 0. */
function mfa_locked_minutes(array $user): int {
    $m = $user['mfa'] ?? [];
    if ((int)($m['fails'] ?? 0) < MFA_MAX_FAILS) return 0;
    $left = (int)($m['fail_at'] ?? 0) + MFA_FAIL_WINDOW - time();
    return $left > 0 ? (int)ceil($left / 60) : 0;
}

function mfa_record_fail(string $uid): void {
    mfa_update_user($uid, function ($u) {
        $m = $u['mfa'] ?? [];
        $recent = time() - (int)($m['fail_at'] ?? 0) < MFA_FAIL_WINDOW;
        $m['fails'] = ($recent ? (int)($m['fails'] ?? 0) : 0) + 1;
        $m['fail_at'] = time();
        $u['mfa'] = $m; return $u;
    });
}

/** A correct 6-digit code or recovery code. Returns 'code', 'recovery' or null. */
function mfa_check(string $uid, string $input): ?string {
    $user = findUserById($uid);
    if (!$user || !mfa_enrolled($user) || mfa_locked_minutes($user)) return null;
    $m = $user['mfa'];
    $step = mfa_verify_code(mfa_secret_of($user), $input, (int)($m['last_step'] ?? 0));
    if ($step !== null) {
        mfa_update_user($uid, function ($u) use ($step) { $u['mfa']['last_step'] = $step; $u['mfa']['fails'] = 0; return $u; });
        return 'code';
    }
    $rc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
    if (strlen($rc) === 8) {
        foreach (($m['recovery'] ?? []) as $i => $h) {
            if (password_verify($rc, $h)) {
                mfa_update_user($uid, function ($u) use ($i) { unset($u['mfa']['recovery'][$i]); $u['mfa']['recovery'] = array_values($u['mfa']['recovery']); $u['mfa']['fails'] = 0; return $u; });
                return 'recovery';
            }
        }
    }
    mfa_record_fail($uid);
    return null;
}

/* ── remember this computer ────────────────────────────────────────────────── */

function mfa_device_key(): string {
    $dir = SITE_ROOT . '/admin/data/secrets';
    $f = $dir . '/mfa-device.key';
    if (!is_file($f)) {
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($f, 0600);
    }
    return (string)@file_get_contents($f);
}

function mfa_device_sig(string $uid, int $exp, string $enrolledAt): string {
    return hash_hmac('sha256', $uid . '|' . $exp . '|' . $enrolledAt, mfa_device_key());
}

function mfa_device_trusted(array $user): bool {
    $c = (string)($_COOKIE[MFA_DEVICE_COOKIE] ?? '');
    if ($c === '' || !mfa_enrolled($user)) return false;
    foreach (explode(',', $c) as $tok) {
        $p = explode('.', $tok);
        if (count($p) !== 3 || $p[0] !== $user['id'] || (int)$p[1] < time()) continue;
        if (hash_equals(mfa_device_sig($p[0], (int)$p[1], (string)($user['mfa']['enrolled_at'] ?? '')), $p[2])) return true;
    }
    return false;
}

function mfa_trust_device(array $user): void {
    $exp = time() + MFA_DEVICE_DAYS * 86400;
    $tok = $user['id'] . '.' . $exp . '.' . mfa_device_sig($user['id'], $exp, (string)($user['mfa']['enrolled_at'] ?? ''));
    $keep = array_filter(explode(',', (string)($_COOKIE[MFA_DEVICE_COOKIE] ?? '')), fn($t) => $t !== '' && strpos($t, $user['id'] . '.') !== 0);
    $keep[] = $tok;
    setcookie(MFA_DEVICE_COOKIE, implode(',', array_slice($keep, -5)), [
        'expires' => $exp, 'path' => '/admin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

/* ── finishing a sign-in ───────────────────────────────────────────────────── */

/** The admin session itself (the five vars every module reads) + optional 30-day cookie. */
function lum_session_set(array $user, bool $remember = false): void {
    session_regenerate_id(true);
    $_SESSION['user_id']           = $user['id'];
    $_SESSION['user_role']         = $user['role'];
    $_SESSION['user_email']        = $user['email'];
    $_SESSION['user_display_name'] = $user['display_name'];
    $_SESSION['login_time']        = time();
    $_SESSION['mfa_ok']            = $user['id'];
    unset($_SESSION['mfa_pending']);
    if ($remember) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + 60 * 60 * 24 * 30, 'path' => $params['path'], 'domain' => $params['domain'],
            'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => 'Lax']);
    }
}

/** Every sign-in path ends here. Never returns. */
function lum_login_finish(array $user, string $via, string $redirect = '/admin/dashboard.php', bool $remember = false): void {
    if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) $redirect = '/admin/dashboard.php';
    if (!mfa_required($user) || mfa_device_trusted($user)) {
        lum_session_set($user, $remember);
        header('Location: ' . $redirect);
        exit;
    }
    session_regenerate_id(true);
    foreach (['user_id', 'user_role', 'user_email', 'user_display_name', 'login_time', 'mfa_ok', 'admin_logged_in'] as $k) unset($_SESSION[$k]);
    $_SESSION['mfa_pending'] = ['uid' => $user['id'], 'at' => time(), 'via' => $via, 'remember' => $remember, 'redirect' => $redirect];
    header('Location: /admin/mfa.php');
    exit;
}

function mfa_pending_user(): ?array {
    $p = $_SESSION['mfa_pending'] ?? null;
    if (!is_array($p) || time() - (int)($p['at'] ?? 0) > MFA_PENDING_TTL) { unset($_SESSION['mfa_pending']); return null; }
    return findUserById((string)$p['uid']);
}

/**
 * Sessions made before the second step existed (or by any path that skipped it): move them
 * back to "pending" so the next page asks for the code. Called from auth.php on every load.
 */
function mfa_enforce_session(): void {
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) return;
    $uid = (string)($_SESSION['user_id'] ?? '');
    if ($uid === '' || ($_SESSION['mfa_ok'] ?? '') === $uid) return;
    $user = findUserById($uid);
    if (!$user || !mfa_required($user)) return;
    if (mfa_device_trusted($user)) { $_SESSION['mfa_ok'] = $uid; return; }
    foreach (['user_id', 'user_role', 'user_email', 'user_display_name', 'login_time', 'mfa_ok', 'admin_logged_in'] as $k) unset($_SESSION[$k]);
    $_SESSION['mfa_pending'] = ['uid' => $uid, 'at' => time(), 'via' => 'session', 'remember' => false,
                                'redirect' => (string)($_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php')];
}

}
