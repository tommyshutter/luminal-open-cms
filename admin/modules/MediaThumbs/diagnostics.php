<?php
/**
 * Media Thumb Diagnostics (read-only)
 * @file   /admin/media-thumb-diagnostics.php
 * @since  2025-09-06
 *
 * What it does
 *   • Scans /media (or a chosen subfolder) for images/videos/pdfs
 *   • For each file, shows:
 *       - kind (image|video|pdf), web src, clickable
 *       - resolved thumbnail (clickable) or MISSING
 *       - ordered candidate list with exists/size/mtime
 *   • Filters: base folder, kind, search, only missing, scan limit
 *   • “Trace” button hits /admin/modules/MediaThumbs/api/thumb-trace.php for the same item
 *
 * Requirements
 *   • /includes/thumbs.php (unified resolver)
 *   • (optional) /admin/modules/MediaThumbs/api/thumb-trace.php for the “Trace” button
 *   • Your normal admin CSS + admin menu include
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/includes/thumbs.php';

// --- helpers ---
function ri_admin_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ri_web_from_abs(string $abs): string {
    $root = rtrim(SITE_ROOT, '/');
    $abs  = str_replace('\\', '/', $abs);
    if (str_starts_with($abs, $root)) {
        $web = substr($abs, strlen($root));
        return $web === '' ? '/' : $web;
    }
    return $abs; // fallback
}
function ri_abs_from_web(string $web): string {
    $web = trim($web);
    if ($web === '' || preg_match('~^https?://~i', $web)) return '';
    if ($web[0] !== '/') $web = '/' . $web;
    return SITE_ROOT . $web;
}
function ri_ext_kind(string $path): ?string {
    $e = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($e, ['jpg','jpeg','png','gif','webp','bmp'], true)) return 'image';
    if (in_array($e, ['mp4','m4v','mov','webm','ogv'], true)) return 'video';
    if ($e === 'pdf') return 'pdf';
    return null;
}
function ri_exists_abs(string $abs): bool {
    clearstatcache(true, $abs);
    return is_file($abs);
}
function ri_stat_abs(string $abs): array {
    clearstatcache(true, $abs);
    if (!is_file($abs)) return ['exists'=>false];
    return [
        'exists'=>true,
        'size'=> @filesize($abs),
        'mtime'=> ($t = @filemtime($abs)) ? date('c', $t) : null,
    ];
}

// --- inputs ---
$artistName = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Site', ENT_QUOTES, 'UTF-8');

$baseOpt   = $_GET['base']   ?? '/media';
$kindOpt   = $_GET['kind']   ?? 'all';     // all|image|video|pdf
$qOpt      = $_GET['q']      ?? '';
$onlyMiss  = isset($_GET['only_missing']) && $_GET['only_missing'] === '1';
$limitOpt  = (int)($_GET['limit'] ?? 300);
if ($limitOpt <= 0) $limitOpt = 300;

// normalize base
$baseOpt = trim($baseOpt);
if ($baseOpt === '' || $baseOpt[0] !== '/') $baseOpt = '/' . ltrim($baseOpt, '/');
$baseAbs = SITE_ROOT . $baseOpt;
if (!is_dir($baseAbs)) {
    $baseOpt = '/media';
    $baseAbs = SITE_ROOT . $baseOpt;
}

// --- scan ---
$rows = [];
$scanned = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($baseAbs, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
        function (SplFileInfo $f, $key, $iter) {
            // skip nested .cache for listing sources, but still allow media in normal dirs
            if ($f->isDir() && $f->getFilename() === '.cache') return false;
            return true;
        }
    ),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($it as $fi) {
    if (!$fi->isFile()) continue;

    $abs = $fi->getPathname();
    $web = ri_web_from_abs($abs);

    $k = ri_ext_kind($web);
    if ($k === null) continue;

    if ($kindOpt !== 'all' && $k !== $kindOpt) continue;

    if ($qOpt !== '') {
        $q = strtolower($qOpt);
        if (!str_contains(strtolower($web), $q) && !str_contains(strtolower($abs), $q)) continue;
    }

    // Resolve thumb using shared code
    $thumb = match ($k) {
        'image' => ri_thumb_for_image($web, null),
        'video' => ri_thumb_for_video($web, null),
        'pdf'   => ri_thumb_for_pdf($web, null),
        default => null,
    };

    if ($onlyMiss && $thumb !== null && $thumb !== '') continue;

    // Build candidate list (same order as renderers)
    $cands = ri_thumb_candidates_for($k, $web, null);

    // Stat resolved
    $resolvedStat = null;
    if ($thumb && !preg_match('~^https?://~i', $thumb)) {
        $resolvedStat = ri_stat_abs(ri_abs_from_web($thumb));
    }

    $rows[] = [
        'kind'     => $k,
        'src_web'  => $web,
        'thumb'    => $thumb,
        'cands'    => $cands,
        'stat'     => $resolvedStat,
    ];

    $scanned++;
    if ($scanned >= $limitOpt) break;
}

// --- page ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Media Thumb Diagnostics</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/admin/css/admin.css">
  <link rel="stylesheet" href="/admin/css/admin-styles.css">
  <style>
    .admin-container { max-width: 1200px; margin: 0 auto; padding: 16px; }
    .page-title { display:flex; align-items:baseline; gap:12px; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.8rem; }
    .badge.ok { background:#064e3b; color:#d1fae5; }
    .badge.miss { background:#7f1d1d; color:#fee2e2; }
    .badge.warn { background:#78350f; color:#ffedd5; }
    form.diag-filters { display:grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap:10px; align-items:end; margin:16px 0 20px; }
    form.diag-filters label { display:block; font-size:.85rem; color:#94a3b8; margin-bottom:4px; }
    form.diag-filters input[type="text"], select { width:100%; padding:8px 10px; border-radius:8px; border:1px solid #334155; background:#0b1220; color:#e5e7eb; }
    form.diag-filters .chk { display:flex; align-items:center; gap:8px; }
    .grid { display:grid; grid-template-columns: 1fr; gap:12px; }
    @media (min-width: 900px) {
      .grid { grid-template-columns: 1fr 1fr; }
    }
    .card { background:#0b0f19; border:1px solid #1f2937; border-radius:12px; padding:14px; }
    .row-top { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
    .row-top .kind { text-transform:uppercase; font-size:.75rem; letter-spacing:.08em; opacity:.75; }
    .row-top .actions a, .row-top .actions button { margin-left:8px; }
    .paths { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:.85rem; line-height:1.45; color:#cbd5e1; }
    .paths .src { color:#e2e8f0; }
    .paths .thumb a { color:#7dd3fc; text-decoration:none; }
    .cands { margin-top:8px; border-top:1px dashed #334155; padding-top:8px; }
    .cand { display:flex; align-items:center; gap:8px; }
    .cand .state { width:9px; height:9px; border-radius:50%; display:inline-block; }
    .state.ok { background:#10b981; }
    .state.miss { background:#ef4444; }
    .state.remote { background:#60a5fa; }
    .tiny { font-size:.8rem; opacity:.8; }
    .btn { padding:6px 10px; border-radius:8px; border:1px solid #334155; background:#0b1220; color:#e5e7eb; cursor:pointer; text-decoration:none; }
    .btn:hover { background:#111827; }
    .note { margin-top:12px; color:#94a3b8; font-size:.9rem; }
  </style>
</head>
<body>
  <header class="admin-header">
    <div class="admin-header-inner">
      <h1 class="site-name"><?php echo $artistName; ?></h1>
      <span class="admin-subtitle">Admin</span>
    </div>
  </header>

  <?php include SITE_ROOT . '/admin/admin_menu.php'; ?>

  <main class="admin-container">
    <div class="page-title">
      <h2>Media Thumb Diagnostics</h2>
      <span class="badge ok">Scanned: <?php echo (int)$scanned; ?></span>
      <span class="badge warn">Base: <?php echo ri_admin_h($baseOpt); ?></span>
    </div>

    <form class="diag-filters" method="get" action="">
      <div>
        <label for="base">Base folder</label>
        <select name="base" id="base">
          <?php
            $bases = ['/media','/media/images','/media/videos','/media/pdfs'];
            foreach ($bases as $b) {
              $sel = ($b === $baseOpt) ? 'selected' : '';
              echo '<option value="'.ri_admin_h($b).'" '.$sel.'>'.$b.'</option>';
            }
          ?>
        </select>
      </div>
      <div>
        <label for="kind">Kind</label>
        <select name="kind" id="kind">
          <?php
            foreach (['all','image','video','pdf'] as $k) {
              $sel = ($k === $kindOpt) ? 'selected' : '';
              echo '<option value="'.$k.'" '.$sel.'>'.strtoupper($k).'</option>';
            }
          ?>
        </select>
      </div>
      <div>
        <label for="q">Search (path substring)</label>
        <input type="text" name="q" id="q" value="<?php echo ri_admin_h($qOpt); ?>" placeholder="e.g. shows/2024 or clip01">
      </div>
      <div class="chk">
        <label for="limit">Limit</label>
        <input type="text" name="limit" id="limit" value="<?php echo (int)$limitOpt; ?>" style="width:90px">
        <label><input type="checkbox" name="only_missing" value="1" <?php echo $onlyMiss?'checked':''; ?>> Only missing thumbs</label>
      </div>
      <div>
        <button class="btn" type="submit">Rescan</button>
      </div>
    </form>

    <?php if (!$rows): ?>
      <div class="card">
        <p>No items matched. Try widening the base, removing filters, or increasing the limit.</p>
      </div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($rows as $row):
          $kind = $row['kind'];
          $srcW = $row['src_web'];
          $thumb = $row['thumb'];
          $hasThumb = ($thumb && $thumb !== '');
          $thumbAbs = $hasThumb && !preg_match('~^https?://~i', $thumb) ? ri_abs_from_web($thumb) : '';
          $stat = $row['stat'] ?? null;
        ?>
        <div class="card">
          <div class="row-top">
            <div class="kind"><?php echo strtoupper($kind); ?></div>
            <div class="actions">
              <?php
                $traceUrl = '/admin/modules/MediaThumbs/api/thumb-trace.php?kind='.rawurlencode($kind).'&src='.rawurlencode($srcW);
              ?>
              <a class="btn" href="<?php echo ri_admin_h($srcW); ?>" target="_blank" rel="noopener">Open Src</a>
              <a class="btn" href="<?php echo ri_admin_h($traceUrl); ?>" target="_blank" rel="noopener">Trace</a>
              <?php if ($hasThumb): ?>
                <a class="btn" href="<?php echo ri_admin_h($thumb); ?>" target="_blank" rel="noopener">Open Thumb</a>
              <?php endif; ?>
            </div>
          </div>
          <div class="paths">
            <div class="src"><strong>SRC</strong>: <a href="<?php echo ri_admin_h($srcW); ?>" target="_blank" rel="noopener"><?php echo ri_admin_h($srcW); ?></a></div>
            <div class="thumb">
              <strong>THUMB</strong>:
              <?php if ($hasThumb): ?>
                <a href="<?php echo ri_admin_h($thumb); ?>" target="_blank" rel="noopener"><?php echo ri_admin_h($thumb); ?></a>
                <?php if ($stat && !empty($stat['exists'])): ?>
                  <span class="badge ok">exists</span>
                  <span class="tiny">size: <?php echo number_format((int)$stat['size']); ?>B</span>
                  <?php if (!empty($stat['mtime'])): ?><span class="tiny">mtime: <?php echo ri_admin_h($stat['mtime']); ?></span><?php endif; ?>
                <?php else: ?>
                  <?php if (preg_match('~^https?://~i', $thumb)): ?>
                    <span class="badge warn">remote</span>
                  <?php else: ?>
                    <span class="badge miss">missing on disk</span>
                  <?php endif; ?>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge miss">MISSING</span>
              <?php endif; ?>
            </div>
          </div>

          <?php
            $cands = $row['cands'];
            if ($cands):
          ?>
          <div class="cands">
            <div class="tiny" style="margin-bottom:6px; opacity:.8">Candidates (resolver order):</div>
            <?php foreach ($cands as $c):
              $isRemote = preg_match('~^https?://~i', $c);
              $stateCls = $isRemote ? 'remote' : (ri_exists_abs(ri_abs_from_web($c)) ? 'ok' : 'miss');
            ?>
            <div class="cand">
              <span class="state <?php echo $stateCls; ?>"></span>
              <a href="<?php echo ri_admin_h($c); ?>" target="_blank" rel="noopener"><?php echo ri_admin_h($c); ?></a>
              <?php if (!$isRemote):
                $st = ri_stat_abs(ri_abs_from_web($c));
                if (!empty($st['exists'])) {
                  echo '<span class="tiny">['.number_format((int)$st['size']).'B';
                  if (!empty($st['mtime'])) echo ', '.$st['mtime'];
                  echo ']</span>';
                } else {
                  echo '<span class="tiny" style="opacity:.7">[missing]</span>';
                }
              endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="note">
            • Resolver = <code>/includes/thumbs.php</code>. Folder-local <code>.cache/</code> is preferred, then legacy central caches.  
            • “Trace” opens JSON from <code>/admin/modules/MediaThumbs/api/thumb-trace.php</code> for this item.
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
