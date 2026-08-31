<?php
/**
 * Header Core — central config loader (GUARDRAILED)
 * @file    /header/header_core.php
 * @version 2025.09.09.r10-guardrails
 *
 * CONTRACT (do not “optimize” away):
 *  1) DATA ONLY + CSS <style> VARS. No DOM markup besides the <style> block.
 *  2) Hydrate $GLOBALS: $SITE_SETTINGS, $MENU_ITEMS, $MENU_SETTINGS, $BACKGROUND,
 *     $artistName, $siteTitle, $homeSlug
 *  3) Accept menu JSON in shapes: {items:[]}, {menu:[]}, [] (array root)
 *  4) Emit CSS vars for header/body/menu colors & fonts (historical behavior)
 *  5) Optional TRACE via ?trace=1 → /admin/data/.logs/header_core.trace.json
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

/* Module renderer autoloader — pulls in every installed module's renderers
 * (galleries, stacks, events, affiliate, trading, etc.). Runs from the
 * universal front-end bootstrap so every page type (page.php, article.php,
 * verticals) has renderer functions available regardless of include order.
 * Idempotent; see includes/module-renderers.php. */
require_once SITE_ROOT . '/includes/module-renderers.php';

/* ─────────────────────────── GUARDRAILS ─────────────────────────── */
/* To future “optimizers”: This file is the cross-module contract.
   Trimming any section below will break menus, fonts, background,
   or CSS variables across the site. Change only by adding keys or
   extending behavior. */

/* ---------------- Trace toggle ---------------- */
$TRACE_ENABLED = (isset($_GET['trace']) && ($_GET['trace']==='1' || $_GET['trace']==='true'));
$TRACE = [
  'when'       => gmdate('c'),
  'request'    => [ 'uri' => $_SERVER['REQUEST_URI'] ?? '', 'query' => $_GET ],
  'paths'      => [],
  'loaded'     => [],
  'brand'      => [],
  'menu'       => [],
  'background' => [],
];

/* ---------------- Canonical data paths ---------------- */
$DATA_DIR           = SITE_ROOT . '/admin/data';
$SITE_FILE          = $DATA_DIR . '/site-settings.json';
$MENU_ITEMS_FILE    = $DATA_DIR . '/menu/menu_items.json';
$MENU_SETTINGS_FILE = $DATA_DIR . '/menu/menu_settings.json';

$BG_FILES = [
  $DATA_DIR . '/background_settings.json',
  $DATA_DIR . '/background_images.json',
  $DATA_DIR . '/background_slide_shows.json',
];

$TRACE['paths'] = [
  'SITE_ROOT'          => SITE_ROOT,
  'DATA_DIR'           => $DATA_DIR,
  'site-settings.json' => $SITE_FILE,
  'menu_items.json'    => $MENU_ITEMS_FILE,
  'menu_settings.json' => $MENU_SETTINGS_FILE,
  'bg_candidates'      => $BG_FILES,
];

/* ---------------- Helpers (do not remove) ---------------- */
if (!function_exists('hc_safe_json')) {
  function hc_safe_json(string $path): array {
    clearstatcache(true, $path);
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $j = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($j)) ? $j : [];
  }
}
if (!function_exists('hc_get')) {
  function hc_get(string $k, array $a, $default=null) { $v = $a[$k] ?? null; return ($v !== null && trim((string)$v) !== '') ? $v : $default; }
}
if (!function_exists('hc_h')) {
  function hc_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('hc_public_url_with_bust')) {
  function hc_public_url_with_bust(string $maybeUrl): array {
    $u = trim($maybeUrl);
    if ($u === '') return ['', false];
    if (preg_match('~^https?://~i', $u)) return [$u, true];
    if ($u[0] !== '/') $u = '/' . $u;
    $abs = SITE_ROOT . $u;
    if (is_file($abs)) { $v = @filemtime($abs) ?: time(); return [$u.'?v='.$v, true]; }
    return [$u, false];
  }
}
if (!function_exists('hc_rgba')) {
  function hc_rgba(string $hex, float $alpha): string {
    $hex = ltrim($hex, '#'); if (strlen($hex)===3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec(substr($hex,0,2)); $g = hexdec(substr($hex,2,2)); $b = hexdec(substr($hex,4,2));
    return "rgba($r,$g,$b," . max(0,min(1,$alpha)) . ")";
  }
}
if (!function_exists('hc_toast')) {
  function hc_toast(string $lvl, string $msg): void {
    $lvl = preg_replace('/[^a-z]/i','',strtolower($lvl)) ?: 'info';
    $safe = hc_h($msg);
    echo '<script>(function(){try{(window.RIToaster&&RIToaster['.$lvl.']?RIToaster['.$lvl.']:console.log)("'.$safe.'");}catch(_){}})();</script>';
  }
}

/* ---------------- Load JSONs ---------------- */
$SITE_SETTINGS      = hc_safe_json($SITE_FILE);
$MENU_ITEMS_WRAPPER = hc_safe_json($MENU_ITEMS_FILE);
$MENU_SETTINGS      = hc_safe_json($MENU_SETTINGS_FILE);

$TRACE['loaded']['site-settings.json'] = ['present'=>(bool)$SITE_SETTINGS,'keys'=>array_keys($SITE_SETTINGS ?: [])];
$TRACE['loaded']['menu_items.json']    = ['present'=>(bool)$MENU_ITEMS_WRAPPER,'keys'=>array_keys($MENU_ITEMS_WRAPPER ?: [])];
$TRACE['loaded']['menu_settings.json'] = ['present'=>(bool)$MENU_SETTINGS,'keys'=>array_keys($MENU_SETTINGS ?: [])];

/* ---------------- Brand globals ---------------- */
$artistName = (string) hc_get('site_name',  $SITE_SETTINGS, ($_SERVER['HTTP_HOST'] ?? 'Site'));
$siteTitle  = (string) hc_get('site_title', $SITE_SETTINGS, $artistName);
$homeSlug   = (string) (hc_get('home_page_slug', $SITE_SETTINGS, '') ?: hc_get('home', $SITE_SETTINGS, 'home'));

$TRACE['brand'] = [
  'artistName' => $artistName,
  'siteTitle'  => $siteTitle,
  'homeSlug'   => $homeSlug,
];

/* ---------------- Menu items (accept multiple shapes) ---------------- */
$rawItems = [];
if (isset($MENU_ITEMS_WRAPPER['items']) && is_array($MENU_ITEMS_WRAPPER['items'])) {
  $rawItems = $MENU_ITEMS_WRAPPER['items'];
} elseif (isset($MENU_ITEMS_WRAPPER['menu']) && is_array($MENU_ITEMS_WRAPPER['menu'])) {
  $rawItems = $MENU_ITEMS_WRAPPER['menu'];
} elseif (is_array($MENU_ITEMS_WRAPPER)) {
  $rawItems = $MENU_ITEMS_WRAPPER;
}

$requestedSlug = isset($_GET['p']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string)$_GET['p']) : $homeSlug;

$MENU_ITEMS = [];
foreach ($rawItems as $it) {
  if (!is_array($it)) continue;
  $title = trim((string)($it['title'] ?? $it['label'] ?? $it['text'] ?? ''));
  $slug  = trim((string)($it['slug']  ?? ''));
  $url   = trim((string)($it['url']   ?? ''));
  if ($url === '' && $slug !== '') $url = '/page.php?p=' . rawurlencode($slug);
  if ($title === '' && $url !== '') $title = $url;
  if ($title === '' || $url === '') continue;

  $MENU_ITEMS[] = [
    'title'  => $title,
    'slug'   => $slug,
    'url'    => $url,
    'active' => ($slug !== '' ? $slug === $requestedSlug : false),
  ];
}
$TRACE['menu']['items_count'] = count($MENU_ITEMS);

/* ---------------- Background config (robust) ---------------- */
$BACKGROUND = [
  'image'           => '',
  'image_public'    => '',
  'color'           => '',
  'repeat'          => '',
  'size'            => '',
  'position'        => '',
  'attachment'      => '',
  'overlay_color'   => '',
  'overlay_opacity' => null,
];

foreach (['site_background_image','background_image','background_url','bg_image'] as $k) {
  if (!empty($SITE_SETTINGS[$k])) { $BACKGROUND['image'] = (string)$SITE_SETTINGS[$k]; break; }
}
foreach (['site_background_color','background_color','bg_color'] as $k) {
  if (!empty($SITE_SETTINGS[$k])) { $BACKGROUND['color'] = (string)$SITE_SETTINGS[$k]; break; }
}
$BACKGROUND['repeat']     = (string) hc_get('background_repeat',     $SITE_SETTINGS, '');
$BACKGROUND['size']       = (string) hc_get('background_size',       $SITE_SETTINGS, '');
$BACKGROUND['position']   = (string) hc_get('background_position',   $SITE_SETTINGS, '');
$BACKGROUND['attachment'] = (string) hc_get('background_attachment', $SITE_SETTINGS, '');
$BACKGROUND['overlay_color'] = (string) hc_get('background_overlay_color',$SITE_SETTINGS,'');
$ov = hc_get('background_overlay_opacity',$SITE_SETTINGS,null);
$BACKGROUND['overlay_opacity'] = is_null($ov) ? null : (float)$ov;

/* Fallback from background_*.json if still empty */
$BG_SOURCE = null;
foreach ($BG_FILES as $cand) {
  $j = hc_safe_json($cand);
  if ($j) { $BG_SOURCE = $cand;
    foreach (['image','image_url','url','src','background_image','bg_image'] as $k) if ($BACKGROUND['image']==='' && !empty($j[$k])) $BACKGROUND['image']=(string)$j[$k];
    foreach (['color','background_color','bg_color'] as $k) if ($BACKGROUND['color']==='' && !empty($j[$k])) $BACKGROUND['color']=(string)$j[$k];
    foreach (['repeat','background_repeat','bg_repeat'] as $k) if ($BACKGROUND['repeat']==='' && !empty($j[$k])) $BACKGROUND['repeat']=(string)$j[$k];
    foreach (['size','background_size','bg_size'] as $k) if ($BACKGROUND['size']==='' && !empty($j[$k])) $BACKGROUND['size']=(string)$j[$k];
    foreach (['position','background_position','bg_position'] as $k) if ($BACKGROUND['position']==='' && !empty($j[$k])) $BACKGROUND['position']=(string)$j[$k];
    foreach (['attachment','background_attachment','bg_attachment'] as $k) if ($BACKGROUND['attachment']==='' && !empty($j[$k])) $BACKGROUND['attachment']=(string)$j[$k];
    foreach (['overlay_color','overlay','bg_overlay_color'] as $k) if ($BACKGROUND['overlay_color']==='' && !empty($j[$k])) $BACKGROUND['overlay_color']=(string)$j[$k];
    foreach (['overlay_opacity','overlayAlpha','bg_overlay_opacity'] as $k) if ($BACKGROUND['overlay_opacity']===null && isset($j[$k])) $BACKGROUND['overlay_opacity']=(float)$j[$k];
    break;
  }
}
if (!empty($BACKGROUND['image'])) {
  [$pub,$ok] = hc_public_url_with_bust($BACKGROUND['image']);
  if ($ok) $BACKGROUND['image_public'] = $pub;
}

/* ---------------- Export globals (do not remove) ---------------- */
$GLOBALS['SITE_SETTINGS'] = $SITE_SETTINGS;
$GLOBALS['MENU_ITEMS']    = $MENU_ITEMS;
$GLOBALS['MENU_SETTINGS'] = $MENU_SETTINGS;
$GLOBALS['BACKGROUND']    = $BACKGROUND;
$GLOBALS['artistName']    = $artistName;
$GLOBALS['siteTitle']     = $siteTitle;
$GLOBALS['homeSlug']      = $homeSlug;

/* ---------------- CSS vars (historical emission) ---------------- */
/* Site & body fonts/colors (from site-settings) */
$headerFontFamily = (string) hc_get('header_font_family', $SITE_SETTINGS, 'Inter');
$headerFontSize   = (string) hc_get('header_font_size',   $SITE_SETTINGS, '2.0');
$headerFontColor  = (string) hc_get('header_font_color',  $SITE_SETTINGS, '#ffffff');

$bodyFontFamily   = (string) hc_get('body_font_family',   $SITE_SETTINGS, 'Inter');
$bodyFontSize     = (string) hc_get('body_font_size',     $SITE_SETTINGS, '1.0');
$bodyFontColor    = (string) hc_get('body_font_color',    $SITE_SETTINGS, '#ffffff');

/* Menu fonts/colors (from menu_settings) */
$menuFontFamily    = (string) hc_get('menu_font_family',      $MENU_SETTINGS, 'Inter');
$menuFontSizeRaw   = hc_get('menu_font_size', $MENU_SETTINGS, 16);
$menuFontSize      = is_numeric($menuFontSizeRaw) && $menuFontSizeRaw > 5 ? ((float)$menuFontSizeRaw / 16) : (float)$menuFontSizeRaw;
$menuFontWeight    = (int)    hc_get('menu_font_weight',      $MENU_SETTINGS, 400);
$menuFontStyle     = (string) hc_get('menu_font_style',       $MENU_SETTINGS, 'normal');
$menuFontColor     = (string) hc_get('menu_font_color',       $MENU_SETTINGS, '#eeeeee');
$menuFontHoverColor= (string) hc_get('menu_font_hover_color', $MENU_SETTINGS, '#ffffff');
$menuDisabled      = (bool)   hc_get('menu_disabled',         $MENU_SETTINGS, false);
$GLOBALS['MENU_DISABLED'] = $menuDisabled;

/* Menu chroma → rgba combos */
$menuBgColor        = (string) hc_get('menu_bg_color',               $MENU_SETTINGS, '#000000');
$menuBgOpacity      = (float)  hc_get('menu_bg_opacity',             $MENU_SETTINGS, 1.0);
$itemBgColor        = (string) hc_get('menu_item_bg_color',          $MENU_SETTINGS, 'transparent');
$itemBgOpacity      = (float)  hc_get('menu_item_bg_opacity',        $MENU_SETTINGS, 0.0);
$itemHoverBgColor   = (string) hc_get('menu_item_hover_bg_color',    $MENU_SETTINGS, '#333333');
$itemHoverBgOpacity = (float)  hc_get('menu_item_hover_bg_opacity',  $MENU_SETTINGS, 0.2);
$currBgColor        = (string) hc_get('menu_current_item_bg_color',  $MENU_SETTINGS, '#ffffff');
$currBgOpacity      = (float)  hc_get('menu_current_item_bg_opacity',$MENU_SETTINGS, 0.10);
$currFontColor      = (string) hc_get('menu_current_item_font_color',$MENU_SETTINGS, '#ffffff');

/* Menu alignment */
$menuAlignment      = (string) hc_get('menu_alignment', $MENU_SETTINGS, 'left');
$alignmentMap       = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
$menuAlignmentCss   = $alignmentMap[$menuAlignment] ?? 'flex-start';

/* Allow page top spacing from site settings (if you use it) */
$topContentGapPx    = (int)    hc_get('main_content_margin_top', $SITE_SETTINGS, 0);

/* ---------------- Custom Font Loading ---------------- */
/* Scan /media/fonts and generate @font-face for fonts used in settings */
$FONTS_DIR = SITE_ROOT . '/media/fonts';
$customFonts = [];

if (is_dir($FONTS_DIR)) {
    $fontExts = ['ttf' => 'truetype', 'otf' => 'opentype', 'woff' => 'woff', 'woff2' => 'woff2'];
    // Collect all fonts that might be referenced
    $fontsToCheck = [$menuFontFamily, $headerFontFamily, $bodyFontFamily];

    foreach (scandir($FONTS_DIR) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!isset($fontExts[$ext])) continue;

        $base = pathinfo($f, PATHINFO_FILENAME);
        $family = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);

        // Check if this font is used in any of our font settings
        foreach ($fontsToCheck as $fontSetting) {
            if (stripos($fontSetting, $family) !== false || stripos($fontSetting, $base) !== false) {
                $customFonts[$family] = [
                    'file' => $f,
                    'family' => $family,
                    'url' => '/media/fonts/' . rawurlencode($f),
                    'format' => $fontExts[$ext]
                ];
                break;
            }
        }
    }
}

?>
<style>
<?php foreach ($customFonts as $font): ?>
@font-face {
    font-family: '<?php echo hc_h($font['family']); ?>';
    src: url('<?php echo hc_h($font['url']); ?>') format('<?php echo $font['format']; ?>');
    font-display: swap;
}
<?php endforeach; ?>

:root{
  --site-header-font-family: <?php echo hc_h($headerFontFamily); ?>, system-ui, Arial;
  --site-header-font-size:   <?php echo hc_h($headerFontSize); ?>rem;
  --site-header-font-color:  <?php echo hc_h($headerFontColor); ?>;

  --site-body-font-family:   <?php echo hc_h($bodyFontFamily); ?>, system-ui, Arial;
  --site-body-font-size:     <?php echo hc_h($bodyFontSize); ?>rem;
  --site-body-font-color:    <?php echo hc_h($bodyFontColor); ?>;

  --menu-font-family:        <?php echo hc_h($menuFontFamily); ?>, system-ui, Arial;
  --menu-font-size:          <?php echo hc_h($menuFontSize); ?>rem;
  --menu-font-weight:        <?php echo (int)$menuFontWeight; ?>;
  --menu-font-style:         <?php echo hc_h($menuFontStyle); ?>;
  --menu-font-color:         <?php echo hc_h($menuFontColor); ?>;
  --menu-font-hover-color:   <?php echo hc_h($menuFontHoverColor); ?>;

  --menu-bar-bg:             <?php echo hc_rgba($menuBgColor,$menuBgOpacity); ?>;
  --menu-item-bg:            <?php echo ($itemBgColor==='transparent'?'rgba(0,0,0,0)':hc_rgba($itemBgColor,$itemBgOpacity)); ?>;
  --menu-item-hover-bg:      <?php echo hc_rgba($itemHoverBgColor,$itemHoverBgOpacity); ?>;
  --menu-current-bg:         <?php echo hc_rgba($currBgColor,$currBgOpacity); ?>;
  --menu-current-font-color: <?php echo hc_h($currFontColor); ?>;
  --menu-alignment:          <?php echo hc_h($menuAlignmentCss); ?>;

  --top-content-gap:         <?php echo (int)$topContentGapPx; ?>px;
<?php
  /* Global page width — one site-level knob for every page + article. Emit only when set,
     so unset sites keep each element's built-in fallback (1760 wrappers / 1200|880 articles).
     Map preset|custom-px → a safe CSS length here (never emit raw user input). */
  $pmwRaw = hc_get('page_max_width', $SITE_SETTINGS, '');
  $pmwCss = null;
  if ($pmwRaw !== '' && $pmwRaw !== null) {
      $pmwPresets = ['standard' => '1200px', 'wide' => '1480px', 'full' => 'min(1800px, 96vw)'];
      if (is_string($pmwRaw) && isset($pmwPresets[$pmwRaw])) { $pmwCss = $pmwPresets[$pmwRaw]; }
      elseif (is_numeric($pmwRaw)) { $n = (int)$pmwRaw; if ($n >= 480 && $n <= 4000) $pmwCss = $n . 'px'; }
  }
  if ($pmwCss !== null): ?>
  --page-max-width:          <?php echo $pmwCss; ?>;
<?php endif; ?>
}
/* Optional helper if main containers honor top gap */
.content-wrapper, .main-content-container { margin-top: var(--top-content-gap); }
</style>
<?php

/* ---------------- Telemetry (visible) ---------------- */
$__mt = (is_file($SITE_FILE) ? (@filemtime($SITE_FILE) ?: 0) : 0);
hc_toast('debug', 'header_core: site-settings mtime='.$__mt
  . ', home='.$homeSlug
  . ', header_font='.$headerFontFamily
  . ', menu_font='.$menuFontFamily
  . ', top_gap='.$topContentGapPx.'px'
);

/* ---------------- TRACE LOG (disk) ---------------- */
if ($TRACE_ENABLED) {
  $TRACE['brand']       = array_merge($TRACE['brand'], ['top_gap'=>$topContentGapPx]);
  $TRACE['menu']['items_preview'] = array_slice($MENU_ITEMS, 0, 6);
  $TRACE['background']  = $BACKGROUND;
  $logDir = $DATA_DIR.'/.logs'; if(!is_dir($logDir)) @mkdir($logDir,0777,true);
  $outPath = $logDir.'/header_core.trace.json';
  @file_put_contents($outPath, json_encode($TRACE, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  @chmod($outPath, 0666);
}

/* ---------------- Canonical gallery constants (compat) ---------------- */
if (!defined('DATA_DIR')) define('DATA_DIR', $DATA_DIR);
if (!defined('PHOTO_GALLERY_FILE')) define('PHOTO_GALLERY_FILE', DATA_DIR . '/photo_gallery.json');
if (!defined('PHOTOS_FILE'))        define('PHOTOS_FILE',        DATA_DIR . '/photos.json');
if (!defined('VIDEO_GALLERY_FILE')) define('VIDEO_GALLERY_FILE', DATA_DIR . '/video_gallery.json');
if (!defined('VIDEOS_FILE'))        define('VIDEOS_FILE',        DATA_DIR . '/videos.json');
if (!defined('PDF_GALLERY_FILE'))   define('PDF_GALLERY_FILE',   DATA_DIR . '/pdf_gallery.json');
if (!defined('PDFS_FILE'))          define('PDFS_FILE',          DATA_DIR . '/pdfs.json');

/* END HEADER CORE — Do not trim beyond this point. */
?>