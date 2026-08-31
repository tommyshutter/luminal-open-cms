<?php
/**
 * IP Whitelist AJAX API — Auth-gated endpoint for dashboard whitelist operations
 *
 * @file /admin/ajax/ip-whitelist-api.php
 * @version 2026.03.01
 *
 * Actions: status, whitelist_ip, extend
 *
 * whitelist_ip / extend accept an optional `minutes` param (GET or POST).
 * Only the preset durations below are honored; anything else falls back to 60.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

require_once SITE_ROOT . '/admin/includes/auth.php';
requireAuth();

require_once SITE_ROOT . '/admin/includes/IPWhitelistHelper.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// Take the first IP if X-Forwarded-For contains a chain
if (strpos($clientIp, ',') !== false) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}

// Allowed whitelist durations (minutes). Anything else → 60 min default.
const IPWL_ALLOWED_MINUTES = [60, 180, 360, 480];
function ipwl_resolve_minutes(): int {
    $raw = $_POST['minutes'] ?? $_GET['minutes'] ?? 60;
    $min = (int)$raw;
    return in_array($min, IPWL_ALLOWED_MINUTES, true) ? $min : 60;
}
function ipwl_label(int $minutes): string {
    return $minutes % 60 === 0 ? ($minutes / 60) . ' hr' . ($minutes === 60 ? '' : 's') : $minutes . ' min';
}

switch ($action) {

    case 'status':
        $entry = ipwl_get_entry($clientIp);
        $remaining = $entry ? ipwl_remaining_seconds($clientIp) : 0;
        echo json_encode([
            'ok'          => true,
            'ip'          => $clientIp,
            'whitelisted' => $entry !== null,
            'entry'       => $entry,
            'remaining'   => $remaining,
        ]);
        break;

    case 'whitelist_ip':
        $ttl = ipwl_resolve_minutes();
        $label = ipwl_label($ttl);
        $entry = ipwl_add($clientIp, "Admin self-whitelist via dashboard ({$label})", $ttl, 'dashboard');

        // Attempt UFW allow if on a server with ufw
        $ufwResult = null;
        if (is_executable('/usr/sbin/ufw')) {
            $escapedIp = escapeshellarg($clientIp);
            $cmd = "sudo /usr/sbin/ufw allow from {$escapedIp} comment 'CMS-whitelist-temp' 2>&1";
            $ufwResult = trim(shell_exec($cmd) ?? '');
        }

        // Send email alert
        ipwl_send_alert($clientIp, "IP whitelisted via dashboard ({$label})", 'dashboard');

        echo json_encode([
            'ok'      => true,
            'message' => "IP {$clientIp} whitelisted for {$label}",
            'ttl'     => $ttl,
            'entry'   => $entry,
            'ufw'     => $ufwResult,
        ]);
        break;

    case 'extend':
        $ttl = ipwl_resolve_minutes();
        $label = ipwl_label($ttl);
        $entry = ipwl_add($clientIp, "Extended whitelist via dashboard ({$label})", $ttl, 'dashboard');

        echo json_encode([
            'ok'      => true,
            'message' => "Whitelist set for {$label}",
            'ttl'     => $ttl,
            'entry'   => $entry,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Unknown action: {$action}"]);
}
