<?php
/**
 * convert-to-html-block.php — turn a curated HTML selection into a reusable
 * [[html-block:slug]] shortcode, capturing its CSS self-contained.
 *
 * The heavy lifting is in includes/css_scope.php (residual-usage scope engine).
 * This endpoint wires it to the page store + HTMLBlocks store:
 *   • extract inline <style>/<script> from the selection
 *   • scope-classify the page CSS the selection uses (move/copy/promote + :root vars)
 *   • auto-name  {Title} Html Block  /  {slug}-html-block
 *   • save the block, rebuild the index, return the shortcode + a report
 *
 * COPY-SAFE by default: the page's own {slug}.css is NOT modified in this version
 * (the block gets a self-contained copy). We REPORT what is movable so pruning can
 * be an explicit, later step. Articles are refused (different content type).
 *
 * @file admin/modules/PageManager/api/convert-to-html-block.php
 */
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/css_scope.php';
require_once dirname(__DIR__) . '/includes/html_block_store.php';

$SITE_ROOT = realpath(dirname(__DIR__, 4));
$PAGES_DIR = $SITE_ROOT . '/admin/data/pages';
$HB_DIR    = $SITE_ROOT . '/admin/data/html-blocks';

function cvt_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function cvt_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

// ── payload ──────────────────────────────────────────────────────────────
$ctype = $_SERVER['CONTENT_TYPE'] ?? '';
$in = [];
if (stripos($ctype, 'application/json') !== false) {
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
} else {
    $in = $_POST;
}
$slug      = cvt_slugify((string)($in['slug'] ?? ''));
$selection = (string)($in['selection'] ?? '');
$main      = (string)($in['main_content'] ?? '');
$right     = (string)($in['right_column'] ?? '');
$titleIn   = trim((string)($in['title'] ?? ''));
$commit    = !isset($in['commit']) || filter_var($in['commit'], FILTER_VALIDATE_BOOLEAN);

if ($slug === '')      cvt_fail('Missing page slug.');
if (trim($selection) === '') cvt_fail('Nothing selected to convert.');

// ── article guard: never treat an ArticlesManager article like a page ──────
$articleSet = [];
foreach ([
    $SITE_ROOT . '/admin/data/ArticlesManager/articles.json',
    $SITE_ROOT . '/admin/data/Articles/index.json',
] as $f) {
    if (!is_file($f)) continue;
    $d = json_decode(@file_get_contents($f), true);
    if (!is_array($d)) continue;
    foreach ((array)($d['articles'] ?? $d) as $r) {
        $s = is_array($r) ? ($r['slug'] ?? '') : (is_string($r) ? $r : '');
        if ($s !== '') $articleSet[strtolower($s)] = true;
    }
}
if (isset($articleSet[$slug])) {
    cvt_fail('“' . htmlspecialchars($slug) . '” is an Article, not a Page. Convert-to-HTML-Block is page-only for now (Articles use a different layout/store).', 409);
}

// ── page CSS (path-guarded) ────────────────────────────────────────────────
$pageDir = realpath($PAGES_DIR . '/' . $slug);
if ($pageDir === false || strpos($pageDir, realpath($PAGES_DIR)) !== 0) cvt_fail('Unknown page.', 404);
$pageCssFile = $pageDir . '/' . $slug . '.css';
$pageCss = is_file($pageCssFile) ? (string)file_get_contents($pageCssFile) : '';

// ── peel inline <style>/<script> out of the selection ──────────────────────
$inlineStyles = '';
$cleanHtml = preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function ($m) use (&$inlineStyles) {
    $inlineStyles .= $m[1] . "\n"; return '';
}, $selection);
$js = '';
$cleanHtml = preg_replace_callback('/<script[^>]*>(.*?)<\/script>/is', function ($m) use (&$js) {
    $js .= $m[1] . "\n"; return '';
}, $cleanHtml);
$cleanHtml = trim($cleanHtml);

// ── residual = the rest of the page (both columns) minus this selection ────
$residual = $main . "\n" . $right;
$pos = strpos($residual, $selection);
if ($pos !== false) $residual = substr_replace($residual, '', $pos, strlen($selection));

// ── classify ───────────────────────────────────────────────────────────────
$scopeCss = $pageCss . "\n" . $inlineStyles;
$r = pmcss_classify($scopeCss, $cleanHtml, $residual);

// ── name it (Tom's convention: "{Title} Html Block" / "{slug}-html-block") ─
$title = $titleIn;
if ($title === '' && preg_match('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $cleanHtml, $hm)) {
    $title = trim(preg_replace('/\s+/', ' ', strip_tags($hm[1])));
}
if ($title === '') $title = ucwords(str_replace('-', ' ', $slug)) . ' Block';
// Keep names sane — a long heading/paragraph must not become a 150-char slug.
$title = trim(preg_replace('/\s+/', ' ', $title));
if (mb_strlen($title) > 70) $title = rtrim(mb_substr($title, 0, 70)) . '…';
$blockTitle = $title . ' Html Block';
$base = trim(substr(cvt_slugify($title), 0, 55), '-');
if ($base === '') $base = 'block-' . substr(bin2hex(random_bytes(3)), 0, 6);
$blockSlug = $base . '-html-block';

if (!is_dir($HB_DIR)) @mkdir($HB_DIR, 0775, true);
// ensure unique
$try = $blockSlug; $i = 2;
while (is_file($HB_DIR . '/' . $try . '.json')) { $try = $blockSlug . '-' . $i; $i++; }
$blockSlug = $try;

$report = [
    'stats'        => $r['stats'],
    'moved'        => $r['moved'],
    'copied'       => $r['copied'],
    'promote'      => $r['promote'],
    'promote_vars' => $r['promote_vars'],
    'css_bytes'    => strlen($r['captured_css']),
    'note'         => 'Copy-safe: page CSS left intact. ' . $r['stats']['moved'] . ' rule(s) are movable (pruneable later).',
];
$shortcode = '[[html-block:' . $blockSlug . ']]';

if (!$commit) {
    echo json_encode(['ok' => true, 'preview' => true, 'slug' => $blockSlug, 'title' => $blockTitle, 'shortcode' => $shortcode, 'report' => $report]);
    exit;
}

// ── write the block + rebuild index ────────────────────────────────────────
$now = date('c');
$block = [
    'id'          => 'hb_' . substr(bin2hex(random_bytes(4)), 0, 8),
    'slug'        => $blockSlug,
    'title'       => $blockTitle,
    'html'        => $cleanHtml,
    'css'         => $r['captured_css'],
    'js'          => trim($js),
    'tags'        => ['converted'],
    'created'     => $now,
    'updated'     => $now,
    'usage_count' => 0,
];
if (!hbstore_save($HB_DIR, $block)) cvt_fail('Failed to write the block.', 500);
hbstore_reindex($HB_DIR);

echo json_encode([
    'ok'        => true,
    'slug'      => $blockSlug,
    'title'     => $blockTitle,
    'shortcode' => $shortcode,
    'report'    => $report,
]);
