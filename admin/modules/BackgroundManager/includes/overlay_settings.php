<?php
/**
 * Background Manager — overlay block helpers.
 *
 * overlay.color_rgba     the dimming layer over the background (existing).
 * overlay.panel_opacity  how solid the site's content panels are, 0..1. Absent = the site's
 *                        own CSS decides (every site is untouched until someone moves the slider).
 *                        Emitted as --lum-panel-alpha by header_core.php; a site's panel CSS opts in
 *                        with background: rgba(R, G, B, var(--lum-panel-alpha, <its default>)).
 */

if (!function_exists('bgm_merge_overlay')) {
    /** Merge a saved overlay block with an incoming one. panel_opacity null/'' = back to site default. */
    function bgm_merge_overlay(array $current, array $incoming): array {
        $out = array_merge($current, $incoming);
        if (array_key_exists('panel_opacity', $incoming)) {
            $v = $incoming['panel_opacity'];
            if ($v === null || $v === '' || !is_numeric($v)) {
                unset($out['panel_opacity']);
            } else {
                $out['panel_opacity'] = round(max(0.0, min(1.0, (float)$v)), 2);
            }
        }
        return $out;
    }
}
