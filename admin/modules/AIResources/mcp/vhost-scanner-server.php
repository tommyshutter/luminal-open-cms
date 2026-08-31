#!/usr/bin/env php
<?php
/**
 * Vhost Scanner MCP Server
 *
 * Discovers and lists all vhosts with metadata.
 * Launched as: php vhost-scanner-server.php
 */
declare(strict_types=1);

require_once __DIR__ . '/MCPServer.php';

class VhostScannerServer extends MCPServer
{
    private string $vhostsBase = '/var/www/vhosts';

    protected function getServerInfo(): array
    {
        return ['name' => 'vhost-scanner', 'version' => '1.0.0'];
    }

    protected function getTools(): array
    {
        return [
            [
                'name'        => 'scan_vhosts',
                'description' => 'List all virtual hosts with metadata (domain, type, features)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name'        => 'get_vhost_info',
                'description' => 'Get detailed info for a specific vhost: type, features, paths',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'Domain name to inspect'],
                    ],
                    'required' => ['domain'],
                ],
            ],
        ];
    }

    protected function executeTool(string $name, array $args): array
    {
        switch ($name) {
            case 'scan_vhosts':
                return $this->scanVhosts();
            case 'get_vhost_info':
                $domain = $this->sanitizeDomain($args['domain'] ?? '');
                if ($domain === '') {
                    return $this->textResult('Error: domain is required');
                }
                return $this->getVhostInfo($domain);
            default:
                return $this->textResult("Unknown tool: {$name}");
        }
    }

    // ── Tool implementations ──────────────────────────────────────────

    private function scanVhosts(): array
    {
        $vhosts = [];

        if (!is_dir($this->vhostsBase)) {
            return $this->textResult('Error: vhosts directory not found');
        }

        $entries = scandir($this->vhostsBase);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $this->vhostsBase . '/' . $entry;
            if (!is_dir($fullPath)) continue;

            // Skip obvious non-domain directories
            if (strpos($entry, '.') === false) continue;

            $type = $this->detectSiteType($fullPath);
            $hasAdminPanel = is_dir($fullPath . '/AdminPanel');
            $hasBgSlider   = is_dir($fullPath . '/AdminPanel/modules/BackgroundManager');

            $vhosts[] = [
                'domain'           => $entry,
                'type'             => $type,
                'hasAdminPanel'    => $hasAdminPanel,
                'hasBackgroundSlider' => $hasBgSlider,
                'hasPages'         => is_dir($fullPath . '/pages') || is_file($fullPath . '/data/menupages/pages.json'),
            ];
        }

        return $this->textResult(json_encode($vhosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function getVhostInfo(string $domain): array
    {
        $siteRoot = $this->vhostsBase . '/' . $domain;
        if (!is_dir($siteRoot)) {
            return $this->textResult(json_encode(['error' => "Domain not found: {$domain}"]));
        }

        $type = $this->detectSiteType($siteRoot);
        $hasAdminPanel = is_dir($siteRoot . '/AdminPanel');

        $info = [
            'domain'              => $domain,
            'type'                => $type,
            'siteRoot'            => $siteRoot,
            'hasAdminPanel'       => $hasAdminPanel,
            'hasBackgroundSlider' => is_dir($siteRoot . '/AdminPanel/modules/BackgroundManager'),
            'hasMenuPages'        => is_file($siteRoot . '/data/menupages/pages.json'),
            'hasCss'              => is_dir($siteRoot . '/css'),
            'hasLegacyConfig'     => is_file($siteRoot . '/conf/site_config.php'),
            'hasModernConfig'     => is_file($siteRoot . '/AdminPanel/config/site_config.json'),
        ];

        // List top-level directories
        $dirs = [];
        foreach (scandir($siteRoot) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir($siteRoot . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }
        $info['directories'] = $dirs;

        // List AdminPanel modules if present
        if ($hasAdminPanel && is_dir($siteRoot . '/AdminPanel/modules')) {
            $modules = [];
            foreach (scandir($siteRoot . '/AdminPanel/modules') as $m) {
                if ($m === '.' || $m === '..' || !is_dir($siteRoot . '/AdminPanel/modules/' . $m)) continue;
                $modules[] = $m;
            }
            $info['adminModules'] = $modules;
        }

        return $this->textResult(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function detectSiteType(string $siteRoot): string
    {
        if (is_file($siteRoot . '/AdminPanel/config/site_config.json')) {
            return 'modern';
        }
        if (is_file($siteRoot . '/conf/site_config.php')) {
            return 'legacy';
        }
        return 'static';
    }

    private function sanitizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        return preg_replace('/[^a-z0-9.\-]/', '', $domain);
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
$server = new VhostScannerServer();
$server->run();
