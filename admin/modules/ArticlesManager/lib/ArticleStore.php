<?php
/**
 * ArticleStore — canonical article storage layer.
 *
 * Storage model: single JSON file at admin/data/ArticlesManager/articles.json
 *   { "articles": [ {...}, ... ], "updated_at": "..." }
 *
 * Why single-file: article counts are small (tens, maybe low hundreds per
 * site), JSON parse is fast, one atomic rename-on-write is safer than
 * shard-per-file when many readers are hitting the store. If a site ever
 * crosses 1k articles we shard — but none will.
 *
 * Article schema:
 *   slug            — URL-safe identifier (unique primary key)
 *   title           — display title
 *   eyebrow         — category label / kicker (UPPERCASE)
 *   excerpt         — short summary
 *   body_html       — full HTML body
 *   hero_image      — path to hero image (relative or absolute)
 *   tags            — array of tag slugs (shows, topics)
 *   category        — primary category slug
 *   author          — optional author name
 *   author_slug     — optional author slug
 *   published       — bool, false = draft, true = visible
 *   pinned          — bool, pinned articles sort first
 *   featured_home   — bool, THE one featured article for the /articles hero.
 *                     Setting true on any article clears it on all others.
 *   date            — ISO8601 published date
 *   updated_at      — ISO8601 last edit
 *   source          — 'pipeline' | 'hand-curated' | 'manual' | 'migration'
 *   external_url    — optional, for content that lives elsewhere
 *
 * @module  ArticlesManager
 * @version 1.0.0
 */
declare(strict_types=1);

namespace ArticlesManager;

class ArticleStore
{
    private string $dataDir;
    private string $storeFile;
    private string $archiveDir;

    public function __construct(string $siteRoot)
    {
        $this->dataDir    = $siteRoot . '/admin/data/ArticlesManager';
        $this->storeFile  = $this->dataDir . '/articles.json';
        $this->archiveDir = $this->dataDir . '/_archive';
        if (!is_dir($this->dataDir))   @mkdir($this->dataDir,   0775, true);
        if (!is_dir($this->archiveDir)) @mkdir($this->archiveDir, 0775, true);
    }

    // ─── Read ───────────────────────────────────────────────────────

    /** All articles — newest first, pinned override. */
    public function all(array $opts = []): array
    {
        $store = $this->loadStore();
        $items = $store['articles'] ?? [];

        if (!empty($opts['published_only'])) {
            $items = array_values(array_filter($items, fn($a) => !empty($a['published'])));
        }
        if (!empty($opts['category'])) {
            $cat = $opts['category'];
            $items = array_values(array_filter($items, fn($a) => ($a['category'] ?? '') === $cat));
        }
        if (!empty($opts['tag'])) {
            $tag = $opts['tag'];
            $items = array_values(array_filter($items, fn($a) => in_array($tag, $a['tags'] ?? [], true)));
        }
        if (!empty($opts['search'])) {
            $q = strtolower($opts['search']);
            $items = array_values(array_filter($items, function ($a) use ($q) {
                foreach (['title', 'eyebrow', 'excerpt', 'body_html'] as $f) {
                    if (stripos((string)($a[$f] ?? ''), $q) !== false) return true;
                }
                foreach ($a['tags'] ?? [] as $t) {
                    if (stripos($t, $q) !== false) return true;
                }
                return false;
            }));
        }

        // Sort: pinned first, then date desc
        usort($items, function ($a, $b) {
            $pa = !empty($a['pinned']) ? 1 : 0;
            $pb = !empty($b['pinned']) ? 1 : 0;
            if ($pa !== $pb) return $pb - $pa;
            return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
        });

        if (!empty($opts['limit'])) {
            $items = array_slice($items, 0, (int)$opts['limit']);
        }
        return $items;
    }

    /** One article by slug. */
    public function get(string $slug): ?array
    {
        $items = ($this->loadStore())['articles'] ?? [];
        foreach ($items as $a) {
            if (($a['slug'] ?? '') === $slug) return $a;
        }
        return null;
    }

    /** Count + quick stats. */
    public function stats(): array
    {
        $items = ($this->loadStore())['articles'] ?? [];
        $pub = 0; $draft = 0; $pinned = 0;
        foreach ($items as $a) {
            if (!empty($a['published'])) $pub++; else $draft++;
            if (!empty($a['pinned']))    $pinned++;
        }
        $tags = [];
        foreach ($items as $a) {
            foreach ($a['tags'] ?? [] as $t) {
                $tags[$t] = ($tags[$t] ?? 0) + 1;
            }
        }
        arsort($tags);
        return [
            'total'      => count($items),
            'published'  => $pub,
            'draft'      => $draft,
            'pinned'     => $pinned,
            'top_tags'   => array_slice($tags, 0, 15, true),
        ];
    }

    // ─── Write ──────────────────────────────────────────────────────

    /**
     * Save (insert or update). Upsert by slug. Returns the stored record.
     */
    public function save(array $article): array
    {
        $article = $this->normalize($article);
        $slug    = $article['slug'];
        if ($slug === '') {
            throw new \InvalidArgumentException('ArticleStore::save — slug is required');
        }

        $store = $this->loadStore();
        $items = $store['articles'] ?? [];

        // Enforce: only one article can be featured_home at a time.
        // If this save sets it true, clear it on every other article.
        if (!empty($article['featured_home'])) {
            foreach ($items as $i => $existing) {
                if (($existing['slug'] ?? '') !== $slug && !empty($existing['featured_home'])) {
                    $items[$i]['featured_home'] = false;
                    $items[$i]['updated_at']    = gmdate('c');
                }
            }
        }

        $found = false;
        foreach ($items as $i => $existing) {
            if (($existing['slug'] ?? '') === $slug) {
                // Merge: new fields win, preserve untouched fields
                $items[$i] = array_merge($existing, $article);
                $items[$i]['updated_at'] = gmdate('c');
                $article = $items[$i];
                $found = true;
                break;
            }
        }
        if (!$found) {
            if (empty($article['date']))       $article['date'] = gmdate('c');
            if (empty($article['updated_at'])) $article['updated_at'] = gmdate('c');
            $items[] = $article;
        }

        $store['articles']   = $items;
        $store['updated_at'] = gmdate('c');
        $this->writeStore($store);
        return $article;
    }

    /** Return the single article flagged as featured on home, or null. Published only. */
    public function getFeaturedHome(): ?array
    {
        $store = $this->loadStore();
        foreach ($store['articles'] ?? [] as $a) {
            if (!empty($a['featured_home']) && !empty($a['published'])) return $a;
        }
        return null;
    }

    /** Delete by slug. Archives the record first. */
    public function delete(string $slug): bool
    {
        $store = $this->loadStore();
        $items = $store['articles'] ?? [];
        $removed = null;
        foreach ($items as $i => $a) {
            if (($a['slug'] ?? '') === $slug) {
                $removed = $a;
                array_splice($items, $i, 1);
                break;
            }
        }
        if (!$removed) return false;

        $archivePath = $this->archiveDir . '/' . $slug . '-' . date('Ymd-His') . '.json';
        file_put_contents($archivePath, json_encode($removed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $store['articles']   = $items;
        $store['updated_at'] = gmdate('c');
        $this->writeStore($store);
        return true;
    }

    /** Bulk ingest — used by pipelines + migration. Upsert each. */
    public function ingestBatch(array $articles): int
    {
        $n = 0;
        foreach ($articles as $a) {
            try { $this->save($a); $n++; } catch (\Throwable $e) { /* skip bad */ }
        }
        return $n;
    }

    // ─── Internals ──────────────────────────────────────────────────

    private function loadStore(): array
    {
        if (!is_file($this->storeFile)) {
            return ['articles' => [], 'updated_at' => null];
        }
        $raw = @file_get_contents($this->storeFile);
        if ($raw === false) return ['articles' => [], 'updated_at' => null];
        $data = json_decode($raw, true);
        if (!is_array($data)) return ['articles' => [], 'updated_at' => null];
        if (!isset($data['articles']) || !is_array($data['articles'])) $data['articles'] = [];
        return $data;
    }

    private function writeStore(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp  = $this->storeFile . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $this->storeFile);
        @chmod($this->storeFile, 0664);
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            @chown($this->storeFile, 'www-data');
            @chgrp($this->storeFile, 'www-data');
        }
    }

    private function normalize(array $a): array
    {
        $slug = $a['slug'] ?? '';
        if ($slug === '' && !empty($a['title'])) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim((string)$a['title'])));
            $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        }
        return [
            'slug'         => $slug,
            'title'        => (string)($a['title']        ?? ''),
            'eyebrow'      => strtoupper(trim((string)($a['eyebrow'] ?? ''))),
            'excerpt'      => (string)($a['excerpt']      ?? ''),
            'body_html'    => (string)($a['body_html']    ?? ''),
            'hero_image'   => (string)($a['hero_image']   ?? ''),
            'tags'         => array_values(array_filter(array_map('trim', (array)($a['tags'] ?? [])))),
            'category'     => (string)($a['category']     ?? ''),
            'author'       => (string)($a['author']       ?? ''),
            'author_slug'  => (string)($a['author_slug']  ?? ''),
            'published'    => !empty($a['published']),
            'pinned'       => !empty($a['pinned']),
            'featured_home' => !empty($a['featured_home']),
            'date'         => (string)($a['date']         ?? ''),
            'updated_at'   => (string)($a['updated_at']   ?? ''),
            'source'       => (string)($a['source']       ?? 'manual'),
            'external_url' => (string)($a['external_url'] ?? ''),
        ];
    }

    public function getStoreFile(): string { return $this->storeFile; }
    public function getDataDir(): string { return $this->dataDir; }
}
