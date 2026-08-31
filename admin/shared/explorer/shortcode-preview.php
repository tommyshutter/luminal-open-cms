<?php
/**
 * Luminal CMS — Explorer shortcode live preview
 * @file /admin/shared/explorer/shortcode-preview.php
 *
 * Auth-gated iframe target: renders ONE shortcode through the real
 * apply_shortcodes() engine with the site's real stylesheet, so the
 * Explorer lightbox can show a stack/gallery/widget as it will actually
 * appear on a page (same fidelity idea as PageManager's edit_frame.php).
 *
 * Query:
 *   code=[[stack:foo]]   required — must be a single well-formed shortcode
 *
 * Body is transparent over a dark admin scrim (transparent-page rule).
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/auth.php';
requireAuth();
// Renderers can be slow (stats, store) — never hold the session lock while
// rendering, or we'd serialize the whole admin session (media-cache lesson).
session_write_close();

$code = (string)($_GET['code'] ?? '');
// Strict: exactly one [[ ... ]] shortcode, sane length, no nested brackets.
if (!preg_match('/^\[\[[^\[\]]{1,300}\]\]$/', $code)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid shortcode.';
    exit;
}

@include_once SITE_ROOT . '/includes/shortcodes.php';

$rendered = '';
$error    = '';
if (!function_exists('apply_shortcodes')) {
    $error = 'Shortcode engine unavailable on this site.';
} else {
    try {
        ob_start();
        $rendered = apply_shortcodes($code);
        $stray = ob_get_clean();                  // some renderers echo — capture
        if ($rendered === $code) {
            // Engine left it untouched → unknown/unregistered shortcode here
            $rendered = '';
            $error = 'No renderer for this shortcode on this site.';
        } elseif (trim($rendered) === '' && trim((string)$stray) !== '') {
            $rendered = (string)$stray;
        }
    } catch (Throwable $e) {
        @ob_end_clean();
        $error = 'Preview failed: ' . $e->getMessage();
    }
}

$stylesCss   = SITE_ROOT . '/styles.css';
$stylesMtime = is_file($stylesCss) ? (int)filemtime($stylesCss) : 0;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Preview — <?= $h($code) ?></title>
<base target="_blank">
<?php if ($stylesMtime): ?>
<link rel="stylesheet" href="/styles.css?v=<?= $stylesMtime ?>">
<?php endif; ?>
<style>
  /* Preview scaffold — transparent body over a dark scrim so site styles
     render true against admin chrome (mirrors edit_frame.php). */
  html, body { background: transparent !important; margin: 0; padding: 0; }
  body { padding: 16px 18px; color: #e5e7eb; }
  body::before {
    content: ""; position: fixed; inset: 0; z-index: -1;
    background: linear-gradient(180deg, rgba(15,18,25,.94), rgba(9,11,16,.96));
  }
  .scp-error {
    font: 13px/1.5 system-ui, sans-serif; color: #f0a500;
    border: 1px dashed rgba(240,165,0,.4); border-radius: 8px;
    padding: 18px; text-align: center;
  }
  .scp-error code { color: #93c5fd; }
  /* Previews shouldn't navigate anywhere. */
  body a { pointer-events: none; }
</style>
</head>
<body class="scp-body">
<?php if ($error !== ''): ?>
  <div class="scp-error"><?= $h($error) ?><br><code><?= $h($code) ?></code></div>
<?php else: ?>
  <?= $rendered ?>
<?php endif; ?>
</body>
</html>
