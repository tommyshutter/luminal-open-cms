<?php
/**
 * Admin session bootstrap — long-lived so a full workday of admin work isn't
 * logged out from under you.
 *
 * @file /admin/includes/session_boot.php
 *
 * Sets a 12-hour GC lifetime + a persistent 12-hour cookie BEFORE session_start().
 * PHP's defaults (session.gc_maxlifetime≈24min, browser-session cookie) were what
 * actually logged admins out ~hourly — NOT the IP whitelist (a separate system).
 *
 * Idempotent + guarded: require_once this BEFORE any session_start(). If a session
 * is already active the params are left alone (the first include already set them).
 * "Remember me" at login still upgrades the cookie to 30 days on top of this.
 */
if (session_status() === PHP_SESSION_NONE) {
    // Admin session lifetime is CONFIGURABLE in Site Settings → "Admin Session" panel:
    // site-settings.json → admin_session_hours. Default 12h; clamped to 1–24h. Read the file directly
    // (this runs before auth.php/SITE_ROOT), path-relative to admin/includes/.
    $ttl = 60 * 60 * 12; // default: 12 hours (a workday)
    $cfgFile = __DIR__ . '/../data/site-settings.json';
    if (is_file($cfgFile)) {
        $cfg = json_decode((string)@file_get_contents($cfgFile), true);
        $hrs = (int)($cfg['admin_session_hours'] ?? 0);
        if ($hrs >= 1 && $hrs <= 24) $ttl = $hrs * 3600;
    }

    @ini_set('session.gc_maxlifetime', (string)$ttl);

    // Expose the resolved TTL so auth.php can stamp an expiry the UI can count down to,
    // and so the Dashboard strip can re-issue the cookie when the duration is changed.
    if (!defined('LUMINAL_SESSION_TTL')) define('LUMINAL_SESSION_TTL', $ttl);

    $p = session_get_cookie_params();
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    @session_set_cookie_params([
        'lifetime' => $ttl,
        'path'     => $p['path'] ?: '/',
        'domain'   => $p['domain'] ?? '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
