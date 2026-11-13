<?php
/**
 * Page Renderer — wrapper + columns + shortcodes
 * @file    /includes/renderers/page_renderer.php
 * @version 2025.09.08.r2
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
if (!defined('DATA_DIR'))  define('DATA_DIR',  SITE_ROOT . '/admin/data');

/* Includes (explicit; no “candidates”) */
//@include_once SITE_ROOT . '/includes/shortcodes_compat_2025-09-22.php';
@include_once SITE_ROOT . '/includes/shortcodes.php';
@include_once SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/image_gallery.renderer.php';
@include_once SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/video_gallery.renderer.php';
@include_once SITE_ROOT . '/admin/modules/GalleryManager/includes/renderers/pdf_gallery.renderer.php';

/* Toaster */
if (!function_exists('pr_toast')) {
  function pr_toast($lvl,$msg){
    $lvl = preg_replace('/[^a-z]/i','',strtolower((string)$lvl)) ?: 'info';
    $msg = htmlspecialchars((string)$msg, ENT_QUOTES, 'UTF-8');
    echo '<script>(function(){try{if(window.RIToaster&&RIToaster.'.$lvl.'){RIToaster.'.$lvl.'("'.$msg.'");}else{console.'
      .($lvl==='error'?'error':($lvl==='warn'?'warn':'log')).'("'.$msg.'");}}catch(e){}})();</script>';
  }
}

/**
 * Expect $slug from page.php; $pageData already loaded there is welcome too.
 * Return true on output, false for “nothing rendered”.
 */
if (!isset($slug)) { $slug = ''; }
$slug = preg_replace('/[^a-z0-9_\-]/i','',(string)$slug);

pr_toast('info', "page_renderer: slug={$slug}");

/* Load JSON if not provided */
if (!isset($pageData) || !is_array($pageData)) {
  $json = DATA_DIR . '/pages/' . $slug . '/' . $slug . '.json';
  $exists = is_file($json);
  pr_toast('info', "page_renderer: json={$json} (exists=" . ($exists?'yes':'no') . ")");
  if ($exists) {
    $raw = @file_get_contents($json);
    $pageData = json_decode((string)$raw, true);
  } else {
    $pageData = [];
  }
}

if (!$pageData || !is_array($pageData)) {
  pr_toast('warn', 'page_renderer: pageData empty');
  return false;
}

/* Extract common fields */
$useWrapper   = !empty($pageData['use_wrapper']);
$rightEnabled = !empty($pageData['right_column_enabled']);
$leftWidth    = isset($pageData['left_width'])  ? (int)$pageData['left_width']  : 70;
$rightWidth   = isset($pageData['right_width']) ? (int)$pageData['right_width'] : 30;

$components   = isset($pageData['components']) && is_array($pageData['components']) ? $pageData['components'] : [];
pr_toast('info', "page_renderer: scanning 'components' (count=" . count($components) . ")");

/* Collect left/right raw HTML (from Page Manager “main_content”/“right_column”) */
$leftHtml  = (string)($components['main_content']['content']  ?? '');
$rightHtml = (string)($components['right_column']['content'] ?? '');

pr_toast('debug', 'page_renderer: components[main_content] content len=' . strlen($leftHtml));
pr_toast('debug', 'page_renderer: components[right_column] content len=' . strlen($rightHtml));

/* ── Content injection: two orthogonal right-column providers (UCS + Affiliate) ──
 * Resolve article-ness once (explicit flag → legacy heuristic → ArticlesManager index),
 * then prepend the enabled providers ABOVE the page's own stored right-column content.
 * Injected providers dominate the top; manual placements are preserved below. */
@include_once SITE_ROOT . '/includes/content_injection.inc.php';
$_isArticlePage = (
    (($pageData['page_type'] ?? '') === 'article')
    || (empty($pageData['page_type']) && (!empty($pageData['eyebrow']) || !empty($pageData['pub_date']) || !empty($pageData['author'])))
    || (function_exists('ci_is_article_slug') && ci_is_article_slug((string)$slug))
);
if (function_exists('ci_resolve')) {
    $_ciFlags = ci_resolve($pageData, $_isArticlePage);
    if (!empty($_ciFlags['affiliate'])) {
        // De-dupe: drop any old auto-generated affiliate shortcodes already stored in the column.
        $rightHtml = ci_strip_affiliate_shortcodes($rightHtml);
    }
    $_ciHtml = ci_build_html($_ciFlags);
    if ($_ciHtml !== '') {
        $rightHtml = $_ciHtml . $rightHtml;
        if (!$rightEnabled) { $rightEnabled = true; $leftWidth = 68; $rightWidth = 32; }
        pr_toast('info', 'page_renderer: content-injection ucs=' . ((int)!empty($_ciFlags['ucs'])) . ' affiliate=' . ((int)!empty($_ciFlags['affiliate'])));
    }
}

/* Build the page body */

/* Inline grid — respect configured column widths. Mobile stacks to single column. */
if ($rightEnabled) {
  $styleGrid = 'display:grid;grid-template-columns:' . $leftWidth . 'fr ' . $rightWidth . 'fr;gap:18px;align-items:start;';
} else {
  $styleGrid = 'display:grid;grid-template-columns:100%;gap:18px;align-items:start;';
}

/* Assemble inner (before shortcodes) */
$inner  = '';
if ($useWrapper) {
  // Your requested wrapper fragment
  $inner .= '<main id="main-content" class="main-content-container">';
  $inner .= '  <div class="container">';
  $inner .= '    <div class="home-layout-grid" id="home">';
}

/* ── Article share rack: append to left column if page is an article ──
 * Now driven by an explicit page_type === 'article' flag in page JSON
 * (set via Page Manager). The old "match any class containing article"
 * heuristic was flagging non-articles as articles — replaced.
 * Legacy guard: respect eyebrow/pub_date/author if page_type is unset so
 * pages that pre-date the flag still render a share rack. */
/* $_isArticlePage already resolved above (page_type → heuristic → ArticlesManager index). */
if ($_isArticlePage) {
    $_amRenderers = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 5))
        . '/admin/modules/ArticlesManager/lib/renderers.php';
    if (is_file($_amRenderers)) {
        require_once $_amRenderers;
        if (function_exists('am_render_share_rack')) {
            // Build a minimal article array from page data
            $_shareArticle = [
                'slug'       => $slug ?? ($pageData['slug'] ?? ''),
                'title'      => $pageData['page_title'] ?? $pageData['title'] ?? '',
                'excerpt'    => $pageData['description'] ?? $pageData['meta_description'] ?? '',
                'hero_image' => $pageData['og_image'] ?? $pageData['featured_image'] ?? '',
            ];
            $leftHtml .= am_render_share_rack($_shareArticle);
            pr_toast('info', 'page_renderer: article share rack appended');
        }
    }
}

// Luminal base text styles (structural typography) — ON by default; opt out per page with
// lum_text_off. Widgets keep their own styling (higher-specificity classes); the reading
// SURFACE is the separate text_style layer.
$lumClass = empty($pageData['lum_text_off']) ? ' lum-text' : '';
$inner .= '<div class="pm-grid" style="'.$styleGrid.'">';
$inner .= '  <div class="pm-col pm-left'.$lumClass.'" data-col="left" style="min-width:0;overflow:hidden;">'.$leftHtml.'</div>';
if ($rightEnabled) {
  $inner .= '  <div class="pm-col pm-right'.$lumClass.'" data-col="right" style="min-width:0;overflow:hidden;">'.$rightHtml.'</div>';
}
$inner .= '</div>';

if ($useWrapper) {
  $inner .= '    </div><!-- home-layout-grid #home -->';
  $inner .= '  </div><!-- container -->';
  $inner .= '</main>';
}

pr_toast('info', 'page_renderer: built from components length=' . strlen($inner));

/* Expand shortcodes in the assembled HTML */
if (function_exists('apply_shortcodes')) {
  $inLen = strlen($inner);
  $inner = apply_shortcodes($inner);
  pr_toast('info', 'page_renderer: shortcodes applied (in=' . $inLen . ', out=' . strlen($inner) . ')');
} else {
  pr_toast('warn', 'page_renderer: apply_shortcodes() missing — shortcodes will show literally');
}

/* Emit */
echo $inner;
return true;
?>