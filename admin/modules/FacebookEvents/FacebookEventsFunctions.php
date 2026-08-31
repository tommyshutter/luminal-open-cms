<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: FacebookEvents v1.0.0
 * File: FacebookEventsFunctions.php
 *
 * Helper functions for Facebook Graph API integration.
 * Ported from: fb_events_logic.php (dropped namespace, fbe_ prefix).
 */
declare(strict_types=1);

/* ======================================================================= */
/* Data / Cache Paths                                                      */
/* ======================================================================= */

function fbe_data_dir(): string {
    $dir = SITE_ROOT . '/admin/data/FacebookEvents';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function fbe_cache_dir(): string {
    $dir = fbe_data_dir() . '/caches';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/* ======================================================================= */
/* Config (data/config.json)                                               */
/* ======================================================================= */

function fbe_defaults(): array {
    return [
        'fb_app_id'       => '',
        'access_token'    => '',
        'page_id'         => '',
        'fallback_url'    => '',
        'layout'          => 'grid',
        'view_mode'       => 'grid',
        'image_shape'     => 'natural',
        'columns'         => 'auto',
        'events_per_page' => 10,
        'show_description'=> false,
        'time_frame'      => 'upcoming',
        'limit_events'    => 100,
        'cache_duration'  => 3600,
        'display_options' => [
            'show_location'  => true,
            'show_time'      => true,
            'show_canceled'  => false,
        ],
    ];
}

/**
 * Read the legacy admin/data/facebook_events_settings.json, normalised.
 * Returns [] when absent or unreadable. This is the ONLY place that file is
 * interpreted — every consumer must resolve through fbe_load_config().
 */
function fbe_read_legacy_config(): array {
    $root = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3);
    $legacy = $root . '/admin/data/facebook_events_settings.json';
    if (!is_file($legacy)) return [];
    $raw = json_decode((string)@file_get_contents($legacy), true);
    if (!is_array($raw)) return [];
    unset($raw['config_file']);
    if (isset($raw['show_description']) && is_string($raw['show_description'])) {
        $raw['show_description'] = ($raw['show_description'] === 'yes');
    }
    return $raw;
}

function fbe_load_config(): array {
    $path   = fbe_data_dir() . '/config.json';
    $legacy = fbe_read_legacy_config();

    /* One-time migration: no module config yet, but a legacy file exists. */
    if (!is_file($path) && $legacy) {
        fbe_save_config($legacy);
    }

    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($data)) $data = [];

    /* Layered resolve: defaults < legacy < module config.
       The module config wins for every key it defines, but keys it does NOT
       define are filled from the legacy file instead of silently reverting to
       defaults. Before this, a site holding fb_app_id only in the legacy file
       and access_token only in the module config resolved to one or the other
       depending on which code path ran — which is why App IDs and tokens
       appeared not to "take" after being set in the admin UI. */
    return array_merge(fbe_defaults(), $legacy, $data);
}

function fbe_save_config(array $data): bool {
    $path = fbe_data_dir() . '/config.json';
    $merged = array_merge(fbe_defaults(), $data);
    return file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
}

/* ======================================================================= */
/* Cache                                                                   */
/* ======================================================================= */

function fbe_cache_path(): string {
    return fbe_cache_dir() . '/events.cache';
}

function fbe_clear_cache(): bool {
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3);

    // Clear ALL Facebook event caches — module cache, renderer cache, and legacy caches
    $cleared = 0;
    $targets = [
        fbe_cache_dir() . '/events.cache',
        fbe_cache_dir() . '/events_front.cache',
        $siteRoot . '/admin/data/caches/facebook_events_settings.cache',
        $siteRoot . '/admin/data/caches/default.cache',
    ];
    // Also glob any other .cache files in both directories
    foreach (glob(fbe_cache_dir() . '/*.cache') as $f) { @unlink($f); $cleared++; }
    foreach (glob($siteRoot . '/admin/data/caches/*.cache') as $f) { @unlink($f); $cleared++; }
    // Hit the explicit targets too in case glob missed
    foreach ($targets as $f) { if (is_file($f)) { @unlink($f); $cleared++; } }

    return $cleared >= 0;
}

function fbe_get_cached(): ?array {
    $path = fbe_cache_path();
    if (!is_file($path)) return null;
    $cfg = fbe_load_config();
    $ttl = (int)($cfg['cache_duration'] ?? 3600);
    if (filemtime($path) + $ttl < time()) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function fbe_set_cache(array $data): void {
    file_put_contents(fbe_cache_path(), json_encode($data, JSON_UNESCAPED_SLASHES));
}

/* ======================================================================= */
/* Facebook Graph API                                                      */
/* ======================================================================= */

function fbe_fetch_api(string $url, array &$log = []): ?array {
    $logUrl = preg_replace('/access_token=[^&]+/', 'access_token=***', $url);
    $log[] = ['type' => 'cmd', 'msg' => "GET $logUrl"];

    $start   = microtime(true);
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $body    = @file_get_contents($url, false, $context);
    $ms      = round((microtime(true) - $start) * 1000, 1);

    $headers    = $http_response_header ?? [];
    $statusLine = $headers[0] ?? 'HTTP/1.1 000';
    preg_match('#HTTP/\d\.\d\s+(\d+)#', $statusLine, $m);
    $code = (int)($m[1] ?? 0);

    if ($code === 200) {
        $log[] = ['type' => 'success', 'msg' => "200 OK ({$ms}ms)"];
    } else {
        $log[] = ['type' => 'error', 'msg' => "HTTP $code ({$ms}ms)"];
    }

    $data = json_decode($body ?: '', true);

    if (isset($data['error'])) {
        $emsg  = $data['error']['message'] ?? 'Unknown';
        $ecode = $data['error']['code'] ?? '?';
        $log[] = ['type' => 'error', 'msg' => "API Error [$ecode]: $emsg"];
    } elseif (isset($data['data'])) {
        $cnt = count($data['data']);
        $log[] = ['type' => ($cnt > 0 ? 'success' : 'warning'), 'msg' => "Payload: $cnt items"];
    }

    return is_array($data) ? $data : null;
}

function fbe_resolve_page_id(string $input, string $token, array &$log = []): string {
    $input = trim($input);
    if ($input === '') return '';
    if (ctype_digit($input)) return $input;

    $handle = $input;
    if (str_contains($input, 'facebook.com')) {
        $parsed = parse_url($input);
        $parts  = array_values(array_filter(explode('/', $parsed['path'] ?? '')));
        foreach ($parts as $p) {
            if (ctype_digit($p) && strlen($p) > 8) return $p;
        }
        $handle = end($parts) ?: $input;
    }

    $log[] = ['type' => 'info', 'msg' => "Resolving handle: '$handle'"];
    $data = fbe_fetch_api("https://graph.facebook.com/v17.0/$handle?access_token=$token", $log);

    if (isset($data['id'])) {
        $log[] = ['type' => 'success', 'msg' => 'Resolved ID: ' . $data['id']];
        return $data['id'];
    }

    $log[] = ['type' => 'warning', 'msg' => 'Could not resolve. Using input as-is.'];
    return $input;
}

function fbe_validate_connection(string $pageId, string $token, string $appId = '', array &$log = []): array {
    $status = [
        'token'        => false,
        'page'         => false,
        'error'        => '',
        'entity_name'  => '',
        'entity_type'  => '',
        'entity_id'    => '',
        'categories'   => [],
        'token_app_id' => '',
        'app_id_match' => null,
    ];

    if ($token === '') return $status;

    $log[] = ['type' => 'info', 'msg' => 'Validating token...'];
    $debug = fbe_fetch_api("https://graph.facebook.com/debug_token?input_token=$token&access_token=$token", $log);

    if (!isset($debug['data']['is_valid']) || !$debug['data']['is_valid']) {
        $status['error'] = $debug['data']['error']['message'] ?? 'Invalid token';
        return $status;
    }

    $status['token'] = true;

    if (isset($debug['data']['app_id'])) {
        $status['token_app_id'] = $debug['data']['app_id'];
        if ($appId !== '') {
            $status['app_id_match'] = ($status['token_app_id'] === $appId);
            $log[] = ['type' => $status['app_id_match'] ? 'success' : 'warning',
                       'msg' => $status['app_id_match'] ? 'App ID: MATCH' : "App ID mismatch: token={$status['token_app_id']} config=$appId"];
        }
    }

    if ($pageId !== '') {
        $log[] = ['type' => 'info', 'msg' => "Auditing page $pageId..."];
        $pg = fbe_fetch_api("https://graph.facebook.com/v17.0/$pageId?fields=name,id,category_list&metadata=1&access_token=$token", $log);

        if (isset($pg['id'])) {
            $status['page']        = true;
            $status['entity_id']   = $pg['id'];
            $status['entity_name'] = $pg['name'] ?? 'Unknown';
            $status['entity_type'] = strtoupper($pg['metadata']['type'] ?? 'unknown');

            if (isset($pg['category_list']) && is_array($pg['category_list'])) {
                foreach ($pg['category_list'] as $cat) $status['categories'][] = $cat['name'];
            } elseif ($status['entity_type'] === 'USER') {
                $status['categories'][] = 'User Profile';
            }
            if (empty($status['categories'])) $status['categories'][] = 'Uncategorized';

            $log[] = ['type' => 'success', 'msg' => 'Audit complete: ' . $status['entity_name']];
        } else {
            $log[] = ['type' => 'error', 'msg' => 'Page audit failed'];
        }
    }

    return $status;
}

function fbe_fetch_events(array $cfg, array &$log = []): array {
    $pageId = $cfg['page_id'] ?? '';
    $token  = $cfg['access_token'] ?? '';
    if ($pageId === '' || $token === '') return [];

    $cached = fbe_get_cached();
    if ($cached !== null) {
        $log[] = ['type' => 'info', 'msg' => 'Returning cached events (' . count($cached) . ')'];
        return $cached;
    }

    $limit = (int)($cfg['limit_events'] ?? 100);
    $url   = "https://graph.facebook.com/v17.0/$pageId/events?fields=name,start_time,end_time,place,cover,description&limit=$limit&access_token=$token";
    if (($cfg['time_frame'] ?? 'upcoming') === 'upcoming') $url .= '&time_filter=upcoming';

    $log[] = ['type' => 'info', 'msg' => 'Fetching events...'];
    $data = fbe_fetch_api($url, $log);

    $events = $data['data'] ?? [];
    if (!empty($events)) fbe_set_cache($events);

    return $events;
}

function fbe_fetch_preview(array $cfg, array &$log = []): ?array {
    $pageId = $cfg['page_id'] ?? '';
    $token  = $cfg['access_token'] ?? '';
    if ($pageId === '' || $token === '') return null;

    $url = "https://graph.facebook.com/v17.0/$pageId/events?fields=name,start_time,place,cover&limit=1&access_token=$token";
    if (($cfg['time_frame'] ?? 'upcoming') === 'upcoming') $url .= '&time_filter=upcoming';

    $log[] = ['type' => 'info', 'msg' => 'Fetching event preview...'];
    $data = fbe_fetch_api($url, $log);

    return $data['data'][0] ?? null;
}
