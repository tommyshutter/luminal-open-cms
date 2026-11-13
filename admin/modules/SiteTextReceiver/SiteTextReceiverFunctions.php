<?php
/**
 * SiteTextReceiver — Shortcode Renderer
 *
 * Usage in page content:
 *   [[site-text:this-week]]
 *   [[site-text key="this-week"]]
 *   [[site-text key="this-week" class="custom-class"]]
 *
 * Loads the slot JSON from admin/data/site-text/{key}.json
 * and returns the HTML content wrapped in a styled div.
 *
 * @package  LuminalCMS
 * @module   SiteTextReceiver
 * @version  1.0.0
 */
declare(strict_types=1);

/**
 * Render a site-text shortcode slot.
 *
 * @param array $attrs Shortcode attributes. Expects 'key' (required), optional 'class'.
 * @return string Rendered HTML or empty div with comment if slot missing.
 */
function render_site_text(array $attrs): string {
    $key = $attrs['key'] ?? ($attrs[0] ?? '');
    $key = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower(trim($key)));

    if (empty($key)) {
        return '<!-- site-text: no key specified -->';
    }

    // Determine SITE_ROOT
    if (!defined('SITE_ROOT')) {
        define('SITE_ROOT', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
    }

    $file = SITE_ROOT . '/admin/data/site-text/' . $key . '.json';
    if (!is_file($file)) {
        return '<div class="site-text-slot" data-key="' . htmlspecialchars($key) . '"><!-- site-text slot "' . htmlspecialchars($key) . '" not found --></div>';
    }

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || empty($data['html'])) {
        return '<div class="site-text-slot" data-key="' . htmlspecialchars($key) . '"><!-- site-text slot "' . htmlspecialchars($key) . '" is empty --></div>';
    }

    $cssClass = 'site-text-slot';
    if (!empty($attrs['class'])) {
        $cssClass .= ' ' . htmlspecialchars($attrs['class']);
    }
    if (!empty($data['css_class'])) {
        $cssClass .= ' ' . htmlspecialchars($data['css_class']);
    }

    return '<div class="' . $cssClass . '" data-key="' . htmlspecialchars($key) . '">' . $data['html'] . '</div>';
}
