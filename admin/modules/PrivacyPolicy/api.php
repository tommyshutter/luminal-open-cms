<?php
/**
 * PrivacyPolicy Module — API
 * Actions: load, save, preview_html
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

$user = guard_user();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Admin access required']);
    exit;
}

header('Content-Type: application/json');

function pp_data_path(): string {
    return SITE_ROOT . '/admin/data/PrivacyPolicy/policy.json';
}

function pp_defaults(): array {
    $settings = [];
    $sf = SITE_ROOT . '/admin/data/site-settings.json';
    if (is_file($sf)) $settings = json_decode((string)file_get_contents($sf), true) ?: [];

    return [
        'website_name'          => $settings['site_name'] ?? '',
        'website_url'           => 'https://' . ($_SERVER['HTTP_HOST'] ?? ''),
        'company_name'          => $settings['site_name'] ?? '',
        'contact_email'         => $settings['contact_email'] ?? $settings['email'] ?? '',
        'contact_phone'         => '',
        'company_address'       => '',
        'state_province'        => '',
        'jurisdiction'          => 'United States',
        'effective_date'        => date('F j, Y'),
        'last_updated'          => date('F j, Y'),
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
}

function pp_load(): array {
    $path = pp_data_path();
    if (!is_file($path)) return pp_defaults();
    $saved = json_decode((string)file_get_contents($path), true) ?: [];
    return array_merge(pp_defaults(), $saved);
}

function pp_render_html(array $data): string {
    if (!empty($data['custom_html'])) return $data['custom_html'];

    $html = (string)@file_get_contents(SITE_ROOT . '/admin/modules/PrivacyPolicy/template.html');
    if (!$html) return '<p>Template not found.</p>';

    // Replace simple {{var}} placeholders
    foreach ($data as $k => $v) {
        if (is_string($v) || is_numeric($v)) {
            $html = str_replace('{{' . $k . '}}', htmlspecialchars((string)$v), $html);
        }
    }

    // Handle {{#flag}}...{{/flag}} blocks
    foreach ($data as $k => $v) {
        $pattern = '/\{\{#' . preg_quote($k, '/') . '\}\}(.*?)\{\{\/' . preg_quote($k, '/') . '\}\}/s';
        $show = is_bool($v) ? $v : !empty($v);
        $html = preg_replace($pattern, $show ? '$1' : '', $html);
    }

    return $html;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'load':
        echo json_encode(['ok' => true, 'data' => pp_load()]);
        break;

    case 'save':
        $incoming = json_decode($_POST['data'] ?? '{}', true) ?: [];
        // Sanitize boolean flags
        foreach (['uses_cookies','uses_google_analytics','uses_payment_processing','uses_email_marketing','uses_social_media'] as $flag) {
            if (isset($incoming[$flag])) $incoming[$flag] = (bool)$incoming[$flag];
        }
        $path = pp_data_path();
        @mkdir(dirname($path), 0775, true);
        $incoming['last_updated'] = date('F j, Y');
        file_put_contents($path, json_encode($incoming, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['ok' => true, 'last_updated' => $incoming['last_updated']]);
        break;

    case 'preview_html':
        $data = pp_load();
        echo json_encode(['ok' => true, 'html' => pp_render_html($data)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
