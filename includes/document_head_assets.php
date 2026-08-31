<?php
/**
 * Document Head Assets — OG, Favicons, Meta, Stylesheets
 * @file    /header/document_head_assets.php
 * @version 2026.01.25.og-large-thumbs
 *
 * Outputs:
 *  - Viewport meta
 *  - Open Graph meta tags (with absolute URLs for images)
 *  - Twitter Card meta tags
 *  - Favicon links
 *  - Stylesheets
 */

declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

// Helper for HTML escaping
$_h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Build absolute base URL
$_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_hostClean = preg_replace('/:\d+$/', '', $_host); // Remove port if present
$_baseUrl = $_protocol . '://' . $_host;
$_currentUrl = $_baseUrl . ($_SERVER['REQUEST_URI'] ?? '/');

// Site settings from globals (set by header_core.php)
$_siteSettings = $GLOBALS['SITE_SETTINGS'] ?? [];
$_siteTitle = $GLOBALS['siteTitle'] ?? $_siteSettings['site_title'] ?? $_host;
$_siteName = $GLOBALS['artistName'] ?? $_siteSettings['site_name'] ?? $_siteTitle;
$_siteDesc = $_siteSettings['meta_description'] ?? $_siteSettings['site_description'] ?? '';

// Asset paths
$_assetsDir = '/admin/data/site-assets';
$_assetsPath = SITE_ROOT . $_assetsDir;

// OG Image Priority:
// 1. Page-specific og_image (set by page.php before including this file)
// 2. Site's domain-specific og image (og.{domain}.png)
// 3. Site's generic og image (og.png)

$_ogImageUrl = '';

// Check for page-specific OG image first
if (!empty($GLOBALS['PAGE_OG_IMAGE'])) {
    $pageImg = $GLOBALS['PAGE_OG_IMAGE'];
    // Make absolute URL if relative
    if (strpos($pageImg, 'http') === 0) {
        $_ogImageUrl = $pageImg;
    } else {
        // Ensure leading slash and build full URL
        $pageImg = '/' . ltrim($pageImg, '/');
        $_ogImageUrl = $_baseUrl . $pageImg;
    }
}

// Fall back to site's og image if no page-specific image
// Prefer JPEG for better platform compatibility (LinkedIn, WhatsApp)
if (!$_ogImageUrl) {
    // Try domain-specific JPEG first (most compatible)
    $_ogImageFile = $_assetsDir . '/og.' . $_hostClean . '.jpg';
    $_ogImageAbs = SITE_ROOT . $_ogImageFile;

    // Fallback to domain-specific PNG
    if (!is_file($_ogImageAbs)) {
        $_ogImageFile = $_assetsDir . '/og.' . $_hostClean . '.png';
        $_ogImageAbs = SITE_ROOT . $_ogImageFile;
    }

    // Fallback to generic JPEG
    if (!is_file($_ogImageAbs)) {
        $_ogImageFile = $_assetsDir . '/og.jpg';
        $_ogImageAbs = SITE_ROOT . $_ogImageFile;
    }

    // Fallback to generic PNG
    if (!is_file($_ogImageAbs)) {
        $_ogImageFile = $_assetsDir . '/og.png';
        $_ogImageAbs = SITE_ROOT . $_ogImageFile;
    }

    if (is_file($_ogImageAbs)) {
        $_ogImageUrl = $_baseUrl . $_ogImageFile;
    } else {
        // Last resort: use site logo if available
        foreach (['logo.png', 'logo.jpg', 'logo.svg'] as $_logoFile) {
            $_logoPath = SITE_ROOT . $_assetsDir . '/' . $_logoFile;
            if (is_file($_logoPath)) {
                $_ogImageUrl = $_baseUrl . $_assetsDir . '/' . $_logoFile;
                break;
            }
        }
    }
}

// Determine image type for meta tag
$_ogImageType = 'image/png';
if ($_ogImageUrl && preg_match('/\.jpe?g$/i', $_ogImageUrl)) {
    $_ogImageType = 'image/jpeg';
}

// Page-specific title and description overrides
if (!empty($GLOBALS['PAGE_OG_TITLE'])) {
    $_siteTitle = $GLOBALS['PAGE_OG_TITLE'];
}
if (!empty($GLOBALS['PAGE_OG_DESCRIPTION'])) {
    $_siteDesc = $GLOBALS['PAGE_OG_DESCRIPTION'];
}

// Favicons
$_favicon32 = $_assetsDir . '/favicon-32.png';
$_faviconIco = $_assetsDir . '/favicon.ico';
$_appleTouch = $_assetsDir . '/apple-touch-icon.png';

?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($_siteDesc): ?>
<meta name="description" content="<?php echo $_h($_siteDesc); ?>">
<?php endif; ?>
<?php
$_siteKeywords = $_siteSettings['meta_keywords'] ?? '';
if (!empty($GLOBALS['PAGE_DATA']['meta_keywords'])) {
    $_siteKeywords = $GLOBALS['PAGE_DATA']['meta_keywords'];
}
if ($_siteKeywords): ?>
<meta name="keywords" content="<?php echo $_h($_siteKeywords); ?>">
<?php endif; ?>

<!-- Open Graph (Facebook, LinkedIn, WhatsApp) -->
<meta property="og:type" content="<?php echo !empty($GLOBALS['PAGE_OG_IS_PRODUCT']) ? 'product' : 'website'; ?>">
<meta property="og:site_name" content="<?php echo $_h($_siteName); ?>">
<meta property="og:title" content="<?php echo $_h($_siteTitle); ?>">
<meta property="og:url" content="<?php echo $_h($_currentUrl); ?>">
<?php if ($_siteDesc): ?>
<meta property="og:description" content="<?php echo $_h($_siteDesc); ?>">
<?php endif; ?>
<?php if ($_ogImageUrl): ?>
<meta property="og:image" content="<?php echo $_h($_ogImageUrl); ?>">
<meta property="og:image:secure_url" content="<?php echo $_h($_ogImageUrl); ?>">
<?php if (empty($GLOBALS['PAGE_OG_IS_PRODUCT'])): /* product mockups are portrait — omit the 1200x630 hint so FB scrapes real dims */ ?>
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php endif; ?>
<meta property="og:image:alt" content="<?php echo $_h($_siteTitle); ?>">
<meta property="og:image:type" content="<?php echo $_ogImageType; ?>">
<!-- LinkedIn specific - forces large image format -->
<meta name="linkedin:card" content="summary_large_image">
<meta name="image" property="og:image" content="<?php echo $_h($_ogImageUrl); ?>">
<?php endif; ?>

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo $_h($_siteTitle); ?>">
<?php if ($_siteDesc): ?>
<meta name="twitter:description" content="<?php echo $_h($_siteDesc); ?>">
<?php endif; ?>
<?php if ($_ogImageUrl): ?>
<meta name="twitter:image" content="<?php echo $_h($_ogImageUrl); ?>">
<meta name="twitter:image:src" content="<?php echo $_h($_ogImageUrl); ?>">
<meta name="twitter:image:width" content="1200">
<meta name="twitter:image:height" content="630">
<?php endif; ?>

<!-- WhatsApp / Telegram / Generic -->
<?php if ($_ogImageUrl): ?>
<link rel="image_src" href="<?php echo $_h($_ogImageUrl); ?>">
<meta itemprop="image" content="<?php echo $_h($_ogImageUrl); ?>">
<?php endif; ?>

<!-- Favicons -->
<?php if (is_file(SITE_ROOT . $_faviconIco)): ?>
<link rel="icon" type="image/x-icon" href="<?php echo $_h($_faviconIco); ?>">
<?php endif; ?>
<?php if (is_file(SITE_ROOT . $_favicon32)): ?>
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo $_h($_favicon32); ?>">
<?php endif; ?>
<?php if (is_file(SITE_ROOT . $_appleTouch)): ?>
<link rel="apple-touch-icon" href="<?php echo $_h($_appleTouch); ?>">
<?php endif; ?>

<!-- Machine-readable AI/LLM briefing (llmstxt.org) — advertised for discovery -->
<?php if (is_file(SITE_ROOT . '/llms.txt')): ?>
<link rel="alternate" type="text/plain" title="llms.txt" href="/llms.txt">
<?php endif; ?>
<?php if (is_file(SITE_ROOT . '/llms-full.txt')): ?>
<link rel="alternate" type="text/plain" title="llms-full.txt" href="/llms-full.txt">
<?php endif; ?>

<!-- Stylesheets -->
<link rel="stylesheet" href="/styles.css?v=<?php echo @filemtime(SITE_ROOT . '/styles.css') ?: time(); ?>">
<?php if (is_file(SITE_ROOT . '/css/custom-fonts.css')): ?>
<link rel="stylesheet" href="/css/custom-fonts.css?v=<?php echo @filemtime(SITE_ROOT . '/css/custom-fonts.css') ?: time(); ?>">
<?php endif; ?>

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
