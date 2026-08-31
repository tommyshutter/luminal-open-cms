<?php
/**
 * SocialPlatform — Abstract base class for social media platform adapters
 */
declare(strict_types=1);

abstract class SocialPlatform
{
    protected array $credentials;

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * Test the connection / validate credentials.
     * @return array ['ok' => bool, 'message' => string]
     */
    abstract public function testConnection(): array;

    /**
     * Post a text-only status update.
     * @return array ['ok' => bool, 'message' => string, 'post_id' => string|null]
     */
    abstract public function postStatus(string $text, ?string $linkUrl = null): array;

    /**
     * Post a photo with text caption.
     * @param string $imageSource URL or local file path
     * @return array ['ok' => bool, 'message' => string, 'post_id' => string|null]
     */
    abstract public function postPhoto(string $text, string $imageSource, ?string $linkUrl = null): array;

    /**
     * cURL helper — reused across all adapters (same pattern as fb_events_summary.php)
     */
    protected function curlRequest(string $url, string $method = 'GET', $body = null, array $headers = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            return ['ok' => false, 'http_code' => 0, 'error' => $curlError, 'body' => null];
        }

        $decoded = json_decode($response, true);
        return [
            'ok'        => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'body'      => $decoded ?? $response,
            'raw'       => $response,
        ];
    }

    /**
     * Download an image URL to a temp file for multipart upload.
     */
    protected function downloadToTemp(string $url): ?string
    {
        $ch = curl_init($url);
        $tmp = tempnam(sys_get_temp_dir(), 'sp_img_');
        $fp = fopen($tmp, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);

        if ($code >= 200 && $code < 300 && filesize($tmp) > 0) {
            return $tmp;
        }
        @unlink($tmp);
        return null;
    }
}
