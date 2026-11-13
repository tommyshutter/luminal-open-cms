<?php
/**
 * TwitterPlatform — Twitter/X API v2 posting adapter with OAuth 1.0a signing
 *
 * Credentials: api_key, api_secret, access_token, access_token_secret
 */
declare(strict_types=1);

require_once __DIR__ . '/SocialPlatform.php';

class TwitterPlatform extends SocialPlatform
{
    private string $apiKey;
    private string $apiSecret;
    private string $accessToken;
    private string $accessTokenSecret;

    private const API_BASE    = 'https://api.twitter.com/2';
    private const UPLOAD_BASE = 'https://upload.twitter.com/1.1';

    public function __construct(array $credentials)
    {
        parent::__construct($credentials);
        $this->apiKey            = $credentials['api_key'] ?? '';
        $this->apiSecret         = $credentials['api_secret'] ?? '';
        $this->accessToken       = $credentials['access_token'] ?? '';
        $this->accessTokenSecret = $credentials['access_token_secret'] ?? '';
    }

    public function testConnection(): array
    {
        if (!$this->apiKey || !$this->apiSecret || !$this->accessToken || !$this->accessTokenSecret) {
            return ['ok' => false, 'message' => 'Missing one or more OAuth credentials'];
        }

        $url = self::API_BASE . '/users/me';
        $headers = $this->oauthHeaders('GET', $url);
        $res = $this->curlRequest($url, 'GET', null, $headers);

        if (!$res['ok']) {
            $err = is_array($res['body']) ? ($res['body']['detail'] ?? $res['body']['title'] ?? 'Auth failed') : ($res['error'] ?? 'Request failed');
            return ['ok' => false, 'message' => 'Twitter API error: ' . $err];
        }

        $username = $res['body']['data']['username'] ?? 'Unknown';
        $name     = $res['body']['data']['name'] ?? $username;
        return ['ok' => true, 'message' => 'Connected as @' . $username, 'username' => $username, 'name' => $name];
    }

    public function postStatus(string $text, ?string $linkUrl = null): array
    {
        $body = $linkUrl ? $text . "\n" . $linkUrl : $text;
        return $this->tweet(['text' => $body]);
    }

    public function postPhoto(string $text, string $imageSource, ?string $linkUrl = null): array
    {
        // Step 1: Upload media
        $mediaResult = $this->uploadMedia($imageSource);
        if (!$mediaResult['ok']) {
            return $mediaResult;
        }

        $mediaId = $mediaResult['media_id'];
        $body = $linkUrl ? $text . "\n" . $linkUrl : $text;

        // Step 2: Tweet with media
        return $this->tweet([
            'text'  => $body,
            'media' => ['media_ids' => [$mediaId]],
        ]);
    }

    // ── Private Helpers ─────────────────────────────────────────

    private function tweet(array $payload): array
    {
        $url  = self::API_BASE . '/tweets';
        $json = json_encode($payload);

        $headers = $this->oauthHeaders('POST', $url);
        $headers[] = 'Content-Type: application/json';

        $res = $this->curlRequest($url, 'POST', $json, $headers);

        if (!$res['ok']) {
            $err = is_array($res['body']) ? ($res['body']['detail'] ?? $res['body']['title'] ?? json_encode($res['body'])) : ($res['error'] ?? 'Tweet failed');
            return ['ok' => false, 'message' => 'Twitter tweet error: ' . $err, 'post_id' => null];
        }

        $tweetId = $res['body']['data']['id'] ?? null;
        return ['ok' => true, 'message' => 'Tweeted successfully', 'post_id' => $tweetId];
    }

    private function uploadMedia(string $imageSource): array
    {
        $isUrl = preg_match('#^https?://#i', $imageSource);
        $localPath = $imageSource;
        $tempFile  = null;

        if ($isUrl) {
            $tempFile = $this->downloadToTemp($imageSource);
            if (!$tempFile) {
                return ['ok' => false, 'message' => 'Failed to download image from URL'];
            }
            $localPath = $tempFile;
        }

        if (!file_exists($localPath)) {
            return ['ok' => false, 'message' => 'Image file not found: ' . $localPath];
        }

        $url = self::UPLOAD_BASE . '/media/upload.json';

        $postFields = [
            'media_data'     => base64_encode(file_get_contents($localPath)),
            'media_category' => 'tweet_image',
        ];

        $headers = $this->oauthHeaders('POST', $url, $postFields);

        $res = $this->curlRequest($url, 'POST', http_build_query($postFields), $headers);

        if ($tempFile) @unlink($tempFile);

        if (!$res['ok']) {
            $err = is_array($res['body']) ? json_encode($res['body']) : ($res['error'] ?? 'Upload failed');
            return ['ok' => false, 'message' => 'Twitter media upload error: ' . $err];
        }

        $mediaId = $res['body']['media_id_string'] ?? null;
        if (!$mediaId) {
            return ['ok' => false, 'message' => 'No media_id returned from upload'];
        }

        return ['ok' => true, 'media_id' => $mediaId];
    }

    // ── OAuth 1.0a Signature ────────────────────────────────────

    /**
     * Generate OAuth 1.0a Authorization header (HMAC-SHA1).
     */
    private function oauthHeaders(string $method, string $url, array $extraParams = []): array
    {
        $oauthParams = [
            'oauth_consumer_key'     => $this->apiKey,
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $this->accessToken,
            'oauth_version'          => '1.0',
        ];

        // Combine OAuth params + query params + body params for signature base
        $parsedUrl = parse_url($url);
        $queryParams = [];
        if (!empty($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        $signParams = array_merge($oauthParams, $queryParams, $extraParams);
        ksort($signParams);

        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . ($parsedUrl['path'] ?? '');
        $paramString = http_build_query($signParams, '', '&', PHP_QUERY_RFC3986);
        $signatureBase = strtoupper($method) . '&' . rawurlencode($baseUrl) . '&' . rawurlencode($paramString);

        $signingKey = rawurlencode($this->apiSecret) . '&' . rawurlencode($this->accessTokenSecret);
        $signature  = base64_encode(hash_hmac('sha1', $signatureBase, $signingKey, true));

        $oauthParams['oauth_signature'] = $signature;

        $headerParts = [];
        foreach ($oauthParams as $k => $v) {
            $headerParts[] = rawurlencode($k) . '="' . rawurlencode($v) . '"';
        }

        return ['Authorization: OAuth ' . implode(', ', $headerParts)];
    }
}
