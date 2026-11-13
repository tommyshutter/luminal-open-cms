<?php
/**
 * LeadCollector — Public Lead Collection Endpoint
 *
 * Accepts POST from configured node sites.
 * Stores leads in monthly JSON files.
 *
 * Rate-limited: 20 submissions per IP per hour.
 * No auth required (public endpoint).
 *
 * @endpoint POST /panels/lead-collect.php
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

function lc_submit_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lc_submit_json(['ok' => false, 'error' => 'POST required'], 405);
}

// ── Rate limiting (20/hour per IP) ──
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipHash   = substr(md5($clientIp . date('Y-m-d-H')), 0, 12);
$rateFile = sys_get_temp_dir() . '/lc_rate_' . $ipHash;
$rateCount = 0;

if (is_file($rateFile)) {
    $rateData = json_decode(file_get_contents($rateFile), true);
    if (($rateData['hour'] ?? '') === date('Y-m-d-H')) {
        $rateCount = (int)($rateData['count'] ?? 0);
    }
}

if ($rateCount >= 20) {
    lc_submit_json(['ok' => false, 'error' => 'Rate limit exceeded. Please try again later.'], 429);
}

// Increment rate counter
file_put_contents($rateFile, json_encode([
    'hour'  => date('Y-m-d-H'),
    'count' => $rateCount + 1,
]), LOCK_EX);

// ── Parse input ──
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $input['action'] ?? '';
if ($action !== 'submit_lead') {
    lc_submit_json(['ok' => false, 'error' => 'Invalid action. Expected: submit_lead'], 400);
}

// ── Validate ──
$sourceSite = trim((string)($input['source_site'] ?? ''));
$sourcePage = trim((string)($input['source_page'] ?? 'unknown'));
$fields     = $input['fields'] ?? [];

if ($sourceSite === '') {
    lc_submit_json(['ok' => false, 'error' => 'source_site is required'], 400);
}
if (!is_array($fields) || empty($fields['email'])) {
    lc_submit_json(['ok' => false, 'error' => 'fields.email is required'], 400);
}
if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    lc_submit_json(['ok' => false, 'error' => 'Invalid email address'], 400);
}

// ── Honeypot check ──
if (!empty($fields['hp_field']) || !empty($input['hp_field'])) {
    // Bot detected — return success silently
    lc_submit_json(['ok' => true, 'id' => 'lc_hp_' . time()]);
}

// ── Build lead record ──
$now = time();
$leadId = 'lc_' . $now . '_' . substr(md5(uniqid((string)$now, true)), 0, 8);

$lead = [
    'id'           => $leadId,
    'source_site'  => $sourceSite,
    'source_page'  => $sourcePage,
    'submitted_at' => date('c'),
    'ip_hash'      => substr(md5($clientIp . 'lc_salt_2026'), 0, 16),
    'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 256),
    'fields'       => [
        'name'    => substr(trim((string)($fields['name'] ?? '')), 0, 200),
        'email'   => substr(trim((string)($fields['email'] ?? '')), 0, 200),
        'website' => substr(trim((string)($fields['website'] ?? '')), 0, 500),
        'message' => substr(trim((string)($fields['message'] ?? '')), 0, 5000),
    ],
    'status'       => 'new',
];

// ── Store in monthly file ──
$leadsDir = SITE_ROOT . '/admin/data/LeadCollector/leads';
if (!is_dir($leadsDir)) {
    @mkdir($leadsDir, 0775, true);
}

$monthFile = $leadsDir . '/' . date('Y-m') . '.json';
$existing = [];
if (is_file($monthFile)) {
    $existing = json_decode(file_get_contents($monthFile), true) ?: [];
}

$existing[] = $lead;

$saved = file_put_contents(
    $monthFile,
    json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);
@chmod($monthFile, 0664);

if ($saved === false) {
    lc_submit_json(['ok' => false, 'error' => 'Failed to store lead'], 500);
}

lc_submit_json(['ok' => true, 'id' => $leadId]);
