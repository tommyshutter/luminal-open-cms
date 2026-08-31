<?php
/**
 * text_style.php — Per-page Text Style reading surface (the text-side twin of
 * BackgroundManager's opacity control). A page stores a `text_style` object; this
 * DERIVES the CSS at render time (one source of truth — no frozen copy).
 *
 *   text_style_presets()            → named preset definitions (UI + resolver share these)
 *   text_style_resolve($ts)         → a normalized settings array (preset filled in, sanitized)
 *   text_style_css($ts, $selector)  → the <style> body for the reading surface
 *
 * Presets: none · base (noticeable-not-heavy dark layer) · apple (frosted glass:
 * blur + big radius + soft shadow) · auria (frosted glass + accent glow) · custom.
 */
declare(strict_types=1);

if (!function_exists('text_style_presets')) {

function text_style_presets(): array {
    // surface: dark|light · opacity 0-1 · radius px · blur px · shadow/glow bool
    return [
        'none'   => ['surface'=>'dark','opacity'=>0,   'radius'=>0,  'blur'=>0,  'shadow'=>false,'glow'=>false],
        'base'   => ['surface'=>'dark','opacity'=>0.45,'radius'=>8,  'blur'=>0,  'shadow'=>false,'glow'=>false],
        'apple'  => ['surface'=>'dark','opacity'=>0.55,'radius'=>18, 'blur'=>14, 'shadow'=>true, 'glow'=>false],
        'auria'  => ['surface'=>'dark','opacity'=>0.50,'radius'=>16, 'blur'=>14, 'shadow'=>true, 'glow'=>true ],
    ];
}

/** Normalize + sanitize a stored text_style into concrete values (preset fills gaps). */
function text_style_resolve($ts): array {
    if (!is_array($ts)) return [];
    $preset = (string)($ts['preset'] ?? '');
    if ($preset === '' || $preset === 'none') {
        // explicit "none" (or unset) => no surface, UNLESS custom values were saved
        if ($preset === 'none') return ['preset'=>'none','_off'=>true];
    }
    $presets = text_style_presets();
    // Base values from the named preset (custom => start from apple as a sensible base).
    $base = $presets[$preset] ?? ($preset === 'custom' ? $presets['apple'] : []);
    $out = [
        'preset'  => $preset ?: 'custom',
        'surface' => in_array(($ts['surface'] ?? $base['surface'] ?? 'dark'), ['dark','light'], true) ? ($ts['surface'] ?? $base['surface'] ?? 'dark') : 'dark',
        'opacity' => max(0.0, min(1.0, (float)($ts['opacity'] ?? $base['opacity'] ?? 0))),
        'radius'  => max(0,  min(60, (int)($ts['radius']  ?? $base['radius']  ?? 0))),
        'blur'    => max(0,  min(40, (int)($ts['blur']    ?? $base['blur']    ?? 0))),
        'shadow'  => (bool)($ts['shadow'] ?? $base['shadow'] ?? false),
        'glow'    => (bool)($ts['glow']   ?? $base['glow']   ?? false),
    ];
    // Custom shadow shaping (optional) — color / opacity / height (offset distance) / angle.
    // Only "custom" when at least one is explicitly provided; otherwise the legacy soft
    // drop shadow is used, so existing shadow="1" sections render exactly as before.
    $shC = $ts['shadow_color']   ?? null;
    $shO = $ts['shadow_opacity'] ?? null;
    $shH = $ts['shadow_height']  ?? null;
    $shA = $ts['shadow_angle']   ?? null;
    $out['sh_custom'] = ($shC !== null || $shO !== null || $shH !== null || $shA !== null);
    $out['sh_color']  = (is_string($shC) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $shC)) ? $shC : '#000000';
    $out['sh_op']     = ($shO !== null) ? max(0.0, min(1.0, ((float)$shO > 1 ? (float)$shO / 100 : (float)$shO))) : 0.45;
    $out['sh_h']      = ($shH !== null) ? max(0, min(80, (int)$shH)) : 10;
    $out['sh_ang']    = ($shA !== null) ? ((((int)$shA % 360) + 360) % 360) : 135;  // default: SW (down-left)
    if (($out['opacity'] <= 0) && $out['radius'] === 0 && $out['blur'] === 0 && !$out['shadow'] && !$out['glow']) {
        $out['_off'] = true;
    }
    return $out;
}

/** #rgb|#rrggbb + alpha(0-1) → "rgba(r,g,b,a)". */
function text_style_hex_rgba(string $hex, float $a): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    $a = rtrim(rtrim(number_format(max(0.0, min(1.0, $a)), 3), '0'), '.');
    return "rgba($r,$g,$b,$a)";
}

/** The drop-shadow box-shadow value from a resolved style ('' when shadow off).
 *  Custom mode derives offset X/Y from height+angle and blur from height. */
function text_style_shadow_value(array $s): string {
    if (empty($s['shadow'])) return '';
    if (empty($s['sh_custom'])) return '0 10px 34px rgba(0,0,0,0.45)';
    $rad = deg2rad((float)$s['sh_ang']);
    $ox  = (int)round($s['sh_h'] * cos($rad));
    $oy  = (int)round($s['sh_h'] * sin($rad));
    $bl  = (int)round($s['sh_h'] * 2.4);
    return $ox . 'px ' . $oy . 'px ' . $bl . 'px ' . text_style_hex_rgba($s['sh_color'], (float)$s['sh_op']);
}

/** Build the reading-surface CSS for $selector from a stored text_style (empty string = nothing). */
function text_style_css($ts, string $selector = '#content .content-inner'): string {
    $s = text_style_resolve($ts);
    if (empty($s) || !empty($s['_off'])) return '';
    $rgb = $s['surface'] === 'light' ? '255,255,255' : '0,0,0';
    $rules = [];
    $rules[] = 'background: rgba(' . $rgb . ',' . rtrim(rtrim(number_format($s['opacity'], 3), '0'), '.') . ')';
    if ($s['radius'] > 0) $rules[] = 'border-radius: ' . $s['radius'] . 'px';
    if ($s['blur']   > 0) { $rules[] = 'backdrop-filter: blur(' . $s['blur'] . 'px)'; $rules[] = '-webkit-backdrop-filter: blur(' . $s['blur'] . 'px)'; }
    $shadows = [];
    $sv = text_style_shadow_value($s);
    if ($sv !== '')   $shadows[] = $sv;
    if ($s['glow'])   $shadows[] = '0 0 42px var(--ts-glow, rgba(150,185,255,0.42))';
    if ($shadows)     $rules[] = 'box-shadow: ' . implode(', ', $shadows);
    // Give the surface some breathing room so text isn't flush to the panel edge.
    $rules[] = 'padding: clamp(18px, 3.5vw, 42px)';
    return $selector . " {\n    " . implode(";\n    ", $rules) . ";\n}\n";
}

/** Inline style declarations for the surface (no selector) — for the [[section]] shortcode etc. */
function text_style_inline($ts): string {
    $s = text_style_resolve($ts);
    if (empty($s) || !empty($s['_off'])) return '';
    $rgb = ($s['surface'] === 'light') ? '255,255,255' : '0,0,0';
    $rules = [];
    $rules[] = 'background: rgba(' . $rgb . ',' . rtrim(rtrim(number_format($s['opacity'], 3), '0'), '.') . ')';
    if ($s['radius'] > 0) $rules[] = 'border-radius: ' . $s['radius'] . 'px';
    if ($s['blur']   > 0) { $rules[] = 'backdrop-filter: blur(' . $s['blur'] . 'px)'; $rules[] = '-webkit-backdrop-filter: blur(' . $s['blur'] . 'px)'; }
    $shadows = [];
    $sv = text_style_shadow_value($s);
    if ($sv !== '')   $shadows[] = $sv;
    if ($s['glow'])   $shadows[] = '0 0 42px var(--ts-glow, rgba(150,185,255,0.42))';
    if ($shadows)     $rules[] = 'box-shadow: ' . implode(', ', $shadows);
    $rules[] = 'padding: clamp(18px, 3.5vw, 42px)';
    return implode('; ', $rules);
}

} // end guard
