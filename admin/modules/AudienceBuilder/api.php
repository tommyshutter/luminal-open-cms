<?php
/**
 * AudienceBuilder — Admin API
 *
 * Actions: get_config, save_config, list_leads, get_lead, delete_lead,
 *          stats, export_leads, get_mode,
 *          list_sent, sent_stats, retry_to_hub, flush_sent_queue,
 *          delete_sent, export_sent, lead_print_html, list_connectors
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__, 3));

require_once __DIR__ . '/../_runtime/guard.php';
guard_require_auth();

header('Content-Type: application/json; charset=utf-8');

function ab_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ── Data directories ──

$dataDir  = SITE_ROOT . '/admin/data/AudienceBuilder';
$leadsDir = $dataDir . '/leads';
$sentDir  = $dataDir . '/sent';
$formsDir = $dataDir . '/forms';

if (!is_dir($dataDir))  @mkdir($dataDir, 0775, true);
if (!is_dir($leadsDir)) @mkdir($leadsDir, 0775, true);
if (!is_dir($sentDir))  @mkdir($sentDir, 0775, true);
if (!is_dir($formsDir)) @mkdir($formsDir, 0775, true);

$configFile = $dataDir . '/config.json';

function ab_load_config(string $file): array {
    if (!is_file($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function ab_save_config(string $file, array $config): void {
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($file, 0664);
}

/**
 * Detect hub vs node mode from config.
 */
function ab_get_mode(array $config): array {
    $hubUrl = trim($config['hub_url'] ?? '');
    $isNode = $hubUrl !== '' && !empty($config['hub_enabled']);
    return [
        'mode'    => $isNode ? 'node' : 'hub',
        'hub_url' => $isNode ? $hubUrl : null,
    ];
}

/**
 * Load leads from monthly files.
 */
function ab_load_leads(string $leadsDir, ?string $month = null, ?string $domain = null, ?string $source = null, ?string $search = null): array {
    $leads = [];
    $files = glob($leadsDir . '/*.json') ?: [];
    rsort($files); // newest month first

    foreach ($files as $file) {
        $basename = basename($file, '.json');
        if ($month !== null && $basename !== $month) continue;

        $data = json_decode(file_get_contents($file), true) ?: [];
        foreach ($data as $lead) {
            if ($domain !== null && ($lead['domain'] ?? '') !== $domain) continue;
            if ($source !== null && ($lead['source_site'] ?? '') !== $source) continue;
            if ($search !== null && $search !== '') {
                $haystack = strtolower(($lead['fields']['name'] ?? '') . ' ' . ($lead['fields']['email'] ?? ''));
                if (strpos($haystack, strtolower($search)) === false) continue;
            }
            $leads[] = $lead;
        }
    }

    // Sort newest first
    usort($leads, fn($a, $b) => ($b['submitted_at'] ?? '') <=> ($a['submitted_at'] ?? ''));
    return $leads;
}

/**
 * Load sent records from monthly files.
 */
function ab_load_sent(string $sentDir, ?string $month = null, ?string $status = null): array {
    $records = [];
    $files = glob($sentDir . '/*.json') ?: [];
    rsort($files);

    foreach ($files as $file) {
        $basename = basename($file, '.json');
        if ($month !== null && $basename !== $month) continue;

        $data = json_decode(file_get_contents($file), true) ?: [];
        foreach ($data as $record) {
            if ($status !== null && ($record['hub_status'] ?? '') !== $status) continue;
            $records[] = $record;
        }
    }

    usort($records, fn($a, $b) => ($b['submitted_at'] ?? '') <=> ($a['submitted_at'] ?? ''));
    return $records;
}

/**
 * Update a lead in its monthly file by ID.
 */
function ab_update_lead(string $leadsDir, string $leadId, array $updates): bool {
    $files = glob($leadsDir . '/*.json') ?: [];
    foreach ($files as $file) {
        $leads = json_decode(file_get_contents($file), true) ?: [];
        $found = false;
        foreach ($leads as &$lead) {
            if (($lead['id'] ?? '') === $leadId) {
                $lead = array_merge($lead, $updates);
                $found = true;
                break;
            }
        }
        unset($lead);
        if ($found) {
            file_put_contents($file, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            @chmod($file, 0664);
            return true;
        }
    }
    return false;
}

/**
 * Update a sent record in its monthly file by ID.
 */
function ab_update_sent(string $sentDir, string $sentId, array $updates): bool {
    $files = glob($sentDir . '/*.json') ?: [];
    foreach ($files as $file) {
        $records = json_decode(file_get_contents($file), true) ?: [];
        $found = false;
        foreach ($records as &$record) {
            if (($record['id'] ?? '') === $sentId) {
                $record = array_merge($record, $updates);
                $found = true;
                break;
            }
        }
        unset($record);
        if ($found) {
            file_put_contents($file, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            @chmod($file, 0664);
            return true;
        }
    }
    return false;
}

/**
 * Delete a lead by ID.
 */
function ab_delete_lead_by_id(string $leadsDir, string $leadId): bool {
    $files = glob($leadsDir . '/*.json') ?: [];
    foreach ($files as $file) {
        $leads = json_decode(file_get_contents($file), true) ?: [];
        $filtered = array_values(array_filter($leads, fn($l) => ($l['id'] ?? '') !== $leadId));
        if (count($filtered) !== count($leads)) {
            file_put_contents($file, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            @chmod($file, 0664);
            return true;
        }
    }
    return false;
}

/**
 * Delete a sent record by ID.
 */
function ab_delete_sent_by_id(string $sentDir, string $sentId): bool {
    $files = glob($sentDir . '/*.json') ?: [];
    foreach ($files as $file) {
        $records = json_decode(file_get_contents($file), true) ?: [];
        $filtered = array_values(array_filter($records, fn($r) => ($r['id'] ?? '') !== $sentId));
        if (count($filtered) !== count($records)) {
            file_put_contents($file, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            @chmod($file, 0664);
            return true;
        }
    }
    return false;
}

/**
 * Discover domains on this server.
 */
function ab_discover_domains(): array {
    $vhostsDir = '/var/www/vhosts';
    $domains = [];
    foreach (scandir($vhostsDir) as $entry) {
        if ($entry === '.' || $entry === '..' || strpos($entry, '.') === false) continue;
        if (!is_dir($vhostsDir . '/' . $entry . '/admin')) continue;
        $domains[] = $entry;
    }
    sort($domains);
    return $domains;
}

/**
 * Forward a sent record to the hub.
 */
function ab_forward_to_hub(array $record, string $hubUrl): array {
    $forwardUrl = rtrim($hubUrl, '/') . '/panels/ab-submit.php';
    $payload = [
        'action'      => 'submit_lead',
        'source_site' => $record['domain'] ?? '',
    ];
    // Merge in the form fields
    foreach (($record['fields'] ?? []) as $k => $v) {
        $payload[$k] = $v;
    }

    $ch = curl_init($forwardUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $body    = curl_exec($ch);
    $code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if ($curlErr !== '') {
        return ['hub_status' => 'hub_error', 'hub_error' => 'cURL: ' . $curlErr, 'hub_response' => null, 'lead_id' => null];
    }
    if ($code >= 200 && $code < 300) {
        $resp = json_decode((string)$body, true) ?: [];
        if (!empty($resp['ok'])) {
            return [
                'hub_status'   => 'delivered',
                'hub_error'    => null,
                'lead_id'      => $resp['lead_id'] ?? null,
                'hub_response' => ['status' => $resp['status'] ?? null, 'lead_id' => $resp['lead_id'] ?? null],
            ];
        }
        return ['hub_status' => 'hub_failed', 'hub_error' => $resp['error'] ?? 'Hub returned error', 'hub_response' => $resp, 'lead_id' => null];
    }
    return ['hub_status' => 'hub_error', 'hub_error' => "Hub HTTP {$code}", 'hub_response' => null, 'lead_id' => null];
}

// ── Route ──

try {

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ══════════════════════════════════════════════════════
    // MODE DETECTION
    // ══════════════════════════════════════════════════════

    case 'get_mode':
        $config = ab_load_config($configFile);
        $mode = ab_get_mode($config);
        ab_json(['ok' => true, ...$mode]);
        break;

    // ══════════════════════════════════════════════════════
    // CONFIG
    // ══════════════════════════════════════════════════════

    case 'get_config':
        $config = ab_load_config($configFile);
        $config['_domains'] = ab_discover_domains();
        $config['_mode'] = ab_get_mode($config);
        ab_json(['ok' => true, 'config' => $config]);
        break;

    case 'save_config':
        $config = ab_load_config($configFile);

        // Mailgun notification settings
        if (isset($input['mailgun'])) {
            $config['mailgun'] = [
                'notify_enabled' => !empty($input['mailgun']['notify_enabled']),
                'notify_to'      => trim($input['mailgun']['notify_to'] ?? ''),
                'notify_subject' => trim($input['mailgun']['notify_subject'] ?? 'New Lead from {domain}'),
            ];
        }

        // Enabled flag
        if (isset($input['enabled'])) {
            $config['enabled'] = !empty($input['enabled']);
        }
        if (isset($input['store_leads'])) {
            $config['store_leads'] = !empty($input['store_leads']);
        }

        // Hub URL (node mode config)
        if (isset($input['hub_url'])) {
            $config['hub_url'] = trim($input['hub_url']);
        }
        if (isset($input['hub_enabled'])) {
            $config['hub_enabled'] = !empty($input['hub_enabled']);
        }

        ab_save_config($configFile, $config);
        ab_json(['ok' => true]);
        break;

    // ══════════════════════════════════════════════════════
    // LEADS TAB
    // ══════════════════════════════════════════════════════

    case 'list_leads':
        $month  = !empty($input['month'])  ? $input['month']  : (!empty($_GET['month'])  ? $_GET['month']  : null);
        $domain = !empty($input['domain']) ? $input['domain'] : (!empty($_GET['domain']) ? $_GET['domain'] : null);
        $source = !empty($input['source']) ? $input['source'] : (!empty($_GET['source']) ? $_GET['source'] : null);
        $search = !empty($input['search']) ? $input['search'] : (!empty($_GET['search']) ? $_GET['search'] : null);
        $page   = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
        $limit  = 50;

        $all    = ab_load_leads($leadsDir, $month, $domain, $source, $search);
        $total  = count($all);
        $leads  = array_slice($all, ($page - 1) * $limit, $limit);

        ab_json([
            'ok'     => true,
            'leads'  => $leads,
            'total'  => $total,
            'page'   => $page,
            'pages'  => max(1, (int)ceil($total / $limit)),
        ]);
        break;

    case 'get_lead':
        $leadId = trim($input['id'] ?? $_GET['id'] ?? '');
        if ($leadId === '') ab_json(['ok' => false, 'error' => 'Lead ID required'], 400);

        $all = ab_load_leads($leadsDir);
        $found = null;
        foreach ($all as $lead) {
            if (($lead['id'] ?? '') === $leadId) { $found = $lead; break; }
        }

        if (!$found) ab_json(['ok' => false, 'error' => 'Lead not found'], 404);
        ab_json(['ok' => true, 'lead' => $found]);
        break;

    case 'delete_lead':
        $leadId = trim($input['id'] ?? '');
        if ($leadId === '') ab_json(['ok' => false, 'error' => 'Lead ID required'], 400);

        $deleted = ab_delete_lead_by_id($leadsDir, $leadId);
        ab_json(['ok' => $deleted, 'error' => $deleted ? null : 'Lead not found']);
        break;

    case 'stats':
        $all = ab_load_leads($leadsDir);
        $config = ab_load_config($configFile);
        $thisMonth = date('Y-m');
        $byDomain = [];
        $byStatus = ['stored' => 0, 'pending' => 0];
        $bySource = [];
        $monthCount = 0;

        foreach ($all as $lead) {
            $d = $lead['domain'] ?? 'unknown';
            $byDomain[$d] = ($byDomain[$d] ?? 0) + 1;

            $s = $lead['status'] ?? 'stored';
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;

            if (str_starts_with($lead['submitted_at'] ?? '', $thisMonth)) {
                $monthCount++;
            }

            $src = $lead['source_site'] ?? 'direct';
            $bySource[$src] = ($bySource[$src] ?? 0) + 1;
        }

        $mode = ab_get_mode($config);

        ab_json([
            'ok'         => true,
            'total'      => count($all),
            'this_month' => $monthCount,
            'by_domain'  => $byDomain,
            'by_status'  => $byStatus,
            'by_source'  => $bySource,
            'mode'       => $mode['mode'],
        ]);
        break;

    case 'export_leads':
        $domain = !empty($input['domain']) ? $input['domain'] : null;
        $search = !empty($input['search']) ? $input['search'] : null;
        $format = ($input['format'] ?? 'csv') === 'md' ? 'md' : 'csv';

        $leads = ab_load_leads($leadsDir, null, $domain, null, $search);

        if ($format === 'csv') {
            $lines = ["ID,Date,Domain,Source,Name,Email,Phone,Status,Email Status,Source URL"];
            foreach ($leads as $l) {
                $lines[] = implode(',', [
                    '"' . str_replace('"', '""', $l['id'] ?? '') . '"',
                    '"' . str_replace('"', '""', $l['submitted_at'] ?? '') . '"',
                    '"' . str_replace('"', '""', $l['domain'] ?? '') . '"',
                    '"' . str_replace('"', '""', $l['source_site'] ?? 'direct') . '"',
                    '"' . str_replace('"', '""', $l['fields']['name'] ?? '') . '"',
                    '"' . str_replace('"', '""', $l['fields']['email'] ?? '') . '"',
                    '"' . str_replace('"', '""', $l['fields']['phone'] ?? '') . '"',
                    '"' . str_replace('"', '""', $l['status'] ?? 'stored') . '"',
                    '"' . str_replace('"', '""', $l['email_status'] ?? 'skipped') . '"',
                    '"' . str_replace('"', '""', $l['source_url'] ?? '') . '"',
                ]);
            }
            ab_json(['ok' => true, 'format' => 'csv', 'content' => implode("\n", $lines), 'count' => count($leads)]);
        } else {
            $lines = ["# Audience Builder — Leads Export", ""];
            $lines[] = "**Exported:** " . date('Y-m-d H:i:s');
            if ($domain) $lines[] = "**Domain filter:** " . $domain;
            $lines[] = "**Total:** " . count($leads);
            $lines[] = "";
            $lines[] = "| Date | Domain | Source | Name | Email | Phone | Status |";
            $lines[] = "|------|--------|--------|------|-------|-------|--------|";
            foreach ($leads as $l) {
                $lines[] = sprintf("| %s | %s | %s | %s | %s | %s | %s |",
                    substr($l['submitted_at'] ?? '', 0, 16),
                    $l['domain'] ?? '',
                    $l['source_site'] ?? 'direct',
                    $l['fields']['name'] ?? '',
                    $l['fields']['email'] ?? '',
                    $l['fields']['phone'] ?? '',
                    $l['status'] ?? 'stored'
                );
            }
            ab_json(['ok' => true, 'format' => 'md', 'content' => implode("\n", $lines), 'count' => count($leads)]);
        }
        break;

    // ══════════════════════════════════════════════════════
    // SENT TAB (node mode)
    // ══════════════════════════════════════════════════════

    case 'list_sent':
        $month  = !empty($input['month'])  ? $input['month']  : (!empty($_GET['month'])  ? $_GET['month']  : null);
        $status = !empty($input['status']) ? $input['status'] : (!empty($_GET['status']) ? $_GET['status'] : null);
        $page   = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
        $limit  = 50;

        $all   = ab_load_sent($sentDir, $month, $status);
        $total = count($all);
        $items = array_slice($all, ($page - 1) * $limit, $limit);

        ab_json([
            'ok'    => true,
            'sent'  => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
        ]);
        break;

    case 'sent_stats':
        $all = ab_load_sent($sentDir);
        $thisMonth = date('Y-m');
        $byStatus = ['delivered' => 0, 'hub_failed' => 0, 'hub_error' => 0, 'pending' => 0];
        $monthCount = 0;

        foreach ($all as $r) {
            $s = $r['hub_status'] ?? 'pending';
            $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
            if (str_starts_with($r['submitted_at'] ?? '', $thisMonth)) {
                $monthCount++;
            }
        }

        ab_json([
            'ok'         => true,
            'total'      => count($all),
            'this_month' => $monthCount,
            'by_status'  => $byStatus,
        ]);
        break;

    case 'retry_to_hub':
        $sentId = trim($input['id'] ?? '');
        if ($sentId === '') ab_json(['ok' => false, 'error' => 'Sent ID required'], 400);

        $config = ab_load_config($configFile);
        $hubUrl = trim($config['hub_url'] ?? '');
        if ($hubUrl === '') ab_json(['ok' => false, 'error' => 'No hub URL configured'], 400);

        // Find the sent record
        $all = ab_load_sent($sentDir);
        $target = null;
        foreach ($all as $r) {
            if (($r['id'] ?? '') === $sentId) { $target = $r; break; }
        }
        if (!$target) ab_json(['ok' => false, 'error' => 'Sent record not found'], 404);

        // Forward to hub
        $result = ab_forward_to_hub($target, $hubUrl);

        $updates = [
            'hub_status'   => $result['hub_status'],
            'hub_error'    => $result['hub_error'],
            'hub_response' => $result['hub_response'],
            'lead_id'      => $result['lead_id'],
            'retries'      => ($target['retries'] ?? 0) + 1,
            'last_retry'   => date('c'),
        ];

        ab_update_sent($sentDir, $sentId, $updates);
        ab_json([
            'ok'         => $result['hub_status'] === 'delivered',
            'hub_status' => $result['hub_status'],
            'error'      => $result['hub_error'],
        ]);
        break;

    case 'flush_sent_queue':
        $config = ab_load_config($configFile);
        $hubUrl = trim($config['hub_url'] ?? '');
        if ($hubUrl === '') ab_json(['ok' => false, 'error' => 'No hub URL configured'], 400);

        $all = ab_load_sent($sentDir);
        $queued = array_filter($all, fn($r) => in_array($r['hub_status'] ?? 'pending', ['pending', 'hub_failed', 'hub_error']));

        if (empty($queued)) {
            ab_json(['ok' => true, 'processed' => 0, 'delivered' => 0, 'failed' => 0, 'message' => 'No sent leads to retry.']);
        }

        $processed = 0;
        $delivered = 0;
        $failed    = 0;

        foreach ($queued as $record) {
            $result = ab_forward_to_hub($record, $hubUrl);

            $updates = [
                'hub_status'   => $result['hub_status'],
                'hub_error'    => $result['hub_error'],
                'hub_response' => $result['hub_response'],
                'lead_id'      => $result['lead_id'],
                'retries'      => ($record['retries'] ?? 0) + 1,
                'last_retry'   => date('c'),
            ];
            ab_update_sent($sentDir, $record['id'], $updates);
            $processed++;

            if ($result['hub_status'] === 'delivered') {
                $delivered++;
            } else {
                $failed++;
            }

            if ($processed < count($queued)) usleep(200000);
        }

        ab_json([
            'ok'        => true,
            'processed' => $processed,
            'delivered' => $delivered,
            'failed'    => $failed,
        ]);
        break;

    case 'delete_sent':
        $sentId = trim($input['id'] ?? '');
        if ($sentId === '') ab_json(['ok' => false, 'error' => 'Sent ID required'], 400);

        $deleted = ab_delete_sent_by_id($sentDir, $sentId);
        ab_json(['ok' => $deleted, 'error' => $deleted ? null : 'Sent record not found']);
        break;

    case 'export_sent':
        $status = !empty($input['status']) ? $input['status'] : null;
        $format = ($input['format'] ?? 'csv') === 'md' ? 'md' : 'csv';

        $records = ab_load_sent($sentDir, null, $status);

        if ($format === 'csv') {
            $lines = ["ID,Date,Domain,Name,Email,Phone,Hub Status,Hub Error,Hub Lead ID,Retries"];
            foreach ($records as $r) {
                $lines[] = implode(',', [
                    '"' . str_replace('"', '""', $r['id'] ?? '') . '"',
                    '"' . str_replace('"', '""', $r['submitted_at'] ?? '') . '"',
                    '"' . str_replace('"', '""', $r['domain'] ?? '') . '"',
                    '"' . str_replace('"', '""', $r['fields']['name'] ?? '') . '"',
                    '"' . str_replace('"', '""', $r['fields']['email'] ?? '') . '"',
                    '"' . str_replace('"', '""', $r['fields']['phone'] ?? '') . '"',
                    '"' . str_replace('"', '""', $r['hub_status'] ?? 'pending') . '"',
                    '"' . str_replace('"', '""', $r['hub_error'] ?? '') . '"',
                    '"' . str_replace('"', '""', $r['lead_id'] ?? '') . '"',
                    '"' . ($r['retries'] ?? 0) . '"',
                ]);
            }
            ab_json(['ok' => true, 'format' => 'csv', 'content' => implode("\n", $lines), 'count' => count($records)]);
        } else {
            $lines = ["# Audience Builder — Sent Leads Export", ""];
            $lines[] = "**Exported:** " . date('Y-m-d H:i:s');
            if ($status) $lines[] = "**Status filter:** " . $status;
            $lines[] = "**Total:** " . count($records);
            $lines[] = "";
            $lines[] = "| Date | Domain | Name | Email | Hub Status | Retries |";
            $lines[] = "|------|--------|------|-------|------------|---------|";
            foreach ($records as $r) {
                $lines[] = sprintf("| %s | %s | %s | %s | %s | %d |",
                    substr($r['submitted_at'] ?? '', 0, 16),
                    $r['domain'] ?? '',
                    $r['fields']['name'] ?? '',
                    $r['fields']['email'] ?? '',
                    $r['hub_status'] ?? 'pending',
                    $r['retries'] ?? 0
                );
            }
            ab_json(['ok' => true, 'format' => 'md', 'content' => implode("\n", $lines), 'count' => count($records)]);
        }
        break;

    // ══════════════════════════════════════════════════════
    // LEAD VIEWER (print HTML for iframe)
    // ══════════════════════════════════════════════════════

    case 'lead_print_html':
        $leadId = trim($input['id'] ?? $_GET['id'] ?? '');
        $type   = trim($input['type'] ?? $_GET['type'] ?? 'lead');
        if ($leadId === '') ab_json(['ok' => false, 'error' => 'ID required'], 400);

        if ($type === 'sent') {
            $all = ab_load_sent($sentDir);
        } else {
            $all = ab_load_leads($leadsDir);
        }

        $found = null;
        foreach ($all as $item) {
            if (($item['id'] ?? '') === $leadId) { $found = $item; break; }
        }
        if (!$found) ab_json(['ok' => false, 'error' => 'Record not found'], 404);

        $h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $fields = $found['fields'] ?? [];
        $domain = $h($found['domain'] ?? 'unknown');

        // Extract subject-like fields
        $subjectKeys = ['subject', 'interest', 'regarding', 'inquiry_type', 'service', 'topic', 'category'];
        $subjectLabel = null;
        $subjectValue = null;
        foreach ($subjectKeys as $sk) {
            foreach ($fields as $k => $v) {
                if (strtolower($k) === $sk && trim($v) !== '') {
                    $subjectLabel = ucfirst(str_replace('_', ' ', $k));
                    $subjectValue = $v;
                    break 2;
                }
            }
        }

        // Extract message-like fields
        $messageKeys = ['message', 'description', 'comments', 'details', 'body', 'notes', 'inquiry', 'question'];
        $messageLabel = null;
        $messageValue = null;
        foreach ($messageKeys as $mk) {
            foreach ($fields as $k => $v) {
                if (strtolower($k) === $mk && trim($v) !== '') {
                    $messageLabel = ucfirst(str_replace('_', ' ', $k));
                    $messageValue = $v;
                    break 2;
                }
            }
        }

        // Contact fields
        $contactKeys = ['name', 'email', 'phone', 'company', 'organization', 'business'];
        $contactFields = [];
        $extraFields = [];

        foreach ($fields as $k => $v) {
            $kLower = strtolower($k);
            if ($subjectValue !== null && $v === $subjectValue && in_array($kLower, $subjectKeys)) continue;
            if ($messageValue !== null && $v === $messageValue && in_array($kLower, $messageKeys)) continue;
            // Skip UTM/tracking fields from display
            if (in_array($kLower, ['source_url', 'utm_source', 'utm_medium', 'utm_campaign'])) continue;

            if (in_array($kLower, $contactKeys)) {
                $contactFields[$k] = $v;
            } else {
                $extraFields[$k] = $v;
            }
        }

        // Format date
        $rawDate = $found['submitted_at'] ?? '';
        $fmtDate = $rawDate;
        if ($rawDate) {
            $dt = new \DateTime($rawDate);
            $fmtDate = $dt->format('M j, Y \a\t g:i A');
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<title>Lead ' . $h($found['id']) . '</title>';
        $html .= '<style>';
        $html .= '*{box-sizing:border-box}';
        $html .= 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:700px;margin:0 auto;padding:24px 20px;color:#222;line-height:1.6;font-size:16px}';
        $html .= '.lead-header{margin-bottom:22px;padding-bottom:18px;border-bottom:2px solid #e0e0e0}';
        $html .= '.lead-header h1{font-size:14px;color:#999;margin:0 0 6px;font-weight:500;text-transform:uppercase;letter-spacing:.5px}';
        $html .= '.lead-header .lead-from{font-size:26px;font-weight:700;color:#111;margin:0 0 4px}';
        $html .= '.lead-header .lead-subject{font-size:18px;color:#1565c0;margin:6px 0 0;font-weight:600}';
        $html .= '.lead-header .lead-source{font-size:22px;font-weight:700;color:#7b1fa2;margin:8px 0 0}';
        $html .= '.lead-header .lead-date{font-size:22px;font-weight:600;color:#333;margin:6px 0 0}';
        $html .= '.lead-header .lead-id{font-size:12px;color:#bbb;margin-top:6px}';
        $html .= '.contact-card{background:#f5f7fa;border:1px solid #e0e4ea;border-radius:8px;padding:16px 20px;margin-bottom:18px}';
        $html .= '.contact-card h3{font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:#888;margin:0 0 12px;font-weight:600}';
        $html .= '.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px}';
        $html .= '.contact-grid .cg-item{font-size:16px}';
        $html .= '.contact-grid .cg-label{font-weight:600;color:#555;font-size:12px;text-transform:uppercase;letter-spacing:.3px}';
        $html .= '.contact-grid .cg-value{color:#222;margin-top:2px;font-size:16px}';
        $html .= '.message-block{background:#fff;border:1px solid #e0e4ea;border-radius:8px;padding:18px 20px;margin-bottom:18px}';
        $html .= '.message-block h3{font-size:12px;text-transform:uppercase;letter-spacing:.8px;color:#888;margin:0 0 10px;font-weight:600}';
        $html .= '.message-block .msg-content{font-size:16px;color:#222;white-space:pre-wrap;line-height:1.7}';
        $html .= '.extras{margin-bottom:18px}';
        $html .= '.extras .ex-row{display:flex;gap:14px;padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:15px}';
        $html .= '.extras .ex-label{width:140px;flex-shrink:0;font-weight:600;color:#555;font-size:12px;text-transform:uppercase;letter-spacing:.3px;padding-top:3px}';
        $html .= '.extras .ex-value{color:#222;flex:1;font-size:15px}';
        $html .= '.status-details{background:#fafafa;border:1px solid #e8e8e8;border-radius:8px;margin-bottom:18px}';
        $html .= '.status-details summary{padding:14px 18px;cursor:pointer;font-size:15px;font-weight:600;color:#555;list-style:none;display:flex;align-items:center;gap:10px}';
        $html .= '.status-details summary::-webkit-details-marker{display:none}';
        $html .= '.status-details summary::before{content:"\\25B6";font-size:10px;color:#999;transition:transform .2s}';
        $html .= '.status-details[open] summary::before{transform:rotate(90deg)}';
        $html .= '.status-details .status-inner{padding:0 18px 14px;border-top:1px solid #e8e8e8}';
        $html .= '.status-grid{display:grid;grid-template-columns:140px 1fr;gap:6px 16px;margin-top:12px}';
        $html .= '.status-grid dt{font-weight:600;color:#555;font-size:12px;text-transform:uppercase;letter-spacing:.3px;padding-top:3px}';
        $html .= '.status-grid dd{margin:0;padding:3px 0;color:#222;font-size:15px}';
        $html .= '.badge{display:inline-block;padding:3px 12px;border-radius:4px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}';
        $html .= '.badge-stored{background:#e8f5e9;color:#2e7d32}';
        $html .= '.badge-pending{background:#fff3e0;color:#e65100}';
        $html .= '.badge-delivered{background:#e8f5e9;color:#2e7d32}';
        $html .= '.badge-hub_failed{background:#ffebee;color:#c62828}';
        $html .= '.badge-hub_error{background:#fff3e0;color:#e65100}';
        $html .= '.utm-block{background:#f5f5f5;border:1px solid #e0e0e0;border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#666}';
        $html .= '.utm-block strong{color:#444}';
        $html .= '.print-bar{text-align:center;margin:0 0 16px;padding:8px}';
        $html .= '.print-bar button{padding:8px 24px;font-size:14px;cursor:pointer;background:#1976d2;color:#fff;border:none;border-radius:4px}';
        $html .= '@media print{.print-bar{display:none}}';
        $html .= '.footer{font-size:11px;color:#aaa;text-align:center;margin-top:24px;padding-top:12px;border-top:1px solid #eee}';
        $html .= '</style></head><body>';

        $html .= '<div class="print-bar"><button onclick="window.print()">Print / Save as PDF</button></div>';

        // Header
        $html .= '<div class="lead-header">';
        $html .= '<h1>' . ($type === 'sent' ? 'Sent Lead' : 'Lead Record') . '</h1>';
        $name = $h($fields['name'] ?? $fields['Name'] ?? 'Unknown Contact');
        $html .= '<div class="lead-from">' . $name . '</div>';
        if (!empty($found['source_site']) && $found['source_site'] !== ($found['domain'] ?? '')) {
            $html .= '<div class="lead-source">via ' . $h($found['source_site']) . '</div>';
        }
        $html .= '<div class="lead-date">' . $h($fmtDate) . '</div>';
        if ($subjectValue !== null) {
            $html .= '<div class="lead-subject">' . $h($subjectLabel) . ': ' . $h($subjectValue) . '</div>';
        }
        $html .= '<div class="lead-id">' . $h($found['id']) . '</div>';
        $html .= '</div>';

        // Contact card
        if (!empty($contactFields)) {
            $html .= '<div class="contact-card"><h3>Contact Information</h3><div class="contact-grid">';
            foreach ($contactFields as $k => $v) {
                $html .= '<div class="cg-item"><div class="cg-label">' . $h(ucfirst(str_replace('_', ' ', $k))) . '</div>';
                $kLower = strtolower($k);
                if ($kLower === 'email' && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $html .= '<div class="cg-value"><a href="mailto:' . $h($v) . '" style="color:#1565c0">' . $h($v) . '</a></div>';
                } elseif ($kLower === 'phone') {
                    $html .= '<div class="cg-value"><a href="tel:' . $h(preg_replace('/[^\d+]/', '', $v)) . '" style="color:#1565c0">' . $h($v) . '</a></div>';
                } else {
                    $html .= '<div class="cg-value">' . $h($v) . '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }

        // Message block
        if ($messageValue !== null) {
            $html .= '<div class="message-block"><h3>' . $h($messageLabel) . '</h3>';
            $html .= '<div class="msg-content">' . $h($messageValue) . '</div></div>';
        }

        // Extra fields
        if (!empty($extraFields)) {
            $html .= '<div class="extras">';
            foreach ($extraFields as $k => $v) {
                $html .= '<div class="ex-row"><div class="ex-label">' . $h(ucfirst(str_replace('_', ' ', $k))) . '</div>';
                $html .= '<div class="ex-value">' . $h($v) . '</div></div>';
            }
            $html .= '</div>';
        }

        // UTM block (if any UTM data)
        $utm = $found['utm'] ?? [];
        if (!empty($utm['source']) || !empty($utm['medium']) || !empty($utm['campaign'])) {
            $html .= '<div class="utm-block">';
            $html .= '<strong>UTM Tracking:</strong> ';
            $parts = [];
            if (!empty($utm['source']))   $parts[] = 'source=' . $h($utm['source']);
            if (!empty($utm['medium']))   $parts[] = 'medium=' . $h($utm['medium']);
            if (!empty($utm['campaign'])) $parts[] = 'campaign=' . $h($utm['campaign']);
            $html .= implode(' | ', $parts);
            if (!empty($found['source_url'])) {
                $html .= '<br><strong>Source URL:</strong> ' . $h($found['source_url']);
            }
            $html .= '</div>';
        } elseif (!empty($found['source_url'])) {
            $html .= '<div class="utm-block"><strong>Source URL:</strong> ' . $h($found['source_url']) . '</div>';
        }

        // Status section
        if ($type === 'sent') {
            $hubStatus = $found['hub_status'] ?? 'pending';
            $html .= '<details class="status-details">';
            $html .= '<summary>Hub Delivery &mdash; <span class="badge badge-' . $h($hubStatus) . '">' . $h($hubStatus) . '</span></summary>';
            $html .= '<div class="status-inner"><dl class="status-grid">';
            $html .= '<dt>Hub URL</dt><dd>' . $h($found['hub_url'] ?? '—') . '</dd>';
            if (!empty($found['lead_id'])) {
                $html .= '<dt>Hub Lead ID</dt><dd>' . $h($found['lead_id']) . '</dd>';
            }
            if (!empty($found['hub_error'])) {
                $html .= '<dt>Hub Error</dt><dd style="color:#c62828">' . $h($found['hub_error']) . '</dd>';
            }
            $html .= '<dt>Retries</dt><dd>' . (int)($found['retries'] ?? 0) . '</dd>';
            if (!empty($found['last_retry'])) {
                $dt2 = new \DateTime($found['last_retry']);
                $html .= '<dt>Last Retry</dt><dd>' . $h($dt2->format('M j, Y \a\t g:i A')) . '</dd>';
            }
            $html .= '</dl></div></details>';
        } else {
            $leadStatus = $found['status'] ?? 'stored';
            $html .= '<details class="status-details">';
            $html .= '<summary>Status &mdash; <span class="badge badge-' . $h($leadStatus) . '">' . $h($leadStatus) . '</span></summary>';
            $html .= '<div class="status-inner"><dl class="status-grid">';
            $html .= '<dt>Email Status</dt><dd>' . $h($found['email_status'] ?? 'skipped') . '</dd>';
            if (!empty($found['source_site']) && $found['source_site'] !== ($found['domain'] ?? '')) {
                $html .= '<dt>Source Site</dt><dd>' . $h($found['source_site']) . '</dd>';
            }
            $html .= '<dt>Domain</dt><dd>' . $domain . '</dd>';
            $html .= '</dl></div></details>';
        }

        $html .= '<div class="footer">Audience Builder &mdash; ' . date('Y-m-d H:i:s') . '</div>';
        $html .= '</body></html>';

        $titleParts = [];
        if (!empty($fields['name'])) $titleParts[] = $fields['name'];
        if ($subjectValue !== null) $titleParts[] = $subjectValue;
        $viewerTitle = $titleParts ? implode(' — ', $titleParts) : $found['id'];

        ab_json(['ok' => true, 'html' => $html, 'title' => $viewerTitle]);
        break;

    // ══════════════════════════════════════════════════════
    // OUTPUT CONNECTORS
    // ══════════════════════════════════════════════════════

    case 'list_connectors':
        $connectors = [];
        $connectorDir = __DIR__ . '/connectors';
        if (is_dir($connectorDir)) {
            foreach (glob($connectorDir . '/*.php') as $file) {
                $className = 'AB_Connector_' . basename($file, '.php');
                require_once $file;
                if (class_exists($className)) {
                    $connectors[] = [
                        'id'   => $className::id(),
                        'name' => $className::name(),
                    ];
                }
            }
        }
        ab_json(['ok' => true, 'connectors' => $connectors]);
        break;

    // ══════════════════════════════════════════════════════
    // FORM BUILDER CRUD
    // ══════════════════════════════════════════════════════

    case 'create_basic_forms':
        // On-demand quickstart: create the two standard forms (contact + signup)
        // in the chosen style + enable the module. Idempotent (skips existing slugs).
        require_once __DIR__ . '/bootstrap.php';
        $style   = preg_replace('/[^a-z]/', '', strtolower((string)($input['style'] ?? 'default')));
        $pixel   = (string)($input['pixel'] ?? '');
        $created = ab_ensure_defaults(SITE_ROOT, $style, $pixel);
        $forms   = array_values(array_filter(array_map(
            fn($c) => (strpos($c, 'forms/') === 0) ? basename($c, '.json') : null,
            $created
        )));
        ab_json([
            'ok'      => true,
            'created' => $forms,                       // slugs actually created
            'skipped' => array_values(array_diff(['contact', 'signup'], $forms)),
            'style'   => $style,
        ]);
        break;

    case 'list_forms':
        $forms = [];
        foreach (glob($formsDir . '/*.json') as $file) {
            $f = json_decode(file_get_contents($file), true);
            if (!is_array($f)) continue;
            $forms[] = [
                'slug'        => $f['slug'] ?? basename($file, '.json'),
                'title'       => $f['title'] ?? '',
                'field_count' => count($f['fields'] ?? []),
                'updated_at'  => $f['updated_at'] ?? '',
            ];
        }
        usort($forms, fn($a, $b) => ($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? ''));
        ab_json(['ok' => true, 'forms' => $forms]);
        break;

    case 'get_form':
        $slug = preg_replace('/[^a-z0-9_-]/i', '', trim($input['slug'] ?? $_GET['slug'] ?? ''));
        if ($slug === '') ab_json(['ok' => false, 'error' => 'Slug required'], 400);
        $file = $formsDir . '/' . $slug . '.json';
        if (!is_file($file)) ab_json(['ok' => false, 'error' => 'Form not found'], 404);
        $form = json_decode(file_get_contents($file), true);
        ab_json(['ok' => true, 'form' => $form]);
        break;

    case 'save_form':
        $formData = $input['form'] ?? null;
        if (!is_array($formData)) ab_json(['ok' => false, 'error' => 'Form data required'], 400);

        $slug = preg_replace('/[^a-z0-9_-]/i', '', trim($formData['slug'] ?? ''));
        if ($slug === '') ab_json(['ok' => false, 'error' => 'Slug required'], 400);

        $fields = $formData['fields'] ?? [];
        if (empty($fields)) ab_json(['ok' => false, 'error' => 'At least one field is required'], 400);

        $file = $formsDir . '/' . $slug . '.json';
        $existing = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        $now = date('c');

        $form = [
            'id'              => $existing['id'] ?? ('f-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 6)),
            'slug'            => $slug,
            'title'           => trim($formData['title'] ?? 'Untitled Form'),
            'description'     => trim($formData['description'] ?? ''),
            'fields'          => $fields,
            'submit_text'     => trim($formData['submit_text'] ?? 'Submit'),
            'success_message' => trim($formData['success_message'] ?? 'Thank you! Your submission has been received.'),
            'layout'          => $formData['layout'] ?? 'stacked',
            'css_class'       => trim($formData['css_class'] ?? ''),
            'form_style'      => trim($formData['form_style'] ?? 'default'),
            'custom_css'      => $formData['custom_css'] ?? '',
            'display_mode'    => (($formData['display_mode'] ?? '') === 'lightbox') ? 'lightbox' : 'embedded',
            'trigger_label'   => trim($formData['trigger_label'] ?? ''),
            'tracking'        => [
                'pixel_id'         => trim($formData['tracking']['pixel_id'] ?? ''),
                'conversion_event' => trim($formData['tracking']['conversion_event'] ?? ''),
                'custom_params'    => (object)($formData['tracking']['custom_params'] ?? []),
            ],
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];

        file_put_contents($file, json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($file, 0664);

        ab_json(['ok' => true, 'slug' => $slug]);
        break;

    case 'delete_form':
        $slug = preg_replace('/[^a-z0-9_-]/i', '', trim($input['slug'] ?? ''));
        if ($slug === '') ab_json(['ok' => false, 'error' => 'Slug required'], 400);
        $file = $formsDir . '/' . $slug . '.json';
        if (!is_file($file)) ab_json(['ok' => false, 'error' => 'Form not found'], 404);
        @unlink($file);
        ab_json(['ok' => true]);
        break;

    default:
        ab_json(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
}

} catch (\Throwable $e) {
    ab_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
