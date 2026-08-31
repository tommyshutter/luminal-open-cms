<?php
/**
 * css_scope.php — the residual-usage CSS scope engine behind "Convert to HTML Block".
 *
 * Given a chunk of HTML being lifted out of a page, plus the page's CSS and the
 * page content that will REMAIN, classify each page-CSS rule the chunk uses into:
 *   • MOVE     — no remaining page content uses it → safe to move into the block
 *   • COPY     — remaining content still uses it → leave a copy in the page CSS
 *   • PROMOTE  — the class appears on OTHER pages too → candidate for site-global CSS
 *
 * Pure functions, no I/O, no dependencies. The caller supplies strings.
 *
 * @file admin/modules/PageManager/includes/css_scope.php
 */

if (!function_exists('pmcss_strip_comments')) {

/** Remove /* … *​/ comments (non-greedy, multiline). */
function pmcss_strip_comments(string $css): string {
    return preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
}

/** Collect the class / id / tag tokens an HTML fragment actually references. */
function pmcss_html_tokens(string $html): array {
    $classes = []; $ids = []; $tags = [];
    if (preg_match_all('/class\s*=\s*"([^"]*)"/i', $html, $m)) {
        foreach ($m[1] as $c) foreach (preg_split('/\s+/', trim($c)) as $t) if ($t !== '') $classes[$t] = 1;
    }
    if (preg_match_all("/class\s*=\s*'([^']*)'/i", $html, $m)) {
        foreach ($m[1] as $c) foreach (preg_split('/\s+/', trim($c)) as $t) if ($t !== '') $classes[$t] = 1;
    }
    if (preg_match_all('/\bid\s*=\s*"([^"]*)"/i', $html, $m)) foreach ($m[1] as $i) { $i = trim($i); if ($i !== '') $ids[$i] = 1; }
    if (preg_match_all("/\bid\s*=\s*'([^']*)'/i", $html, $m)) foreach ($m[1] as $i) { $i = trim($i); if ($i !== '') $ids[$i] = 1; }
    if (preg_match_all('/<([a-zA-Z][a-zA-Z0-9]*)/', $html, $m)) foreach ($m[1] as $t) $tags[strtolower($t)] = 1;
    return ['classes' => $classes, 'ids' => $ids, 'tags' => $tags];
}

/**
 * Parse CSS into a flat list of rule units, one level of @media/@supports unwrapped.
 * Each unit: ['media'=>?string, 'selector'=>string, 'body'=>string, 'atrule'=>bool].
 * @keyframes / @font-face are kept whole (atrule=true) so we can pull referenced ones.
 */
function pmcss_parse(string $css): array {
    $css = pmcss_strip_comments($css);
    $rules = []; $i = 0; $n = strlen($css); $buf = '';
    while ($i < $n) {
        $ch = $css[$i];
        if ($ch === '{') {
            $selector = trim($buf); $buf = '';
            // walk to the matching close brace, honoring nesting
            $depth = 1; $j = $i + 1; $inner = '';
            while ($j < $n && $depth > 0) {
                $cj = $css[$j];
                if ($cj === '{') $depth++;
                elseif ($cj === '}') { $depth--; if ($depth === 0) { $j++; break; } }
                $inner .= $cj; $j++;
            }
            $lower = strtolower($selector);
            if (strpos($lower, '@media') === 0 || strpos($lower, '@supports') === 0) {
                foreach (pmcss_parse($inner) as $r) {
                    if ($r['media'] === null) $r['media'] = $selector;
                    $rules[] = $r;
                }
            } elseif ($selector !== '' && $selector[0] === '@') {
                $rules[] = ['media' => null, 'selector' => $selector, 'body' => trim($inner), 'atrule' => true];
            } else {
                $rules[] = ['media' => null, 'selector' => $selector, 'body' => trim($inner), 'atrule' => false];
            }
            $i = $j; continue;
        }
        $buf .= $ch; $i++;
    }
    return $rules;
}

/** Classes / ids / tags a selector list references (across comma branches). */
function pmcss_selector_tokens(string $sel): array {
    $classes = []; $ids = []; $tags = [];
    foreach (explode(',', $sel) as $branch) {
        if (preg_match_all('/\.([A-Za-z0-9_-]+)/', $branch, $m)) foreach ($m[1] as $c) $classes[$c] = 1;
        if (preg_match_all('/#([A-Za-z0-9_-]+)/', $branch, $m))  foreach ($m[1] as $x) $ids[$x] = 1;
        if (preg_match_all('/(?:^|[\s>+~])([a-zA-Z][a-zA-Z0-9]*)/', $branch, $m)) foreach ($m[1] as $t) {
            $t = strtolower($t);
            if (!in_array($t, ['and','or','not','only','screen','print'], true)) $tags[$t] = 1;
        }
    }
    return ['classes' => $classes, 'ids' => $ids, 'tags' => $tags];
}

/** Do two token maps share any key? */
function pmcss_intersects(array $a, array $b): bool {
    foreach ($a as $k => $_) if (isset($b[$k])) return true;
    return false;
}

/** Re-emit a rule as CSS text, re-wrapping its @media if present. */
function pmcss_emit(array $r): string {
    $rule = $r['selector'] . " {\n  " . trim($r['body']) . "\n}";
    if (!empty($r['media'])) return $r['media'] . " {\n" . $rule . "\n}";
    return $rule;
}

/**
 * The classifier.
 * @param string     $pageCss       the page's {slug}.css (+ any inline <style>, concatenated)
 * @param string     $selHtml       the HTML being lifted into the block
 * @param string     $residualHtml  everything that STAYS on the page (other columns/sections)
 * @param array|null $crossUsage    optional map className=>count of OTHER pages using it (promote signal)
 * @return array {captured_css, remaining_css, moved[], copied[], promote[], stats}
 */
function pmcss_classify(string $pageCss, string $selHtml, string $residualHtml, ?array $crossUsage = null): array {
    $selTok = pmcss_html_tokens($selHtml);
    $resTok = pmcss_html_tokens($residualHtml);
    $rules  = pmcss_parse($pageCss);

    $capturedRules = []; $keepRules = [];
    $moved = []; $copied = []; $promote = [];
    $animNeeded = [];   // animation names referenced by captured rules
    $fontNeeded = false;

    foreach ($rules as $r) {
        if (!empty($r['atrule'])) { $keepRules[] = $r; continue; } // handle at-rules after

        $st = pmcss_selector_tokens($r['selector']);
        $usesSel = pmcss_intersects($st['classes'], $selTok['classes'])
                || pmcss_intersects($st['ids'],     $selTok['ids']);
        if (!$usesSel) { $keepRules[] = $r; continue; }

        // capture it
        $usesRes = pmcss_intersects($st['classes'], $resTok['classes'])
                || pmcss_intersects($st['ids'],     $resTok['ids']);

        $label = ['selector' => $r['selector'], 'media' => $r['media']];
        $capturedRules[] = $r;
        if ($usesRes) { $copied[] = $label; $keepRules[] = $r; }   // copy = block gets it AND page keeps it
        else          { $moved[]  = $label; }                       // move = out of page CSS

        // cross-page promote signal
        if ($crossUsage) {
            foreach ($st['classes'] as $c => $_) {
                if (!empty($crossUsage[$c])) { $promote[] = $label + ['pages' => (int)$crossUsage[$c]]; break; }
            }
        }
        // note animation-name references so we can pull @keyframes
        if (preg_match_all('/animation(?:-name)?\s*:\s*([^;]+)/i', $r['body'], $am)) {
            foreach ($am[1] as $decl) {
                foreach (preg_split('/[\s,]+/', trim($decl)) as $tok) {
                    if ($tok !== '' && !is_numeric($tok) &&
                        !preg_match('/^(\d|infinite|linear|ease|ease-in|ease-out|ease-in-out|alternate|normal|reverse|both|forwards|backwards|running|paused|s|ms)$/i', $tok)) {
                        $animNeeded[$tok] = 1;
                    }
                }
            }
        }
    }

    // pull @keyframes matching needed animation names into the captured set
    foreach ($rules as $r) {
        if (empty($r['atrule'])) continue;
        if (stripos($r['selector'], '@keyframes') === 0) {
            $name = trim(preg_replace('/@keyframes/i', '', $r['selector']));
            if (isset($animNeeded[$name])) $capturedRules[] = $r;
        }
    }

    $capturedCss = implode("\n\n", array_map('pmcss_emit', $capturedRules));

    // ── :root custom-property capture ──────────────────────────────────────
    // Captured rules that use var(--x) would break if moved away from the page's
    // :root. Pull ONLY the referenced custom props (transitively) so the block is
    // self-contained. These are almost always site-level design tokens trapped in
    // a page file → also surface them as promote-to-global candidates.
    $rootDefs = pmcss_root_var_defs($rules);            // name => value
    $needVars = pmcss_referenced_vars($capturedCss, $rootDefs);  // resolved, transitive
    $promoteVars = [];
    if ($needVars) {
        $lines = '';
        foreach ($needVars as $v) { $lines .= "  $v: " . $rootDefs[$v] . ";\n"; $promoteVars[] = $v; }
        $capturedCss = ":root {\n" . $lines . "}\n\n" . $capturedCss;
    }

    $remainingCss = implode("\n\n", array_map('pmcss_emit', $keepRules));

    return [
        'captured_css'  => $capturedCss,
        'remaining_css' => $remainingCss,
        'moved'   => $moved,
        'copied'  => $copied,
        'promote' => $promote,
        'promote_vars' => $promoteVars,   // design tokens the block copied — should live in global :root
        'stats'   => [
            'captured' => count($capturedRules),
            'moved'    => count($moved),
            'copied'   => count($copied),
            'promote'  => count($promote),
            'vars'     => count($promoteVars),
        ],
    ];
}

/** Map of custom-property => value from any :root / html / body rule in the page CSS. */
function pmcss_root_var_defs(array $rules): array {
    $defs = [];
    foreach ($rules as $r) {
        if (!empty($r['atrule'])) continue;
        $sel = strtolower($r['selector']);
        if (strpos($sel, ':root') === false && !preg_match('/^\s*(html|body)\b/', $sel)) continue;
        if (preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;]+);?/', $r['body'], $m, PREG_SET_ORDER)) {
            foreach ($m as $d) $defs[$d[1]] = trim($d[2]);
        }
    }
    return $defs;
}

/** Custom props referenced (transitively) by a CSS string, limited to those the page defines. */
function pmcss_referenced_vars(string $css, array $rootDefs): array {
    $need = [];
    $scan = [$css];
    while ($scan) {
        $chunk = array_pop($scan);
        if (preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $chunk, $m)) {
            foreach ($m[1] as $v) {
                if (isset($rootDefs[$v]) && !isset($need[$v])) {
                    $need[$v] = 1;
                    $scan[] = $rootDefs[$v];   // a var value may reference another var
                }
            }
        }
    }
    return array_keys($need);
}

} // function_exists guard
