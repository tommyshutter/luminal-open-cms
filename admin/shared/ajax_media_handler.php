<?php
/**
 * Shared Media Browser - AJAX Handler
 * @file-location: SITE-ROOT/admin/shared/ajax_media_handler.php
 * @version: 2025.07.23.1615.00 EDT
 * @description: Handles all backend logic for the media browser, including
 * fetching, uploading, and deleting media files.
 */

require_once '../auth.php';
requireAuth();
require_once __DIR__ . '/explorer/explorer-core.php';   // shared media-ops library

header('Content-Type: application/json');

$siteRoot = dirname(__DIR__, 2);   // still used by the html-block actions

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === '' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonInput = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonInput) && !empty($jsonInput['action'])) {
        $action = $jsonInput['action'];
    }
}

switch ($action) {
    case 'fetch_media':
        fetch_media($_GET['type'] ?? 'images');
        break;
    
    case 'upload_media':
        upload_media($_POST['type'] ?? 'images', $_POST['path'] ?? '');
        break;

    case 'delete_media':
        delete_media();
        break;

    case 'move_media':
        move_media();
        break;

    case 'rename_media':
        rename_media();
        break;

    case 'list_folders':
        list_folders($_GET['type'] ?? 'images');
        break;

    case 'create_folder':
        create_folder();
        break;

    case 'delete_folder':
        delete_folder();
        break;

    case 'fetch_html_blocks':
        fetch_html_blocks();
        break;

    case 'save_html_block':
        save_html_block();
        break;

    case 'delete_html_block':
        delete_html_block();
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action specified.']);
        http_response_code(400);
}

exit;

/* ── Media actions — thin endpoints over explorer-core.php ──────────────
   Response shapes are contract: explorer.js, GalleryManager regen-thumbs
   and ContentStacks all consume them. Core returns arrays; we add the
   HTTP codes the legacy handler used. */

function fetch_media($type) {
    try {
        $r = ex_list_media((string)$type);
        echo json_encode(['success' => true, 'media' => $r['media'], 'folders' => $r['folders']]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to scan media directory.', 'error' => $e->getMessage()]);
    }
}

function upload_media($type, $path = '') {
    if (!isset($_FILES['files'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No files were uploaded.']);
        return;
    }
    $r = ex_upload((string)$type, (string)$path, $_FILES['files']);
    if (!$r['success'] && $r['errors']) http_response_code(500);
    if (empty($r['errors'])) unset($r['errors']);
    echo json_encode($r);
}

function delete_media() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['paths']) || !is_array($data['paths'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid data. No paths provided for deletion.']);
        return;
    }
    $r = ex_delete($data['paths']);
    if (!$r['success']) http_response_code(500);
    echo json_encode($r);
}

function move_media() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['paths']) || !is_array($data['paths']) || !isset($data['destination'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing paths or destination.']);
        return;
    }
    echo json_encode(ex_move($data['paths'], (string)$data['destination']));
}

function rename_media() {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    echo json_encode(ex_rename((string)($data['path'] ?? ''), (string)($data['name'] ?? '')));
}

function list_folders($type) {
    echo json_encode(['success' => true, 'folders' => ex_list_folders((string)$type)]);
}

function create_folder() {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    echo json_encode(ex_create_folder((string)($data['type'] ?? 'images'), (string)($data['name'] ?? '')));
}

function delete_folder() {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    echo json_encode(ex_delete_folder((string)($data['type'] ?? 'images'), (string)($data['path'] ?? '')));
}

/**
 * Fetches all saved HTML blocks from admin/data/html-blocks/*.json
 */
function fetch_html_blocks() {
    global $siteRoot;
    $dir = $siteRoot . '/admin/data/html-blocks/';
    $blocks = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '*.json') as $file) {
            if (basename($file, '.json') === 'index') continue; // not a block — it's the rebuilt index
            $data = @json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) continue;
            $blocks[] = [
                'slug'       => $data['slug'] ?? basename($file, '.json'),
                'title'      => $data['title'] ?? basename($file, '.json'),
                'preview'    => $data['preview'] ?? '',
                'source'     => $data['source'] ?? '',
                'html'       => $data['html'] ?? '',
                'created_at' => $data['created_at'] ?? '',
                'updated_at' => $data['updated_at'] ?? '',
            ];
        }
        usort($blocks, function($a, $b) {
            return strcmp($b['updated_at'] ?: $b['created_at'], $a['updated_at'] ?: $a['created_at']);
        });
    }
    echo json_encode(['success' => true, 'blocks' => $blocks]);
}

/**
 * Normalize a block title to match the canonical HTMLBlocks writer:
 * collapse whitespace, strip leading markdown #/##, cap length.
 * Keeps titles human-readable instead of raw-HTML/markdown fragments.
 */
function amh_normalize_block_title(string $title): string {
    $t = (string)preg_replace('/\s+/', ' ', $title);      // collapse all whitespace
    $t = (string)preg_replace('/^\s*#{1,6}\s*/', '', $t);  // strip leading markdown heading marks
    $t = trim($t);
    if (mb_strlen($t) > 120) $t = rtrim(mb_substr($t, 0, 120)) . '…';
    return $t;
}

/**
 * Derive a canonical slug from a title: lowercase, [a-z0-9-], 80-char cap
 * with word-boundary trim (don't slice a word in half).
 */
function amh_slug_from_title(string $title): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($title)));
    $slug = trim((string)$slug, '-');
    if (strlen($slug) > 80) {
        $slug = substr($slug, 0, 80);
        // word-boundary trim: drop a trailing partial segment if there's a hyphen to cut on
        $lastDash = strrpos($slug, '-');
        if ($lastDash !== false && $lastDash > 40) $slug = substr($slug, 0, $lastDash);
        $slug = rtrim($slug, '-');
    }
    if ($slug === '') $slug = 'block-' . substr(bin2hex(random_bytes(3)), 0, 6);
    return $slug;
}

/**
 * Rebuild admin/data/html-blocks/index.json from the on-disk block files.
 * Mirrors HTMLBlocks api.php hb_rebuild_index — back-compat tolerant of the
 * legacy created_at/updated_at keys. Kept local so this handler doesn't have
 * to include the guarded api.php (which exits on its own auth gate).
 */
function amh_rebuild_html_blocks_index(string $dir): void {
    $indexPath = rtrim($dir, '/') . '/index.json';
    $list = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') as $f) {
        $base = basename($f, '.json');
        if ($base === 'index') continue;
        $d = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($d)) continue;
        $list[] = [
            'slug'    => $d['slug']    ?? $base,
            'title'   => $d['title']   ?? $base,
            'tags'    => is_array($d['tags'] ?? null) ? $d['tags'] : [],
            'updated' => $d['updated'] ?? ($d['updated_at'] ?? null),
            'created' => $d['created'] ?? ($d['created_at'] ?? null),
        ];
    }
    usort($list, function($a, $b) {
        return strcmp((string)($b['updated'] ?? ''), (string)($a['updated'] ?? ''));
    });
    $json = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($indexPath, $json, LOCK_EX) !== false) {
        @chmod($indexPath, 0664);
        @chown($indexPath, 'www-data');
    }
}

/**
 * Saves an HTML block to admin/data/html-blocks/{slug}.json
 *
 * Writes the CANONICAL HTMLBlocks schema so blocks created from the
 * ContentStacks editor / media browser no longer drift from blocks created
 * in the HTMLBlocks admin: stable `id`, `created`/`updated`, css/js/tags
 * defaults, normalized title, 80-char word-boundary slug. The legacy
 * `created_at`/`updated_at`/`preview`/`source` keys are also written for
 * back-compat with any reader that still expects them. Rebuilds index.json.
 */
function save_html_block() {
    global $siteRoot;
    $dir = $siteRoot . '/admin/data/html-blocks/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $title  = amh_normalize_block_title((string)($_POST['title'] ?? ''));
    $html   = $_POST['html'] ?? '';
    $source = $_POST['source'] ?? 'manual';

    if ($title === '' || $html === '') {
        echo json_encode(['success' => false, 'message' => 'Title and HTML are required.']);
        return;
    }

    // edit_slug = caller is updating a specific block (preserve identity across rename).
    // Falls back to title-derived slug for new-create from "Save to Library".
    $editSlug = trim((string)($_POST['edit_slug'] ?? ''));
    if ($editSlug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $editSlug) && strlen($editSlug) <= 80) {
        $slug = $editSlug;
    } else {
        $slug = amh_slug_from_title($title);
    }

    $file = $dir . $slug . '.json';
    $now = gmdate('Y-m-d\TH:i:s\Z');

    // Preview: strip tags, first 120 chars (kept for back-compat readers)
    $preview = mb_substr(strip_tags($html), 0, 120);

    $existing = is_file($file) ? @json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($existing)) $existing = [];

    // Carry forward original creation time whether stored as created or created_at.
    $existingCreated = $existing['created'] ?? ($existing['created_at'] ?? null);
    // Stable id: keep existing, else mint a canonical "hb_…" id.
    $existingId = (string)($existing['id'] ?? '');
    $id = $existingId !== '' ? $existingId : 'hb_' . substr(bin2hex(random_bytes(4)), 0, 8);

    // Merge over existing so any extra fields (e.g. usage_count) survive an Edit save,
    // then force the canonical fields on top.
    $block = array_merge($existing, [
        'id'         => $id,
        'slug'       => $slug,
        'title'      => $title,
        'html'       => $html,
        'css'        => (string)($existing['css'] ?? ''),
        'js'         => (string)($existing['js']  ?? ''),
        'tags'       => is_array($existing['tags'] ?? null) ? $existing['tags'] : [],
        'created'    => $existingCreated ?? $now,
        'updated'    => $now,
        // Back-compat duplicate keys (low-risk; some readers still use these)
        'preview'    => $preview,
        'source'     => $source,
        'created_at' => $existing['created_at'] ?? ($existingCreated ?? $now),
        'updated_at' => $now,
        'usage_count'=> (int)($existing['usage_count'] ?? 0),
    ]);

    $written = file_put_contents($file, json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($written !== false) {
        @chmod($file, 0664);
        @chown($file, 'www-data');
        amh_rebuild_html_blocks_index($dir);   // keep index.json in sync (canonical writer does this)
        echo json_encode(['success' => true, 'slug' => $slug, 'message' => 'Saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to write file.']);
    }
}

/**
 * Deletes an HTML block by slug, then rebuilds index.json.
 */
function delete_html_block() {
    global $siteRoot;
    $dir = $siteRoot . '/admin/data/html-blocks/';
    $slug = $_POST['slug'] ?? '';
    if ($slug === '') {
        echo json_encode(['success' => false, 'message' => 'No slug provided.']);
        return;
    }
    // Sanitize: only allow alphanumeric + hyphens + underscores
    $slug = preg_replace('/[^a-z0-9_\-]/', '', $slug);
    $file = $dir . $slug . '.json';
    if (is_file($file)) {
        @unlink($file);
        amh_rebuild_html_blocks_index($dir);   // keep index.json in sync after delete
        echo json_encode(['success' => true, 'message' => 'Deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Block not found.']);
    }
}
?>