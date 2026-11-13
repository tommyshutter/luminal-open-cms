<?php
/**
 * PaymentProviders API
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

require_once __DIR__ . '/providers/GatewayRegistry.php';

header('Content-Type: application/json');

$registry = new GatewayRegistry(SITE_ROOT . '/admin/data');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function pp_response(bool $ok, $data = null, string $msg = '', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'data' => $data, 'message' => $msg]);
    exit;
}

switch ($action) {
    case 'get_state':
        pp_response(true, [
            'gateways' => $registry->listGateways(),
            'defaultGateway' => $registry->getDefaultGateway(),
            'currency' => $registry->getCurrency(),
        ]);
        break;

    case 'update_gateway':
        $id = $_POST['gateway_id'] ?? '';
        $data = json_decode($_POST['config'] ?? '{}', true) ?: [];
        if ($registry->updateGateway($id, $data)) {
            pp_response(true, ['gateways' => $registry->listGateways()], 'Gateway updated');
        } else {
            pp_response(false, null, 'Failed to update gateway', 400);
        }
        break;

    case 'enable_gateway':
        $id = $_POST['gateway_id'] ?? '';
        $enable = ($_POST['enabled'] ?? 'true') === 'true';
        if ($registry->enableGateway($id, $enable)) {
            pp_response(true, ['gateways' => $registry->listGateways()], ($enable ? 'Enabled' : 'Disabled'));
        } else {
            pp_response(false, null, 'Failed', 400);
        }
        break;

    case 'set_default':
        $id = $_POST['gateway_id'] ?? '';
        if ($registry->setDefault($id)) {
            pp_response(true, ['gateways' => $registry->listGateways(), 'defaultGateway' => $id], 'Default gateway set');
        } else {
            pp_response(false, null, 'Failed to set default', 400);
        }
        break;

    case 'set_currency':
        $currency = $_POST['currency'] ?? 'USD';
        $registry->setCurrency($currency);
        pp_response(true, null, 'Currency updated');
        break;

    case 'test_connection':
        $id = $_POST['gateway_id'] ?? '';
        $result = $registry->testConnection($id);
        pp_response($result['ok'], ['gateways' => $registry->listGateways()], $result['message']);
        break;

    default:
        pp_response(false, null, 'Unknown action: ' . $action, 400);
}
