<?php
/**
 * @file /index.php
 * @version 2025.09.05.home-inline
 * Renders the configured home page directly (no redirect loops).
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__) ?: __DIR__);
}

// Reserved route: public podcast RSS feed at the clean single-segment URL
// /podcast-feed. The existing front-controller rewrite routes it here as
// ?p=podcast-feed; we serve the PodcastManager generator directly and exit —
// no .htaccess rewrite and no physical /podcasts dir (which would shadow the
// live /podcasts page). Handled BEFORE page.php so the generator can define
// its own DOMAIN and emit XML headers cleanly. Falls through if the module
// isn't installed (site simply has no podcast feed).
if (($_GET['p'] ?? '') === 'podcast-feed') {
    $feedGen = SITE_ROOT . '/admin/modules/PodcastManager/podcast_feed.php';
    if (is_file($feedGen)) {
        require $feedGen;
        exit;
    }
}

// Read home_page_slug from unified settings
$settings_path = SITE_ROOT . '/admin/data/site-settings.json';
$home_slug = 'home';
if (is_file($settings_path)) {
    $raw = @file_get_contents($settings_path);
    $cfg = json_decode((string)$raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($cfg) && !empty($cfg['home_page_slug'])) {
        $home_slug = strtolower(preg_replace('/[^A-Za-z0-9_\-]/','', (string)$cfg['home_page_slug']));
    }
}

// Render page.php inline to avoid redirect issues
// Only override slug when no explicit ?p= was provided
if (empty($_GET['p'])) {
    $_GET['p'] = $home_slug;
}
require_once SITE_ROOT . '/page.php';
?>