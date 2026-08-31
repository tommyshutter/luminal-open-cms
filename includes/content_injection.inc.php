<?php
/**
 * Content Injection resolver — single source of truth for the two orthogonal
 * right-column providers a page/article can carry:
 *   • Affiliate placement  (auto affiliate-products)
 *   • Universal Content Stack (the global content stack: promos/CTAs/affiliates)
 *
 * Each provider has a site-level default per content-type (article|page) and an
 * optional per-item override stored in the page JSON. Injected providers render at
 * the TOP of the right column; the page's own stored right-column content stays below.
 *
 * Built-in defaults (when a site has set nothing): articles = both ON, pages = both OFF.
 *
 * @file /includes/content_injection.inc.php
 */

if (!function_exists('ci_site_settings')) {
    function ci_site_settings(): array {
        if (isset($GLOBALS['SITE_SETTINGS']) && is_array($GLOBALS['SITE_SETTINGS'])) return $GLOBALS['SITE_SETTINGS'];
        $f = (defined('SITE_ROOT') ? SITE_ROOT : '') . '/admin/data/site-settings.json';
        return is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    }
}

if (!function_exists('ci_is_article_slug')) {
    /** True if $slug is a known article in EITHER article store: the newer
     *  Articles/index.json OR the legacy ArticlesManager/articles.json. Sites that
     *  only ever used ArticlesManager (no index.json) still
     *  resolve as articles so the content_injection.article.* defaults apply. */
    function ci_is_article_slug(string $slug): bool {
        if ($slug === '') return false;
        $root   = (defined('SITE_ROOT') ? SITE_ROOT : '');
        $target = strtolower($slug);
        foreach (['/admin/data/Articles/index.json', '/admin/data/ArticlesManager/articles.json'] as $rel) {
            $f = $root . $rel;
            if (!is_file($f)) continue;
            $d = json_decode((string)@file_get_contents($f), true);
            if (!is_array($d)) continue;
            $rows = $d['articles'] ?? $d;
            foreach ((array)$rows as $r) {
                $s = is_array($r) ? ($r['slug'] ?? '') : (is_string($r) ? $r : '');
                if ($s !== '' && strtolower($s) === $target) return true;
            }
        }
        return false;
    }
}

if (!function_exists('ci_flag')) {
    /** Coerce an override value (bool | 'on'|'off'|'inherit'|'' | null) to a bool, else the default. */
    function ci_flag($v, bool $default): bool {
        if ($v === null || $v === '' || $v === 'inherit') return $default;
        if (is_bool($v)) return $v;
        if (in_array($v, ['on', '1', 1, 'true', true], true))  return true;
        if (in_array($v, ['off', '0', 0, 'false', false], true)) return false;
        return $default;
    }
}

if (!function_exists('ci_defaults')) {
    /** Site defaults for a content-type, falling back to the built-ins. */
    function ci_defaults(array $ss, bool $isArticle): array {
        $ci  = $ss['content_injection'] ?? [];
        $key = $isArticle ? 'article' : 'page';
        $d   = is_array($ci[$key] ?? null) ? $ci[$key] : [];
        $cols = (int)($d['columns'] ?? 0);
        // Built-in default is OFF for both providers (opt-in per site) so a fleet deploy
        // never injects into a site that hasn't configured it. Sites enable via the
        // PageManager Right-Column Defaults panel (content_injection in site-settings.json).
        return [
            'affiliate' => array_key_exists('affiliate', $d) ? (bool)$d['affiliate'] : false,
            'ucs'       => array_key_exists('ucs', $d)       ? (bool)$d['ucs']       : false,
            'columns'   => ($cols >= 1 && $cols <= 3) ? $cols : 1,
        ];
    }
}

if (!function_exists('ci_resolve')) {
    /** Effective {affiliate,ucs} for a page: per-item override else the type default. */
    function ci_resolve(array $pageData, bool $isArticle, ?array $ss = null): array {
        $ss  = $ss ?? ci_site_settings();
        $def = ci_defaults($ss, $isArticle);
        $cols = (int)($pageData['inject_columns'] ?? 0);
        return [
            'affiliate' => ci_flag($pageData['inject_affiliate'] ?? null, $def['affiliate']),
            'ucs'       => ci_flag($pageData['inject_ucs'] ?? null, $def['ucs']),
            'columns'   => ($cols >= 1 && $cols <= 3) ? $cols : $def['columns'],
        ];
    }
}

if (!function_exists('ci_ucs_slug')) {
    /** Which content stack the UCS provider injects. Canonical home is
     *  content_injection.ucs_slug (set in PageManager Right-Column Defaults);
     *  falls back to the legacy blog.global_stack.slug, then 'ucs'. */
    function ci_ucs_slug(array $ss): string {
        $slug = (string)($ss['content_injection']['ucs_slug'] ?? $ss['blog']['global_stack']['slug'] ?? 'ucs');
        $slug = preg_replace('/[^a-z0-9_-]/i', '', $slug);
        return $slug !== '' ? $slug : 'ucs';
    }
}

if (!function_exists('ci_build_html')) {
    /**
     * Build the injected right-column HTML (raw shortcodes; the CALLER runs apply_shortcodes()).
     * Order: UCS first, then Affiliate — both above the page's own content.
     */
    function ci_build_html(array $flags, ?array $ss = null): string {
        $ss   = $ss ?? ci_site_settings();
        $cols = (int)($flags['columns'] ?? 1);
        if ($cols < 1 || $cols > 3) $cols = 1;
        $out = '';
        if (!empty($flags['ucs'])) {
            $out .= '<div class="ci-inject ci-inject--ucs">[[stack:' . ci_ucs_slug($ss) . ' cols="' . $cols . '"]]</div>';
        }
        if (!empty($flags['affiliate'])) {
            $out .= '<div class="ci-inject ci-inject--affiliate">'
                  . '<h3 class="ci-inject__heading">Recommended</h3>'
                  . '[[affiliate-products columns="' . $cols . '" limit="6"]]'
                  . '</div>';
        }
        return $out;
    }
}

if (!function_exists('ci_resolve_slug')) {
    /** Effective {affiliate,ucs,columns} for a page slug — loads its page JSON then resolves. */
    function ci_resolve_slug(string $siteRoot, string $slug, bool $isArticle, ?array $ss = null): array {
        $f  = $siteRoot . '/admin/data/pages/' . $slug . '/' . $slug . '.json';
        $pd = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        return ci_resolve($pd, $isArticle, $ss);
    }
}

if (!function_exists('ci_set_item_override')) {
    /** Patch a single per-item injection override into a page JSON (used by the card quick
     *  toggles). $field ∈ affiliate|ucs (→ inject_affiliate|inject_ucs), $value ∈ on|off|inherit. */
    function ci_set_item_override(string $siteRoot, string $slug, string $field, string $value): bool {
        $slug = preg_replace('/[^a-z0-9_\-]/i', '', $slug);
        if ($slug === '' || !in_array($field, ['affiliate', 'ucs'], true)) return false;
        if (!in_array($value, ['on', 'off', 'inherit'], true)) return false;
        $f = $siteRoot . '/admin/data/pages/' . $slug . '/' . $slug . '.json';
        if (!is_file($f)) return false;
        $pd = json_decode((string)@file_get_contents($f), true);
        if (!is_array($pd)) return false;
        $pd['inject_' . $field] = $value;
        $tmp = $f . '.tmp';
        if (file_put_contents($tmp, json_encode($pd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) return false;
        return @rename($tmp, $f);
    }
}

if (!function_exists('ci_strip_affiliate_shortcodes')) {
    /** Remove any stored affiliate-products shortcodes from HTML (used to de-dupe the
     *  old auto-generated right-column copy when the Affiliate provider is active). */
    function ci_strip_affiliate_shortcodes(string $html): string {
        return preg_replace('/\[\[\s*affiliate-products?\b[^\]]*\]\]/i', '', $html) ?? $html;
    }
}
