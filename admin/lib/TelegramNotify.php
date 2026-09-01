<?php
require_once __DIR__ . '/../config/hub.php';
/**
 * TelegramNotify — Alert system for Luminal CMS
 *
 * Severity-aware notifications with actionable links.
 * Green (info/success), Yellow (attention), Red (critical).
 *
 * Usage:
 *   require_once SITE_ROOT . '/admin/lib/TelegramNotify.php';
 *   tg_notify("Raw message");
 *   tg_alert('green', 'PODCAST GENERATED', $body, $links);
 *   tg_notify_lead($domain, $fields);
 *   tg_notify_ticket($ticket);
 *
 * @package  LuminalCMS
 * @version  2.0.0
 * @file     admin/lib/TelegramNotify.php
 * @date     2026-03-20
 */

function tg_load_config(): ?array
{
    // Config lives with the site. There is no shared fallback: a standalone
    // install has no other site to borrow credentials from.
    $paths = [];
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : '';
    if ($siteRoot) $paths[] = $siteRoot . '/admin/data/telegram/config.json';

    // Load through the credential vault where available so bot_token can be sealed
    // at rest. A cron chmods admin/data to 0777 every 5 minutes, so a plaintext
    // token here is world-readable regardless of how it is set. reveal() passes
    // UNSEALED values through untouched, so unmigrated sites behave identically.
    foreach ($paths as $p) {
        if (!is_file($p)) continue;

        $vault = dirname(__DIR__) . '/config/cred_vault.php';
        if (!function_exists('cred_load_json') && is_file($vault)) {
            require_once $vault;
        }

        try {
            $cfg = function_exists('cred_load_json')
                ? cred_load_json($p)
                : json_decode((string)file_get_contents($p), true);
        } catch (\Throwable $e) {
            // Fail closed: a tampered or undecryptable envelope must not fall back
            // to shipping ciphertext to Telegram as if it were a token.
            error_log('tg_load_config: ' . $e->getMessage());
            continue;
        }

        if ($cfg && !empty($cfg['bot_token']) && !empty($cfg['admin_chat_id'])) return $cfg;
    }
    return null;
}

/**
 * Send a raw text message to admin via Telegram.
 */
function tg_notify(string $message): bool
{
    return tg_send($message, 'text');
}

/**
 * Send a severity-aware alert with optional links.
 *
 * @param string $severity  'green' | 'yellow' | 'red'
 * @param string $title     Alert headline (e.g. "PODCAST GENERATED")
 * @param string $body      Alert details (multiline OK)
 * @param array  $links     Optional ['Label' => 'https://...'] action links
 */
function tg_alert(string $severity, string $title, string $body = '', array $links = []): bool
{
    $icons = ['green' => '🟢', 'yellow' => '🟡', 'red' => '🔴'];
    $icon = $icons[$severity] ?? '⚪';

    $msg = "<b>{$icon} {$title}</b>\n";
    if ($body) $msg .= "{$body}\n";

    if ($links) {
        $msg .= "\n";
        foreach ($links as $label => $url) {
            $msg .= "→ <a href=\"{$url}\">{$label}</a>\n";
        }
    }

    $msg .= "\n<i>" . date('M j, g:i A T') . "</i>";

    return tg_send($msg, 'HTML');
}

/**
 * Podcast episode generated alert.
 */
function tg_alert_podcast(string $domain, string $showLabel, string $episodeLabel, string $duration, array $hosts, string $pageUrl = ''): bool
{
    $hostStr = implode(' + ', $hosts);
    $body = "🎙 {$showLabel} — {$episodeLabel}\n";
    $body .= "Duration: {$duration} | Hosts: {$hostStr}";

    $links = [];
    if ($pageUrl) $links['Listen'] = $pageUrl;
    $links['Admin'] = "https://{$domain}/admin/modules/PodcastManager/PodcastManager.php";

    return tg_alert('green', 'PODCAST GENERATED', $body, $links);
}

/**
 * Deploy completed alert.
 */
function tg_alert_deploy(string $commit, int $siteCount, string $buildTime): bool
{
    $body = "Commit: {$commit}\n";
    $body .= "Sites: {$siteCount} | Build: {$buildTime}";

    return tg_alert('green', 'DEPLOY COMPLETE', $body, [
        'Hub' => lm_hub_url() . '/admin/',
    ]);
}

/**
 * Server unreachable alert.
 */
function tg_alert_unreachable(string $serverName, string $ip, string $checkType = 'heartbeat'): bool
{
    $body = "Server: {$serverName} ({$ip})\n";
    $body .= "Check: {$checkType}\n";
    $body .= "Self-mitigated — will retry next cycle";

    return tg_alert('yellow', 'SERVER UNREACHABLE', $body, [
        'ServerMonitor' => lm_hub_url() . '/admin/modules/ServerMonitor/ServerMonitor.php',
    ]);
}

/**
 * Firewall actually down alert.
 */
function tg_alert_firewall(string $serverName, string $ip): bool
{
    $body = "Server: {$serverName} ({$ip})\n";
    $body .= "UFW is INACTIVE — not a connectivity issue";

    return tg_alert('red', 'FIREWALL DOWN', $body, [
        'ServerSentinel' => "https://{$serverName}/admin/modules/ServerSentinel/ServerSentinel.php",
    ]);
}

/**
 * Enforcement quarantine alert.
 */
function tg_alert_quarantine(string $domain, array $modules): bool
{
    $body = "Site: {$domain}\n";
    $body .= "Quarantined: " . implode(', ', $modules);

    return tg_alert('red', 'MODULES QUARANTINED', $body, [
        'Site Admin' => "https://{$domain}/admin/",
    ]);
}

/**
 * Page updated alert.
 */
function tg_alert_page(string $domain, string $pageTitle, string $slug): bool
{
    return tg_alert('green', 'PAGE UPDATED', "📄 {$pageTitle}", [
        'View' => "https://{$domain}/page.php?p={$slug}",
        'Edit' => "https://{$domain}/admin/modules/PageManager/PageManager.php",
    ]);
}

/**
 * Lead/contact received alert.
 */
function tg_notify_lead(string $domain, array $fields, string $sourceSite = ''): bool
{
    $body = "Site: {$domain}\n";
    if ($sourceSite) $body .= "From: {$sourceSite}\n";

    foreach ($fields as $k => $v) {
        if (in_array($k, ['utm_source', 'utm_medium', 'utm_campaign', 'source_url', 'honeypot', 'action', 'form_slug', 'tags'])) continue;
        if ($v === '' || $v === null) continue;
        $label = ucfirst(str_replace('_', ' ', $k));
        $body .= "{$label}: {$v}\n";
    }

    $links = ['Site' => "https://{$domain}/"];

    return tg_alert('green', 'NEW LEAD', $body, $links);
}

/**
 * AgentScheduler task failure alert.
 *
 * Reports the EXACT error message from the failing pipeline so we can
 * triage in Telegram without opening the run log. Severity escalates
 * with consecutive failure count: yellow first, red on 2+ in a row.
 */
function tg_notify_agent_failure(
    string $taskId,
    string $taskName,
    string $pipeline,
    string $errorMsg,
    ?string $failedStep = null,
    int $consecutiveErrors = 1,
    ?string $providerKey = null
): bool {
    $severity = $consecutiveErrors >= 2 ? 'red' : 'yellow';
    $title = $consecutiveErrors >= 2 ? 'AGENT TASK FAILING (repeat)' : 'AGENT TASK FAILED';

    // Telegram caps messages around 4096 chars; reserve room for envelope
    $errClipped = strlen($errorMsg) > 1500 ? substr($errorMsg, 0, 1500) . '…' : $errorMsg;

    $body  = "Task: " . htmlspecialchars($taskName ?: $taskId, ENT_QUOTES, 'UTF-8') . "\n";
    $body .= "Pipeline: " . htmlspecialchars($pipeline, ENT_QUOTES, 'UTF-8') . "\n";
    if ($failedStep) {
        $body .= "Failed step: " . htmlspecialchars($failedStep, ENT_QUOTES, 'UTF-8') . "\n";
    }
    if ($providerKey) {
        $body .= "Provider: " . htmlspecialchars($providerKey, ENT_QUOTES, 'UTF-8') . "\n";
    }
    if ($consecutiveErrors > 1) {
        $body .= "Consecutive failures: {$consecutiveErrors}\n";
    }
    $body .= "\n<b>Error:</b>\n<code>" . htmlspecialchars($errClipped, ENT_QUOTES, 'UTF-8') . "</code>";

    return tg_alert($severity, $title, $body, [
        'AgentScheduler' => lm_hub_url() . '/admin/modules/AgentScheduler/AgentScheduler.php',
    ]);
}

/**
 * Tech support ticket alert.
 */
function tg_notify_ticket(array $ticket): bool
{
    $domain = $ticket['site'] ?? $ticket['domain'] ?? 'unknown';
    $body = "ID: " . ($ticket['id'] ?? '?') . "\n";
    $body .= "Site: {$domain}\n";
    $body .= "Category: " . ($ticket['category'] ?? 'general') . "\n";
    $body .= "Priority: " . ($ticket['priority'] ?? 'normal') . "\n\n";

    $desc = $ticket['description'] ?? $ticket['message'] ?? '';
    if (strlen($desc) > 400) $desc = substr($desc, 0, 400) . '...';
    $body .= $desc;

    return tg_alert('yellow', 'SUPPORT TICKET', $body, [
        'Dashboard' => "https://{$domain}/admin/modules/TechSupport/TechSupport.php",
    ]);
}

/**
 * Internal: send message via Telegram Bot API.
 *
 * @param string $message  Message text
 * @param string $parseMode 'text' | 'HTML' | 'Markdown'
 */
function tg_send(string $message, string $parseMode = 'text'): bool
{
    $cfg = tg_load_config();
    if (!$cfg) return false;

    $payload = [
        'chat_id' => $cfg['admin_chat_id'],
        'text'    => $message,
        'disable_web_page_preview' => false,
    ];
    if ($parseMode !== 'text') {
        $payload['parse_mode'] = $parseMode;
    }

    $url = "https://api.telegram.org/bot{$cfg['bot_token']}/sendMessage";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Append to local JSONL archive — synced to gdrive:LUMINAL/SYSTEM_LOGS/ by cron
    // Archive locally. Previously hardcoded to the hub's own filesystem path,
    // which silently failed on every site that is not the hub.
    $archivePath = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 2)) . '/admin/data/telegram/archive.jsonl';
    $entry = json_encode([
        'ts'      => date('c'),
        'status'  => $code === 200 ? 'sent' : 'failed',
        'mode'    => $parseMode,
        'text'    => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($archivePath, $entry . "\n", FILE_APPEND | LOCK_EX);

    return $code === 200;
}
