<?php
/**
 * AIResources Helper Functions (migrated from AIPageMaker)
 *
 * - apm_read_json() / apm_write_json(): JSON file helpers
 * - apm_get_data_dir(): Data directory path
 * - apm_generate_id(): ID generation
 * - apm_build_system_prompt(): AI system prompt builder
 * - apm_build_user_message(): User message builder with site context
 * - Generation CRUD: save, load, list, delete
 * - apm_sanitize_domain(): Domain validation
 */
declare(strict_types=1);

/**
 * Get the current site root path (environment-agnostic)
 */
function apm_get_site_root(): string
{
    return defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3);
}

function apm_get_data_dir(): string
{
    $dir = SITE_ROOT . '/admin/data/AIResources';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function apm_get_generations_dir(): string
{
    return apm_get_data_dir() . '/generations';
}

function apm_read_json(string $file, $fallback = [])
{
    if (!is_file($file)) return $fallback;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : $fallback;
}

function apm_write_json(string $file, $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ok = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($ok !== false) {
        @chmod($file, 0664);
    }
    return $ok !== false;
}

function apm_generate_id(string $prefix = 'gen'): string
{
    return $prefix . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function apm_sanitize_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    return preg_replace('/[^a-z0-9.\-]/', '', $domain);
}

// ── Prompt Building ──────────────────────────────────────────────────

function apm_build_system_prompt(array $siteContext, array $options = []): string
{
    $contentType  = $options['contentType'] ?? 'article';
    $tone         = $options['tone'] ?? 'professional';
    $outputFormat = $options['outputFormat'] ?? 'full_page';
    $seoEnabled   = !empty($options['seoEnabled']);
    $wordCount    = (int)($options['wordCount'] ?? 800);
    $template     = $options['template'] ?? '';

    $siteName  = $siteContext['config']['name'] ?? $siteContext['domain'] ?? 'the website';
    $themeMode = $siteContext['theme']['mode'] ?? 'dark';
    $fonts     = !empty($siteContext['theme']['fonts']) ? implode(', ', $siteContext['theme']['fonts']) : 'system default';
    $colors    = !empty($siteContext['theme']['colors']) ? implode(', ', array_slice($siteContext['theme']['colors'], 0, 8)) : '';

    $prompt = "You are an expert web content writer and HTML developer creating pages for \"{$siteName}\".\n\n";

    // Theme instructions
    $prompt .= "## Visual Theme\n";
    $prompt .= "- Mode: {$themeMode}\n";
    $prompt .= "- Fonts: {$fonts}\n";
    if ($colors) {
        $prompt .= "- Color palette: {$colors}\n";
    }
    if (!empty($siteContext['theme']['cssSnippet'])) {
        $prompt .= "- Reference CSS:\n```css\n{$siteContext['theme']['cssSnippet']}\n```\n";
    }
    $prompt .= "\n";

    // Content instructions
    $prompt .= "## Content Guidelines\n";
    $prompt .= "- Content type: {$contentType}\n";
    $prompt .= "- Tone: {$tone}\n";
    $prompt .= "- Target word count: ~{$wordCount} words\n";
    $prompt .= "- Match the existing site's visual style exactly\n";

    if ($outputFormat === 'full_page') {
        $prompt .= "- Output a COMPLETE HTML page with <!DOCTYPE html>, <head>, and <body>\n";
        $prompt .= "- Include inline CSS that matches the site theme — do NOT reference external stylesheets unless they exist on the site\n";
    } else {
        $prompt .= "- Output an HTML FRAGMENT only — NO doctype, NO <html>, NO <head>, NO <body> tags\n";
        $prompt .= "- Use inline styles for all visual presentation (the fragment will be inserted into an existing page)\n";
        $prompt .= "- Use semantic HTML5 elements: <section>, <article>, <h2>-<h4>, <p>, <ul>, <div>, etc.\n";
        $prompt .= "- The fragment will be placed inside a page column — do NOT set page-level backgrounds or full-width layouts\n";
    }

    if ($seoEnabled) {
        $prompt .= "- Include proper SEO meta tags: title, description, og:title, og:description\n";
        $prompt .= "- Use semantic HTML5 elements (article, section, header, main, etc.)\n";
    }

    // Existing pages for context
    if (!empty($siteContext['pages'])) {
        $prompt .= "\n## Existing Pages on This Site\n";
        foreach (array_slice($siteContext['pages'], 0, 10) as $page) {
            $prompt .= "- {$page['title']} ({$page['path']})\n";
        }
    }

    // Menu structure
    if (!empty($siteContext['menu'])) {
        $prompt .= "\n## Site Navigation\n";
        foreach ($siteContext['menu'] as $item) {
            $prompt .= "- {$item['title']} → {$item['path']}\n";
        }
    }

    // Template
    if ($template !== '') {
        $prompt .= "\n## HTML Template\nUse this as the structural template:\n```html\n{$template}\n```\n";
    }

    // Image placeholders
    if (!empty($options['imagePlaceholders'])) {
        $imageCount   = (int)($options['imageCount'] ?? 3);
        $imageLayout  = $options['imageLayout'] ?? 'mixed';
        $imageRelevant = $options['imageRelevant'] ?? true;

        $prompt .= "\n## Image Placeholders\n";
        $prompt .= "Include image placeholders using this EXACT shortcode syntax:\n";
        $prompt .= "[ai-image prompt=\"descriptive text\" width=\"800\" height=\"400\" alt=\"...\" style=\"hero\"]\n\n";

        $prompt .= "Rules:\n";
        $prompt .= "- Place inline in HTML where each image should appear\n";
        $prompt .= "- prompt: vivid, detailed description for AI image generation (DALL-E)\n";
        $prompt .= "- Available styles and their dimensions:\n";
        $prompt .= "  - hero: full-width banner (width=\"1200\" height=\"500\")\n";
        $prompt .= "  - inline: standard content image (width=\"800\" height=\"450\")\n";
        $prompt .= "  - thumbnail: small square image (width=\"300\" height=\"300\")\n";
        $prompt .= "  - banner: wide short banner (width=\"1000\" height=\"300\")\n";
        $prompt .= "- Include exactly {$imageCount} images\n";
        $prompt .= "- Do NOT use <img> for AI-generated images — use ONLY the [ai-image] shortcode\n";

        if ($imageRelevant) {
            $prompt .= "- Each image prompt MUST be contextually relevant to the surrounding content\n";
            $prompt .= "- Describe the scene in detail — subject, setting, mood, lighting, composition\n";
        }

        // Layout instructions
        if ($imageLayout === 'mixed') {
            $prompt .= "\nImage Layout: MIXED SIZES\n";
            $prompt .= "- Use a variety of styles — mix hero, inline, and thumbnail sizes\n";
            $prompt .= "- Start with a hero image if appropriate, then mix inline and thumbnails through the content\n";
            $prompt .= "- Vary the visual rhythm — don't use the same style consecutively\n";
        } elseif ($imageLayout === 'fixed') {
            $prompt .= "\nImage Layout: FIXED COLUMNS\n";
            $prompt .= "- Use ALL images at the same size: style=\"inline\" width=\"800\" height=\"450\"\n";
            $prompt .= "- Space them evenly throughout the content at consistent intervals\n";
            $prompt .= "- Uniform presentation — same dimensions for every image\n";
        } elseif ($imageLayout === 'mixed-inset') {
            $prompt .= "\nImage Layout: MIXED SIZES WITH INSETS\n";
            $prompt .= "- Mix hero, inline, and thumbnail styles\n";
            $prompt .= "- For some images, wrap them in a float container for text wrap effect:\n";
            $prompt .= "  <div style=\"float:right;margin:0 0 12px 16px;max-width:40%\">[ai-image ... style=\"thumbnail\"]</div>\n";
            $prompt .= "  <div style=\"float:left;margin:0 16px 12px 0;max-width:45%\">[ai-image ... style=\"inline\"]</div>\n";
            $prompt .= "- Use at least 1-2 floated inset images alongside full-width ones\n";
            $prompt .= "- Add a <div style=\"clear:both\"></div> after floated sections when needed\n";
        }
    }

    $prompt .= "\n## Output\nReturn ONLY the HTML code. No markdown fences, no explanations.";

    return $prompt;
}

function apm_build_user_message(string $title, string $gist, array $options = []): string
{
    $msg = "Create a page titled: \"{$title}\"\n\n";
    $msg .= "Today's date is: " . date('F j, Y') . "\n\n";

    if ($gist !== '') {
        $msg .= "Content brief:\n{$gist}\n\n";
    }

    if (!empty($options['feedback'])) {
        $msg .= "Revision feedback from the user:\n{$options['feedback']}\n\n";
    }

    if (!empty($options['previousHtml'])) {
        $prevSnippet = substr($options['previousHtml'], 0, 2000);
        $msg .= "Previous version for reference:\n```html\n{$prevSnippet}\n```\n\n";
    }

    return $msg;
}

// ── Generation CRUD ──────────────────────────────────────────────────

function apm_save_generation(array $generation): bool
{
    $dir = apm_get_generations_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $id = $generation['id'] ?? apm_generate_id();
    $generation['id'] = $id;
    $generation['updatedAt'] = date('c');

    if (empty($generation['createdAt'])) {
        $generation['createdAt'] = date('c');
    }

    $file = $dir . '/' . $id . '.json';
    $json = json_encode($generation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        error_log('AIResources: json_encode failed for generation ' . $id . ': ' . json_last_error_msg());
        return false;
    }

    $ok = file_put_contents($file, $json);
    if ($ok === false) {
        error_log('AIResources: file_put_contents failed for ' . $file . ' (dir writable: ' . (is_writable($dir) ? 'yes' : 'no') . ')');
        return false;
    }
    @chmod($file, 0664);
    return true;
}

function apm_load_generation(string $id): ?array
{
    $file = apm_get_generations_dir() . '/' . $id . '.json';
    if (!is_file($file)) return null;
    return apm_read_json($file);
}

function apm_list_generations(int $page = 1, int $perPage = 20): array
{
    $dir = apm_get_generations_dir();
    if (!is_dir($dir)) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1];
    }

    $files = glob($dir . '/*.json');
    // Sort newest first
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

    $total = count($files);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    $items = [];
    foreach (array_slice($files, $offset, $perPage) as $file) {
        $gen = apm_read_json($file);
        // Return summary (no full HTML)
        $items[] = [
            'id'        => $gen['id'] ?? basename($file, '.json'),
            'domain'    => $gen['domain'] ?? '',
            'title'     => $gen['title'] ?? '',
            'type'      => $gen['contentType'] ?? '',
            'status'    => $gen['status'] ?? 'draft',
            'createdAt' => $gen['createdAt'] ?? '',
            'model'     => $gen['model'] ?? '',
        ];
    }

    return [
        'items'      => $items,
        'total'      => $total,
        'page'       => $page,
        'totalPages' => $totalPages,
    ];
}

function apm_delete_generation(string $id): bool
{
    // Sanitize ID to prevent path traversal
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
    $file = apm_get_generations_dir() . '/' . $id . '.json';
    if (is_file($file)) {
        return unlink($file);
    }
    return false;
}

// ── Template Loading ──────────────────────────────────────────────────

function apm_list_templates(): array
{
    $dir = __DIR__ . '/templates';
    if (!is_dir($dir)) return [];

    $templates = [];
    foreach (scandir($dir) as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) !== 'html') continue;
        $name = pathinfo($f, PATHINFO_FILENAME);
        $content = file_get_contents($dir . '/' . $f);

        // Extract template metadata from HTML comment
        $description = '';
        if (preg_match('/<!--\s*@description:\s*(.+?)\s*-->/', $content, $m)) {
            $description = $m[1];
        }

        $templates[] = [
            'id'          => $name,
            'name'        => ucwords(str_replace(['-', '_'], ' ', $name)),
            'description' => $description,
            'file'        => $f,
        ];
    }

    return $templates;
}

function apm_load_template(string $id): string
{
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
    $file = __DIR__ . '/templates/' . $id . '.html';
    if (!is_file($file)) return '';
    return file_get_contents($file);
}

// ── Image Shortcode Functions ────────────────────────────────────────

/**
 * Parse [ai-image ...] shortcodes from HTML → array of image metadata.
 */
function apm_parse_image_shortcodes(string $html): array
{
    $images = [];
    $pattern = '/\[ai-image\s+((?:[a-z][\w-]*="[^"]*"\s*)+)\]/i';

    if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
        return $images;
    }

    $attrPattern = '/([a-z][\w-]*)="([^"]*)"/i';

    foreach ($matches as $idx => $match) {
        $raw = $match[0];
        $attrString = $match[1];

        $attrs = [];
        if (preg_match_all($attrPattern, $attrString, $attrMatches, PREG_SET_ORDER)) {
            foreach ($attrMatches as $am) {
                $attrs[$am[1]] = $am[2];
            }
        }

        $prompt = $attrs['prompt'] ?? '';
        $style  = $attrs['style'] ?? 'inline';

        // Default dimensions by style
        $styleDims = [
            'hero'      => [1200, 500],
            'inline'    => [800, 450],
            'thumbnail' => [300, 300],
            'banner'    => [1000, 300],
        ];
        $defaults = $styleDims[$style] ?? [800, 400];

        $images[] = [
            'idx'      => $idx,
            'raw'      => $raw,
            'prompt'   => $prompt,
            'width'    => (int)($attrs['width'] ?? $defaults[0]),
            'height'   => (int)($attrs['height'] ?? $defaults[1]),
            'alt'      => $attrs['alt'] ?? $prompt,
            'style'    => $style,
            'status'   => 'pending',
            'src'      => '',
            'filename' => '',
        ];
    }

    return $images;
}

/**
 * Replace shortcodes with inline-styled placeholder <div>s for preview.
 * Resolved images get <img> tags instead.
 */
function apm_render_image_placeholders(string $html, array $images, string $domain = ''): string
{
    foreach ($images as $img) {
        if ($img['status'] === 'resolved' && !empty($img['src'])) {
            $src = $img['src'];
            if ($domain && strpos($src, 'http') !== 0) {
                $src = 'https://' . $domain . $src;
            }
            $alt = htmlspecialchars($img['alt'] ?? $img['prompt'], ENT_QUOTES, 'UTF-8');
            $replacement = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" '
                . 'alt="' . $alt . '" '
                . 'width="' . $img['width'] . '" height="' . $img['height'] . '" '
                . 'style="max-width:100%;height:auto;display:block;border-radius:6px;" '
                . 'loading="lazy">';
        } else {
            // Placeholder div with inline styles (for iframe srcdoc sandbox)
            $bgColor = '#2a1a3e';
            $borderColor = '#7c3aed';
            $textColor = '#c4b5fd';
            $iconColor = '#a78bfa';

            $replacement = '<div style="'
                . 'width:' . $img['width'] . 'px;max-width:100%;height:' . $img['height'] . 'px;'
                . 'background:' . $bgColor . ';'
                . 'border:2px dashed ' . $borderColor . ';'
                . 'border-radius:8px;'
                . 'display:flex;flex-direction:column;align-items:center;justify-content:center;'
                . 'gap:8px;padding:16px;margin:12px auto;'
                . 'font-family:system-ui,sans-serif;color:' . $textColor . ';'
                . 'box-sizing:border-box;overflow:hidden;'
                . '">'
                . '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="' . $iconColor . '" stroke-width="1.5">'
                . '<rect x="3" y="3" width="18" height="18" rx="2"/>'
                . '<circle cx="8.5" cy="8.5" r="1.5"/>'
                . '<path d="M21 15l-5-5L5 21"/>'
                . '</svg>'
                . '<div style="font-size:13px;font-weight:600;text-align:center;">[AI Image Placeholder]</div>'
                . '<div style="font-size:11px;text-align:center;opacity:0.8;max-width:90%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                . htmlspecialchars($img['prompt'], ENT_QUOTES, 'UTF-8')
                . '</div>'
                . '<div style="font-size:10px;opacity:0.6;">'
                . $img['width'] . 'x' . $img['height'] . ' &middot; ' . htmlspecialchars($img['style'], ENT_QUOTES, 'UTF-8')
                . '</div>'
                . '</div>';
        }

        $html = str_replace($img['raw'], $replacement, $html);
    }

    return $html;
}

/**
 * Replace shortcodes with final <img> tags using resolved src paths.
 * Used at publish time. Returns HTML with root-relative URLs.
 */
function apm_resolve_image_shortcodes(string $html, array $images): string
{
    foreach ($images as $img) {
        if ($img['status'] !== 'resolved' || empty($img['src'])) {
            continue;
        }

        $alt = htmlspecialchars($img['alt'] ?? $img['prompt'], ENT_QUOTES, 'UTF-8');
        $src = $img['src']; // Already root-relative: /images/ai-generated/{genId}/img-0.png
        $replacement = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" '
            . 'alt="' . $alt . '" '
            . 'width="' . $img['width'] . '" height="' . $img['height'] . '" '
            . 'style="max-width:100%;height:auto;" '
            . 'loading="lazy">';

        $html = str_replace($img['raw'], $replacement, $html);
    }

    return $html;
}

/**
 * Save binary image data to vhost filesystem, return web path.
 */
function apm_save_resolved_image(string $domain, string $genId, int $idx, string $imageData, string $format = 'png'): string
{
    $domain = apm_sanitize_domain($domain);
    $genId  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $genId);
    $format = preg_replace('/[^a-z0-9]/', '', strtolower($format));
    if (!in_array($format, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true)) {
        $format = 'png';
    }

    $siteRoot = apm_get_site_root();
    if (!is_dir($siteRoot)) {
        return '';
    }

    $relDir  = '/images/ai-generated/' . $genId;
    $absDir  = $siteRoot . $relDir;
    if (!is_dir($absDir)) {
        @mkdir($absDir, 0775, true);
    }

    $filename = 'img-' . $idx . '.' . $format;
    $absPath  = $absDir . '/' . $filename;
    $written  = file_put_contents($absPath, $imageData);
    if ($written === false) {
        return '';
    }
    @chmod($absPath, 0664);

    return $relDir . '/' . $filename;
}

/**
 * Normalize MCP tool response (url/base64/path) → standard format.
 * Returns ['data' => binary string, 'format' => extension] or null on failure.
 */
function apm_process_mcp_image_result(array $mcpResult): ?array
{
    // MCP result may be: {"url": "..."} or {"base64": "...", "format": "png"} or {"path": "/abs/path"}
    if (!empty($mcpResult['url'])) {
        $data = apm_fetch_image_from_url($mcpResult['url']);
        if ($data === null) return null;
        $format = 'png';
        if (preg_match('/\.(jpe?g|png|webp|gif|svg)(\?|$)/i', $mcpResult['url'], $m)) {
            $format = strtolower($m[1]);
        }
        return ['data' => $data, 'format' => $format];
    }

    if (!empty($mcpResult['base64'])) {
        $data = base64_decode($mcpResult['base64'], true);
        if ($data === false) return null;
        return ['data' => $data, 'format' => $mcpResult['format'] ?? 'png'];
    }

    if (!empty($mcpResult['path'])) {
        $path = $mcpResult['path'];
        if (!is_file($path)) return null;
        $data = file_get_contents($path);
        if ($data === false) return null;
        $format = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
        return ['data' => $data, 'format' => $format];
    }

    return null;
}

// ── Image Library Functions ───────────────────────────────────────────

/**
 * Returns path to the image library JSON file.
 */
function apm_library_path(): string
{
    return apm_get_data_dir() . '/image_library.json';
}

/**
 * Load the image library, returning {'images': [...]} with fallback.
 */
function apm_library_load(): array
{
    return apm_read_json(apm_library_path(), ['images' => []]);
}

/**
 * Save the image library to disk.
 */
function apm_library_save(array $library): bool
{
    return apm_write_json(apm_library_path(), $library);
}

/**
 * Scan all generation files, find resolved images, auto-index into library.
 * Preserves existing entries (by src path), adds new ones, removes orphans.
 */
function apm_library_scan_generations(): array
{
    $genDir = apm_get_generations_dir();
    if (!is_dir($genDir)) return ['images' => []];

    $library = apm_library_load();
    $existingBySrc = [];
    foreach ($library['images'] as $img) {
        $existingBySrc[$img['src']] = $img;
    }

    $found = [];

    $files = glob($genDir . '/*.json');
    foreach ($files as $file) {
        $gen = apm_read_json($file);
        if (empty($gen['images'])) continue;

        $genId   = $gen['id'] ?? basename($file, '.json');
        $domain  = $gen['domain'] ?? '';
        $title   = $gen['title'] ?? '';

        foreach ($gen['images'] as $img) {
            if (($img['status'] ?? '') !== 'resolved' || empty($img['src'])) continue;

            $src = $img['src'];
            $absPath = $src ? apm_get_site_root() . $src : '';

            // Check file still exists on disk
            if ($absPath && !is_file($absPath)) continue;

            // Preserve existing entry or create new
            if (isset($existingBySrc[$src])) {
                $found[$src] = $existingBySrc[$src];
            } else {
                $fileSize = $absPath && is_file($absPath) ? filesize($absPath) : 0;
                $found[$src] = [
                    'id'             => apm_generate_id('img'),
                    'filename'       => $img['filename'] ?? basename($src),
                    'src'            => $src,
                    'absPath'        => $absPath,
                    'domain'         => $domain,
                    'prompt'         => $img['prompt'] ?? '',
                    'alt'            => $img['alt'] ?? ($img['prompt'] ?? ''),
                    'width'          => $img['width'] ?? 0,
                    'height'         => $img['height'] ?? 0,
                    'style'          => $img['style'] ?? 'inline',
                    'tags'           => [],
                    'sourceGenId'    => $genId,
                    'sourceGenTitle' => $title,
                    'fileSize'       => $fileSize,
                    'createdAt'      => $gen['createdAt'] ?? date('c'),
                ];
            }
        }
    }

    $library['images'] = array_values($found);
    apm_library_save($library);
    return $library;
}

/**
 * Add a single image to the library (used after image resolves).
 */
function apm_library_add_image(array $entry): void
{
    $library = apm_library_load();

    // Check if already indexed by src
    foreach ($library['images'] as $img) {
        if ($img['src'] === $entry['src']) return;
    }

    if (empty($entry['id'])) {
        $entry['id'] = apm_generate_id('img');
    }
    if (empty($entry['createdAt'])) {
        $entry['createdAt'] = date('c');
    }

    $library['images'][] = $entry;
    apm_library_save($library);
}

/**
 * Tokenize a prompt for similarity matching.
 * Lowercase, strip non-alpha, remove stop words, return unique word set.
 */
function apm_tokenize_prompt(string $prompt): array
{
    $text = strtolower($prompt);
    $text = preg_replace('/[^a-z\s]/', ' ', $text);
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    $stopWords = [
        'a','an','the','and','or','but','in','on','at','to','for','of','with',
        'by','from','as','is','was','are','were','be','been','being','have',
        'has','had','do','does','did','will','would','could','should','may',
        'might','can','shall','that','this','these','those','it','its','i',
        'you','he','she','we','they','me','him','her','us','them','my','your',
        'his','our','their','what','which','who','whom','where','when','how',
        'not','no','so','if','then','than','too','very','just','about','above',
        'after','before','between','into','through','during','over','under',
        'image','photo','picture','illustration','showing','scene','depicts',
        'create','generate','make','render','draw','paint','style','styled',
    ];

    $filtered = array_diff($words, $stopWords);
    return array_values(array_unique($filtered));
}

/**
 * Search the image library for a matching image.
 * Filters by style, then computes Jaccard similarity on tokenized prompts.
 * Returns the best match above threshold (0.4), or null.
 */
function apm_library_find_match(string $prompt, string $style, int $width, int $height): ?array
{
    $library = apm_library_load();
    $images = $library['images'] ?? [];
    if (empty($images)) return null;

    $promptTokens = apm_tokenize_prompt($prompt);
    if (empty($promptTokens)) return null;

    $threshold = 0.4;
    $bestMatch = null;
    $bestScore = 0.0;

    foreach ($images as $img) {
        // Must match style
        if (($img['style'] ?? 'inline') !== $style) continue;

        $libTokens = apm_tokenize_prompt($img['prompt'] ?? '');
        if (empty($libTokens)) continue;

        // Jaccard similarity: |intersection| / |union|
        $intersection = array_intersect($promptTokens, $libTokens);
        $union = array_unique(array_merge($promptTokens, $libTokens));
        $score = count($intersection) / count($union);

        if ($score >= $threshold && $score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $img;
        }
    }

    return $bestMatch;
}

/**
 * Call DALL-E API to generate an image from a text prompt.
 *
 * @param string $prompt  Descriptive text for image generation
 * @param array  $config  Image provider config: {apiKey, model, size, quality}
 * @return array|null  {data: binary, format: 'png', revised_prompt: string} or null on failure
 */
function apm_generate_image_dalle(string $prompt, array $config): ?array
{
    $apiKey  = $config['apiKey'] ?? '';
    $model   = $config['model'] ?? 'dall-e-3';
    $quality = $config['quality'] ?? 'standard';

    if ($apiKey === '') {
        error_log('AIResources: DALL-E API key is empty');
        return null;
    }

    // Map shortcode style to DALL-E 3 supported sizes
    $size = $config['size'] ?? '1024x1024';
    $validSizes = ['1024x1024', '1792x1024', '1024x1792'];
    if (!in_array($size, $validSizes, true)) {
        $size = '1024x1024';
    }

    $payload = [
        'model'   => $model,
        'prompt'  => $prompt,
        'n'       => 1,
        'size'    => $size,
        'quality' => $quality,
        'response_format' => 'url',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.openai.com/v1/images/generations',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);

    if ($response === false || $httpCode !== 200) {
        $errMsg = $err;
        if ($response !== false) {
            $body = json_decode($response, true);
            $errMsg = $body['error']['message'] ?? $response;
        }
        error_log("AIResources: DALL-E API error (HTTP {$httpCode}): {$errMsg}");
        return null;
    }

    $body = json_decode($response, true);
    if (empty($body['data'][0]['url'])) {
        error_log('AIResources: DALL-E response missing image URL');
        return null;
    }

    $imageUrl      = $body['data'][0]['url'];
    $revisedPrompt = $body['data'][0]['revised_prompt'] ?? $prompt;

    // Download the generated image
    $imageData = apm_fetch_image_from_url($imageUrl);
    if ($imageData === null) {
        error_log('AIResources: Failed to download DALL-E generated image');
        return null;
    }

    return [
        'data'           => $imageData,
        'format'         => 'png',
        'revised_prompt' => $revisedPrompt,
    ];
}

/**
 * Map image shortcode style to DALL-E size parameter.
 */
function apm_dalle_size_for_style(string $style): string
{
    return match ($style) {
        'hero', 'banner' => '1792x1024',
        'thumbnail'      => '1024x1024',
        default          => '1024x1024',  // inline and others
    };
}

/**
 * cURL download of image from URL → binary string, or null on failure.
 */
function apm_fetch_image_from_url(string $url): ?string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'AIResources/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    if ($data === false || $httpCode !== 200) {
        error_log("AIResources: Failed to fetch image from {$url}: HTTP {$httpCode} - {$err}");
        return null;
    }

    return $data;
}
