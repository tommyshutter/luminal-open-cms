<?php
/**
 * HTMLBlocks API — CRUD for admin/data/html-blocks/{slug}.json + index.json
 *
 * Endpoints (all POST for state change, GET for read):
 *   GET   ?action=list
 *   GET   ?action=get&slug=…
 *   POST  action=save   (fields: slug, title, html, css, js, tags)
 *   POST  action=delete (fields: slug)
 *   POST  action=rename (fields: slug, new_slug)
 *
 *   --- bulk endpoints (operate on a slugs[] / comma-list of slugs) ---
 *   POST  action=bulk_delete   (fields: slugs[] or slugs="a,b,c")
 *   POST  action=bulk_tag      (fields: slugs[] , tags="a,b" , mode=add|set|remove)
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';

/*
 * Determine the action BEFORE the auth gate so the cross-origin, site_key-
 * authenticated `push` action can skip the admin session requirement. Every
 * OTHER action (list/get/save/delete/rename/bulk_*) still requires a logged-in
 * admin via guard_require_auth(), exactly as before.
 */
$HB_ACTION  = $_REQUEST['action'] ?? '';
$HB_JSON_IN = null;
// Always parse a JSON request body when present — push sends action in BOTH the
// query string (?action=push) AND the JSON body, so we must capture the body
// regardless of where the action was found, or the payload is lost.
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $HB_RAW_BODY = file_get_contents('php://input');
    $decoded     = json_decode((string)$HB_RAW_BODY, true);
    if (is_array($decoded)) {
        $HB_JSON_IN = $decoded;
        if ($HB_ACTION === '' && !empty($decoded['action'])) {
            $HB_ACTION = (string)$decoded['action'];
        }
    }
}

if ($HB_ACTION === 'push') {
    /* ---- CORS for cross-origin spoke push (mirror SiteTextReceiver) ---- */
    $hbOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($hbOrigin !== '') {
        header("Access-Control-Allow-Origin: $hbOrigin");
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    // push validates via site_key (below) — NOT the admin session / CSRF token.
} else {
    // All admin actions require a logged-in admin session.
    guard_require_auth();
}

header('Content-Type: application/json');

$DATA_DIR  = SITE_ROOT . '/admin/data/html-blocks';
$INDEX     = $DATA_DIR . '/index.json';

if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0775, true);
}

/* ----------------------------------------------------------------
 * site_key auth for the `push` action — mirrors SiteTextReceiver's
 * st_validate_site_auth: validate (domain, site_key) against the hub
 * deployment registry (admin/deployment_mgr/site_registry.json), with
 * www./.com↔.pro candidate matching, then a local-vhost fallback.
 * ---------------------------------------------------------------- */
function hb_validate_site_auth(string $domain, string $siteKey): bool {
    $regFile = SITE_ROOT . '/admin/deployment_mgr/site_registry.json';
    $registry = is_file($regFile)
        ? (@json_decode((string)@file_get_contents($regFile), true) ?: [])
        : [];

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
        return hash_equals((string)$entry['site_key'], $siteKey);
    }
    if ($entry) return true;

    // Local vhost fallback (same as SiteTextReceiver)
    $vhostDir = '/var/www/vhosts/' . preg_replace('/[^a-zA-Z0-9._-]/', '', $domain);
    if (is_dir($vhostDir)) return true;

    return false;
}

/* ---- CSRF gate for state-changing actions ---- */
function hb_require_csrf(): void {
    $expected = (string)($_SESSION['hb_csrf'] ?? '');
    $provided = (string)(
        $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['csrf_token']
            ?? $_POST['_csrf']
            ?? ''
    );
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token missing or invalid']);
        exit;
    }
}

function hb_slug_is_safe(string $s): bool {
    return $s !== '' && strlen($s) <= 80 && (bool)preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $s);
}

/**
 * Collect a list of slugs from a request that may send either
 * slugs[]=a&slugs[]=b (array) or slugs=a,b,c (comma string).
 * Returns de-duped, validated, safe slugs only.
 */
function hb_collect_slugs(): array {
    $raw = $_POST['slugs'] ?? [];
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) $raw = [];
    $out = [];
    foreach ($raw as $s) {
        $s = trim((string)$s);
        if ($s !== '' && hb_slug_is_safe($s)) {
            $out[$s] = true; // de-dupe via key
        }
    }
    return array_keys($out);
}

function hb_slug_from_title(string $t): string {
    $s = strtolower(trim($t));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') $s = 'block-' . substr(bin2hex(random_bytes(3)), 0, 6);
    return $s;
}

function hb_write_json(string $path, $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ok = @file_put_contents($path, $json, LOCK_EX);
    if ($ok !== false) @chmod($path, 0664);
    return $ok !== false;
}

/**
 * Back-compat reader: normalize a raw block array onto the canonical
 * schema in memory (does NOT write). Tolerates the divergent
 * ajax_media_handler schema (created_at/updated_at, no id/css/js/tags).
 *   - updated  := updated ?? updated_at
 *   - created  := created ?? created_at
 *   - id       := backfilled stable "hb_…" when absent
 *   - css/js   := "" when absent
 *   - tags     := [] when absent
 */
function hb_normalize_block(array $d, string $fallbackSlug): array {
    $slug = (string)($d['slug'] ?? $fallbackSlug);
    $d['slug']    = $slug;
    $d['id']      = (string)($d['id'] ?? '') !== '' ? $d['id'] : 'hb_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $d['title']   = $d['title'] ?? $slug;
    $d['html']    = (string)($d['html'] ?? '');
    $d['css']     = (string)($d['css']  ?? '');
    $d['js']      = (string)($d['js']   ?? '');
    $d['tags']    = is_array($d['tags'] ?? null) ? $d['tags'] : [];
    $d['created'] = $d['created'] ?? ($d['created_at'] ?? null);
    $d['updated'] = $d['updated'] ?? ($d['updated_at'] ?? null);
    return $d;
}

function hb_rebuild_index(string $dataDir, string $indexPath): void {
    $list = [];
    foreach (glob($dataDir . '/*.json') as $f) {
        $base = basename($f, '.json');
        if ($base === 'index') continue;
        $raw = @file_get_contents($f);
        $d = @json_decode((string)$raw, true);
        if (!is_array($d)) continue;
        // Back-compat: divergent path wrote created_at/updated_at, no id/tags.
        $list[] = [
            'slug'    => $d['slug']    ?? $base,
            'title'   => $d['title']   ?? $base,
            'tags'    => is_array($d['tags'] ?? null) ? $d['tags'] : [],
            'updated' => $d['updated'] ?? ($d['updated_at'] ?? null),
            'created' => $d['created'] ?? ($d['created_at'] ?? null),
        ];
    }
    // Sort newest-updated first
    usort($list, function($a, $b) {
        return strcmp((string)($b['updated'] ?? ''), (string)($a['updated'] ?? ''));
    });
    hb_write_json($indexPath, $list);
}

/* ---- Dispatch ---- */
$action = $HB_ACTION;

try {
    switch ($action) {
        case 'list': {
            if (!is_file($INDEX)) hb_rebuild_index($DATA_DIR, $INDEX);
            $list = @json_decode((string)@file_get_contents($INDEX), true) ?: [];
            echo json_encode(['success' => true, 'blocks' => $list]);
            exit;
        }

        case 'get': {
            $slug = (string)($_GET['slug'] ?? '');
            if (!hb_slug_is_safe($slug)) {
                echo json_encode(['success' => false, 'message' => 'Invalid slug']); exit;
            }
            $f = $DATA_DIR . '/' . $slug . '.json';
            if (!is_file($f)) {
                echo json_encode(['success' => false, 'message' => 'Block not found']); exit;
            }
            $raw = @json_decode((string)@file_get_contents($f), true);
            if (!is_array($raw)) {
                echo json_encode(['success' => false, 'message' => 'Bad JSON in block']); exit;
            }
            // Back-compat tolerant: surface a canonical-shaped block to the editor
            // even for divergent-path files (created_at/updated_at, no id/css/js/tags).
            echo json_encode(['success' => true, 'block' => hb_normalize_block($raw, $slug)]);
            exit;
        }

        case 'save': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST required']); exit; }
            hb_require_csrf();
            $title = trim((string)($_POST['title'] ?? ''));
            $slug  = trim((string)($_POST['slug']  ?? ''));
            $html  = (string)($_POST['html'] ?? '');
            $css   = (string)($_POST['css']  ?? '');
            $js    = (string)($_POST['js']   ?? '');
            $tags  = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? '')))));

            if ($title === '' && $slug === '') {
                echo json_encode(['success' => false, 'message' => 'Title or slug required']); exit;
            }
            if ($slug === '') $slug = hb_slug_from_title($title);
            if (!hb_slug_is_safe($slug)) {
                echo json_encode(['success' => false, 'message' => 'Invalid slug: must be [a-z0-9_-] starting with alnum']); exit;
            }

            $f = $DATA_DIR . '/' . $slug . '.json';
            $existing = is_file($f) ? (@json_decode((string)@file_get_contents($f), true) ?: []) : [];
            $now = date('c');
            // Back-compat: an existing divergent-path file has no id/created
            // but does have created_at — preserve original creation time.
            $existingId      = (string)($existing['id'] ?? '');
            $existingCreated = $existing['created'] ?? ($existing['created_at'] ?? null);
            $data = [
                'id'      => $existingId !== '' ? $existingId : 'hb_' . substr(bin2hex(random_bytes(4)), 0, 8),
                'slug'    => $slug,
                'title'   => $title !== '' ? $title : ($existing['title'] ?? $slug),
                'html'    => $html,
                'css'     => $css,
                'js'      => $js,
                'tags'    => $tags,
                'created' => $existingCreated ?? $now,
                'updated' => $now,
                'usage_count' => (int)($existing['usage_count'] ?? 0),
            ];
            if (!hb_write_json($f, $data)) {
                echo json_encode(['success' => false, 'message' => 'Write failed']); exit;
            }
            hb_rebuild_index($DATA_DIR, $INDEX);
            echo json_encode(['success' => true, 'slug' => $slug, 'block' => $data]);
            exit;
        }

        case 'delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST required']); exit; }
            hb_require_csrf();
            $slug = trim((string)($_POST['slug'] ?? ''));
            if (!hb_slug_is_safe($slug)) {
                echo json_encode(['success' => false, 'message' => 'Invalid slug']); exit;
            }
            $f = $DATA_DIR . '/' . $slug . '.json';
            if (!is_file($f)) {
                echo json_encode(['success' => false, 'message' => 'Block not found']); exit;
            }
            if (!@unlink($f)) {
                echo json_encode(['success' => false, 'message' => 'Delete failed']); exit;
            }
            hb_rebuild_index($DATA_DIR, $INDEX);
            echo json_encode(['success' => true]);
            exit;
        }

        case 'rename': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST required']); exit; }
            hb_require_csrf();
            $oldSlug = trim((string)($_POST['slug'] ?? ''));
            $newSlug = trim((string)($_POST['new_slug'] ?? ''));
            if (!hb_slug_is_safe($oldSlug) || !hb_slug_is_safe($newSlug)) {
                echo json_encode(['success' => false, 'message' => 'Invalid slug']); exit;
            }
            if ($oldSlug === $newSlug) { echo json_encode(['success' => true]); exit; }
            $oldF = $DATA_DIR . '/' . $oldSlug . '.json';
            $newF = $DATA_DIR . '/' . $newSlug . '.json';
            if (!is_file($oldF)) { echo json_encode(['success' => false, 'message' => 'Source not found']); exit; }
            if (is_file($newF))  { echo json_encode(['success' => false, 'message' => 'Target slug exists']); exit; }
            $data = @json_decode((string)@file_get_contents($oldF), true) ?: [];
            $data['slug'] = $newSlug;
            $data['updated'] = date('c');
            if (!hb_write_json($newF, $data) || !@unlink($oldF)) {
                echo json_encode(['success' => false, 'message' => 'Rename failed']); exit;
            }
            hb_rebuild_index($DATA_DIR, $INDEX);
            echo json_encode(['success' => true, 'slug' => $newSlug]);
            exit;
        }

        case 'bulk_delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST required']); exit; }
            hb_require_csrf();
            $slugs = hb_collect_slugs();
            if (empty($slugs)) {
                echo json_encode(['success' => false, 'message' => 'No valid slugs supplied']); exit;
            }
            $deleted = [];
            $failed  = [];
            foreach ($slugs as $slug) {
                $f = $DATA_DIR . '/' . $slug . '.json';
                if (!is_file($f)) { $failed[$slug] = 'not found'; continue; }
                if (@unlink($f)) { $deleted[] = $slug; }
                else            { $failed[$slug] = 'delete failed'; }
            }
            hb_rebuild_index($DATA_DIR, $INDEX);
            echo json_encode([
                'success'       => empty($failed),
                'deleted'       => $deleted,
                'deleted_count' => count($deleted),
                'failed'        => $failed,
                'message'       => count($deleted) . ' deleted'
                                   . (empty($failed) ? '' : ', ' . count($failed) . ' failed'),
            ]);
            exit;
        }

        case 'bulk_tag': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST required']); exit; }
            hb_require_csrf();
            $slugs = hb_collect_slugs();
            if (empty($slugs)) {
                echo json_encode(['success' => false, 'message' => 'No valid slugs supplied']); exit;
            }
            $mode = (string)($_POST['mode'] ?? 'add');
            if (!in_array($mode, ['add', 'set', 'remove'], true)) $mode = 'add';
            $tags = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? '')))));
            if ($mode !== 'set' && empty($tags)) {
                echo json_encode(['success' => false, 'message' => 'No tags supplied']); exit;
            }
            $updated = [];
            $failed  = [];
            $now = date('c');
            foreach ($slugs as $slug) {
                $f = $DATA_DIR . '/' . $slug . '.json';
                if (!is_file($f)) { $failed[$slug] = 'not found'; continue; }
                $data = @json_decode((string)@file_get_contents($f), true);
                if (!is_array($data)) { $failed[$slug] = 'bad JSON'; continue; }
                $cur = is_array($data['tags'] ?? null) ? $data['tags'] : [];
                if ($mode === 'set') {
                    $new = $tags;
                } elseif ($mode === 'remove') {
                    $new = array_values(array_diff($cur, $tags));
                } else { // add
                    $new = array_values(array_unique(array_merge($cur, $tags)));
                }
                $data['tags']    = $new;
                $data['updated'] = $now;
                if (hb_write_json($f, $data)) { $updated[] = $slug; }
                else                          { $failed[$slug] = 'write failed'; }
            }
            hb_rebuild_index($DATA_DIR, $INDEX);
            echo json_encode([
                'success'       => empty($failed),
                'updated'       => $updated,
                'updated_count' => count($updated),
                'failed'        => $failed,
                'message'       => count($updated) . ' updated'
                                   . (empty($failed) ? '' : ', ' . count($failed) . ' failed'),
            ]);
            exit;
        }

        case 'push': {
            // Cross-origin, site_key-authenticated upsert BY SLUG. No CSRF, no
            // admin session — auth is the site_key checked against the registry.
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'POST required']); exit;
            }

            // Accept JSON body (captured pre-gate) or form POST.
            $body = (isset($HB_JSON_IN) && is_array($HB_JSON_IN)) ? $HB_JSON_IN : $_POST;

            $domain  = (string)($body['domain']   ?? '');
            $siteKey = (string)($body['site_key'] ?? '');
            if ($domain === '' || $siteKey === '' || !hb_validate_site_auth($domain, $siteKey)) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
            }

            $slug  = trim((string)($body['slug'] ?? ''));
            $title = trim((string)($body['title'] ?? ''));
            $html  = (string)($body['html'] ?? '');
            $css   = (string)($body['css']  ?? '');
            $js    = (string)($body['js']   ?? '');
            $source = (string)($body['source'] ?? 'hub:push');
            $tags  = $body['tags'] ?? [];
            if (is_string($tags)) {
                $tags = array_values(array_filter(array_map('trim', explode(',', $tags))));
            }
            if (!is_array($tags)) $tags = [];

            if ($slug === '' && $title !== '') $slug = hb_slug_from_title($title);
            if (!hb_slug_is_safe($slug)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid or missing slug']); exit;
            }
            if ($html === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'html is required']); exit;
            }

            $f = $DATA_DIR . '/' . $slug . '.json';
            $existing = is_file($f) ? (@json_decode((string)@file_get_contents($f), true) ?: []) : [];
            $now = date('c');
            $existingId      = (string)($existing['id'] ?? '');
            $existingCreated = $existing['created'] ?? ($existing['created_at'] ?? null);

            $data = [
                'id'      => $existingId !== '' ? $existingId : 'hb_' . substr(bin2hex(random_bytes(4)), 0, 8),
                'slug'    => $slug,
                'title'   => $title !== '' ? $title : ($existing['title'] ?? $slug),
                'html'    => $html,
                'css'     => $css !== '' ? $css : (string)($existing['css'] ?? ''),
                'js'      => $js  !== '' ? $js  : (string)($existing['js']  ?? ''),
                'tags'    => !empty($tags) ? $tags : (is_array($existing['tags'] ?? null) ? $existing['tags'] : []),
                'source'  => $source,
                'created' => $existingCreated ?? $now,
                'updated' => $now,
                'usage_count' => (int)($existing['usage_count'] ?? 0),
            ];
            if (!hb_write_json($f, $data)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Write failed']); exit;
            }
            hb_rebuild_index($DATA_DIR, $INDEX);
            // SiteTextReceiver-style envelope so the hub pipeline can read it uniformly.
            echo json_encode([
                'ok'      => true,
                'success' => true,
                'slug'    => $slug,
                'created' => $existingId === '',
                'data'    => ['slug' => $slug, 'id' => $data['id'], 'updated' => $data['updated']],
            ]);
            exit;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
