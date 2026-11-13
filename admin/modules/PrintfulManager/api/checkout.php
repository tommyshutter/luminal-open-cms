<?php
/**
 * PrintfulManager — Checkout API
 *
 * Handles the checkout flow:
 *   1. estimate_shipping — get shipping rates for cart
 *   2. create_order — submit order to Printful, return confirmation
 *
 * Path A: Printful handles fulfillment + payment confirmation.
 * Orders are created as "draft" — Printful sends invoice to store owner.
 *
 * @package  LuminalCMS
 * @module   PrintfulManager
 * @version  1.0.0
 * @file     admin/modules/PrintfulManager/api/checkout.php
 * @date     2026-03-20
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__, 4));

// Load Printful config
$configFile = SITE_ROOT . '/admin/data/printful/printful-config.json';
if (!is_file($configFile)) {
    $configFile = SITE_ROOT . '/admin/data/printful/config.json';
}
$config = is_file($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$apiKey = $config['api_key'] ?? '';
$storeId = $config['store_id'] ?? '';

if (!$apiKey) {
    echo json_encode(['ok' => false, 'error' => 'Printful not configured']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * Normalize a phone number before it reaches Printful.
 * An autofilled "+1" prefix can make Printful reject an order AFTER the PayPal
 * capture has already succeeded. Phone is optional, so an unusable value is dropped.
 */
function pfNormalizePhone($raw): string {
    $d = preg_replace('/[^0-9]/', '', (string)$raw);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    if (strlen($d) === 10) return $d;
    return (strlen($d) >= 7 && strlen($d) <= 15) ? $d : '';
}

function pfApi(string $endpoint, string $method = 'GET', ?array $body = null): array {
    global $apiKey, $storeId;
    $url = 'https://api.printful.com' . $endpoint;
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ];
    if ($storeId) $headers[] = 'X-PF-Store-Id: ' . $storeId;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $data = json_decode($response, true) ?: [];
    $data['_http'] = $httpCode;
    return $data;
}

switch ($action) {

    // ── Get product details with variants + prices ──
    case 'get_product':
        $productId = $input['product_id'] ?? '';
        if (!$productId) { echo json_encode(['ok' => false, 'error' => 'Missing product_id']); exit; }

        // /sync/products works for every store type; /store/products is rejected
        // with HTTP 400 on platform-connected stores (Shopify, Etsy, ...).
        $result = pfApi("/sync/products/{$productId}");
        if (($result['_http'] ?? 0) !== 200) {
            $pfMsg = $result['error']['message']
                ?? (is_string($result['result'] ?? null) ? $result['result'] : '');
            error_log(sprintf(
                'PrintfulManager get_product failed: product_id=%s http=%s printful=%s',
                (string)$productId,
                (string)($result['_http'] ?? 'none'),
                $pfMsg !== '' ? $pfMsg : 'no message'
            ));
            echo json_encode(['ok' => false, 'error' => 'Failed to load product']);
            exit;
        }

        $product = $result['result']['sync_product'] ?? [];
        $variants = [];
        foreach ($result['result']['sync_variants'] ?? [] as $v) {
            $variants[] = [
                'id' => $v['id'],
                'variant_id' => $v['variant_id'],
                'name' => $v['name'] ?? '',
                'retail_price' => $v['retail_price'] ?? '0.00',
                'currency' => $v['currency'] ?? 'USD',
                'sku' => $v['sku'] ?? '',
            ];
        }

        echo json_encode([
            'ok' => true,
            'product' => [
                'id' => $product['id'] ?? '',
                'name' => $product['name'] ?? '',
                'thumbnail' => $product['thumbnail_url'] ?? '',
            ],
            'variants' => $variants,
        ]);
        break;

    // ── Estimate shipping costs ──
    case 'estimate_shipping':
        $address = $input['address'] ?? [];
        $items = $input['items'] ?? [];

        if (empty($address['country_code']) || empty($items)) {
            echo json_encode(['ok' => false, 'error' => 'Address and items required']);
            exit;
        }

        $pfItems = [];
        foreach ($items as $item) {
            $pfItems[] = [
                'variant_id' => (int)($item['variant_id'] ?? 0),
                'quantity' => (int)($item['quantity'] ?? 1),
            ];
        }

        $result = pfApi('/shipping/rates', 'POST', [
            'recipient' => [
                'country_code' => $address['country_code'] ?? 'US',
                'state_code' => $address['state_code'] ?? '',
                'city' => $address['city'] ?? '',
                'zip' => $address['zip'] ?? '',
            ],
            'items' => $pfItems,
        ]);

        if (($result['_http'] ?? 0) !== 200) {
            echo json_encode(['ok' => false, 'error' => $result['error']['message'] ?? 'Shipping estimate failed']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'rates' => $result['result'] ?? [],
        ]);
        break;

    // ── Create order ──
    case 'create_order':
        $customer = $input['customer'] ?? [];
        $items = $input['items'] ?? [];
        $shipping = $input['shipping'] ?? 'STANDARD';

        if (empty($customer['name']) || empty($customer['email']) || empty($customer['address1'])
            || empty($customer['city']) || empty($customer['zip']) || empty($items)) {
            echo json_encode(['ok' => false, 'error' => 'Customer info and items required']);
            exit;
        }

        // Build Printful order items
        $pfItems = [];
        $subtotal = 0;
        foreach ($items as $item) {
            $pfItems[] = [
                'sync_variant_id' => (int)($item['sync_variant_id'] ?? $item['variant_id'] ?? 0),
                'quantity' => (int)($item['quantity'] ?? 1),
                'retail_price' => $item['retail_price'] ?? '0.00',
            ];
            $subtotal += (float)($item['retail_price'] ?? 0) * (int)($item['quantity'] ?? 1);
        }

        $orderData = [
            'recipient' => [
                'name' => $customer['name'],
                'email' => $customer['email'],
                'phone' => pfNormalizePhone($customer['phone'] ?? ''),
                'address1' => $customer['address1'],
                'address2' => $customer['address2'] ?? '',
                'city' => $customer['city'],
                'state_code' => $customer['state'] ?? '',
                'country_code' => $customer['country'] ?? 'US',
                'zip' => $customer['zip'],
            ],
            'items' => $pfItems,
            'shipping' => $shipping,
        ];

        // Orders are created as drafts; the store owner confirms them in Printful.
        $paymentId = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string)($input['payment_id'] ?? '')), 0, 64);
        $paymentMethod = substr(preg_replace('/[^a-z_]/', '', strtolower((string)($input['payment_method'] ?? ''))), 0, 20);
        $result = pfApi('/orders', 'POST', $orderData);

        if (($result['_http'] ?? 0) !== 200) {
            $errMsg = $result['error']['message'] ?? 'Order creation failed';
            // PayPal's onApprove captures BEFORE this call. If Printful then rejects,
            // the payment must not vanish -- record it so it is recoverable rather
            // than living only in a toast the customer sees once.
            if ($paymentId) {
                $failFile = SITE_ROOT . '/admin/data/printful/failed-orders.json';
                $failed = is_file($failFile) ? (json_decode(file_get_contents($failFile), true) ?: []) : [];
                $failed[] = [
                    'payment_id'     => $paymentId,
                    'payment_method' => $paymentMethod,
                    'error'          => $errMsg,
                    'customer'       => $customer,
                    'items'          => $pfItems,
                    'subtotal'       => round($subtotal, 2),
                    'failed_at'      => date('c'),
                ];
                file_put_contents($failFile, json_encode($failed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                @chmod($failFile, 0660);
                $errMsg .= ' (payment ' . $paymentId . ' recorded for recovery)';
            }
            echo json_encode(['ok' => false, 'error' => $errMsg]);
            exit;
        }

        $order = $result['result'] ?? [];
        $orderId = $order['id'] ?? '';

        // Save order locally
        $ordersFile = SITE_ROOT . '/admin/data/printful/orders.json';
        $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
        $costs = $order['costs'] ?? [];
        $retail = $order['retail_costs'] ?? [];
        $pricing = $order['pricing_breakdown'][0] ?? [];
        $orders[] = [
            'printful_id' => $orderId,
            'customer_name' => $customer['name'],
            'customer_email' => $customer['email'],
            'items' => count($pfItems),
            'subtotal' => $subtotal,
            'retail_total' => $retail['total'] ?? $subtotal,
            'printful_cost' => $costs['total'] ?? '0.00',
            'profit' => $pricing['profit'] ?? '0.00',
            'shipping_cost' => $costs['shipping'] ?? '0.00',
            'status' => $order['status'] ?? 'draft',
            'payment_method' => $paymentMethod ?: 'none',
            'payment_id' => $paymentId,
            'dashboard_url' => $order['dashboard_url'] ?? '',
            'created_at' => date('c'),
        ];
        file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Telegram alert
        $tgLib = SITE_ROOT . '/admin/lib/TelegramNotify.php';
        if (is_file($tgLib)) {
            require_once $tgLib;
            if (function_exists('tg_alert')) {
                $domain = $_SERVER['HTTP_HOST'] ?? 'site';
                tg_alert('green', 'PRINTFUL ORDER (DRAFT: confirm after checking payment)',
                    "Site: {$domain}\nCustomer: {$customer['name']}\nEmail: {$customer['email']}\nItems: " . count($pfItems) . "\nSubtotal: \${$subtotal}\nPayment ref: " . ($paymentId !== '' ? $paymentId : 'none'),
                    ['Printful Dashboard' => 'https://www.printful.com/dashboard/orders']
                );
            }
        }

        echo json_encode([
            'ok' => true,
            'order_id' => $orderId,
            'status' => $order['status'] ?? 'draft',
            'message' => 'Order received! We will email you as soon as it is on its way.',
        ]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
