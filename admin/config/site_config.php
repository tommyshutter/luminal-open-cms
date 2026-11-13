<?php
/**
 * Luminal CMS — Site Config Adapter
 *
 * Provides thoht.io AdminPanel-compatible configuration API backed by
 * Luminal's existing JSON data files. Modules that call sc_get(), sc_set(),
 * and sc_asset() work identically in both systems.
 *
 */
declare(strict_types=1);

if (defined('SITE_CONFIG_LOADED')) {
    return;
}
define('SITE_CONFIG_LOADED', true);

// ============================================================================
// DOMAIN AUTO-DETECTION (matches thoht.io exactly)
// ============================================================================

function _sc_detect_domain(): string {
    // Try filesystem path first
    $path = __DIR__;
    if (preg_match('#/var/www/vhosts/([^/]+)#', $path, $m)) {
        return $m[1];
    }
    // Fallback: HTTP_HOST
    if (!empty($_SERVER['HTTP_HOST'])) {
        return preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']);
    }
    return gethostname() ?: 'localhost';
}

function _sc_derive_prefix(string $domain): string {
    $parts = explode('.', $domain);
    $prefix = preg_replace('/[^a-z0-9]/', '', strtolower($parts[0]));
    return $prefix ?: 'site';
}

// ============================================================================
// ENSURE LUMINAL CORE IS LOADED
// ============================================================================

// SITE_ROOT may already be defined by utils.php; if not, define it now
if (!defined('SITE_ROOT')) {
    $siteRoot = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    define('SITE_ROOT', $siteRoot);
}

// Load utils.php for loadJsonData() if not already loaded
$_utilsFile = SITE_ROOT . '/utils.php';
if (function_exists('loadJsonData') === false && is_file($_utilsFile)) {
    require_once $_utilsFile;
}

// Credential Vault crypto core — makes cred_vault_reveal()/seal() globally available so
// modules can un-seal API keys at rest. Passthrough on plaintext = zero-risk until sealed.
$_credVault = __DIR__ . '/cred_vault.php';
if (!function_exists('cred_vault_reveal') && is_file($_credVault)) {
    require_once $_credVault;
}

// ============================================================================
// CONFIG LOADER — Bridge Luminal JSON to thoht.io structure
// ============================================================================

function sc_load_config(): array {
    $domain   = _sc_detect_domain();
    $prefix   = _sc_derive_prefix($domain);
    $siteRoot = SITE_ROOT;
    $dataDir  = $siteRoot . '/admin/data';

    // Load Luminal's settings files
    $ss = []; // site-settings.json
    $ms = []; // meta_settings.json

    $ssFile = $dataDir . '/site-settings.json';
    if (is_file($ssFile)) {
        $ss = json_decode(file_get_contents($ssFile), true) ?: [];
    }
    $msFile = $dataDir . '/meta_settings.json';
    if (is_file($msFile)) {
        $ms = json_decode(file_get_contents($msFile), true) ?: [];
    }

    // Build thoht.io-compatible config structure
    return [
        '_meta' => [
            'version' => '1.0.0',
            'adapter' => 'luminal',
            'description' => 'Luminal CMS config bridge — reads from admin/data/ JSON files'
        ],
        'site' => [
            'prefix'     => $prefix,
            'domain'     => $domain,
            'name'       => $ss['site_name'] ?? $ms['site_name'] ?? ucwords(str_replace(['.', '-', '_'], ' ', explode('.', $domain)[0])),
            'title'      => $ss['site_title'] ?? $ms['site_title'] ?? ucwords(str_replace(['.', '-', '_'], ' ', explode('.', $domain)[0])),
            'url'        => 'https://' . $domain,
            'adminEmail' => $ss['contact_email'] ?? 'admin@' . $domain,
        ],
        'paths' => [
            'vhostsBase' => '/var/www/vhosts',
            'siteRoot'   => $siteRoot,
            'adminDir'   => $siteRoot . '/admin',
            'logFile'    => '/var/log/vhosts/' . $domain . '/' . $domain . '.site.log',
        ],
        'mailgun' => [
            'apiKey' => $ss['mailgun_api_key'] ?? '',
            'domain' => $ss['mailgun_domain'] ?? '',
            'region' => $ss['mailgun_region'] ?? 'US',
            'from'   => $ss['mailgun_from'] ?? '',
        ],
        'social' => [
            'twitter'  => $ss['twitter_url'] ?? '',
            'youtube'  => $ss['youtube_url'] ?? '',
            'facebook' => $ss['facebook_url'] ?? '',
            'linkedin' => $ss['linkedin_url'] ?? '',
        ],
        'seo' => [
            'keywords'        => $ms['meta_keywords'] ?? $ss['meta_keywords'] ?? '',
            'meta_description' => $ms['meta_description'] ?? $ss['meta_description'] ?? '',
        ],
        'display' => [
            'timezone'       => $ss['timezone'] ?? 'America/New_York',
            'dateFormat'     => 'Y-m-d',
            'timeFormat'     => 'H:i:s',
            'datetimeFormat' => 'Y-m-d H:i:s',
        ],
    ];
}

// ============================================================================
// DOT-NOTATION ACCESSORS (matches thoht.io API exactly)
// ============================================================================

function sc_get(string $key, $default = null) {
    static $config = null;
    if ($config === null) {
        $config = sc_load_config();
    }

    if (strpos($key, '.') === false) {
        return $config[$key] ?? $default;
    }

    $parts = explode('.', $key);
    $value = $config;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function sc_set(string $key, $value): bool {
    // In Luminal, writes go back to site-settings.json for site.* keys
    // This is a simplified adapter — full write-back would map each key
    // to its source file. For now, log a warning.
    error_log("sc_set() called with key=$key — Luminal adapter does not support write-back yet");
    return false;
}

function sc_save_config(array $config): bool {
    error_log("sc_save_config() called — Luminal adapter does not support full config save");
    return false;
}

// ============================================================================
// ASSET HELPER (ported directly from thoht.io)
// ============================================================================

function sc_asset(string $webPath, bool $bust = true): string {
    $docRoot = SITE_ROOT;
    $fsPath  = $docRoot . $webPath;

    // Try minified version
    if (preg_match('/\.(js|css)$/', $webPath, $m)) {
        $ext    = $m[1];
        $minWeb = preg_replace('/\.' . $ext . '$/', '.min.' . $ext, $webPath);
        $minFs  = $docRoot . $minWeb;
        if (is_file($minFs)) {
            $webPath = $minWeb;
            $fsPath  = $minFs;
        }
    }

    if ($bust && is_file($fsPath)) {
        $webPath .= '?v=' . filemtime($fsPath);
    }

    return $webPath;
}

// ============================================================================
// DATE/TIME HELPER (ported from thoht.io)
// ============================================================================

function sc_format_datetime($time = null, string $format = 'datetime'): string {
    if ($time === null) {
        $ts = time();
    } elseif ($time instanceof DateTime) {
        $ts = $time->getTimestamp();
    } elseif (is_numeric($time)) {
        $ts = (int)$time;
    } else {
        $ts = strtotime($time) ?: time();
    }

    $display = sc_get('display', []);
    switch ($format) {
        case 'date':     return date($display['dateFormat'] ?? 'Y-m-d', $ts);
        case 'time':     return date($display['timeFormat'] ?? 'H:i:s', $ts);
        case 'datetime': return date($display['datetimeFormat'] ?? 'Y-m-d H:i:s', $ts);
        default:         return date($format, $ts);
    }
}

// ============================================================================
// LOAD CONFIG & DEFINE CONSTANTS
// ============================================================================

$_SITE_CONFIG = sc_load_config();

// Site identity constants (if not already defined)
if (!defined('SITE_PREFIX'))      define('SITE_PREFIX',      $_SITE_CONFIG['site']['prefix']);
if (!defined('SITE_DOMAIN_NAME')) define('SITE_DOMAIN_NAME', $_SITE_CONFIG['site']['domain']);
if (!defined('SITE_NAME'))        define('SITE_NAME',        $_SITE_CONFIG['site']['name']);
if (!defined('SITE_TITLE'))       define('SITE_TITLE',       $_SITE_CONFIG['site']['title']);
if (!defined('SITE_URL'))         define('SITE_URL',         $_SITE_CONFIG['site']['url']);
if (!defined('ADMIN_EMAIL'))      define('ADMIN_EMAIL',      $_SITE_CONFIG['site']['adminEmail']);
if (!defined('ADMIN_DIR'))        define('ADMIN_DIR',        $_SITE_CONFIG['paths']['adminDir']);

// Backwards-compat globals (used by some templates)
$domain_title        = SITE_TITLE;
$domain_name         = SITE_DOMAIN_NAME;
$domain_prefix       = SITE_PREFIX;
$domain_author_email = ADMIN_EMAIL;
