<?php
/**
 * @appname     HTML Block Renderer
 * @file        SITE-ROOT/includes/renderers/html_block.renderer.php
 * @description Renders first-class HTML Blocks (admin/data/html-blocks/{slug}.json)
 *              AND provides the shared CSS/JS scoping helpers used by the
 *              content-stack renderer for per-item CSS/JS.
 *
 *              Scoping strategy: every block gets a unique wrapper class
 *              (.hb-{id}) and its CSS is rewritten so every non-@-rule
 *              selector is prefixed with .hb-{id}. JS is wrapped in an IIFE
 *              bound to the wrapper element with a dedup guard so it runs
 *              exactly once per rendered instance.
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

/* ----------------------------------------------------------
 * Escape helpers — stop hostile CSS/JS from breaking out of
 * their container by emitting </style> or </script>.
 * ---------------------------------------------------------- */

if (!function_exists('hb_escape_css_safe')) {
    function hb_escape_css_safe(string $css): string {
        // Case-insensitive match on </style, replace the '<' with escape
        return preg_replace('~</style~i', '<\\/style', $css) ?? $css;
    }
}
if (!function_exists('hb_escape_js_safe')) {
    function hb_escape_js_safe(string $js): string {
        return preg_replace('~</script~i', '<\\/script', $js) ?? $js;
    }
}

/* ----------------------------------------------------------
 * hb_scope_css — prefix every rule in $css with $scope class.
 * Handles @media / @keyframes / @supports / @font-face / @import
 * correctly by descending into rule blocks.
 *
 * Keyframes (@keyframes name { 0% {...} 100% {...} }) intentionally
 * NOT scoped — they're name-referenced, not selector-referenced.
 * ---------------------------------------------------------- */

if (!function_exists('hb_scope_css')) {
    function hb_scope_css(string $css, string $scope): string {
        $css = hb_escape_css_safe($css);
        if (trim($css) === '') return '';

        // Strip CSS comments first (they can contain { } that confuse the parser)
        $css = preg_replace('~/\*.*?\*/~s', '', $css);

        $out = '';
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            // Pull the next "chunk" up to the next { or end
            $brace = strpos($css, '{', $i);
            if ($brace === false) {
                // Trailing garbage, skip
                break;
            }
            $header = trim(substr($css, $i, $brace - $i));
            if ($header === '') { $i = $brace + 1; continue; }

            // Find matching close brace (handles nested braces in @media etc.)
            $depth = 1;
            $j = $brace + 1;
            while ($j < $len && $depth > 0) {
                if ($css[$j] === '{') { $depth++; }
                elseif ($css[$j] === '}') { $depth--; }
                $j++;
            }
            $body = substr($css, $brace + 1, $j - $brace - 2);

            if (strpos($header, '@') === 0) {
                // At-rule. Decide by type.
                if (preg_match('/^@(media|supports|document)\b/i', $header)) {
                    // Recurse: the inner body is itself a sequence of rules; scope them.
                    $out .= $header . " {\n" . hb_scope_css($body, $scope) . "\n}\n";
                } else {
                    // @keyframes / @font-face / @page / etc — emit verbatim.
                    $out .= $header . " {\n" . $body . "\n}\n";
                }
            } else {
                // Regular rule. Prefix each comma-separated selector.
                $parts = array_map('trim', explode(',', $header));
                $scoped = [];
                foreach ($parts as $sel) {
                    if ($sel === '') continue;
                    // Leave :root / html / body untouched — document-level
                    // vars should still reach the block. Prefix everything else.
                    if (preg_match('/^(:root|html|body)\b/i', $sel)) {
                        $scoped[] = $scope . ' ' . $sel;
                    } else {
                        $scoped[] = $scope . ' ' . $sel;
                    }
                }
                $out .= implode(', ', $scoped) . " {\n" . $body . "\n}\n";
            }
            $i = $j;
        }
        return $out;
    }
}

/* ----------------------------------------------------------
 * hb_emit_style — build <style id="hb-css-{id}"> for a scoped
 * CSS payload. Returns empty string if $css is blank.
 * ---------------------------------------------------------- */

if (!function_exists('hb_emit_style')) {
    function hb_emit_style(string $css, string $scope, string $hbId): string {
        $scoped = hb_scope_css($css, $scope);
        if (trim($scoped) === '') return '';
        return '<style id="hb-css-' . htmlspecialchars($hbId, ENT_QUOTES) . '">'
             . $scoped
             . '</style>';
    }
}

/* ----------------------------------------------------------
 * hb_emit_script — build <script> wrapping user JS in an IIFE
 * keyed to the wrapper element. A data-hb-init attribute guards
 * against double-execution if the block is rendered twice on
 * one page (e.g. two stacks both embedding the same library
 * block), or from live-reload scenarios.
 * ---------------------------------------------------------- */

if (!function_exists('hb_emit_script')) {
    function hb_emit_script(string $js, string $scopeClass, string $hbId): string {
        $js = trim($js);
        if ($js === '') return '';
        $js = hb_escape_js_safe($js);
        $cls = htmlspecialchars($scopeClass, ENT_QUOTES);
        // Strip leading '.' for querySelector — class name only
        $clsOnly = ltrim($scopeClass, '.');
        $out  = '<script id="hb-js-' . htmlspecialchars($hbId, ENT_QUOTES) . '">';
        $out .= '(function(){';
        $out .=   'var els=document.querySelectorAll(".' . $clsOnly . '");';
        $out .=   'els.forEach(function(root){';
        $out .=     'if(root.getAttribute("data-hb-init")==="1")return;';
        $out .=     'root.setAttribute("data-hb-init","1");';
        $out .=     'try{(function(root){' . $js . '})(root);}';
        $out .=     'catch(e){if(window.console)console.error("html-block js:",e);}';
        $out .=   '});';
        $out .= '})();';
        $out .= '</script>';
        return $out;
    }
}

/* ----------------------------------------------------------
 * hb_stable_id — short deterministic id derived from content.
 * Used when an item doesn't have an explicit id (e.g. inline
 * stack HTML items). Stable across renders so the class name
 * is predictable and cacheable.
 * ---------------------------------------------------------- */

if (!function_exists('hb_stable_id')) {
    function hb_stable_id(string $seed): string {
        return 'i' . substr(md5($seed), 0, 10);
    }
}

/* ----------------------------------------------------------
 * hb_render — render a {html, css, js, id} bundle into a
 * complete scoped output (style + wrapper + html + script).
 * Shared entry point used by both render_html_block() and
 * the content-stack renderer's per-item path.
 * ---------------------------------------------------------- */

if (!function_exists('hb_render')) {
    function hb_render(array $bundle): string {
        $html = (string)($bundle['html'] ?? '');
        $css  = (string)($bundle['css']  ?? '');
        $js   = (string)($bundle['js']   ?? '');
        $id   = (string)($bundle['id']   ?? '');
        $slug = (string)($bundle['slug'] ?? '');

        if ($id === '') $id = hb_stable_id($slug . '|' . $html);
        $cls  = 'hb hb-' . $id;
        if ($slug !== '') $cls .= ' hb-slug-' . preg_replace('/[^a-z0-9_-]/i', '-', $slug);

        // Allow nested shortcodes inside block HTML
        if (function_exists('apply_shortcodes')) {
            $html = apply_shortcodes($html);
        }

        $styleTag  = hb_emit_style($css, '.hb-' . $id, $id);
        $scriptTag = hb_emit_script($js, '.hb-' . $id, $id);

        return $styleTag
             . '<div class="' . htmlspecialchars($cls, ENT_QUOTES) . '">'
             . $html
             . '</div>'
             . $scriptTag;
    }
}

/* ----------------------------------------------------------
 * render_html_block — public entry for [[html-block:slug]].
 * Loads admin/data/html-blocks/{slug}.json and renders it.
 * ---------------------------------------------------------- */

if (!function_exists('render_html_block')) {
    function render_html_block(string $slug): string {
        // Recursion guard: a block that references itself (directly or via a cycle)
        // must never re-enter its own render — otherwise apply_shortcodes() loops
        // forever and exhausts memory (fatal). Also caps overall nesting depth.
        static $stack = [];
        $slug = trim($slug);
        if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/i', $slug)) {
            return '<!-- html-block: invalid slug -->';
        }
        if (isset($stack[$slug])) {
            return '<!-- html-block: recursion blocked (' . htmlspecialchars($slug, ENT_QUOTES) . ') -->';
        }
        if (count($stack) >= 12) {
            return '<!-- html-block: max nesting depth reached -->';
        }
        $file = SITE_ROOT . '/admin/data/html-blocks/' . $slug . '.json';
        if (!is_file($file)) {
            return '<!-- html-block not found: ' . htmlspecialchars($slug, ENT_QUOTES) . ' -->';
        }
        $raw = @file_get_contents($file);
        $data = @json_decode((string)$raw, true);
        if (!is_array($data)) {
            return '<!-- html-block: bad JSON in ' . htmlspecialchars($slug, ENT_QUOTES) . ' -->';
        }
        $stack[$slug] = true;
        try {
            $out = hb_render([
                'html' => (string)($data['html'] ?? ''),
                'css'  => (string)($data['css']  ?? ''),
                'js'   => (string)($data['js']   ?? ''),
                'id'   => (string)($data['id']   ?? ''),
                'slug' => $slug,
            ]);
        } finally {
            unset($stack[$slug]);
        }
        return $out;
    }
}
