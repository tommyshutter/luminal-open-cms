<?php
/**
 * Dashboard Whitelist Cleanup Cron
 *
 * @file /admin/modules/Dashboard/dashboard-whitelist-cron.php
 * @version 2026.03.01
 *
 * Prunes expired CMS whitelist entries and removes matching UFW rules.
 * Intended to run hourly via cron.
 *
 * Cron example:
 *   0 * * * * php /path/to/site/admin/modules/Dashboard/dashboard-whitelist-cron.php
 */
declare(strict_types=1);

// CLI-only guard
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));

require_once SITE_ROOT . '/admin/includes/IPWhitelistHelper.php';

$now = date('Y-m-d H:i:s');

// Load entries before pruning to identify expired IPs for UFW cleanup
$entries = ipwl_load();
$expiredIps = [];
$time = time();

foreach ($entries as $e) {
    if (!empty($e['expires_at']) && strtotime($e['expires_at']) <= $time) {
        $expiredIps[] = $e['ip'];
    }
}

// Prune expired from CMS whitelist
$pruned = ipwl_prune_expired();

// Remove expired IPs from UFW if available
$ufwRemoved = 0;
if (!empty($expiredIps) && is_executable('/usr/sbin/ufw')) {
    foreach ($expiredIps as $ip) {
        $escapedIp = escapeshellarg($ip);
        // Delete all UFW rules for this IP (numbered delete is fragile — use ufw delete allow)
        @shell_exec("sudo /usr/sbin/ufw delete allow from {$escapedIp} 2>&1");
        $ufwRemoved++;
    }
}

// Also prune expired emergency tokens
$tokenFile = SITE_ROOT . '/admin/data/emergency_tokens.json';
if (is_file($tokenFile)) {
    $tokens = json_decode(file_get_contents($tokenFile), true) ?: [];
    $before = count($tokens);
    $tokens = array_filter($tokens, fn($t) => strtotime($t['expires_at'] ?? '0') > $time && empty($t['used']));
    if (count($tokens) < $before) {
        file_put_contents($tokenFile, json_encode(array_values($tokens), JSON_PRETTY_PRINT));
        @chmod($tokenFile, 0664);
        @chown($tokenFile, 'www-data');
        @chgrp($tokenFile, 'www-data');
    }
    $tokensPruned = $before - count($tokens);
} else {
    $tokensPruned = 0;
}

// Prune rate limit file too
$rateFile = SITE_ROOT . '/admin/data/emergency_ratelimit.json';
if (is_file($rateFile)) {
    $rateData = json_decode(file_get_contents($rateFile), true) ?: [];
    $windowStart = $time - 3600;
    $rateData = array_filter($rateData, fn($e) => ($e['time'] ?? 0) > $windowStart);
    file_put_contents($rateFile, json_encode(array_values($rateData), JSON_PRETTY_PRINT));
    @chmod($rateFile, 0664);
    @chown($rateFile, 'www-data');
    @chgrp($rateFile, 'www-data');
}

echo "[{$now}] Whitelist cron: {$pruned} expired entries pruned, {$ufwRemoved} UFW rules removed, {$tokensPruned} tokens cleaned\n";
