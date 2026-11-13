<?php
/**
 * SiteTextReceiver — JSON API
 * Path: /admin/modules/SiteTextReceiver/api.php
 *
 * Public endpoints (site_key auth OR admin session):
 *   list     — list all slots (metadata only, no content body)
 *   get      — get full slot content + metadata
 *   push     — push content to a slot (auto-creates if new)
 *   history  — get version history for a slot
 *   revert   — revert to a specific version
 *   delete   — delete a slot
 *
 * Version: 1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/lib/SafeIO.php';

$ST_DATA_DIR = SITE_ROOT . '/admin/data/site-text';

// Ensure data directory exists
if (!is_dir($ST_DATA_DIR)) {
    @mkdir($ST_DATA_DIR, 0775, true);
}

/* ---------- CORS for cross-origin push ---------- */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* ---------- helpers ---------- */
function st_json_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function st_json_err(string $error, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}

function st_sanitize_key(string $key): string {
    // Allow alphanumeric, hyphens, underscores only
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($key));
    return strtolower($clean);
}

function st_load_slot(string $key): ?array {
    global $ST_DATA_DIR;
    $file = $ST_DATA_DIR . '/' . $key . '.json';
    if (!is_file($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function st_save_slot(string $key, array $data): bool {
    global $ST_DATA_DIR;
    $file = $ST_DATA_DIR . '/' . $key . '.json';
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function st_load_history(string $key): array {
    global $ST_DATA_DIR;
    $file = $ST_DATA_DIR . '/' . $key . '.history.json';
    if (!is_file($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function st_save_history(string $key, array $history): bool {
    global $ST_DATA_DIR;
    $file = $ST_DATA_DIR . '/' . $key . '.history.json';
    return file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

function st_push_to_history(string $key, array $slot): void {
    $history = st_load_history($key);
    // Store current state as a history entry
    $entry = [
        'version' => $slot['version'] ?? 1,
        'html' => $slot['html'] ?? '',
        'text' => $slot['text'] ?? '',
        'updated_at' => $slot['updated_at'] ?? '',
        'updated_by' => $slot['updated_by'] ?? 'unknown',
    ];
    array_unshift($history, $entry);
    // Keep only last 5
    $history = array_slice($history, 0, 5);
    st_save_history($key, $history);
}

/* ---------- auth ---------- */
function st_check_auth(): bool {
    // Check admin session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Canonical Luminal admin session (used by the admin UI fetches).
    // The legacy `$_SESSION['user']['role']` shape is never set by Luminal auth
    // (it uses $_SESSION['user_id']/$_SESSION['user_role'] or the legacy
    // $_SESSION['admin_logged_in']); fall through to the guard adapter, which
    // understands all of those. This is what every other module uses.
    $guard = SITE_ROOT . '/admin/modules/UserManager/guard.php';
    if (is_file($guard)) {
        require_once $guard;
        if (function_exists('guard_is_authenticated') && guard_is_authenticated()) {
            return true;
        }
    }

    // Back-compat: also honor the original (never-populated) shape, just in case.
    if (!empty($_SESSION['user']) && ($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }

    // Check HMAC site_key auth
    $domain  = $_GET['domain']   ?? $_POST['domain']   ?? '';
    $siteKey = $_GET['site_key'] ?? $_POST['site_key'] ?? '';

    // Also check JSON body
    if (empty($domain) || empty($siteKey)) {
        $body = json_decode(file_get_contents('php://input'), true);
        if (is_array($body)) {
            $domain  = $domain  ?: ($body['domain'] ?? '');
            $siteKey = $siteKey ?: ($body['site_key'] ?? '');
        }
    }

    if (!empty($domain) && !empty($siteKey)) {
        return st_validate_site_auth($domain, $siteKey);
    }

    return false;
}

function st_validate_site_auth(string $domain, string $siteKey): bool {
    $registry = safe_load_registry();

    $entry = null;
    $candidates = [$domain];
    $candidates[] = str_starts_with($domain, 'www.') ? substr($domain, 4) : 'www.' . $domain;
    foreach (['.com' => '.pro', '.pro' => '.com'] as $from => $to) {
        if (str_ends_with($domain, $from)) {
            $candidates[] = substr($domain, 0, -strlen($from)) . $to;
        }
    }
    foreach ($candidates as $c) {
        if (isset($registry[$c]) && is_array($registry[$c])) {
            $entry = $registry[$c];
            break;
        }
    }

    if ($entry && !empty($entry['site_key'])) {
        return hash_equals($entry['site_key'], $siteKey);
    }
    if ($entry) return true;

    // Local vhost check
    $vhostDir = '/var/www/vhosts/' . preg_replace('/[^a-zA-Z0-9._-]/', '', $domain);
    if (is_dir($vhostDir)) return true;

    return false;
}

/* ---------- auth gate ---------- */
if (!st_check_auth()) {
    st_json_err('Unauthorized', 401);
}

/* ---------- routing ---------- */
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (empty($action)) {
    $body = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? '';
}

$now = (new DateTime('now', new DateTimeZone('America/New_York')))->format('c');

switch ($action) {

    /* ----- list ----- */
    case 'list':
        $slots = [];
        $files = glob($ST_DATA_DIR . '/*.json');
        foreach ($files as $f) {
            $base = basename($f, '.json');
            // Skip history files
            if (str_ends_with($base, '.history')) continue;
            $slot = json_decode(file_get_contents($f), true);
            if (!is_array($slot)) continue;
            $slots[] = [
                'key'        => $slot['key'] ?? $base,
                'label'      => $slot['label'] ?? $base,
                'updated_at' => $slot['updated_at'] ?? '',
                'updated_by' => $slot['updated_by'] ?? '',
                'version'    => $slot['version'] ?? 1,
                'created_at' => $slot['created_at'] ?? '',
            ];
        }
        usort($slots, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
        st_json_ok(['slots' => $slots]);

    /* ----- get ----- */
    case 'get':
        $key = st_sanitize_key($_GET['key'] ?? '');
        if (empty($key)) st_json_err('Missing key');
        $slot = st_load_slot($key);
        if (!$slot) st_json_err('Slot not found', 404);
        st_json_ok(['slot' => $slot]);

    /* ----- push ----- */
    case 'push':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        $key = st_sanitize_key($body['key'] ?? '');
        if (empty($key)) st_json_err('Missing key');

        $html   = $body['html'] ?? '';
        $text   = $body['text'] ?? strip_tags($html);
        $source = $body['source'] ?? 'unknown';
        $label  = $body['label'] ?? '';

        $existing = st_load_slot($key);
        $version = 1;

        if ($existing) {
            // Save current to history before overwriting
            st_push_to_history($key, $existing);
            $version = ($existing['version'] ?? 1) + 1;
        }

        $slot = [
            'key'        => $key,
            'label'      => $label ?: ($existing['label'] ?? $key),
            'html'       => $html,
            'text'       => $text,
            'updated_at' => $now,
            'updated_by' => $source,
            'version'    => $version,
            'created_at' => $existing['created_at'] ?? $now,
        ];

        if (!st_save_slot($key, $slot)) {
            st_json_err('Failed to save slot', 500);
        }
        st_json_ok(['version' => $version]);

    /* ----- history ----- */
    case 'history':
        $key = st_sanitize_key($_GET['key'] ?? '');
        if (empty($key)) st_json_err('Missing key');
        $history = st_load_history($key);
        st_json_ok(['history' => $history, 'key' => $key]);

    /* ----- revert ----- */
    case 'revert':
        $key = st_sanitize_key($_GET['key'] ?? $_POST['key'] ?? '');
        $ver = (int)($_GET['version'] ?? $_POST['version'] ?? 0);
        if (empty($key)) st_json_err('Missing key');
        if ($ver < 1) st_json_err('Invalid version');

        $history = st_load_history($key);
        $target = null;
        foreach ($history as $h) {
            if (($h['version'] ?? 0) === $ver) {
                $target = $h;
                break;
            }
        }
        if (!$target) st_json_err('Version not found in history', 404);

        $existing = st_load_slot($key);
        if ($existing) {
            st_push_to_history($key, $existing);
        }

        $newVersion = ($existing['version'] ?? 1) + 1;
        $slot = [
            'key'        => $key,
            'label'      => $existing['label'] ?? $key,
            'html'       => $target['html'],
            'text'       => $target['text'] ?? strip_tags($target['html']),
            'updated_at' => $now,
            'updated_by' => 'human:revert',
            'version'    => $newVersion,
            'created_at' => $existing['created_at'] ?? $now,
        ];
        st_save_slot($key, $slot);
        st_json_ok(['version' => $newVersion]);

    /* ----- delete ----- */
    case 'delete':
        $key = st_sanitize_key($_GET['key'] ?? $_POST['key'] ?? '');
        if (empty($key)) st_json_err('Missing key');

        $slotFile = $ST_DATA_DIR . '/' . $key . '.json';
        $histFile = $ST_DATA_DIR . '/' . $key . '.history.json';
        if (is_file($slotFile)) @unlink($slotFile);
        if (is_file($histFile)) @unlink($histFile);
        st_json_ok(['deleted' => $key]);

    default:
        st_json_err('Unknown action: ' . $action);
}
