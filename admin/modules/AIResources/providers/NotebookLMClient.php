<?php
/**
 * NotebookLM Podcast API Client
 *
 * Google Cloud Standalone Podcast API integration.
 * Uses service account JWT auth (RS256) — no Composer required.
 *
 * Generates podcast-style audio from page content via
 * discoveryengine.googleapis.com.
 */
declare(strict_types=1);

class NotebookLMClient
{
    private string $projectId;
    private array  $serviceAccount;
    private string $scope = 'https://www.googleapis.com/auth/cloud-platform';
    private string $tokenUrl = 'https://oauth2.googleapis.com/token';
    private string $apiBase  = 'https://discoveryengine.googleapis.com/v1';

    private ?string $cachedToken = null;
    private int     $tokenExpiry = 0;

    public function __construct(array $config)
    {
        $this->projectId      = $config['projectId'] ?? '';
        $this->serviceAccount = $config['serviceAccountKey'] ?? [];

        if ($this->projectId === '' && !empty($this->serviceAccount['project_id'])) {
            $this->projectId = $this->serviceAccount['project_id'];
        }
    }

    /**
     * Test connection by attempting a token exchange.
     */
    public function testConnection(): array
    {
        try {
            if (empty($this->serviceAccount['client_email']) || empty($this->serviceAccount['private_key'])) {
                return ['ok' => false, 'message' => 'Service account key missing client_email or private_key'];
            }
            if ($this->projectId === '') {
                return ['ok' => false, 'message' => 'Project ID is required'];
            }

            $token = $this->getAccessToken();
            if ($token === '') {
                return ['ok' => false, 'message' => 'Failed to obtain access token'];
            }

            return [
                'ok'        => true,
                'projectId' => $this->projectId,
                'message'   => 'Connected — token obtained for ' . ($this->serviceAccount['client_email'] ?? 'unknown'),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Start podcast generation (async long-running operation).
     *
     * @return array Operation response with 'name' field for polling
     */
    /**
     * Start podcast generation (async long-running operation).
     *
     * @param string $format CONVERSATION, DEEP_DIVE, or DEBATE
     * @return array Operation response with 'name' field for polling
     */
    public function generatePodcast(
        string $title,
        string $content,
        string $focus = '',
        string $length = 'STANDARD',
        string $lang = 'en-US',
        string $format = 'CONVERSATION'
    ): array {
        if (strlen($content) > 400000) {
            throw new \RuntimeException('Content exceeds 400k character limit (~100k tokens)');
        }
        if ($content === '') {
            throw new \RuntimeException('Content cannot be empty');
        }

        $url = $this->apiBase . '/projects/' . $this->projectId . '/locations/global/podcasts';

        $payload = [
            'title'       => $title,
            'description' => 'Generated from page content',
            'podcastConfig' => [
                'length'       => $length,
                'languageCode' => $lang,
            ],
            'contexts' => [
                [
                    'inlineData' => [
                        'mimeType' => 'text/plain',
                        'data'     => base64_encode($content),
                    ],
                ],
            ],
        ];

        if ($focus !== '') {
            $payload['podcastConfig']['focus'] = $focus;
        }

        // Format type — Conversation is default, Deep Dive and Debate are newer API options
        if ($format !== '' && $format !== 'CONVERSATION') {
            $payload['podcastConfig']['format'] = $format;
        }

        return $this->apiRequest('POST', $url, $payload);
    }

    /**
     * Check operation status (poll for completion).
     */
    public function checkOperation(string $operationName): array
    {
        $url = $this->apiBase . '/' . ltrim($operationName, '/');
        return $this->apiRequest('GET', $url);
    }

    /**
     * Download completed podcast audio.
     *
     * @return string Raw audio bytes (MP3)
     */
    public function downloadPodcast(string $operationName): string
    {
        $url = $this->apiBase . '/' . ltrim($operationName, '/') . ':download?alt=media';
        $token = $this->getAccessToken();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: audio/mpeg',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException('Download error: ' . $curlErr);
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException('Download failed — HTTP ' . $httpCode);
        }

        return $body;
    }

    // ─── Auth ─────────────────────────────

    /**
     * Get a valid access token (cached until 60s before expiry).
     */
    public function getAccessToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->tokenExpiry) {
            return $this->cachedToken;
        }

        $jwt = $this->buildJwt();

        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException('Token exchange failed: ' . $curlErr);
        }

        $data = json_decode((string)$body, true);

        if ($httpCode !== 200 || empty($data['access_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException('Token exchange failed: ' . $err);
        }

        $this->cachedToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60;

        return $this->cachedToken;
    }

    /**
     * Build a signed JWT for service account auth.
     */
    private function buildJwt(): string
    {
        $now = time();

        $header = $this->base64url(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $claims = $this->base64url(json_encode([
            'iss'   => $this->serviceAccount['client_email'],
            'scope' => $this->scope,
            'aud'   => $this->tokenUrl,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $payload    = $header . '.' . $claims;
        $privateKey = $this->serviceAccount['private_key'] ?? '';

        $ok = openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('JWT signing failed — check private_key in service account JSON');
        }

        return $payload . '.' . $this->base64url($signature);
    }

    // ─── HTTP helpers ─────────────────────

    /**
     * Make an authenticated API request.
     */
    private function apiRequest(string $method, string $url, ?array $payload = null): array
    {
        $token = $this->getAccessToken();

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException('API request failed: ' . $curlErr);
        }

        $data = json_decode((string)$body, true) ?: [];

        if ($httpCode >= 400) {
            $errMsg = $data['error']['message'] ?? $data['error'] ?? "HTTP {$httpCode}";
            if (is_array($errMsg)) $errMsg = json_encode($errMsg);
            throw new \RuntimeException('NotebookLM API error: ' . $errMsg);
        }

        return $data;
    }

    /**
     * Base64url encode (JWT-safe, no padding).
     */
    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
