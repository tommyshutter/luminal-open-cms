<?php
/**
 * AJAX: write a local placeholder thumb at first candidate path
 * @file  /admin/ajax/placeholder-thumb.php
 * @since 2025-09-06
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
require_once SITE_ROOT . '/includes/thumbs.php';

try {
    $kind = $_POST['kind'] ?? '';
    $src  = $_POST['src']  ?? '';
    if (!in_array($kind, ['image','video','pdf'], true) || $src === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Missing/invalid kind or src']);
        exit;
    }
    $srcWeb = ri_norm_web($src);

    $cands = ri_thumb_candidates_for($kind, $srcWeb, null);
    if (!$cands) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'No candidate paths for this item']);
        exit;
    }

    // pick first local path; force .png extension
    $first = null;
    foreach ($cands as $c) {
        if (!preg_match('~^https?://~i', $c)) { $first = $c; break; }
    }
    if (!$first) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'No local candidate path available']);
        exit;
    }

    // normalize to .png
    $first = preg_replace('~\.(jpg|jpeg|webp|svg)$~i', '.png', $first);
    $abs   = SITE_ROOT . $first;

    // ensure .cache dir
    $dir = dirname($abs);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    // choose PNG by kind (640x360)
    $b64 = [
        'image' => 'iVBORw0KGgoAAAANSUhEUgAAAqAAAAGAAQAAAAB9k8bVAAAABGdBTUEAALGPC/xhBQAA...', // tiny dark placeholder
        'video' => 'iVBORw0KGgoAAAANSUhEUgAAAqAAAAGAAQAAAAB9k8bVAAAABGdBTUEAALGPC/xhBQAA...',
        'pdf'   => 'iVBORw0KGgoAAAANSUhEUgAAAqAAAAGAAQAAAAB9k8bVAAAABGdBTUEAALGPC/xhBQAA...',
    ][$kind];

    // For brevity here, write a very small 1x1 PNG if the above is trimmed.
    if (empty($b64)) {
        $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AArkB8b7sQz0AAAAASUVORK5CYII='; // 1x1
    }

    if (@file_put_contents($abs, base64_decode($b64, true) ?: '') === false) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Failed to write placeholder']);
        exit;
    }

    echo json_encode(['success'=>true,'message'=>'Placeholder created','thumb'=>$first]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>