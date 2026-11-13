<?php
/**
 * SEO head helpers — one place for the browser-tab name, page titles, descriptions,
 * canonical addresses and social-image sizes.
 * @file    /includes/seo_head.php
 *
 * Why it exists: page.php read the page title from a key the editor never writes ("title";
 * PageManager saves "page_title"), so every inner page showed its slug; the site description
 * was read only from site-settings.json while many sites keep it in meta_settings.json;
 * no page had a canonical address; and the social-image tags always claimed 1200x630.
 * Display settings are NOT changed here: a letter-spaced site_title still draws the header;
 * only the <title>, og: and twitter: text use the plain name.
 */
declare(strict_types=1);

if (!function_exists('seo_plain')) {

/** "M I A M I   A N D" -> "MIAMI AND"; "m y s i t e " -> "mysite". Other text is only trimmed. */
function seo_plain(string $s): string {
    $s = trim(preg_replace('/[\x{00A0}\s]+$/u', '', $s) ?? $s);
    if ($s === '') return '';
    $parts = preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$s];
    $tokens = []; $gaps = [];
    foreach ($parts as $i => $p) { if ($i % 2) $gaps[] = mb_strlen($p); else $tokens[] = $p; }
    $single = 0;
    foreach ($tokens as $t) if (mb_strlen($t) === 1) $single++;
    if (count($tokens) >= 4 && $single >= 0.8 * count($tokens) && $gaps) {
        // the narrowest gap is the space between letters; anything wider separates words
        $letterGap = min($gaps); $out = '';
        foreach ($tokens as $i => $t) {
            $out .= $t;
            if ($i < count($gaps)) $out .= ($gaps[$i] > $letterGap) ? ' ' : '';
        }
        $s = $out;
    }
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

function seo_host(): string {
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $h = preg_replace('/:\d+$/', '', $h) ?? $h;
    return preg_replace('/^www\./', '', $h) ?? $h;
}

function seo_json_file(string $path): array {
    if (!is_file($path)) return [];
    $j = json_decode((string)@file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

/** site-settings.json first (what the admin writes), meta_settings.json as a read-only fallback. */
function seo_settings(): array {
    static $s = null;
    if ($s !== null) return $s;
    $ss = $GLOBALS['SITE_SETTINGS'] ?? null;
    if (!is_array($ss) || !$ss) $ss = seo_json_file(SITE_ROOT . '/admin/data/site-settings.json');
    $ms = seo_json_file(SITE_ROOT . '/admin/data/meta_settings.json');
    return $s = ['ss' => $ss, 'ms' => $ms];
}

/** The site's plain name for the browser tab and social cards. */
function seo_site_name(): string {
    static $n = null;
    if ($n !== null) return $n;
    $st = seo_settings();
    $host = seo_host();
    $cands = [
        (string)($st['ss']['seo_site_name'] ?? ''),   // optional override, if a site ever needs one
        (string)($st['ss']['site_title'] ?? ''),
        (string)($st['ss']['site_name'] ?? ''),
        (string)($st['ms']['site_title'] ?? ''),
        (string)($st['ms']['site_name'] ?? ''),
    ];
    $first = '';
    foreach ($cands as $c) {
        $c = seo_plain($c);
        if ($c === '') continue;
        if ($first === '') $first = $c;
        // a bare hostname is the last resort: keep looking for a real name
        if (strtolower($c) === $host || strtolower($c) === 'www.' . $host) continue;
        return $n = $c;
    }
    return $n = ($first !== '' ? $first : $host);
}

/** Collapse whitespace, drop tags; '' when there is nothing real. */
function seo_clean_text(string $s): string {
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** Site-wide description: site-settings, then meta_settings. Template filler is skipped. */
function seo_site_desc(): string {
    $st = seo_settings();
    foreach ([$st['ss']['meta_description'] ?? '', $st['ss']['site_description'] ?? '', $st['ms']['meta_description'] ?? ''] as $d) {
        $d = seo_clean_text((string)$d);
        if ($d === '' || $d === 'A website for artists and musicians.') continue;
        return $d;
    }
    return '';
}

/** A page's own description ('' if it has none). */
function seo_page_desc(array $page): string {
    foreach (['description', 'meta_description'] as $k) {
        $d = seo_clean_text((string)($page[$k] ?? ''));
        if ($d !== '') return $d;
    }
    return '';
}

/** Drop a trailing " | Site" / " — Site" / " - Site" the editor may have typed into a page title. */
function seo_strip_site_suffix(string $t, string $site): string {
    if ($site === '') return $t;
    $q = preg_quote($site, '/');
    $out = preg_replace('/\s*(\||—|–|-|:)\s*' . $q . '\s*$/iu', '', $t);
    return trim($out === null || $out === '' ? $t : $out);
}

/** Inner-page title text (without the site name): title, then page_title, then the slug made readable. */
function seo_page_name(array $page, string $slug): string {
    $site = seo_site_name();
    foreach (['title', 'page_title'] as $k) {   // an explicit 'title' is set on purpose; the editor writes page_title
        $t = seo_plain((string)($page[$k] ?? ''));
        if ($t === '') continue;
        $t = seo_strip_site_suffix($t, $site);
        if ($t !== '' && strcasecmp($t, $slug) !== 0) return $t;
    }
    $t = trim(str_replace(['-', '_'], ' ', $slug));
    return $t === '' ? $site : mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
}

/** The full <title>. Home: the site name, or the home page's own title when it already carries the name. */
function seo_full_title(array $page, string $slug, bool $isHome): string {
    $site = seo_site_name();
    if ($isHome) {
        $own = seo_plain((string)($page['page_title'] ?? ''));
        if ($own !== '' && mb_stripos($own, $site) !== false && strcasecmp($own, $site) !== 0
            && !preg_match('/\|\s*' . preg_quote($site, '/') . '\s*$/iu', $own)) return $own;
        return $site;
    }
    $name = seo_page_name($page, $slug);
    return (strcasecmp($name, $site) === 0) ? $site : ($name . ' — ' . $site);
}

/** Is there a live /blog/{slug} article with this slug? Then that is the canonical copy. */
function seo_article_live(string $slug): bool {
    if ($slug === '') return false;
    $d = seo_json_file(SITE_ROOT . '/admin/data/ArticlesManager/articles.json');
    foreach ((array)($d['articles'] ?? $d) as $a) {
        if (!is_array($a) || ($a['slug'] ?? '') !== $slug) continue;
        if (isset($a['published']) && !$a['published'] && empty($a['is_published'])) return false;
        return trim((string)($a['body_html'] ?? '')) !== '';
    }
    return false;
}

/** https://host/ for home, https://host/slug otherwise (or /blog/slug for an article's page copy). */
function seo_canonical(string $slug, bool $isHome): string {
    $base = 'https://' . seo_host();
    if ($isHome) return $base . '/';
    if (seo_article_live($slug)) return $base . '/blog/' . rawurlencode($slug);
    return $base . '/' . rawurlencode($slug);
}

/** [w, h] of a same-site image URL, or null when it is remote or unreadable. */
function seo_image_size(string $url): ?array {
    $p = parse_url($url);
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host !== '' && preg_replace('/^www\./', '', $host) !== seo_host()) return null;
    $path = rawurldecode((string)($p['path'] ?? ''));
    if ($path === '' || strpos($path, '..') !== false) return null;
    $fs = SITE_ROOT . '/' . ltrim($path, '/');
    if (!is_file($fs)) return null;
    $sz = @getimagesize($fs);
    return ($sz && $sz[0] > 0 && $sz[1] > 0) ? [(int)$sz[0], (int)$sz[1]] : null;
}

/** Can the public fetch files under admin/data/site-assets/? (admin/data is denied unless .htaccess makes an exception.) */
function seo_site_assets_public(): bool {
    $ht = (string)@file_get_contents(SITE_ROOT . '/admin/data/.htaccess');
    if ($ht === '') return true;                     // no rules file: nothing blocks it
    return stripos($ht, 'site-assets') !== false;    // the exception names the folder
}


/** a description search engines show whole — about 160 characters, cut at a word. */
function seo_cap(string $d, int $max = 160): string {
    $d = trim($d);
    if (mb_strlen($d) <= $max) return $d;
    $cut = mb_substr($d, 0, $max - 1);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > (int)($max * 0.6)) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, " ,;:\u{2014}\u{2013}-") . "\u{2026}";
}

/**
 * a page with no description of its own gets its first real paragraph (60+ characters,
 * html-blocks inlined one level), not the site-wide one, so pages stop sharing one description.
 */
function seo_body_excerpt(array $page, string $title = ''): string {
    $html = '';
    foreach ((array)($page['components'] ?? []) as $c) {
        if (is_array($c)) $html .= ' ' . (string)($c['content'] ?? '');
        elseif (is_string($c)) $html .= ' ' . $c;
    }
    if (trim($html) === '') $html = (string)($page['content'] ?? '');
    $html = (string)preg_replace_callback('/\[\[html-block:([a-z0-9_-]+)\]\]/i', function ($m) {
        $f = SITE_ROOT . '/admin/data/html-blocks/' . strtolower($m[1]) . '.json';
        $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return is_array($d) ? (string)($d['html'] ?? '') : '';
    }, $html);
    $html = (string)preg_replace('#<(script|style|nav|footer|form|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = (string)preg_replace('/\[\[[^\]]*\]\]/', ' ', $html);
    if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $m)) {
        foreach ($m[1] as $para) {
            $t = seo_clean_text($para);
            if (mb_strlen($t) >= 60 && strcasecmp($t, trim($title)) !== 0) return seo_cap($t);
        }
    }
    return '';
}

}
