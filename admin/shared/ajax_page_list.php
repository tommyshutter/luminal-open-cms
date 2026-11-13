<?php
/**
 * Luminal CMS — Page List API
 *
 * Returns JSON list of CMS pages with title, slug, snippet, date.
 * Shared endpoint used by page-browser.js in HTMLEditor and MDEditor.
 *
 * @package    LuminalCMS
 * @module     Shared
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

header('Content-Type: application/json; charset=UTF-8');

$pagesDir = SITE_ROOT . '/admin/data/pages';
$out = [];

if (is_dir($pagesDir)) {
    foreach (scandir($pagesDir) as $d) {
        if ($d[0] === '.' || $d === 'pages_trash' || !is_dir($pagesDir . '/' . $d)) continue;
        $dir = $pagesDir . '/' . $d;
        $cand = array_merge(glob($dir . '/*.rev.json') ?: [], glob($dir . '/*.json') ?: []);
        if (!$cand) continue;
        usort($cand, fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $j = json_decode(@file_get_contents($cand[0]), true) ?: [];
        $raw = $j['components']['main_content']['content'] ?? $j['left_content'] ?? '';
        $snippet = mb_substr(strip_tags(preg_replace('/\[\[[^\]]*\]\]/', '', $raw)), 0, 120);
        $title = $j['page_title'] ?? $j['title'] ?? $d;
        $mtime = @filemtime($cand[0]) ?: 0;
        $out[] = [
            'slug'    => $d,
            'title'   => $title,
            'snippet' => $snippet,
            'date'    => $mtime ? date('M j, Y', $mtime) : '',
            'mtime'   => $mtime,
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

echo json_encode(['success' => true, 'pages' => $out]);
