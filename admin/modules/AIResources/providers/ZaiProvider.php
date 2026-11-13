<?php
/**
 * Z.ai (Zhipu AI) Provider
 *
 * GLM model family via OpenAI-compatible chat completions API.
 * Supports tool/function calling for agent pipelines.
 *
 * API docs: https://docs.z.ai/api-reference/introduction
 * Models:   GLM-5, GLM-4.7, GLM-4.7-Flash, GLM-4.6, GLM-4.5V
 */
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';

class ZaiProvider implements AIResources\Providers\AIProvider
{
    private string $apiKey;
    private string $model;
    private int    $maxTokens;
    private string $apiBase = 'https://api.z.ai/api/paas/v4';
    private int    $maxToolRounds = 5;

    public function __construct(string $apiKey, string $model = 'glm-4.7-flash', int $maxTokens = 4096)
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
        return 'Z.ai (Zhipu)';
    }

    public function getModels(): array
    {
        return [
            // --- Free tier (no cost, no rate limits) ---
            ['id' => 'glm-4.7-flash',   'name' => 'GLM-4.7 Flash [FREE]',        'maxTokens' => 16384],
            ['id' => 'glm-4.5-flash',   'name' => 'GLM-4.5 Flash [FREE]',        'maxTokens' => 16384],
            ['id' => 'glm-4.6v-flash',  'name' => 'GLM-4.6V Flash [FREE/Vision]', 'maxTokens' => 8192],
            // --- Paid models ---
            ['id' => 'glm-5',           'name' => 'GLM-5 (Flagship $1/$3.2)',     'maxTokens' => 16384],
            ['id' => 'glm-5-code',      'name' => 'GLM-5 Code ($1.2/$5)',         'maxTokens' => 16384],
            ['id' => 'glm-4.7',         'name' => 'GLM-4.7 (200K $0.6/$2.2)',     'maxTokens' => 16384],
            ['id' => 'glm-4.7-flashx',  'name' => 'GLM-4.7 FlashX ($0.07/$0.4)', 'maxTokens' => 16384],
            ['id' => 'glm-4.6',         'name' => 'GLM-4.6 ($0.6/$2.2)',          'maxTokens' => 16384],
            ['id' => 'glm-4.6v',        'name' => 'GLM-4.6V Vision ($0.3/$0.9)',  'maxTokens' => 8192],
            ['id' => 'glm-4.5v',        'name' => 'GLM-4.5V Vision ($0.6/$1.8)',  'maxTokens' => 8192],
        ];
    }

    public function testConnection(): array
    {
        try {
            $body = [
                'model'      => $this->model,
                'messages'   => [
                    ['role' => 'system', 'content' => 'Reply with exactly one word: OK'],
                    ['role' => 'user',   'content' => 'Hi'],
                ],
                'max_tokens' => 8,
            ];
            $result = $this->callApi('/chat/completions', $body);
            $content = $result['choices'][0]['message']['content'] ?? '';
            if ($content !== '') {
                return ['ok' => true, 'model' => $result['model'] ?? $this->model, 'message' => 'Connected to ' . $this->model];
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

        // Convert MCP tools to OpenAI function-calling format
        $oaiTools = [];
        if (!empty($tools)) {
            foreach ($tools as $tool) {
                $schema = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass()];
                unset($tool['_serverId']);
                $oaiTools[] = [
                    'type'     => 'function',
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

        for ($round = 0; $round < $this->maxToolRounds; $round++) {
            $body = [
                'model'       => $this->model,
                'messages'    => $messages,
                'max_tokens'  => $this->maxTokens,
                'temperature' => 0.7,
            ];

            if (!empty($oaiTools)) {
                $body['tools'] = $oaiTools;
            }

            $result = $this->callApi('/chat/completions', $body);

            // Accumulate usage
            $usage = $result['usage'] ?? [];
            $totalUsage['input_tokens']  += $usage['prompt_tokens'] ?? 0;
            $totalUsage['output_tokens'] += $usage['completion_tokens'] ?? 0;

            $choice  = $result['choices'][0] ?? [];
            $message = $choice['message'] ?? [];
            $content = $message['content'] ?? '';
            $fnCalls = $message['tool_calls'] ?? [];

            // If no tool calls, return text
            if (empty($fnCalls)) {
                return [
                    'content'   => $content,
                    'model'     => $result['model'] ?? $this->model,
                    'usage'     => $totalUsage,
                    'toolCalls' => $allToolCalls,
                ];
            }

            // Append assistant message (with tool_calls) to conversation
            $messages[] = $message;

            // Execute each tool call and add results
            foreach ($fnCalls as $tc) {
                $fnName = $tc['function']['name'] ?? '';
                $fnArgs = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $tcId   = $tc['id'] ?? '';

                $allToolCalls[] = ['name' => $fnName, 'input' => $fnArgs];

                $toolResult = '';
                if ($toolCallback !== null) {
                    try {
                        $toolResult = $toolCallback($fnName, $fnArgs);
                        if (is_array($toolResult)) {
                            $toolResult = json_encode($toolResult, JSON_UNESCAPED_SLASHES);
                        }
                    } catch (\Throwable $e) {
                        $toolResult = json_encode(['error' => $e->getMessage()]);
                    }
                } else {
                    $toolResult = json_encode(['error' => 'No tool callback configured']);
                }

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tcId,
                    'content'      => (string)$toolResult,
                ];
            }
        }

        // Max rounds exhausted
        return [
            'content'   => '',
            'model'     => $this->model,
            'usage'     => $totalUsage,
            'toolCalls' => $allToolCalls,
        ];
    }

    private function callApi(string $path, array $body): array
    {
        $url = $this->apiBase . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);

        if ($err) {
            throw new \RuntimeException("Z.ai connection failed: {$err}");
        }

        $data = json_decode((string)$response, true);

        if ($httpCode >= 400) {
            $errMsg = $data['error']['message'] ?? $data['error'] ?? "HTTP {$httpCode}";
            if (is_array($errMsg)) {
                $errMsg = json_encode($errMsg);
            }
            throw new \RuntimeException("Z.ai API error: {$errMsg}");
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response from Z.ai API');
        }

        return $data;
    }
}
