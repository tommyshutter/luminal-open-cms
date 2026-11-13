<?php
/**
 * @appname    Earl's / RI Admin
 * @file       /admin/includes/site_root_resolver.inc.php
 * @version    2025.10.02.1736.00-EST r1202-quiet
 * @author     ChatGPT
 * @license    Business Source License
 * @description Resolve SITE_ROOT robustly on cPanel/DO. Silent by default; verbose only with ?debug=1.
 */

declare(strict_types=1);

/* --- Debug flag (silent by default) --- */
$RI_VERBOSE = isset($_GET['debug']) && $_GET['debug'] !== '0';

/* --- Minimal toast shim (only emits when $RI_VERBOSE) --- */
if (!function_exists('ri_toast')) {
  function ri_toast(string $level, string $msg, array $meta = []): void {
    // Intentionally silent by default; the resolver will gate calls via $RI_VERBOSE.
    @error_log("[TOAST][$level] $msg " . json_encode($meta));
  }
}

/* Validator: does this path look like project root? */
if (!function_exists('ri_validate_root')) {
  function ri_validate_root(string $root): bool {
    if ($root === '' || !is_dir($root)) return false;
    $sentinels = [
      $root . '/admin/admin_header.php',
      $root . '/admin/admin_menu.php',
      $root . '/header/document_header_body.php',
    ];
    foreach ($sentinels as $p) {
      if (is_file($p)) return true;
    }
    return false;
  }
}

/* If already defined AND valid, keep it */
if (defined('SITE_ROOT') && ri_validate_root(SITE_ROOT)) {
  if ($RI_VERBOSE) { ri_toast('info','SITE_ROOT pre-defined & valid', ['SITE_ROOT'=>SITE_ROOT]); }
  return;
}

/* Build candidates */
$candidates = [];
$candidates[] = realpath(__DIR__ . '/..');        // /admin
$candidates[] = realpath(__DIR__ . '/../..');     // root (likely)
if (!empty($_SERVER['DOCUMENT_ROOT'])) $candidates[] = realpath($_SERVER['DOCUMENT_ROOT']);
if (!empty($_SERVER['SCRIPT_FILENAME'])) {
  $sf = realpath($_SERVER['SCRIPT_FILENAME']);
  if ($sf) {
    $up = dirname($sf);
    for ($i=0; $i<5; $i++) { $candidates[] = $up; $up = dirname($up); }
  }
}
$candidates[] = realpath(getcwd());
$candidates[] = realpath(getcwd() . '/..');

/* If any path contains public_html, trim to that (common on HostGator) */
$uniq = array_filter(array_unique(array_filter($candidates)));
foreach ($uniq as $p) {
  $pos = strpos($p ?: '', 'public_html');
  if ($pos !== false) $candidates[] = substr($p, 0, $pos + strlen('public_html'));
}
$candidates = array_values(array_unique(array_filter($candidates)));

$chosen = null;
foreach ($candidates as $cand) {
  if ($cand && ri_validate_root($cand)) { $chosen = $cand; break; }
}

/* Final fallback: two-levels up from /admin/includes */
if (!$chosen) {
  $chosen = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
  if ($RI_VERBOSE) { ri_toast('warn','SITE_ROOT guessed by fallback; sentinels not found', ['chosen'=>$chosen]); }
} else {
  if ($RI_VERBOSE) { ri_toast('info','SITE_ROOT resolved', ['chosen'=>$chosen]); }
}

/* Define */
if (!defined('SITE_ROOT')) define('SITE_ROOT', $chosen);

/* Optional verbose breadcrumb (only with ?debug=1) */
if ($RI_VERBOSE) {
  ri_toast('info','SITE_ROOT debug', [
    'SITE_ROOT' => SITE_ROOT,
    '__DIR__'   => __DIR__,
    'DOC_ROOT'  => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'SCRIPT'    => $_SERVER['SCRIPT_FILENAME'] ?? null,
    'CWD'       => getcwd(),
    'candidates'=> $candidates,
  ]);
}
