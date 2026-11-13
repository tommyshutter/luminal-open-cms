<?php
/**
 * Migrator — one-shot ingestion of legacy article storage into ArticlesManager.
 *
 * Sources (in order of trust, higher trumps lower on slug collision):
 *   1. admin/data/ArticlesManager/articles.json       (already-ingested, skip)
 *   2. admin/data/Articles/index.json                  (pipeline output)
 *   3. admin/data/pages/{slug}/{slug}.json             (PageManager bodies)
 *   4. admin/data/article-archive/*.json               (old per-file archive)
 *   5. Hand-curated cards in admin/data/pages/articles/articles.json
 *      main_content (article-body style)
 *
 * Strategy: union of all sources, prefer pages/{slug}/{slug}.json for body_html
 * because that's what the spoke actually served to users.
 *
 * Safe to run multiple times — save() is upsert by slug.
 *
 * @module  ArticlesManager
 * @version 1.0.0
 */
declare(strict_types=1);

namespace ArticlesManager;

require_once __DIR__ . '/ArticleStore.php';

class Migrator
{
    private string $siteRoot;
    private ArticleStore $store;
    private array $report = ['pipeline_index' => 0, 'page_bodies' => 0, 'article_archive' => 0, 'hand_curated' => 0, 'total_saved' => 0, 'skipped_pages' => [], 'notes' => []];

    public function __construct(string $siteRoot)
    {
        $this->siteRoot = $siteRoot;
        $this->store    = new ArticleStore($siteRoot);
    }

    public function run(): array
    {
        // Build a slug → article map, merging across sources
        $merged = [];

        // ── 1. Pipeline index (Articles/index.json) ──
        $idxPath = $this->siteRoot . '/admin/data/Articles/index.json';
        if (is_file($idxPath)) {
            $idx = json_decode((string)file_get_contents($idxPath), true);
            foreach ($idx['articles'] ?? [] as $e) {
                $slug = $e['slug'] ?? '';
                if ($slug === '') continue;
                $merged[$slug] = [
                    'slug'        => $slug,
                    'title'       => $e['title']   ?? '',
                    'eyebrow'     => strtoupper((string)($e['eyebrow'] ?? '')),
                    'excerpt'     => $e['excerpt'] ?? '',
                    'date'        => $e['date']    ?? gmdate('c'),
                    'author'      => $e['author']  ?? '',
                    'author_slug' => $e['author_slug'] ?? '',
                    'published'   => true,
                    'source'      => 'pipeline',
                ];
                $this->report['pipeline_index']++;
            }
        }

        // ── 2. Page bodies (admin/data/pages/{slug}/{slug}.json) ──
        // A page body may ENRICH an article that another source already vouches
        // for. It may NEVER ENROL a new one. We take body_html from
        // main_content.content. See vouchedArticleSlugs() for why.
        $vouched  = $this->vouchedArticleSlugs();
        $pagesDir = $this->siteRoot . '/admin/data/pages';
        if (is_dir($pagesDir)) {
            foreach (glob($pagesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $slug = basename($dir);
                // Skip obvious non-articles
                if (in_array($slug, ['home', 'articles', 'podcastscards', 'discuss-archive', 'event-page', 'youtubes', 'stacks'], true)) continue;
                $pf = $dir . '/' . $slug . '.json';
                if (!is_file($pf)) continue;
                $pageData = json_decode((string)file_get_contents($pf), true);
                if (!is_array($pageData)) continue;

                $body = $pageData['components']['main_content']['content'] ?? '';
                if ($body === '') continue;

                // THE GATE. Without it this loop enrols every page on the site.
                if (!isset($merged[$slug]) && !isset($vouched[$slug])) {
                    $this->report['skipped_pages'][] = $slug;
                    continue;
                }

                $title = $pageData['page_title'] ?? $merged[$slug]['title'] ?? $this->slugToTitle($slug);
                $heroPath = $this->siteRoot . '/media/images/articles/' . $slug . '.jpg';
                $heroUrl  = is_file($heroPath) ? '/media/images/articles/' . $slug . '.jpg' : '';

                $merged[$slug] = array_merge($merged[$slug] ?? [
                    'slug'        => $slug,
                    'excerpt'     => $pageData['meta_description'] ?? '',
                    'eyebrow'     => '',
                    'date'        => $pageData['saved_at'] ?? gmdate('c'),
                    'published'   => true,
                    'source'      => 'page-body',
                ], [
                    'title'     => $title,
                    'body_html' => $body,
                    'hero_image'=> $heroUrl,
                    'updated_at'=> $pageData['saved_at'] ?? gmdate('c'),
                ]);
                // Only count if this is a NEW contribution
                $this->report['page_bodies']++;
            }
        }

        // ── 3. article-archive/*.json (old per-file format) ──
        $arcDir = $this->siteRoot . '/admin/data/article-archive';
        if (is_dir($arcDir)) {
            foreach (glob($arcDir . '/*.json') ?: [] as $f) {
                $data = json_decode((string)file_get_contents($f), true);
                if (!is_array($data) || empty($data['title'])) continue;
                $slug = $data['slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['title']));
                $slug = trim(preg_replace('/-+/', '-', $slug), '-');
                if (!isset($merged[$slug])) {
                    $merged[$slug] = [
                        'slug'      => $slug,
                        'title'     => $data['title'],
                        'eyebrow'   => strtoupper((string)($data['article_type'] ?? $data['eyebrow'] ?? '')),
                        'excerpt'   => $data['summary'] ?? $data['excerpt'] ?? '',
                        'body_html' => $data['body_html'] ?? $data['html'] ?? $data['body'] ?? '',
                        'hero_image'=> $data['hero_image'] ?? $data['featured_image'] ?? '',
                        'date'      => $data['generated_at'] ?? $data['date'] ?? gmdate('c'),
                        'published' => true,
                        'source'    => 'article-archive',
                    ];
                    $this->report['article_archive']++;
                }
            }
        }

        // ── 4. Hand-curated cards from /articles main_content ──
        // Cinema-style: the /articles page has a hardcoded card grid of shows.
        // Parse those cards into ArticlesManager records so they become
        // first-class, editable articles.
        $handPageFile = $this->siteRoot . '/admin/data/pages/articles/articles.json';
        if (is_file($handPageFile)) {
            $hp = json_decode((string)file_get_contents($handPageFile), true);
            $html = $hp['components']['main_content']['content'] ?? '';
            if ($html && preg_match_all('~<a\s+class="ca-card[^"]*"\s+href="/page\.php\?p=([^"]+)"[^>]*>(.*?)</a>~s', $html, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $slug = $match[1];
                    $card = $match[2];
                    $title = '';
                    $eyebrow = '';
                    $excerpt = '';
                    if (preg_match('~<h2 class="ca-card-title">([^<]+)</h2>~', $card, $t)) $title = trim($t[1]);
                    if (preg_match('~<span class="ca-card-eyebrow">([^<]+)</span>~', $card, $e)) $eyebrow = trim($e[1]);
                    if (preg_match('~<p class="ca-card-desc">([^<]+)</p>~', $card, $d)) $excerpt = trim($d[1]);
                    $heroImg = '';
                    if (preg_match('~<img class="ca-card-img" src="([^"]+)"~', $card, $im)) $heroImg = trim($im[1]);
                    if ($title) {
                        // Always stamp hand-curated fields onto the record so the admin
                        // cards show the correct hero + eyebrow + pinned status. Without
                        // this the 5 show cards migrate via page-body with blank heroes
                        // because the slug doesn't map to /media/images/articles/{slug}.jpg.
                        // If a page-body already supplied body_html, preserve it.
                        $existing = $merged[$slug] ?? null;
                        $merged[$slug] = array_merge($existing ?? [
                            'slug'      => $slug,
                            'published' => true,
                            'source'    => 'hand-curated',
                            'date'      => $hp['saved_at'] ?? gmdate('c'),
                        ], [
                            'title'      => $title,
                            'eyebrow'    => strtoupper($eyebrow),
                            'excerpt'    => $excerpt,
                            'hero_image' => $heroImg,
                            'pinned'     => true,
                            'category'   => 'featured-show',
                            'tags'       => [$slug],
                        ]);
                        if ($existing && !empty($existing['body_html'])) {
                            $merged[$slug]['body_html'] = $existing['body_html'];
                        }
                        $this->report['hand_curated']++;
                    }
                }
            }
        }

        // Save everything
        $this->report['total_saved'] = $this->store->ingestBatch(array_values($merged));
        $this->report['notes'][] = 'merged ' . count($merged) . ' unique slugs from all sources';
        return $this->report;
    }

    /**
     * Slugs that an authoritative source calls an article: the per-file archive and
     * the hand-curated card grid. (The pipeline index is already in $merged by the
     * time the page pass runs.) Built BEFORE that pass so source ordering — and the
     * hand-curated hero/eyebrow stamping further down — is completely unchanged.
     *
     * WHY THIS EXISTS (2026-08-24). The page pass used to claim, in its own comment,
     * that it took only pages which "look article-ish (has eyebrow + dated
     * description)". That test was never written. What the code actually did was
     * skip a small hardcoded blocklist and then enrol EVERY page with a non-empty
     * main_content — storefronts, contact/faq/about, [[pdf-gallery]] and [[mystore]]
     * containers, iframe embeds, whole pasted <!DOCTYPE html> documents. 57 real
     * pages across 17 sites ended up in the article store, each serving duplicate
     * content at both /blog/{slug} and /?p={slug}; one site had all 10 of its
     * pages misfiled and zero genuine articles.
     *
     * Guessing article-ness from markup is precisely what failed, so this does not
     * guess. Promoting a genuine page to an article is a deliberate human action:
     * PageManager's Convert to Article toggle (__toggle_article_type), which also
     * populates body_html so /blog/{slug} renders instead of 404ing.
     */
    private function vouchedArticleSlugs(): array
    {
        $out = [];

        $arcDir = $this->siteRoot . '/admin/data/article-archive';
        if (is_dir($arcDir)) {
            foreach (glob($arcDir . '/*.json') ?: [] as $f) {
                $data = json_decode((string)file_get_contents($f), true);
                if (!is_array($data) || empty($data['title'])) continue;
                $slug = $data['slug'] ?? strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['title']));
                $slug = trim(preg_replace('/-+/', '-', $slug), '-');
                if ($slug !== '') $out[$slug] = true;
            }
        }

        $handPageFile = $this->siteRoot . '/admin/data/pages/articles/articles.json';
        if (is_file($handPageFile)) {
            $hp   = json_decode((string)file_get_contents($handPageFile), true);
            $html = $hp['components']['main_content']['content'] ?? '';
            if ($html && preg_match_all('~<a\s+class="ca-card[^"]*"\s+href="/page\.php\?p=([^"]+)"~', $html, $m)) {
                foreach ($m[1] as $slug) $out[$slug] = true;
            }
        }

        return $out;
    }

    private function slugToTitle(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }
}
