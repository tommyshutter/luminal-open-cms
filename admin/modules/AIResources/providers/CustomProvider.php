<?php
/**
 * Custom AI Provider — OpenAI-compatible endpoint
 *
 * Works with any API that follows the OpenAI chat completions format:
 * Groq, Together AI, Mistral, Ollama, LM Studio, vLLM, etc.
 */
declare(strict_types=1);

require_once __DIR__ . '/ProviderInterface.php';

class CustomProvider implements AIResources\Providers\AIProvider
{
    private string $apiKey;
    private string $model;
    private int    $maxTokens;
    private string $baseUrl;

    public function __construct(string $apiKey, string $model, int $maxTokens = 4096, string $baseUrl = '')
    {
        if (!function_exists("cred_vault_reveal") && defined("SITE_ROOT")
            && is_file(SITE_ROOT . "/admin/config/cred_vault.php")) {
            require_once SITE_ROOT . "/admin/config/cred_vault.php";
        }
        $this->apiKey    = function_exists("cred_vault_reveal") ? \cred_vault_reveal($apiKey) : $apiKey;
        $this->model     = $model;
        $this->maxTokens = $maxTokens;
        $this->baseUrl   = rtrim($baseUrl ?: 'https://api.openai.com/v1', '/');
    }

    public function generate(string $systemPrompt, string $userMessage, array $tools = [], ?callable $toolCallback = null): array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'temperature' => 0.7,
        ];

        $result = $this->callApi('/chat/completions', $body);

        $choice  = $result['choices'][0] ?? [];
        $content = $choice['message']['content'] ?? '';
        $usage   = $result['usage'] ?? [];

        return [
            'content'   => $content,
            'model'     => $result['model'] ?? $this->model,
            'usage'     => [
                'input_tokens'  => $usage['prompt_tokens'] ?? 0,
                'output_tokens' => $usage['completion_tokens'] ?? 0,
            ],
            'toolCalls' => [],
        ];
    }

    public function getModels(): array
    {
        return [
            ['id' => $this->model, 'name' => $this->model, 'maxTokens' => $this->maxTokens],
        ];
    }

    public function testConnection(): array
    {
        try {
            // Try a minimal completion
            $body = [
                'model'      => $this->model,
                'messages'   => [['role' => 'user', 'content' => 'Hi']],
                'max_tokens' => 5,
            ];
            $result = $this->callApi('/chat/completions', $body);
            return [
                'ok'      => true,
                'model'   => $result['model'] ?? $this->model,
                'message' => 'Connection successful',
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'model'   => $this->model,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }

    public function getName(): string
    {
        return 'Custom (' . parse_url($this->baseUrl, PHP_URL_HOST) . ')';
    }

    private function callApi(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);

        if ($err) {
            throw new \RuntimeException("Connection failed: {$err}");
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $errMsg = $data['error']['message'] ?? $data['error'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("API error: {$errMsg}");
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response from API');
        }

        return $data;
    }
}
