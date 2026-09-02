<?php
/**
 * Frontend Page Router / Renderer
 * @file      /page.php
 * @version   2025.09.07.r3-no-legacy-panels + toaster-flag
 *
 * - Honors SITE_SETTINGS.debug_toaster and ?debug=1/0 to show/hide the top-of-page toaster.
 */

declare(strict_types=1);

// Start the session BEFORE any output so Set-Cookie: PHPSESSID reaches the
// browser. mystore-store.php (and any other renderer that reads $_SESSION)
// was calling session_start() mid-render — after HTML had already been
// flushed — so the session cookie never reached the browser. Every POST
// back to the storefront API was a fresh session with a new CSRF token,
// every checkout failed with "Invalid request — please refresh the page".
// Starting here fixes the whole family of session-dependent flows.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}

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

/* ---------- Live docs mirror ----------
   Docs are authored in the admin at admin/data/docs/{slug}/{slug}.json and use
   the IDENTICAL schema to a PageManager page. So when a slug is not a page, fall
   back to the docs tree and render it through this very pipeline — per-doc CSS
   and JS included, because both derive from $PAGE_DIR.

   This is a MIRROR, not a copy. Nothing is duplicated, so what is published can
   never drift from what the admin shows, and doc updates arriving with a release
   are live without anyone maintaining a second set.
   $requested is pg_slug()'d to [a-z0-9_-], so no traversal is possible. */
if (!is_file($PAGE_JSON)) {
    $DOCS_DIR  = SITE_ROOT . '/admin/data/docs/' . $requested;
    $DOCS_JSON = $DOCS_DIR . '/' . $requested . '.json';
    if (is_file($DOCS_JSON)) {
        $PAGE_DIR  = $DOCS_DIR;
        $PAGE_JSON = $DOCS_JSON;
        $IS_DOC_PAGE = true;
    }
}

/* ---------- Product-specific OG tags (for social sharing) ----------
   Goal: a shared product link shows the PRODUCT (name + image + price CTA) on
   Facebook/etc. so people click back to buy — not a generic store banner.
   Sets PAGE_OG_IS_PRODUCT so the page-title/description logic below doesn't clobber it. */
if (!empty($_GET['product'])) {
    $productId = (string)$_GET['product'];
    $_ogSiteName = (string)($GLOBALS['siteTitle'] ?? ($GLOBALS['artistName'] ?? ($_SERVER['HTTP_HOST'] ?? '')));
    // Check Printful products
    $pfProductsFile = SITE_ROOT . '/admin/data/printful/products.json';
    if (is_file($pfProductsFile)) {
        $pfProducts = json_decode(file_get_contents($pfProductsFile), true) ?: [];
        $pfPrices   = @json_decode((string)@file_get_contents(SITE_ROOT . '/admin/data/printful/price-cache.json'), true) ?: [];
        foreach ($pfProducts as $pfp) {
            if ((string)($pfp['id'] ?? '') === $productId) {
                if (!empty($pfp['thumbnail_url'])) $GLOBALS['PAGE_OG_IMAGE'] = $pfp['thumbnail_url'];
                if (!empty($pfp['name'])) {
                    $GLOBALS['PAGE_OG_TITLE'] = $pfp['name'];
                    $_from = $pfPrices[$pfp['id']] ?? ($pfp['from_price'] ?? null);
                    $GLOBALS['PAGE_OG_DESCRIPTION'] = ($_from ? 'From $' . $_from . ' — ' : '')
                        . 'Shop ' . $pfp['name'] . ' at ' . $_ogSiteName . '. Tap to pick colors & sizes and order — shipped worldwide.';
                }
                $GLOBALS['PAGE_OG_IS_PRODUCT'] = true;
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
                    if (!empty($mp['name'])) {
                        $GLOBALS['PAGE_OG_TITLE'] = $mp['name'];
                        $_msPrice = $mp['price'] ?? null;
                        $GLOBALS['PAGE_OG_DESCRIPTION'] = ($_msPrice ? '$' . $_msPrice . ' — ' : '')
                            . 'Shop ' . $mp['name'] . ' at ' . $_ogSiteName . '. Tap to order.';
                    }
                    $GLOBALS['PAGE_OG_IS_PRODUCT'] = true;
                    break;
                }
            }
        }
    }
}

/* ---------- Shortcodes + renderers ---------- */
@include_once SITE_ROOT . '/includes/shortcodes.php';

/* ---------- Head/Body/Footer partials (exact locations) ---------- */
$HEAD_ASSETS = SITE_ROOT . '/header/document_head_assets.php';
$BODY_HEADER = SITE_ROOT . '/header/document_header_body.php';
$FOOTER_FILE = SITE_ROOT . '/footer.php'; // NOTE: your footer lives at SITE-ROOT/footer.php

/* ---------- Load page data ---------- */
$PAGE_DATA = pg_json($PAGE_JSON);

/* ---------- Docs reading layout ----------
   Docs read better as a sidebar of links beside the page than as a wall of
   cards. The doc files are the MIRROR of the admin documentation, so the layout
   is applied HERE at render time rather than written into them — a doc that
   ships with a release must stay byte-identical to what the admin shows.

   main_content is the left column and right_column the right, so the nav goes
   in main_content and the doc's own content moves across. */
if (!empty($IS_DOC_PAGE) && is_array($PAGE_DATA)) {
    $__docBody = $PAGE_DATA['components']['main_content']['content'] ?? '';
    $PAGE_DATA['right_column_enabled'] = true;
    $PAGE_DATA['left_width']  = 23;
    $PAGE_DATA['right_width'] = 77;
    $PAGE_DATA['use_wrapper'] = false;
    $PAGE_DATA['components']['main_content']['content'] = '[[docs-nav]]';
    $PAGE_DATA['components']['right_column']['content'] =
        '<div class="lm-doc-pane">' . $__docBody . '</div>';
    unset($__docBody);
}

/* Title */
$artistName = isset($GLOBALS['artistName']) ? (string)$GLOBALS['artistName'] : ($_SERVER['HTTP_HOST'] ?? 'Site');
$siteTitle  = isset($GLOBALS['siteTitle'])  ? (string)$GLOBALS['siteTitle']  : $artistName;
$isHomePage = ($requested === 'home' || $requested === ($homeSlug ?? 'home'));
$pageTitle  = isset($PAGE_DATA['title']) && $PAGE_DATA['title'] !== '' ? (string)$PAGE_DATA['title'] : $requested;
// Home page: just site name. Other pages: "Page Title — Site Name"
$fullTitle  = $isHomePage ? $siteTitle : ($pageTitle ? ($pageTitle . ' — ' . $siteTitle) : $siteTitle);

/* OG Image Priority:
   1. Bare domain (home page) → Site's og image (handled by document_head_assets.php default)
   2. Page with specific og_image field → Use that
   3. Page with no og_image → Extract first image from content
   4. No images found → Fall back to site's og image
*/
// Preserve product-specific OG if already set (from ?product= lookup above)
if (empty($GLOBALS['PAGE_OG_IMAGE'])) $GLOBALS['PAGE_OG_IMAGE'] = null;
if (empty($GLOBALS['PAGE_OG_DESCRIPTION'])) $GLOBALS['PAGE_OG_DESCRIPTION'] = null;

if (!$isHomePage && !empty($PAGE_DATA) && empty($GLOBALS['PAGE_OG_IS_PRODUCT'])) {
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

// Set page title for OG — but keep the product name when sharing a ?product= link.
if (empty($GLOBALS['PAGE_OG_IS_PRODUCT'])) {
    $GLOBALS['PAGE_OG_TITLE'] = $fullTitle;
}

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
    // OG meta + favicons + stylesheets — single source of truth in document_head_assets.php
    // Also checks media/images/site/{domain}.og_image.png as legacy fallback
    $_ogLegacyPath = SITE_ROOT . '/media/images/site/' . ($_SERVER['HTTP_HOST'] ?? '') . '.og_image.png';
    if (empty($GLOBALS['PAGE_OG_IMAGE']) && is_file($_ogLegacyPath)) {
        $GLOBALS['PAGE_OG_IMAGE'] = '/media/images/site/' . ($_SERVER['HTTP_HOST'] ?? '') . '.og_image.png';
    }
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
<?php
  /* Per-page width/padding overrides → scoped CSS vars on this page's wrapper.
     Wins over the site-level --page-max-width (inline style > stylesheet). Empty = inherit. */
  $pwStyle = '';
  $pmw = $PAGE_DATA['page_max_width'] ?? '';
  if ($pmw === 'fluid' || $pmw === 'full') { $pwStyle .= '--page-max-width:min(1800px,96vw);'; }
  elseif (is_numeric($pmw)) { $pwStyle .= '--page-max-width:' . (int)$pmw . 'px;'; }
  $ppd = $PAGE_DATA['page_pad'] ?? '';
  if ($ppd !== '' && is_numeric($ppd)) { $pwStyle .= '--page-pad:' . (int)$ppd . 'px;'; }
?>
<main id="content" class="content-wrapper"<?php echo $pwStyle !== '' ? ' style="' . htmlspecialchars($pwStyle, ENT_QUOTES) . '"' : ''; ?>>

  <div class="content-inner">
    <?php
      $rendered = false;

      // 1) Let the page renderer try first.
      $PAGE_RENDERER = SITE_ROOT . '/admin/modules/Base/includes/renderers/page_renderer.php';
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