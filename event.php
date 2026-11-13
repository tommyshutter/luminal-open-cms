<?php
/**
 * Event Share Page - Generates proper OG tags for individual events
 * @file    /event.php
 * @version 2026.01.26
 * - Removed cache-busting query params from og:image URLs (breaks FB scraper)
 * - Dynamic og:image:type detection based on file extension
 *
 * Usage: /event.php?id=evt_xxxxx
 * This page outputs proper OG meta tags so Facebook/Twitter show the event artwork
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', __DIR__);
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Get event ID
$eventId = $_GET['id'] ?? '';
if (!$eventId) {
    header('Location: /');
    exit;
}

// Build base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $protocol . '://' . $host;
$currentUrl = $baseUrl . '/event.php?id=' . urlencode($eventId);

// Load event data
$eventsFile = SITE_ROOT . '/admin/data/events_master.json';
$event = null;
$venue = null;

if (is_file($eventsFile)) {
    $events = json_decode(file_get_contents($eventsFile), true) ?? [];
    foreach ($events as $e) {
        if (($e['id'] ?? '') === $eventId) {
            $event = $e;
            break;
        }
    }
}

if (!$event) {
    header('Location: /');
    exit;
}

// Load venue if available
if (!empty($event['venue_id'])) {
    $venuesFile = SITE_ROOT . '/admin/data/venues_master.json';
    if (is_file($venuesFile)) {
        $venues = json_decode(file_get_contents($venuesFile), true) ?? [];
        foreach ($venues as $v) {
            if (($v['id'] ?? '') === $event['venue_id']) {
                $venue = $v;
                break;
            }
        }
    }
}

// Load site settings
$siteSettingsFile = SITE_ROOT . '/admin/data/site-settings.json';
$siteSettings = is_file($siteSettingsFile) ? (json_decode(file_get_contents($siteSettingsFile), true) ?? []) : [];
$siteName = $siteSettings['site_name'] ?? $host;

// Event details
$title = $event['title'] ?? 'Event';
$description = strip_tags($event['content_html'] ?? '');
if (strlen($description) > 200) {
    $description = substr($description, 0, 197) . '...';
}

// Date string
$dateStr = '';
if (!empty($event['start_date'])) {
    $dt = strtotime($event['start_date'] . ' ' . ($event['start_time'] ?? '00:00'));
    if ($dt) {
        $dateStr = date('l, F j, Y', $dt);
        if (!empty($event['start_time'])) {
            $dateStr .= ' at ' . date('g:i A', $dt);
        }
    }
}

// Venue info for description
$venueStr = '';
if ($venue) {
    $venueStr = $venue['name'] ?? '';
    if (!empty($venue['address'])) {
        $venueStr .= ' - ' . $venue['address'];
    }
}

// Build full description
$fullDesc = $title;
if ($dateStr) $fullDesc .= ' | ' . $dateStr;
if ($venueStr) $fullDesc .= ' | ' . $venueStr;
if ($description) $fullDesc .= ' | ' . $description;
$fullDesc = substr($fullDesc, 0, 300);

// Event image - THIS IS THE KEY PART
// IMPORTANT: Do NOT add cache-busting query params to og:image URLs!
// Facebook's image scraper often fails on URLs with query parameters.
// Cache busting is handled by changing the event URL, not the image URL.
$imageUrl = '';
$imageType = 'image/jpeg'; // Default to JPEG (most common)
if (!empty($event['image'])) {
    $imgPath = '/' . ltrim($event['image'], '/');
    $imgFullPath = SITE_ROOT . $imgPath;
    if (is_file($imgFullPath)) {
        $imageUrl = $baseUrl . $imgPath;
        // Detect actual image type
        $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            $imageType = 'image/png';
        } elseif ($ext === 'webp') {
            $imageType = 'image/webp';
        } elseif ($ext === 'gif') {
            $imageType = 'image/gif';
        }
    }
}

// Fallback to site OG image if no event image
if (!$imageUrl) {
    $hostClean = preg_replace('/:\d+$/', '', $host);
    $ogFile = SITE_ROOT . '/admin/data/site-assets/og.' . $hostClean . '.png';
    if (is_file($ogFile)) {
        $imageUrl = $baseUrl . '/admin/data/site-assets/og.' . $hostClean . '.png';
        $imageType = 'image/png';
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo $h($title); ?> - <?php echo $h($siteName); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo $h($fullDesc); ?>">

    <!-- Open Graph - Event Specific -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo $h($siteName); ?>">
    <meta property="og:title" content="<?php echo $h($title); ?>">
    <meta property="og:url" content="<?php echo $h($currentUrl); ?>">
    <meta property="og:description" content="<?php echo $h($fullDesc); ?>">
<?php if ($imageUrl): ?>
    <meta property="og:image" content="<?php echo $h($imageUrl); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="<?php echo $h($imageType); ?>">
<?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $h($title); ?>">
    <meta name="twitter:description" content="<?php echo $h($fullDesc); ?>">
<?php if ($imageUrl): ?>
    <meta name="twitter:image" content="<?php echo $h($imageUrl); ?>">
<?php endif; ?>

    <!-- Redirect to main page after crawlers have read meta tags -->
    <meta http-equiv="refresh" content="0;url=/page.php?p=events">

    <style>
        body {
            font-family: system-ui, sans-serif;
            background: #111;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            text-align: center;
        }
        .loading { opacity: 0.7; }
    </style>
</head>
<body>
    <div class="loading">
        <p>Loading event...</p>
    </div>
    <script>
        // Immediate redirect for browsers (crawlers will read the meta tags first)
        window.location.href = '/page.php?p=events';
    </script>
</body>
</html>
