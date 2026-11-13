<?php
/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: FacebookEvents v1.0.0
 * File: api.php
 *
 * JSON API — Facebook Events configuration and Graph API operations.
 * Auth hardened: source had NO authentication on any endpoint.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once __DIR__ . '/../../modules/UserManager/guard.php';
guard_require_auth();

$user = guard_user();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only']);
    exit;
}

require_once __DIR__ . '/FacebookEventsFunctions.php';

header('Content-Type: application/json');

function ok(array $data = []): never {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function bad(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'load':
        $cfg = fbe_load_config();
        // Mask the access token for security (send only last 8 chars)
        $token = $cfg['access_token'] ?? '';
        $cfg['access_token_masked'] = $token !== '' ? ('***' . substr($token, -8)) : '';
        ok(['config' => $cfg]);

    case 'export_config':
        // Export full config JSON for AgentScheduler FB task import
        $cfg = fbe_load_config();
        $export = [
            'page_id'      => $cfg['page_id'] ?? '',
            'access_token' => $cfg['access_token'] ?? '',
            'fb_app_id'    => $cfg['fb_app_id'] ?? '',
            'page_name'    => $cfg['page_name'] ?? '',
            'fallback_url' => $cfg['fallback_url'] ?? '',
            'exported_from' => $_SERVER['HTTP_HOST'] ?? '',
            'exported_at'   => date('c'),
        ];
        ok(['config_json' => $export]);

    case 'save':
        $cfg = [
            'fb_app_id'        => trim($_POST['fb_app_id'] ?? ''),
            'access_token'     => trim($_POST['access_token'] ?? ''),
            'page_id'          => trim($_POST['page_id'] ?? ''),
            'fallback_url'     => trim($_POST['fallback_url'] ?? ''),
            'layout'           => in_array(($_POST['layout'] ?? 'grid'), ['grid','list'], true) ? $_POST['layout'] : 'grid',
            'view_mode'        => $_POST['view_mode'] ?? 'grid',
            'image_shape'      => in_array(($_POST['image_shape'] ?? 'natural'), ['natural','portrait','square'], true) ? $_POST['image_shape'] : 'natural',
            'columns'          => in_array(($_POST['columns'] ?? 'auto'), ['auto','1','2','3','4'], true) ? $_POST['columns'] : 'auto',
            'events_per_page'  => max(1, (int)($_POST['events_per_page'] ?? 10)),
            'show_description' => ($_POST['show_description'] ?? '0') === '1',
            'time_frame'       => $_POST['time_frame'] ?? 'upcoming',
            'limit_events'     => max(1, (int)($_POST['limit_events'] ?? 100)),
            'cache_duration'   => max(0, (int)($_POST['cache_duration'] ?? 3600)),
            'display_options'  => [
                'show_location'  => ($_POST['show_location'] ?? '1') === '1',
                'show_time'      => ($_POST['show_time'] ?? '1') === '1',
                'show_canceled'  => ($_POST['show_canceled'] ?? '0') === '1',
            ],
        ];

        // Resolve page ID if it looks like a URL/handle
        $log = [];
        if ($cfg['page_id'] !== '' && $cfg['access_token'] !== '') {
            $cfg['page_id'] = fbe_resolve_page_id($cfg['page_id'], $cfg['access_token'], $log);
        }

        if (!fbe_save_config($cfg)) bad('Failed to save config');
        fbe_clear_cache();
        ok(['log' => $log]);

    case 'clear_cache':
        fbe_clear_cache();
        ok(['message' => 'Cache cleared']);

    case 'resolve_id':
        $input = trim($_POST['page_id'] ?? '');
        $token = trim($_POST['access_token'] ?? '');
        if ($input === '' || $token === '') bad('Page ID and access token required');
        $log = [];
        $resolved = fbe_resolve_page_id($input, $token, $log);
        ok(['page_id' => $resolved, 'log' => $log]);

    case 'validate':
        $cfg = fbe_load_config();
        $log = [];
        $result = fbe_validate_connection(
            $cfg['page_id'] ?? '',
            $cfg['access_token'] ?? '',
            $cfg['fb_app_id'] ?? '',
            $log
        );
        ok(['validation' => $result, 'log' => $log]);

    case 'preview':
        $cfg = fbe_load_config();
        $log = [];
        $validation = fbe_validate_connection(
            $cfg['page_id'] ?? '',
            $cfg['access_token'] ?? '',
            $cfg['fb_app_id'] ?? '',
            $log
        );
        $event = null;
        if ($validation['token'] && $validation['page']) {
            $event = fbe_fetch_preview($cfg, $log);
        }
        ok(['validation' => $validation, 'event' => $event, 'log' => $log]);

    case 'fetch_events':
        $cfg = fbe_load_config();
        $log = [];
        $events = fbe_fetch_events($cfg, $log);
        ok(['events' => $events, 'count' => count($events), 'log' => $log]);

    default:
        bad('Unknown action');
}
