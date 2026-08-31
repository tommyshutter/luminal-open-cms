<?php
/**
 * Luminal CMS — Page Manager Module
 *
 * Core content management system with card UI, modal editor, iframe preview,
 * and revision history. Supports two-column layouts, shortcodes, and components.
 *
 * @package    LuminalCMS
 * @module     PageManager
 * @version    1.0.0
 * @file       /admin/modules/PageManager/PageManager.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/auth.php';

// Sanitize content — converts media AND scrubs WYSIWYG cruft.
// Browser contenteditable (Chrome/Firefox) inserts <div><br></div> on every
// Enter keypress and wraps shortcodes in stray <div> blocks. Over time a
// page accumulates these and they break layout (stack ends up trapped in a
// wrapper div it shouldn't be in, etc.). This runs on every save.
// (Hoisted to file scope so it survives multiple POST requests without a
// "Cannot redeclare" fatal.)
if (!function_exists('ri_sanitize_content')) {
    function ri_sanitize_content(string $html): string {
        // Fix <img src="*.mp4|*.webm|*.ogg|*.mov|*.avi"> → <video>
        $html = preg_replace(
            '/<img\s+([^>]*?)src=["\']([^"\']*\.(?:mp4|webm|ogg|mov|avi))["\']([^>]*)>/i',
            '<video src="$2" controls style="max-width:100%;border-radius:8px" preload="metadata"></video>',
            $html
        );

        // === WYSIWYG cruft scrubber ===
        // Loops to a fixed point (max 5 passes) so nested empties converge
        // in one save. Pattern 1 tolerates attributes on the wrapping div
        // (paste flows / execCommand('justify*') emit `<div style="...">
        // <br></div>`, which is still cruft). Patterns 2 and 3 stay narrow
        // — literal `<div>` only — because attributed empty divs and
        // attributed shortcode wrappers can be intentional (CSS spacers,
        // JS anchors, embed placeholders, user-applied alignment).
        for ($pass = 0; $pass < 5; $pass++) {
            $before = $html;

            // 1. <div ...><br></div> (optionally with whitespace or &nbsp;) → <br>
            $html = preg_replace(
                '~<div\b[^>]*>\s*(?:&nbsp;)?\s*<br\s*/?>\s*(?:&nbsp;)?\s*</div>~i',
                '<br>',
                $html
            );

            // 2. Bare empty <div></div> (no attrs) or div holding only
            //    &nbsp;/whitespace → removed. Narrow by design: an empty
            //    `<div class="spacer">` or `<div id="anchor">` is often
            //    intentional and must survive the save round-trip.
            $html = preg_replace(
                '~<div>\s*(?:&nbsp;|\s)*\s*</div>~i',
                '',
                $html
            );

            // 3. Bare <div>[[shortcode]]</div> (no attrs) → unwrap.
            // Narrow on purpose: attributed wrappers carry user-chosen
            // styling we shouldn't silently strip.
            $html = preg_replace(
                '~<div>\s*(?:&nbsp;|<br\s*/?>|\s)*\s*(\[\[[^\]]+\]\])\s*(?:&nbsp;|<br\s*/?>|\s)*\s*</div>~i',
                '$1',
                $html
            );

            // 4. Collapse runs of 3+ <br> (contenteditable spam) to 2.
            $html = preg_replace('~(?:<br\s*/?>\s*){3,}~i', '<br><br>', $html);

            if ($html === $before) break;
        }

        return $html;
    }
}

requireAuth();

$artistName = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Artist', ENT_QUOTES, 'UTF-8');
$PAGES_DIR   = SITE_ROOT . '/admin/data/pages';
$TRASH_DIR   = SITE_ROOT . '/admin/data/pages/pages_trash';
$PANELS_DIR  = SITE_ROOT . '/panels';
$EVENTS_FILE = SITE_ROOT . '/admin/data/events_master.json';

// User-facing messages array
$MESSAGES = [];

// Directories for galleries
$IMG_DIRS    = [SITE_ROOT.'/admin/data/galleries/images'];
$VID_DIRS    = [SITE_ROOT.'/admin/data/galleries/videos'];
$PDF_DIRS    = [SITE_ROOT.'/admin/data/galleries/pdfs'];

if (!is_dir($PAGES_DIR)) @mkdir($PAGES_DIR, 0775, true);

// --- HELPER FUNCTIONS ---
function ri_list_json_slugs_recursive(array $dirs, &$messages){
  $set=[];
  foreach ($dirs as $root){
    if (!is_dir($root)) continue;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach($it as $fi){
          if(!$fi->isFile() || strtolower($fi->getExtension())!=='json') continue;
          $base=basename($fi->getFilename(),'.json');
          if($base==='index') continue;
          $set[$base]=true;
        }
    } catch (Exception $e) {
        $messages[] = "Notice: Could not read '{$root}'. Check permissions.";
    }
  }
  $out=array_keys($set); sort($out,SORT_NATURAL|SORT_FLAG_CASE); return $out;
}
function ri_safe($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ri_slug($s){ return preg_replace('/[^A-Za-z0-9_\-]/','', (string)$s); }
function ri_make_slug_from_title($t){
  $t=strtolower(trim((string)$t)); $t=preg_replace('/[^a-z0-9]+/','-',$t); $t=trim($t,'-');
  if($t==='') $t='page-'.date('Ymd-His'); return ri_slug($t);
}
function ri_list_panels($dir){
  if(!is_dir($dir)) return [];
  $deny=['background_loader.php','diag_hero.php','load-panel.php','panel-layout-controller.php','panel-left.php','panel-right.php','pdf-proxy.php','img-proxy.php','pdf-viewer.php'];
  $out=[]; foreach(scandir($dir) as $f){ if($f[0]==='.'||substr($f,-4)!=='.php'||in_array($f,$deny,true)) continue; $out[]=$f; }
  sort($out,SORT_NATURAL|SORT_FLAG_CASE); return $out;
}
function ri_latest_page_meta($root,$folder){
  $d=$root.'/'.$folder; if(!is_dir($d)) return ['title'=>$folder,'mtime'=>0,'snippet'=>''];
  $cand=array_merge(glob($d.'/*.rev.json')?:[],glob($d.'/*.json')?:[]); if(!$cand) return ['title'=>$folder,'mtime'=>0,'snippet'=>''];
  usort($cand,fn($a,$b)=>(filemtime($b)?:0)<=>(filemtime($a)?:0));
  $j=json_decode(@file_get_contents($cand[0]),true)?:[];
  $raw = $j['components']['main_content']['content'] ?? $j['left_content'] ?? '';
  $snippet = mb_substr(strip_tags(preg_replace('/\[\[[^\]]*\]\]/','',$raw)), 0, 120);
  return['title'=>$j['page_title']??$j['title']??$folder,'mtime'=>@filemtime($cand[0])?:0,'snippet'=>$snippet];
}
function ri_list_pages($root){
  $out=[]; if(!is_dir($root)) return $out;
  foreach(scandir($root) as $d){ if($d[0]==='.'||$d==='pages_trash'||!is_dir($root.'/'.$d)) continue; $m=ri_latest_page_meta($root,$d); $out[]=['folder'=>$d,'title'=>$m['title'],'mtime'=>$m['mtime'],'snippet'=>$m['snippet']??'']; }
  usort($out,fn($a,$b)=>$b['mtime']<=>$a['mtime']); return $out;
}

function ri_list_aip_pages($aipDir){
  $out=[]; if(!is_dir($aipDir)) return $out;
  foreach(scandir($aipDir) as $slug){
    if($slug[0]==='.'||!is_dir($aipDir.'/'.$slug)) continue;
    $indexFile = $aipDir.'/'.$slug.'/index.html';
    $metaFile = $aipDir.'/'.$slug.'/meta.json';
    if(!is_file($indexFile)) continue;
    $title = $slug; $mtime = filemtime($indexFile)?:0;
    if(is_file($metaFile)){
      $meta = json_decode(@file_get_contents($metaFile),true);
      if(is_array($meta) && !empty($meta['title'])) $title = $meta['title'];
      if(!empty($meta['published'])) $mtime = strtotime($meta['published'])?:$mtime;
    }
    $out[]=['folder'=>$slug,'title'=>$title,'mtime'=>$mtime,'snippet'=>'AI-generated page','is_aip'=>true,'source'=>$meta['source']??'local'];
  }
  usort($out,fn($a,$b)=>$b['mtime']<=>$a['mtime']); return $out;
}
// Article slugs live in the ArticlesManager store; a "page" whose folder matches one
// is really an article (they share the pages dir). Used to badge + filter the catalog
// ("Display Articles" is OFF by default). No per-page flag exists — membership in the
// article store is the authoritative signal.
//
// CANONICAL store = admin/data/ArticlesManager/articles.json (what ArticleStore reads/writes).
// The legacy admin/data/Articles/index.json is a stale/absent second store on many sites — reading
// ONLY it (the old behavior) left every article leaking into the Pages tab wherever it was missing
// (e.g. a site with no index.json showed 11 articles as pages). Union BOTH so the
// filter is correct regardless of which store a site populated — derive-on-read, no data migration.
function ri_article_slugs($siteRoot){
  $set = [];
  foreach ([
    $siteRoot . '/admin/data/ArticlesManager/articles.json',  // canonical
    $siteRoot . '/admin/data/Articles/index.json',            // legacy fallback
  ] as $f){
    if(!is_file($f)) continue;
    $d = json_decode(@file_get_contents($f), true);
    if(!is_array($d)) continue;
    $rows = $d['articles'] ?? $d;
    foreach((array)$rows as $r){
      $slug = is_array($r) ? ($r['slug'] ?? '') : (is_string($r) ? $r : '');
      if($slug !== '') $set[strtolower($slug)] = true;
    }
  }
  return $set;
}
// Revision retention model (2026-07-08). A page dir holds the live base `{slug}.json`
// (never a revision) plus timestamped snapshots. Three revision classes, by filename:
//   {slug}.{ts}.origin.rev.json  — the FIRST save ever for this page. NEVER pruned.
//   {slug}.{ts}.rev.json         — a deliberate (committed) revision. Kept up to KEEP_COMMITTED.
//   {slug}.{ts}.auto.rev.json    — an autosave. Rolling buffer, kept up to KEEP_AUTO.
// The old "keep latest 5 of everything" clobbered deliberate revisions with churny autosaves
// and deleted the origin first. This split pins the origin + committed history and only
// rotates the autosave buffer. Legacy pages (unmarked .rev.json) are all treated as committed
// and their OLDEST file is protected as a de-facto origin, so no existing history is lost.
if(!defined('RI_REV_KEEP_AUTO'))      define('RI_REV_KEEP_AUTO', 8);
if(!defined('RI_REV_KEEP_COMMITTED')) define('RI_REV_KEEP_COMMITTED', 25);
// Only real revision snapshots — NOT the live base {slug}.json (which must never be pruned).
function ri_rev_files_sorted($dir){ if(!is_dir($dir)) return[]; $cand=glob($dir.'/*.rev.json')?:[]; usort($cand,fn($a,$b)=>(filemtime($b)?:0)<=>(filemtime($a)?:0)); return $cand; }
function ri_rev_is_auto($f){   return (bool)preg_match('/\.auto\.rev\.json$/',   $f); }
function ri_rev_is_origin($f){ return (bool)preg_match('/\.origin\.rev\.json$/', $f); }
function ri_rev_prune($dir){
  $files = ri_rev_files_sorted($dir); // newest-first, *.rev.json only
  if(!$files) return 0;
  // Origin = explicit marker, else the single OLDEST snapshot (protect legacy history).
  $origin = null;
  foreach($files as $f){ if(ri_rev_is_origin($f)){ $origin=$f; break; } }
  if($origin === null){ $origin = $files[count($files)-1]; }
  $autos=[]; $committed=[];
  foreach($files as $f){
    if($f === $origin) continue;                    // never prune the origin
    if(ri_rev_is_auto($f)) $autos[]=$f; else $committed[]=$f;
  }
  // Both lists are newest-first; drop the overflow past each cap.
  $delete = array_merge(array_slice($autos, RI_REV_KEEP_AUTO), array_slice($committed, RI_REV_KEEP_COMMITTED));
  $n=0; foreach($delete as $f){ if(@unlink($f)) $n++; }
  return $n;
}
// Back-compat shim: any remaining caller of the old name gets the new origin-safe behavior.
function ri_rev_prune_to_5($dir){ return ri_rev_prune($dir); }

// Load events for the picker
function ri_load_events($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(@file_get_contents($file), true);
    if (!is_array($data)) return [];
    $events = array_filter($data, fn($e) => ($e['status'] ?? '') === 'published');
    usort($events, fn($a,$b) => strtotime($a['start_date']??'') - strtotime($b['start_date']??''));
    return $events;
}

// ALL AJAX ENDPOINTS ARE PRESERVED
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['__save']) && $_POST['__save']==='1'){
  header('Content-Type:application/json; charset=UTF-8');
  $posted_title = trim($_POST['page_title'] ?? '');
  $posted_slug  = trim($_POST['page_name'] ?? '');
  $slug = ri_slug($posted_slug !== '' ? $posted_slug : ri_make_slug_from_title($posted_title));
  if ($slug === '') { echo json_encode(['ok'=>false,'error'=>'Missing slug']); exit; }
  $dir = $PAGES_DIR . '/' . $slug;
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { echo json_encode(['ok'=>false,'error'=>'Cannot create page folder']); exit; }
  $rc_enabled = isset($_POST['right_column_enabled']) ? true : false;
  $left_width = (int)($_POST['left_width'] ?? 65);
  // Per-item right-column injection override (inherits site default when 'inherit'/0).
  $inj_aff  = in_array($_POST['inject_affiliate'] ?? 'inherit', ['on','off'], true) ? $_POST['inject_affiliate'] : 'inherit';
  $inj_ucs  = in_array($_POST['inject_ucs'] ?? 'inherit', ['on','off'], true) ? $_POST['inject_ucs'] : 'inherit';
  $inj_cols = (int)($_POST['inject_columns'] ?? 0); if ($inj_cols < 0 || $inj_cols > 3) $inj_cols = 0;
  // ri_sanitize_content() is now defined at file scope (near top of this file)
  // so it survives multiple POST requests without a "Cannot redeclare" fatal.

  $leftContent = ri_sanitize_content($_POST['components']['main_content']['content'] ?? '');
  $rightContent = ri_sanitize_content($_POST['components']['right_column']['content'] ?? '');

  $payload = [
    'page_title' => $posted_title,
    // Page excerpt → meta description (page.php feeds this to <meta name="description"> + og:description).
    'meta_description' => trim((string)($_POST['page_excerpt'] ?? '')),
    'right_column_enabled' => $rc_enabled,
    'left_width' => $left_width,
    'right_width' => 100 - $left_width,
    'use_wrapper' => isset($_POST['use_wrapper']),
    'include_bg' => isset($_POST['include_bg']),
    'lum_text_off' => isset($_POST['lum_text_off']),   // base text styles OFF when set (default: on)
    'inject_affiliate' => $inj_aff,
    'inject_ucs' => $inj_ucs,
    'inject_columns' => $inj_cols,
    'components' => [
      'main_content' => ['content' => $leftContent],
      'right_column' => ['content' => $rightContent],
    ],
    'saved_at' => date('c'),
  ];
  $base_file = $dir . '/' . $slug . '.json';
  // og:image — explicit value wins; carry over from the existing page JSON
  // when the caller didn't send the field (older callers / partial saves),
  // so a save can never silently strip a configured share image.
  if (array_key_exists('og_image', $_POST)) {
    $payload['og_image'] = trim((string)$_POST['og_image']);
  } else {
    $prev = is_file($base_file) ? (json_decode((string)@file_get_contents($base_file), true) ?: []) : [];
    $payload['og_image'] = (string)($prev['og_image'] ?? '');
  }
  if ($payload['og_image'] === '') unset($payload['og_image']);
  // Per-page width / padding overrides (empty = inherit site default). Explicit value wins;
  // carry over from existing JSON on partial saves so a save can't silently strip them.
  $prevPW = is_file($base_file) ? (json_decode((string)@file_get_contents($base_file), true) ?: []) : [];
  if (array_key_exists('page_max_width', $_POST)) {
    $v = trim((string)$_POST['page_max_width']);
    if ($v === 'fluid' || $v === 'full') { $payload['page_max_width'] = 'fluid'; }
    elseif (is_numeric($v)) { $n = (int)$v; if ($n >= 320 && $n <= 4000) $payload['page_max_width'] = (string)$n; }
    // '' / invalid → omit (clears the override → inherit)
  } elseif (isset($prevPW['page_max_width'])) {
    $payload['page_max_width'] = $prevPW['page_max_width'];
  }
  if (array_key_exists('page_pad', $_POST)) {
    $v = trim((string)$_POST['page_pad']);
    if (is_numeric($v)) { $p = (int)$v; if ($p >= 0 && $p <= 200) $payload['page_pad'] = (string)$p; }
  } elseif (isset($prevPW['page_pad'])) {
    $payload['page_pad'] = $prevPW['page_pad'];
  }
  // Text Style reading surface — explicit value wins; carry over when not sent (partial saves).
  if (array_key_exists('text_style', $_POST)) {
    $rawTs = json_decode((string)$_POST['text_style'], true);
    $tsPreset = is_array($rawTs) ? (string)($rawTs['preset'] ?? '') : '';
    if (is_array($rawTs) && $tsPreset !== '' && $tsPreset !== 'none') {
      @include_once SITE_ROOT . '/includes/text_style.php';
      $payload['text_style'] = function_exists('text_style_resolve') ? text_style_resolve($rawTs) : $rawTs;
    } // preset '' or 'none' => leave unset (removes the surface)
  } else {
    $prevTs = is_file($base_file) ? (json_decode((string)@file_get_contents($base_file), true) ?: []) : [];
    if (!empty($prevTs['text_style'])) $payload['text_style'] = $prevTs['text_style'];
  }
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  // Classify this snapshot: first-ever save = ORIGIN (pinned); autosaves post autosave=1
  // (rolling buffer); everything else = a committed revision (retained). [[revision retention]]
  $existing_revs = ri_rev_files_sorted($dir);
  $is_autosave   = !empty($_POST['autosave']);
  $ts            = date('Ymd-His');
  if (empty($existing_revs)) {
    $rev_file = $dir . '/' . $slug . '.' . $ts . '.origin.rev.json';
  } else {
    $rev_file = $dir . '/' . $slug . '.' . $ts . ($is_autosave ? '.auto' : '') . '.rev.json';
  }
  if (@file_put_contents($base_file, $json) && @file_put_contents($rev_file, $json)) {
    ri_rev_prune($dir);
    // Per-page CSS: write or delete {slug}.css
    $pageCssContent = trim($_POST['page_css'] ?? '');
    $cssFile = $dir . '/' . $slug . '.css';
    if ($pageCssContent !== '') {
      @file_put_contents($cssFile, $pageCssContent);
    } elseif (is_file($cssFile)) {
      @unlink($cssFile);
    }
    // Per-page JS: write or delete {slug}.js
    $pageJsContent = trim($_POST['page_js'] ?? '');
    $jsFile = $dir . '/' . $slug . '.js';
    if ($pageJsContent !== '') {
      @file_put_contents($jsFile, $pageJsContent);
    } elseif (is_file($jsFile)) {
      @unlink($jsFile);
    }
    echo json_encode(['ok'=>true, 'slug'=>$slug]);
  } else {
    echo json_encode(['ok'=>false,'error'=>'Write failed']);
  }
  exit;
}
/* -------------------------------------------------------------------------
 *  Article endpoints — load/save an article via ArticleStore so Page Manager
 *  can serve as the unified editor for both pages and articles.
 * ------------------------------------------------------------------------- */
if (isset($_GET['__load_article'])) {
    header('Content-Type:application/json; charset=UTF-8');
    $slug = preg_replace('/[^a-z0-9\-_]/i', '', (string)($_GET['slug'] ?? ''));
    require_once SITE_ROOT . '/admin/modules/ArticlesManager/lib/ArticleStore.php';
    $store = new \ArticlesManager\ArticleStore(SITE_ROOT);
    $art   = $slug !== '' ? $store->get($slug) : null;
    if (!$art) { echo json_encode(['ok' => false, 'error' => 'not found']); exit; }
    // Normalize to a shape page-manager-edit.js understands (reuses left column
    // for body_html; article fields carried in _article).
    $payload = [
        'ok'                   => true,
        'page_type'            => 'article',
        'page_title'           => (string)($art['title'] ?? ''),
        'components'           => ['main_content' => ['content' => (string)($art['body_html'] ?? '')]],
        'right_column_enabled' => false,
        'use_wrapper'          => true,
        'include_bg'           => true,
        'left_width'           => 100,
        'right_width'          => 0,
        '_page_css'            => '',
        '_page_js'             => '',
        '_article'             => [
            'slug'        => (string)($art['slug'] ?? $slug),
            'eyebrow'     => (string)($art['eyebrow'] ?? ''),
            'excerpt'     => (string)($art['excerpt'] ?? ''),
            'hero_image'  => (string)($art['hero_image'] ?? ''),
            'tags'        => (array) ($art['tags'] ?? []),
            'category'    => (string)($art['category'] ?? ''),
            'author'      => (string)($art['author'] ?? ''),
            'author_slug' => (string)($art['author_slug'] ?? ''),
            'date'        => (string)($art['date'] ?? ''),
            'published'   => (bool)  ($art['published'] ?? false),
            'pinned'      => (bool)  ($art['pinned'] ?? false),
            'featured_home'=> (bool) ($art['featured_home'] ?? false),
            'source'      => (string)($art['source'] ?? 'manual'),
            'external_url'=> (string)($art['external_url'] ?? ''),
        ],
    ];
    // Per-item right-column injection override lives in the page JSON (page_renderer reads it there,
    // not the ArticleStore) — surface it so the editor's RIGHT COLUMN controls populate correctly.
    $pjFile = $PAGES_DIR . '/' . $slug . '/' . $slug . '.json';
    $pj = is_file($pjFile) ? (json_decode((string)@file_get_contents($pjFile), true) ?: []) : [];
    $payload['inject_affiliate'] = (string)($pj['inject_affiliate'] ?? 'inherit');
    $payload['inject_ucs']       = (string)($pj['inject_ucs'] ?? 'inherit');
    $payload['inject_columns']   = (int)($pj['inject_columns'] ?? 0);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// Direct, one-shot toggle for featured_home. Completely independent of the article
// save form — prevents stale/cached form state from affecting which article is featured.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__toggle_featured']) && $_POST['__toggle_featured'] === '1') {
    header('Content-Type:application/json; charset=UTF-8');
    require_once SITE_ROOT . '/admin/modules/ArticlesManager/lib/ArticleStore.php';
    $store = new \ArticlesManager\ArticleStore(SITE_ROOT);
    $slug  = preg_replace('/[^a-z0-9\-_]/i', '', (string)($_POST['slug'] ?? ''));
    $value = !empty($_POST['value']);
    $a     = $slug !== '' ? $store->get($slug) : null;
    if (!$a) { echo json_encode(['ok' => false, 'error' => 'article not found']); exit; }
    $a['featured_home'] = $value;
    try {
        $store->save($a);
        echo json_encode(['ok' => true, 'slug' => $slug, 'featured_home' => $value]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Manual page⇄article flip. Article-ness is PURELY membership in the ArticlesManager store —
// both a page and an article are the same page-dir, rendered via /?p=slug. So converting only
// toggles that membership; it never touches the page's content/components. The catalog badge +
// tab counts re-derive from the store on reload, so the card reclassifies immediately.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__toggle_article_type']) && $_POST['__toggle_article_type'] === '1') {
    header('Content-Type:application/json; charset=UTF-8');
    require_once SITE_ROOT . '/admin/modules/ArticlesManager/lib/ArticleStore.php';
    $store = new \ArticlesManager\ArticleStore(SITE_ROOT);
    $slug  = preg_replace('/[^a-z0-9\-_]/i', '', (string)($_POST['slug'] ?? ''));
    $to    = (string)($_POST['to'] ?? '');   // 'article' | 'page'
    if ($slug === '') { echo json_encode(['ok' => false, 'error' => 'missing slug']); exit; }
    $pjFile = $PAGES_DIR . '/' . $slug . '/' . $slug . '.json';
    try {
        if ($to === 'article') {
            if (!is_file($pjFile)) { echo json_encode(['ok' => false, 'error' => 'page folder not found']); exit; }
            $pj = json_decode((string)@file_get_contents($pjFile), true) ?: [];
            $existing = $store->get($slug) ?: [];
            // Populate body_html from the page's components so the article renders at /blog/{slug}
            // (article.php renders body_html; an empty body 404s). Mirrors how native articles store
            // their content. Concatenate each component's content/html block in document order.
            $body = '';
            if (empty($existing['body_html'])) {
                $parts = [];
                foreach (($pj['components'] ?? []) as $c) {
                    if (!is_array($c)) continue;
                    foreach (['content','html','body','text'] as $ck) {
                        if (isset($c[$ck]) && is_string($c[$ck]) && trim($c[$ck]) !== '') { $parts[] = $c[$ck]; break; }
                    }
                }
                $body = implode("\n", $parts);
            }
            $store->save(array_merge($existing, [
                'slug'      => $slug,
                'title'     => (string)($existing['title'] ?? $pj['title'] ?? $pj['page_title'] ?? $slug),
                'excerpt'   => (string)($existing['excerpt'] ?? $pj['meta_description'] ?? ''),
                'body_html' => (string)($existing['body_html'] ?? $body),
                'published' => true,
                'source'    => $existing['source'] ?? 'converted-from-page',
            ]));
            echo json_encode(['ok' => true, 'slug' => $slug, 'type' => 'article']);
        } elseif ($to === 'page') {
            $store->delete($slug);   // page-dir stays; only the article registration is dropped
            echo json_encode(['ok' => true, 'slug' => $slug, 'type' => 'page']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'bad target type']);
        }
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['__save_article']) && $_POST['__save_article'] === '1') {
    header('Content-Type:application/json; charset=UTF-8');
    require_once SITE_ROOT . '/admin/modules/ArticlesManager/lib/ArticleStore.php';
    $store = new \ArticlesManager\ArticleStore(SITE_ROOT);

    $origSlug = preg_replace('/[^a-z0-9\-_]/i', '', (string)($_POST['original_slug'] ?? ''));
    $slug     = preg_replace('/[^a-z0-9\-_]/i', '', (string)($_POST['slug'] ?? $origSlug));
    $existing = $origSlug !== '' ? ($store->get($origSlug) ?: []) : [];

    $tags = (string)($_POST['tags'] ?? '');
    $tagsArr = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $tags) ?: [])));

    $article = array_merge($existing, [
        'slug'         => $slug !== '' ? $slug : ($existing['slug'] ?? ''),
        'title'        => (string)($_POST['page_title'] ?? $existing['title'] ?? ''),
        'eyebrow'      => (string)($_POST['eyebrow'] ?? ''),
        'excerpt'      => (string)($_POST['excerpt'] ?? ''),
        'body_html'    => (string)($_POST['body_html'] ?? ''),
        'hero_image'   => (string)($_POST['hero_image'] ?? ''),
        'tags'         => $tagsArr,
        'category'     => (string)($_POST['category'] ?? ''),
        'author'       => (string)($_POST['author'] ?? ''),
        // Published flag retired from UI — every saved article is published by default.
        'published'    => true,
        'pinned'       => !empty($_POST['pinned']),
        // featured_home is NOT handled here — it has its own dedicated __toggle_featured
        // endpoint. Always preserve existing value. The regular save path (autosave or
        // manual Save button) can never clobber the featured flag.
        'featured_home'=> (bool)($existing['featured_home'] ?? false),
        'date'         => (string)($_POST['date'] ?? ($existing['date'] ?? date('c'))),
        'source'       => (string)($existing['source'] ?? 'manual'),
        'external_url' => (string)($existing['external_url'] ?? ''),
    ]);

    try {
        $saved = $store->save($article);
        // If slug changed, delete the old record.
        if ($origSlug !== '' && $origSlug !== $saved['slug']) {
            $store->delete($origSlug);
        }
        // Persist the per-item right-column injection override into the page JSON, which is
        // where page_renderer (the /?p= route) reads inject_* from — the ArticleStore is a
        // separate copy. Keeps the AM-side edit path consistent with the page-editor path.
        $pjFile = $PAGES_DIR . '/' . $saved['slug'] . '/' . $saved['slug'] . '.json';
        if (is_file($pjFile)) {
            $pj = json_decode((string)@file_get_contents($pjFile), true) ?: [];
            $pj['inject_affiliate'] = in_array($_POST['inject_affiliate'] ?? 'inherit', ['on','off'], true) ? $_POST['inject_affiliate'] : 'inherit';
            $pj['inject_ucs']       = in_array($_POST['inject_ucs'] ?? 'inherit', ['on','off'], true) ? $_POST['inject_ucs'] : 'inherit';
            $ic = (int)($_POST['inject_columns'] ?? 0); if ($ic < 0 || $ic > 3) $ic = 0;
            $pj['inject_columns']   = $ic;
            $tmp = $pjFile . '.tmp';
            file_put_contents($tmp, json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            @rename($tmp, $pjFile);
        }
        echo json_encode(['ok' => true, 'slug' => $saved['slug']]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if(isset($_GET['__load'])){
    header('Content-Type:application/json; charset=UTF-8');
    $slug=ri_slug($_GET['slug']??''); $dir=$PAGES_DIR.'/'.$slug; $file=null;
    if($slug && is_dir($dir)){
      $base=$dir.'/'.$slug.'.json';
      $cand=ri_rev_files_sorted($dir);
      if($cand){ $file=$cand[0]; }
      // Keep the revision chain in sync with the canonical page file: if {slug}.json was
      // written AFTER the newest revision (e.g. a direct/out-of-band restore), prefer it so
      // the editor never loads a stale/diverged revision (the "cloaked page" bug).
      if(is_file($base) && (!$file || @filemtime($base) > @filemtime($file))){ $file=$base; }
    }
    $raw = $file ? (@file_get_contents($file) ?: '{}') : '{}';
    $data = json_decode($raw, true) ?: [];
    // Attach per-page CSS if file exists
    $cssFile = $dir . '/' . $slug . '.css';
    $data['_page_css'] = is_file($cssFile) ? (@file_get_contents($cssFile) ?: '') : '';
    // Attach per-page JS if file exists
    $jsFile = $dir . '/' . $slug . '.js';
    $data['_page_js'] = is_file($jsFile) ? (@file_get_contents($jsFile) ?: '') : '';
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
if(isset($_GET['__revs'])){
    header('Content-Type:application/json; charset=UTF-8');
    $slug=ri_slug($_GET['slug']??''); $dir=$PAGES_DIR.'/'.$slug;
    if($slug && is_dir($dir)) { ri_rev_prune_to_5($dir); }
    $cand=ri_rev_files_sorted($dir); echo json_encode(array_map('basename',$cand));
    exit;
}
if(isset($_GET['__rev_get'])){
    header('Content-Type:application/json; charset=UTF-8');
    $slug=ri_slug($_GET['slug']??''); $rev=basename($_GET['rev']??'');
    $file=$PAGES_DIR.'/'.$slug.'/'.$rev; echo ($slug&&$rev&&is_file($file))?(@file_get_contents($file)?:'{}'):'{}';
    exit;
}
if(isset($_POST['__rev_delete'])){
    header('Content-Type:application/json; charset=UTF-8');
    $slug=ri_slug($_POST['slug']??''); $rev=basename($_POST['rev']??'');
    $file=$PAGES_DIR.'/'.$slug.'/'.$rev;
    if(!$slug||!$rev||!is_file($file)){ echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
    if(@unlink($file)){ ri_rev_prune_to_5($PAGES_DIR.'/'.$slug); echo json_encode(['ok'=>true]); }
    else { echo json_encode(['ok'=>false,'error'=>'unlink failed']); }
    exit;
}
if(isset($_POST['__pm_action'])&&$_POST['__pm_action']==='delete_page'){
    header('Content-Type:application/json; charset=UTF-8');
    $slug = ri_slug($_POST['slug'] ?? '');
    if ($slug===''){ echo json_encode(['ok'=>false,'error'=>'Missing slug']); exit; }
    $src = $PAGES_DIR . '/' . $slug;
    if (!is_dir($src)){ echo json_encode(['ok'=>false,'error'=>'Page folder not found']); exit; }
    if (!is_dir($TRASH_DIR) && !@mkdir($TRASH_DIR, 0775, true)){ echo json_encode(['ok'=>false,'error'=>'Could not create trash dir']); exit; }
    $dst = $TRASH_DIR . '/' . $slug . '--' . date('Ymd-His');
    if (rename($src, $dst)) {
        // delete-unlink: drop any menu items pointing at the now-gone page so the
        // nav never renders a dead link. Canonical menu file only.
        $unlinked = 0;
        $menuFile = SITE_ROOT . '/admin/data/menu/menu_items.json';
        if (is_file($menuFile)) {
            $rawMenu = json_decode((string)file_get_contents($menuFile), true);
            if (is_array($rawMenu)) {
                $wrapped = isset($rawMenu['items']) && is_array($rawMenu['items']);
                $list = $wrapped ? $rawMenu['items'] : $rawMenu;
                $kept = array_values(array_filter($list, function ($it) use ($slug) {
                    return ($it['slug'] ?? '') !== $slug;
                }));
                $unlinked = count($list) - count($kept);
                if ($unlinked > 0) {
                    $save = $wrapped ? array_merge($rawMenu, ['items'=>$kept]) : $kept;
                    $tmp = $menuFile . '.tmp';
                    if (@file_put_contents($tmp, json_encode($save, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
                        @chmod($tmp, 0664); @rename($tmp, $menuFile);
                    }
                }
            }
        }
        echo json_encode(['ok'=>true,'moved_to'=>basename($dst),'unlinked'=>$unlinked]);
    } else {
        $err = error_get_last();
        echo json_encode(['ok'=>false,'error'=>'Move to trash failed: ' . ($err['message'] ?? 'unknown')]);
    }
    exit;
}
if(isset($_POST['__pm_action'])&&$_POST['__pm_action']==='purge_trash'){
    header('Content-Type:application/json; charset=UTF-8');
    if(!is_dir($TRASH_DIR)){ echo json_encode(['ok'=>true,'purged'=>0]); exit; }
    $count=0;
    foreach(scandir($TRASH_DIR) as $e){
        if($e[0]==='.') continue;
        $path=$TRASH_DIR.'/'.$e;
        if(is_dir($path)){
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $f){ $f->isDir()?@rmdir($f->getRealPath()):@unlink($f->getRealPath()); }
            if(@rmdir($path)) $count++;
        } else { if(@unlink($path)) $count++; }
    }
    echo json_encode(['ok'=>true,'purged'=>$count]); exit;
}

// ── Set Home Page API ──
if (isset($_POST['__pm_action']) && $_POST['__pm_action'] === 'set_home_page') {
    header('Content-Type: application/json');
    $slug = trim($_POST['slug'] ?? '');
    if ($slug === '') { echo json_encode(['ok'=>false,'error'=>'Missing slug']); exit; }
    $settingsFile = SITE_ROOT . '/admin/data/site-settings.json';
    $settings = is_file($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    if (!is_array($settings)) $settings = [];
    $settings['home_page_slug'] = $slug;
    $tmp = $settingsFile . '.tmp';
    file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0664);
    rename($tmp, $settingsFile);
    echo json_encode(['ok'=>true,'home_page_slug'=>$slug]); exit;
}

// ── Content-injection defaults API (per content-type: affiliate + UCS right-column) ──
if (isset($_POST['__pm_action']) && $_POST['__pm_action'] === 'set_injection_defaults') {
    header('Content-Type: application/json');
    $ctype = ($_POST['ctype'] ?? '') === 'page' ? 'page' : (($_POST['ctype'] ?? '') === 'article' ? 'article' : '');
    if ($ctype === '') { echo json_encode(['ok'=>false,'error'=>'bad ctype']); exit; }
    $affiliate = filter_var($_POST['affiliate'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $ucs       = filter_var($_POST['ucs'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $columns   = (int)($_POST['columns'] ?? 1); if ($columns < 1 || $columns > 3) $columns = 1;
    $settingsFile = SITE_ROOT . '/admin/data/site-settings.json';
    $settings = is_file($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    if (!is_array($settings)) $settings = [];
    if (!isset($settings['content_injection']) || !is_array($settings['content_injection'])) $settings['content_injection'] = [];
    $settings['content_injection'][$ctype] = ['affiliate'=>$affiliate, 'ucs'=>$ucs, 'columns'=>$columns];
    $tmp = $settingsFile . '.tmp';
    file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0664);
    rename($tmp, $settingsFile);
    echo json_encode(['ok'=>true,'ctype'=>$ctype,'affiliate'=>$affiliate,'ucs'=>$ucs]); exit;
}

// ── Per-item injection toggle (card quick-toggle: set inject_affiliate|inject_ucs on a page JSON) ──
if (isset($_POST['__pm_action']) && $_POST['__pm_action'] === 'set_item_injection') {
    header('Content-Type: application/json');
    @include_once SITE_ROOT . '/includes/content_injection.inc.php';
    $ok = function_exists('ci_set_item_override')
        && ci_set_item_override(SITE_ROOT, (string)($_POST['slug'] ?? ''), (string)($_POST['field'] ?? ''), (string)($_POST['value'] ?? ''));
    echo json_encode(['ok'=>$ok, 'slug'=>$_POST['slug'] ?? '', 'field'=>$_POST['field'] ?? '', 'value'=>$_POST['value'] ?? '']); exit;
}

// ── UCS stack selector API (which content stack the UCS provider injects) ──
if (isset($_POST['__pm_action']) && $_POST['__pm_action'] === 'set_ucs_slug') {
    header('Content-Type: application/json');
    $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['slug'] ?? ''));
    $settingsFile = SITE_ROOT . '/admin/data/site-settings.json';
    $settings = is_file($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    if (!is_array($settings)) $settings = [];
    if (!isset($settings['content_injection']) || !is_array($settings['content_injection'])) $settings['content_injection'] = [];
    $settings['content_injection']['ucs_slug'] = $slug;
    $tmp = $settingsFile . '.tmp';
    file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0664);
    rename($tmp, $settingsFile);
    echo json_encode(['ok'=>true,'slug'=>$slug]); exit;
}

// ── Global page-width API (site-wide max content width for every page + article) ──
if (isset($_POST['__pm_action']) && $_POST['__pm_action'] === 'set_page_width') {
    header('Content-Type: application/json');
    $val = trim((string)($_POST['value'] ?? ''));
    $store = null; // null = valid + clear
    if (in_array($val, ['', 'standard', 'wide', 'full'], true)) {
        $store = $val;
    } elseif (is_numeric($val)) {
        $n = (int)$val;
        if ($n < 480 || $n > 4000) { echo json_encode(['ok'=>false,'error'=>'px out of range (480–4000)']); exit; }
        $store = (string)$n;
    } else {
        echo json_encode(['ok'=>false,'error'=>'invalid value']); exit;
    }
    $settingsFile = SITE_ROOT . '/admin/data/site-settings.json';
    $settings = is_file($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
    if (!is_array($settings)) $settings = [];
    if ($store === '') unset($settings['page_max_width']);
    else $settings['page_max_width'] = $store;
    $tmp = $settingsFile . '.tmp';
    file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0664);
    rename($tmp, $settingsFile);
    echo json_encode(['ok'=>true,'value'=>$store]); exit;
}

// ── Add to Menu API ──
if (isset($_POST['__pm_action']) && $_POST['__pm_action'] === 'add_to_menu') {
    header('Content-Type: application/json');
    $slug  = ri_slug($_POST['slug'] ?? '');
    $title = trim(strip_tags($_POST['title'] ?? ''));
    if ($slug === '') { echo json_encode(['ok'=>false,'error'=>'Missing slug']); exit; }
    if ($title === '') $title = $slug;
    // Canonical menu SoT — the same file MenuManager + the live nav read
    // (header/document_header_body.php). Writing anywhere else = a silent no-op.
    $menuFile = SITE_ROOT . '/admin/data/menu/menu_items.json';
    @mkdir(dirname($menuFile), 0775, true);
    $rawMenu = is_file($menuFile) ? json_decode(file_get_contents($menuFile), true) : [];
    if (!is_array($rawMenu)) $rawMenu = [];
    $isWrapped = isset($rawMenu['items']) && is_array($rawMenu['items']);
    $items = $isWrapped ? $rawMenu['items'] : $rawMenu;
    foreach ($items as $item) {
        if (($item['slug'] ?? '') === $slug) {
            echo json_encode(['ok'=>true,'already'=>true,'msg'=>htmlspecialchars($title) . ' is already in the menu']); exit;
        }
    }
    // Match the nav's item shape {title,url,slug,type}.
    $items[] = ['title'=>$title,'url'=>'/page.php?p='.$slug,'slug'=>$slug,'type'=>'page'];
    $toSave  = $isWrapped ? array_merge($rawMenu, ['items'=>$items]) : $items;
    $tmp = $menuFile . '.tmp';
    file_put_contents($tmp, json_encode($toSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0664);
    rename($tmp, $menuFile);
    echo json_encode(['ok'=>true,'added'=>true,'msg'=>htmlspecialchars($title) . ' added to site menu']); exit;
}

// Load current home page slug
$homePageSlug = '';
$settingsFile = SITE_ROOT . '/admin/data/site-settings.json';
if (is_file($settingsFile)) {
    $ss = json_decode(file_get_contents($settingsFile), true);
    $homePageSlug = $ss['home_page_slug'] ?? '';
}

// DATA FOR PAGE RENDER
// (Picker data prep removed 2026-06-06 — the Insert Explorer sidebar fetches
//  its shortcode catalog from /admin/shared/explorer/insertables.php.)
$regularPages = ri_list_pages($PAGES_DIR);
$aipPages = ri_list_aip_pages(SITE_ROOT . '/admin/data/AIP_Pages');
$pages = array_merge($regularPages, $aipPages);

// Classify regular pages as page vs article (article = folder is in the AM store).
$articleSlugSet = ri_article_slugs(SITE_ROOT);
@include_once SITE_ROOT . '/includes/content_injection.inc.php';   // per-card injection chips
// Current global page-width setting (drives the editor control's initial state).
$__pmSs = is_file(SITE_ROOT.'/admin/data/site-settings.json') ? (json_decode(@file_get_contents(SITE_ROOT.'/admin/data/site-settings.json'), true) ?: []) : [];
$curPageWidth = (string)($__pmSs['page_max_width'] ?? '');
$curPageWidthIsCustom = ($curPageWidth !== '' && is_numeric($curPageWidth));
// Content-injection defaults (right-column providers). Built-ins: articles both on, pages both off.
$__ci = is_array($__pmSs['content_injection'] ?? null) ? $__pmSs['content_injection'] : [];
$ciDef = function($type, $prov) use ($__ci) {
    $d = is_array($__ci[$type] ?? null) ? $__ci[$type] : [];
    // Built-in default OFF (opt-in per site) — matches ci_defaults() in content_injection.inc.php.
    return array_key_exists($prov, $d) ? (bool)$d[$prov] : false;
};
$ciCols = function($type) use ($__ci) {
    $d = is_array($__ci[$type] ?? null) ? $__ci[$type] : [];
    $c = (int)($d['columns'] ?? 1);
    return ($c >= 1 && $c <= 3) ? $c : 1;
};
// UCS stack selector — which content stack the UCS provider injects (canonical home,
// replaces the retired ArticlesManager "Universal Content" modal).
$ciUcsSlug = (string)($__ci['ucs_slug'] ?? ($__pmSs['blog']['global_stack']['slug'] ?? 'ucs'));
$__csRows  = is_file(SITE_ROOT.'/admin/data/content-stacks-registry.json')
    ? (json_decode((string)@file_get_contents(SITE_ROOT.'/admin/data/content-stacks-registry.json'), true) ?: []) : [];
$__csList  = $__csRows['stacks'] ?? $__csRows;
$ciStacks  = [];
foreach ((array)$__csList as $s) {
    if (!is_array($s)) continue;
    $sl = $s['slug'] ?? preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($s['label'] ?? '')));
    if ($sl !== '') $ciStacks[$sl] = (string)($s['label'] ?? $sl);
}
if ($ciUcsSlug !== '' && !isset($ciStacks[$ciUcsSlug])) $ciStacks[$ciUcsSlug] = $ciUcsSlug; // keep current selectable
$articleCount = 0; $realPageCount = 0;
foreach($regularPages as $rp){
  if(isset($articleSlugSet[strtolower($rp['folder'])])) $articleCount++;
  else $realPageCount++;
}

// Trash items
$trashItems = [];
if(is_dir($TRASH_DIR)){
  foreach(scandir($TRASH_DIR) as $t){
    if($t[0]==='.'||!is_dir($TRASH_DIR.'/'.$t)) continue;
    $trashItems[] = $t;
  }
  rsort($trashItems);
}

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<!-- No panel_header_h1 here: PageManager uses its own .pm-title "PAGE MANAGER"
     street sign below (avoids a duplicate now that panel_header_h1 is visible). -->

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/PageManager/css/page-manager-inline.css') ?>">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/PageManager/css/page-manager-external.css') ?>">
<link rel="stylesheet" href="<?= sc_asset('/admin/shared/markdown-editor.css') ?>">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/PageManager/css/page-manager-edit.css') ?>">

        <h1 class="pm-title">P A G E &nbsp; M A N A G E R</h1>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button type="button" id="btn-new-page" class="pm-btn pm-btn-new"><i class="fa-solid fa-plus"></i> New Page</button>
            <div class="pm-home-selector">
              <label for="pm-home-select"><i class="fa-solid fa-house"></i> Home Page:</label>
              <select id="pm-home-select">
                <?php foreach($pages as $pg): if(!empty($pg['is_aip'])) continue; ?>
                <option value="<?php echo ri_safe($pg['folder']); ?>"<?php if($pg['folder'] === $homePageSlug) echo ' selected'; ?>><?php echo ri_safe($pg['title']); ?> (/<?php echo ri_safe($pg['folder']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="pm-debug-toggle">
                <input type="checkbox" id="pm-debug-toggle">
                <div>
                    <label for="pm-debug-toggle">Enable Front Page Debug</label>
                    <small>Toggles telemetry bar on public site.</small>
                </div>
            </div>
            <button type="button" id="pm-minimize-toggle" class="pm-btn pm-btn-minimize" title="Toggle compact view"><i class="fa-solid fa-minimize"></i> Minimize</button>
            <select id="pm-sort-select" class="pm-sort-select" title="Sort pages">
                <option value="recent">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="az">Title A–Z</option>
                <option value="za">Title Z–A</option>
                <option value="slug">Slug A–Z</option>
            </select>
        </div>

        <div id="toast-notification"></div>

        <!-- Global settings — site-level knobs that apply to EVERY page + article. -->
        <details class="pm-global-settings" id="pm-global-settings">
            <summary><i class="fa-solid fa-sliders"></i> Global Settings <span class="pm-gs-sub">— apply to all pages &amp; articles</span></summary>
            <div class="pm-global-settings-body">
                <div class="pm-gs-field">
                    <label class="pm-gs-label" for="edit-global-page-width"><i class="fa-solid fa-arrows-left-right-to-line"></i> Page Width</label>
                    <select id="edit-global-page-width">
                        <option value=""<?php if($curPageWidth==='') echo ' selected'; ?>>Default (1760)</option>
                        <option value="standard"<?php if($curPageWidth==='standard') echo ' selected'; ?>>Standard (1200)</option>
                        <option value="wide"<?php if($curPageWidth==='wide') echo ' selected'; ?>>Wide (1480)</option>
                        <option value="full"<?php if($curPageWidth==='full') echo ' selected'; ?>>Full / Fluid</option>
                        <option value="custom"<?php if($curPageWidthIsCustom) echo ' selected'; ?>>Custom…</option>
                    </select>
                    <input type="number" id="edit-global-page-width-px" min="480" max="4000" step="10" placeholder="px" value="<?php echo $curPageWidthIsCustom ? (int)$curPageWidth : ''; ?>"<?php if(!$curPageWidthIsCustom) echo ' hidden'; ?>>
                    <span class="pm-global-width-status" id="edit-global-page-width-status"></span>
                    <p class="pm-gs-hint">Maximum content width for every page and article. “Full / Fluid” lets content fill the column and scale.</p>
                </div>

                <div class="pm-gs-field pm-gs-injection">
                    <label class="pm-gs-label"><i class="fa-solid fa-layer-group"></i> Right-Column Defaults</label>
                    <table class="pm-ci-table" id="pm-ci-defaults">
                        <thead><tr><th></th><th>🛒 Affiliate</th><th>🧩 UCS</th><th>Columns</th></tr></thead>
                        <tbody>
                            <?php foreach(['article'=>'Articles','page'=>'Pages'] as $ct=>$ctLabel): ?>
                            <tr data-ctype="<?php echo $ct; ?>">
                                <th><?php echo $ctLabel; ?></th>
                                <td><input type="checkbox" class="pm-ci-cb" data-prov="affiliate"<?php if($ciDef($ct,'affiliate')) echo ' checked'; ?>></td>
                                <td><input type="checkbox" class="pm-ci-cb" data-prov="ucs"<?php if($ciDef($ct,'ucs')) echo ' checked'; ?>></td>
                                <td>
                                    <select class="pm-ci-cols">
                                        <?php for($n=1;$n<=3;$n++): ?>
                                        <option value="<?php echo $n; ?>"<?php if($ciCols($ct)===$n) echo ' selected'; ?>><?php echo $n; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <span class="pm-global-width-status" id="pm-ci-status"></span>
                    <p class="pm-gs-hint">Auto-inject the Affiliate placement and/or Universal Content Stack into the right column, by content type. Injected content sits at the top; a page’s own right-column content stays below. Each page/article can override this in its editor.</p>
                </div>

                <div class="pm-gs-field">
                    <label class="pm-gs-label" for="pm-ucs-slug"><i class="fa-solid fa-cube"></i> UCS stack</label>
                    <select id="pm-ucs-slug">
                        <?php if (empty($ciStacks)): ?><option value="">— no content stacks yet —</option><?php endif; ?>
                        <?php foreach($ciStacks as $sl=>$lbl): ?>
                        <option value="<?php echo htmlspecialchars($sl, ENT_QUOTES); ?>"<?php if($ciUcsSlug===$sl) echo ' selected'; ?>><?php echo htmlspecialchars($lbl, ENT_QUOTES); ?> (<?php echo htmlspecialchars($sl, ENT_QUOTES); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <span class="pm-global-width-status" id="pm-ucs-slug-status"></span>
                    <p class="pm-gs-hint">Which content stack the “UCS” provider injects. Edit its contents + its own column layout in Content Stacks.</p>
                </div>
            </div>
        </details>

        <div class="pm-tabs" id="pm-tabs">
            <button type="button" class="pm-tab-btn active" data-tab="pages" onclick="pmTabSwitch('pages')">Pages (<span id="pm-count-pages"><?php echo (int)$realPageCount; ?></span>)</button>
            <button type="button" class="pm-tab-btn" data-tab="aip" onclick="pmTabSwitch('aip')">AI Pages (<?php echo count($aipPages); ?>)</button>
            <button type="button" class="pm-tab-btn" data-tab="all" onclick="pmTabSwitch('all')">All (<?php echo count($pages); ?>)</button>
            <label class="pm-show-articles" title="Articles are stored as pages but managed in Articles Manager. Off by default to keep this list clean.">
                <input type="checkbox" id="pm-show-articles"> Display Articles (<span id="pm-count-articles"><?php echo (int)$articleCount; ?></span>)
            </label>
        </div>

    <div class="pm-preview-cards pm-articles-hidden" id="pm-cards-grid" style="position:relative">
        <?php if(empty($pages)): ?>
            <p style="color:#555;font-size:.85rem;grid-column:1/-1;">No pages yet. Click "New Page" to create one.</p>
        <?php else: foreach($pages as $pg):
            $pgFolder  = ri_safe($pg['folder']);
            $pgTitle   = ri_safe($pg['title']);
            $pgSnippet = ri_safe($pg['snippet'] ?? '');
            $pgDate    = $pg['mtime'] ? date('M j, Y', $pg['mtime']) : '';
        ?>
        <?php $isAip = !empty($pg['is_aip']);
              $isArticle = !$isAip && isset($articleSlugSet[strtolower($pg['folder'])]);
              $cardType = $isAip ? 'aip' : ($isArticle ? 'article' : 'page'); ?>
        <div class="pm-preview-card<?php if($isAip) echo ' is-aip'; ?><?php if($isArticle) echo ' is-article'; ?><?php if($pgFolder === $homePageSlug) echo ' is-home'; ?>"
             data-page="<?php echo $pgFolder; ?>"
             data-type="<?php echo $cardType; ?>"
             data-title="<?php echo $pgTitle; ?>"
             data-slug="<?php echo $pgFolder; ?>"
             data-mtime="<?php echo (int)($pg['mtime'] ?? 0); ?>">
            <?php if(!$isAip): ?><span class="pm-type-badge pm-type-<?php echo $isArticle ? 'article' : 'page'; ?>"><?php echo $isArticle ? 'ARTICLE' : 'PAGE'; ?></span><?php
              $ciEff = function_exists('ci_resolve_slug') ? ci_resolve_slug(SITE_ROOT, $pg['folder'], $isArticle) : ['affiliate'=>false,'ucs'=>false];
            ?><span class="pm-inject-chips">
                <button type="button" class="pm-inject-chip js-inject-toggle<?php echo !empty($ciEff['affiliate'])?' is-on':''; ?>" data-slug="<?php echo $pgFolder; ?>" data-field="affiliate" title="Affiliate right column — click to toggle for this item">🛒</button>
                <button type="button" class="pm-inject-chip js-inject-toggle<?php echo !empty($ciEff['ucs'])?' is-on':''; ?>" data-slug="<?php echo $pgFolder; ?>" data-field="ucs" title="Universal Content Stack right column — click to toggle for this item">🧩</button>
              </span><?php endif; ?>
            <div class="pm-preview-card-title"><?php if($pgFolder === $homePageSlug): ?><span class="pm-home-badge"><i class="fa-solid fa-house"></i></span> <?php endif; ?><?php echo $pgTitle; ?><?php if($isAip): ?><span class="pm-aip-shortcode" onclick="event.stopPropagation();navigator.clipboard.writeText('[[aip:<?php echo $pgFolder; ?>]]');this.textContent='Copied!';setTimeout(()=>this.textContent='[[aip:<?php echo $pgFolder; ?>]]',1200)" title="Click to copy shortcode">[[aip:<?php echo $pgFolder; ?>]]</span><?php endif; ?></div>
            <div class="pm-preview-card-slug">/<?php echo $pgFolder; ?><?php if($isAip): ?><span class="pm-aip-source"><?php echo ri_safe($pg['snippet'] ?? ''); ?></span><?php endif; ?></div>
            <?php if(!$isAip && $pgSnippet): ?>
            <div class="pm-preview-card-snippet"><?php echo $pgSnippet; ?></div>
            <?php endif; ?>
            <div class="pm-preview-card-actions">
                <?php if($isAip): ?>
                <button type="button" class="pm-preview-card-btn preview-btn js-card-preview" data-page="<?php echo $pgFolder; ?>" data-title="<?php echo $pgTitle; ?>">Preview</button>
                <button type="button" class="pm-preview-card-btn delete-btn js-aip-delete" data-slug="<?php echo $pgFolder; ?>" data-title="<?php echo $pgTitle; ?>"><i class="fa-solid fa-trash"></i></button>
                <?php else: ?>
                <button type="button" class="pm-preview-card-btn js-card-edit" data-page="<?php echo $pgFolder; ?>">Edit</button>
                <button type="button" class="pm-preview-card-btn html-btn js-card-html-edit" data-page="<?php echo $pgFolder; ?>"><i class="fa-solid fa-code"></i> HTML</button>
                <button type="button" class="pm-preview-card-btn preview-btn js-card-preview" data-page="<?php echo $pgFolder; ?>" data-title="<?php echo $pgTitle; ?>">Preview</button>
                <button type="button" class="pm-preview-card-btn icon-btn js-card-set-home" data-page="<?php echo $pgFolder; ?>" title="Set as Home Page"><i class="fa-solid fa-house"></i></button>
                <button type="button" class="pm-preview-card-btn icon-btn js-card-add-menu" data-page="<?php echo $pgFolder; ?>" data-title="<?php echo $pgTitle; ?>" title="Add to Site Menu"><i class="fa-solid fa-bars"></i></button>
                <button type="button" class="pm-preview-card-btn icon-btn js-card-convert-type" data-slug="<?php echo $pgFolder; ?>" data-current="<?php echo $isArticle ? 'article' : 'page'; ?>" data-title="<?php echo $pgTitle; ?>" title="<?php echo $isArticle ? 'Convert to Page — removes it from Articles Manager (page content untouched)' : 'Convert to Article — manage it in Articles Manager (page content untouched)'; ?>"><i class="fa-solid <?php echo $isArticle ? 'fa-file-lines' : 'fa-newspaper'; ?>"></i></button>
                <button type="button" class="pm-preview-card-btn delete-btn js-card-delete" data-page="<?php echo $pgFolder; ?>" data-title="<?php echo $pgTitle; ?>" title="Delete page"><i class="fa-solid fa-trash"></i></button>
                <?php endif; ?>
            </div>
            <?php if($pgDate): ?>
            <div class="pm-preview-card-date">Published <?php echo $pgDate; ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <details style="margin-top:16px;">
      <summary style="cursor:pointer;color:#888;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;padding:6px 0;">
        <i class="fa-solid fa-trash-can"></i> Trash (<?php echo count($trashItems); ?>)
      </summary>
      <div style="background:#0a0a0a;border:1px solid #222;border-radius:6px;padding:10px;margin-top:6px;">
        <?php if(empty($trashItems)): ?>
          <p style="color:#555;font-size:.8rem;margin:0;">Trash is empty.</p>
        <?php else: ?>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
            <?php foreach($trashItems as $ti): ?>
              <span style="font-size:.75rem;color:#ff6b6b;background:#1a0a0a;border:1px solid #330000;border-radius:4px;padding:3px 8px;"><?php echo ri_safe($ti); ?></span>
            <?php endforeach; ?>
          </div>
          <button type="button" id="btn-purge-trash" style="padding:6px 14px;font-size:.75rem;background:#3b0b0b;color:#fff;border:1px solid #7a2b2b;border-radius:5px;cursor:pointer;text-transform:uppercase;">
            <i class="fa-solid fa-fire"></i> Purge All Trash
          </button>
        <?php endif; ?>
      </div>
    </details>

</div>

<div class="pm-edit-overlay" id="pm-edit-overlay" data-edit-type="page">
  <div class="pm-edit-modal">
    <!-- Mode banner — full-width row on the OUTER frame, above the tools. Reads
         PAGE / ARTICLE / NEW ARTICLE (set by the open handlers). High-contrast. -->
    <div class="pm-edit-mode-bar" id="edit-mode-label">PAGE EDITOR</div>
    <!-- ① Title strip — title + most-used layout controls inline, lifecycle actions upper-right -->
    <div class="pm-edit-modal-header">
      <input type="text" id="edit-page-title" placeholder="Page Title">
      <!-- Edit-type discriminator — 'page' or 'article' (set by openEditArticleModal) -->
      <input type="hidden" id="edit-page-type" value="page">
      <div class="pm-th-layout" id="edit-th-layout">
        <span class="pm-th-lbl">COLUMN WIDTHS</span>
        <span class="slider-lbl" id="edit-left-lbl">65%</span>
        <input type="range" id="edit-left-pct" min="25" max="75" value="65">
        <span class="slider-lbl" id="edit-right-lbl">35%</span>
        <label class="pm-th-enable"><input type="checkbox" id="edit-right-enabled" checked> ENABLE RIGHT COLUMN</label>
      </div>
      <div class="pm-edit-modal-header-btns">
        <button type="button" class="pm-btn pm-btn-save" id="edit-btn-save">Save</button>
        <button type="button" class="pm-btn pm-btn-save-exit" id="edit-btn-save-exit" style="display:none" title="Save this block and collapse straight back to the shortcode on the page"><i class="fa-solid fa-check-double"></i> Save &amp; Exit</button>
        <a class="pm-btn pm-btn-view" id="edit-btn-view" target="_blank" href="#">View</a>
        <button type="button" class="pm-btn pm-btn-delete-modal" id="edit-btn-delete"><i class="fa-solid fa-trash"></i> Delete</button>
        <button type="button" class="pm-edit-close" id="edit-btn-close">&times;</button>
      </div>
    </div>

    <!-- Hidden compat inputs — kept so save/load JS keeps working (OG image is auto now; no UI field). -->
    <input type="hidden" id="edit-page-og" value="">

    <!-- ② Trigger bar — slim strip: glassy drawer triggers + action buttons. Settings live in drawers below. -->
    <div class="pm-drawer-bar">
      <div class="pm-action-btns">
        <button type="button" class="pm-toolbar-btn" id="edit-btn-left"><i class="fa-solid fa-pen-to-square"></i> Edit Left Col</button>
        <button type="button" class="pm-toolbar-btn" id="edit-btn-right"><i class="fa-solid fa-pen-to-square"></i> Edit Right Col</button>
        <!-- Right Column settings drawer trigger — placed next to Edit Right Col (was in the left group; counterintuitive there) -->
        <button type="button" class="pm-drawer-trig" data-drawer="rightcol"><i class="fa-solid fa-layer-group"></i> Right Column</button>
        <button type="button" class="pm-toolbar-btn swap-btn" id="edit-btn-swap"><i class="fa-solid fa-right-left"></i> Swap</button>
        <!-- Edit CSS & JS — moved to the right of Swap (Tom's call) -->
        <button type="button" class="pm-toolbar-btn css-btn" id="edit-btn-css"><i class="fa-solid fa-palette"></i> Edit CSS &amp; JS</button>
        <!-- Page Styles + Article Meta drawer triggers — kept centre-of-toolbar, right of Edit CSS & JS (Tom's call). -->
        <button type="button" class="pm-toolbar-btn pm-drawer-trig" data-drawer="styles"><i class="fa-solid fa-wand-magic-sparkles"></i> Page Styles</button>
        <!-- Article Meta — article mode only (CSS un-hides via [data-edit-type="article"]). -->
        <button type="button" class="pm-toolbar-btn pm-drawer-trig pm-settings-article" data-drawer="article" hidden><i class="fa-solid fa-id-card"></i> Article Meta</button>
      </div>
      <div class="pm-util-btns">
        <button type="button" class="pm-toolbar-btn ai-btn" id="edit-btn-ai"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Assist</button>
        <button type="button" class="pm-toolbar-btn versions-btn" id="edit-btn-versions" title="Browse and restore a previous saved version of this page"><i class="fa-solid fa-clock-rotate-left"></i> Versions</button>
        <button type="button" class="pm-toolbar-btn md-btn" id="edit-btn-md"><i class="fa-solid fa-file-code"></i> MD</button>
        <button type="button" class="pm-toolbar-btn home-btn" id="edit-btn-home"><i class="fa-solid fa-house"></i> Set as Home</button>
        <button type="button" class="pm-toolbar-btn menu-btn" id="edit-btn-add-menu"><i class="fa-solid fa-bars"></i> Add to Menu</button>
      </div>
    </div>

    <!-- Glassy drawers — collapsed by default; a trigger slides one open (accordion). -->
    <div class="pm-drawers">

      <!-- Drawer: Page Styles (tabbed — Page Customizations ⇄ Base Settings) -->
      <div class="pm-drawer pm-settings-textstyle" data-drawer="styles">
        <div class="pm-drawer-inner">
          <!-- "Page Settings" collapse row removed — the drawer trigger button already opens/closes this. -->
          <div class="pm-panel-tabbed pm-panel-merged" id="pm-settings-body">
            <div class="pm-tabs">
              <button type="button" class="pm-tab-lbl active" data-tab="cust">Page Customizations</button>
              <button type="button" class="pm-tab-lbl" data-tab="base">Base Settings</button>
            </div>
            <!-- Tab: Page Customizations (reading surface) -->
            <div class="pm-tab-panel active" data-tab="cust" data-title="Page Customizations">
              <div class="pm-cust-head">
                <label class="pm-panel-enable" title="Enable the reading surface for this page. Off = untouched."><input type="checkbox" id="edit-ts-enabled"> Enable</label>
                <label class="pm-cust-preset">Preset
                  <select id="edit-ts-preset">
                    <option value="base">Base</option>
                    <option value="apple">Apple</option>
                    <option value="auria">Auria</option>
                    <option value="custom">Custom</option>
                  </select>
                </label>
              </div>
              <div id="edit-ts-controls" class="pm-cust-controls" style="display:none">
                <label class="pm-cust-slider">Opacity <input type="range" id="edit-ts-opacity" min="0" max="100" value="45"></label>
                <label class="pm-cust-slider">Radius <input type="range" id="edit-ts-radius" min="0" max="40" value="8"></label>
                <label class="pm-cust-slider">Blur <input type="range" id="edit-ts-blur" min="0" max="40" value="0"></label>
              </div>
            </div>
            <!-- Tab: Base Settings — panels distributed HORIZONTALLY to keep the drawer short:
                 Excerpt | Page Width | (Content Padding + Text Surface) little panels. -->
            <div class="pm-tab-panel" data-tab="base" data-title="Base Settings">
<?php
                // On-bar tick positions + site-wide default width (used by both sliders below).
                $pwPos = fn($v,$min,$max) => max(0, min(100, round(($v - $min) / ($max - $min) * 100, 1)));
                $siteWidthPx = 1760;
                $sw = (string)($__pmSs['page_max_width'] ?? '');
                if     ($sw === 'standard') $siteWidthPx = 1200;
                elseif ($sw === 'wide')     $siteWidthPx = 1480;
                elseif ($sw === 'full')     $siteWidthPx = 1800;
                elseif (is_numeric($sw))    $siteWidthPx = (int)$sw;
              ?>
              <div class="pm-base-cols">
                <label class="pm-base-excerpt">Excerpt / Meta Description
                  <textarea id="edit-page-excerpt" rows="4" placeholder="Short summary for search engines + social cards (the page's &lt;meta description&gt;)."></textarea>
                </label>
                <div class="pm-base-col-right">
                  <div class="pm-pw pm-pw-w">
                    <div class="pm-pw-head">
                      <span class="pm-pw-title"><i class="fa-solid fa-arrows-left-right-to-line"></i> Page Width</span>
                      <label class="pm-pw-def"><input type="checkbox" id="edit-pw-width-default" checked> Site default</label>
                      <span class="pm-pw-val" id="edit-pw-width-val">1200px</span>
                    </div>
                    <div class="pm-pw-track">
                      <input type="range" id="edit-pw-width" min="640" max="2400" step="20" value="1200" disabled>
                      <div class="pm-pw-ticks">
                        <button type="button" class="pm-pw-chip pm-pw-tick" data-pw="width" data-v="1200" style="left:<?php echo $pwPos(1200,640,2400); ?>%"><i></i><b>1200</b></button>
                        <button type="button" class="pm-pw-chip pm-pw-tick" data-pw="width" data-v="1480" style="left:<?php echo $pwPos(1480,640,2400); ?>%"><i></i><b>1480</b></button>
                        <button type="button" class="pm-pw-chip pm-pw-tick" data-pw="width" data-v="1760" style="left:<?php echo $pwPos(1760,640,2400); ?>%"><i></i><b>1760</b></button>
                        <button type="button" class="pm-pw-chip pm-pw-tick" data-pw="width" data-v="2000" style="left:<?php echo $pwPos(2000,640,2400); ?>%"><i></i><b>2000</b></button>
                        <button type="button" class="pm-pw-chip pm-pw-tick pm-pw-chip-site" data-pw="width" data-v="<?php echo $siteWidthPx; ?>" style="left:<?php echo $pwPos($siteWidthPx,640,2400); ?>%" title="Match the site-wide default"><i></i><b>Site</b></button>
                      </div>
                    </div>
                    <label class="pm-pw-fluid"><input type="checkbox" id="edit-pw-fluid" disabled> Full / Fluid (fill the column)</label>
                  </div>
                  <p class="pm-base-note">Page width for THIS page — overrides the site default.</p>
                </div>
                <!-- Little panels to the RIGHT of Page Width (moved here to shorten the drawer). -->
                <div class="pm-base-extras">
                  <div class="pm-pw pm-pw-p">
                    <div class="pm-pw-head">
                      <span class="pm-pw-title"><i class="fa-solid fa-left-right"></i> Content Padding</span>
                      <label class="pm-pw-def"><input type="checkbox" id="edit-pw-pad-default" checked> Default</label>
                    </div>
                    <div class="pm-pw-track">
                      <div class="pm-pw-srow">
                        <input type="range" id="edit-pw-pad" min="0" max="120" step="2" value="24" disabled>
                        <span class="pm-pw-val" id="edit-pw-pad-val">24px</span>
                      </div>
                      <div class="pm-pw-ticks">
                        <button type="button" class="pm-pw-chip pm-pw-tick" data-pw="pad" data-v="16" style="left:<?php echo $pwPos(16,0,120); ?>%"><i></i><b>16</b></button>
                        <button type="button" class="pm-pw-chip pm-pw-tick" data-pw="pad" data-v="24" style="left:<?php echo $pwPos(24,0,120); ?>%"><i></i><b>24</b></button>
                        <button type="button" class="pm-pw-chip pm-pw-tick" data-pw="pad" data-v="48" style="left:<?php echo $pwPos(48,0,120); ?>%"><i></i><b>48</b></button>
                        <button type="button" class="pm-pw-chip pm-pw-tick" data-pw="pad" data-v="64" style="left:<?php echo $pwPos(64,0,120); ?>%"><i></i><b>64</b></button>
                      </div>
                    </div>
                  </div>
                  <div class="pm-mini-panel">
                    <span class="pm-mini-title">Text Surface</span>
                    <label class="pm-ts-chk"><input type="checkbox" id="edit-ts-shadow"> Shadow</label>
                    <label class="pm-ts-chk"><input type="checkbox" id="edit-ts-glow"> Glow</label>
                  </div>
                </div>
                <!-- Base Text Styles checkbox removed (useless per Tom). Hidden input kept checked so the save path keeps base typography ON by default. -->
                <input type="checkbox" id="edit-lum-text" checked hidden>
              </div><!-- /pm-base-cols -->
            </div>
          </div>
        </div>
      </div>

      <!-- Drawer: Right Column injection override -->
      <div class="pm-drawer pm-settings-inject" data-drawer="rightcol" title="Override this item's right-column providers. 'Default' inherits the site-wide setting for this content type.">
        <div class="pm-drawer-inner pm-drawer-inline">
          <label>Affiliate
            <select id="edit-inject-affiliate">
              <option value="inherit">Default</option>
              <option value="on">On</option>
              <option value="off">Off</option>
            </select>
          </label>
          <label>UCS
            <select id="edit-inject-ucs">
              <option value="inherit">Default</option>
              <option value="on">On</option>
              <option value="off">Off</option>
            </select>
          </label>
          <label>Cols
            <select id="edit-inject-columns">
              <option value="0">Default</option>
              <option value="1">1</option>
              <option value="2">2</option>
              <option value="3">3</option>
            </select>
          </label>
        </div>
      </div>

      <!-- Drawer: Article meta (article mode only) -->
      <div class="pm-drawer pm-settings-article" data-drawer="article" hidden>
        <div class="pm-drawer-inner pm-article-fields">
          <!-- Two columns: all meta packed LEFT, Excerpt + Hero up on the RIGHT. -->
          <div class="pm-art-grid">
            <div class="pm-art-meta">
              <div class="pm-af-row">
                <label>Eyebrow <input type="text" id="edit-art-eyebrow" placeholder="CATEGORY"></label>
                <label>Author <input type="text" id="edit-art-author"></label>
                <label>Date <input type="datetime-local" id="edit-art-date"></label>
              </div>
              <div class="pm-af-row">
                <label>Tags <input type="text" id="edit-art-tags" placeholder="outlander, drama, season-5"></label>
                <label>Category <input type="text" id="edit-art-category"></label>
                <label class="pm-af-chk"><input type="checkbox" id="edit-art-pinned"> Pinned</label>
              </div>
            </div>
            <div class="pm-art-side">
              <label class="pm-af-full pm-art-excerpt">Excerpt <textarea id="edit-art-excerpt" rows="4" placeholder="Short summary (used in feeds + cards)"></textarea></label>
              <div class="pm-art-hero">
                <label>Hero Image <span class="pm-pick-wrap"><input type="text" id="edit-art-hero" placeholder="/media/images/articles/slug.jpg"><button type="button" class="pm-pick-btn" data-pick-target="edit-art-hero" title="Browse media">&#128193;</button></span></label>
                <div class="pm-art-hero-thumb" id="edit-art-hero-thumb"><span class="pm-art-hero-empty">No image</span></div>
              </div>
            </div>
          </div>
          <!-- Published is always true on save (checkbox hidden but kept for JS compat). -->
          <input type="checkbox" id="edit-art-published" checked hidden>
        </div>
      </div>
    </div>

    <!-- Drawer + tab handlers (self-contained; delegated so DOM order is irrelevant) -->
    <script>
    (function(){
      if (window.__pmTopWired) return; window.__pmTopWired = true;
      document.addEventListener('click', function(e){
        // Glassy drawers — accordion (opening one closes the others)
        var trig = e.target.closest('.pm-drawer-trig');
        if (trig) {
          var name = trig.getAttribute('data-drawer');
          var scope = trig.closest('.pm-edit-modal') || document;
          var willOpen = !trig.classList.contains('active');
          scope.querySelectorAll('.pm-drawer-trig').forEach(function(t){ t.classList.toggle('active', t === trig && willOpen); });
          scope.querySelectorAll('.pm-drawer').forEach(function(d){ d.classList.toggle('open', willOpen && d.getAttribute('data-drawer') === name); });
          return;
        }
        // Tab swap inside the Page Styles drawer
        var t = e.target.closest('.pm-tab-lbl'); if (!t) return;
        var panel = t.closest('.pm-panel-tabbed'); if (!panel) return;
        var tab = t.getAttribute('data-tab');
        panel.querySelectorAll('.pm-tab-lbl').forEach(function(l){ l.classList.toggle('active', l === t); });
        panel.querySelectorAll('.pm-tab-panel').forEach(function(p){ p.classList.toggle('active', p.getAttribute('data-tab') === tab); });
      });
    })();
    </script>

    <!-- ④ Primary bar — Back to Preview (shown only while editing a column) -->
    <div class="pm-edit-modal-toolbar pm-primary-bar">
      <button type="button" class="pm-toolbar-btn back-btn" id="edit-btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Preview</button>
    </div>

    <!-- Version history picker modal -->
    <div class="pm-versions-overlay" id="pm-versions-overlay" style="display:none;position:fixed;inset:0;z-index:10050;background:rgba(0,0,0,.7)">
      <div class="pm-versions-modal" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(720px,92vw);max-height:80vh;background:#1a1a24;border:1px solid #333;border-radius:10px;display:flex;flex-direction:column;overflow:hidden">
        <div style="padding:14px 18px;border-bottom:1px solid #333;display:flex;justify-content:space-between;align-items:center;background:#11111a">
          <h3 style="margin:0;color:#e8edf5;font-size:1.05rem"><i class="fa-solid fa-clock-rotate-left"></i> Previous Versions</h3>
          <button type="button" id="pm-versions-close" style="background:none;border:none;color:#888;font-size:1.6rem;cursor:pointer;line-height:1">&times;</button>
        </div>
        <div id="pm-versions-list" style="flex:1;overflow:auto;padding:12px 18px;color:#ccd">
          <div style="color:#666;text-align:center;padding:30px">Loading…</div>
        </div>
        <div style="padding:10px 18px;border-top:1px solid #333;font-size:.8rem;color:#666;background:#11111a">
          Up to 5 revisions kept per page. Restoring loads the version into the editor — click Save to commit it.
        </div>
      </div>
    </div>

    <!-- Content Stacks in-place editor — iframe modal (opened by Edit Shortcode / chip ✎ on a [[stack:…]]) -->
    <div class="pm-cs-overlay" id="pm-cs-overlay" style="display:none">
      <div class="pm-cs-modal">
        <div class="pm-cs-bar">
          <span class="pm-cs-title"><i class="fa-solid fa-layer-group"></i> Edit Stack: <b id="pm-cs-slug"></b></span>
          <span class="pm-cs-hint">Save inside the builder — this page's preview refreshes automatically.</span>
          <button type="button" class="pm-cs-close" id="pm-cs-close" title="Close (Esc)">&times;</button>
        </div>
        <iframe id="pm-cs-frame" title="Content Stacks editor" src="about:blank"></iframe>
      </div>
    </div>

    <div class="pm-edit-modal-body">
      <div class="pm-edit-preview" id="edit-preview-pane">
        <iframe id="edit-preview-iframe" src="about:blank"></iframe>
      </div>
      <div class="pm-edit-editor hidden" id="edit-editor-pane">
        <div class="pm-modal-sidebar" id="edit-sidebar">
          <?php echo ri_render_insert_explorer(); ?>
        </div>
        <div class="pm-sidebar-resizer" data-resize-for="edit-sidebar" title="Drag to resize the Insert Explorer"></div>
        <div class="pm-modal-main">
          <div class="pm-html-toolbar hidden" id="pm-html-toolbar">
            <button class="pm-ht-btn" data-cmd="bold" title="Bold"><b>B</b></button>
            <button class="pm-ht-btn" data-cmd="italic" title="Italic"><i>I</i></button>
            <button class="pm-ht-btn" data-cmd="underline" title="Underline"><u>U</u></button>
            <span class="pm-ht-sep"></span>
            <select class="pm-ht-select" data-cmd="formatBlock" title="Format">
              <option value="p">P</option>
              <option value="h1">H1</option>
              <option value="h2">H2</option>
              <option value="h3">H3</option>
              <option value="h4">H4</option>
              <option value="pre">Pre</option>
              <option value="blockquote">Quote</option>
            </select>
            <span class="pm-ht-sep"></span>
            <button class="pm-ht-btn" data-cmd="insertUnorderedList" title="Bullet list"><i class="fa-solid fa-list-ul"></i></button>
            <button class="pm-ht-btn" data-cmd="insertOrderedList" title="Numbered list"><i class="fa-solid fa-list-ol"></i></button>
            <span class="pm-ht-sep"></span>
            <button class="pm-ht-btn" data-action="pm-link" title="Insert link"><i class="fa-solid fa-link"></i></button>
            <button class="pm-ht-btn" data-cmd="removeFormat" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
            <span class="pm-ht-sep"></span>
            <span class="pm-ht-mode-indicator pm-ht-mode-source" id="pm-ht-mode-indicator" title="Click Source button to toggle">ACTIVE: SOURCE MODE</span>
            <button class="pm-ht-btn pm-ht-source-toggle" data-action="pm-source-toggle" title="Switch between Source (HTML) and Visual / WYSIWYG">
              <i class="fa-solid fa-eye pm-src-ico"></i> <span class="pm-src-lbl">WYSIWYG</span>
            </button>
            <button class="pm-ht-btn" data-action="pm-pretty" title="Pretty-print HTML/MD with indentation (switches to Source view)">
              <i class="fa-solid fa-indent"></i> Pretty
            </button>
            <button class="pm-ht-btn" data-action="pm-minify" title="Collapse HTML to a single line (switches to Source view)">
              <i class="fa-solid fa-compress"></i> Minify
            </button>
            <span class="pm-ht-sep"></span>
            <button class="pm-ht-btn pm-ht-convert" data-action="pm-convert-block" id="pm-convert-block" title="Convert the selected HTML (or the whole column) into a reusable [[html-block]] shortcode — captures its CSS automatically">
              <i class="fa-solid fa-cube"></i> → HTML Block
            </button>
            <button class="pm-ht-btn pm-ht-editsc" data-action="pm-edit-shortcode" id="pm-edit-shortcode" title="Edit the [[html-block:…]] your cursor is inside — opens it right here, no trip to the HTML Blocks module">
              <i class="fa-solid fa-pen-to-square"></i> Edit Shortcode
            </button>
            <button class="pm-ht-btn pm-ht-unwind" data-action="pm-unwind-shortcode" id="pm-unwind-shortcode" title="Unwind the shortcode at your cursor into inline HTML on this page. html-block pastes its authored source; a stack or other shortcode pastes a snapshot of what it renders (the original is not deleted)">
              <i class="fa-solid fa-box-open"></i> Unwind
            </button>
          </div>
          <!-- Edit-target readout: proves which shortcode Edit Shortcode / Unwind will act on.
               Hover it (or the Edit Shortcode button) to highlight that token in the editor. -->
          <div class="pm-sc-target" id="pm-sc-target" style="display:none" title="The shortcode your cursor is in — what “Edit Shortcode” and “Unwind” will act on. Hover to highlight it; click to edit.">
            <span class="pm-sc-target-none">Cursor is not inside a shortcode</span>
          </div>
          <!-- Visual edit: iframe loads edit_frame.php with real site CSS for WYSIWYG fidelity -->
          <iframe class="pm-edit-frame hidden" id="pm-edit-frame" title="Visual editor"></iframe>
          <textarea id="edit-textarea" placeholder="Content — shortcodes, HTML..."></textarea>
          <div class="pm-modal-status" id="edit-status">Ready</div>
        </div>
      </div>
      <!-- Style Editor Pane — 2 Column -->
      <div class="pm-style-pane hidden" id="edit-style-pane">
        <div class="pm-style-toolbar">
          <span class="pm-style-title"><i class="fa-solid fa-palette"></i> Page Style Editor</span>
          <div class="pm-style-toolbar-right">
            <button type="button" class="pm-style-tab active" data-stab="css">CSS</button>
            <button type="button" class="pm-style-tab" data-stab="js">JS</button>
            <div class="pm-style-toolbar-sep"></div>
            <button type="button" class="pm-style-add-btn" id="style-add-var"><i class="fa-solid fa-plus"></i> Add Variable</button>
          </div>
        </div>
        <div class="pm-style-columns">
          <div class="pm-style-col-left">
            <textarea id="style-raw-css" class="pm-style-raw" placeholder="Page CSS — custom styles..." spellcheck="false"></textarea>
            <textarea id="style-raw-js" class="pm-style-raw hidden" placeholder="Page JavaScript — runs on page load..." spellcheck="false"></textarea>
          </div>
          <div class="pm-style-col-right" id="style-variables-container">
            <!-- Dynamically populated by JS -->
          </div>
        </div>
      </div>
      <!-- Markdown Editor Overlay -->
      <div class="pm-md-pane hidden" id="edit-md-pane">
        <div style="display:flex;flex-direction:column;height:100%;padding:16px;gap:12px;background:#0a0e14;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="font:600 14px system-ui;color:#34d399;">Markdown Composer</span>
            <div style="display:flex;gap:8px;">
              <button class="pm-toolbar-btn" id="md-insert-html" style="border-color:#059669;color:#34d399;font-size:12px;padding:5px 12px;">Insert as HTML</button>
              <button class="pm-toolbar-btn" id="md-insert-raw" style="border-color:#6366f1;color:#818cf8;font-size:12px;padding:5px 12px;">Insert as Markdown</button>
              <button class="pm-toolbar-btn" id="md-close" style="font-size:12px;padding:5px 12px;">Close</button>
            </div>
          </div>
          <div id="pm-md-editor-container" style="flex:1;min-height:0;"></div>
        </div>
      </div>
      <!-- AI Assist Pane -->
      <div class="pm-ai-pane hidden" id="edit-ai-pane">
        <div class="pm-ai-controls">
          <div class="pm-ai-provider-bar" id="ai-provider-bar" style="display:none">
            <div class="pm-ai-provider-bar-head">
              <div class="pm-ai-provider-bar-icon" id="ai-pbar-icon">?</div>
              <div class="pm-ai-provider-bar-title" id="ai-pbar-title">—</div>
              <span class="pm-ai-provider-bar-tag" id="ai-pbar-tag">ACTIVE</span>
            </div>
            <div class="pm-ai-provider-bar-rows">
              <span class="pm-ai-provider-bar-label">Type</span>
              <span class="pm-ai-provider-bar-value" id="ai-pbar-type">—</span>
              <span class="pm-ai-provider-bar-label">Model</span>
              <span class="pm-ai-provider-bar-value" id="ai-pbar-model">—</span>
              <span class="pm-ai-provider-bar-label">API Key</span>
              <span class="pm-ai-provider-bar-value" id="ai-pbar-key">—</span>
            </div>
          </div>
          <div class="pm-ai-section">
            <label class="pm-ai-label">PRESETS</label>
            <div class="pm-ai-presets" id="ai-presets">
              <button type="button" class="pm-ai-preset" data-prompt="Write a compelling event summary with dates, times, and descriptions. Use exciting language to drive attendance." data-tone="enthusiastic" data-type="event_summary">Event Summary</button>
              <button type="button" class="pm-ai-preset" data-prompt="Write a professional bio/about section highlighting background, skills, and personality." data-tone="professional" data-type="bio">Bio / About</button>
              <button type="button" class="pm-ai-preset" data-prompt="Write an introduction for a media gallery page that contextualizes the collection." data-tone="creative" data-type="gallery_intro">Gallery Intro</button>
              <button type="button" class="pm-ai-preset" data-prompt="Create a welcoming landing page section with a strong headline, brief description, and call to action." data-tone="friendly" data-type="landing">Welcome Landing</button>
              <button type="button" class="pm-ai-preset" data-prompt="Write a services section listing what is offered, with brief descriptions for each." data-tone="professional" data-type="services">Services</button>
              <button type="button" class="pm-ai-preset" data-prompt="Create a contact section with invitation to reach out, emphasizing availability and responsiveness." data-tone="friendly" data-type="contact">Contact Section</button>
            </div>
          </div>
          <div class="pm-ai-section">
            <label class="pm-ai-label" for="ai-prompt">PROMPT</label>
            <textarea id="ai-prompt" class="pm-ai-prompt" placeholder="Describe what you want to generate..." rows="5"></textarea>
            <div class="pm-ai-prompt-commons">
              <select id="ai-prompt-commons-select" class="pm-ai-select pm-ai-pc-select" title="Load a saved prompt">
                <option value="">Prompt Commons...</option>
              </select>
              <button type="button" class="pm-ai-pc-btn" id="ai-prompt-save-btn" title="Save current prompt to Prompt Commons"><i class="fa-solid fa-bookmark"></i> Save Prompt</button>
              <button type="button" class="pm-ai-pc-btn danger" id="ai-prompt-delete-btn" title="Delete selected prompt" style="display:none"><i class="fa-solid fa-trash"></i></button>
            </div>
          </div>
          <div class="pm-ai-row">
            <div class="pm-ai-field">
              <label class="pm-ai-label" for="ai-tone">TONE</label>
              <select id="ai-tone" class="pm-ai-select">
                <option value="professional">Professional</option>
                <option value="friendly">Friendly</option>
                <option value="enthusiastic">Enthusiastic</option>
                <option value="creative">Creative</option>
                <option value="formal">Formal</option>
                <option value="casual">Casual</option>
                <option value="humorous">Humorous</option>
              </select>
            </div>
            <div class="pm-ai-field">
              <label class="pm-ai-label" for="ai-type">CONTENT TYPE</label>
              <select id="ai-type" class="pm-ai-select">
                <option value="article">Article</option>
                <option value="landing">Landing Page</option>
                <option value="bio">Bio / About</option>
                <option value="event_summary">Event Summary</option>
                <option value="gallery_intro">Gallery Intro</option>
                <option value="services">Services</option>
                <option value="contact">Contact</option>
                <option value="freeform">Freeform</option>
              </select>
            </div>
            <div class="pm-ai-field">
              <label class="pm-ai-label" for="ai-provider">AI PROVIDER</label>
              <select id="ai-provider" class="pm-ai-select">
                <option value="">Default</option>
              </select>
            </div>
          </div>
          <div class="pm-ai-row">
            <div class="pm-ai-field" style="flex:1">
              <label class="pm-ai-label">WORD COUNT: <span id="ai-wordcount-lbl">300</span></label>
              <input type="range" id="ai-wordcount" class="pm-ai-slider" min="50" max="1500" value="300" step="50">
            </div>
          </div>
          <div class="pm-ai-row" id="ai-images-row">
            <label class="pm-ai-images-toggle" title="Generate AI images with DALL-E alongside content">
              <input type="checkbox" id="ai-include-images">
              <span class="pm-ai-images-label"><i class="fa-solid fa-images"></i> Include AI Images</span>
              <span class="pm-ai-images-badge" id="ai-images-badge" style="display:none">DALL-E</span>
            </label>
          </div>
          <div class="pm-ai-img-options" id="ai-img-options" style="display:none">
            <div class="pm-ai-img-opt-row">
              <label class="pm-ai-img-opt-label">IMAGE COUNT</label>
              <div class="pm-ai-img-stepper">
                <button type="button" class="pm-ai-img-step-btn" id="ai-imgcount-dec">&minus;</button>
                <span class="pm-ai-img-step-val" id="ai-imgcount-val">3</span>
                <button type="button" class="pm-ai-img-step-btn" id="ai-imgcount-inc">+</button>
              </div>
            </div>
            <div class="pm-ai-img-opt-row">
              <label class="pm-ai-img-opt-label">LAYOUT STYLE</label>
              <div class="pm-ai-img-layout-btns" id="ai-img-layout-btns">
                <button type="button" class="pm-ai-img-layout-btn active" data-layout="mixed" title="Mixed column widths — hero, inline, thumbnail variety"><i class="fa-solid fa-table-cells"></i> Mixed Sizes</button>
                <button type="button" class="pm-ai-img-layout-btn" data-layout="fixed" title="All images same uniform size"><i class="fa-solid fa-grip"></i> Fixed Columns</button>
                <button type="button" class="pm-ai-img-layout-btn" data-layout="mixed-inset" title="Mixed sizes with inset/float positioning"><i class="fa-solid fa-newspaper"></i> Mixed + Insets</button>
              </div>
            </div>
            <label class="pm-ai-img-relevance" title="AI chooses contextually appropriate images for the content">
              <input type="checkbox" id="ai-img-relevant" checked>
              <span>Generate Relevant Images</span>
            </label>
          </div>
          <button type="button" class="pm-ai-generate-btn" id="ai-generate-btn">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Generate
          </button>
          <div class="pm-ai-status" id="ai-status"></div>
          <div class="pm-ai-token-badge" id="ai-token-badge" style="display:none"></div>

          <!-- ── NotebookLM Podcast ── -->
          <hr class="pm-nlm-divider">
          <label class="pm-nlm-label"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" style="flex-shrink:0"><rect width="24" height="24" rx="6" fill="url(#nlmG1)"/><path d="M7 8.5C7 7.67 7.67 7 8.5 7H11v10H8.5C7.67 17 7 16.33 7 15.5v-7z" fill="#fff" opacity=".9"/><path d="M13 7h2.5C16.33 7 17 7.67 17 8.5v7c0 .83-.67 1.5-1.5 1.5H13V7z" fill="#fff" opacity=".7"/><defs><linearGradient id="nlmG1" x1="0" y1="0" x2="24" y2="24"><stop stop-color="#7c3aed"/><stop offset="1" stop-color="#ec4899"/></linearGradient></defs></svg> NOTEBOOKLM AUDIO</label>
          <div class="pm-nlm-api-bar" id="nlm-api-bar">
            <div class="pm-nlm-api-bar-left">
              <span class="pm-nlm-api-dot" id="nlm-panel-dot"></span>
              <span class="pm-nlm-api-text"><strong>Google Cloud</strong> &middot; NotebookLM Podcast API</span>
            </div>
            <span class="pm-nlm-api-acct" id="nlm-panel-acct"></span>
          </div>
          <input type="text" class="pm-nlm-input" id="nlm-notebook-name" placeholder="Notebook name (defaults to page slug)">
          <textarea class="pm-nlm-input" id="nlm-focus" rows="2" placeholder="What should the podcast emphasize? (optional)" style="resize:vertical;font:12px system-ui"></textarea>
          <div class="pm-nlm-section">
            <label class="pm-nlm-sublabel">FORMAT</label>
            <div class="pm-nlm-row">
              <button type="button" class="pm-nlm-type-btn active" data-type="CONVERSATION" title="Two hosts discuss the topic naturally">Conversation</button>
              <button type="button" class="pm-nlm-type-btn" data-type="DEEP_DIVE" title="In-depth exploration of the topic">Deep Dive</button>
              <button type="button" class="pm-nlm-type-btn" data-type="DEBATE" title="Two perspectives argue different sides">Debate</button>
            </div>
          </div>
          <div class="pm-nlm-section">
            <label class="pm-nlm-sublabel">LENGTH</label>
            <div class="pm-nlm-row">
              <button type="button" class="pm-nlm-len-btn" data-len="SHORT" id="nlm-len-short">Short (~5 min)</button>
              <button type="button" class="pm-nlm-len-btn active" data-len="STANDARD" id="nlm-len-standard">Standard (~10 min)</button>
              <button type="button" class="pm-nlm-len-btn" data-len="LONG" id="nlm-len-long">Long (~20 min)</button>
            </div>
          </div>
          <div class="pm-nlm-source-note" id="nlm-source-note" style="display:none">
            <i class="fa-solid fa-file-alt"></i> <span id="nlm-source-note-text">AI-generated content added as source</span>
          </div>
          <button type="button" class="pm-nlm-generate-btn" id="nlm-generate-btn">
            <i class="fa-solid fa-podcast"></i> Generate Podcast
          </button>
          <div class="pm-nlm-progress" id="nlm-progress"><div class="pm-nlm-progress-bar" id="nlm-progress-bar"></div></div>
          <div class="pm-nlm-status" id="nlm-status"></div>

          <hr class="pm-nlm-divider">
          <label class="pm-nlm-label"><i class="fa-solid fa-clock-rotate-left"></i> PODCAST HISTORY</label>
          <div class="pm-nlm-history" id="nlm-history">
            <div class="pm-nlm-empty">Loading...</div>
          </div>
        </div>
        <div class="pm-ai-result">
          <div class="pm-ai-result-header">
            <span class="pm-ai-result-title">Generated Content</span>
            <div class="pm-ai-result-actions" id="ai-result-actions" style="display:none">
              <button type="button" class="pm-ai-insert-btn" data-target="left">Insert into Left Column</button>
              <button type="button" class="pm-ai-insert-btn" data-target="right">Insert into Right Column</button>
              <button type="button" class="pm-ai-insert-btn replace" data-target="replace-left">Replace Left Column</button>
              <button type="button" class="pm-ai-insert-btn nlm-send" data-target="nlm" title="Use this content as source for NotebookLM podcast"><i class="fa-solid fa-podcast"></i> Send to NotebookLM</button>
            </div>
          </div>
          <div class="pm-ai-preview-wrap">
            <iframe id="ai-preview-iframe" class="pm-ai-preview-iframe" sandbox="allow-same-origin" srcdoc="<html><body style='background:#111;color:#888;font:14px system-ui;padding:40px;text-align:center'>Generate content to see a preview here</body></html>"></iframe>
          </div>
          <div class="pm-ai-source-toggle">
            <button type="button" class="pm-ai-toggle-btn" id="ai-toggle-source">View Source</button>
            <button type="button" class="pm-ai-toggle-btn pm-ai-drafts-btn" id="ai-toggle-drafts"><i class="fa-solid fa-clock-rotate-left"></i> Drafts</button>
          </div>
          <pre class="pm-ai-source hidden" id="ai-source-code"></pre>
          <div class="pm-ai-drafts-panel hidden" id="ai-drafts-panel">
            <div class="pm-ai-drafts-header">
              <span class="pm-ai-drafts-title"><i class="fa-solid fa-clock-rotate-left"></i> AI GENERATION DRAFTS</span>
              <span class="pm-ai-drafts-count" id="ai-drafts-count">0</span>
            </div>
            <div class="pm-ai-drafts-list" id="ai-drafts-list">
              <div class="pm-ai-drafts-empty">No drafts yet. Generate content to auto-save drafts.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="pm-new-overlay" id="pm-new-overlay">
  <div class="pm-new-modal">
    <div class="pm-new-modal-header">
      <h3>Create New Page</h3>
      <button type="button" class="pm-edit-close" id="new-btn-close">&times;</button>
    </div>
    <div class="pm-new-modal-body">
      <input type="text" id="new-page-title" placeholder="Page Title">
      <div class="pm-new-settings">
        <label>COLUMN WIDTHS</label>
        <span class="slider-lbl" id="new-left-lbl">65%</span>
        <input type="range" id="new-left-pct" min="25" max="75" value="65">
        <span class="slider-lbl" id="new-right-lbl">35%</span>
        <label><input type="checkbox" id="new-right-enabled" checked> ENABLE RIGHT COLUMN</label>
        <label title="Luminal base typography for text sections. Uncheck for raw, unstyled text."><input type="checkbox" id="new-lum-text" checked> BASE TEXT STYLES</label>
      </div>
      <div class="pm-new-editor-btns">
        <button type="button" class="pm-toolbar-btn" id="new-btn-left"><i class="fa-solid fa-pen-to-square"></i> Edit Left Column</button>
        <button type="button" class="pm-toolbar-btn" id="new-btn-right"><i class="fa-solid fa-pen-to-square"></i> Edit Right Column</button>
        <button type="button" class="pm-toolbar-btn css-btn" id="new-btn-css"><i class="fa-solid fa-palette"></i> Edit CSS &amp; JS</button>
      </div>
      <div class="pm-new-editor-area" id="new-editor-area">
        <div class="pm-modal-sidebar" id="new-sidebar">
          <?php echo ri_render_insert_explorer(); ?>
        </div>
        <div class="pm-sidebar-resizer" data-resize-for="new-sidebar" title="Drag to resize the Insert Explorer"></div>
        <div class="pm-modal-main">
          <textarea id="new-textarea" placeholder="Content — shortcodes, HTML..."></textarea>
          <div class="pm-modal-status" id="new-status">Ready</div>
        </div>
      </div>
    </div>
    <div class="pm-new-modal-footer">
      <button type="button" class="pm-btn pm-btn-save" id="new-btn-create">Create & Save</button>
    </div>
  </div>
</div>

<?php
/* Render the pill boxes for modal sidebars */
function ri_render_insert_explorer(){
  // The Insert Explorer — ONE surface for everything insertable. Replaces
  // the legacy 8-box shortcode pill sidebar (2026-06-06): media tabs plus
  // an Inserts tab (stacks, galleries, panels, widgets, affiliates, forms)
  // served by /admin/shared/explorer/insertables.php. Click = lightbox
  // live preview, drag into the editor (or lightbox Insert) to place.
  ob_start();
  ?>
  <div class="pm-picker-box pm-insert-explorer">
    <div class="pm-picker-header"><i class="fa-solid fa-photo-film"></i><span>INSERT EXPLORER</span></div>
    <div class="pm-insert-explorer-wrap">
      <iframe class="pm-media-browser-iframe" src="/admin/shared/explorer/media-explorer.php?host=pagemanager&types=inserts,images,videos,pdfs,audio" loading="lazy" title="Insert Explorer"></iframe>
    </div>
  </div>
  <?php
  return ob_get_clean();
}
?>

<script>window.PM_HOME_SLUG = <?php echo json_encode($homePageSlug); ?>;</script>
<script src="<?= sc_asset('/admin/shared/markdown-editor.js') ?>"></script>
<script src="<?= sc_asset('/admin/modules/PageManager/js/page-manager-edit.js') ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
