<?php
/**
 * MCP Registry
 *
 * Manages n-configured MCP server connections. Each "port" is a server entry
 * with type, command/URL, roles, and tags. Provides role-based routing for
 * the generation workflow.
 */
declare(strict_types=1);

require_once __DIR__ . '/MCPClient.php';

class MCPRegistry
{
    private string $configFile;
    private ?array $servers = null;
    /** @var MCPClient[] */
    private array $clients = [];

    public function __construct(string $configFile)
    {
        $this->configFile = $configFile;
    }

    /**
     * Load server definitions from config file.
     */
    public function loadServers(): array
    {
        if ($this->servers !== null) {
            return $this->servers;
        }

        if (is_file($this->configFile)) {
            $data = json_decode((string)file_get_contents($this->configFile), true);
            $this->servers = $data['servers'] ?? [];
        } else {
            $this->servers = $this->getDefaultServers();
            $this->saveServers($this->servers);
        }

        return $this->servers;
    }

    /**
     * Save server definitions to config file.
     */
    public function saveServers(array $servers): bool
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ok = file_put_contents(
            $this->configFile,
            json_encode(['servers' => $servers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        if ($ok !== false) {
            @chmod($this->configFile, 0664);
            $this->servers = $servers;
        }
        return $ok !== false;
    }

    /**
     * Add a new server. Returns the generated ID.
     */
    public function addServer(array $config): string
    {
        $servers = $this->loadServers();
        $id = $config['id'] ?? $this->generateId($config['name'] ?? 'server');
        $config['id'] = $id;
        $servers[$id] = $config;
        $this->saveServers($servers);
        return $id;
    }

    /**
     * Update an existing server's config.
     */
    public function updateServer(string $id, array $config): bool
    {
        $servers = $this->loadServers();
        if (!isset($servers[$id])) {
            return false;
        }

        // Don't overwrite auth credentials with masked values
        if (isset($config['auth']['key']) && strpos($config['auth']['key'], '••') !== false) {
            unset($config['auth']['key']);
        }
        if (isset($config['auth']['token']) && strpos($config['auth']['token'], '••') !== false) {
            unset($config['auth']['token']);
        }

        $servers[$id] = array_merge($servers[$id], $config);
        $servers[$id]['id'] = $id; // preserve original ID
        return $this->saveServers($servers);
    }

    /**
     * Remove a server (built-in servers cannot be removed).
     */
    public function removeServer(string $id): bool
    {
        $servers = $this->loadServers();
        if (!isset($servers[$id])) {
            return false;
        }
        if (!empty($servers[$id]['builtin'])) {
            return false; // Cannot remove built-in servers
        }

        // Disconnect if active
        $this->disconnectClient($id);
        unset($servers[$id]);
        return $this->saveServers($servers);
    }

    /**
     * Test a server's connectivity. Returns connection info and available tools.
     */
    public function testServer(string $id): array
    {
        $servers = $this->loadServers();
        if (!isset($servers[$id])) {
            return ['ok' => false, 'error' => 'Server not found'];
        }

        $serverConf = $servers[$id];
        $client = new MCPClient();

        try {
            if ($serverConf['type'] === 'stdio') {
                $cmd  = $serverConf['command'] ?? 'php';
                $args = $serverConf['args'] ?? [];
                if (!$client->connectStdio($cmd, $args)) {
                    return ['ok' => false, 'error' => 'Failed to spawn process'];
                }
            } elseif ($serverConf['type'] === 'http') {
                $url  = $serverConf['url'] ?? '';
                $auth = $serverConf['auth'] ?? [];
                if (!$client->connectHttp($url, $auth)) {
                    return ['ok' => false, 'error' => 'Failed to connect'];
                }
            } else {
                return ['ok' => false, 'error' => 'Unknown server type: ' . $serverConf['type']];
            }

            $initResult = $client->initialize();
            $tools      = $client->listTools();
            $resources  = $client->listResources();

            $client->disconnect();

            return [
                'ok'         => true,
                'serverInfo' => $initResult['serverInfo'] ?? [],
                'tools'      => $tools,
                'resources'  => $resources,
                'toolCount'  => count($tools),
            ];
        } catch (\Throwable $e) {
            $client->disconnect();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get a connected client for a server. Connects and initializes on first use.
     */
    public function getClient(string $id): MCPClient
    {
        if (isset($this->clients[$id]) && $this->clients[$id]->isConnected()) {
            return $this->clients[$id];
        }

        $servers = $this->loadServers();
        if (!isset($servers[$id])) {
            throw new \RuntimeException("MCP server not found: {$id}");
        }

        $serverConf = $servers[$id];
        if (empty($serverConf['enabled'])) {
            throw new \RuntimeException("MCP server disabled: {$id}");
        }

        $client = new MCPClient();

        if ($serverConf['type'] === 'stdio') {
            if (!$client->connectStdio($serverConf['command'] ?? 'php', $serverConf['args'] ?? [])) {
                throw new \RuntimeException("Failed to start MCP server: {$id}");
            }
        } elseif ($serverConf['type'] === 'http') {
            if (!$client->connectHttp($serverConf['url'] ?? '', $serverConf['auth'] ?? [])) {
                throw new \RuntimeException("Failed to connect to MCP server: {$id}");
            }
        }

        $client->initialize();
        $this->clients[$id] = $client;

        return $client;
    }

    /**
     * Get all tools from all enabled servers.
     */
    public function getAllTools(): array
    {
        $allTools = [];
        $servers = $this->loadServers();

        foreach ($servers as $id => $server) {
            if (empty($server['enabled'])) continue;

            try {
                $client = $this->getClient($id);
                $tools = $client->listTools();
                foreach ($tools as $tool) {
                    $tool['_serverId'] = $id;
                    $allTools[] = $tool;
                }
            } catch (\Throwable $e) {
                // Skip unreachable servers
                continue;
            }
        }

        return $allTools;
    }

    /**
     * Call a tool by name — routes to the correct server.
     */
    public function callTool(string $toolName, array $args = []): array
    {
        $servers = $this->loadServers();

        foreach ($servers as $id => $server) {
            if (empty($server['enabled'])) continue;

            try {
                $client = $this->getClient($id);
                $tools = $client->listTools();

                foreach ($tools as $tool) {
                    if ($tool['name'] === $toolName) {
                        return $client->callTool($toolName, $args);
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        throw new \RuntimeException("Tool not found: {$toolName}");
    }

    /**
     * Get servers filtered by role.
     */
    public function getServersByRole(string $role): array
    {
        $result = [];
        foreach ($this->loadServers() as $id => $server) {
            if (in_array($role, $server['roles'] ?? [], true)) {
                $result[$id] = $server;
            }
        }
        return $result;
    }

    /**
     * Get servers filtered by custom tag.
     */
    public function getServersByTag(string $tag): array
    {
        $result = [];
        foreach ($this->loadServers() as $id => $server) {
            if (in_array($tag, $server['tags'] ?? [], true)) {
                $result[$id] = $server;
            }
        }
        return $result;
    }

    /**
     * Get merged tools from all servers with a given role.
     */
    public function getToolsByRole(string $role): array
    {
        $tools = [];
        foreach ($this->getServersByRole($role) as $id => $server) {
            if (empty($server['enabled'])) continue;
            try {
                $client = $this->getClient($id);
                foreach ($client->listTools() as $tool) {
                    $tool['_serverId'] = $id;
                    $tools[] = $tool;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $tools;
    }

    /**
     * Disconnect all clients.
     */
    public function disconnectAll(): void
    {
        foreach ($this->clients as $id => $client) {
            $client->disconnect();
        }
        $this->clients = [];
    }

    private function disconnectClient(string $id): void
    {
        if (isset($this->clients[$id])) {
            $this->clients[$id]->disconnect();
            unset($this->clients[$id]);
        }
    }

    private function generateId(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
        $base = trim($base, '-');
        if ($base === '') $base = 'server';
        return $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function getDefaultServers(): array
    {
        $mcpDir = __DIR__;
        return [
            'site-context' => [
                'id'          => 'site-context',
                'name'        => 'Site Context',
                'type'        => 'stdio',
                'command'     => 'php',
                'args'        => [$mcpDir . '/site-context-server.php'],
                'enabled'     => true,
                'builtin'     => true,
                'roles'       => ['context'],
                'tags'        => [],
                'description' => 'Exposes site config, theme, pages, and menu data',
            ],
            'vhost-scanner' => [
                'id'          => 'vhost-scanner',
                'name'        => 'Vhost Scanner',
                'type'        => 'stdio',
                'command'     => 'php',
                'args'        => [$mcpDir . '/vhost-scanner-server.php'],
                'enabled'     => true,
                'builtin'     => true,
                'roles'       => ['context'],
                'tags'        => [],
                'description' => 'Discovers and lists all hosted websites',
            ],
        ];
    }
}
