<?php
/**
 * Cart drawer visibility.
 *
 * The header cart icon and the footer cart drawer show only when this returns true.
 * site-settings.json "cart_drawer":
 *   auto (default) — show when the site has an active shop: an enabled MyStore product,
 *                    or synced Printful products (admin/data/printful/products.json)
 *   on             — always show ("Display Cart Drawer On Site")
 *   off            — never show
 */
if (!function_exists('lum_cart_drawer_enabled')) {
    function lum_cart_drawer_enabled(): bool {
        static $r = null;
        if ($r !== null) return $r;
        $root = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__);
        $s = json_decode((string)@file_get_contents($root . '/admin/data/site-settings.json'), true);
        $mode = is_array($s) ? (string)($s['cart_drawer'] ?? 'auto') : 'auto';
        if ($mode === 'off') return $r = false;
        if ($mode === 'on')  return $r = true;
        foreach (glob($root . '/admin/data/mystore/products/*.json') ?: [] as $f) {
            $p = json_decode((string)@file_get_contents($f), true);
            if (is_array($p) && !empty($p['sku']) && ($p['enabled'] ?? true)) return $r = true;
        }
        $pf = json_decode((string)@file_get_contents($root . '/admin/data/printful/products.json'), true);
        return $r = (is_array($pf) && count($pf) > 0);
    }
}
