<?php
/**
 * Frontend Page Router / Renderer
 * @file      /page.php
 * @version   2025.09.07.r3-no-legacy-panels + toaster-flag
 *
 * - Honors SITE_SETTINGS.debug_toaster and ?debug=1/0 to show/hide the top-of-page toaster.
 */

declare(strict_types=1);

// --- DEBUG: show fatals now ---
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);

// breadcrumb to confirm we got this far
echo '<script>console.log("CP-A: page.php after bootstrap & BEFORE DOCTYPE");</script>';

/* ---------- Paths ---------- */
if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', __DIR__);
}

/* ---------- Tiny helpers ---------- */
function pg_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pg_slug($s): string {
  $s = strtolower(trim((string)$s));
  $s = preg_replace('/[^a-z0-9_\-]/', '', $s);
  return $s ?: 'home';
}
function pg_json(string $path): array {
  clearstatcache(true, $path);
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false) return [];
  $j = json_decode($raw, true);
  return (json_last_error() === JSON_ERROR_NONE && is_array($j)) ? $j : [];
}
function pg_toast($lvl, $msg): void {
  $lvl  = preg_replace('/[^a-z]/i', '', strtolower((string)$lvl)) ?: 'info';
  $safe = pg_h($msg);
  echo '<script>(function(){try{'
     . 'if(window.RIToaster&&RIToaster.'.$lvl.'){RIToaster.'.$lvl.'("'.$safe.'");}'
     . 'else if(window.RIToast){RIToast("'.$safe.'","'.$lvl.'");}'
     . 'else{console.'.($lvl==='error'?'error':($lvl==='warn'?'warn':'log')).'("'.$safe.'");}'
     . '}catch(e){}})();</script>';
}

/* ---------- Resolve requested slug (may be overridden by header_core) ---------- */
$requested = isset($_GET['p']) ? pg_slug($_GET['p']) : 'home';

/* ---------- Load header core (DATA ONLY; no DOM) ---------- */
@include_once SITE_ROOT . '/header/header_core.php';

/* After core, honor $homeSlug if no explicit ?p */
if ((!isset($_GET['p']) || $_GET['p'] === '') && !empty($homeSlug)) {
  $requested = pg_slug($homeSlug);
}

/* Export pageSlug for footer.php ServerMonitor pixel */
$pageSlug = $requested;

/* ---------- TOASTER FLAG: from site-settings.json + URL override ---------- */
$__debug_toaster = !empty($GLOBALS['SITE_SETTINGS']['debug_toaster']);
if (isset($_GET['debug'])) {
  $__debug_toaster = ($_GET['debug'] === '1' || $_GET['debug'] === 'true');
}
if (!defined('RI_TOASTER')) {
  define('RI_TOASTER', $__debug_toaster);
}

/* ---------- Page JSON path ---------- */
$PAGE_DIR  = SITE_ROOT . '/admin/data/pages/' . $requested;
$PAGE_JSON = $PAGE_DIR . '/' . $requested . '.json';

/* ---------- Product-specific OG tags (for social sharing) ---------- */
if (!empty($_GET['product'])) {
    $productId = (string)$_GET['product'];
    // Check Printful products
    $pfProductsFile = SITE_ROOT . '/admin/data/printful/products.json';
    if (is_file($pfProductsFile)) {
        $pfProducts = json_decode(file_get_contents($pfProductsFile), true) ?: [];
        foreach ($pfProducts as $pfp) {
            if ((string)($pfp['id'] ?? '') === $productId) {
                if (!empty($pfp['thumbnail_url'])) $GLOBALS['PAGE_OG_IMAGE'] = $pfp['thumbnail_url'];
                if (!empty($pfp['name'])) $GLOBALS['PAGE_OG_TITLE'] = $pfp['name'];
                break;
            }
        }
    }
    // Check MyStore products
    if (empty($GLOBALS['PAGE_OG_IMAGE'])) {
        $msProductsDir = SITE_ROOT . '/admin/data/mystore/products';
        if (is_dir($msProductsDir)) {
            foreach (glob($msProductsDir . '/*.json') as $mpf) {
                $mp = json_decode(file_get_contents($mpf), true);
                if ($mp && ($mp['sku'] ?? '') == $productId && !empty($mp['image'])) {
                    $GLOBALS['PAGE_OG_IMAGE'] = $mp['image'];
                    $GLOBALS['PAGE_OG_TITLE'] = $mp['name'] ?? '';
                    break;
                }
            }
        }
    }
}

/* ---------- Shortcodes + renderers ---------- */
@include_once SITE_ROOT . '/includes/shortcodes.php';
@include_once SITE_ROOT . '/includes/renderers/pdf_gallery.renderer.php';
@include_once SITE_ROOT . '/includes/renderers/image_gallery.renderer.php';
@include_once SITE_ROOT . '/includes/renderers/video_gallery.renderer.php';
@include_once SITE_ROOT . '/includes/renderers/aip_page.renderer.php';

/* ---------- Head/Body/Footer partials (exact locations) ---------- */
$HEAD_ASSETS = SITE_ROOT . '/header/document_head_assets.php';
$BODY_HEADER = SITE_ROOT . '/header/document_header_body.php';
$FOOTER_FILE = SITE_ROOT . '/footer.php'; // NOTE: your footer lives at SITE-ROOT/footer.php

/* ---------- Load page data ---------- */
$PAGE_DATA = pg_json($PAGE_JSON);

/* Title */
$artistName = isset($GLOBALS['artistName']) ? (string)$GLOBALS['artistName'] : ($_SERVER['HTTP_HOST'] ?? 'Site');
$siteTitle  = isset($GLOBALS['siteTitle'])  ? (string)$GLOBALS['siteTitle']  : $artistName;
$pageTitle  = isset($PAGE_DATA['title']) && $PAGE_DATA['title'] !== '' ? (string)$PAGE_DATA['title'] : $requested;
$fullTitle  = $pageTitle ? ($pageTitle . ' — ' . $siteTitle) : $siteTitle;

/* OG Image Priority:
   1. Bare domain (home page) → Site's og image (handled by document_head_assets.php default)
   2. Page with specific og_image field → Use that
   3. Page with no og_image → Extract first image from content
   4. No images found → Fall back to site's og image
*/
// Preserve product-specific OG if already set (from ?product= lookup above)
if (empty($GLOBALS['PAGE_OG_IMAGE'])) $GLOBALS['PAGE_OG_IMAGE'] = null;
if (empty($GLOBALS['PAGE_OG_DESCRIPTION'])) $GLOBALS['PAGE_OG_DESCRIPTION'] = null;
$isHomePage = ($requested === 'home' || $requested === ($homeSlug ?? 'home'));

if (!$isHomePage && !empty($PAGE_DATA)) {
    // Check for explicit og_image in page data
    if (!empty($PAGE_DATA['og_image'])) {
        $GLOBALS['PAGE_OG_IMAGE'] = $PAGE_DATA['og_image'];
    }
    // Check for featured_image field
    elseif (!empty($PAGE_DATA['featured_image'])) {
        $GLOBALS['PAGE_OG_IMAGE'] = $PAGE_DATA['featured_image'];
    }
    // Check for image field
    elseif (!empty($PAGE_DATA['image'])) {
        $GLOBALS['PAGE_OG_IMAGE'] = $PAGE_DATA['image'];
    }
    // Extract first image from content as fallback
    else {
        $contentToScan = $PAGE_DATA['html'] ?? $PAGE_DATA['content'] ?? '';
        if (empty($contentToScan) && !empty($PAGE_DATA['blocks'])) {
            foreach ($PAGE_DATA['blocks'] as $blk) {
                if (is_string($blk)) $contentToScan .= $blk;
                elseif (is_array($blk) && !empty($blk['html'])) $contentToScan .= $blk['html'];
            }
        }
        // Find first <img src="..."> in content
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $contentToScan, $m)) {
            $GLOBALS['PAGE_OG_IMAGE'] = $m[1];
        }
    }

    // Page description for OG
    if (!empty($PAGE_DATA['description'])) {
        $GLOBALS['PAGE_OG_DESCRIPTION'] = $PAGE_DATA['description'];
    } elseif (!empty($PAGE_DATA['meta_description'])) {
        $GLOBALS['PAGE_OG_DESCRIPTION'] = $PAGE_DATA['meta_description'];
    }
}

// Set page title for OG
$GLOBALS['PAGE_OG_TITLE'] = $fullTitle;

/* ---------- Announce routing ---------- */
pg_toast('info', 'page.php: route slug=' . $requested);
pg_toast('debug','page.php: json=' . $PAGE_JSON . ' exists='.(is_file($PAGE_JSON)?'yes':'no'));

/* ---------- Begin HTML ---------- */
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo pg_h($fullTitle); ?></title>
  <?php
    // OG meta tags — site-level defaults from site-settings + per-page overrides
    $_ogTitle = $fullTitle;
    // Pull meta description: site-settings → $cfg → per-page override
    $_ogDesc = (string)(($GLOBALS['SITE_SETTINGS']['meta_description'] ?? '') ?: ($cfg['meta_description'] ?? ''));
    $_ogImage = '';
    $_ogUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    // Check for site OG image
    $_ogCandidates = [
        SITE_ROOT . '/media/images/site/' . ($_SERVER['HTTP_HOST'] ?? '') . '.og_image.png',
        SITE_ROOT . '/admin/data/site-assets/og.' . ($_SERVER['HTTP_HOST'] ?? '') . '.png',
        SITE_ROOT . '/admin/data/site-assets/og.png',
    ];
    foreach ($_ogCandidates as $_ogC) {
        if (is_file($_ogC)) {
            $_ogImage = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . str_replace(SITE_ROOT, '', $_ogC);
            break;
        }
    }
    // Per-page overrides
    if (!empty($pageConfig['meta_description'])) $_ogDesc = $pageConfig['meta_description'];
    if (!empty($GLOBALS['PAGE_OG_DESCRIPTION'])) $_ogDesc = $GLOBALS['PAGE_OG_DESCRIPTION'];
    if (!empty($GLOBALS['PAGE_OG_IMAGE']))        $_ogImage = $GLOBALS['PAGE_OG_IMAGE'];
  ?>
  <?php if ($_ogDesc): ?><meta name="description" content="<?= pg_h($_ogDesc) ?>"><?php endif; ?>
  <meta property="og:title" content="<?= pg_h($_ogTitle) ?>">
  <?php if ($_ogDesc): ?><meta property="og:description" content="<?= pg_h($_ogDesc) ?>"><?php endif; ?>
  <?php if ($_ogImage): ?><meta property="og:image" content="<?= pg_h($_ogImage) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630"><?php endif; ?>
  <meta property="og:url" content="<?= pg_h($_ogUrl) ?>">
  <meta property="og:type" content="website">
  <meta name="twitter:card" content="summary_large_image">
  <?php
    if (is_file($HEAD_ASSETS)) {
      include $HEAD_ASSETS;
    } else {
      pg_toast('warn','page.php: missing /header/document_head_assets.php');
    }

    // Per-page Text Style reading surface — derived from the page's text_style object
    // (preset: base/apple/auria/custom). Emitted BEFORE page-css so custom CSS wins.
    if (!empty($PAGE_DATA['text_style'])) {
      @include_once SITE_ROOT . '/includes/text_style.php';
      if (function_exists('text_style_css')) {
        $tsCss = text_style_css($PAGE_DATA['text_style']);
        if (trim($tsCss) !== '') echo "\n  <style id=\"page-text-style\">\n" . $tsCss . "  </style>\n";
      }
    }

    // Per-page CSS from admin/data/pages/{slug}/{slug}.css
    $PAGE_CSS_FILE = $PAGE_DIR . '/' . $requested . '.css';
    if (is_file($PAGE_CSS_FILE)) {
      $pageCss = @file_get_contents($PAGE_CSS_FILE);
      if ($pageCss !== false && trim($pageCss) !== '') {
        echo "\n  <style id=\"page-css\">\n" . $pageCss . "\n  </style>\n";
      }
    }

    // JSON-LD structured data (Organization, Article, Product)
    @include_once SITE_ROOT . '/header/json_ld.php';
  ?>
</head>
<body class="ri-site">


<?php //include_once __DIR__ . '/includes/page-wrap-controls.inc.php'; ?>


<?php
  // Conditionally mount the toaster debug window at top-of-page
  if (RI_TOASTER) {
    $TOASTER = SITE_ROOT . '/header/header_toaster_messages.php';
    if (is_file($TOASTER)) {
      include $TOASTER;
      pg_toast('info','page.php: toaster mounted (top of page, static)');
    } else {
      pg_toast('warn','page.php: toaster include missing');
    }
  } else {
    pg_toast('debug','page.php: toaster disabled');
  }
?>

<?php
  if (is_file($BODY_HEADER)) {
    include $BODY_HEADER;
    pg_toast('info','page.php: included document_header_body.php');
  } else {
    pg_toast('error','page.php: missing /header/document_header_body.php');
  }
?>
<!-- moved to import into /styles.css  -->
<!-- link rel="stylesheet" href="/css/galleries.css"-->

<main id="content" class="content-wrapper">

  <div class="content-inner">
    <?php
      $rendered = false;

      // 1) Let the page renderer try first.
      $PAGE_RENDERER = SITE_ROOT . '/includes/renderers/page_renderer.php';
      if (is_file($PAGE_RENDERER)) {
        pg_toast('info','page.php: page_renderer start → '.$PAGE_RENDERER.' (slug='.$requested.')');
        $slug     = $requested;
        $pageData = $PAGE_DATA;
        $t0 = microtime(true);
        $result = include $PAGE_RENDERER; // expected to echo and return true on success
        $dt = (int)round((microtime(true)-$t0)*1000);
        if ($result) {
          $rendered = true;
          pg_toast('info','page.php: page_renderer returned OK ('.$dt.'ms)');
        } else {
          pg_toast('warn','page.php: page_renderer returned false ('.$dt.'ms)');
        }
      } else {
        pg_toast('debug','page.php: no page_renderer found (skipping)');
      }

      // 2) JSON-led fallback render
      if (!$rendered) {
        if (!empty($PAGE_DATA)) {
          $htmlOut = '';

          if (!empty($PAGE_DATA['html']) && is_string($PAGE_DATA['html'])) {
            $htmlOut = $PAGE_DATA['html'];
            pg_toast('debug','page.php: using PAGE_DATA.html');
          } elseif (!empty($PAGE_DATA['content']) && is_string($PAGE_DATA['content'])) {
            $htmlOut = $PAGE_DATA['content'];
            pg_toast('debug','page.php: using PAGE_DATA.content');
          } elseif (!empty($PAGE_DATA['blocks']) && is_array($PAGE_DATA['blocks'])) {
            $buf = '';
            foreach ($PAGE_DATA['blocks'] as $blk) {
              if (is_string($blk)) {
                $buf .= $blk;
              } elseif (is_array($blk) && !empty($blk['html'])) {
                $buf .= (string)$blk['html'];
              }
            }
            $htmlOut = $buf;
            pg_toast('debug','page.php: concatenated PAGE_DATA.blocks ('.strlen($buf).' bytes)');
          }

          // Apply shortcodes if available
          if ($htmlOut !== '' && function_exists('apply_shortcodes')) {
            pg_toast('info','page.php: apply_shortcodes begin');
            $t0 = microtime(true);
            try {
              $htmlOut = apply_shortcodes($htmlOut);
              $dt = (int)round((microtime(true)-$t0)*1000);
              pg_toast('info','page.php: apply_shortcodes done ('.$dt.'ms)');
            } catch (Throwable $e) {
              pg_toast('error','page.php: apply_shortcodes exception: '.$e->getMessage());
            }
          }

          if ($htmlOut !== '') {
            echo $htmlOut;
            $rendered = true;
            pg_toast('info','page.php: JSON-led render OK');
          }
        } else {
          pg_toast('warn','page.php: no PAGE_DATA for slug='.$requested);
        }
      }

      // 3) Graceful message if nothing rendered
      if (!$rendered) {
        echo '<div class="notice notice-warn" style="padding:12px;border:1px solid rgba(255,255,255,.15);border-radius:8px;background:rgba(0,0,0,.25)">';
        echo '<strong>Page not found or empty:</strong> ' . pg_h($requested);
        if (!is_file($PAGE_JSON)) {
          echo '<br><small>Expected: /admin/data/pages/' . pg_h($requested) . '/' . pg_h($requested) . '.json</small>';
        }
        echo '</div>';
        pg_toast('warn','page.php: rendered fallback notice (slug='.$requested.')');
      }

      /* NOTE: Legacy panels are intentionally NOT auto-included anymore.
         If needed, embed their content via content stacks / shortcodes in the page JSON. */
      pg_toast('info','page.php: legacy panel-left/right cleared');
    ?>
  </div>
</main>

<?php
  // ── Affiliate Editorial Rack (magazine-style) ──────────────────────────
  // Fires on home page if admin/data/AffiliateProducts/editorial.json exists + enabled
  $_ap_ed_json = SITE_ROOT . '/admin/data/AffiliateProducts/editorial.json';
  if (is_file($_ap_ed_json)) {
    $_ap_ed = json_decode((string)file_get_contents($_ap_ed_json), true) ?: [];
    if (!empty($_ap_ed['enabled'])) {
      $_ap_ed_hook = $_ap_ed['hook'] ?? 'home';
      $homeSlugVal = $homeSlug ?? 'home';
      if ($_ap_ed_hook === 'all' || $requested === $homeSlugVal) {
        @include_once SITE_ROOT . '/panels/affiliate-editorial.php';
        // South Florida editorial engines — SouthFlorida module (south_florida distribution).
        // Silent on sites without the module. Legacy miami-*.php CORE panels retired 2026-07-04.
        if (function_exists('render_sf_articles')) { echo render_sf_articles(); }
        if (function_exists('render_sf_pulse'))    { echo render_sf_pulse(); }
        if (function_exists('render_sf_biz_pulse') && function_exists('sf_active_kinds')
            && in_array('biz', sf_active_kinds(SITE_ROOT), true)) { echo render_sf_biz_pulse(); }
      }
    }
  }

  if (is_file($FOOTER_FILE)) {
    include $FOOTER_FILE;
    pg_toast('info','page.php: included footer.php');
  } else {
    pg_toast('error','page.php: missing /footer.php');
  }

  // Optional site-wide extra footer injection
  if (!empty($SITE_SETTINGS['extra_footer_html']) && is_string($SITE_SETTINGS['extra_footer_html'])) {
    echo $SITE_SETTINGS['extra_footer_html'];
    pg_toast('debug','page.php: extra_footer_html emitted');
  }

  // Per-page JS from admin/data/pages/{slug}/{slug}.js
  $PAGE_JS_FILE = $PAGE_DIR . '/' . $requested . '.js';
  if (is_file($PAGE_JS_FILE)) {
    $pageJs = @file_get_contents($PAGE_JS_FILE);
    if ($pageJs !== false && trim($pageJs) !== '') {
      echo "\n  <script id=\"page-js\">\n" . $pageJs . "\n  </script>\n";
    }
  }
?>
</body>
</html>