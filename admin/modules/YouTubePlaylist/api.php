<?php
/**
 * YouTube Playlist Studio — API
 * @file admin/modules/YouTubePlaylist/api.php
 *
 * GET actions:
 *   list_playlists              — scan youtube_playlists/*.json, return array
 *   get_playlist  &slug=X       — load single playlist config
 *   preview_playlist &api_key=X&playlist_id=X — fetch first 5 items for validation
 *
 * POST actions (JSON body):
 *   save_playlist   {slug, title, api_key, playlist_id, ...}
 *   delete_playlist {slug}
 *   clear_cache     {slug?}  — clear cache for one or all playlists
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once __DIR__ . '/../_runtime/guard.php';
guard_require_auth();

header('Content-Type: application/json; charset=utf-8');

$DATA_DIR  = SITE_ROOT . '/admin/data/youtube_playlists';
$CACHE_DIR = $DATA_DIR . '/cache';

// Ensure dirs exist
if (!is_dir($DATA_DIR))  { @mkdir($DATA_DIR, 0775, true);  @chown($DATA_DIR, 'www-data'); @chgrp($DATA_DIR, 'www-data'); }
if (!is_dir($CACHE_DIR)) { @mkdir($CACHE_DIR, 0775, true); @chown($CACHE_DIR, 'www-data'); @chgrp($CACHE_DIR, 'www-data'); }

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function valid_slug(string $s): bool {
    return (bool)preg_match('/^[a-z0-9][a-z0-9-]*$/', $s);
}

function sanitize_slug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9-]+/', '-', $s);
    $s = preg_replace('/-{2,}/', '-', $s);
    return trim($s, '-');
}

function scan_playlists(string $dir): array {
    $playlists = [];
    foreach (glob("$dir/*.json") as $f) {
        $data = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($data) || empty($data['slug'])) continue;
        $playlists[] = $data;
    }
    usort($playlists, fn($a, $b) => strcasecmp($a['title'] ?? '', $b['title'] ?? ''));
    return $playlists;
}

/**
 * Extract YouTube video ID from any common URL shape.
 * Returns 11-char ID or empty string.
 */
function extract_yt_video_id(string $url): string {
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $url, $m)) {
        return $m[1];
    }
    if (preg_match('~^[A-Za-z0-9_-]{11}$~', trim($url), $m)) {
        return $m[0]; // bare ID
    }
    return '';
}

/**
 * Resolve a YouTube video URL via noembed (no API key required).
 * Returns ['video_id'=>..., 'title'=>..., 'thumbnail'=>...] or ['error'=>...]
 */
function resolve_yt_video(string $url): array {
    $videoId = extract_yt_video_id($url);
    if ($videoId === '') {
        return ['error' => 'Could not extract a YouTube video ID from that URL.'];
    }
    $watchUrl = "https://www.youtube.com/watch?v={$videoId}";
    $noembed  = 'https://noembed.com/embed?url=' . urlencode($watchUrl);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $noembed,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_USERAGENT      => 'luminal-cms/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $title = '';
    $thumb = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
    if ($code === 200 && $response) {
        $data = json_decode($response, true);
        if (is_array($data)) {
            $title = (string)($data['title'] ?? '');
            $thumb = (string)($data['thumbnail_url'] ?? $thumb);
        }
    }

    return [
        'video_id'  => $videoId,
        'title'     => $title,
        'thumbnail' => $thumb,
    ];
}

/**
 * Fetch playlist items. YouTube caps this at 50 per request — the renderer and admin
 * both honor that as the hard ceiling. Used by the admin preview and shared by the
 * public renderer.
 */
function fetch_yt_items(string $playlist_id, string $api_key, int $max = 50): array {
    $playlist_id = yt_normalize_playlist_id($playlist_id);
    if ($playlist_id === '') {
        return ['error' => 'Playlist ID is missing or is not a valid YouTube playlist link (needs a ?list=… ID).'];
    }
    if ($api_key === '') {
        return ['error' => 'API Key is not configured.'];
    }
    $pageSize = max(1, min(50, $max));
    $url = "https://www.googleapis.com/youtube/v3/playlistItems"
         . "?part=snippet,contentDetails&maxResults={$pageSize}"
         . "&playlistId=" . urlencode($playlist_id)
         . "&key=" . urlencode($api_key);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_USERAGENT      => 'luminal-cms/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response  = curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code !== 200) {
        $errData = @json_decode($response, true);
        $errMsg  = $errData['error']['message'] ?? "HTTP $http_code";
        return ['error' => "YouTube API error: $errMsg"];
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data['items'])) {
        return ['error' => 'Could not parse playlist data or playlist is empty.'];
    }
    return $data['items'];
}

/**
 * Extract a real YouTube playlist ID from a URL (?list=...). Auto-generated mix/radio
 * lists (RD…) are NOT real playlists and are ignored. Returns '' when none.
 */
function extract_yt_playlist_id(string $url): string {
    if (preg_match('~[?&]list=([A-Za-z0-9_-]+)~i', $url, $m)) {
        $id = $m[1];
        if (preg_match('~^RD~', $id)) return '';           // radio/autoplay mix — skip
        return $id;
    }
    return '';
}

/**
 * Accept EITHER a bare playlist ID or any YouTube URL containing ?list=… and return the
 * bare ID. Users paste URLs into the "Playlist ID" field constantly; sending a URL to
 * playlistItems yields YouTube's opaque "Invalid Value" 400, which reads to the user as
 * "my API key is bad". Normalize at every boundary instead of blaming the key.
 * Returns '' when the input is a URL we cannot parse a real list ID out of.
 */
function yt_normalize_playlist_id(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $fromUrl = extract_yt_playlist_id($raw);
    if ($fromUrl !== '') return $fromUrl;
    // Looks like a URL/query but had no usable list= → don't pass it through as an ID.
    if (preg_match('~[/?&=:]~', $raw)) return '';
    return $raw;
}

/**
 * Plain keyless GET. Channel resolution deliberately does NOT use the Data API:
 * channels.list needs a key, and — exactly as with playlists since ce39e43 — a channel
 * does not otherwise need one to render. Returns '' on any failure; callers degrade.
 */
function yt_http_get(string $url, int $timeout = 12): string {
    if ($url === '') return '';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'luminal-cms/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && is_string($body)) ? $body : '';
}

/**
 * Channel ID out of any channel URL we can read directly. Returns '' when the URL
 * needs a page fetch (handle / legacy custom name) or is not a channel URL at all.
 */
function extract_yt_channel_id(string $url): string {
    $u = trim($url);
    if (preg_match('~youtube\.com/channel/(UC[A-Za-z0-9_-]{22})~i', $u, $m)) return $m[1];
    if (preg_match('~^UC[A-Za-z0-9_-]{22}$~', $u, $m)) return $m[0];
    return '';
}

/**
 * The canonical channel page for a handle / legacy custom URL — the only keyless way
 * to learn the UC… ID behind it. Returns '' when this is not that kind of URL.
 */
function yt_channel_page_url(string $url): string {
    $u = trim($url);
    if ($u === '') return '';
    if (preg_match('~^@[A-Za-z0-9._-]{3,30}$~', $u)) return 'https://www.youtube.com/' . $u;
    if (preg_match('~youtube\.com/(@[A-Za-z0-9._-]+|c/[A-Za-z0-9._%-]+|user/[A-Za-z0-9._%-]+)~i', $u, $m)) {
        return 'https://www.youtube.com/' . $m[1];
    }
    return '';
}

/**
 * Every channel's uploads feed is a real playlist whose ID is the channel ID with UC
 * swapped for UU. That is what makes a channel renderable at all: YouTube has no
 * channel embed, but /embed/videoseries?list=UU… plays the uploads with NO API key,
 * and playlistItems lists them when a key is present.
 * DERIVED AT USE, NEVER STORED — a record keeps only channel_id. One source of truth.
 */
function yt_channel_uploads_id(string $channelId): string {
    $c = trim($channelId);
    return preg_match('~^UC[A-Za-z0-9_-]{22}$~', $c) ? 'UU' . substr($c, 2) : '';
}

/**
 * Resolve any channel URL → id + display title, keylessly, by reading the channel page.
 * Also flags YouTube's auto-generated "<Artist> - Topic" channels — the kind a music
 * distributor (DistroKid et al) creates on an artist's behalf, which is how
 * a site may instead receive a Topic channel id. A Topic channel IS an ordinary
 * channel and renders by the same path; the flag only lets the UI say what it is.
 */
function resolve_yt_channel(string $url): array {
    $channelId = extract_yt_channel_id($url);
    $page = $channelId !== ''
        ? "https://www.youtube.com/channel/{$channelId}"
        : yt_channel_page_url($url);

    if ($channelId === '' && $page === '') {
        return ['error' => 'That does not look like a YouTube channel URL.'];
    }

    $title = '';
    $handle = '';
    $html = yt_http_get($page);
    if ($html !== '') {
        if ($channelId === '' && preg_match('~"externalId"\s*:\s*"(UC[A-Za-z0-9_-]{22})"~', $html, $m)) {
            $channelId = $m[1];
        }
        if (preg_match('~<title>(.*?)</title>~is', $html, $m)) {
            $t = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = trim((string)preg_replace('~\s*-\s*YouTube\s*$~u', '', $t));
        }
        if (preg_match('~"canonicalBaseUrl"\s*:\s*"/(@[A-Za-z0-9._-]+)"~', $html, $m)) {
            $handle = $m[1];
        }
    }

    if ($channelId === '') {
        return ['error' => 'Could not read a channel ID from that URL. Open the channel on YouTube and paste its /channel/UC… address instead.'];
    }

    return [
        'channel_id'       => $channelId,
        'title'            => $title,
        'handle'           => $handle,
        'is_topic'         => (bool)preg_match('~\s-\sTopic$~u', $title),
        'uploads_playlist' => yt_channel_uploads_id($channelId),
    ];
}

/**
 * The site-level default YouTube API key (Settings tab → youtube_playlist_settings.json).
 * Read through the credential vault so a sealed ENC:v1 key is transparently unsealed.
 */
function yt_global_api_key(): string {
    $f = SITE_ROOT . '/admin/data/youtube_playlist_settings.json';
    if (!is_file($f)) return '';
    if (!function_exists('cred_load_json') && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
        require_once SITE_ROOT . '/admin/config/cred_vault.php';
    }
    $s = function_exists('cred_load_json') ? cred_load_json($f) : json_decode((string)@file_get_contents($f), true);
    return is_array($s) ? trim((string)($s['api_key'] ?? '')) : '';
}

/**
 * Best-effort fetch of a playlist's own title (playlists.list). Non-fatal — '' on any miss.
 */
function fetch_yt_playlist_title(string $playlist_id, string $api_key): string {
    if ($playlist_id === '' || $api_key === '') return '';
    $url = "https://www.googleapis.com/youtube/v3/playlists?part=snippet&id="
         . urlencode($playlist_id) . "&key=" . urlencode($api_key);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_USERAGENT => 'luminal-cms/1.0', CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return '';
    $d = json_decode($resp, true);
    return (string)($d['items'][0]['snippet']['title'] ?? '');
}

// ---- Route ----
$action = $_GET['action'] ?? '';
$input  = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($ct, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
    } else {
        $input = $_POST;
    }
}

switch ($action) {

    case 'list_playlists':
        $playlists = scan_playlists($DATA_DIR);
        json_out(['ok' => true, 'playlists' => $playlists, 'count' => count($playlists)]);
        break;

    case 'get_playlist':
        $slug = sanitize_slug($_GET['slug'] ?? '');
        if (!$slug) json_out(['ok' => false, 'error' => 'Missing slug'], 400);
        $file = "$DATA_DIR/$slug.json";
        if (!is_file($file)) json_out(['ok' => false, 'error' => 'Playlist not found'], 404);
        $data = json_decode(file_get_contents($file), true);
        json_out(['ok' => true, 'playlist' => $data]);
        break;

    case 'preview_playlist':
        $apiKey     = trim($_GET['api_key'] ?? '');
        $playlistId = trim($_GET['playlist_id'] ?? '');
        if (!$apiKey || !$playlistId) json_out(['ok' => false, 'error' => 'Missing api_key or playlist_id'], 400);

        $items = fetch_yt_items($playlistId, $apiKey, 8);
        if (isset($items['error'])) {
            json_out(['ok' => false, 'error' => $items['error']]);
        }
        // Return simplified preview data
        $preview = [];
        foreach ($items as $it) {
            $preview[] = [
                'video_id'  => $it['contentDetails']['videoId'] ?? '',
                'title'     => $it['snippet']['title'] ?? '',
                'thumbnail' => $it['snippet']['thumbnails']['medium']['url']
                            ?? $it['snippet']['thumbnails']['default']['url']
                            ?? '',
            ];
        }
        json_out(['ok' => true, 'items' => $preview, 'count' => count($preview)]);
        break;

    case 'resolve_video':
        $url = trim($_GET['url'] ?? $input['url'] ?? '');
        if ($url === '') json_out(['ok' => false, 'error' => 'Missing url'], 400);
        $resolved = resolve_yt_video($url);
        if (!empty($resolved['error'])) {
            json_out(['ok' => false, 'error' => $resolved['error']]);
        }
        json_out(['ok' => true] + $resolved);
        break;

    // Paste ANY YouTube URL → auto-detect playlist vs single, return a preview.
    // The API key stays server-side (pulled from settings) so the browser never sees it.
    case 'smart_resolve':
        $url = trim($_GET['url'] ?? $input['url'] ?? '');
        if ($url === '') json_out(['ok' => false, 'error' => 'Missing url'], 400);

        $plId = extract_yt_playlist_id($url);
        if ($plId !== '') {
            // Prefer the site-level key, but fall back to a key typed into the modal's
            // Advanced field. Without this fallback a site with no Settings key can never
            // resolve — and the Studio has no UI to set one — so the user is told to fix
            // a key they have already supplied. (The browser typed it; echoing it back
            // leaks nothing that isn't already in that tab.)
            $key = yt_global_api_key();
            if ($key === '') $key = trim($_GET['api_key'] ?? $input['api_key'] ?? '');
            if ($key === '') {
                // A key is needed for the PREVIEW GRID, not for the playlist to work.
                // Hand back the resolved id so the entry can be saved and rendered as a
                // player embed; refusing outright made a key look mandatory when it is not.
                json_out([
                    'ok'          => true,
                    'kind'        => 'playlist',
                    'playlist_id' => $plId,
                    'title'       => '',
                    'items'       => [],
                    'count'       => 0,
                    'no_key'      => true,
                    'note'        => 'Playlist detected. No API key set, so there is no preview or video grid — the playlist will render as a player. Add a key here or in Settings to get the grid.',
                ]);
            }
            $items = fetch_yt_items($plId, $key, 8);
            if (isset($items['error'])) json_out(['ok' => false, 'error' => $items['error']]);
            $preview = [];
            foreach ($items as $it) {
                $preview[] = [
                    'video_id'  => $it['contentDetails']['videoId'] ?? '',
                    'title'     => $it['snippet']['title'] ?? '',
                    'thumbnail' => $it['snippet']['thumbnails']['medium']['url']
                                ?? $it['snippet']['thumbnails']['default']['url'] ?? '',
                ];
            }
            json_out([
                'ok' => true, 'kind' => 'playlist',
                'playlist_id' => $plId,
                'title'       => fetch_yt_playlist_title($plId, $key),
                'items'       => $preview,
                'count'       => count($preview),
            ]);
        }

        // Channel BEFORE video. The two cannot actually collide — a channel URL has no
        // ?list= and no 11-char watch id — but resolve ORDER is what decides what a
        // /channel/UC…/videos URL becomes, so state it rather than leave it to luck.
        if (extract_yt_channel_id($url) !== '' || yt_channel_page_url($url) !== '') {
            $chan = resolve_yt_channel($url);
            if (!empty($chan['error'])) json_out(['ok' => false, 'error' => $chan['error']]);

            $uploads = (string)$chan['uploads_playlist'];
            $key = yt_global_api_key();
            if ($key === '') $key = trim($_GET['api_key'] ?? $input['api_key'] ?? '');

            // Same rule as playlists: the key buys the GRID, not the ability to render.
            $preview = [];
            $noKey   = ($key === '');
            if (!$noKey) {
                $items = fetch_yt_items($uploads, $key, 8);
                if (!isset($items['error'])) {
                    foreach ($items as $it) {
                        $preview[] = [
                            'video_id'  => $it['contentDetails']['videoId'] ?? '',
                            'title'     => $it['snippet']['title'] ?? '',
                            'thumbnail' => $it['snippet']['thumbnails']['medium']['url']
                                        ?? $it['snippet']['thumbnails']['default']['url'] ?? '',
                        ];
                    }
                }
            }

            json_out([
                'ok'               => true,
                'kind'             => 'channel',
                'channel_id'       => $chan['channel_id'],
                'title'            => $chan['title'],
                'handle'           => $chan['handle'],
                'is_topic'         => $chan['is_topic'],
                'uploads_playlist' => $uploads,
                'items'            => $preview,
                'count'            => count($preview),
                'no_key'           => $noKey,
                'note'             => $noKey
                    ? 'Channel detected. No API key set, so there is no video grid — the uploads feed will render as a player. Add a key here or in Settings to get the grid.'
                    : '',
            ]);
        }

        $vid = extract_yt_video_id($url);
        if ($vid !== '') {
            $resolved = resolve_yt_video($url);
            if (!empty($resolved['error'])) json_out(['ok' => false, 'error' => $resolved['error']]);
            json_out(['ok' => true, 'kind' => 'video'] + $resolved);
        }

        json_out(['ok' => false, 'error' => 'No YouTube playlist or video found in that URL.']);
        break;

    case 'save_playlist':
        if (!$input) json_out(['ok' => false, 'error' => 'No input'], 400);

        $slug  = sanitize_slug($input['slug'] ?? '');
        $title = trim($input['title'] ?? '');
        $type  = (string)($input['type'] ?? 'playlist');
        $type  = in_array($type, ['video', 'channel'], true) ? $type : 'playlist';
        if (!$slug)  json_out(['ok' => false, 'error' => 'Invalid or missing slug'], 400);
        if (!$title) json_out(['ok' => false, 'error' => 'Title is required'], 400);

        $file = "$DATA_DIR/$slug.json";
        $isNew = !is_file($file);

        // Check for duplicate slug on create
        if ($isNew && isset($input['_creating']) && is_file($file)) {
            json_out(['ok' => false, 'error' => "Slug '$slug' already exists"], 409);
        }

        $existing = $isNew ? [] : (json_decode(file_get_contents($file), true) ?: []);

        if ($type === 'video') {
            // Resolve URL → video_id if URL provided; otherwise trust existing id.
            $videoUrl = trim($input['video_url'] ?? '');
            $videoId  = $videoUrl !== '' ? extract_yt_video_id($videoUrl) : ($existing['video_id'] ?? '');
            if ($videoId === '') {
                json_out(['ok' => false, 'error' => 'Valid YouTube video URL or ID required'], 400);
            }
            $videoTitle = trim($input['video_title'] ?? $existing['video_title'] ?? $title);
            $videoThumb = trim($input['video_thumbnail'] ?? $existing['video_thumbnail'] ?? "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg");
            $aspect     = (string)($input['aspect_ratio'] ?? $existing['aspect_ratio'] ?? '16/9');
            $aspect     = in_array($aspect, ['9/16', '1/1'], true) ? $aspect : '16/9';

            $entry = [
                'type'              => 'video',
                'title'             => $title,
                'slug'              => $slug,
                'video_id'          => $videoId,
                'video_title'       => $videoTitle,
                'video_thumbnail'   => $videoThumb,
                'video_description' => trim((string)($input['video_description'] ?? $existing['video_description'] ?? '')),
                'aspect_ratio'      => $aspect,
                'show_description'  => (bool)($input['show_description'] ?? $existing['show_description'] ?? false),
                'created'           => $existing['created'] ?? date('c'),
                'updated'           => date('c'),
            ];
        } elseif ($type === 'channel') {
            // A channel record stores ONLY its channel_id. The uploads playlist that
            // actually renders is derived from it (UC… → UU…) at render time, so the
            // record never carries a second, staleable copy of the same fact.
            $channelId = trim((string)($input['channel_id'] ?? ''));
            if ($channelId === '') $channelId = extract_yt_channel_id(trim((string)($input['channel_url'] ?? '')));
            if ($channelId === '') $channelId = trim((string)($existing['channel_id'] ?? ''));
            // Same rule the video branch has always had and the playlist branch gained
            // on 2026-08-17: refuse to save a record that renders to nothing.
            if (yt_channel_uploads_id($channelId) === '') {
                json_out(['ok' => false, 'error' => 'A YouTube channel ID (UC…) is required — paste the channel URL into Smart Add and press Resolve.'], 400);
            }

            $entry = [
                'type'             => 'channel',
                'title'            => $title,
                'slug'             => $slug,
                'channel_id'       => $channelId,
                'channel_title'    => trim((string)($input['channel_title'] ?? $existing['channel_title'] ?? '')),
                'channel_handle'   => trim((string)($input['channel_handle'] ?? $existing['channel_handle'] ?? '')),
                'is_topic'         => (bool)($input['is_topic'] ?? $existing['is_topic'] ?? false),
                'api_key'          => trim($input['api_key'] ?? $existing['api_key'] ?? '') ?: yt_global_api_key(),
                'per_page'         => max(1, min(50, (int)($input['per_page'] ?? $existing['per_page'] ?? 24))),
                'max_pull'         => max(1, min(50, (int)($input['max_pull'] ?? $existing['max_pull'] ?? 50))),
                'show_description' => (bool)($input['show_description'] ?? $existing['show_description'] ?? false),
                'columns'          => max(1, min(8, (int)($input['columns'] ?? $existing['columns'] ?? 4))),
                'player_max'       => max(30, min(100, (int)($input['player_max'] ?? $existing['player_max'] ?? 100))),
                'grid_match'       => (bool)($input['grid_match'] ?? $existing['grid_match'] ?? false),
                'auto_advance'     => (bool)($input['auto_advance'] ?? $existing['auto_advance'] ?? true),
                'loop_playlist'    => (bool)($input['loop_playlist'] ?? $existing['loop_playlist'] ?? false),
                'card_gap'         => max(0, min(80, (int)($input['card_gap'] ?? $existing['card_gap'] ?? 10))),
                'created'          => $existing['created'] ?? date('c'),
                'updated'          => date('c'),
            ];
        } else {
            // The video branch below has always refused an empty video_id; the
            // playlist branch did not, so Save wrote a record with
            // playlist_id:"" that renders to nothing on the page and gives no
            // hint why.
            // Same rule for both types now.
            $playlistId = yt_normalize_playlist_id((string)($input['playlist_id'] ?? $existing['playlist_id'] ?? ''));
            if ($playlistId === '') {
                json_out(['ok' => false, 'error' => 'Playlist ID is required — paste the YouTube URL into Smart Add and let it resolve, or type the ID into Advanced.'], 400);
            }

            $entry = [
                'type'             => 'playlist',
                'title'            => $title,
                'slug'             => $slug,
                'api_key'          => trim($input['api_key'] ?? $existing['api_key'] ?? '') ?: yt_global_api_key(),
                'playlist_id'      => $playlistId,
                'per_page'         => max(1, min(50, (int)($input['per_page'] ?? $existing['per_page'] ?? 24))),
                'max_pull'         => max(1, min(50, (int)($input['max_pull'] ?? $existing['max_pull'] ?? 50))),
                'show_description' => (bool)($input['show_description'] ?? $existing['show_description'] ?? false),
                'columns'          => max(1, min(8, (int)($input['columns'] ?? $existing['columns'] ?? 4))),
                'player_max'       => max(30, min(100, (int)($input['player_max'] ?? $existing['player_max'] ?? 100))),
                'grid_match'       => (bool)($input['grid_match'] ?? $existing['grid_match'] ?? false),
                // Auto-advance defaults ON — a playlist that halts after one video is nobody's intent.
                'auto_advance'     => (bool)($input['auto_advance'] ?? $existing['auto_advance'] ?? true),
                'loop_playlist'    => (bool)($input['loop_playlist'] ?? $existing['loop_playlist'] ?? false),
                'card_gap'         => max(0, min(80, (int)($input['card_gap'] ?? $existing['card_gap'] ?? 10))),
                'created'          => $existing['created'] ?? date('c'),
                'updated'          => date('c'),
            ];
        }

        // Encode BEFORE writing. json_encode returns false on a single bad byte
        // (a Latin-1 title pasted from YouTube will do it), and
        // file_put_contents($f, false) writes an empty file and returns int(0)
        // — so a `!== false` check on the write still passes while the record
        // is destroyed. Check the encode, then check the byte count.
        $payload = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            json_out(['ok' => false, 'error' => 'Could not encode this playlist (' . json_last_error_msg() . ') — nothing was written.'], 500);
        }
        $written = file_put_contents($file, $payload);
        if ($written !== strlen($payload)) {
            json_out(['ok' => false, 'error' => 'Failed to write file'], 500);
        }
        @chown($file, 'www-data');
        @chgrp($file, 'www-data');

        json_out(['ok' => true, 'playlist' => $entry, 'created' => $isNew]);
        break;

    case 'delete_playlist':
        if (!$input) json_out(['ok' => false, 'error' => 'No input'], 400);
        $slug = sanitize_slug($input['slug'] ?? '');
        if (!$slug) json_out(['ok' => false, 'error' => 'Missing slug'], 400);

        $file = "$DATA_DIR/$slug.json";
        if (!is_file($file)) json_out(['ok' => false, 'error' => 'Playlist not found'], 404);

        @unlink($file);
        @unlink("$CACHE_DIR/$slug.json");

        json_out(['ok' => true, 'deleted' => $slug]);
        break;

    case 'clear_cache':
        $slug = sanitize_slug($input['slug'] ?? $_GET['slug'] ?? '');
        $cleared = 0;
        if ($slug) {
            $cf = "$CACHE_DIR/$slug.json";
            if (is_file($cf)) { @unlink($cf); $cleared = 1; }
        } else {
            foreach (glob("$CACHE_DIR/*.json") as $cf) {
                @unlink($cf);
                $cleared++;
            }
        }
        json_out(['ok' => true, 'cleared' => $cleared]);
        break;

    default:
        json_out(['ok' => false, 'error' => "Unknown action: $action"], 400);
}
