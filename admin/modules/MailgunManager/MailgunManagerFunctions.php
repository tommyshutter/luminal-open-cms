<?php
/**
 * MailgunManager — Helper Functions
 *
 * @file /admin/modules/MailgunManager/MailgunManagerFunctions.php
 */
declare(strict_types=1);

/**
 * Load Mailgun configuration from data file
 *
 * @return array Configuration array with defaults
 */
function mgm_data_dir(): string {
    $dir = SITE_ROOT . '/admin/data/MailgunManager';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function mgm_load_config(): array {
    $configFile = mgm_data_dir() . '/mailgun-config.json';
    $defaults = [
        'api_key'    => '',
        'domain'     => '',
        'from_name'  => '',
        'from_email' => '',
        'region'     => 'us',
    ];

    if (!is_file($configFile)) {
        return $defaults;
    }

    $data = function_exists('cred_load_json') ? cred_load_json($configFile) : json_decode(file_get_contents($configFile), true);
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

/**
 * Save Mailgun configuration to data file
 *
 * @param array $cfg Configuration array
 * @return void
 */
function mgm_save_config(array $cfg): void {
    $configFile = mgm_data_dir() . '/mailgun-config.json';
    $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    file_put_contents($configFile, $json);
    @chmod($configFile, 0664);
    @chown($configFile, 'www-data');
    @chgrp($configFile, 'www-data');
}

/**
 * Get Mailgun API base URL for region
 *
 * @param string $region Region code ('us' or 'eu')
 * @return string API base URL
 */
function mgm_api_base(string $region): string {
    return $region === 'eu' ? 'https://api.eu.mailgun.net/v3' : 'https://api.mailgun.net/v3';
}

/**
 * Make HTTP request to Mailgun API
 *
 * @param string $url Full API endpoint URL
 * @param string $apiKey Mailgun API key
 * @param string $method HTTP method (GET, POST, PUT)
 * @param array $postFields POST fields for POST requests
 * @return array Response with keys: ok, http_code, data, raw
 */
function mgm_request(string $url, string $apiKey, string $method = 'GET', array $postFields = []): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERPWD        => "api:$apiKey",
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    }

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => "cURL error: $err"];
    }

    $data = json_decode($resp, true);
    return [
        'ok'        => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'data'      => $data,
        'raw'       => $resp
    ];
}
