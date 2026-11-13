<?php
/**
 * MailgunManager — API Endpoint
 *
 * Actions: load, save, test, send_test, check_dns
 *
 * @file /admin/modules/MailgunManager/api.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
require_once SITE_ROOT . '/admin/modules/_runtime/api_helpers.php';
require_once __DIR__ . '/MailgunManagerFunctions.php';

guard_require_auth();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ---- Actions ---- */

switch ($action) {

    case 'load':
        $cfg = mgm_load_config();
        // Mask the API key for display
        $masked = $cfg['api_key'];
        if (strlen($masked) > 8) {
            $masked = substr($masked, 0, 4) . str_repeat('*', strlen($masked) - 8) . substr($masked, -4);
        }
        ok([
            'config' => array_merge($cfg, ['api_key_masked' => $masked]),
        ]);
        break;

    case 'save':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            bad('Invalid input', 400);
        }

        $cfg = mgm_load_config();
        if (isset($input['api_key']) && $input['api_key'] !== '')   $cfg['api_key']    = trim($input['api_key']);
        if (isset($input['domain']))                                 $cfg['domain']     = trim($input['domain']);
        if (isset($input['from_name']))                              $cfg['from_name']  = trim($input['from_name']);
        if (isset($input['from_email']))                             $cfg['from_email'] = trim($input['from_email']);
        if (isset($input['region']) && in_array($input['region'], ['us', 'eu'], true)) {
            $cfg['region'] = $input['region'];
        }

        mgm_save_config($cfg);
        ok(['message' => 'Configuration saved']);
        break;

    case 'test':
        $cfg = mgm_load_config();
        if (empty($cfg['api_key']) || empty($cfg['domain'])) {
            bad('API key and domain are required');
        }

        $base = mgm_api_base($cfg['region']);
        $result = mgm_request("$base/domains/{$cfg['domain']}", $cfg['api_key']);

        if ($result['ok']) {
            $domainData = $result['data']['domain'] ?? $result['data'] ?? [];
            ok([
                'domain' => $domainData['name'] ?? $cfg['domain'],
                'state'  => $domainData['state'] ?? 'unknown',
                'type'   => $domainData['type'] ?? 'unknown',
            ]);
        } else {
            $errMsg = $result['data']['message'] ?? $result['error'] ?? 'Connection failed';
            bad($errMsg, $result['http_code'] ?? 500);
        }
        break;

    case 'send_test':
        $input = json_decode(file_get_contents('php://input'), true);
        $to = trim($input['to'] ?? '');

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            bad('Valid recipient email required', 400);
        }

        $cfg = mgm_load_config();
        if (empty($cfg['api_key']) || empty($cfg['domain'])) {
            bad('Save credentials first');
        }

        $base = mgm_api_base($cfg['region']);
        $from = ($cfg['from_name'] ? "{$cfg['from_name']} <{$cfg['from_email']}>" : $cfg['from_email']) ?: "test@{$cfg['domain']}";
        $host = $_SERVER['HTTP_HOST'] ?? 'unknown';

        $result = mgm_request("$base/{$cfg['domain']}/messages", $cfg['api_key'], 'POST', [
            'from'    => $from,
            'to'      => $to,
            'subject' => "Mailgun Test — $host",
            'html'    => "<h2>Mailgun is working!</h2><p>This test email was sent from <strong>$host</strong> via the Mailgun Manager.</p><p style=\"color:#888;font-size:12px;\">Sent at: " . date('Y-m-d H:i:s T') . "</p>",
            'text'    => "Mailgun is working! This test email was sent from $host via the Mailgun Manager. Sent at: " . date('Y-m-d H:i:s T'),
        ]);

        if ($result['ok']) {
            ok([
                'message' => "Test email sent to $to",
                'id'      => $result['data']['id'] ?? '',
            ]);
        } else {
            $errMsg = $result['data']['message'] ?? $result['error'] ?? 'Send failed';
            bad($errMsg);
        }
        break;

    case 'check_dns':
        $cfg = mgm_load_config();
        if (empty($cfg['api_key']) || empty($cfg['domain'])) {
            bad('Save credentials first');
        }

        $base = mgm_api_base($cfg['region']);
        // PUT /domains/{domain}/verify forces Mailgun to re-check DNS (not just read cache)
        $result = mgm_request("$base/domains/{$cfg['domain']}/verify", $cfg['api_key'], 'PUT');

        if (!$result['ok']) {
            bad($result['data']['message'] ?? 'API error');
        }

        $sending = $result['data']['sending_dns_records'] ?? [];
        $receiving = $result['data']['receiving_dns_records'] ?? [];
        $domainInfo = $result['data']['domain'] ?? [];

        ok([
            'domain'    => $domainInfo['name'] ?? $cfg['domain'],
            'state'     => $domainInfo['state'] ?? 'unknown',
            'sending'   => $sending,
            'receiving' => $receiving,
        ]);
        break;

    default:
        bad("Unknown action: $action", 400);
}
