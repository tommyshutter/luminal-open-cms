<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: EventsManagerPro v1.0.0
 * File: classes/SocialPoster.php
 *
 * Social media posting — Facebook Graph API v19.0, IG/X stubs.
 * path constants updated to EMP_* prefixes.
 */
declare(strict_types=1);

class SocialPoster {
    public function getCredentials(): array {
        $fb  = DataStore::load(EMP_FILE_FB_SETTINGS);
        $soc = DataStore::load(EMP_FILE_SOCIALS);
        return array_merge($fb, $soc);
    }

    public function saveCredentials(array $post): array {
        $fbData = DataStore::load(EMP_FILE_FB_SETTINGS);
        $fbData['fb_app_id']     = $post['fb_app_id'] ?? '';
        $fbData['access_token']  = $post['fb_token'] ?? '';
        $fbData['page_id']       = $post['fb_page_id'] ?? '';
        DataStore::save(EMP_FILE_FB_SETTINGS, $fbData);

        $socials = [
            'insta_id'    => $post['insta_id'] ?? '',
            'insta_token' => $post['insta_token'] ?? '',
            'x_api_key'   => $post['x_api_key'] ?? '',
            'x_secret'    => $post['x_secret'] ?? '',
        ];
        DataStore::save(EMP_FILE_SOCIALS, $socials);
        return ['ok' => true];
    }

    public function push(string $platform, string $evtId): array {
        $db = DataStore::load(EMP_FILE_MASTER);
        $event = null;
        foreach ($db as $e) {
            if ($e['id'] === $evtId) { $event = $e; break; }
        }
        if (!$event) return ['ok' => false, 'error' => 'Event not found'];

        $creds = $this->getCredentials();

        if ($platform === 'FB') return $this->postToFacebook($creds, $event);

        if ($platform === 'IG') {
            if (empty($creds['insta_id'])) return ['ok' => false, 'error' => 'IG Not Configured'];
            return ['ok' => false, 'error' => 'IG Business API pending implementation'];
        }
        if ($platform === 'X') {
            if (empty($creds['x_api_key'])) return ['ok' => false, 'error' => 'X Not Configured'];
            return ['ok' => false, 'error' => 'X OAuth pending implementation'];
        }

        return ['ok' => false, 'error' => 'Unknown Platform'];
    }

    private function postToFacebook(array $creds, array $event): array {
        if (empty($creds['page_id']) || empty($creds['access_token'])) {
            return ['ok' => false, 'error' => 'Missing FB Page ID or Token'];
        }

        $dateTime = '';
        if (!empty($event['start_date'])) {
            $dateTime = $event['start_date'] . ' ' . ($event['start_time'] ?? '20:00');
        } elseif (!empty($event['start_time'])) {
            $dateTime = $event['start_time'];
        }
        $dateStr = $dateTime ? date('D, M j @ g:i A', strtotime($dateTime)) : 'TBA';

        $cleanDesc = strip_tags(str_replace(['<br>', '<br/>'], "\n", $event['content_html'] ?? ''));

        $msg = "\xF0\x9F\x93\x85 " . strtoupper($event['title'] ?? 'Event') . "\n\xF0\x9F\x95\x92 " . $dateStr . "\n\n";
        if ($cleanDesc) $msg .= $cleanDesc . "\n\n";
        if (!empty($event['cta_url'])) {
            $msg .= "\xF0\x9F\x8E\x9F\xEF\xB8\x8F Tickets/Info: " . $event['cta_url'] . "\n";
        }
        if (!empty($event['fb_link'])) {
            $msg .= "\xF0\x9F\x93\x98 FB Event: " . $event['fb_link'];
        }

        $siteRoot = emp_site_root();
        $hasImage = !empty($event['image']) && file_exists($siteRoot . '/' . $event['image']);
        $endpoint = "https://graph.facebook.com/v19.0/{$creds['page_id']}/" . ($hasImage ? "photos" : "feed");

        $fields = ['access_token' => $creds['access_token'], 'message' => $msg];

        if ($hasImage) {
            $fields['source'] = new \CURLFile(realpath($siteRoot . '/' . $event['image']));
        } else {
            $linkUrl = $event['cta_url'] ?? $event['fb_link'] ?? '';
            if ($linkUrl) $fields['link'] = $linkUrl;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $json = json_decode($res, true);

        if ($code == 200 && isset($json['id'])) {
            return ['ok' => true, 'message' => 'Posted to FB! ID: ' . $json['id']];
        }

        $fbError      = $json['error']['message'] ?? 'Unknown error';
        $errorCode    = $json['error']['code'] ?? 0;
        $errorSubcode = $json['error']['error_subcode'] ?? 0;
        $debugInfo    = "Error {$errorCode}" . ($errorSubcode ? ".{$errorSubcode}" : "") . ": {$fbError}";

        if ($errorCode == 190) {
            return ['ok' => false, 'error' => "Token Invalid/Expired\n\n{$debugInfo}\n\nRegenerate at developers.facebook.com > Graph API Explorer."];
        }
        if (strpos($fbError, 'publish_actions') !== false) {
            return ['ok' => false, 'error' => "Wrong token type — use a PAGE Access Token, not a User token.\n\n{$debugInfo}"];
        }
        if ($errorCode == 200 || $errorCode == 10 || strpos($fbError, 'permission') !== false) {
            return ['ok' => false, 'error' => "Permission error.\n\n{$debugInfo}\n\nEnsure pages_manage_posts permission."];
        }

        return ['ok' => false, 'error' => "Facebook API Error\n\n{$debugInfo}"];
    }
}
