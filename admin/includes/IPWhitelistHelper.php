<?php
/**
 * IP Whitelist Helper — CMS-level IP whitelist with optional UFW integration
 *
 * @file /admin/includes/IPWhitelistHelper.php
 * @version 2026.03.01
 *
 * Data: admin/data/ip_whitelist.json (per-site, rsync-excluded via admin/data/ blanket)
 * Each entry: { ip, added_at, expires_at, reason, source }
 */
declare(strict_types=1);

function ipwl_data_file(): string {
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/../..');
    $dir = $siteRoot . '/admin/data';
    return $dir . '/ip_whitelist.json';
}

function ipwl_load(): array {
    $file = ipwl_data_file();
    if (!is_file($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function ipwl_save(array $entries): void {
    $file = ipwl_data_file();
    file_put_contents($file, json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($file, 0664);
    @chown($file, 'www-data');
    @chgrp($file, 'www-data');
}

/**
 * Check if an IP is currently whitelisted (not expired)
 */
function ipwl_is_whitelisted(string $ip): bool {
    $entry = ipwl_get_entry($ip);
    return $entry !== null;
}

/**
 * Get a non-expired whitelist entry for an IP, or null
 */
function ipwl_get_entry(string $ip): ?array {
    $entries = ipwl_load();
    $now = time();
    foreach ($entries as $e) {
        if ($e['ip'] === $ip) {
            // Permanent entry (no expires_at) or not yet expired
            if (empty($e['expires_at']) || strtotime($e['expires_at']) > $now) {
                return $e;
            }
        }
    }
    return null;
}

/**
 * Add or refresh an IP in the whitelist
 *
 * @param string $ip         IP address
 * @param string $reason     Human-readable reason
 * @param int    $ttlMinutes TTL in minutes (0 = permanent)
 * @param string $source     Who added it: "dashboard", "emergency", "cron", "manual"
 * @return array The created/updated entry
 */
function ipwl_add(string $ip, string $reason = 'Admin whitelist', int $ttlMinutes = 60, string $source = 'dashboard'): array {
    $entries = ipwl_load();
    $now = time();
    $expiresAt = $ttlMinutes > 0 ? date('c', $now + ($ttlMinutes * 60)) : null;

    // Remove existing entry for this IP (refresh)
    $entries = array_filter($entries, fn($e) => $e['ip'] !== $ip);

    $entry = [
        'ip'         => $ip,
        'added_at'   => date('c', $now),
        'expires_at' => $expiresAt,
        'reason'     => $reason,
        'source'     => $source,
    ];
    $entries[] = $entry;

    ipwl_save($entries);
    return $entry;
}

/**
 * Remove expired entries. Returns count of pruned entries.
 */
function ipwl_prune_expired(): int {
    $entries = ipwl_load();
    $now = time();
    $before = count($entries);

    $entries = array_filter($entries, function($e) use ($now) {
        if (empty($e['expires_at'])) return true; // permanent
        return strtotime($e['expires_at']) > $now;
    });

    $pruned = $before - count($entries);
    if ($pruned > 0) {
        ipwl_save($entries);
    }
    return $pruned;
}

/**
 * Get remaining seconds for an IP's whitelist entry (0 if not found or permanent)
 */
function ipwl_remaining_seconds(string $ip): int {
    $entry = ipwl_get_entry($ip);
    if (!$entry) return 0;
    if (empty($entry['expires_at'])) return 0; // permanent
    $remaining = strtotime($entry['expires_at']) - time();
    return max(0, $remaining);
}

/**
 * Remove a specific IP from the whitelist
 */
function ipwl_remove(string $ip): bool {
    $entries = ipwl_load();
    $before = count($entries);
    $entries = array_filter($entries, fn($e) => $e['ip'] !== $ip);
    if (count($entries) < $before) {
        ipwl_save($entries);
        return true;
    }
    return false;
}

/**
 * Send email alert via site's Mailgun config (if available)
 */
function ipwl_send_alert(string $ip, string $reason, string $source): void {
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/../..');
    $funcFile = $siteRoot . '/admin/modules/MailgunManager/MailgunManagerFunctions.php';
    if (!is_file($funcFile)) return;

    require_once $funcFile;
    $cfg = mgm_load_config();
    if (empty($cfg['api_key']) || empty($cfg['domain'])) return;

    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    $from = ($cfg['from_name'] ? "{$cfg['from_name']} <{$cfg['from_email']}>" : $cfg['from_email']) ?: "noreply@{$cfg['domain']}";

    // Find superadmin email(s)
    $usersFile = $siteRoot . '/admin/data/users.json';
    $recipients = [];
    if (is_file($usersFile)) {
        $users = json_decode(file_get_contents($usersFile), true);
        foreach (($users['users'] ?? []) as $u) {
            if (($u['role'] ?? '') === 'superadmin' && ($u['status'] ?? '') === 'active') {
                $recipients[] = $u['email'];
            }
        }
    }
    if (empty($recipients)) return;

    $base = mgm_api_base($cfg['region']);
    $time = date('Y-m-d H:i:s T');

    $html = "<h2>IP Whitelist Alert</h2>"
        . "<p><strong>IP Address:</strong> {$ip}</p>"
        . "<p><strong>Action:</strong> {$reason}</p>"
        . "<p><strong>Source:</strong> {$source}</p>"
        . "<p><strong>Server:</strong> {$host}</p>"
        . "<p><strong>Time:</strong> {$time}</p>"
        . "<p style='color:#888;font-size:12px;'>This is an automated security alert from Luminal CMS.</p>";

    foreach ($recipients as $to) {
        mgm_request("$base/{$cfg['domain']}/messages", $cfg['api_key'], 'POST', [
            'from'    => $from,
            'to'      => $to,
            'subject' => "[Security] IP Whitelisted on {$host} — {$ip}",
            'html'    => $html,
            'text'    => "IP Whitelist Alert\nIP: {$ip}\nAction: {$reason}\nSource: {$source}\nServer: {$host}\nTime: {$time}",
        ]);
    }
}
