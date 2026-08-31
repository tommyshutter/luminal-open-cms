<?php
/**
 * AJAX: scan thumbs and summarize (PDFs are counted but NEVER missing)
 * @file  /admin/ajax/scan-thumbs.php
 * @since 2025-09-06
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
require_once SITE_ROOT . '/includes/thumbs.php';

function web_from_abs(string $abs): string {
    $root = rtrim(SITE_ROOT, '/');
    $abs  = str_replace('\\','/',$abs);
    if (str_starts_with($abs, $root)) return substr($abs, strlen($root)) ?: '/';
    return $abs;
}
function abs_from_web(string $web): string {
    if ($web === '' || preg_match('~^https?://~i',$web)) return '';
    if ($web[0] !== '/') $web = '/'.ltrim($web,'/');
    return SITE_ROOT.$web;
}
function ext_kind(string $p): ?string {
    $e = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    if (in_array($e,['jpg','jpeg','png','gif','webp','bmp'], true)) return 'image';
    if (in_array($e,['mp4','m4v','mov','webm','ogv'], true)) return 'video';
    if ($e==='pdf') return 'pdf';
    return null;
}

try {
    $base  = isset($_GET['base']) ? (string)$_GET['base'] : '/media';
    $kind  = isset($_GET['kind']) ? (string)$_GET['kind'] : 'all';
    $q     = isset($_GET['q'])    ? (string)$_GET['q']    : '';
    $limit = (int)($_GET['limit'] ?? 500);
    if ($limit <= 0) $limit = 500;

    if ($base === '' || $base[0] !== '/') $base = '/'.ltrim($base,'/');
    $baseAbs = SITE_ROOT . $base;
    if (!is_dir($baseAbs)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Base folder not found: '.$base]);
        exit;
    }

    $totScanned = 0; $totOk = 0; $totMiss = 0;
    $byKind = ['image'=>['total'=>0,'missing'=>0],'video'=>['total'=>0,'missing'=>0],'pdf'=>['total'=>0,'missing'=>0]];
    $byFolder = [];  // folder => ['folder'=>..., 'total'=>N, 'missing'=>M (images+videos only)]
    $missing = [];   // sample of missing items (images+videos only)

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($baseAbs, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            function (SplFileInfo $f) { return $f->getFilename() !== '.cache'; }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $fi) {
        if (!$fi->isFile()) continue;
        $abs = $fi->getPathname();
        $web = web_from_abs($abs);

        $k = ext_kind($web);
        if ($k === null) continue;
        if ($kind !== 'all' && $k !== $kind) continue;

        if ($q !== '') {
            $ql = strtolower($q);
            if (!str_contains(strtolower($web), $ql)) continue;
        }

        $totScanned++;
        $byKind[$k]['total'] = ($byKind[$k]['total'] ?? 0) + 1;

        $folder = dirname($web);
        if (!isset($byFolder[$folder])) $byFolder[$folder] = ['folder'=>$folder,'total'=>0,'missing'=>0];
        $byFolder[$folder]['total']++;

        // PDFs: count but NEVER mark missing
        if ($k === 'pdf') {
            $totOk++; // treat as OK (thumbs N/A)
            if ($totScanned >= $limit) break;
            continue;
        }

        // images/videos: resolve thumb normally
        $thumb = ($k === 'image') ? ri_thumb_for_image($web, null)
                : (($k === 'video') ? ri_thumb_for_video($web, null) : null);

        $ok = $thumb !== null && $thumb !== '';
        if ($ok) {
            $totOk++;
        } else {
            $totMiss++;
            $byKind[$k]['missing'] = ($byKind[$k]['missing'] ?? 0) + 1;
            $byFolder[$folder]['missing']++;

            if (count($missing) < 50) {
                $cands = ri_thumb_candidates_for($k, $web, null);
                $first = $cands[0] ?? '';
                $missing[] = [
                    'kind' => $k,
                    'src_web' => $web,
                    'first_candidate' => $first,
                ];
            }
        }

        if ($totScanned >= $limit) break;
    }

    // sort folders by missing desc
    usort($byFolder, function($a,$b){
        if ($a['missing'] === $b['missing']) return $a['total'] <=> $b['total'];
        return $b['missing'] <=> $a['missing'];
    });

    echo json_encode([
        'success'=>true,
        'totals'=>[
            'scanned'=>$totScanned,
            'ok'=>$totOk,
            'missing'=>$totMiss,         // excludes PDFs by definition
            'byKind'=>$byKind,           // 'pdf' => missing always 0
        ],
        'folders'=>array_slice(array_values($byFolder), 0, 100),
        'missing'=>$missing,            // images/videos only
        'note'=>'PDFs are counted but never flagged as missing; thumbs are N/A for PDFs.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
?>