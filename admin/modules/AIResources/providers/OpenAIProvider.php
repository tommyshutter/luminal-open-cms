<?php
/**
 * OpenAI Provider
 *
 * OpenAI / ChatGPT API integration (GPT-4o, GPT-4, etc.)
 * Implements the same AIProvider interface as ClaudeProvider.
 */
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';

class OpenAIProvider implements AIResources\Providers\AIProvider
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private string $apiUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct(string $apiKey, string $model = 'gpt-4o', int $maxTokens = 4096)
    {
        if (!function_exists("cred_vault_reveal") && defined("SITE_ROOT")
            && is_file(SITE_ROOT . "/admin/config/cred_vault.php")) {
            require_once SITE_ROOT . "/admin/config/cred_vault.php";
        }
        $this->apiKey    = function_exists("cred_vault_reveal") ? \cred_vault_reveal($apiKey) : $apiKey;
        $this->model     = $model;
        $this->maxTokens = $maxTokens;
    }

    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }

    public function getName(): string
    {
        return 'OpenAI';
    }

    public function getModels(): array
    {
        return [
            ['id' => 'gpt-4o',      'name' => 'GPT-4o',      'maxTokens' => 16384],
            ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'maxTokens' => 16384],
            ['id' => 'gpt-4-turbo', 'name' => 'GPT-4 Turbo', 'maxTokens' => 4096],
            ['id' => 'gpt-4',       'name' => 'GPT-4',       'maxTokens' => 8192],
            ['id' => 'o1',           'name' => 'o1',           'maxTokens' => 100000],
            ['id' => 'o3-mini',      'name' => 'o3 Mini',     'maxTokens' => 100000],
        ];
    }

    public function testConnection(): array
    {
        try {
            $response = $this->callApi([
                ['role' => 'user', 'content' => 'Reply with exactly: OK']
            ], 'You are a test endpoint. Reply with exactly one word: OK', 16);

            $content = $response['choices'][0]['message']['content'] ?? '';
            if ($content !== '') {
                return ['ok' => true, 'model' => $this->model, 'message' => 'Connected to ' . $this->model];
            }
            return ['ok' => false, 'model' => $this->model, 'message' => 'Empty response from API'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'model' => $this->model, 'message' => $e->getMessage()];
        }
    }

    public function generate(string $systemPrompt, string $userMessage, array $tools = [], ?callable $toolCallback = null): array
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // Convert MCP tools to OpenAI function calling format
        $functions = [];
        if (!empty($tools)) {
            foreach ($tools as $tool) {
                $schema = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()];
                $functions[] = [
                    'type' => 'function',
                    'function' => [
                        'name'        => $tool['name'],
                        'description' => $tool['description'] ?? '',
                        'parameters'  => $schema,
                    ],
                ];
            }
        }

        $allToolCalls = [];
        $totalUsage = ['input_tokens' => 0, 'output_tokens' => 0];
        $maxRounds = 5;

        for ($round = 0; $round < $maxRounds; $round++) {
            $response = $this->callApi($messages, '', $this->maxTokens, $functions);

            // Accumulate usage
            if (!empty($response['usage'])) {
                $totalUsage['input_tokens']  += $response['usage']['prompt_tokens'] ?? 0;
                $totalUsage['output_tokens'] += $response['usage']['completion_tokens'] ?? 0;
            }

            $choice = $response['choices'][0] ?? [];
            $message = $choice['message'] ?? [];
            $finishReason = $choice['finish_reason'] ?? 'stop';

            // Check for function calls
            $toolCalls = $message['tool_calls'] ?? [];
            if (empty($toolCalls) || $finishReason !== 'tool_calls') {
                return [
                    'content'   => $message['content'] ?? '',
                    'model'     => $response['model'] ?? $this->model,
                    'usage'     => $totalUsage,
                    'toolCalls' => $allToolCalls,
                ];
            }

            // Append assistant message with tool calls
            $messages[] = $message;

            // Execute each tool call
            foreach ($toolCalls as $tc) {
                $fnName = $tc['function']['name'] ?? '';
                $fnArgs = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $tcId   = $tc['id'] ?? '';

                $allToolCalls[] = ['name' => $fnName, 'input' => $fnArgs];

                $result = '';
                if ($toolCallback !== null) {
                    try {
                        $result = $toolCallback($fnName, $fnArgs);
                        if (is_array($result)) {
                            $result = json_encode($result, JSON_UNESCAPED_SLASHES);
                        }
                    } catch (\Throwable $e) {
                        $result = json_encode(['error' => $e->getMessage()]);
                    }
                } else {
                    $result = json_encode(['error' => 'No tool callback configured']);
                }

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tcId,
                    'content'      => (string)$result,
                ];
            }
        }

        // Fallback
        return [
            'content'   => '',
            'model'     => $this->model,
            'usage'     => $totalUsage,
            'toolCalls' => $allToolCalls,
        ];
    }

    private function callApi(array $messages, string $system, int $maxTokens, array $tools = []): array
    {
        // If system prompt passed separately, prepend as system message
        if ($system !== '' && ($messages[0]['role'] ?? '') !== 'system') {
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException('OpenAI API connection error: ' . $curlErr);
        }

        $data = json_decode((string)$body, true);

        if ($httpCode !== 200) {
            $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException('OpenAI API error: ' . $errMsg);
        }

        return $data;
    }
}
