<?php
/**
 * Renderer: Blog Archive + Single Article
 * Shortcode: [[blog]]
 *
 * Archive view (default): Grid of article cards linking to /blog/{slug}
 * Single view: Pass ?a={slug} in URL — renders full article
 *
 * Articles stored in: admin/data/articles/{slug}.json
 * Fields: slug, title, meta_description, category, tags[], published_date,
 *         hero_image_url, body_html, author, read_time, is_published
 */
declare(strict_types=1);

if (!function_exists('render_blog')) {
    function render_blog(array $attrs = []): string
    {
        $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : (realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
        $articlesDir = $siteRoot . '/admin/data/articles';

        // Single article view — triggered by ?a= param
        $articleSlug = isset($_GET['a']) ? preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['a']))) : '';
        if ($articleSlug !== '') {
            return _blog_single($articlesDir, $articleSlug);
        }

        // Archive view
        $limit    = max(1, (int)($attrs['limit'] ?? 100));
        $category = $attrs['category'] ?? '';

        if (!is_dir($articlesDir)) {
            return '<p class="blog-empty">No articles yet.</p>';
        }

        $files = glob($articlesDir . '/*.json') ?: [];
        $articles = [];
        foreach ($files as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data) || empty($data['title'])) continue;
            if (empty($data['is_published'])) continue;
            if ($category !== '' && ($data['category'] ?? '') !== $category) continue;
            $articles[] = $data;
        }

        if (empty($articles)) {
            return '<p class="blog-empty">No articles published yet.</p>';
        }

        usort($articles, function ($a, $b) {
            return strcmp($b['published_date'] ?? '', $a['published_date'] ?? '');
        });
        $articles = array_slice($articles, 0, $limit);

        return _blog_archive($articles);
    }

    function _blog_archive(array $articles): string
    {
        $out = _blog_styles();
        $out .= '<div class="blog-archive">';

        // Category filter tabs
        $cats = [];
        foreach ($articles as $a) {
            $cat = $a['category'] ?? '';
            if ($cat !== '') $cats[$cat] = true;
        }
        if (count($cats) > 1) {
            $out .= '<div class="blog-cats">';
            $out .= '<button class="blog-cat-btn blog-cat-btn--active" data-cat="all" onclick="blogFilter(\'all\',this)">All</button>';
            foreach (array_keys($cats) as $cat) {
                $label = ucwords(str_replace(['-', '_'], ' ', $cat));
                $out .= '<button class="blog-cat-btn" data-cat="' . htmlspecialchars($cat) . '" onclick="blogFilter(\'' . htmlspecialchars($cat, ENT_QUOTES) . '\',this)">'
                      . htmlspecialchars($label) . '</button>';
            }
            $out .= '</div>';
        }

        $out .= '<div class="blog-grid" id="blog-grid">';
        foreach ($articles as $a) {
            $slug    = htmlspecialchars($a['slug'] ?? '');
            $title   = htmlspecialchars($a['title'] ?? 'Untitled');
            $cat     = $a['category'] ?? '';
            $catLabel = ucwords(str_replace(['-', '_'], ' ', $cat));
            $hero    = $a['hero_image_url'] ?? '';
            $desc    = htmlspecialchars(substr(strip_tags($a['meta_description'] ?? $a['body_html'] ?? ''), 0, 130));
            $date    = $a['published_date'] ?? '';
            $dateFmt = $date !== '' ? date('M j, Y', strtotime($date)) : '';
            $readTime = (int)($a['read_time'] ?? 4);

            $out .= '<article class="blog-card" data-cat="' . htmlspecialchars($cat) . '">';
            if ($hero) {
                $out .= '<a href="/blog/' . $slug . '" class="blog-card__img-wrap">'
                      . '<img src="' . htmlspecialchars($hero) . '" alt="' . $title . '" loading="lazy" class="blog-card__img">'
                      . '</a>';
            }
            $out .= '<div class="blog-card__body">';
            if ($cat) {
                $out .= '<span class="blog-card__cat">' . htmlspecialchars($catLabel) . '</span>';
            }
            $out .= '<h2 class="blog-card__title"><a href="/blog/' . $slug . '">' . $title . '</a></h2>';
            if ($desc) {
                $out .= '<p class="blog-card__excerpt">' . $desc . '…</p>';
            }
            $out .= '<div class="blog-card__meta">';
            if ($dateFmt) $out .= '<span>' . $dateFmt . '</span>';
            $out .= '<span>' . $readTime . ' min read</span>';
            $out .= '</div>';
            $out .= '<a href="/blog/' . $slug . '" class="blog-card__read-more">Read More →</a>';
            $out .= '</div></article>';
        }

        $out .= '</div></div>';
        $out .= _blog_filter_script();
        return $out;
    }

    function _blog_single(string $articlesDir, string $slug): string
    {
        $file = $articlesDir . '/' . $slug . '.json';
        if (!is_file($file)) {
            return '<div class="blog-not-found"><h2>Article not found</h2><p><a href="/blog">← Back to Blog</a></p></div>';
        }

        $a = json_decode((string)file_get_contents($file), true);
        if (!is_array($a) || empty($a['is_published'])) {
            return '<div class="blog-not-found"><h2>Article not available</h2><p><a href="/blog">← Back to Blog</a></p></div>';
        }

        $title   = htmlspecialchars($a['title'] ?? 'Article');
        $cat     = $a['category'] ?? '';
        $catLabel = ucwords(str_replace(['-', '_'], ' ', $cat));
        $hero    = $a['hero_image_url'] ?? '';
        $date    = $a['published_date'] ?? '';
        $dateFmt = $date !== '' ? date('F j, Y', strtotime($date)) : '';
        $readTime = (int)($a['read_time'] ?? 4);
        $bodyHtml = $a['body_html'] ?? '';
        $author  = $a['author'] ?? 'Shaggahs.com';
        $metaDesc = htmlspecialchars($a['meta_description'] ?? '');

        // Set OG/meta for this article
        if (!empty($a['hero_image_url'])) $GLOBALS['PAGE_OG_IMAGE'] = $a['hero_image_url'];
        if (!empty($a['meta_description'])) $GLOBALS['PAGE_OG_DESCRIPTION'] = $a['meta_description'];
        $GLOBALS['PAGE_OG_TITLE'] = ($a['title'] ?? '') . ' — Shaggahs';

        $out = _blog_styles();
        $out .= '<article class="blog-single">';

        // Back link
        $out .= '<div class="blog-single__back"><a href="/blog">← Back to Blog</a></div>';

        // Hero
        if ($hero) {
            $out .= '<div class="blog-single__hero"><img src="' . htmlspecialchars($hero) . '" alt="' . $title . '" class="blog-single__hero-img" onerror="this.parentNode&&this.parentNode.remove()"></div>';
        }

        // Header
        $out .= '<header class="blog-single__header">';
        if ($cat) {
            $out .= '<span class="blog-card__cat">' . htmlspecialchars($catLabel) . '</span>';
        }
        $out .= '<h1 class="blog-single__title">' . $title . '</h1>';
        $out .= '<div class="blog-single__meta">';
        if ($dateFmt) $out .= '<span>' . $dateFmt . '</span>';
        $out .= '<span>' . $readTime . ' min read</span>';
        if ($author) $out .= '<span>By ' . htmlspecialchars($author) . '</span>';
        $out .= '</div>';
        $out .= '</header>';

        // Body
        $out .= '<div class="blog-single__body prose">' . $bodyHtml . '</div>';

        // Footer nav
        $out .= '<div class="blog-single__footer"><a href="/blog" class="blog-card__read-more">← Back to Blog</a></div>';

        // Article schema
        $schemaDate = $date ?: date('Y-m-d');
        $out .= '<script type="application/ld+json">' . json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $a['title'] ?? '',
            'description' => $a['meta_description'] ?? '',
            'image' => $a['hero_image_url'] ?? '',
            'datePublished' => $schemaDate,
            'author' => ['@type' => 'Organization', 'name' => 'Shaggahs'],
            'publisher' => ['@type' => 'Organization', 'name' => 'Shaggahs', 'url' => 'https://shaggahs.com'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

        $out .= '</article>';
        return $out;
    }

    function _blog_styles(): string
    {
        static $done = false;
        if ($done) return '';
        $done = true;
        return '<style>
/* ── Blog Archive ── */
.blog-archive { max-width: 1200px; margin: 0 auto; padding: 0 16px 60px; }
.blog-cats { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 32px; }
.blog-cat-btn { padding: 7px 18px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.15); background: transparent; color: #aaa; font-size: 0.82rem; cursor: pointer; transition: all 0.15s; font-family: inherit; }
.blog-cat-btn:hover, .blog-cat-btn--active { background: #2b9348; border-color: #2b9348; color: #fff; }
.blog-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
@media (max-width: 900px) { .blog-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 560px) { .blog-grid { grid-template-columns: 1fr; } }
.blog-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; display: flex; flex-direction: column; }
.blog-card:hover { transform: translateY(-3px); box-shadow: 0 8px 32px rgba(0,0,0,0.35); }
.blog-card__img-wrap { display: block; aspect-ratio: 16/9; overflow: hidden; }
.blog-card__img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; }
.blog-card:hover .blog-card__img { transform: scale(1.04); }
.blog-card__body { padding: 20px; display: flex; flex-direction: column; flex: 1; }
.blog-card__cat { display: inline-block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #2b9348; background: rgba(43,147,72,0.12); border: 1px solid rgba(43,147,72,0.25); border-radius: 4px; padding: 2px 8px; margin-bottom: 10px; }
.blog-card__title { margin: 0 0 10px; font-size: 1.05rem; line-height: 1.4; }
.blog-card__title a { color: #e8e8e8; text-decoration: none; }
.blog-card__title a:hover { color: #fff; }
.blog-card__excerpt { font-size: 0.83rem; color: #999; line-height: 1.55; margin: 0 0 14px; flex: 1; }
.blog-card__meta { display: flex; gap: 12px; font-size: 0.72rem; color: #666; margin-bottom: 14px; }
.blog-card__read-more { display: inline-block; font-size: 0.8rem; font-weight: 700; color: #2b9348; text-decoration: none; }
.blog-card__read-more:hover { color: #3dba5c; }
/* ── Blog Single ── */
.blog-single { max-width: 780px; margin: 0 auto; padding: 0 20px 60px; }
.blog-single__back { margin-bottom: 24px; }
.blog-single__back a { color: #888; font-size: 0.85rem; text-decoration: none; }
.blog-single__back a:hover { color: #ccc; }
.blog-single__hero { border-radius: 12px; overflow: hidden; margin-bottom: 32px; max-height: 720px; display: flex; justify-content: center; align-items: center; background: rgba(0,0,0,0.35); }
.blog-single__hero-img { max-width: 100%; max-height: 720px; width: auto; height: auto; object-fit: contain; display: block; }
.blog-single__header { margin-bottom: 32px; }
.blog-single__title { font-size: clamp(1.6rem,4vw,2.4rem); line-height: 1.25; margin: 10px 0 16px; color: #fff; }
.blog-single__meta { display: flex; flex-wrap: wrap; gap: 16px; font-size: 0.8rem; color: #777; }
.blog-single__body { font-size: 1.02rem; line-height: 1.75; color: #c8d0dc; }
.blog-single__body img { max-width: 100%; height: auto; max-height: 70vh; object-fit: contain; border-radius: 8px; display: block; }
.blog-single__body figure { margin: 24px 0; max-width: 100%; }
.blog-single__body figcaption { font-size: 0.78rem; color: #777; margin-top: 6px; line-height: 1.4; }
.blog-single__body h2 { font-size: 1.45rem; color: #fff; margin: 40px 0 14px; }
.blog-single__body h3 { font-size: 1.15rem; color: #e0e0e0; margin: 30px 0 10px; }
.blog-single__body p { margin: 0 0 18px; }
.blog-single__body ul, .blog-single__body ol { margin: 0 0 18px 22px; }
.blog-single__body li { margin-bottom: 6px; }
.blog-single__body a { color: #2b9348; }
.blog-single__body a:hover { color: #3dba5c; }
.blog-single__body strong { color: #e8e8e8; }
.blog-single__body blockquote { border-left: 3px solid #2b9348; padding-left: 16px; color: #aaa; font-style: italic; margin: 24px 0; }
.blog-single__footer { margin-top: 48px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.08); }
.blog-not-found { text-align: center; padding: 60px 20px; color: #888; }
.blog-empty { text-align: center; padding: 40px; color: #888; }
.blog-card[style*="display: none"] { display: none !important; }
</style>';
    }

    function _blog_filter_script(): string
    {
        return '<script>
function blogFilter(cat, btn) {
    document.querySelectorAll(".blog-cat-btn").forEach(function(b){ b.classList.remove("blog-cat-btn--active"); });
    btn.classList.add("blog-cat-btn--active");
    document.querySelectorAll(".blog-card").forEach(function(c){
        c.style.display = (cat === "all" || c.dataset.cat === cat) ? "" : "none";
    });
}
</script>';
    }
}
