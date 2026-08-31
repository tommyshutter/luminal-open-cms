<?php
/**
 * PayPal IPN (Instant Payment Notification) Listener
 *
 * Receives POST from PayPal when a payment event occurs.
 * Verifies the notification, then creates/updates orders.
 *
 * File: /admin/modules/MyStore/api/paypal-ipn.php
 *
 * SECURITY: This endpoint is called by PayPal servers directly.
 * No session, no CSRF — authentication is via IPN verification handshake.
 */

// No session needed — PayPal server-to-server call
// Must return HTTP 200 quickly or PayPal will retry

// ── Bootstrap ──────────────────────────────────────────────────────────
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__, 4));
}

$dataBase = SITE_ROOT . '/admin/data/mystore/';
if (!is_dir($dataBase) && is_dir(SITE_ROOT . '/admin/data/paypal/')) {
    $dataBase = SITE_ROOT . '/admin/data/paypal/';
}

// ── Logging ────────────────────────────────────────────────────────────
$logDir = $dataBase . 'logs/';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . 'ipn.log';

function ipn_log(string $message): void {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $entry = "[{$timestamp}] [{$ip}] {$message}\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// ── Load Settings ──────────────────────────────────────────────────────
$settingsFile = $dataBase . 'settings.json';
$settings = [];
if (is_file($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
}

$configuredEmail = $settings['merchantEmail'] ?? '';
$configuredCurrency = strtoupper($settings['currency'] ?? 'USD');
$paypalMode = $settings['paypal_mode'] ?? 'live';

// ── Receive IPN ────────────────────────────────────────────────────────
// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ipn_log('REJECTED: Non-POST request method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown'));
    http_response_code(405);
    exit('Method Not Allowed');
}

// Read raw POST data
$rawPost = file_get_contents('php://input');
if (empty($rawPost)) {
    ipn_log('REJECTED: Empty POST body');
    http_response_code(400);
    exit('Bad Request');
}

ipn_log('IPN RECEIVED: ' . strlen($rawPost) . ' bytes');
ipn_log('RAW DATA: ' . $rawPost);

// Parse POST data
parse_str($rawPost, $ipnData);

// ── Verify IPN with PayPal ─────────────────────────────────────────────
// Prepend cmd=_notify-validate and post back to PayPal
$verifyPayload = 'cmd=_notify-validate&' . $rawPost;

$verifyUrl = ($paypalMode === 'sandbox')
    ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr'
    : 'https://ipnpb.paypal.com/cgi-bin/webscr';

ipn_log("VERIFY: Posting to {$verifyUrl}");

$ch = curl_init($verifyUrl);
curl_setopt_array($ch, [
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $verifyPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => [
        'Connection: close',
        'User-Agent: LuminalCMS-IPN-Verifier/1.0',
    ],
    // PayPal requires TLS 1.2+
    CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
]);

$verifyResponse = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($curlError) {
    ipn_log("VERIFY ERROR: cURL error: {$curlError}");
    // Return 200 so PayPal doesn't retry immediately, but don't process
    http_response_code(200);
    exit;
}

ipn_log("VERIFY RESPONSE: HTTP {$httpCode} — '{$verifyResponse}'");

$verifyResponse = trim($verifyResponse);

if ($verifyResponse !== 'VERIFIED') {
    ipn_log("VERIFY FAILED: Response was '{$verifyResponse}' (expected VERIFIED). IPN REJECTED.");
    // Log full IPN data for forensic review
    ipn_log('REJECTED IPN DATA: ' . json_encode($ipnData));
    http_response_code(200); // Still return 200 per PayPal docs
    exit;
}

ipn_log('VERIFY OK: IPN verified by PayPal');

// ── Extract IPN Fields ─────────────────────────────────────────────────
$txnId         = $ipnData['txn_id'] ?? '';
$paymentStatus = $ipnData['payment_status'] ?? '';
$payerEmail    = $ipnData['payer_email'] ?? '';
$firstName     = $ipnData['first_name'] ?? '';
$lastName      = $ipnData['last_name'] ?? '';
$receiverEmail = $ipnData['receiver_email'] ?? '';
$mcGross       = $ipnData['mc_gross'] ?? '0.00';
$mcFee         = $ipnData['mc_fee'] ?? '0.00';
$mcCurrency    = strtoupper($ipnData['mc_currency'] ?? '');
$itemName      = $ipnData['item_name'] ?? '';
$itemNumber    = $ipnData['item_number'] ?? '';
$quantity      = intval($ipnData['quantity'] ?? 1);
$shipping      = $ipnData['shipping'] ?? '0.00';
$tax           = $ipnData['tax'] ?? '0.00';
$txnType       = $ipnData['txn_type'] ?? '';

// Address fields
$addressStreet  = $ipnData['address_street'] ?? '';
$addressCity    = $ipnData['address_city'] ?? '';
$addressState   = $ipnData['address_state'] ?? '';
$addressZip     = $ipnData['address_zip'] ?? '';
$addressCountry = $ipnData['address_country'] ?? '';
$addressName    = $ipnData['address_name'] ?? '';

ipn_log("TXN: {$txnId} | Status: {$paymentStatus} | Amount: {$mcGross} {$mcCurrency} | Payer: {$payerEmail}");

// ── Security Checks ────────────────────────────────────────────────────

// 1. Payment must be Completed
if ($paymentStatus !== 'Completed') {
    ipn_log("SKIPPED: payment_status is '{$paymentStatus}' (not Completed). Logging only.");
    // Log non-Completed statuses (Pending, Refunded, etc.) but don't create orders
    http_response_code(200);
    exit;
}

// 2. Currency must match store configuration
if ($mcCurrency && $mcCurrency !== $configuredCurrency) {
    ipn_log("REJECTED: Currency mismatch. IPN says '{$mcCurrency}', store configured for '{$configuredCurrency}'");
    http_response_code(200);
    exit;
}

// 3. Receiver email must match configured merchant email
if ($configuredEmail && $receiverEmail) {
    if (strtolower($receiverEmail) !== strtolower($configuredEmail)) {
        ipn_log("REJECTED: Receiver email mismatch. IPN says '{$receiverEmail}', configured: '{$configuredEmail}'");
        http_response_code(200);
        exit;
    }
}

// 4. Transaction ID is required
if (empty($txnId)) {
    ipn_log("REJECTED: No txn_id in IPN data");
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
        ipn_log("DUPLICATE: txn_id '{$txnId}' already exists as order '{$existingOrder['order_id']}'. Skipping.");
        http_response_code(200);
        exit;
    }
}

// ── Parse Multiple Items (PayPal cart IPN format) ──────────────────────
// PayPal sends multiple items as item_name1, item_number1, quantity1, mc_gross_1, etc.
$items = [];
$subtotal = 0;

// Check for multi-item format (item_name1, item_name2, ...)
$itemIndex = 1;
while (isset($ipnData["item_name{$itemIndex}"])) {
    $iName = $ipnData["item_name{$itemIndex}"] ?? '';
    $iNumber = $ipnData["item_number{$itemIndex}"] ?? '';
    $iQty = intval($ipnData["quantity{$itemIndex}"] ?? 1);
    // mc_gross_X is the per-item total (price * qty)
    $iGross = floatval($ipnData["mc_gross_{$itemIndex}"] ?? 0);
    $iPrice = $iQty > 0 ? round($iGross / $iQty, 2) : $iGross;

    $items[] = [
        'name'       => $iName,
        'sku'        => $iNumber,
        'quantity'   => $iQty,
        'price'      => $iPrice,
        'total'      => $iGross,
        'variations' => [],
    ];
    $subtotal += $iGross;
    $itemIndex++;
}

// Single-item format fallback
if (empty($items) && !empty($itemName)) {
    $itemPrice = floatval($mcGross) - floatval($shipping) - floatval($tax);
    if ($quantity > 1) {
        $itemPrice = round($itemPrice / $quantity, 2);
    }
    $items[] = [
        'name'       => $itemName,
        'sku'        => $itemNumber,
        'quantity'   => $quantity,
        'price'      => $itemPrice,
        'total'      => $itemPrice * $quantity,
        'variations' => [],
    ];
    $subtotal = $itemPrice * $quantity;
}

// If we still have no items, create a generic line item from the total
if (empty($items)) {
    $items[] = [
        'name'       => 'PayPal Purchase',
        'sku'        => '',
        'quantity'   => 1,
        'price'      => floatval($mcGross),
        'total'      => floatval($mcGross),
        'variations' => [],
    ];
    $subtotal = floatval($mcGross);
}

// ── Build Order Record ─────────────────────────────────────────────────
$orderPrefix = $settings['orderPrefix'] ?? 'ORD';
$orderId = $orderPrefix . '-' . date('Ymd-His') . '-' . substr(md5($txnId), 0, 6);

// Parse customer name from address_name or first/last
$customerName = trim($firstName . ' ' . $lastName);
if (empty($customerName) && !empty($addressName)) {
    $customerName = $addressName;
}

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
    'tax'            => round(floatval($tax), 2),
    'tax_rate'       => floatval($settings['taxRate'] ?? 0),
    'shipping'       => round(floatval($shipping), 2),
    'total'          => round(floatval($mcGross), 2),
    'payment_method' => 'paypal',
    'payment_id'     => $txnId,
    'paypal_txn_id'  => $txnId,
    'paypal_fee'     => round(floatval($mcFee), 2),
    'paypal_payer'   => $payerEmail,
    'created_at'     => date('c'),
    'updated_at'     => date('c'),
    'source'         => 'paypal_ipn',
    'notes'          => '',
];

// ── Save Order ─────────────────────────────────────────────────────────
$orders[] = $order;
$written = file_put_contents(
    $ordersFile,
    json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($written === false) {
    ipn_log("ERROR: Failed to write order to {$ordersFile}");
    http_response_code(200);
    exit;
}

ipn_log("ORDER CREATED: {$orderId} | TXN: {$txnId} | Total: \${$mcGross} | Customer: {$customerName} <{$payerEmail}>");

// ── Pipe to AudienceBuilder leads ──────────────────────────────────────
$abLeadsDir = SITE_ROOT . '/admin/data/AudienceBuilder/leads';
if (!is_dir($abLeadsDir)) @mkdir($abLeadsDir, 0775, true);
$abMonth = date('Y-m');
$abFile = $abLeadsDir . '/' . $abMonth . '.json';
$abLeads = is_file($abFile) ? (json_decode(file_get_contents($abFile), true) ?: []) : [];
$abLeads[] = [
    'id'           => 'ipn-' . $orderId,
    'source'       => 'paypal-ipn',
    'source_url'   => 'paypal',
    'submitted_at' => date('c'),
    'domain'       => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'fields'       => [
        'name'        => $customerName,
        'email'       => $payerEmail,
        'address'     => trim("{$addressStreet}, {$addressCity}, {$addressState} {$addressZip}"),
        'order_id'    => $orderId,
        'order_total' => '$' . number_format(floatval($mcGross), 2),
    ],
    'status' => 'converted',
    'tags'   => ['customer', 'store-purchase', 'paypal-ipn'],
];
file_put_contents($abFile, json_encode($abLeads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

// ── Telegram Notification ──────────────────────────────────────────────
$tgLib = SITE_ROOT . '/admin/lib/TelegramNotify.php';
if (is_file($tgLib)) {
    require_once $tgLib;
    $itemList = implode("\n", array_map(function ($i) {
        return "  - {$i['name']} x{$i['quantity']} = \${$i['total']}";
    }, $items));
    $tgMsg = "\xF0\x9F\x92\xB0 *New PayPal Order!*\n"
           . "Order: {$orderId}\n"
           . "Customer: {$customerName}\n"
           . "Email: {$payerEmail}\n"
           . "Items:\n{$itemList}\n"
           . "Total: \$" . number_format(floatval($mcGross), 2) . "\n"
           . "TXN: {$txnId}";
    tg_notify($tgMsg);
}

// ── Send Confirmation Email ────────────────────────────────────────────
if ($payerEmail) {
    $storeName = $settings['storeName'] ?? 'Our Store';
    $subject = "Order Confirmed: {$orderId} — {$storeName}";

    $itemsHtml = '';
    foreach ($items as $item) {
        $itemsHtml .= '<tr>'
            . '<td style="padding:8px 0;border-bottom:1px solid #eee">' . htmlspecialchars($item['name']) . '</td>'
            . '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:center">' . intval($item['quantity']) . '</td>'
            . '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right">$' . number_format($item['total'], 2) . '</td>'
            . '</tr>';
    }

    $emailHtml = '<div style="font-family:sans-serif;max-width:560px;margin:0 auto;padding:24px">'
        . '<h2 style="color:#333;margin:0 0 16px;text-align:center">Order Confirmed!</h2>'
        . '<p>Hi ' . htmlspecialchars($customerName) . ',</p>'
        . '<p>Thank you for your order from <strong>' . htmlspecialchars($storeName) . '</strong>.</p>'
        . '<p style="background:#f5f5f5;padding:10px 14px;border-radius:6px;font-weight:bold;color:#333">Order: ' . htmlspecialchars($orderId) . '</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:0.9rem">'
        . '<tr style="border-bottom:2px solid #333"><th style="text-align:left;padding:8px 0">Item</th><th style="text-align:center;padding:8px 0">Qty</th><th style="text-align:right;padding:8px 0">Price</th></tr>'
        . $itemsHtml
        . '<tr><td colspan="2" style="padding:6px 0;text-align:right;color:#888">Subtotal</td><td style="padding:6px 0;text-align:right">$' . number_format($subtotal, 2) . '</td></tr>'
        . '<tr><td colspan="2" style="padding:6px 0;text-align:right;color:#888">Tax</td><td style="padding:6px 0;text-align:right">$' . number_format(floatval($tax), 2) . '</td></tr>'
        . '<tr><td colspan="2" style="padding:6px 0;text-align:right;color:#888">Shipping</td><td style="padding:6px 0;text-align:right">' . (floatval($shipping) == 0 ? 'FREE' : '$' . number_format(floatval($shipping), 2)) . '</td></tr>'
        . '<tr><td colspan="2" style="padding:8px 0;text-align:right;font-weight:bold;font-size:1.1rem;border-top:2px solid #333">Total</td><td style="padding:8px 0;text-align:right;font-weight:bold;font-size:1.1rem;border-top:2px solid #333">$' . number_format(floatval($mcGross), 2) . '</td></tr>'
        . '</table>'
        . '<p style="color:#888;font-size:0.85rem">Paid via PayPal</p>'
        . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
        . '<p style="color:#999;font-size:0.75rem;text-align:center">' . htmlspecialchars($storeName) . '</p>'
        . '</div>';

    // Transport selection (SMTP / Mailgun / host MTA) belongs to mail.php, which reads this
    // site's own config and un-seals credentials. The hand-rolled Mailgun client that used to
    // live here also sent HTML with no text/plain alternative, and never closed its curl
    // handle.
    require_once SITE_ROOT . '/admin/includes/mail.php';
    $opt = !empty($settings['businessEmail']) ? ['from_email' => $settings['businessEmail']] : [];
    $res = luminal_send_mail($payerEmail, $subject, $emailHtml, '', $opt);

    if (!empty($res['ok'])) {
        ipn_log("EMAIL: Confirmation sent via {$res['transport']} to {$payerEmail}");
    } else {
        // An IPN must still return 200 to PayPal, so a mail failure is logged, not fatal.
        ipn_log("EMAIL: FAILED via {$res['transport']} to {$payerEmail}: {$res['error']}");
    }
}

ipn_log("COMPLETE: IPN fully processed for txn {$txnId}");

// ── Return 200 to PayPal ───────────────────────────────────────────────
http_response_code(200);
exit;
