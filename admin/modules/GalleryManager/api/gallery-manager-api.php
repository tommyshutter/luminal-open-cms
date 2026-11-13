<?php
/**
 * Unified Gallery Manager - AJAX API
 * @file /admin/ajax/gallery-manager-api.php
 * @version 2026.01.31
 *
 * Actions: list_galleries, get_gallery, save_gallery, delete_gallery
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();
session_write_close(); // release the session lock — save_gallery loopback-calls media-cache.php with the same session cookie (deadlock otherwise)

header('Content-Type: application/json; charset=UTF-8');

$ADMIN_DIR = realpath(dirname(__DIR__, 3));
$SITE_BASE = dirname($ADMIN_DIR);
$GALLERIES_BASE = $ADMIN_DIR . '/data/galleries';

$VALID_TYPES = ['images', 'videos', 'pdfs', 'combined'];

function gm_json(bool $ok, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok], $extra), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
function gm_bad(string $msg): void {
    http_response_code(400);
    gm_json(false, ['message' => $msg]);
}

function gm_slug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\-]/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ── list_galleries ─────────────────────────────────────────────── */
if ($action === 'list_galleries') {
    $galleries = [];
    foreach ($VALID_TYPES as $type) {
        $dir = $GALLERIES_BASE . '/' . $type;
        if (!is_dir($dir)) continue;
        $it = new DirectoryIterator($dir);
        foreach ($it as $fi) {
            if (!$fi->isFile() || $fi->getExtension() !== 'json') continue;
            $slug = $fi->getBasename('.json');
            if ($slug === 'index') continue;
            $data = json_decode((string)@file_get_contents($fi->getPathname()), true);
            if (!is_array($data)) continue;

            // Derive a cover (first item path) + total bytes so the chooser can
            // show a real thumbnail and size metadata without a second request.
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            $cover = '';
            $bytes = 0;
            foreach ($items as $it) {
                $p = is_string($it) ? $it : (string)($it['path'] ?? '');
                if ($p === '') continue;
                if ($cover === '') $cover = $p;
                $fs = $SITE_BASE . '/' . ltrim($p, '/');
                if (is_file($fs)) $bytes += (int)filesize($fs);
            }

            $galleries[] = [
                'type'      => $type,
                'title'     => $data['title'] ?? $slug,
                'slug'      => $slug,
                'itemCount' => count($items),
                'layout'    => $data['layout'] ?? '',
                'updated'   => $data['updated'] ?? '',
                'cover'     => $cover,
                'bytes'     => $bytes,
            ];
        }
    }
    usort($galleries, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    gm_json(true, ['galleries' => $galleries]);

/* ── get_gallery ────────────────────────────────────────────────── */
} elseif ($action === 'get_gallery') {
    $type = $_GET['type'] ?? $_POST['type'] ?? '';
    $slug = $_GET['slug'] ?? $_POST['slug'] ?? '';
    if (!in_array($type, $VALID_TYPES, true)) gm_bad('Invalid type.');
    $slug = gm_slug($slug);
    if ($slug === '') gm_bad('Missing slug.');
    $path = $GALLERIES_BASE . '/' . $type . '/' . $slug . '.json';
    if (!is_file($path)) gm_bad('Gallery not found.');
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) gm_bad('Corrupt JSON.');
    gm_json(true, ['gallery' => $data]);

/* ── save_gallery ───────────────────────────────────────────────── */
} elseif ($action === 'save_gallery') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) gm_bad('Invalid JSON body.');

    $type     = $input['type'] ?? '';
    $title    = trim($input['title'] ?? '');
    $slug     = gm_slug($input['slug'] ?? $title);
    $layout   = $input['layout'] ?? 'grid';
    $columns  = max(1, min(8, (int)($input['columns'] ?? 3)));
    $rowheight = max(80, min(600, (int)($input['rowheight'] ?? 240)));
    $sortTools = !empty($input['sort_tools']);
    $sortDef   = in_array(($input['sort_default'] ?? 'newest'), ['newest','oldest','az','za','custom'], true)
                 ? $input['sort_default'] : 'newest';
    $items    = $input['items'] ?? [];

    if (!in_array($type, $VALID_TYPES, true)) gm_bad('Invalid type.');
    if ($slug === '') gm_bad('Title/slug required.');
    if (!is_array($items)) gm_bad('Items must be array.');

    // Map type to shortcode type name
    $typeMap = [
        'images' => 'image-gallery',
        'videos' => 'video-gallery',
        'pdfs'   => 'pdf-gallery',
        'combined' => 'combined-gallery',
    ];

    $gallery = [
        'type'      => $typeMap[$type] ?? $type,
        'title'     => $title,
        'slug'      => $slug,
        'layout'    => $layout,
        'columns'   => $columns,
        'rowheight' => $rowheight,
        'sort_tools'   => $sortTools,
        'sort_default' => $sortDef,
        'items'     => $items,
        'updated'   => date('c'),
    ];

    $dir = $GALLERIES_BASE . '/' . $type;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/' . $slug . '.json';
    $written = file_put_contents($path, json_encode($gallery, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    if ($written === false) gm_bad('Failed to write gallery file.');
    @chmod($path, 0664);

    // ── .cache thumb generation ──
    $thumbResults = ['generated' => 0, 'skipped' => 0, 'failed' => []];

    // Include thumb generation functions from media-cache.php
    // We can't include it directly (it has a router that calls exit), so we
    // replicate the key functions or call the ensure_thumb API internally.
    foreach ($items as $item) {
        $itemPath = is_string($item) ? $item : ($item['path'] ?? '');
        if ($itemPath === '' || strpos($itemPath, '/media/') !== 0) {
            $thumbResults['skipped']++;
            continue;
        }
        $fsPath = $SITE_BASE . $itemPath;
        if (!is_file($fsPath)) {
            $thumbResults['skipped']++;
            continue;
        }

        $ext = strtolower(pathinfo($itemPath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','avif'], true)) $kind = 'image';
        elseif (in_array($ext, ['mp4','webm','mov','m4v'], true)) $kind = 'video';
        elseif ($ext === 'pdf') $kind = 'pdf';
        else { $thumbResults['skipped']++; continue; }

        $cacheDir = dirname($fsPath) . '/.cache';
        $thumbPath = $cacheDir . '/' . pathinfo($itemPath, PATHINFO_FILENAME) . '.jpg';
        if (is_file($thumbPath)) {
            $thumbResults['skipped']++;
            continue;
        }

        // Call ensure_thumb API via internal HTTP request
        $apiUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin/modules/MediaThumbs/api/media-cache.php?action=ensure_thumb&kind=' . urlencode($kind) . '&src=' . urlencode($itemPath);
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'header'  => 'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '') . "\r\n",
        ]]);
        $res = @file_get_contents($apiUrl, false, $ctx);
        if ($res !== false) {
            $resData = json_decode($res, true);
            if (!empty($resData['success'])) {
                $thumbResults['generated']++;
            } else {
                $thumbResults['failed'][] = $itemPath;
            }
        } else {
            $thumbResults['failed'][] = $itemPath;
        }
    }

    // Build shortcode
    if ($type === 'combined') {
        $shortcode = '[[gallery:' . $slug . ']]';
    } else {
        $shortcode = '[[' . $typeMap[$type] . ':' . $slug . ']]';
    }

    gm_json(true, [
        'message'   => 'Gallery saved.',
        'slug'      => $slug,
        'shortcode' => $shortcode,
        'thumbs'    => $thumbResults,
    ]);

/* ── delete_gallery ─────────────────────────────────────────────── */
} elseif ($action === 'delete_gallery') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    $type = $input['type'] ?? $_GET['type'] ?? '';
    $slug = gm_slug($input['slug'] ?? $_GET['slug'] ?? '');
    if (!in_array($type, $VALID_TYPES, true)) gm_bad('Invalid type.');
    if ($slug === '') gm_bad('Missing slug.');
    $path = $GALLERIES_BASE . '/' . $type . '/' . $slug . '.json';
    if (!is_file($path)) gm_bad('Gallery not found.');
    if (!unlink($path)) gm_bad('Failed to delete gallery file.');
    gm_json(true, ['message' => 'Gallery deleted.']);

} else {
    gm_bad('Unknown or missing action.');
}
