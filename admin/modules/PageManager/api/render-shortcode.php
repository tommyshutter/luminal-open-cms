<?php
/**
 * render-shortcode.php — render a single shortcode token to HTML for the
 * WYSIWYG editor, so Page Manager can show shortcodes as live "chips" instead
 * of raw [[…]] text. Runs the site's own apply_shortcodes() engine.
 *
 *   POST  code=<shortcode string>   → { ok, rendered, html }
 *     rendered=false  → engine returned the input unchanged (unknown or
 *                       context-dependent shortcode) → client shows a label chip.
 *
 * Auth: admin session (requireAuth), same as every other PageManager api.
 * @file admin/modules/PageManager/api/render-shortcode.php
 */
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();
header('Content-Type: application/json; charset=utf-8');

$SITE_ROOT = realpath(dirname(__DIR__, 4));

function rs_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$code = (string)($_POST['code'] ?? '');
$code = trim($code);
if ($code === '')            rs_fail('empty');
if (strlen($code) > 20000)   rs_fail('oversized');

// Load the site's shortcode engine (same file page.php uses).
$scFile = $SITE_ROOT . '/includes/shortcodes.php';
if (is_file($scFile)) { @include_once $scFile; }
if (!function_exists('apply_shortcodes')) rs_fail('shortcode engine unavailable', 500);

// Render defensively — a context-dependent shortcode (shf-*, dashboards) may
// throw or emit warnings in the editor context; fall back to a label chip.
$html = $code;
try {
    $out = apply_shortcodes($code);
    if (is_string($out)) $html = $out;
} catch (\Throwable $e) {
    echo json_encode(['ok' => true, 'rendered' => false, 'html' => $code, 'note' => 'threw']);
    exit;
}

// Strip the engine's debug-toaster <script> injections (editor noise; they
// won't run from an innerHTML chip anyway).
$html = preg_replace('#<script>\(function\(w\)\{try\{var t=w\.__ADMIN_TOASTER.*?</script>#is', '', $html);
$html = trim($html);

$rendered = ($html !== '' && $html !== $code && stripos($html, '<!--') !== 0);
echo json_encode(['ok' => true, 'rendered' => $rendered, 'html' => $html]);
