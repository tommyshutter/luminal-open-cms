<?php
/**
 * AJAX: set/remove mapping in .cache/_thumbs.json
 * @file  /admin/ajax/thumbs-map.php
 * @since 2025-09-06
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
require_once SITE_ROOT . '/includes/thumbs.php';

try {
    $action = $_POST['action'] ?? '';
    $kind   = $_POST['kind'] ?? '';
    $src    = $_POST['src']  ?? '';
    $thumb  = $_POST['thumb'] ?? '';

    if ($action !== 'set' && $action !== 'remove') {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Invalid action']);
        exit;
    }
    if ($src === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Missing src']);
        exit;
    }

    $srcWeb = ri_norm_web($src);
    if (preg_match('~^https?://~i', $srcWeb)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Relink only supports local site media']);
        exit;
    }
    $dirWeb = rtrim(dirname($srcWeb), '/');
    $file   = basename($srcWeb);

    $cacheDirAbs = SITE_ROOT . $dirWeb . '/.cache';
    if (!is_dir($cacheDirAbs)) @mkdir($cacheDirAbs, 0775, true);

    $mapAbs = $cacheDirAbs . '/_thumbs.json';
    $map = [];
    if (is_file($mapAbs)) {
        $raw = @file_get_contents($mapAbs);
        $j = json_decode((string)$raw, true);
        if (is_array($j)) $map = $j;
    }

    if ($action === 'remove') {
        unset($map[$file]);
        @file_put_contents($mapAbs, json_encode($map, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        echo json_encode(['success'=>true,'message'=>'Mapping removed']);
        exit;
    }

    // SET
    if ($thumb === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Missing thumb path']);
        exit;
    }
    $thumbWeb = ri_norm_web($thumb);
    // allow remote? prefer local; check existence only for local
    if (!preg_match('~^https?://~i', $thumbWeb) && !ri_exists_web($thumbWeb)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Thumb file does not exist on disk: '.$thumbWeb]);
        exit;
    }

    $map[$file] = $thumbWeb;
    if (@file_put_contents($mapAbs, json_encode($map, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) === false) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Failed to write map file']);
        exit;
    }

    echo json_encode(['success'=>true,'message'=>'Mapping saved','map_file'=>str_replace(SITE_ROOT,'',$mapAbs)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>