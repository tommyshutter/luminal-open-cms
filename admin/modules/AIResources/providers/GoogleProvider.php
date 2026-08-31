<?php
/**
 * Google Gemini Provider
 *
 * Google Gemini API integration (Gemini 2.0, 1.5, etc.)
 * Implements the same AIProvider interface as ClaudeProvider.
 */
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';

class GoogleProvider implements AIResources\Providers\AIProvider
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private string $apiBase = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(string $apiKey, string $model = 'gemini-2.5-flash', int $maxTokens = 8192)
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
        return 'Google Gemini';
    }

    public function getModels(): array
    {
        return [
            ['id' => 'gemini-2.5-flash',        'name' => 'Gemini 2.5 Flash',                          'maxTokens' => 65536],
            ['id' => 'gemini-2.5-pro',          'name' => 'Gemini 2.5 Pro',                            'maxTokens' => 65536],
            ['id' => 'gemini-2.0-flash',        'name' => 'Gemini 2.0 Flash (retiring Jun 2026)',      'maxTokens' => 8192],
            ['id' => 'gemini-2.0-flash-lite',   'name' => 'Gemini 2.0 Flash Lite (retiring Jun 2026)', 'maxTokens' => 8192],
        ];
    }

    public function testConnection(): array
    {
        try {
            $response = $this->callApi(
                [['role' => 'user', 'parts' => [['text' => 'Reply with exactly: OK']]]],
                'You are a test endpoint. Reply with exactly one word: OK',
                16
            );

            $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
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
        $contents = [
            ['role' => 'user', 'parts' => [['text' => $userMessage]]]
        ];

        // Convert MCP tools to Gemini function declarations
        $functionDeclarations = [];
        if (!empty($tools)) {
            foreach ($tools as $tool) {
                $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()];
                // Ensure properties is always an object for Gemini API
                if (isset($schema['properties']) && is_array($schema['properties']) && empty($schema['properties'])) {
                    $schema['properties'] = new \stdClass();
                }
                $functionDeclarations[] = [
                    'name'        => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters'  => $schema,
                ];
            }
        }

        $allToolCalls = [];
        $totalUsage = ['input_tokens' => 0, 'output_tokens' => 0];
        $maxRounds = 5;

        for ($round = 0; $round < $maxRounds; $round++) {
            $response = $this->callApi($contents, $systemPrompt, $this->maxTokens, $functionDeclarations);

            // Accumulate usage
            if (!empty($response['usageMetadata'])) {
                $totalUsage['input_tokens']  += $response['usageMetadata']['promptTokenCount'] ?? 0;
                $totalUsage['output_tokens'] += $response['usageMetadata']['candidatesTokenCount'] ?? 0;
            }

            $candidate = $response['candidates'][0] ?? [];
            $parts = $candidate['content']['parts'] ?? [];

            // Separate text and function call parts
            $textParts = [];
            $fnCallParts = [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $textParts[] = $part['text'];
                }
                if (isset($part['functionCall'])) {
                    $fnCallParts[] = $part['functionCall'];
                }
            }

            // If no function calls, return text
            if (empty($fnCallParts)) {
                return [
                    'content'   => implode("\n", $textParts),
                    'model'     => $this->model,
                    'usage'     => $totalUsage,
                    'toolCalls' => $allToolCalls,
                ];
            }

            // Append model response to contents
            // Fix PHP JSON round-trip: empty {} → [] → [] instead of {}.
            // Gemini requires args to be an object, not an array.
            $fixedParts = [];
            foreach ($parts as $part) {
                if (isset($part['functionCall']) && isset($part['functionCall']['args'])) {
                    if (empty($part['functionCall']['args'])) {
                        $part['functionCall']['args'] = new \stdClass();
                    }
                }
                $fixedParts[] = $part;
            }
            $contents[] = ['role' => 'model', 'parts' => $fixedParts];

            // Execute function calls
            $responseParts = [];
            foreach ($fnCallParts as $fnCall) {
                $fnName = $fnCall['name'] ?? '';
                $fnArgs = $fnCall['args'] ?? [];

                $allToolCalls[] = ['name' => $fnName, 'input' => $fnArgs];

                $result = '';
                if ($toolCallback !== null) {
                    try {
                        $result = $toolCallback($fnName, is_array($fnArgs) ? $fnArgs : []);
                        if (is_array($result)) {
                            $result = json_encode($result, JSON_UNESCAPED_SLASHES);
                        }
                    } catch (\Throwable $e) {
                        $result = json_encode(['error' => $e->getMessage()]);
                    }
                } else {
                    $result = json_encode(['error' => 'No tool callback configured']);
                }

                $responseParts[] = [
                    'functionResponse' => [
                        'name'     => $fnName,
                        'response' => json_decode((string)$result, true) ?: ['result' => $result],
                    ],
                ];
            }

            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        return [
            'content'   => '',
            'model'     => $this->model,
            'usage'     => $totalUsage,
            'toolCalls' => $allToolCalls,
        ];
    }

    private function callApi(array $contents, string $systemInstruction, int $maxTokens, array $functionDeclarations = []): array
    {
        $url = $this->apiBase . $this->model . ':generateContent?key=' . $this->apiKey;

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
            ],
        ];

        if ($systemInstruction !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        if (!empty($functionDeclarations)) {
            $payload['tools'] = [['functionDeclarations' => $functionDeclarations]];
        }

        // Retry transient errors (overload, 429, 503) with exponential backoff.
        // "high demand" responses often clear within seconds.
        $maxAttempts = 3;
        $backoffMs = [0, 1500, 5000];
        $lastErr = '';

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($backoffMs[$attempt] > 0) usleep($backoffMs[$attempt] * 1000);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);

            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr !== '') {
                $lastErr = 'Gemini API connection error: ' . $curlErr;
                if ($attempt < $maxAttempts - 1) continue;
                throw new \RuntimeException($lastErr);
            }

            $data = json_decode((string)$body, true);

            if ($httpCode === 200) {
                return $data;
            }

            $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
            $lastErr = 'Gemini API error: ' . $errMsg;

            // Retry on transient HTTP codes / overload messages, otherwise fail fast
            $isTransient = in_array($httpCode, [429, 500, 502, 503, 504], true)
                || stripos($errMsg, 'high demand') !== false
                || stripos($errMsg, 'overload') !== false
                || stripos($errMsg, 'rate') !== false;

            if (!$isTransient || $attempt >= $maxAttempts - 1) {
                throw new \RuntimeException($lastErr);
            }
        }

        throw new \RuntimeException($lastErr ?: 'Gemini API error: unknown');
    }
}
