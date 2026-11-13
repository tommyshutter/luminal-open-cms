<?php
/**
 * SEO Scanner — Page meta description analysis and AI generation
 *
 * Functions:
 *   seo_scan_pages()                         — Scan all pages, classify meta status
 *   seo_generate_descriptions(array $pages)  — AI-generate meta descriptions
 *   seo_apply_description(slug, description) — Write description to page JSON
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';

/**
 * Scan all page JSONs and classify meta_description status.
 *
 * Returns array of page info sorted by severity (missing > weak > long > ok).
 */
function seo_scan_pages(?string $siteRoot = null): array
{
    $root = $siteRoot ?? (defined('SITE_ROOT') ? SITE_ROOT : '');
    $pagesDir = $root . '/admin/data/pages';
    $results = [];

    if (!is_dir($pagesDir)) return $results;

    foreach (scandir($pagesDir) as $slug) {
        if ($slug === '.' || $slug === '..') continue;
        $jsonPath = $pagesDir . '/' . $slug . '/' . $slug . '.json';
        if (!is_file($jsonPath)) continue;

        $data = json_decode((string)file_get_contents($jsonPath), true);
        if (!is_array($data)) continue;

        $desc = trim($data['meta_description'] ?? '');
        $title = $data['page_title'] ?? ucwords(str_replace('-', ' ', $slug));
        $len = mb_strlen($desc);

        // Classify
        if ($desc === '') {
            $status = 'missing';
            $severity = 0;
        } elseif ($len < 50) {
            $status = 'weak';
            $severity = 1;
        } elseif ($len > 160) {
            $status = 'long';
            $severity = 2;
        } else {
            $status = 'ok';
            $severity = 3;
        }

        // Extract plain text from main_content for AI context
        $contentHtml = $data['components']['main_content']['content'] ?? '';
        $plainText = _seo_strip_content($contentHtml);

        $results[] = [
            'slug'        => $slug,
            'title'       => $title,
            'status'      => $status,
            'severity'    => $severity,
            'description' => $desc,
            'char_count'  => $len,
            'content_preview' => mb_substr($plainText, 0, 1500),
        ];
    }

    // Sort by severity (missing first, ok last)
    usort($results, function ($a, $b) {
        return $a['severity'] <=> $b['severity'] ?: strcmp($a['slug'], $b['slug']);
    });

    return $results;
}

/**
 * Use AI to generate meta descriptions for pages with missing/weak/long status.
 *
 * @param array $pages Output from seo_scan_pages()
 * @return array [{slug, title, suggestion, char_count}]
 */
function seo_generate_descriptions(array $pages): array
{
    // Filter to only pages that need improvement
    $needsWork = array_filter($pages, fn($p) => in_array($p['status'], ['missing', 'weak', 'long']));
    if (empty($needsWork)) return [];

    // Load AI provider
    $configFile = SITE_ROOT . '/admin/data/AIResources/config.json';
    if (!is_file($configFile)) {
        throw new \RuntimeException('AI not configured — set up a provider in AIResources first');
    }

    require_once SITE_ROOT . '/admin/modules/AIResources/providers/ProviderRegistry.php';
    $registry = new ProviderRegistry($configFile);
    $provider = $registry->getActiveProvider();

    // Build prompt with page data
    $pageEntries = [];
    foreach (array_values($needsWork) as $i => $p) {
        $entry = "Page " . ($i + 1) . ": \"" . $p['title'] . "\" (slug: " . $p['slug'] . ")\n";
        $entry .= "Current status: " . $p['status'];
        if ($p['description']) {
            $entry .= " | Current description (" . $p['char_count'] . " chars): " . $p['description'];
        }
        $entry .= "\nContent preview: " . mb_substr($p['content_preview'], 0, 500);
        $pageEntries[] = $entry;
    }

    $systemPrompt = "You are an SEO specialist. Generate meta descriptions for web pages.\n\n"
        . "Rules:\n"
        . "- Each description MUST be 130-155 characters (this is critical)\n"
        . "- Use active voice and be compelling\n"
        . "- Include relevant keywords naturally\n"
        . "- Don't start with the page title\n"
        . "- Don't use quotes in the descriptions\n"
        . "- Return ONLY valid JSON — an array of objects with keys: slug, description\n"
        . "- No markdown fences, no commentary, just the JSON array";

    $userPrompt = "Generate SEO meta descriptions for these pages:\n\n"
        . implode("\n\n---\n\n", $pageEntries)
        . "\n\nReturn a JSON array like: [{\"slug\": \"...\", \"description\": \"...\"}]";

    $response = $provider->generate($systemPrompt, $userPrompt);

    // Parse JSON from response
    $text = $response['content'] ?? $response['text'] ?? '';
    // Strip markdown fences if present
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/\s*```\s*$/m', '', $text);

    $suggestions = json_decode(trim($text), true);
    if (!is_array($suggestions)) {
        throw new \RuntimeException('AI returned invalid JSON. Response: ' . mb_substr($text, 0, 300));
    }

    // Index by slug and add char_count
    $result = [];
    foreach ($suggestions as $s) {
        if (empty($s['slug']) || empty($s['description'])) continue;
        $result[] = [
            'slug'        => $s['slug'],
            'description' => $s['description'],
            'char_count'  => mb_strlen($s['description']),
        ];
    }

    return $result;
}

/**
 * Auto-generate + apply SEO description for a single page — pipeline convenience.
 *
 * Use this from article-generation pipelines after they write the page JSON:
 *     require_once SITE_ROOT.'/admin/modules/SiteSettings/SeoScanner.php';
 *     seo_auto_generate_one($slug);
 *
 * Returns ['status' => 'ok'|'skipped'|'failed', 'reason' => '...', 'description' => '...']
 *
 * Rules:
 *   - Skips if page already has a 'ok' length (50-160 chars) description — no cost
 *   - Skips if page has meta_description_locked=true in its JSON (manual override)
 *   - Logs to error_log on failure; never throws
 */
function seo_auto_generate_one(string $slug, ?string $siteRoot = null): array
{
    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
    if ($slug === '') return ['status' => 'failed', 'reason' => 'invalid slug'];

    // Callers targeting another site pass $siteRoot explicitly.
    // UI calls omit it and use SITE_ROOT of the current request.
    $root = $siteRoot ?? (defined('SITE_ROOT') ? SITE_ROOT : '');
    if ($root === '' || !is_dir($root)) return ['status' => 'failed', 'reason' => 'invalid site root'];

    $jsonPath = $root . '/admin/data/pages/' . $slug . '/' . $slug . '.json';
    if (!is_file($jsonPath)) return ['status' => 'failed', 'reason' => 'page json not found'];

    $data = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($data)) return ['status' => 'failed', 'reason' => 'invalid page json'];

    // Honor manual lock
    if (!empty($data['meta_description_locked'])) {
        return ['status' => 'skipped', 'reason' => 'locked'];
    }

    $desc = trim($data['meta_description'] ?? '');
    $len = mb_strlen($desc);

    // Classify current state
    if ($desc !== '' && $len >= 50 && $len <= 160) {
        return ['status' => 'skipped', 'reason' => 'already ok', 'description' => $desc];
    }

    // Build a single-page "pages" array to feed into the existing generator
    $title = $data['page_title'] ?? ucwords(str_replace('-', ' ', $slug));
    $status = $desc === '' ? 'missing' : ($len < 50 ? 'weak' : 'long');
    $contentHtml = $data['components']['main_content']['content'] ?? '';
    $plainText = _seo_strip_content($contentHtml);

    $onePage = [[
        'slug'            => $slug,
        'title'           => $title,
        'status'          => $status,
        'severity'        => 0,
        'description'     => $desc,
        'char_count'      => $len,
        'content_preview' => mb_substr($plainText, 0, 1500),
    ]];

    try {
        $suggestions = seo_generate_descriptions($onePage);
    } catch (\Throwable $e) {
        error_log("seo_auto_generate_one($slug): generate failed — " . $e->getMessage());
        return ['status' => 'failed', 'reason' => 'ai error: ' . $e->getMessage()];
    }

    if (empty($suggestions) || empty($suggestions[0]['description'])) {
        return ['status' => 'failed', 'reason' => 'no suggestion returned'];
    }

    $newDesc = $suggestions[0]['description'];
    // Inline apply that respects siteRoot (seo_apply_description hardcodes SITE_ROOT)
    $data['meta_description'] = trim($newDesc);
    $ok = file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
    if (!$ok) return ['status' => 'failed', 'reason' => 'write failed'];

    return ['status' => 'ok', 'description' => $newDesc, 'char_count' => mb_strlen($newDesc)];
}

/**
 * Apply a meta description to a page JSON file.
 */
function seo_apply_description(string $slug, string $description): bool
{
    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
    if ($slug === '') return false;

    $jsonPath = SITE_ROOT . '/admin/data/pages/' . $slug . '/' . $slug . '.json';
    if (!is_file($jsonPath)) return false;

    $data = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($data)) return false;

    $data['meta_description'] = trim($description);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($jsonPath, $json) !== false;
}

/**
 * Strip HTML tags, shortcodes, and excess whitespace from content.
 */
function _seo_strip_content(string $html): string
{
    // Remove shortcodes [[...]]
    $text = preg_replace('/\[\[[^\]]*\]\]/', '', $html);
    // Remove HTML tags
    $text = strip_tags($text);
    // Decode entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalize whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}
