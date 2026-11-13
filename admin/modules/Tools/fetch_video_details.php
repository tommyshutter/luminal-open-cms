<?php
/*
 * The Site - Fetch Video Details
 * File Path: /admin/fetch_video_details.php
 * Version: 2025.07.07.10.49.00
 * Description: Fetches details (title, thumbnail, type) for YouTube, Instagram, Facebook, and TikTok URLs.
 * Author: Gemini
 */
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/auth.php';
requireAuth(); // Ensure only logged-in admins can access this

header('Content-Type: application/json');

$videoUrl = $_GET['url'] ?? '';

$details = [
    'type' => 'unknown',
    'url' => $videoUrl,
    'title' => 'Could not fetch title',
    'thumbnail' => ''
];

if (empty($videoUrl)) {
    echo json_encode(['error' => 'No URL provided.']);
    exit;
}

// Function to get data from URL (e.g., cURL)
function getUrlContents($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // WARNING: For development, disable SSL verification. Re-enable in production.
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ($http_code >= 200 && $http_code < 300) ? $data : false;
}

// YouTube
if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $videoUrl, $matches)) {
    $videoId = $matches[1];
    $details['type'] = 'youtube';
    $details['url'] = "https://www.youtube.com/watch?v=" . $videoId; // Standardize URL
    $details['thumbnail'] = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg"; // High quality default thumbnail

    // Fetch title from YouTube API (requires API key) or parsing page HTML (less reliable)
    // For simplicity, we'll try parsing HTML meta tags or just use the URL
    $html = getUrlContents($details['url']);
    if ($html) {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $titleMatches)) {
            $details['title'] = html_entity_decode($titleMatches[1], ENT_QUOTES, 'UTF-8');
            $details['title'] = str_replace(' - YouTube', '', $details['title']); // Clean up YouTube suffix
        }
    }
}
// Instagram (requires Facebook Developer App for robust API access, otherwise basic parsing)
elseif (preg_match('/(?:instagram\.com\/p\/|instagr\.am\/p\/)([a-zA-Z0-9_-]+)/i', $videoUrl, $matches) ||
          preg_match('/(?:instagram\.com\/reels\/|instagr\.am\/reels\/)([a-zA-Z0-9_-]+)/i', $videoUrl, $matches)) {
    $shortcode = $matches[1];
    $details['type'] = 'instagram';
    $details['url'] = "https://www.instagram.com/p/{$shortcode}/"; // Standardize
    // Instagram embeds are tricky without their API or Oembed endpoint
    // For now, a generic thumbnail/title or attempt to parse if not public.
    // A robust solution needs an Instagram Basic Display API token or a scraping approach which is not recommended.
    // For testing: $details['thumbnail'] = "https://www.instagram.com/p/{$shortcode}/media/?size=l"; // Often redirects to actual image
    
    // A simple method for public profiles: use oEmbed endpoint for basic info if allowed
    $oembedUrl = "https://www.instagram.com/oembed/?url=" . urlencode($details['url']);
    $oembedData = json_decode(getUrlContents($oembedUrl), true);
    if ($oembedData && isset($oembedData['title'])) {
        $details['title'] = $oembedData['title'];
        $details['thumbnail'] = $oembedData['thumbnail_url'] ?? '';
    } else {
        // Fallback: Try fetching page and parsing meta, less reliable.
        $html = getUrlContents($details['url']);
        if ($html) {
             if (preg_match('/<meta property="og:title" content="(.*?)"/i', $html, $titleMatches)) {
                 $details['title'] = html_entity_decode($titleMatches[1], ENT_QUOTES, 'UTF-8');
             }
             if (preg_match('/<meta property="og:image" content="(.*?)"/i', $html, $imageMatches)) {
                 $details['thumbnail'] = $imageMatches[1];
             }
        }
    }

}
// Facebook Video (Similar to Instagram, requires API/oEmbed for reliable data)
elseif (preg_match('/(?:facebook\.com\/(?:video\.php\?v=|watch\/\?v=)|fb\.watch\/)([0-9]+)/i', $videoUrl, $matches)) {
    $videoId = $matches[1];
    $details['type'] = 'facebook';
    $details['url'] = "https://www.facebook.com/watch/?v={$videoId}"; // Standardize

    // Facebook oEmbed is often restricted to whitelisted domains or requires an app token.
    // This will likely only work for public, embeddable videos if the server has access.
    $oembedUrl = "https://www.facebook.com/plugins/video/oembed.json/?url=" . urlencode($details['url']) . "&access_token=YOUR_FACEBOOK_APP_ID|YOUR_FACEBOOK_APP_SECRET"; // Replace with actual tokens
    $oembedData = json_decode(getUrlContents($oembedUrl), true);
    if ($oembedData && isset($oembedData['title'])) {
        $details['title'] = $oembedData['title'];
        $details['thumbnail'] = $oembedData['thumbnail_url'] ?? '';
    } else {
        // Fallback: Basic parsing from HTML, very unreliable for FB
        $html = getUrlContents($details['url']);
        if ($html) {
             if (preg_match('/<meta property="og:title" content="(.*?)"/i', $html, $titleMatches)) {
                 $details['title'] = html_entity_decode($titleMatches[1], ENT_QUOTES, 'UTF-8');
             }
             if (preg_match('/<meta property="og:image" content="(.*?)"/i', $html, $imageMatches)) {
                 $details['thumbnail'] = $imageMatches[1];
             }
        }
    }
}
// TikTok (API for details is complex, usually relies on scraping or specific libraries)
elseif (preg_match('/(?:tiktok\.com\/@(?:[a-zA-Z0-9._-]+\/video\/|v\/|share\/video\/)([0-9]+))/i', $videoUrl, $matches)) {
    $videoId = $matches[1];
    $details['type'] = 'tiktok';
    $details['url'] = "https://www.tiktok.com/foryou?is_from_webapp=1&sender_device=pc&vid={$videoId}"; // Standardized

    // TikTok embeds are highly dynamic. Without a specific API or reliable oEmbed,
    // getting title/thumbnail accurately server-side is very hard.
    // We'll try a generic approach but it might fail.
    $html = getUrlContents($details['url']);
    if ($html) {
         if (preg_match('/<meta property="og:title" content="(.*?)"/i', $html, $titleMatches)) {
             $details['title'] = html_entity_decode($titleMatches[1], ENT_QUOTES, 'UTF-8');
         }
         if (preg_match('/<meta property="og:image" content="(.*?)"/i', $html, $imageMatches)) {
             $details['thumbnail'] = $imageMatches[1];
         }
    }
    // TikTok thumbnails often use unique URLs. Example: https://p16-sign-va.tiktokcdn.com/obj/tos-maliva-p-0068/7074492659392237062~c5_720x720.jpeg?x-expires=1678827600&x-signature=...
    // Directly constructing them is risky. Rely on og:image if possible.
}
// Direct MP4 URL (if user provides a direct link to an MP4) - for preview, not adding to pool as external
elseif (preg_match('/\.(mp4|mov|webm)$/i', $videoUrl)) {
    // This part is for *external* MP4s, not uploaded ones.
    // For direct preview only, not adding to video pool unless it has unique ID
    $details['type'] = 'mp4'; // Treat as external MP4
    $details['url'] = $videoUrl;
    $details['title'] = basename($videoUrl); // Use filename as title
    $details['thumbnail'] = ''; // No auto-thumbnail for external MP4s easily
}


if ($details['type'] === 'unknown') {
    echo json_encode(['error' => 'Unsupported video URL or could not extract details.']);
} else {
    echo json_encode($details);
}

// Re-include utils.php just in case functions like extractYouTubeId were used directly here
// But this file's purpose is to fetch, not extract simple IDs.
?>