<?php
/*
 * The Site - OG Image Fetcher
 * File Path: /admin/fetch_og_image.php
 * Version: 2025.07.01.17.00.00
 * Description: Fetches the Open Graph (og:image) URL from a given website URL.
 * Author: Gemini
 */
header('Content-Type: application/json');

if (!isset($_GET['url'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No URL provided.']);
    exit;
}

$url = filter_var($_GET['url'], FILTER_VALIDATE_URL);

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL provided.']);
    exit;
}

// Use cURL for robust fetching
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

if ($curl_error || $http_code != 200 || $html === false) {
    http_response_code(500);
    echo json_encode(['error' => "Could not fetch page content. (cURL error: {$curl_error}, HTTP Status: {$http_code})"]);
    exit;
}

$doc = new DOMDocument();
@$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);

$imageUrl = null;
$metas = $doc->getElementsByTagName('meta');
for ($i = 0; $i < $metas->length; $i++) {
    $meta = $metas->item($i);
    if (strtolower($meta->getAttribute('property')) == 'og:image') {
        $imageUrl = $meta->getAttribute('content');
        break;
    }
}

if ($imageUrl) {
    echo json_encode(['imageUrl' => $imageUrl]);
} else {
    echo json_encode(['error' => 'og:image meta tag not found on the page.']);
}
?>