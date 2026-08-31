<?php
/**
 * Claude AI Provider
 *
 * Anthropic Claude API integration with:
 *   - Tool use (multi-turn loop)
 *   - Prompt caching (cache_control on system + large user context)
 *   - Extended thinking (budget_tokens)
 *   - Streaming (optional chunk callback)
 *   - Model tiering (per-pipeline model override via ProviderRegistry)
 *
 * Options array (4th constructor param):
 *   caching          bool   — wrap system prompt in cache_control block (default: true)
 *   thinking         bool   — enable extended thinking (default: false)
 *   thinking_budget  int    — thinking budget in tokens (default: 8000)
 */
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';

class ClaudeProvider implements AIResources\Providers\AIProvider
{
    private string  $apiKey;
    private string  $model;
    private int     $maxTokens;
    private string  $apiUrl     = 'https://api.anthropic.com/v1/messages';
    private string  $apiVersion = '2024-11-01';
    private int     $maxToolRounds = 5;

    // Feature flags
    private bool    $cachingEnabled;
    private bool    $thinkingEnabled;
    private int     $thinkingBudget;

    // Minimum chars before caching the user message context block
    private const CACHE_USER_MIN_LEN = 2000;

    public function __construct(
        string $apiKey,
        string $model      = 'claude-sonnet-4-6',
        int    $maxTokens  = 10000,
        array  $options    = []
    ) {
        // Credential Vault: un-seal the API key (plaintext passes through untouched).
        if (!function_exists('cred_vault_reveal') && defined('SITE_ROOT')
            && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
            require_once SITE_ROOT . '/admin/config/cred_vault.php';
        }
        $this->apiKey          = function_exists('cred_vault_reveal') ? \cred_vault_reveal($apiKey) : $apiKey;
        $this->model           = $model;
        $this->maxTokens       = $maxTokens;
        $this->cachingEnabled  = (bool)($options['caching']         ?? true);
        $this->thinkingEnabled = (bool)($options['thinking']        ?? false);
        $this->thinkingBudget  = (int) ($options['thinking_budget'] ?? 8000);
    }

    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }

    public function getName(): string
    {
        return 'Anthropic Claude';
    }

    public function getModels(): array
    {
        return [
            ['id' => 'claude-opus-4-6',           'name' => 'Claude Opus 4.6',   'maxTokens' => 32000],
            ['id' => 'claude-sonnet-4-6',          'name' => 'Claude Sonnet 4.6', 'maxTokens' => 16000],
            ['id' => 'claude-sonnet-4-5-20250929', 'name' => 'Claude Sonnet 4.5', 'maxTokens' => 8192],
            ['id' => 'claude-haiku-4-5-20251001',  'name' => 'Claude Haiku 4.5',  'maxTokens' => 8192],
        ];
    }

    public function testConnection(): array
    {
        try {
            // Disable caching/thinking for the simple ping
            $orig = [$this->cachingEnabled, $this->thinkingEnabled];
            $this->cachingEnabled = $this->thinkingEnabled = false;

            $response = $this->callApi(
                [['role' => 'user', 'content' => 'Reply with exactly: OK']],
                'You are a test endpoint. Reply with exactly one word: OK',
                [], 16
            );

            [$this->cachingEnabled, $this->thinkingEnabled] = $orig;

            if (!empty($response['content'])) {
                return ['ok' => true,  'model' => $this->model, 'message' => 'Connected to ' . $this->model];
            }
            return ['ok' => false, 'model' => $this->model, 'message' => 'Empty response from API'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'model' => $this->model, 'message' => $e->getMessage()];
        }
    }

    /**
     * Generate a response.
     *
     * @param string        $systemPrompt   System / instruction text
     * @param string        $userMessage    User turn content
     * @param array         $tools          MCP tool definitions
     * @param callable|null $toolCallback   Executed when Claude calls a tool
     * @param callable|null $streamCallback fn(string $chunk) — called with each text token when streaming
     */
    public function generate(
        string    $systemPrompt,
        string    $userMessage,
        array     $tools          = [],
        ?callable $toolCallback   = null,
        ?callable $streamCallback = null
    ): array {
        // Build initial messages array, optionally caching large user context
        $firstUserContent = $this->buildUserContent($userMessage);
        $messages = [['role' => 'user', 'content' => $firstUserContent]];

        $cleanTools      = array_map(function($t) { unset($t['_serverId']); return $t; }, $tools);
        $anthropicTools  = $this->convertToolsToAnthropic($cleanTools);

        $allToolCalls    = [];
        $finalContent    = '';
        $totalUsage      = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0];

        for ($round = 0; $round < $this->maxToolRounds; $round++) {
            if ($streamCallback !== null && $round === 0) {
                // Streaming mode — only on the first (non-tool) round
                $streamResult = $this->callApiStream($messages, $systemPrompt, $anthropicTools, $this->maxTokens, $streamCallback);
                $response = $streamResult['response'];
                $finalContent = $streamResult['text'];
            } else {
                $response = $this->callApi($messages, $systemPrompt, $anthropicTools, $this->maxTokens);
            }

            // Accumulate usage (including cache stats)
            foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
                $totalUsage[$key] += $response['usage'][$key] ?? 0;
            }

            $stopReason = $response['stop_reason'] ?? 'end_turn';

            // Separate text, thinking, and tool_use blocks
            $textBlocks    = [];
            $toolUseBlocks = [];
            foreach ($response['content'] ?? [] as $block) {
                match ($block['type'] ?? '') {
                    'text'      => $textBlocks[]    = $block['text'],
                    'tool_use'  => $toolUseBlocks[] = $block,
                    'thinking'  => null, // Extended thinking — internal, not surfaced to caller
                    default     => null,
                };
            }

            if (empty($toolUseBlocks) || $stopReason !== 'tool_use') {
                if ($streamCallback === null) {
                    $finalContent = implode("\n", $textBlocks);
                }
                break;
            }

            // Tool call round — sanitize and append assistant turn
            $sanitizedContent = array_map(function ($block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $block['input'] = $this->ensureObject($block['input'] ?? []);
                }
                return $block;
            }, $response['content'] ?? []);
            $messages[] = ['role' => 'assistant', 'content' => $sanitizedContent];

            // Execute tools and build result turn
            $toolResults = [];
            foreach ($toolUseBlocks as $toolUse) {
                $allToolCalls[] = ['name' => $toolUse['name'], 'input' => $toolUse['input'] ?? []];
                $result = '';
                if ($toolCallback !== null) {
                    try {
                        $result = $toolCallback($toolUse['name'], $toolUse['input'] ?? []);
                        if (is_array($result)) $result = json_encode($result, JSON_UNESCAPED_SLASHES);
                    } catch (\Throwable $e) {
                        $result = json_encode(['error' => $e->getMessage()]);
                    }
                } else {
                    $result = json_encode(['error' => 'No tool callback configured']);
                }
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $toolUse['id'],
                    'content'     => (string)$result,
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return [
            'content'   => $finalContent,
            'model'     => $response['model'] ?? $this->model,
            'usage'     => $totalUsage,
            'toolCalls' => $allToolCalls,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build user message content, optionally marking large context for caching.
     */
    private function buildUserContent(string $userMessage): array|string
    {
        if (!$this->cachingEnabled || strlen($userMessage) < self::CACHE_USER_MIN_LEN) {
            return $userMessage;
        }
        // Cache the large user context block — saves on repeated pipeline runs
        return [
            [
                'type'          => 'text',
                'text'          => $userMessage,
                'cache_control' => ['type' => 'ephemeral'],
            ]
        ];
    }

    /**
     * Build the system param — array with cache_control when caching is on.
     */
    private function buildSystemParam(string $system): array|string
    {
        if (!$this->cachingEnabled || $system === '') {
            return $system;
        }
        return [
            [
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]
        ];
    }

    /**
     * Build HTTP headers array.
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->apiVersion,
        ];

        $betas = [];
        if ($this->cachingEnabled)  $betas[] = 'prompt-caching-2024-07-31';

        if (!empty($betas)) {
            $headers[] = 'anthropic-beta: ' . implode(',', $betas);
        }

        return $headers;
    }

    /**
     * Build the request payload.
     */
    private function buildPayload(array $messages, string $system, array $tools, int $maxTokens, bool $stream = false): array
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];

        $sysParam = $this->buildSystemParam($system);
        if ($sysParam !== '') {
            $payload['system'] = $sysParam;
        }

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        if ($this->thinkingEnabled) {
            // Thinking requires max_tokens > budget_tokens
            $payload['max_tokens']  = max($maxTokens, $this->thinkingBudget + 1000);
            $payload['thinking']    = ['type' => 'enabled', 'budget_tokens' => $this->thinkingBudget];
            $payload['temperature'] = 1; // Required when thinking is enabled
        }

        if ($stream) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /**
     * Make a synchronous API call.
     */
    private function callApi(array $messages, string $system, array $tools, int $maxTokens): array
    {
        $payload = $this->buildPayload($messages, $system, $tools, $maxTokens);

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        $body    = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        if ($curlErr !== '') throw new \RuntimeException('Claude API connection error: ' . $curlErr);

        $data = json_decode((string)$body, true);

        if ($code !== 200) {
            $errMsg = $data['error']['message'] ?? "HTTP {$code}";
            throw new \RuntimeException('Claude API error: ' . $errMsg);
        }

        return $data;
    }

    /**
     * Make a streaming API call, invoking $onChunk($text) for each text delta.
     * Returns ['response' => $syntheticResponse, 'text' => $fullText].
     */
    private function callApiStream(array $messages, string $system, array $tools, int $maxTokens, callable $onChunk): array
    {
        $payload = $this->buildPayload($messages, $system, $tools, $maxTokens, stream: true);

        $buffer         = '';
        $fullText       = '';
        $usage          = [];
        $stopReason     = 'end_turn';
        $model          = $this->model;
        $inThinkingBlock = false;

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_TIMEOUT    => 180,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                &$buffer, &$fullText, &$usage, &$stopReason, &$model, &$inThinkingBlock, $onChunk
            ) {
                $buffer .= $data;
                // Process complete SSE lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    if (!str_starts_with($line, 'data: ')) continue;
                    $json = json_decode(substr($line, 6), true);
                    if (!$json) continue;

                    switch ($json['type'] ?? '') {
                        case 'message_start':
                            $usage = $json['message']['usage'] ?? [];
                            $model = $json['message']['model'] ?? $model;
                            break;

                        case 'content_block_start':
                            // Track whether we're inside a thinking block
                            $blockType = $json['content_block']['type'] ?? '';
                            $inThinkingBlock = ($blockType === 'thinking');
                            break;

                        case 'content_block_delta':
                            $delta = $json['delta'] ?? [];
                            if (!$inThinkingBlock && ($delta['type'] ?? '') === 'text_delta') {
                                $chunk      = $delta['text'] ?? '';
                                $fullText  .= $chunk;
                                $onChunk($chunk);
                            }
                            break;

                        case 'content_block_stop':
                            $inThinkingBlock = false;
                            break;

                        case 'message_delta':
                            $stopReason = $json['delta']['stop_reason'] ?? $stopReason;
                            // Merge delta usage
                            foreach ($json['usage'] ?? [] as $k => $v) {
                                $usage[$k] = ($usage[$k] ?? 0) + $v;
                            }
                            break;
                    }
                }
                return strlen($data);
            },
        ]);

        $curlErr = '';
        curl_exec($ch);
        $curlErr = curl_error($ch);

        if ($curlErr !== '') throw new \RuntimeException('Claude streaming error: ' . $curlErr);

        // Synthesize a response object matching the non-streaming shape
        $response = [
            'model'       => $model,
            'stop_reason' => $stopReason,
            'usage'       => $usage,
            'content'     => [['type' => 'text', 'text' => $fullText]],
        ];

        return ['response' => $response, 'text' => $fullText];
    }

    private function convertToolsToAnthropic(array $mcpTools): array
    {
        $converted = [];
        foreach ($mcpTools as $tool) {
            $schema = $this->sanitizeSchema($tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()]);
            $converted[] = [
                'name'         => $tool['name'],
                'description'  => $tool['description'] ?? '',
                'input_schema' => $schema,
            ];
        }
        return $converted;
    }

    private function sanitizeSchema($schema)
    {
        if (!is_array($schema)) return $schema;
        $objectKeys = ['properties', 'patternProperties', 'definitions', 'additionalProperties'];
        foreach ($schema as $key => &$value) {
            if (is_array($value)) {
                $value = (in_array($key, $objectKeys, true) && empty($value))
                    ? new \stdClass()
                    : $this->sanitizeSchema($value);
            }
        }
        unset($value);
        return $schema;
    }

    private function ensureObject($value)
    {
        if (is_array($value) && empty($value)) return new \stdClass();
        if (is_array($value)) {
            foreach ($value as $k => &$v) {
                if (is_array($v) && empty($v) && is_string($k)) $v = new \stdClass();
            }
            unset($v);
        }
        return $value;
    }
}
