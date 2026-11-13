<?php
/**
 * AI Page Maker - Page Renderer
 * Renders AIP pages when embedded via shortcode [[aip:slug]]
 * @file /includes/renderers/aip_page.renderer.php
 */

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__, 5));
}

if (!function_exists('render_aip_page')) {
    /**
     * Render an AIP page by slug
     * @param string $slug The AIP page slug
     * @return string Rendered HTML
     */
    function render_aip_page(string $slug): string {
        if (empty($slug)) {
            return '<!-- AIP: no slug provided -->';
        }

        $aipDir = SITE_ROOT . '/admin/data/AIP_Pages/' . $slug;
        $indexFile = $aipDir . '/index.html';
        $metaFile = $aipDir . '/meta.json';

        // Check if page exists
        if (!is_dir($aipDir) || !is_file($indexFile)) {
            return '<!-- AIP page not found: ' . htmlspecialchars($slug) . ' -->';
        }

        // Load metadata
        $meta = [];
        if (is_file($metaFile)) {
            $metaContent = @file_get_contents($metaFile);
            if ($metaContent) {
                $meta = json_decode($metaContent, true) ?: [];
            }
        }

        // Load HTML content
        $html = @file_get_contents($indexFile);
        if ($html === false) {
            return '<!-- AIP: failed to read page content -->';
        }

        // Extract body content (strip <html>, <head>, <body> tags if present)
        $html = preg_replace('~<\?xml[^>]*>\s*~i', '', $html);
        $html = preg_replace('~<!DOCTYPE[^>]*>\s*~i', '', $html);

        if (preg_match('~<body[^>]*>(.*?)</body>~is', $html, $m)) {
            $html = $m[1];
        } elseif (preg_match('~<html[^>]*>(.*?)</html>~is', $html, $m)) {
            $html = $m[1];
            // Remove head section if present
            $html = preg_replace('~<head[^>]*>.*?</head>~is', '', $html);
        }

        // Build social share URLs
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $siteUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $pageUrl = $siteUrl . $requestUri;

        // Remove query params for cleaner share URLs
        $pageUrl = strtok($pageUrl, '?');

        $shareTitle = !empty($meta['title']) ? $meta['title'] : 'Check out this page';
        $shareDesc = !empty($meta['description']) ? $meta['description'] : $shareTitle;

        // Facebook share
        $fbShareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($pageUrl);

        // Twitter share
        $twitterText = $shareTitle;
        if (strlen($twitterText) > 240) {
            $twitterText = substr($twitterText, 0, 237) . '...';
        }
        $twitterShareUrl = 'https://twitter.com/intent/tweet?url=' . urlencode($pageUrl) . '&text=' . urlencode($twitterText);

        // Email share
        $emailSubject = rawurlencode('Check out: ' . $shareTitle);
        $emailBody = rawurlencode("I wanted to share this page with you:\n\n" . $shareTitle . "\n\n" . $shareDesc . "\n\n" . $pageUrl);
        $emailShareUrl = 'mailto:?subject=' . $emailSubject . '&body=' . $emailBody;

        // Wrap in AIP container
        $title = !empty($meta['title']) ? htmlspecialchars($meta['title']) : '';
        $generatedDate = !empty($meta['generated_at']) ? date('M j, Y', strtotime($meta['generated_at'])) : '';

        $output = '<div class="aip-embedded-page" data-slug="' . htmlspecialchars($slug) . '">';

        if ($title || $generatedDate) {
            $output .= '<div class="aip-page-header">';
            if ($title) {
                $output .= '<h2 class="aip-page-title">' . $title . '</h2>';
            }
            if ($generatedDate) {
                $output .= '<div class="aip-page-meta"><i class="fa-solid fa-wand-magic-sparkles"></i> AI-generated on ' . $generatedDate . '</div>';
            }
            // Add share buttons
            $output .= '<div class="aip-share-buttons">';
            $output .= '<span class="aip-share-label">Share:</span>';
            $output .= '<a href="' . htmlspecialchars($fbShareUrl) . '" target="_blank" class="aip-share-btn facebook" title="Share on Facebook"><i class="fa-brands fa-facebook"></i></a>';
            $output .= '<a href="' . htmlspecialchars($twitterShareUrl) . '" target="_blank" class="aip-share-btn twitter" title="Share on Twitter/X"><i class="fa-brands fa-x-twitter"></i></a>';
            $output .= '<a href="' . htmlspecialchars($emailShareUrl) . '" class="aip-share-btn email" title="Share via Email"><i class="fa-solid fa-envelope"></i></a>';
            $output .= '</div>';
            $output .= '</div>';
        }

        $output .= '<div class="aip-page-content">' . $html . '</div>';
        $output .= '</div>';

        // Prepend embed CSS (idempotent; emitted only when an AIP page renders)
        return aip_embed_styles() . $output;
    }
}

// Auto-output CSS for embedded AIP pages (non-WordPress)
if (!function_exists('aip_embed_styles')) {
    function aip_embed_styles() {
        static $loaded = false;
        if ($loaded) return '';
        $loaded = true;

        return '<style>
.aip-embedded-page {
    margin: 2rem 0;
    padding: 2rem;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.aip-page-header {
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.aip-page-title {
    margin: 0 0 0.5rem;
    font-size: 1.8rem;
    font-weight: 600;
}

.aip-page-meta {
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.6);
    font-style: italic;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 1rem;
}

.aip-page-meta i {
    opacity: 0.7;
}

.aip-share-buttons {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 1rem;
}

.aip-share-label {
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.7);
    font-weight: 500;
}

.aip-share-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: rgba(255, 255, 255, 0.9);
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 16px;
}

.aip-share-btn:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.15);
    border-color: rgba(255, 255, 255, 0.3);
}

.aip-share-btn.facebook {
    background: rgba(24, 119, 242, 0.2);
    border-color: rgba(24, 119, 242, 0.4);
    color: #1877F2;
}

.aip-share-btn.facebook:hover {
    background: rgba(24, 119, 242, 0.3);
    color: #fff;
}

.aip-share-btn.twitter {
    background: rgba(29, 161, 242, 0.2);
    border-color: rgba(29, 161, 242, 0.4);
    color: #1DA1F2;
}

.aip-share-btn.twitter:hover {
    background: rgba(29, 161, 242, 0.3);
    color: #fff;
}

.aip-share-btn.email {
    background: rgba(220, 53, 69, 0.2);
    border-color: rgba(220, 53, 69, 0.4);
    color: #DC3545;
}

.aip-share-btn.email:hover {
    background: rgba(220, 53, 69, 0.3);
    color: #fff;
}

.aip-page-content {
    line-height: 1.7;
}

.aip-page-content h1,
.aip-page-content h2,
.aip-page-content h3 {
    margin-top: 1.5rem;
    margin-bottom: 1rem;
}

.aip-page-content p {
    margin-bottom: 1rem;
}

.aip-page-content ul,
.aip-page-content ol {
    margin-bottom: 1rem;
    padding-left: 2rem;
}

.aip-page-content img {
    max-width: 100%;
    height: auto;
    border-radius: 4px;
    margin: 1rem 0;
}
</style>';
    }
}
