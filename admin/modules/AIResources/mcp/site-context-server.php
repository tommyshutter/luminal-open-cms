#!/usr/bin/env php
<?php
/**
 * Site Context MCP Server
 *
 * Exposes site data tools: config, theme analysis, pages, menu, backgrounds.
 * Launched as: php site-context-server.php
 */
declare(strict_types=1);

require_once __DIR__ . '/MCPServer.php';

class SiteContextServer extends MCPServer
{
    private string $vhostsBase = '/var/www/vhosts';

    protected function getServerInfo(): array
    {
        return ['name' => 'site-context', 'version' => '1.0.0'];
    }

    protected function getTools(): array
    {
        return [
            [
                'name'        => 'get_site_context',
                'description' => 'Get full site context bundle: config, theme, pages, and menu for a domain',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'Domain name (e.g., scifitimelines.com)'],
                    ],
                    'required' => ['domain'],
                ],
            ],
            [
                'name'        => 'analyze_theme',
                'description' => 'Analyze CSS colors, fonts, layout pattern, and extract a CSS snippet for the domain',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'Domain name'],
                    ],
                    'required' => ['domain'],
                ],
            ],
            [
                'name'        => 'list_pages',
                'description' => 'List existing pages with titles and paths for a domain',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'Domain name'],
                    ],
                    'required' => ['domain'],
                ],
            ],
            [
                'name'        => 'get_page_content',
                'description' => 'Read an existing page\'s HTML content',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'Domain name'],
                        'page'   => ['type' => 'string', 'description' => 'Page path relative to site root'],
                    ],
                    'required' => ['domain', 'page'],
                ],
            ],
            [
                'name'        => 'get_menu',
                'description' => 'Get the site navigation structure for a domain',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'Domain name'],
                    ],
                    'required' => ['domain'],
                ],
            ],
            [
                'name'        => 'get_backgrounds',
                'description' => 'Get background media configuration for visual context',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'Domain name'],
                    ],
                    'required' => ['domain'],
                ],
            ],
        ];
    }

    protected function executeTool(string $name, array $args): array
    {
        $domain = $this->sanitizeDomain($args['domain'] ?? '');
        if ($domain === '') {
            return $this->textResult('Error: domain is required');
        }

        $siteRoot = $this->vhostsBase . '/' . $domain;
        if (!is_dir($siteRoot)) {
            return $this->textResult("Error: site root not found for {$domain}");
        }

        switch ($name) {
            case 'get_site_context':
                return $this->getSiteContext($domain, $siteRoot);
            case 'analyze_theme':
                return $this->analyzeTheme($domain, $siteRoot);
            case 'list_pages':
                return $this->listPages($domain, $siteRoot);
            case 'get_page_content':
                return $this->getPageContent($domain, $siteRoot, $args['page'] ?? '');
            case 'get_menu':
                return $this->getMenu($domain, $siteRoot);
            case 'get_backgrounds':
                return $this->getBackgrounds($domain, $siteRoot);
            default:
                return $this->textResult("Unknown tool: {$name}");
        }
    }

    protected function getResources(): array
    {
        return [
            ['uri' => 'site://{domain}/config', 'name' => 'Site Configuration', 'description' => 'Site config data', 'mimeType' => 'application/json'],
            ['uri' => 'site://{domain}/theme',  'name' => 'Site Theme',         'description' => 'CSS/visual identity', 'mimeType' => 'application/json'],
            ['uri' => 'site://{domain}/pages',  'name' => 'Page Inventory',     'description' => 'Existing pages list', 'mimeType' => 'application/json'],
        ];
    }

    protected function readResource(string $uri): array
    {
        // Parse uri: site://{domain}/{type}
        if (preg_match('#^site://([^/]+)/(.+)$#', $uri, $m)) {
            $domain = $this->sanitizeDomain($m[1]);
            $type   = $m[2];
            $siteRoot = $this->vhostsBase . '/' . $domain;

            if (!is_dir($siteRoot)) {
                return ['contents' => []];
            }

            switch ($type) {
                case 'config':
                    $config = $this->loadSiteConfig($domain, $siteRoot);
                    return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => json_encode($config, JSON_PRETTY_PRINT)]]];
                case 'theme':
                    $theme = $this->extractTheme($siteRoot);
                    return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => json_encode($theme, JSON_PRETTY_PRINT)]]];
                case 'pages':
                    $pages = $this->findPages($domain, $siteRoot);
                    return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => json_encode($pages, JSON_PRETTY_PRINT)]]];
            }
        }
        return ['contents' => []];
    }

    // ── Tool implementations ──────────────────────────────────────────

    private function getSiteContext(string $domain, string $siteRoot): array
    {
        $config = $this->loadSiteConfig($domain, $siteRoot);
        $theme  = $this->extractTheme($siteRoot);
        $pages  = $this->findPages($domain, $siteRoot);
        $menu   = $this->loadMenu($domain);

        $context = [
            'domain' => $domain,
            'config' => $config,
            'theme'  => $theme,
            'pages'  => $pages,
            'menu'   => $menu,
        ];

        return $this->textResult(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function analyzeTheme(string $domain, string $siteRoot): array
    {
        $theme = $this->extractTheme($siteRoot);
        return $this->textResult(json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function listPages(string $domain, string $siteRoot): array
    {
        $pages = $this->findPages($domain, $siteRoot);
        return $this->textResult(json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function getPageContent(string $domain, string $siteRoot, string $page): array
    {
        if ($page === '') {
            return $this->textResult('Error: page path is required');
        }

        // Sanitize: prevent path traversal
        $page = ltrim($page, '/');
        $realSiteRoot = realpath($siteRoot);
        $filePath = realpath($siteRoot . '/' . $page);

        if ($filePath === false || $realSiteRoot === false || strpos($filePath, $realSiteRoot) !== 0) {
            return $this->textResult('Error: invalid page path');
        }

        if (!is_file($filePath)) {
            return $this->textResult("Error: page not found: {$page}");
        }

        $content = file_get_contents($filePath);
        // Limit content to 50KB to avoid context overflow
        if (strlen($content) > 51200) {
            $content = substr($content, 0, 51200) . "\n\n[Truncated at 50KB]";
        }

        return $this->textResult($content);
    }

    private function getMenu(string $domain, string $siteRoot): array
    {
        $menu = $this->loadMenu($domain);
        return $this->textResult(json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function getBackgrounds(string $domain, string $siteRoot): array
    {
        // Check for BackgroundManager data
        $bgDataDir = $siteRoot . '/AdminPanel/modules/BackgroundManager/data';
        $bgConfig  = $this->readJson($bgDataDir . '/config.json');
        $bgLibrary = $this->readJson($bgDataDir . '/library.json');

        $result = [
            'hasBackgroundManager' => is_dir($bgDataDir),
            'config'  => [
                'activePlaylist'  => $bgConfig['activePlaylist'] ?? null,
                'transitionMs'    => $bgConfig['transitionMs'] ?? 10000,
                'cycleTime'       => $bgConfig['cycleTime'] ?? 7500,
                'overlayColor'    => $bgConfig['overlayColor'] ?? null,
                'overlayOpacity'  => $bgConfig['overlayOpacity'] ?? null,
            ],
            'imageCount' => count($bgLibrary['images'] ?? []),
            'videoCount' => count($bgLibrary['videos'] ?? []),
        ];

        return $this->textResult(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function loadSiteConfig(string $domain, string $siteRoot): array
    {
        // Try modern AdminPanel config
        $modernConfig = $siteRoot . '/AdminPanel/config/site_config.json';
        if (is_file($modernConfig)) {
            $data = $this->readJson($modernConfig);
            return [
                'name'   => $data['site']['name'] ?? $domain,
                'title'  => $data['site']['title'] ?? $domain,
                'url'    => $data['site']['url'] ?? "https://{$domain}",
                'type'   => 'modern',
                'hasAdminPanel' => true,
            ];
        }

        // Try legacy config
        $legacyConfig = $siteRoot . '/conf/site_config.php';
        if (is_file($legacyConfig)) {
            return $this->parseLegacyConfig($legacyConfig, $domain);
        }

        // Static site - no config
        return [
            'name'  => ucwords(str_replace(['.', '-'], ' ', explode('.', $domain)[0])),
            'title' => $domain,
            'url'   => "https://{$domain}",
            'type'  => 'static',
            'hasAdminPanel' => false,
        ];
    }

    private function parseLegacyConfig(string $file, string $domain): array
    {
        $content = file_get_contents($file);
        $config  = ['type' => 'legacy', 'hasAdminPanel' => false];

        // Extract define() values
        $defines = [
            'SITE_NAME'        => 'name',
            'SITE_TITLE'       => 'title',
            'SITE_DOMAIN_NAME' => 'domain',
            'SITE_URL'         => 'url',
            'ADMIN_EMAIL'      => 'adminEmail',
        ];

        foreach ($defines as $const => $key) {
            if (preg_match("/define\(\s*['\"]" . preg_quote($const) . "['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
                $config[$key] = $m[1];
            }
        }

        if (empty($config['name'])) {
            $config['name'] = $domain;
        }

        return $config;
    }

    private function extractTheme(string $siteRoot): array
    {
        $theme = [
            'colors'     => [],
            'fonts'      => [],
            'layout'     => 'unknown',
            'cssSnippet' => '',
        ];

        // Search for CSS files
        $cssFiles = [];
        $cssDirs = ['css', 'assets/css', 'public/css', 'AdminPanel/css'];
        foreach ($cssDirs as $dir) {
            $fullDir = $siteRoot . '/' . $dir;
            if (!is_dir($fullDir)) continue;
            foreach (scandir($fullDir) as $f) {
                if (pathinfo($f, PATHINFO_EXTENSION) === 'css') {
                    $cssFiles[] = $fullDir . '/' . $f;
                }
            }
        }

        // Also check root-level CSS
        foreach (['style.css', 'main.css', 'site_wide.css'] as $name) {
            $path = $siteRoot . '/css/' . $name;
            if (is_file($path) && !in_array($path, $cssFiles)) {
                array_unshift($cssFiles, $path);
            }
        }

        if (empty($cssFiles)) {
            return $theme;
        }

        // Analyze the first/primary CSS file
        $cssContent = '';
        foreach (array_slice($cssFiles, 0, 3) as $cssFile) {
            $cssContent .= file_get_contents($cssFile) . "\n";
        }

        // Extract colors (hex)
        preg_match_all('/#([0-9a-fA-F]{3,8})\b/', $cssContent, $colorMatches);
        $colors = array_unique(array_map('strtoupper', $colorMatches[0]));
        $theme['colors'] = array_values(array_slice($colors, 0, 20));

        // Extract rgba colors
        preg_match_all('/rgba?\([^)]+\)/', $cssContent, $rgbaMatches);
        $theme['rgbaColors'] = array_values(array_unique(array_slice($rgbaMatches[0], 0, 10)));

        // Extract font families
        preg_match_all("/font-family:\s*([^;]+)/", $cssContent, $fontMatches);
        $fonts = [];
        foreach ($fontMatches[1] as $fontDecl) {
            $cleaned = trim($fontDecl, " '\"\t\n\r");
            if ($cleaned !== '' && !in_array($cleaned, $fonts)) {
                $fonts[] = $cleaned;
            }
        }
        $theme['fonts'] = array_slice($fonts, 0, 5);

        // Detect layout pattern
        if (stripos($cssContent, 'display: grid') !== false || stripos($cssContent, 'display:grid') !== false) {
            $theme['layout'] = 'css-grid';
        } elseif (stripos($cssContent, 'display: flex') !== false || stripos($cssContent, 'display:flex') !== false) {
            $theme['layout'] = 'flexbox';
        } elseif (stripos($cssContent, 'float:') !== false) {
            $theme['layout'] = 'float-based';
        }

        // Detect dark/light theme
        $darkIndicators = ['background: #1', 'background-color: #0', 'background-color: #1', 'background: #0', 'background: rgba(0', 'color: #fff', 'color: #FFF', 'color: white'];
        $darkScore = 0;
        foreach ($darkIndicators as $indicator) {
            if (stripos($cssContent, $indicator) !== false) {
                $darkScore++;
            }
        }
        $theme['mode'] = $darkScore >= 3 ? 'dark' : 'light';

        // Extract key CSS snippet (body and content classes)
        $snippet = '';
        if (preg_match('/body\s*\{[^}]+\}/s', $cssContent, $bodyMatch)) {
            $snippet .= $bodyMatch[0] . "\n\n";
        }
        if (preg_match('/\.content\s*\{[^}]+\}/s', $cssContent, $contentMatch)) {
            $snippet .= $contentMatch[0] . "\n\n";
        }
        if (preg_match('/h1\s*\{[^}]+\}/s', $cssContent, $h1Match)) {
            $snippet .= $h1Match[0] . "\n";
        }
        $theme['cssSnippet'] = trim($snippet);

        return $theme;
    }

    private function findPages(string $domain, string $siteRoot): array
    {
        $pages = [];

        // Check MenuPages data
        $menuPagesDir = $siteRoot . '/data/menupages';
        if (is_file($menuPagesDir . '/pages.json')) {
            $pagesData = $this->readJson($menuPagesDir . '/pages.json');
            foreach ($pagesData as $key => $page) {
                $pages[] = [
                    'key'   => $key,
                    'title' => $page['title'] ?? $key,
                    'path'  => $page['path'] ?? '/',
                    'type'  => $page['type'] ?? 'page',
                    'source' => 'menupages',
                ];
            }
            return $pages;
        }

        // Scan for HTML files in root
        $htmlFiles = ['index.html', 'about.html', 'contact.html'];
        foreach ($htmlFiles as $file) {
            if (is_file($siteRoot . '/' . $file)) {
                $title = $file;
                $content = file_get_contents($siteRoot . '/' . $file);
                if (preg_match('/<title>([^<]+)<\/title>/i', $content, $tm)) {
                    $title = trim($tm[1]);
                }
                $pages[] = [
                    'key'    => pathinfo($file, PATHINFO_FILENAME),
                    'title'  => $title,
                    'path'   => '/' . $file,
                    'type'   => 'page',
                    'source' => 'filesystem',
                ];
            }
        }

        // Scan pages/ directory
        $pagesDir = $siteRoot . '/pages';
        if (is_dir($pagesDir)) {
            foreach (scandir($pagesDir) as $f) {
                if (pathinfo($f, PATHINFO_EXTENSION) !== 'html') continue;
                $filePath = $pagesDir . '/' . $f;
                $title = $f;
                $content = file_get_contents($filePath);
                if (preg_match('/<title>([^<]+)<\/title>/i', $content, $tm)) {
                    $title = trim($tm[1]);
                }
                $pages[] = [
                    'key'    => pathinfo($f, PATHINFO_FILENAME),
                    'title'  => $title,
                    'path'   => '/pages/' . $f,
                    'type'   => 'page',
                    'source' => 'filesystem',
                ];
            }
        }

        return $pages;
    }

    private function loadMenu(string $domain): array
    {
        $menuFile = $this->vhostsBase . '/' . $domain . '/data/menupages/active_menu.json';
        $pagesFile = $this->vhostsBase . '/' . $domain . '/data/menupages/pages.json';

        if (!is_file($menuFile) || !is_file($pagesFile)) {
            return [];
        }

        $menuOrder = $this->readJson($menuFile);
        $pages     = $this->readJson($pagesFile);

        $menu = [];
        foreach ($menuOrder as $key) {
            if (isset($pages[$key])) {
                $menu[] = [
                    'key'   => $key,
                    'title' => $pages[$key]['title'] ?? $key,
                    'path'  => $pages[$key]['path'] ?? '/',
                ];
            }
        }
        return $menu;
    }

    // ── Utility ──────────────────────────────────────────────────────

    private function sanitizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        return preg_replace('/[^a-z0-9.\-]/', '', $domain);
    }

    private function readJson(string $file): array
    {
        if (!is_file($file)) return [];
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function textResult(string $text): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
    }
}

// Run the server
$server = new SiteContextServer();
$server->run();
