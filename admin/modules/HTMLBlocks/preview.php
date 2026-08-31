<?php
/**
 * HTML Blocks — live preview of UNSAVED block content.
 *
 * Auth-gated iframe target. POST {html, css, js} → renders the block the way it will appear
 * on a page: HTML through apply_shortcodes(), CSS scoped to .hb-preview (reusing the block
 * renderer's hb_scope_css/hb_emit_style), JS wrapped in an IIFE bound to the wrapper — all
 * inside the site's real stylesheets (same fidelity idea as shortcode-preview.php / edit_frame.php).
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/auth.php';
requireAuth();
session_write_close();   // don't hold the session lock while renderers run

$html = (string)($_POST['html'] ?? '');
$css  = (string)($_POST['css']  ?? '');
$js   = (string)($_POST['js']   ?? '');

@include_once SITE_ROOT . '/includes/shortcodes.php';
@include_once SITE_ROOT . '/admin/modules/HTMLBlocks/includes/renderers/html_block.renderer.php';

$scope = '.hb-preview';
$body  = '';
$error = '';
try {
    ob_start();
    $rendered = function_exists('apply_shortcodes') ? apply_shortcodes($html) : $html;
    $stray = ob_get_clean();
    if (trim($rendered) === '' && trim((string)$stray) !== '') $rendered = (string)$stray;

    $style = function_exists('hb_emit_style')
        ? hb_emit_style($css, $scope, 'preview')
        : (trim($css) !== '' ? '<style>' . preg_replace('~</style~i', '<\\/style', $css) . '</style>' : '');

    $script = '';
    if (trim($js) !== '') {
        $safeJs = preg_replace('~</script~i', '<\\/script', $js);
        $script = '<script>(function(){var root=document.querySelector(' . json_encode($scope) . ');try{' . $safeJs . '}catch(e){console.error(e);}})();</script>';
    }
    $body = $style . '<div class="hb-preview">' . $rendered . '</div>' . $script;
} catch (Throwable $e) {
    $body = '<div class="hbp-error">Preview error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</div>';
}

$sv = fn($p) => is_file(SITE_ROOT . $p) ? '?v=' . (int)@filemtime(SITE_ROOT . $p) : '';
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HTML Block Preview</title>
<base target="_blank">
<?php if (is_file(SITE_ROOT . '/styles.css')): ?><link rel="stylesheet" href="/styles.css<?= $sv('/styles.css') ?>"><?php endif; ?>
<?php if (is_file(SITE_ROOT . '/css/lum-text.css')): ?><link rel="stylesheet" href="/css/lum-text.css<?= $sv('/css/lum-text.css') ?>"><?php endif; ?>
<?php if (is_file(SITE_ROOT . '/css/custom-fonts.css')): ?><link rel="stylesheet" href="/css/custom-fonts.css<?= $sv('/css/custom-fonts.css') ?>"><?php endif; ?>
<style>
  /* Preview scaffold — transparent body over a dark scrim (mirrors shortcode-preview.php). */
  html, body { background: transparent !important; margin: 0; padding: 0; }
  body { padding: 18px; color: #e5e7eb; }
  body::before { content:""; position:fixed; inset:0; z-index:-1;
    background: linear-gradient(180deg, rgba(15,18,25,.94), rgba(9,11,16,.96)); }
  .hb-preview { max-width: 100%; }
  .hbp-error { font:13px/1.5 system-ui,sans-serif; color:#f0a500;
    border:1px dashed rgba(240,165,0,.4); border-radius:8px; padding:18px; text-align:center; }
  body a { pointer-events: none; }  /* previews don't navigate */
</style>
</head>
<body>
<?= $body ?>
</body>
</html>
