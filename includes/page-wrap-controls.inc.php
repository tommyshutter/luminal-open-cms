<?php
/**
 * @appname    The Site
 * @file       /includes/page-wrap-controls.inc.php
 * @version    2025.09.25.1059.00-EST
 * @author     ChatGPT
 * @license    Business Source License
 * @description Page JSON aware toggles for: page wrapper + background include.
 *              Additive helper; safe to include anywhere after PAGE_DATA is established.
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
  define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

/**
 * Looks for page-level flags from the resolved PAGE_DATA array if available.
 * Falls back to a best-effort read of the resolved page json path if the
 * caller exposed it via $PAGE_JSON. If nothing found, no-op gracefully.
 */
$__PAGE_FLAGS = [
  'use_wrapper' => null,
  'include_bg'  => null,
];

try {
  if (isset($GLOBALS['PAGE_DATA']) && is_array($GLOBALS['PAGE_DATA'])) {
    $pd = $GLOBALS['PAGE_DATA'];
    $__PAGE_FLAGS['use_wrapper'] = array_key_exists('use_wrapper', $pd) ? (bool)$pd['use_wrapper'] : null;
    $__PAGE_FLAGS['include_bg']  = array_key_exists('include_bg',  $pd) ? (bool)$pd['include_bg']  : null;
  } elseif (isset($GLOBALS['PAGE_JSON']) && is_string($GLOBALS['PAGE_JSON']) && is_file($GLOBALS['PAGE_JSON'])) {
    $j = json_decode(@file_get_contents($GLOBALS['PAGE_JSON']) ?: '', true);
    if (is_array($j)) {
      $__PAGE_FLAGS['use_wrapper'] = array_key_exists('use_wrapper', $j) ? (bool)$j['use_wrapper'] : null;
      $__PAGE_FLAGS['include_bg']  = array_key_exists('include_bg',  $j) ? (bool)$j['include_bg']  : null;
    }
  }
} catch (\Throwable $e) {
  // fail silent
}

/* ---------- Background loader (opt-in per page) ---------- */
if ($__PAGE_FLAGS['include_bg'] === true) {
  $bg_inc = SITE_ROOT . '/panels/background_loader.php';
  if (is_file($bg_inc)) {
    @include_once $bg_inc;
  }
}

/* ---------- Page wrapper toggle ---------- */
/* Contract: adding a class lets CSS handle the wrap; we DO NOT restructure DOM */
if ($__PAGE_FLAGS['use_wrapper'] === false) {
  echo "<script>(function(){try{document.documentElement.classList.add('no-page-wrap');document.body.classList.add('no-page-wrap');}catch(e){}})();</script>";
}

/* OPTIONAL tiny CSS: only activates when .no-page-wrap is present */
?>
<style>
  /* Only has effect when toggled; otherwise inert */
  html.no-page-wrap body.no-page-wrap main {
    max-width: none !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
  }
</style>
