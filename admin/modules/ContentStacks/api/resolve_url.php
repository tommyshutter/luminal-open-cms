<?php
/**
 * URL Details Resolver
 * @file-location: SITE-ROOT/admin/modules/ContentStacks/api/resolve_url.php
 * @description   Fetches title/thumbnail/aspect/metadata for a URL.
 *                YouTube + Shorts, TikTok, Vimeo, Twitter/X, generic oEmbed
 *                via noembed.com; Facebook handled directly (OG + JSON-LD
 *                scraping with multi-UA fallback because FB rejects most
 *                third-party oEmbed providers).
 *
 *                Facebook support covers: events, reels, videos, live,
 *                posts/photos. FB URL pattern detection sets fb_subtype
 *                so the UI can tag the item appropriately.
 */
require_once dirname(__DIR__, 3) . '/auth.php';
requireAuth();
header('Content-Type: application/json');

$url = $_POST['url'] ?? '';

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or no URL provided.']);
    exit;
}

/** Detect overall provider */
function detect_url_type($url) {
    if (preg_match('/youtube\.com|youtu\.be/i', $url)) return 'youtube';
    if (preg_match('/tiktok\.com/i', $url)) return 'tiktok';
    if (preg_match('/facebook\.com|fb\.com|fb\.watch/i', $url)) return 'facebook';
    if (preg_match('/x\.com|twitter\.com/i', $url)) return 'twitter';
    if (preg_match('/vimeo\.com/i', $url)) return 'vimeo';
    if (preg_match('/instagram\.com/i', $url)) return 'instagram';
    return 'unknown';
}

/**
 * Detect the FB content subtype from URL shape.
 * Returns: 'event' | 'reel' | 'video' | 'live' | 'photo' | 'post' | 'watch' | 'generic'
 */
function detect_fb_subtype($url) {
    $u = strtolower($url);
    if (preg_match('~facebook\.com/events/\d+~', $u))                          return 'event';
    if (preg_match('~facebook\.com/(reel|reels)/~', $u))                       return 'reel';
    if (preg_match('~facebook\.com/live/~', $u))                               return 'live';
    if (preg_match('~facebook\.com/watch/?\?v=\d+~', $u))                      return 'watch';
    if (preg_match('~facebook\.com/[^/]+/videos/~', $u) || strpos($u, 'fb.watch/') !== false) return 'video';
    if (preg_match('~facebook\.com/[^/]+/posts/~', $u))                        return 'post';
    if (preg_match('~facebook\.com/photo~', $u))                               return 'photo';
    return 'generic';
}

/**
 * cURL helper with configurable UA + redirect tracking.
 * Returns: ['code', 'body', 'final_url', 'err']
 */
function fetch_url($endpoint, $ua = 'Mozilla/5.0 (compatible; ContentStacks/1.0)', $timeout = 12) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Accept-Language: en-US,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'final_url' => (string)$finalUrl, 'err' => $err];
}

/** Extract all og:* and name="*" meta tags from an HTML body. */
function parse_meta_tags($html) {
    $og = [];
    if (preg_match_all(
        '/<meta\s+(?:property|name)="(og:[^"]+|twitter:[^"]+|description|title)"\s+content="([^"]*)"[^>]*>/i',
        $html, $m, PREG_SET_ORDER
    )) {
        foreach ($m as $match) {
            // last occurrence wins
            $og[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
        }
    }
    // Alternate order: content-first meta tags
    if (preg_match_all(
        '/<meta\s+content="([^"]*)"\s+(?:property|name)="(og:[^"]+|twitter:[^"]+|description|title)"[^>]*>/i',
        $html, $m2, PREG_SET_ORDER
    )) {
        foreach ($m2 as $match) {
            if (!isset($og[$match[2]])) $og[$match[2]] = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }
    }
    return $og;
}

/** Extract the first JSON-LD blob that matches the wanted @type(s). */
function parse_jsonld($html, array $wantedTypes) {
    if (!preg_match_all('~<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>~is', $html, $m)) {
        return null;
    }
    foreach ($m[1] as $blob) {
        $decoded = json_decode(trim($blob), true);
        if (!is_array($decoded)) continue;
        // Some pages wrap in @graph
        $candidates = [];
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            $candidates = $decoded['@graph'];
        } elseif (array_is_list($decoded)) {
            $candidates = $decoded;
        } else {
            $candidates = [$decoded];
        }
        foreach ($candidates as $c) {
            if (!is_array($c)) continue;
            $t = $c['@type'] ?? '';
            if (is_array($t)) $t = $t[0] ?? '';
            if (in_array($t, $wantedTypes, true)) return $c;
        }
    }
    return null;
}

/** Aspect-ratio guess. */
function detect_aspect($url, $type, $width = 0, $height = 0, $fbSubtype = '') {
    // Explicit vertical patterns
    if (preg_match('~youtube\.com/shorts/~i', $url)) return '9:16';
    if ($type === 'tiktok') return '9:16';
    if (preg_match('~/reel/~i', $url) || $fbSubtype === 'reel') return '9:16';
    if ($width > 0 && $height > 0 && $height > $width) return '9:16';
    return '16:9';
}

$type = detect_url_type($url);

/* ─────────────────────────────────────────────────────────────
 *  FACEBOOK
 *  FB actively degrades responses from unknown IPs — rotate UAs
 *  (facebookexternalhit → Twitterbot → regular browser), parse
 *  both OG meta and JSON-LD, detect login-wall redirects, and
 *  surface subtype-aware results.
 * ───────────────────────────────────────────────────────────── */
if ($type === 'facebook') {
    $subtype = detect_fb_subtype($url);

    $uaChain = [
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'Twitterbot/1.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    ];

    $bestOG = [];
    $bestHtml = '';
    $finalUrl = $url;
    $lastErr = '';
    $attempts = [];

    foreach ($uaChain as $ua) {
        $res = fetch_url($url, $ua, 15);
        $attempts[] = ['ua' => substr($ua, 0, 40), 'code' => $res['code'], 'final' => $res['final_url']];
        if ($res['code'] !== 200 || empty($res['body'])) { $lastErr = "HTTP {$res['code']}"; continue; }

        // Login-wall detection: FB redirects to /login/?next=... when content is gated
        if (stripos($res['final_url'], '/login') !== false || stripos($res['final_url'], 'login.php') !== false) {
            $lastErr = 'Facebook redirected to login — content is not public';
            continue;
        }

        $og = parse_meta_tags($res['body']);
        if (!empty($og['og:title']) || !empty($og['og:image']) || !empty($og['og:description'])) {
            // Prefer the richest result across UA attempts
            if (count($og) > count($bestOG)) {
                $bestOG    = $og;
                $bestHtml  = $res['body'];
                $finalUrl  = $res['final_url'] ?: $url;
            }
            // If we already have title + thumbnail, stop — good enough.
            if (!empty($og['og:title']) && !empty($og['og:image'])) break;
        }
    }

    if (empty($bestOG)) {
        echo json_encode([
            'success'    => false,
            'message'    => 'Facebook returned no metadata (' . $lastErr . '). The content may be private, a login-gated page, or temporarily unreachable.',
            'fb_subtype' => $subtype,
            'attempts'   => $attempts,
        ]);
        exit;
    }

    // Try JSON-LD for richer structured data (events expose startDate/endDate/location)
    $ldTypes = ['Event','SocialMediaPosting','VideoObject','Article','WebPage'];
    $ld = parse_jsonld($bestHtml, $ldTypes);

    $title     = $bestOG['og:title']       ?? ($ld['name'] ?? 'Facebook ' . ucfirst($subtype === 'generic' ? 'post' : $subtype));
    $thumbnail = $bestOG['og:image']       ?? ($ld['image']['url'] ?? ($ld['image'] ?? ''));
    if (is_array($thumbnail)) $thumbnail = $thumbnail[0] ?? '';
    $desc      = $bestOG['og:description'] ?? ($ld['description'] ?? '');
    $ogType    = $bestOG['og:type']        ?? '';
    $ogW       = (int)($bestOG['og:video:width']  ?? $bestOG['og:image:width']  ?? 0);
    $ogH       = (int)($bestOG['og:video:height'] ?? $bestOG['og:image:height'] ?? 0);
    $author    = $bestOG['og:site_name'] ?? '';
    $videoUrl  = $bestOG['og:video']       ?? $bestOG['og:video:secure_url'] ?? '';

    // Event-specific fields
    $eventStart = $eventEnd = $eventLocation = '';
    if ($subtype === 'event' && is_array($ld)) {
        $eventStart    = (string)($ld['startDate'] ?? '');
        $eventEnd      = (string)($ld['endDate']   ?? '');
        if (isset($ld['location'])) {
            $loc = $ld['location'];
            if (is_array($loc)) {
                $eventLocation = (string)($loc['name'] ?? $loc['address']['streetAddress'] ?? '');
            } else {
                $eventLocation = (string)$loc;
            }
        }
    }

    $payload = [
        'success'    => true,
        'type'       => 'facebook',
        'fb_subtype' => $subtype,   // event | reel | video | live | post | photo | watch | generic
        'path'       => $finalUrl,
        'url'        => $finalUrl,
        'title'      => $title,
        'description'=> $desc,
        'author'     => $author,
        'thumbnail'  => $thumbnail,
        'video_url'  => $videoUrl,
        'og_type'    => $ogType,
        'aspect'     => detect_aspect($finalUrl, 'facebook', $ogW, $ogH, $subtype),
    ];
    if ($subtype === 'event') {
        $payload['event'] = [
            'start_date' => $eventStart,
            'end_date'   => $eventEnd,
            'location'   => $eventLocation,
        ];
    }
    echo json_encode($payload);
    exit;
}

/* ─── TIKTOK (official oEmbed) ─── */
if ($type === 'tiktok') {
    $oembedUrl = 'https://www.tiktok.com/oembed?url=' . urlencode($url);
    $res = fetch_url($oembedUrl);

    if ($res['code'] !== 200 || empty($res['body'])) {
        echo json_encode(['success' => false, 'message' => 'Could not fetch TikTok details.']);
        exit;
    }

    $data = json_decode($res['body'], true);
    if (!is_array($data) || !empty($data['error']) || empty($data['title'])) {
        echo json_encode(['success' => false, 'message' => $data['message'] ?? 'TikTok returned no data. The video may be private.']);
        exit;
    }

    $tw = (int)($data['thumbnail_width']  ?? 0);
    $th = (int)($data['thumbnail_height'] ?? 0);

    echo json_encode([
        'success'   => true,
        'type'      => 'tiktok',
        'path'      => $url,
        'url'       => $url,
        'title'     => $data['title'] ?? 'TikTok',
        'author'    => $data['author_name'] ?? '',
        'thumbnail' => $data['thumbnail_url'] ?? '',
        'aspect'    => detect_aspect($url, 'tiktok', $tw, $th),
    ]);
    exit;
}

/* ─── Real YouTube playlist ID from ?list= (ignore RD… auto-mixes) ─── */
function extract_yt_playlist_id($url) {
    if (preg_match('~[?&]list=([A-Za-z0-9_-]+)~i', $url, $m)) {
        return preg_match('~^RD~', $m[1]) ? '' : $m[1];
    }
    return '';
}

/* ─── Site default YouTube API key (best-effort enrichment only) ─── */
function cs_yt_key() {
    $f = dirname(__DIR__, 3) . '/data/youtube_playlist_settings.json';
    if (!is_file($f)) return '';
    $cv = dirname(__DIR__, 3) . '/config/cred_vault.php';
    if (!function_exists('cred_load_json') && is_file($cv)) require_once $cv;
    $s = function_exists('cred_load_json') ? cred_load_json($f) : json_decode((string)@file_get_contents($f), true);
    return is_array($s) ? trim((string)($s['api_key'] ?? '')) : '';
}

/* ─── YouTube PLAYLIST → native videoseries embed (auto-detected; no API key needed to PLAY) ─── */
$ytPlaylistId = ($type === 'youtube') ? extract_yt_playlist_id($url) : '';
if ($ytPlaylistId !== '') {
    $plTitle = 'YouTube Playlist';
    $plThumb = '';
    if (preg_match('~[?&]v=([A-Za-z0-9_\-]{11})~', $url, $vm)) {
        $plThumb = 'https://i.ytimg.com/vi/' . $vm[1] . '/hqdefault.jpg';
    }
    $csKey = cs_yt_key();   // enrich title + first-video thumb when a key is configured
    if ($csKey !== '') {
        $pr = fetch_url('https://www.googleapis.com/youtube/v3/playlists?part=snippet&id=' . urlencode($ytPlaylistId) . '&key=' . urlencode($csKey));
        if ($pr['code'] === 200) { $pd = json_decode($pr['body'], true); $plTitle = $pd['items'][0]['snippet']['title'] ?? $plTitle; }
        if ($plThumb === '') {
            $ir = fetch_url('https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=1&playlistId=' . urlencode($ytPlaylistId) . '&key=' . urlencode($csKey));
            if ($ir['code'] === 200) { $idd = json_decode($ir['body'], true); $plThumb = $idd['items'][0]['snippet']['thumbnails']['medium']['url'] ?? ''; }
        }
    }
    echo json_encode([
        'success'     => true,
        'type'        => 'youtube',
        'path'        => $url,
        'url'         => $url,
        'playlist_id' => $ytPlaylistId,
        'is_playlist' => true,
        'title'       => $plTitle,
        'author'      => '',
        'thumbnail'   => $plThumb,
        'aspect'      => '16:9',
    ]);
    exit;
}

/* ─── YouTube Shorts → watch URL (noembed doesn't handle /shorts/) ─── */
$resolveUrl = $url;
if (preg_match('~youtube\.com/shorts/([A-Za-z0-9_\-]+)~i', $url, $shortsMatch)) {
    $resolveUrl = 'https://www.youtube.com/watch?v=' . $shortsMatch[1];
}

/* ─── YouTube / Vimeo / Twitter / others via noembed ─── */
$resolverServiceUrl = 'https://noembed.com/embed?url=' . urlencode($resolveUrl);
$res = fetch_url($resolverServiceUrl);

if ($res['code'] !== 200 || empty($res['body'])) {
    echo json_encode(['success' => false, 'message' => 'Could not fetch details from the provider. The URL might be private, invalid, or the service is down.']);
    exit;
}

$data = json_decode($res['body'], true);

if (isset($data['error'])) {
    echo json_encode(['success' => false, 'message' => $data['error']]);
    exit;
}

echo json_encode([
    'success'   => true,
    'type'      => $type === 'unknown' ? 'youtube' : $type,
    'path'      => $url,
    'url'       => $url,
    'title'     => $data['title'] ?? 'Untitled',
    'author'    => $data['author_name'] ?? '',
    'thumbnail' => $data['thumbnail_url'] ?? '',
    'aspect'    => detect_aspect($url, $type, (int)($data['width'] ?? 0), (int)($data['height'] ?? 0)),
]);
