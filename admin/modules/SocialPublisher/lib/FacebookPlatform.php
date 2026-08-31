<?php
/**
 * FacebookPlatform — Facebook Graph API v17.0 posting adapter
 *
 * Credentials: page_id, access_token, page_name (optional display)
 */
declare(strict_types=1);

require_once __DIR__ . '/SocialPlatform.php';

class FacebookPlatform extends SocialPlatform
{
    private string $pageId;
    private string $accessToken;
    private const API_BASE = 'https://graph.facebook.com/v17.0';

    public function __construct(array $credentials)
    {
        parent::__construct($credentials);
        $this->pageId      = $credentials['page_id'] ?? '';
        $this->accessToken = $credentials['access_token'] ?? '';
    }

    public function testConnection(): array
    {
        if (!$this->pageId || !$this->accessToken) {
            return ['ok' => false, 'message' => 'Missing page_id or access_token'];
        }

        $url = self::API_BASE . '/' . $this->pageId . '?fields=name,id&access_token=' . urlencode($this->accessToken);
        $res = $this->curlRequest($url);

        if (!$res['ok']) {
            $err = is_array($res['body']) ? ($res['body']['error']['message'] ?? 'Unknown error') : ($res['error'] ?? 'Request failed');
            return ['ok' => false, 'message' => 'Facebook API error: ' . $err];
        }

        $pageName = $res['body']['name'] ?? 'Unknown Page';
        return ['ok' => true, 'message' => 'Connected to page: ' . $pageName, 'page_name' => $pageName];
    }

    public function postStatus(string $text, ?string $linkUrl = null): array
    {
        $url = self::API_BASE . '/' . $this->pageId . '/feed';
        $params = [
            'message'      => $text,
            'access_token' => $this->accessToken,
        ];
        if ($linkUrl) {
            $params['link'] = $linkUrl;
        }

        $res = $this->curlRequest($url, 'POST', http_build_query($params));

        if (!$res['ok']) {
            $err = is_array($res['body']) ? ($res['body']['error']['message'] ?? 'Post failed') : ($res['error'] ?? 'Request failed');
            return ['ok' => false, 'message' => 'Facebook post error: ' . $err, 'post_id' => null];
        }

        $postId = $res['body']['id'] ?? null;
        return ['ok' => true, 'message' => 'Posted to Facebook', 'post_id' => $postId];
    }

    public function postPhoto(string $text, string $imageSource, ?string $linkUrl = null): array
    {
        $url = self::API_BASE . '/' . $this->pageId . '/photos';

        // Determine if imageSource is a URL or local file
        $isUrl = preg_match('#^https?://#i', $imageSource);

        if ($isUrl) {
            // Post with image URL
            $params = [
                'message'      => $text,
                'url'          => $imageSource,
                'access_token' => $this->accessToken,
            ];
            $res = $this->curlRequest($url, 'POST', http_build_query($params));
        } else {
            // Post with file upload (multipart)
            if (!file_exists($imageSource)) {
                return ['ok' => false, 'message' => 'Image file not found: ' . $imageSource, 'post_id' => null];
            }
            $params = [
                'message'      => $text,
                'source'       => new CURLFile($imageSource, mime_content_type($imageSource) ?: 'image/jpeg'),
                'access_token' => $this->accessToken,
            ];
            $res = $this->curlRequest($url, 'POST', $params);
        }

        if (!$res['ok']) {
            $err = is_array($res['body']) ? ($res['body']['error']['message'] ?? 'Photo post failed') : ($res['error'] ?? 'Request failed');
            return ['ok' => false, 'message' => 'Facebook photo error: ' . $err, 'post_id' => null];
        }

        $postId = $res['body']['id'] ?? ($res['body']['post_id'] ?? null);
        return ['ok' => true, 'message' => 'Photo posted to Facebook', 'post_id' => $postId];
    }
}
