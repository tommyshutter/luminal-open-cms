<?php
/**
 * MCP Client
 *
 * Connects to MCP servers via stdio (subprocess) or HTTP.
 * Implements JSON-RPC 2.0 over newline-delimited JSON per MCP spec 2025-11-25.
 */
declare(strict_types=1);

class MCPClient
{
    private string $type = '';           // 'stdio' or 'http'
    private $process = null;             // proc_open resource (stdio)
    private ?array $pipes = null;        // [stdin, stdout, stderr] pipes
    private string $httpUrl = '';         // HTTP endpoint URL
    private array $httpAuth = [];        // HTTP auth config
    private int $requestId = 0;
    private bool $initialized = false;
    private float $timeout = 30.0;       // seconds

    /**
     * Connect to a stdio MCP server (spawn subprocess).
     */
    public function connectStdio(string $command, array $args = []): bool
    {
        $this->type = 'stdio';

        $fullCommand = escapeshellarg($command);
        foreach ($args as $arg) {
            $fullCommand .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // child stdin  (parent writes)
            1 => ['pipe', 'w'],  // child stdout (parent reads)
            2 => ['pipe', 'w'],  // child stderr (parent reads)
        ];

        $this->process = proc_open($fullCommand, $descriptors, $pipes);
        if (!is_resource($this->process)) {
            return false;
        }

        $this->pipes = $pipes;

        // Set stdout to non-blocking for timeout support
        stream_set_blocking($this->pipes[1], false);

        return true;
    }

    /**
     * Connect to an HTTP MCP server.
     */
    public function connectHttp(string $url, array $auth = []): bool
    {
        $this->type    = 'http';
        $this->httpUrl = $url;
        $this->httpAuth = $auth;
        return true;
    }

    /**
     * Perform MCP initialize handshake.
     */
    public function initialize(): array
    {
        $result = $this->sendRequest('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => [
                'name'    => 'AIResources',
                'version' => '1.0.0',
            ],
        ]);

        // Send initialized notification
        $this->sendNotification('notifications/initialized');
        $this->initialized = true;

        return $result;
    }

    /**
     * List available tools from the server.
     */
    public function listTools(): array
    {
        $result = $this->sendRequest('tools/list', []);
        return $result['tools'] ?? [];
    }

    /**
     * Call a tool on the server.
     */
    public function callTool(string $name, array $args = []): array
    {
        return $this->sendRequest('tools/call', [
            'name'      => $name,
            'arguments' => (object)$args,
        ]);
    }

    /**
     * List available resources.
     */
    public function listResources(): array
    {
        $result = $this->sendRequest('resources/list', []);
        return $result['resources'] ?? [];
    }

    /**
     * Read a resource by URI.
     */
    public function readResource(string $uri): array
    {
        return $this->sendRequest('resources/read', ['uri' => $uri]);
    }

    /**
     * Clean up: close process or connection.
     */
    public function disconnect(): void
    {
        if ($this->type === 'stdio' && $this->pipes !== null) {
            // Close stdin to signal EOF
            if (is_resource($this->pipes[0])) {
                fclose($this->pipes[0]);
            }
            if (is_resource($this->pipes[1])) {
                fclose($this->pipes[1]);
            }
            if (is_resource($this->pipes[2])) {
                fclose($this->pipes[2]);
            }
            if (is_resource($this->process)) {
                proc_terminate($this->process);
                proc_close($this->process);
            }
            $this->pipes = null;
            $this->process = null;
        }

        $this->initialized = false;
    }

    public function isConnected(): bool
    {
        if ($this->type === 'stdio') {
            return is_resource($this->process) && $this->pipes !== null;
        }
        if ($this->type === 'http') {
            return $this->httpUrl !== '';
        }
        return false;
    }

    /**
     * Send a JSON-RPC request and wait for the response.
     */
    private function sendRequest(string $method, $params): array
    {
        $this->requestId++;
        $request = [
            'jsonrpc' => '2.0',
            'id'      => $this->requestId,
            'method'  => $method,
            'params'  => $params,
        ];

        if ($this->type === 'stdio') {
            return $this->sendStdio($request);
        }
        if ($this->type === 'http') {
            return $this->sendHttp($request);
        }

        throw new \RuntimeException('MCPClient: Not connected');
    }

    /**
     * Send a notification (no id, no response expected).
     */
    private function sendNotification(string $method, $params = null): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method'  => $method,
        ];
        if ($params !== null) {
            $notification['params'] = $params;
        }

        if ($this->type === 'stdio') {
            $json = json_encode($notification, JSON_UNESCAPED_SLASHES);
            fwrite($this->pipes[0], $json . "\n");
            fflush($this->pipes[0]);
        }
        // HTTP notifications: fire-and-forget POST (not waiting for response)
    }

    /**
     * Send request over stdio pipes.
     */
    private function sendStdio(array $request): array
    {
        if (!is_resource($this->pipes[0]) || !is_resource($this->pipes[1])) {
            throw new \RuntimeException('MCPClient: stdio pipes not available');
        }

        $json = json_encode($request, JSON_UNESCAPED_SLASHES);
        fwrite($this->pipes[0], $json . "\n");
        fflush($this->pipes[0]);

        // Read response with timeout
        $deadline = microtime(true) + $this->timeout;
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = fgets($this->pipes[1]);
            if ($chunk !== false && trim($chunk) !== '') {
                $buffer = trim($chunk);
                break;
            }
            // Small sleep to avoid busy-waiting
            usleep(10000); // 10ms
        }

        if ($buffer === '') {
            // Check stderr for error messages
            $stderr = '';
            if (is_resource($this->pipes[2])) {
                stream_set_blocking($this->pipes[2], false);
                $stderr = (string)stream_get_contents($this->pipes[2]);
            }
            throw new \RuntimeException('MCPClient: Timeout waiting for response' . ($stderr ? ": {$stderr}" : ''));
        }

        $response = json_decode($buffer, true);
        if (!is_array($response)) {
            throw new \RuntimeException('MCPClient: Invalid JSON response');
        }

        if (isset($response['error'])) {
            $err = $response['error'];
            throw new \RuntimeException('MCP error: ' . ($err['message'] ?? 'Unknown'));
        }

        return $response['result'] ?? [];
    }

    /**
     * Send request over HTTP.
     */
    private function sendHttp(array $request): array
    {
        $headers = ['Content-Type: application/json'];

        // Auth headers
        $authType = $this->httpAuth['type'] ?? 'none';
        if ($authType === 'api_key' && !empty($this->httpAuth['key'])) {
            $headers[] = 'X-API-Key: ' . $this->httpAuth['key'];
        } elseif ($authType === 'bearer' && !empty($this->httpAuth['token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->httpAuth['token'];
        }

        $ch = curl_init($this->httpUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)$this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($request, JSON_UNESCAPED_SLASHES),
        ]);

        $body    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException('MCPClient HTTP error: ' . $curlErr);
        }

        $response = json_decode((string)$body, true);
        if (!is_array($response)) {
            throw new \RuntimeException("MCPClient: Invalid response (HTTP {$httpCode})");
        }

        if (isset($response['error'])) {
            throw new \RuntimeException('MCP error: ' . ($response['error']['message'] ?? 'Unknown'));
        }

        return $response['result'] ?? [];
    }
}
