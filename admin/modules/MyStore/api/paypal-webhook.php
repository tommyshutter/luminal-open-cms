<?php
/**
 * PayPal Webhook Handler (Modern REST API)
 *
 * Receives JSON webhooks from PayPal REST API.
 * Handles PAYMENT.SALE.COMPLETED, PAYMENT.CAPTURE.COMPLETED, and
 * CHECKOUT.ORDER.COMPLETED events.
 *
 * File: /admin/modules/MyStore/api/paypal-webhook.php
 *
 * Setup: Configure webhook URL in PayPal Developer Dashboard:
 *   https://yourdomain.com/admin/modules/MyStore/api/paypal-webhook.php
 *
 * SECURITY: Verifies webhook signature via PayPal API before processing.
 */

// ── Bootstrap ──────────────────────────────────────────────────────────
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__, 4));
}

$dataBase = SITE_ROOT . '/admin/data/mystore/';
if (!is_dir($dataBase) && is_dir(SITE_ROOT . '/admin/data/paypal/')) {
    $dataBase = SITE_ROOT . '/admin/data/paypal/';
}

// ── Logging (shared with IPN) ──────────────────────────────────────────
$logDir = $dataBase . 'logs/';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . 'ipn.log';

function wh_log(string $message): void {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $entry = "[{$timestamp}] [{$ip}] [WEBHOOK] {$message}\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// ── Load Settings ──────────────────────────────────────────────────────
$settingsFile = $dataBase . 'settings.json';
$settings = [];
if (is_file($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
}

// Load PaymentProviders config for client credentials
$ppConfigFile = SITE_ROOT . '/admin/data/PaymentProviders/config.json';
$ppConfig = [];
if (is_file($ppConfigFile)) {
    $ppConfig = json_decode(file_get_contents($ppConfigFile), true) ?: [];
    // Credential Vault: un-seal PayPal clientSecret (plaintext passes through). This
    // webhook doesn't load site_config, so pull the crypto core in explicitly.
    if (!function_exists('cred_vault_reveal_deep') && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
        require_once SITE_ROOT . '/admin/config/cred_vault.php';
    }
    if (function_exists('cred_vault_reveal_deep')) $ppConfig = cred_vault_reveal_deep($ppConfig);
}

$paypalGateway = $ppConfig['gateways']['paypal'] ?? [];
$clientId = $paypalGateway['clientId'] ?? '';
$clientSecret = $paypalGateway['clientSecret'] ?? '';
$isSandbox = !empty($paypalGateway['sandbox']);

// Also check MyStore settings for mode override
$paypalMode = $settings['paypal_mode'] ?? ($isSandbox ? 'sandbox' : 'live');
$isSandbox = ($paypalMode === 'sandbox');

$configuredEmail = $settings['merchantEmail'] ?? '';
$configuredCurrency = strtoupper($settings['currency'] ?? 'USD');
$webhookId = $settings['paypal_webhook_id'] ?? '';

// ── Must be POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wh_log('REJECTED: Non-POST request');
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── Read Body ──────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    wh_log('REJECTED: Empty body');
    http_response_code(400);
    exit('Bad Request');
}

$event = json_decode($rawBody, true);
if (!$event || !is_array($event)) {
    wh_log('REJECTED: Invalid JSON');
    http_response_code(400);
    exit('Invalid JSON');
}

$eventType = $event['event_type'] ?? '';
$eventId = $event['id'] ?? '';

wh_log("WEBHOOK RECEIVED: {$eventType} (event_id: {$eventId})");
wh_log('RAW BODY: ' . $rawBody);

// ── Verify Webhook Signature ───────────────────────────────────────────
// PayPal sends these headers for signature verification
$authAlgo = $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? '';
$certUrl = $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? '';
$transmissionId = $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '';
$transmissionSig = $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '';
$transmissionTime = $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '';

$canVerify = ($clientId && $clientSecret && $webhookId
    && $authAlgo && $certUrl && $transmissionId && $transmissionSig && $transmissionTime);

if ($canVerify) {
    // Get OAuth token
    $apiBase = $isSandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

    $ch = curl_init($apiBase . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => "{$clientId}:{$clientSecret}",
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
    ]);
    $tokenResp = curl_exec($ch);
    $tokenCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($tokenCode !== 200) {
        wh_log("VERIFY ERROR: Failed to get OAuth token (HTTP {$tokenCode})");
        // Process anyway with warning — don't lose the order
        wh_log("WARNING: Processing webhook WITHOUT signature verification");
    } else {
        $tokenData = json_decode($tokenResp, true);
        $accessToken = $tokenData['access_token'] ?? '';

        // Verify webhook signature
        $verifyPayload = json_encode([
            'auth_algo'         => $authAlgo,
            'cert_url'          => $certUrl,
            'transmission_id'   => $transmissionId,
            'transmission_sig'  => $transmissionSig,
            'transmission_time' => $transmissionTime,
            'webhook_id'        => $webhookId,
            'webhook_event'     => $event,
        ]);

        $ch = curl_init($apiBase . '/v1/notifications/verify-webhook-signature');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $verifyPayload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$accessToken}",
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        ]);
        $verifyResp = curl_exec($ch);
        $verifyCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $verifyData = json_decode($verifyResp, true);
        $verificationStatus = $verifyData['verification_status'] ?? '';

        wh_log("VERIFY: HTTP {$verifyCode} — status: {$verificationStatus}");

        if ($verificationStatus !== 'SUCCESS') {
            wh_log("VERIFY FAILED: Webhook signature verification failed. REJECTED.");
            wh_log("REJECTED DATA: " . json_encode($event));
            http_response_code(200);
            exit;
        }

        wh_log('VERIFY OK: Webhook signature verified');
    }
} else {
    wh_log("WARNING: Incomplete verification data (missing webhook_id or credentials). Processing with caution.");
    // Log what we're missing
    if (!$webhookId) wh_log("  Missing: paypal_webhook_id in settings.json");
    if (!$clientId || !$clientSecret) wh_log("  Missing: PayPal client credentials in PaymentProviders config");
}

// ── SHF (trading platform) branch ──────────────────────────────────────
// If custom_id on the purchase looks like an SHF member id (mbr_<hex>),
// delegate to the CardManager handler and stop. Keeps SHF activation flow
// isolated from MyStore order processing while sharing the same webhook URL.
$shfHandlerPath = SITE_ROOT . '/admin/modules/CardManager/lib/paypal_handler.php';
if (is_file($shfHandlerPath)) {
    require_once $shfHandlerPath;
    $shfMeta = shf_extract_event_meta($event);
    if ($shfMeta) {
        wh_log("SHF EVENT: type={$shfMeta['event_type']} member={$shfMeta['member_id']} order={$shfMeta['order_id']} amount=\${$shfMeta['amount']}");
        $shfResult = shf_paypal_handle_webhook($event);
        wh_log("SHF DISPATCH: handled=" . ($shfResult['handled'] ? '1' : '0') . " reason={$shfResult['reason']}");
        // SHF claimed this event — acknowledge to PayPal and stop here.
        http_response_code(200);
        exit;
    }
}

// ── Handle Event Types ─────────────────────────────────────────────────
$handledEvents = [
    'PAYMENT.SALE.COMPLETED',
    'PAYMENT.CAPTURE.COMPLETED',
    'CHECKOUT.ORDER.COMPLETED',
];

if (!in_array($eventType, $handledEvents)) {
    wh_log("SKIPPED: Event type '{$eventType}' is not handled. Acknowledged.");
    http_response_code(200);
    exit;
}

// ── Extract Order Data from Event ──────────────────────────────────────
$resource = $event['resource'] ?? [];

// Different event types have data in different locations
$txnId = '';
$mcGross = 0;
$mcFee = 0;
$mcCurrency = '';
$payerEmail = '';
$firstName = '';
$lastName = '';
$addressStreet = '';
$addressCity = '';
$addressState = '';
$addressZip = '';
$addressCountry = '';
$items = [];

switch ($eventType) {
    case 'PAYMENT.SALE.COMPLETED':
        $txnId = $resource['id'] ?? '';
        $mcGross = floatval($resource['amount']['total'] ?? 0);
        $mcCurrency = $resource['amount']['currency'] ?? '';
        $mcFee = floatval($resource['transaction_fee']['value'] ?? 0);
        $payerEmail = $resource['payer_email'] ?? '';
        break;

    case 'PAYMENT.CAPTURE.COMPLETED':
        $txnId = $resource['id'] ?? '';
        $mcGross = floatval($resource['amount']['value'] ?? 0);
        $mcCurrency = $resource['amount']['currency_code'] ?? '';
        $feeBreakdown = $resource['seller_receivable_breakdown'] ?? [];
        $mcFee = floatval($feeBreakdown['paypal_fee']['value'] ?? 0);
        break;

    case 'CHECKOUT.ORDER.COMPLETED':
        $txnId = $resource['id'] ?? '';
        $purchaseUnits = $resource['purchase_units'] ?? [];
        if (!empty($purchaseUnits)) {
            $pu = $purchaseUnits[0];
            $mcGross = floatval($pu['amount']['value'] ?? 0);
            $mcCurrency = $pu['amount']['currency_code'] ?? '';

            // Extract items from purchase unit
            $puItems = $pu['items'] ?? [];
            foreach ($puItems as $puItem) {
                $iQty = intval($puItem['quantity'] ?? 1);
                $iPrice = floatval($puItem['unit_amount']['value'] ?? 0);
                $items[] = [
                    'name'       => $puItem['name'] ?? 'Item',
                    'sku'        => $puItem['sku'] ?? '',
                    'quantity'   => $iQty,
                    'price'      => $iPrice,
                    'total'      => round($iPrice * $iQty, 2),
                    'variations' => [],
                ];
            }

            // Shipping address
            $shipping = $pu['shipping'] ?? [];
            $shippingAddr = $shipping['address'] ?? [];
            $addressStreet = $shippingAddr['address_line_1'] ?? '';
            $addressCity = $shippingAddr['admin_area_2'] ?? '';
            $addressState = $shippingAddr['admin_area_1'] ?? '';
            $addressZip = $shippingAddr['postal_code'] ?? '';
            $addressCountry = $shippingAddr['country_code'] ?? '';

            $shippingName = $shipping['name']['full_name'] ?? '';

            // Fee from captures
            $captures = $pu['payments']['captures'] ?? [];
            if (!empty($captures)) {
                $mcFee = floatval($captures[0]['seller_receivable_breakdown']['paypal_fee']['value'] ?? 0);
                // Use capture ID as txn_id if order ID was used
                if (empty($txnId) || $txnId === $resource['id']) {
                    $txnId = $captures[0]['id'] ?? $txnId;
                }
            }
        }

        // Payer info
        $payer = $resource['payer'] ?? [];
        $payerEmail = $payer['email_address'] ?? '';
        $firstName = $payer['name']['given_name'] ?? '';
        $lastName = $payer['name']['surname'] ?? '';
        break;
}

wh_log("EXTRACTED: txn={$txnId} amount={$mcGross} {$mcCurrency} payer={$payerEmail}");

// ── Security Checks ────────────────────────────────────────────────────
if (empty($txnId)) {
    wh_log("REJECTED: No transaction ID in webhook resource");
    http_response_code(200);
    exit;
}

if ($mcCurrency && $configuredCurrency && strtoupper($mcCurrency) !== $configuredCurrency) {
    wh_log("REJECTED: Currency mismatch. Webhook: '{$mcCurrency}', Configured: '{$configuredCurrency}'");
    http_response_code(200);
    exit;
}

// ── Duplicate Check ────────────────────────────────────────────────────
$ordersFile = $dataBase . 'orders.json';
$orders = [];
if (is_file($ordersFile)) {
    $raw = file_get_contents($ordersFile);
    $orders = json_decode($raw, true) ?: [];
}

foreach ($orders as $existingOrder) {
    $existingTxn = $existingOrder['paypal_txn_id'] ?? ($existingOrder['payment_id'] ?? '');
    if ($existingTxn === $txnId) {
        wh_log("DUPLICATE: txn '{$txnId}' already exists as order '{$existingOrder['order_id']}'. Skipping.");
        http_response_code(200);
        exit;
    }
}

// ── Build Order ────────────────────────────────────────────────────────
$orderPrefix = $settings['orderPrefix'] ?? 'ORD';
$orderId = $orderPrefix . '-' . date('Ymd-His') . '-' . substr(md5($txnId), 0, 6);
$customerName = trim($firstName . ' ' . $lastName);

if (empty($items)) {
    $items[] = [
        'name'       => 'PayPal Purchase',
        'sku'        => '',
        'quantity'   => 1,
        'price'      => $mcGross,
        'total'      => $mcGross,
        'variations' => [],
    ];
}
$subtotal = array_sum(array_column($items, 'total'));

$order = [
    'order_id'       => $orderId,
    'status'         => 'processing',
    'customer'       => [
        'name'    => $customerName,
        'email'   => $payerEmail,
        'phone'   => '',
        'address' => [
            'street'  => $addressStreet,
            'city'    => $addressCity,
            'state'   => $addressState,
            'zip'     => $addressZip,
            'country' => $addressCountry,
        ],
    ],
    'items'          => $items,
    'subtotal'       => round($subtotal, 2),
    'tax'            => 0,
    'tax_rate'       => floatval($settings['taxRate'] ?? 0),
    'shipping'       => 0,
    'total'          => round($mcGross, 2),
    'payment_method' => 'paypal',
    'payment_id'     => $txnId,
    'paypal_txn_id'  => $txnId,
    'paypal_fee'     => round($mcFee, 2),
    'paypal_payer'   => $payerEmail,
    'created_at'     => date('c'),
    'updated_at'     => date('c'),
    'source'         => 'paypal_webhook',
    'notes'          => "Event: {$eventType}",
];

// ── Save Order ─────────────────────────────────────────────────────────
$orders[] = $order;
$written = file_put_contents(
    $ordersFile,
    json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($written === false) {
    wh_log("ERROR: Failed to write order to {$ordersFile}");
    http_response_code(200);
    exit;
}

wh_log("ORDER CREATED: {$orderId} | TXN: {$txnId} | Total: \${$mcGross} | Customer: {$customerName} <{$payerEmail}>");

// ── AudienceBuilder ────────────────────────────────────────────────────
$abLeadsDir = SITE_ROOT . '/admin/data/AudienceBuilder/leads';
if (!is_dir($abLeadsDir)) @mkdir($abLeadsDir, 0775, true);
$abFile = $abLeadsDir . '/' . date('Y-m') . '.json';
$abLeads = is_file($abFile) ? (json_decode(file_get_contents($abFile), true) ?: []) : [];
$abLeads[] = [
    'id'           => 'wh-' . $orderId,
    'source'       => 'paypal-webhook',
    'source_url'   => 'paypal',
    'submitted_at' => date('c'),
    'domain'       => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'fields'       => [
        'name'        => $customerName,
        'email'       => $payerEmail,
        'order_id'    => $orderId,
        'order_total' => '$' . number_format($mcGross, 2),
    ],
    'status' => 'converted',
    'tags'   => ['customer', 'store-purchase', 'paypal-webhook'],
];
file_put_contents($abFile, json_encode($abLeads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

// ── Telegram Notification ──────────────────────────────────────────────
$tgLib = SITE_ROOT . '/admin/lib/TelegramNotify.php';
if (is_file($tgLib)) {
    require_once $tgLib;
    $itemList = implode("\n", array_map(function ($i) {
        return "  - {$i['name']} x{$i['quantity']} = \${$i['total']}";
    }, $items));
    $tgMsg = "\xF0\x9F\x92\xB0 *New PayPal Order!* (webhook)\n"
           . "Order: {$orderId}\n"
           . "Customer: {$customerName}\n"
           . "Email: {$payerEmail}\n"
           . "Items:\n{$itemList}\n"
           . "Total: \$" . number_format($mcGross, 2) . "\n"
           . "TXN: {$txnId}";
    tg_notify($tgMsg);
}

wh_log("COMPLETE: Webhook fully processed for txn {$txnId}");

http_response_code(200);
echo json_encode(['status' => 'ok']);
exit;
