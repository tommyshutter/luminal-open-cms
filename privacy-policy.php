<?php
/**
 * Privacy Policy — Public Page
 * Served by PrivacyPolicy module. Editable in Admin → Privacy Policy.
 */

$SITE_ROOT = realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'];
$data_file  = $SITE_ROOT . '/admin/data/PrivacyPolicy/policy.json';
$tpl_file   = $SITE_ROOT . '/admin/modules/PrivacyPolicy/template.html';

// Load policy data
$defaults = [
    'website_name'          => '',
    'website_url'           => 'https://' . ($_SERVER['HTTP_HOST'] ?? ''),
    'company_name'          => '',
    'contact_email'         => '',
    'contact_phone'         => '',
    'company_address'       => '',
    'state_province'        => '',
    'jurisdiction'          => 'United States',
    'effective_date'        => 'January 1, 2025',
    'last_updated'          => 'January 1, 2025',
    'data_retention_period' => '2 years',
    'minimum_age'           => '13',
    'services_description'  => 'website and related services',
    'uses_cookies'          => true,
    'uses_google_analytics' => false,
    'uses_payment_processing' => false,
    'uses_email_marketing'  => false,
    'uses_social_media'     => false,
    'custom_html'           => '',
];

if (is_file($data_file)) {
    $saved = json_decode((string)file_get_contents($data_file), true) ?: [];
    $data = array_merge($defaults, $saved);
} else {
    // Fallback: try site-settings for name
    $sf = $SITE_ROOT . '/admin/data/site-settings.json';
    if (is_file($sf)) {
        $ss = json_decode((string)file_get_contents($sf), true) ?: [];
        $defaults['website_name'] = $ss['site_name'] ?? '';
        $defaults['company_name'] = $ss['site_name'] ?? '';
        $defaults['contact_email'] = $ss['contact_email'] ?? $ss['email'] ?? '';
    }
    $data = $defaults;
}

// Render content
function pp_render(array $data, string $tpl_file): string {
    if (!empty($data['custom_html'])) return $data['custom_html'];
    if (!is_file($tpl_file)) return '<p>Policy template not found.</p>';

    $html = (string)file_get_contents($tpl_file);

    foreach ($data as $k => $v) {
        if (is_string($v) || is_numeric($v)) {
            $html = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v), $html);
        }
    }
    foreach ($data as $k => $v) {
        $pattern = '/\{\{#' . preg_quote($k, '/') . '\}\}(.*?)\{\{\/' . preg_quote($k, '/') . '\}\}/s';
        $show = is_bool($v) ? $v : !empty($v);
        $html = preg_replace($pattern, $show ? '$1' : '', $html);
    }
    return $html;
}

$content = pp_render($data, $tpl_file);
$site_name = htmlspecialchars($data['website_name'] ?: ($_SERVER['HTTP_HOST'] ?? 'This Site'));

// Try to load site CSS for on-brand rendering
$has_cms = is_file($SITE_ROOT . '/admin/config/site_config.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — <?= $site_name ?></title>
    <meta name="robots" content="noindex">
    <?php if ($has_cms && is_file($SITE_ROOT . '/css/site.css')): ?>
    <link rel="stylesheet" href="/css/site.css">
    <?php endif; ?>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f8f8f8; color: #222; margin: 0; padding: 0; }
        .pp-outer { max-width: 760px; margin: 0 auto; padding: 40px 24px 80px; }
        .pp-back { display: inline-flex; align-items: center; gap: 6px; color: #555; text-decoration: none; font-size: 0.82rem; margin-bottom: 32px; }
        .pp-back:hover { color: #000; }
        .pp-body h1 { font-size: 1.6rem; margin-bottom: 8px; }
        .pp-body h2 { font-size: 1.1rem; margin: 28px 0 10px; border-bottom: 1px solid #e0e0e0; padding-bottom: 6px; }
        .pp-body h3 { font-size: 0.95rem; margin: 20px 0 8px; color: #444; }
        .pp-body p, .pp-body li { font-size: 0.9rem; line-height: 1.7; color: #333; }
        .pp-body ul { padding-left: 20px; margin: 8px 0; }
        .pp-body a { color: #2563eb; }
        .pp-body em { color: #666; }
        .pp-footer { margin-top: 48px; padding-top: 16px; border-top: 1px solid #ddd; font-size: 0.75rem; color: #999; text-align: center; }
        .pp-footer a { color: #999; }
    </style>
</head>
<body>
<div class="pp-outer">
    <a href="/" class="pp-back">&#x2190; Back to <?= $site_name ?></a>
    <div class="pp-body">
        <?= $content ?>
    </div>
    <div class="pp-footer">
        &copy; <?= date('Y') ?> <?= $site_name ?> &middot;
        <a href="/">Home</a>
    </div>
</div>
</body>
</html>
