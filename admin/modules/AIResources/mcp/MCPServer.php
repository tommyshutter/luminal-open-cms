<?php
/**
 * MCP Server Base Class
 *
 * JSON-RPC 2.0 handler for the Model Context Protocol (spec 2025-11-25).
 * Concrete servers extend this class and implement tool/resource methods.
 *
 * Protocol: newline-delimited JSON over stdio.
 * Lifecycle: initialize -> notifications/initialized -> operations -> shutdown
 */
declare(strict_types=1);

abstract class MCPServer
{
    private string $protocolVersion = '2025-11-25';

    /**
     * Server identity: {name, version}
     */
    abstract protected function getServerInfo(): array;

    /**
     * Return array of MCP tool definitions.
     * Each: {name, description, inputSchema: {type: 'object', properties: {...}, required: [...]}}
     */
    abstract protected function getTools(): array;

    /**
     * Execute a tool by name with arguments. Return {content: [{type: 'text', text: '...'}]}
     */
    abstract protected function executeTool(string $name, array $args): array;

    /**
     * Return array of resource definitions (optional).
     * Each: {uri, name, description, mimeType}
     */
    protected function getResources(): array
    {
        return [];
    }

    /**
     * Read a resource by URI (optional).
     * Return {contents: [{uri, mimeType, text}]}
     */
    protected function readResource(string $uri): array
    {
        return ['contents' => []];
    }

    /**
     * Main loop: read JSON-RPC from stdin, dispatch, write to stdout.
     */
    public function run(): void
    {
        // Ensure stderr is available for logging
        $stderr = fopen('php://stderr', 'w');
        $stdin  = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');

        if (!$stdin || !$stdout) {
            fwrite($stderr, "MCPServer: Cannot open stdio\n");
            exit(1);
        }

        stream_set_blocking($stdin, true);

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $request = json_decode($line, true);
            if (!is_array($request)) {
                $this->writeResponse($stdout, $this->errorResponse(null, -32700, 'Parse error'));
                continue;
            }

            $id     = $request['id'] ?? null;
            $method = $request['method'] ?? '';
            $params = $request['params'] ?? [];

            // Notifications (no id) — just acknowledge silently
            if ($id === null) {
                // notifications/initialized — no response needed
                if ($method === 'notifications/initialized') {
                    fwrite($stderr, "MCPServer: Client initialized\n");
                }
                continue;
            }

            try {
                $result = $this->dispatch($method, $params);
                $this->writeResponse($stdout, [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => $result,
                ]);
            } catch (\Throwable $e) {
                $this->writeResponse($stdout, $this->errorResponse($id, -32603, $e->getMessage()));
            }
        }
    }

    /**
     * Dispatch a JSON-RPC method to the appropriate handler.
     */
    private function dispatch(string $method, array $params): array
    {
        switch ($method) {
            case 'initialize':
                return $this->handleInitialize($params);

            case 'tools/list':
                return ['tools' => $this->getTools()];

            case 'tools/call':
                $name = $params['name'] ?? '';
                $args = $params['arguments'] ?? [];
                return $this->executeTool($name, $args);

            case 'resources/list':
                return ['resources' => $this->getResources()];

            case 'resources/read':
                $uri = $params['uri'] ?? '';
                return $this->readResource($uri);

            case 'ping':
                return [];

            default:
                throw new \RuntimeException("Method not found: {$method}");
        }
    }

    private function handleInitialize(array $params): array
    {
        $info = $this->getServerInfo();
        return [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => [
                'tools'     => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'serverInfo' => [
                'name'    => $info['name'] ?? 'mcp-server',
                'version' => $info['version'] ?? '1.0.0',
            ],
        ];
    }

    private function writeResponse($stream, array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES);
        fwrite($stream, $json . "\n");
        fflush($stream);
    }

    private function errorResponse($id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
    }
}
