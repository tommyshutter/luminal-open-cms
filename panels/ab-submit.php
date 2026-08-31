<?php
/**
 * AudienceBuilder — Public Form Submission Endpoint
 *
 * Accepts POST from frontend forms, stores lead locally,
 * forwards to hub (node mode), sends email notification (hub mode).
 *
 * Store-and-Forward (Node Mode):
 * When hub_url is configured, this endpoint operates as a node:
 * 1. Store lead locally in leads/ + sent/ directories
 * 2. Forward to hub's ab-submit.php via cURL
 * 3. Record hub response status locally
 *
 * Rate-limited: 20 submissions per IP per hour.
 * No auth required (public endpoint).
 *
 * @endpoint POST /panels/ab-submit.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__));

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function ab_submit_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ab_submit_json(['ok' => false, 'error' => 'POST required'], 405);
}

// ── Rate limiting (20/hour per IP) ──

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipHash   = substr(md5($clientIp . date('Y-m-d-H')), 0, 12);
$rateFile = sys_get_temp_dir() . '/ab_rate_' . $ipHash;
$rateCount = 0;

if (is_file($rateFile)) {
    $rateData = json_decode(file_get_contents($rateFile), true);
    if (($rateData['hour'] ?? '') === date('Y-m-d-H')) {
        $rateCount = (int)($rateData['count'] ?? 0);
    }
}

if ($rateCount >= 20) {
    ab_submit_json(['ok' => false, 'error' => 'Rate limit exceeded. Please try again later.'], 429);
}

// Increment rate counter
file_put_contents($rateFile, json_encode([
    'hour'  => date('Y-m-d-H'),
    'count' => $rateCount + 1,
]), LOCK_EX);

// ── Parse input ──

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Fallback to form-encoded
    $input = $_POST;
}

$action = $input['action'] ?? '';
if ($action !== 'submit_lead') {
    ab_submit_json(['ok' => false, 'error' => 'Invalid action'], 400);
}

// ── Validate required fields ──

$name  = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');

if ($name === '' || $email === '') {
    ab_submit_json(['ok' => false, 'error' => 'Name and email are required'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ab_submit_json(['ok' => false, 'error' => 'Invalid email address'], 400);
}

// ── Spam / jibberish guard (earliest server-side choke point) ──
// Reject bot submissions BEFORE any storage, hub-forward, or notification.
// Silent drop: return a success-shaped response so bots get no signal to adapt,
// but never store/forward/notify. Rejections are logged for false-positive audit.
$spamLib = SITE_ROOT . '/admin/lib/SpamGuard.php';
if (is_file($spamLib)) {
    require_once $spamLib;
    $spamReason = spamguard_check_lead($name, $email, is_array($input) ? $input : []);
    if ($spamReason !== null) {
        $rejDir = SITE_ROOT . '/admin/data/AudienceBuilder/spam_rejected';
        if (!is_dir($rejDir)) @mkdir($rejDir, 0775, true);
        $rejFile = $rejDir . '/' . date('Y-m') . '.json';
        $rejLog  = is_file($rejFile) ? (json_decode(file_get_contents($rejFile), true) ?: []) : [];
        $rejLog[] = [
            'rejected_at' => date('c'),
            'reason'      => $spamReason,
            'name'        => mb_substr($name, 0, 120),
            'email'       => mb_substr($email, 0, 160),
            'domain'      => preg_replace('/^www\./', '', strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown')),
            'ip_hash'     => substr(md5(($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')), 0, 8),
        ];
        file_put_contents($rejFile, json_encode($rejLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($rejFile, 0664);

        // ── Burst tripwire: a rash of rejections in a short window fires an
        // immediate "bot attack" alert (rate-limited to once per hour). ──
        $burstWindow   = 15 * 60;  // look-back window: 15 minutes
        $burstMin      = 8;        // rejections within window to trip
        $burstCooldown = 60 * 60;  // min seconds between burst alerts
        $nowTs  = time();
        $recent = 0;
        foreach ($rejLog as $r) {
            $rt = strtotime($r['rejected_at'] ?? '');
            if ($rt && ($nowTs - $rt) <= $burstWindow) $recent++;
        }
        if ($recent >= $burstMin) {
            $markFile  = $rejDir . '/.last_burst_alert';
            $lastAlert = is_file($markFile) ? (int)trim((string)@file_get_contents($markFile)) : 0;
            if (($nowTs - $lastAlert) >= $burstCooldown) {
                $tgLib = SITE_ROOT . '/admin/lib/TelegramNotify.php';
                if (is_file($tgLib)) {
                    require_once $tgLib;
                    if (function_exists('tg_alert')) {
                        $dom = preg_replace('/^www\./', '', strtolower($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown'));
                        tg_alert('warning', '🤖 Bot signup burst — ' . $dom,
                            "{$recent} jibberish signups blocked in the last 15 min.\nSpamGuard is dropping them silently — no action needed unless this persists.");
                    }
                }
                @file_put_contents($markFile, (string)$nowTs, LOCK_EX);
            }
        }

        ab_submit_json(['ok' => true, 'lead_id' => null, 'status' => 'received']);
    }
}

// ── Sanitize fields ──
// Accept all form fields dynamically (form builder creates arbitrary names).
// Skip internal/system keys and UTM/tracking (handled separately below).

$systemKeys = ['action', 'source_site', 'form_id', '_token'];
$utmKeys    = ['source_url', 'utm_source', 'utm_medium', 'utm_campaign'];

$fields = [];
foreach ($input as $key => $val) {
    if (!is_string($key) || !is_string($val)) continue;
    if (in_array($key, $systemKeys, true)) continue;
    $key = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
    if ($key === '' || strlen($key) > 64) continue;
    $val = trim($val);
    if ($val === '') continue;
    // Cap field value length (64 KB — generous for textareas)
    if (strlen($val) > 65536) $val = substr($val, 0, 65536);
    $fields[$key] = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

// ── Determine domain ──

$domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown';
$domain = preg_replace('/^www\./', '', strtolower($domain));

// ── Load config ──

$configFile = SITE_ROOT . '/admin/data/AudienceBuilder/config.json';
if (!is_file($configFile)) {
    ab_submit_json(['ok' => false, 'error' => 'Audience Builder not configured'], 503);
}

$config = json_decode(file_get_contents($configFile), true) ?: [];

// ── Detect mode: node (has hub_url) vs hub (no hub_url) ──

$hubUrl    = trim($config['hub_url'] ?? '');
$isNode    = $hubUrl !== '' && !empty($config['hub_enabled']);
$sourceSite = trim($input['source_site'] ?? '');

// ── Generate lead record ──

$leadId = 'AB-' . date('Ymd') . '-' . substr(uniqid(), -6);
$lead = [
    'id'           => $leadId,
    'domain'       => $domain,
    'source_site'  => $sourceSite !== '' ? $sourceSite : $domain,
    'submitted_at' => date('c'),
    'ip_hash'      => substr(md5($clientIp), 0, 8),
    'source_url'   => $fields['source_url'] ?? '',
    'utm'          => [
        'source'   => $fields['utm_source'] ?? '',
        'medium'   => $fields['utm_medium'] ?? '',
        'campaign' => $fields['utm_campaign'] ?? '',
    ],
    'fields'       => $fields,
    'status'       => 'stored',
    'hub_status'   => null,
    'email_status' => 'skipped',
];

// ══════════════════════════════════════════════════════
// NODE MODE — Store locally in leads/ + sent/, forward to hub
// ══════════════════════════════════════════════════════

if ($isNode) {
    $lead['status'] = 'pending';

    // Store lead in leads/ directory
    if (!empty($config['store_leads']) || !isset($config['store_leads'])) {
        $leadsDir = SITE_ROOT . '/admin/data/AudienceBuilder/leads';
        if (!is_dir($leadsDir)) @mkdir($leadsDir, 0775, true);
        $leadsMonth = date('Y-m');
        $leadsMonthFile = $leadsDir . '/' . $leadsMonth . '.json';
        $leadsExisting = is_file($leadsMonthFile) ? (json_decode(file_get_contents($leadsMonthFile), true) ?: []) : [];
        $leadsExisting[] = $lead;
        file_put_contents($leadsMonthFile, json_encode($leadsExisting, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($leadsMonthFile, 0664);
    }

    $sentId = 'SENT-' . date('Ymd') . '-' . substr(uniqid(), -6);
    $sentRecord = [
        'id'           => $sentId,
        'lead_id'      => null,
        'domain'       => $domain,
        'submitted_at' => date('c'),
        'fields'       => $fields,
        'hub_url'      => $hubUrl,
        'hub_status'   => 'pending',
        'hub_response' => null,
        'hub_error'    => null,
        'retries'      => 0,
        'last_retry'   => null,
    ];

    // Store in sent/ directory
    $sentDir = SITE_ROOT . '/admin/data/AudienceBuilder/sent';
    if (!is_dir($sentDir)) @mkdir($sentDir, 0775, true);

    $month     = date('Y-m');
    $monthFile = $sentDir . '/' . $month . '.json';
    $existing  = is_file($monthFile) ? (json_decode(file_get_contents($monthFile), true) ?: []) : [];

    // Forward to hub
    $forwardUrl = rtrim($hubUrl, '/') . '/panels/ab-submit.php';
    $forwardPayload = array_merge($input, [
        'source_site' => $domain,
    ]);

    $ch = curl_init($forwardUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($forwardPayload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $hubBody    = curl_exec($ch);
    $hubCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hubCurlErr = curl_error($ch);

    if ($hubCurlErr !== '') {
        $sentRecord['hub_status'] = 'hub_error';
        $sentRecord['hub_error']  = 'cURL: ' . $hubCurlErr;
    } elseif ($hubCode >= 200 && $hubCode < 300) {
        $hubResp = json_decode((string)$hubBody, true) ?: [];
        if (!empty($hubResp['ok'])) {
            $sentRecord['hub_status']   = 'delivered';
            $sentRecord['lead_id']      = $hubResp['lead_id'] ?? null;
            $sentRecord['hub_response'] = [
                'status'  => $hubResp['status'] ?? null,
                'lead_id' => $hubResp['lead_id'] ?? null,
            ];
        } else {
            $sentRecord['hub_status'] = 'hub_failed';
            $sentRecord['hub_error']  = $hubResp['error'] ?? 'Hub returned error';
            $sentRecord['hub_response'] = $hubResp;
        }
    } else {
        $sentRecord['hub_status'] = 'hub_error';
        $sentRecord['hub_error']  = "Hub HTTP {$hubCode}";
    }

    // Update lead in leads/ with hub response status
    if (!empty($config['store_leads']) || !isset($config['store_leads'])) {
        $leadsMonthFile = SITE_ROOT . '/admin/data/AudienceBuilder/leads/' . date('Y-m') . '.json';
        if (is_file($leadsMonthFile)) {
            $leads = json_decode(file_get_contents($leadsMonthFile), true) ?: [];
            foreach ($leads as &$l) {
                if (($l['id'] ?? '') === $leadId) {
                    $l['status'] = $sentRecord['hub_status'] === 'delivered' ? 'stored' : 'pending';
                    $l['hub_status'] = $sentRecord['hub_status'];
                    break;
                }
            }
            unset($l);
            file_put_contents($leadsMonthFile, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
    }

    // Save sent record
    $existing[] = $sentRecord;
    file_put_contents($monthFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($monthFile, 0664);

    // Return response to frontend form
    ab_submit_json([
        'ok'         => true,
        'sent_id'    => $sentId,
        'lead_id'    => $sentRecord['lead_id'],
        'hub_status' => $sentRecord['hub_status'],
        'status'     => $sentRecord['hub_status'] === 'delivered' ? 'stored' : 'pending',
    ]);
}

// ══════════════════════════════════════════════════════
// HUB / DIRECT MODE — Process lead locally
// ══════════════════════════════════════════════════════

// ── Store lead locally ──

if (!empty($config['store_leads']) || !isset($config['store_leads'])) {
    $leadsDir = SITE_ROOT . '/admin/data/AudienceBuilder/leads';
    if (!is_dir($leadsDir)) @mkdir($leadsDir, 0775, true);

    $month = date('Y-m');
    $monthFile = $leadsDir . '/' . $month . '.json';
    $existing = is_file($monthFile) ? (json_decode(file_get_contents($monthFile), true) ?: []) : [];
    $existing[] = $lead;
    file_put_contents($monthFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($monthFile, 0664);
}

// ── Send email notification via Mailgun ──

if (!empty($config['mailgun']['notify_enabled'])) {
    $notifyTo = $config['mailgun']['notify_to'] ?? '';
    if ($notifyTo !== '') {
        $mgmFunctions = SITE_ROOT . '/admin/modules/MailgunManager/MailgunManagerFunctions.php';
        if (is_file($mgmFunctions)) {
            require_once $mgmFunctions;
            $mgConfig = mgm_load_config();
            $mgApiKey = $mgConfig['api_key'] ?? '';
            $mgDomain = $mgConfig['domain'] ?? '';

            if ($mgApiKey !== '' && $mgDomain !== '') {
                $mgBase = mgm_api_base($mgConfig['region'] ?? 'us');
                $subject = $config['mailgun']['notify_subject'] ?? 'New Lead from {domain}';
                $subject = str_replace('{domain}', $domain, $subject);

                // Build email body
                $bodyLines = ["New lead submitted on {$domain}", "", "Date: " . date('Y-m-d H:i:s')];
                if ($sourceSite !== '') {
                    $bodyLines[] = "Forwarded from: {$sourceSite}";
                }
                foreach ($fields as $k => $v) {
                    if (in_array($k, ['utm_source', 'utm_medium', 'utm_campaign', 'source_url'])) continue;
                    $bodyLines[] = ucfirst(str_replace('_', ' ', $k)) . ": {$v}";
                }
                if (!empty($lead['source_url'])) {
                    $bodyLines[] = "";
                    $bodyLines[] = "Source URL: " . $lead['source_url'];
                }
                if (!empty($lead['utm']['source'])) {
                    $bodyLines[] = "UTM: " . $lead['utm']['source'] . ' / ' . ($lead['utm']['medium'] ?? '') . ' / ' . ($lead['utm']['campaign'] ?? '');
                }

                $fromEmail = $mgConfig['from_email'] ?? ('noreply@' . $mgDomain);
                $fromName  = $mgConfig['from_name'] ?? 'Luminal CMS';

                $emailResult = mgm_request(
                    "{$mgBase}/{$mgDomain}/messages",
                    $mgApiKey,
                    'POST',
                    [
                        'from'    => "{$fromName} <{$fromEmail}>",
                        'to'      => $notifyTo,
                        'subject' => $subject,
                        'text'    => implode("\n", $bodyLines),
                    ]
                );

                $lead['email_status'] = ($emailResult['ok'] ?? false) ? 'sent' : 'failed';

                // Update lead with email status
                if (!empty($config['store_leads']) || !isset($config['store_leads'])) {
                    $leadsDir  = SITE_ROOT . '/admin/data/AudienceBuilder/leads';
                    $monthFile = $leadsDir . '/' . date('Y-m') . '.json';
                    if (is_file($monthFile)) {
                        $leads = json_decode(file_get_contents($monthFile), true) ?: [];
                        foreach ($leads as &$l) {
                            if (($l['id'] ?? '') === $leadId) {
                                $l['email_status'] = $lead['email_status'];
                                break;
                            }
                        }
                        unset($l);
                        file_put_contents($monthFile, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
                    }
                }
            }
        }
    }
}

// ── Telegram notification ──
$tgLib = SITE_ROOT . '/admin/lib/TelegramNotify.php';
if (is_file($tgLib)) {
    require_once $tgLib;
    tg_notify_lead($domain, $fields, $sourceSite);
}

// ── Response ──

ab_submit_json([
    'ok'      => true,
    'lead_id' => $leadId,
    'status'  => $lead['status'],
]);
