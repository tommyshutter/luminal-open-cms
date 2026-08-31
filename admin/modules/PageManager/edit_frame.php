<?php
/**
 * Page Manager — Visual Edit Frame
 *
 * Renders a single page column (left/right/css) inside an iframe so the
 * Edit view picks up the site's real stylesheets + page-specific CSS,
 * making WYSIWYG editing match the public /page.php preview. The content
 * zone is `contenteditable` and proxies edits to the parent modal via
 * postMessage.
 *
 * Query:
 *   slug=<page-slug>   required
 *   col=left|right|css default 'left'
 *   type=page|article  default 'page' (controls storage lookup)
 *
 * @package  LuminalCMS
 * @module   PageManager
 * @version  1.0.0
 * @file     /admin/modules/PageManager/edit_frame.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/auth.php';
requireAuth();

$slug = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['slug'] ?? ''));
$col  = (string)($_GET['col']  ?? 'left');
$type = (string)($_GET['type'] ?? 'page');
if (!in_array($col, ['left','right','css'], true)) $col = 'left';
if (!in_array($type, ['page','article'], true)) $type = 'page';

/* ---- Load raw content ---- */
$leftHtml = $rightHtml = $cssText = '';

if ($type === 'article') {
    $storeFile = SITE_ROOT . '/admin/modules/ArticlesManager/lib/ArticleStore.php';
    if (is_file($storeFile)) {
        require_once $storeFile;
        if (class_exists('\\ArticlesManager\\ArticleStore')) {
            $store = new \ArticlesManager\ArticleStore(SITE_ROOT);
            $art = $store->get($slug);
            if (is_array($art)) {
                $leftHtml = (string)($art['body_html'] ?? '');
            }
        }
    }
} else {
    $pagesDir = SITE_ROOT . '/admin/data/pages/' . $slug;
    $file = $pagesDir . '/' . $slug . '.json';
    if (is_file($file)) {
        $data = json_decode((string)@file_get_contents($file), true) ?: [];
        $leftHtml  = (string)($data['components']['main_content']['content']  ?? '');
        $rightHtml = (string)($data['components']['right_column']['content'] ?? '');
    }
    $cssFile = $pagesDir . '/' . $slug . '.css';
    if (is_file($cssFile)) $cssText = (string)@file_get_contents($cssFile);
}

$content = ($col === 'right') ? $rightHtml : (($col === 'css') ? $cssText : $leftHtml);

/* ---- Resolve asset versions ---- */
$stylesCss     = SITE_ROOT . '/styles.css';
$stylesMtime   = is_file($stylesCss) ? (int)filemtime($stylesCss) : time();
$pageCssPath   = SITE_ROOT . '/admin/data/pages/' . $slug . '/' . $slug . '.css';
$pageCssMtime  = is_file($pageCssPath) ? (int)filemtime($pageCssPath) : 0;

/* ---- Helpers ---- */
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PM Edit — <?= $h($slug) ?> · <?= $h($col) ?></title>
<base target="_blank">
<link rel="stylesheet" href="/styles.css?v=<?= $stylesMtime ?>">
<?php /* Footer-injected shortcode stylesheets aren't part of styles.css, so inserted
         widgets render unstyled in the editor. Load the ones with front-end CSS here
         so the preview matches the public page (e.g. AudienceBuilder forms). */ ?>
<?php if (is_file(SITE_ROOT . '/css/ab-forms.css')): ?>
<link rel="stylesheet" href="/css/ab-forms.css">
<?php endif; ?>
<?php if ($pageCssMtime): ?>
<link rel="stylesheet" href="/admin/data/pages/<?= $h($slug) ?>/<?= $h($slug) ?>.css?v=<?= $pageCssMtime ?>">
<?php endif; ?>
<style>
  /* Edit-frame scaffold — mirror the site's page wrapper so inherited
     styles apply, but override body to transparent since the parent
     iframe sits on the admin surface. Respect the transparent-page rule. */
  html, body { background: transparent !important; margin: 0; padding: 0; min-height: 100vh; }
  body { padding: 18px 22px; color: #e5e7eb; }
  /* Dim background helper so contenteditable is visible against admin dark chrome. */
  body::before {
    content: ""; position: fixed; inset: 0; z-index: -1;
    background: linear-gradient(180deg, rgba(15,18,25,.92), rgba(9,11,16,.95));
    backdrop-filter: blur(6px);
  }
  /* ===== Editor background switcher — EDITOR-ONLY (never affects the live page) =====
     Dark = the default dim scrim; White = flat white surface (see dark text as printed);
     Site = fully transparent so the real front/admin background shows through. */
  body.pmef-bg-white { color: #1a1e28; }
  body.pmef-bg-white::before { background: #ffffff; backdrop-filter: none; }
  body.pmef-bg-through::before { background: transparent; backdrop-filter: none; }
  .pmef-bg-switch {
    position: fixed; top: 8px; right: 8px; z-index: 2147483200;
    display: inline-flex; gap: 2px; background: rgba(18,21,29,.85);
    border: 1px solid rgba(255,255,255,.15); border-radius: 8px; padding: 3px;
    box-shadow: 0 6px 20px rgba(0,0,0,.4); user-select: none;
  }
  .pmef-bg-switch button {
    background: transparent; border: 0; color: #cbd5e1; cursor: pointer;
    font: 600 11px/1 system-ui, sans-serif; padding: 5px 9px; border-radius: 6px;
  }
  .pmef-bg-switch button:hover { color: #fff; background: rgba(255,255,255,.08); }
  .pmef-bg-switch button.active { background: #6580eb; color: #fff; }
  .pmef-zone {
    min-height: calc(100vh - 40px);
    outline: 2px dashed rgba(101,128,235,.35);
    outline-offset: 6px;
    border-radius: 8px;
    padding: 6px;
    background: transparent;
    transition: outline-color .15s;
  }
  .pmef-zone:focus { outline-color: #6580eb; outline-style: solid; }
  .pmef-zone.pmef-drop-armed { outline-color: #00d2ff; outline-style: dashed; background: rgba(0,210,255,.04); }
  .pmef-zone[data-col="css"] {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    white-space: pre-wrap;
    background: rgba(0,0,0,.35);
    color: #e2e8f0;
    padding: 12px;
  }
  /* Discourage navigation from edit frame. */
  .pmef-zone a { cursor: text; }
  .pmef-zone a:hover::after {
    content: " (click disabled in edit)";
    font-size: .7em; color: #94a3b8;
  }

  /* --- Per-media Lightbox toggle (right-click an image or video) --- */
  .pmef-zone img, .pmef-zone video { cursor: context-menu; }   /* hint: right-click for options */

  /* Editor-only badge on lightbox-enabled images (generated content — NOT saved). */
  .pmef-zone a[data-lightbox] {
    position: relative; display: inline-block;
    outline: 1px dashed rgba(0,210,255,.6); outline-offset: 3px;
  }
  .pmef-zone a[data-lightbox]::before {
    content: "🔦 lightbox"; position: absolute; top: 4px; left: 4px; z-index: 2;
    background: rgba(0,150,200,.92); color: #fff;
    font: 600 10px/1 system-ui, -apple-system, sans-serif; letter-spacing: .2px;
    padding: 3px 6px; border-radius: 999px; pointer-events: none;
  }
  /* Suppress the generic "click disabled" hint on lightbox anchors (misleading here). */
  .pmef-zone a[data-lightbox]:hover::after { content: ""; }

  /* The right-click menu (appended to this frame's body). */
  .pmef-img-menu {
    position: fixed; z-index: 2147483000;
    background: #1a1e28; border: 1px solid rgba(124,196,255,.35);
    border-radius: 10px; padding: 5px; min-width: 190px;
    box-shadow: 0 12px 40px rgba(0,0,0,.5);
  }
  .pmef-img-menu-item {
    display: block; width: 100%; text-align: left;
    background: transparent; border: 0; color: #e5e7eb; cursor: pointer;
    font: 500 13.5px/1.2 system-ui, -apple-system, sans-serif;
    padding: 9px 12px; border-radius: 7px;
  }
  .pmef-img-menu-item:hover { background: rgba(124,196,255,.16); color: #fff; }

  /* ===== Page-Builder [[section]] widgets — EDITOR-ONLY chrome (never on the live page) ===== */
  .pmef-section { position: relative; outline: 1px dashed rgba(120,170,255,.55); outline-offset: 3px; margin: 14px 0; }
  .pmef-sc-bar { position: absolute; top: -12px; left: 8px; z-index: 3; display: flex; align-items: center; gap: 6px;
    font: 600 10px/1 system-ui, sans-serif; user-select: none; }
  .pmef-sc-tag { background: #1a2030; color: #a5b4fc; padding: 2px 7px; border-radius: 5px;
    border: 1px solid rgba(120,170,255,.4); letter-spacing: .04em; box-shadow: 0 3px 10px rgba(0,0,0,.45); }
  .pmef-sc-gear { background: #1a2030; color: #cbd5e1; border: 1px solid rgba(255,255,255,.15);
    border-radius: 5px; cursor: pointer; padding: 1px 6px; font-size: 12px; line-height: 1; }
  .pmef-sc-gear:hover { color: #fff; border-color: rgba(120,170,255,.5); }
  .pmef-sc-tools { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 6px 10px;
    background: #12151d; border: 1px solid rgba(255,255,255,.14); border-radius: 6px; padding: 3px 9px; color: #cbd5e1; }
  .pmef-sc-tools label { display: inline-flex; align-items: center; gap: 4px; }
  .pmef-sc-tools select, .pmef-sc-tools input[type=text] { font: 11px system-ui; background: rgba(0,0,0,.3);
    color: #e2e8f0; border: 1px solid rgba(255,255,255,.15); border-radius: 4px; padding: 1px 5px; }
  .pmef-sc-tools input[type=range] { vertical-align: middle; width: 72px; }
  .pmef-sc-tools input[type=color] { width: 22px; height: 20px; padding: 0; border: 1px solid rgba(255,255,255,.2);
    border-radius: 4px; background: none; cursor: pointer; vertical-align: middle; }
  .pmef-t-opv { font-variant-numeric: tabular-nums; min-width: 30px; color: #a5b4fc; }
  /* Shadow shaping sub-group — appears when Shadow is enabled. */
  .pmef-sh-group { display: inline-flex; align-items: center; gap: 6px 9px; padding-left: 8px; margin-left: 2px;
    border-left: 1px solid rgba(255,255,255,.14); }
  .pmef-sh-group input[type=range] { width: 60px; }
  .pmef-sh-group .pmef-t-shov, .pmef-sh-group .pmef-t-shhv, .pmef-sh-group .pmef-t-shav {
    font-variant-numeric: tabular-nums; min-width: 26px; color: #a5b4fc; }
  .pmef-sc-inner { min-height: 1em; }

  /* ===== Shortcode chips — locked, editor-only. Every non-section [[…]] shows as an object. ===== */
  .pmef-chip { display: inline-block; vertical-align: middle; max-width: 100%; margin: 2px 2px; border: 1px solid rgba(120,170,255,.45);
    border-radius: 8px; background: rgba(20,26,40,.72); box-shadow: 0 2px 8px rgba(0,0,0,.35); font: 500 12px/1.2 system-ui,-apple-system,sans-serif; color: #cbd5e1; }
  .pmef-chip.pmef-chip-open { display: block; }
  .pmef-chip-h { display: flex; align-items: center; gap: 8px; padding: 5px 8px; }
  .pmef-chip-ico { color: #7cc4ff; font-size: 13px; line-height: 1; flex-shrink: 0; }
  .pmef-chip-name { font-weight: 600; color: #e6eef6; white-space: nowrap; }
  .pmef-chip-sub { color: #7cc4ff; font-weight: 600; }
  .pmef-chip-meta { color: #8a93a6; font: 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 300px; }
  .pmef-chip-btns { display: inline-flex; gap: 1px; margin-left: auto; padding-left: 6px; flex-shrink: 0; }
  .pmef-chip-btns button { background: transparent; border: 0; color: #8a93a6; cursor: pointer; font-size: 12px; padding: 3px 6px; border-radius: 5px; line-height: 1; }
  .pmef-chip-btns button:hover { color: #fff; background: rgba(124,196,255,.20); }
  .pmef-chip-render { display: block; padding: 10px; border-top: 1px dashed rgba(120,170,255,.3); }
  .pmef-chip-render[hidden] { display: none; }
  .pmef-chip-render img, .pmef-chip-render video { max-width: 100%; height: auto; }
  .pmef-chip-loading, .pmef-chip-empty { color: #7b8fa3; font: italic 11px/1.4 system-ui; }
</style>
</head>
<body data-pm-edit-slug="<?= $h($slug) ?>" data-pm-edit-col="<?= $h($col) ?>" data-pm-edit-type="<?= $h($type) ?>">

<div class="pmef-bg-switch" id="pmef-bg-switch" title="Editor background — does not affect the live page">
  <button type="button" data-bg="dark">Dark</button>
  <button type="button" data-bg="white">White</button>
  <button type="button" data-bg="through">Site</button>
</div>

<?php if ($col === 'css'): ?>
<div id="pmef-zone" class="pmef-zone" data-col="css" contenteditable="plaintext-only" spellcheck="false"><?= $h($content) ?></div>
<?php else: ?>
<div id="pmef-zone" class="pmef-zone" data-col="<?= $h($col) ?>" contenteditable="true" spellcheck="true"><?= $content ?></div>
<?php endif; ?>

<script>
/* Editor background switcher — persists per-browser; editor-only, never saved to the page. */
(function(){
  var KEY = 'pmef_bg_mode', body = document.body, sw = document.getElementById('pmef-bg-switch');
  function apply(mode){
    if (['dark','white','through'].indexOf(mode) === -1) mode = 'dark';
    body.classList.remove('pmef-bg-dark','pmef-bg-white','pmef-bg-through');
    body.classList.add('pmef-bg-' + mode);
    if (sw) sw.querySelectorAll('button').forEach(function(b){ b.classList.toggle('active', b.dataset.bg === mode); });
    try { localStorage.setItem(KEY, mode); } catch(_){}
  }
  var saved = 'dark';
  try { saved = localStorage.getItem(KEY) || 'dark'; } catch(_){}
  apply(saved);
  if (sw) sw.addEventListener('click', function(e){
    var b = e.target.closest('button[data-bg]');
    if (b) apply(b.dataset.bg);
  });
})();
</script>
<script src="/admin/modules/PageManager/js/pm-edit-bridge.js?v=<?= (int)@filemtime(__DIR__ . '/js/pm-edit-bridge.js') ?>"></script>
</body>
</html>
