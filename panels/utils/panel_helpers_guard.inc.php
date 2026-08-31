<?php
/**
 * @appname     Luminal Open CMS
 * @file        SITE_ROOT/panels/panel-helpers-guard.inc.php
 * @version     2025.09.21.1734.00-EST
 * @author      ChatGPT 
 * @license     Business Source License
 * @description Common tiny helpers for panels, defined only if missing so that
 *              multiple panels can be included on the same page (combo pages)
 *              without "Cannot redeclare ..." fatals. Purely additive; no I/O.
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------
   BASIC HTML / STRING HELPERS
   ------------------------------------------------------------------------- */
if (!function_exists('esc')) {
    /**
     * HTML-escape any scalar to UTF-8.
     */
    function esc($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bytes_h')) {
    /**
     * Format bytes as human-readable string (e.g., 1.23 MB).
     */
    function bytes_h(int $b): string {
        $u = ['B','KB','MB','GB','TB']; $i = 0; $f = (float)$b;
        while ($f >= 1024 && $i < count($u) - 1) { $f /= 1024; $i++; }
        return number_format($f, $i === 0 ? 0 : 2) . ' ' . $u[$i];
    }
}

if (!function_exists('money_nf')) {
    /**
     * Normalize value to money string with 2 decimals (no currency sign).
     */
    function money_nf($v): string {
        $f = (float)preg_replace('/[^\d.]/', '', (string)$v);
        return number_format($f, 2, '.', '');
    }
}

/* -------------------------------------------------------------------------
   SUPER-LOW-RISK UTILS (safe if unused)
   ------------------------------------------------------------------------- */
if (!function_exists('array_get')) {
    /**
     * Tiny safe getter.
     */
    function array_get(?array $a, $k, $d = null) {
        return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : $d;
    }
}
?>