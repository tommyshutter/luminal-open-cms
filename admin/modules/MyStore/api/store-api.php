<?php
/**
 * MyStore public storefront API — handles cart sync, verification, orders
 * Standalone endpoint for shortcode-embedded stores
 */
session_start();
header('Content-Type: application/json');

if (!defined('MYSTORE_SYSTEM')) define('MYSTORE_SYSTEM', true);
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', dirname(__DIR__, 4));
}

// Session security
session_set_cookie_params(['samesite' => 'Lax', 'secure' => true, 'httponly' => true]);

$action = $_POST['ms_action'] ?? '';
if (!$action) { echo json_encode(['ok' => false, 'error' => 'No action']); exit; }

// CSRF token management
if (empty($_SESSION['ms_csrf'])) $_SESSION['ms_csrf'] = bin2hex(random_bytes(16));
// Exempt read-only and initial actions from CSRF
$csrfExempt = ['send_verification', 'verify_code', 'log_error'];
if (!in_array($action, $csrfExempt)) {
    if (!hash_equals($_SESSION['ms_csrf'], $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request — please refresh the page']);
        exit;
    }
}

// Load settings
$dataBase = SITE_ROOT . '/admin/data/mystore/';
if (!is_dir($dataBase) && is_dir(SITE_ROOT . '/admin/data/paypal/')) {
    $dataBase = SITE_ROOT . '/admin/data/paypal/';
}
$settings = [];
$sf = $dataBase . 'settings.json';
if (is_file($sf)) $settings = json_decode(file_get_contents($sf), true) ?: [];

$taxRate = floatval($settings['taxRate'] ?? 0);
$shippingCost = floatval($settings['shippingCost'] ?? 5.99);
$freeShipThreshold = floatval($settings['freeShippingThreshold'] ?? 75);

// Ensure SITE_ROOT is set before loading checkout (it checks defined('SITE_ROOT'))
if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__, 4));
require_once __DIR__ . '/mystore-checkout.php';
require_once __DIR__ . '/../includes/mystore-shipping.php';

// Order normalizer — ensures consistent fields regardless of order source
if (!function_exists('mystore_normalize_order')) {
    function mystore_normalize_order(array $o): array {
        $c = $o['customer'] ?? [];
        if (empty($c['name'])) {
            $first = $c['first_name'] ?? '';
            $last = $c['last_name'] ?? '';
            $c['name'] = trim("$first $last") ?: ($c['email'] ?? 'Unknown');
        }
        if (empty($c['email'])) $c['email'] = '';
        if (empty($c['phone'])) $c['phone'] = '';
        if (empty($c['address']) && !empty($o['shipping'])) $c['address'] = $o['shipping'];
        if (is_string($c['address'] ?? null)) $c['address'] = ['street' => $c['address']];
        if (!is_array($c['address'] ?? null)) $c['address'] = [];
        $o['customer'] = $c;
        if (!isset($o['total']) || $o['total'] === null) {
            if (isset($o['totals']['total'])) { $o['total'] = floatval($o['totals']['total']); }
            else { $s = 0; foreach ($o['items'] ?? [] as $i) { $s += floatval($i['price'] ?? $i['total'] ?? 0) * intval($i['quantity'] ?? 1); } $o['total'] = $s; }
        }
        $o['total'] = round(floatval($o['total']), 2);
        if (empty($o['totals'])) $o['totals'] = ['subtotal' => $o['subtotal'] ?? $o['total'], 'shipping' => $o['shipping'] ?? 0, 'tax' => $o['tax'] ?? 0, 'total' => $o['total']];
        if (empty($o['created_at'])) $o['created_at'] = $o['created'] ?? $o['date'] ?? date('c');
        if (empty($o['updated_at'])) $o['updated_at'] = $o['updated'] ?? $o['created_at'];
        if (empty($o['status'])) $o['status'] = 'processing';
        if (empty($o['payment_method'])) $o['payment_method'] = 'unknown';
        if (empty($o['order_id'])) $o['order_id'] = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(json_encode($o)), 0, 8));
        foreach ($o['items'] ?? [] as &$item) {
            if (!isset($item['total'])) $item['total'] = round(floatval($item['price'] ?? 0) * intval($item['quantity'] ?? 1), 2);
            if (empty($item['name'])) $item['name'] = 'Unknown Product';
            if (!isset($item['quantity'])) $item['quantity'] = 1;
            if (!isset($item['price'])) $item['price'] = $item['total'];
        }
        unset($item);
        if (!isset($o['items'])) $o['items'] = [];
        if (!isset($o['notes'])) $o['notes'] = '';
        return $o;
    }
}

// Load site logo
$siteSettingsFile = SITE_ROOT . '/admin/data/site-settings.json';
$siteSettings = is_file($siteSettingsFile) ? json_decode(file_get_contents($siteSettingsFile), true) : [];
$logoPath = $siteSettings['logo_path'] ?? '';
$logoUrl = '';
if ($logoPath) {
    $host = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $logoUrl = $host . '/' . ltrim($logoPath, '/');
}
$storeName = $settings['storeName'] ?? 'Store';

// Shared invoice HTML builder
function buildInvoiceHtml($o, $storeName, $logoUrl, $isResend = false) {
    $custName = htmlspecialchars($o['customer']['name'] ?? 'Customer');
    $addr = $o['customer']['address'] ?? [];
    $addrStr = trim(htmlspecialchars(($addr['street'] ?? '') . ', ' . ($addr['city'] ?? '') . ', ' . ($addr['state'] ?? '') . ' ' . ($addr['zip'] ?? '')), ', ');

    $itemsHtml = '';
    foreach ($o['items'] ?? [] as $item) {
        $itemsHtml .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee">' . htmlspecialchars($item['name']);
        if (!empty($item['variations']) && is_array($item['variations'])) {
            $varStr = implode(', ', array_map(function($k,$v){return "$k: $v";}, array_keys($item['variations']), $item['variations']));
            if ($varStr) $itemsHtml .= '<br><small style="color:#888">' . htmlspecialchars($varStr) . '</small>';
        }
        $itemsHtml .= '</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:center">' . intval($item['quantity']) . '</td>';
        $itemsHtml .= '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right">$' . number_format($item['total'] ?? 0, 2) . '</td></tr>';
    }

    $logoBlock = $logoUrl ? '<div style="text-align:center;margin-bottom:20px"><img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($storeName) . '" style="max-height:80px;max-width:240px"></div>' : '';
    $title = $isResend ? 'Your Order Receipt' : 'Order Confirmed!';

    return '<div style="font-family:sans-serif;max-width:560px;margin:0 auto;padding:24px">'
        . $logoBlock
        . '<h2 style="color:#333;margin:0 0 16px;text-align:center">' . $title . '</h2>'
        . '<p>Hi ' . $custName . ',</p>'
        . '<p>Thank you for your order from <strong>' . htmlspecialchars($storeName) . '</strong>.</p>'
        . '<p style="background:#f5f5f5;padding:10px 14px;border-radius:6px;font-weight:bold;color:#333">Order: ' . htmlspecialchars($o['order_id']) . '</p>'
        . ($addrStr ? '<p style="color:#666;font-size:0.85rem">Ship to: ' . $addrStr . '</p>' : '')
        . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:0.9rem">'
        . '<tr style="border-bottom:2px solid #333"><th style="text-align:left;padding:8px 0">Item</th><th style="text-align:center;padding:8px 0">Qty</th><th style="text-align:right;padding:8px 0">Price</th></tr>'
        . $itemsHtml
        . '<tr><td colspan="2" style="padding:6px 0;text-align:right;color:#888">Subtotal</td><td style="padding:6px 0;text-align:right">$' . number_format($o['subtotal'] ?? 0, 2) . '</td></tr>'
        . '<tr><td colspan="2" style="padding:6px 0;text-align:right;color:#888">Tax</td><td style="padding:6px 0;text-align:right">$' . number_format($o['tax'] ?? 0, 2) . '</td></tr>'
        . '<tr><td colspan="2" style="padding:6px 0;text-align:right;color:#888">Shipping</td><td style="padding:6px 0;text-align:right">' . (($o['shipping'] ?? 0) == 0 ? 'FREE' : '$' . number_format($o['shipping'], 2)) . '</td></tr>'
        . '<tr><td colspan="2" style="padding:8px 0;text-align:right;font-weight:bold;font-size:1.1rem;border-top:2px solid #333">Total</td><td style="padding:8px 0;text-align:right;font-weight:bold;font-size:1.1rem;border-top:2px solid #333">$' . number_format($o['total'] ?? 0, 2) . '</td></tr>'
        . '</table>'
        . '<p style="color:#888;font-size:0.85rem">Paid via ' . htmlspecialchars(ucfirst($o['payment_method'] ?? '')) . '</p>'
        . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0">'
        . '<p style="color:#999;font-size:0.75rem;text-align:center">' . htmlspecialchars($storeName) . '</p>'
        . '</div>';
}

switch ($action) {
    case 'sync_cart':
        $cartData = json_decode($_POST['cart'] ?? '[]', true);
        $_SESSION['mystore_cart'] = $cartData;
        echo json_encode(['ok' => true]);
        break;

    case 'create_order':
        $cartData = json_decode($_POST['cart'] ?? '[]', true);
        $customer = json_decode($_POST['customer'] ?? '{}', true);
        if (empty($cartData)) { echo json_encode(['ok' => false, 'error' => 'Cart is empty']); break; }
        if (!mystore_is_email_verified($customer['email'] ?? '')) {
            echo json_encode(['ok' => false, 'error' => 'Please verify your email first']); break;
        }
        // Server-side price validation — NEVER trust client-sent prices
        $productsDir = $dataBase . 'products/';
        $orderItems = []; $subtotal = 0; $totalQty = 0;
        foreach ($cartData as $ci) {
            $sku = preg_replace('/[^a-z0-9\-]/', '', strtolower($ci['sku'] ?? ''));
            if (!$sku) continue;
            $productFile = $productsDir . $sku . '.json';
            if (!is_file($productFile)) {
                echo json_encode(['ok' => false, 'error' => 'Product not found: ' . $sku]);
                exit;
            }
            $product = json_decode(file_get_contents($productFile), true);
            $serverPrice = floatval($product['price'] ?? 0);
            // Apply variation pricing — fixed price overrides base; adj adds/subtracts
            $cartVars = $ci['variations'] ?? [];
            foreach ($product['variations']['groups'] ?? [] as $group) {
                $groupName = $group['name'] ?? '';
                if (!isset($cartVars[$groupName])) continue;
                $selectedVal = $cartVars[$groupName];
                foreach ($group['options'] ?? [] as $opt) {
                    $optVal = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                    if ($optVal !== $selectedVal) continue;
                    if (is_array($opt)) {
                        if (isset($opt['price']) && $opt['price'] !== null) {
                            $serverPrice = floatval($opt['price']); // fixed — exact price
                        } elseif (isset($opt['adj']) && $opt['adj'] !== null) {
                            $serverPrice = floatval($product['price'] ?? 0) + floatval($opt['adj']); // adj — base ± delta
                        }
                    }
                    break;
                }
            }
            $qty = max(1, min(99, intval($ci['quantity'] ?? 1)));
            $itemTotal = $serverPrice * $qty;
            $orderItems[] = ['sku'=>$sku,'name'=>$product['name'] ?? $sku,'price'=>$serverPrice,'quantity'=>$qty,'variations'=>$ci['variations']??[],'total'=>$itemTotal];
            $subtotal += $itemTotal;
            $totalQty += $qty;
        }
        $tax = round($subtotal * ($taxRate / 100), 2);
        $shipping = mystore_calc_shipping($totalQty, $subtotal, $settings);
        $total = $subtotal + $tax + $shipping;
        $orderId = ($settings['orderPrefix'] ?? 'ORD') . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $order = ['order_id'=>$orderId,'status'=>'pending','customer'=>$customer,'items'=>$orderItems,'subtotal'=>$subtotal,'tax'=>$tax,'tax_rate'=>$taxRate,'shipping'=>$shipping,'total'=>$total,'payment_method'=>$_POST['payment_method']??'pending','payment_id'=>null,'created_at'=>date('c'),'updated_at'=>date('c')];
        $ordersFile = $dataBase . 'orders.json';
        $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
        $orders[] = $order;
        file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo json_encode(['ok' => true, 'order_id' => $orderId, 'total' => $total]);
        break;

    case 'complete_payment':
        $orderId = $_POST['order_id'] ?? '';
        $ordersFile = $dataBase . 'orders.json';
        $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
        $found = false;
        $foundIdx = -1;
        foreach ($orders as $idx => &$o) {
            if (($o['order_id'] ?? '') === $orderId) {
                $o['status'] = 'completed';
                $o['payment_method'] = $_POST['payment_method'] ?? '';
                $o['payment_id'] = $_POST['payment_id'] ?? '';
                $o['updated_at'] = date('c');
                $found = true;
                $foundIdx = $idx;
                break;
            }
        }
        unset($o);
        if ($found) {
            file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $_SESSION['mystore_cart'] = [];

            // Normalize the completed order for downstream use
            $completedOrder = mystore_normalize_order($orders[$foundIdx]);

            // Pipe customer to AudienceBuilder leads
            $abLeadsDir = SITE_ROOT . '/admin/data/AudienceBuilder/leads';
            if (!is_dir($abLeadsDir)) @mkdir($abLeadsDir, 0775, true);
            $abMonth = date('Y-m');
            $abFile = $abLeadsDir . '/' . $abMonth . '.json';
            $abLeads = is_file($abFile) ? (json_decode(file_get_contents($abFile), true) ?: []) : [];
            $abLeads[] = [
                'id' => 'store-' . $completedOrder['order_id'],
                'source' => 'mystore',
                'source_url' => 'checkout',
                'submitted_at' => date('c'),
                'domain' => $_SERVER['HTTP_HOST'] ?? 'unknown',
                'fields' => [
                    'name' => $completedOrder['customer']['name'] ?? '',
                    'email' => $completedOrder['customer']['email'] ?? '',
                    'phone' => $completedOrder['customer']['phone'] ?? '',
                    'address' => ($completedOrder['customer']['address']['street'] ?? '') . ', ' . ($completedOrder['customer']['address']['city'] ?? '') . ', ' . ($completedOrder['customer']['address']['state'] ?? '') . ' ' . ($completedOrder['customer']['address']['zip'] ?? ''),
                    'order_id' => $completedOrder['order_id'],
                    'order_total' => '$' . number_format($completedOrder['total'] ?? 0, 2),
                ],
                'status' => 'converted',
                'tags' => ['customer', 'store-purchase'],
            ];
            file_put_contents($abFile, json_encode($abLeads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

            // Send order confirmation email
            $custEmail = $completedOrder['customer']['email'] ?? '';
            if ($custEmail) {
                $emailHtml = buildInvoiceHtml($completedOrder, $storeName, $logoUrl, false);
                $subject = 'Order Confirmed: ' . $completedOrder['order_id'] . ' — ' . $storeName;
                require_once SITE_ROOT . '/admin/includes/mail.php';
                $opt = !empty($settings['businessEmail']) ? ['from_email' => $settings['businessEmail']] : [];
                luminal_send_mail($custEmail, $subject, $emailHtml, '', $opt);
            }

            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Order not found']);
        }
        break;

    case 'send_verification':
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$email) { echo json_encode(['ok' => false, 'error' => 'Invalid email']); break; }
        $spam = mystore_check_spam_email($email);
        if (count($spam) >= 2) { echo json_encode(['ok' => false, 'error' => 'This email address appears invalid']); break; }
        $v = $_SESSION['mystore_email_verify'] ?? null;
        if ($v && $v['email'] === $email && (time() - ($v['sent_at'] ?? 0)) < 60) {
            echo json_encode(['ok' => false, 'error' => 'Please wait before requesting another code']); break;
        }
        // Debug: check Mailgun config is reachable
        $mgPath = SITE_ROOT . '/admin/data/MailgunManager/mailgun-config.json';
        if (!is_file($mgPath)) {
            echo json_encode(['ok' => false, 'error' => 'Email service not configured']);
            break;
        }
        $sent = mystore_send_verification($email);
        if ($sent) {
            $_SESSION['mystore_email_verify']['sent_at'] = time();
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Email send failed — check Mailgun API key']);
        }
        break;

    case 'verify_code':
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
        $result = mystore_verify_code($email, $code);
        echo json_encode(['ok' => $result['ok'], 'error' => $result['error'] ?? null]);
        break;

    case 'resend_invoice':
    case 'print_order':
        // Admin-only actions — require admin session
        if (session_status() === PHP_SESSION_NONE) session_start();
        $adminRoles = ['admin','superadmin','super'];
        $isAdmin = !empty($_SESSION['user_role']) && in_array($_SESSION['user_role'], $adminRoles);
        if (!$isAdmin) {
            // Fallback: check if they have a valid CMS auth session
            $authFile = SITE_ROOT . '/admin/data/auth/sessions.json';
            if (is_file($authFile)) {
                $sessions = json_decode(file_get_contents($authFile), true) ?: [];
                $sid = session_id();
                $isAdmin = isset($sessions[$sid]) && in_array($sessions[$sid]['role'] ?? '', $adminRoles);
            }
            if (!$isAdmin) {
                echo json_encode(['ok' => false, 'error' => 'Admin access required']);
                break;
            }
        }
        $orderId = $_POST['order_id'] ?? '';
        $ordersFile = $dataBase . 'orders.json';
        $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
        $o = null;
        foreach ($orders as $ord) {
            if (($ord['order_id'] ?? '') === $orderId) {
                $o = mystore_normalize_order($ord);
                break;
            }
        }
        if (!$o) { echo json_encode(['ok' => false, 'error' => 'Order not found']); break; }
        if ($action === 'print_order') goto print_order_handler;

        $custEmail = $o['customer']['email'] ?? '';
        $custName = $o['customer']['name'] ?? 'Customer';
        if (!$custEmail) { echo json_encode(['ok' => false, 'error' => 'No customer email']); break; }

        $emailHtml = buildInvoiceHtml($o, $storeName, $logoUrl, true);
        $subject = 'Invoice: ' . $o['order_id'] . ' — ' . $storeName;
        require_once SITE_ROOT . '/admin/includes/mail.php';
        $opt  = !empty($settings['businessEmail']) ? ['from_email' => $settings['businessEmail']] : [];
        $r    = luminal_send_mail($custEmail, $subject, $emailHtml, '', $opt);
        $sent = !empty($r['ok']);
        // Surface the transport's own reason instead of a flat "failed" — the Printful
        // lesson: a discarded error message makes a misconfiguration and a dead credential
        // look identical from outside.
        echo json_encode(['ok' => $sent, 'error' => $sent ? null : ($r['error'] ?: 'Failed to send email')]);
        break;

    print_order_handler:
        $type = $_POST['type'] ?? 'packing';
        $bizAddr = trim(($settings['businessName'] ?? '') . "\n" . ($settings['businessAddress'] ?? '') . "\n" . ($settings['businessCity'] ?? '') . ', ' . ($settings['businessState'] ?? '') . ' ' . ($settings['businessZip'] ?? ''));
        $custAddr = ($o['customer']['name'] ?? '') . "\n" . ($o['customer']['address']['street'] ?? '') . "\n" . ($o['customer']['address']['city'] ?? '') . ', ' . ($o['customer']['address']['state'] ?? '') . ' ' . ($o['customer']['address']['zip'] ?? '');

        if ($type === 'label') {
            $html = '<html><head><title>Shipping Label</title><style>@page{size:4in 6in;margin:0.25in}body{font-family:sans-serif;margin:0;padding:0.25in}'
                . '.from{font-size:10px;color:#666;margin-bottom:20px}.to{font-size:18px;font-weight:bold;line-height:1.6;margin-top:20px;padding-top:16px;border-top:2px solid #000}'
                . '.order{font-size:10px;color:#888;margin-top:20px;border-top:1px solid #ccc;padding-top:8px}</style></head><body>'
                . '<div class="from">FROM:<br>' . nl2br(htmlspecialchars($bizAddr)) . '</div>'
                . '<div class="to">SHIP TO:<br>' . nl2br(htmlspecialchars($custAddr)) . '</div>'
                . '<div class="order">Order: ' . htmlspecialchars($o['order_id']) . '</div>'
                . '</body></html>';
        } else {
            $itemRows = '';
            foreach ($o['items'] ?? [] as $item) {
                $vars = '';
                if (!empty($item['variations']) && is_array($item['variations'])) {
                    $vars = ' (' . implode(', ', array_map(function($k,$v){return "$k: $v";}, array_keys($item['variations']), $item['variations'])) . ')';
                }
                $itemRows .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd">' . htmlspecialchars($item['name'] . $vars) . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #ddd;text-align:center">' . intval($item['quantity']) . '</td>'
                    . '<td style="padding:8px;border-bottom:1px solid #ddd;text-align:right">$' . number_format($item['total'] ?? 0, 2) . '</td></tr>';
            }
            $html = '<html><head><title>Packing Slip</title><style>@page{margin:0.5in}body{font-family:sans-serif;color:#333;font-size:14px}'
                . 'h1{font-size:20px;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin:16px 0}'
                . 'th{text-align:left;padding:8px;border-bottom:2px solid #333;font-size:12px;text-transform:uppercase;color:#666}'
                . '.addr{background:#f5f5f5;padding:12px;border-radius:6px;margin:12px 0;font-size:13px;line-height:1.6}'
                . '.footer{margin-top:24px;padding-top:12px;border-top:1px solid #ddd;font-size:11px;color:#999}</style></head><body>'
                . ($logoUrl ? '<div style="text-align:center;margin-bottom:16px"><img src="' . htmlspecialchars($logoUrl) . '" style="max-height:60px;max-width:200px"></div>' : '')
                . '<h1>' . htmlspecialchars($storeName) . '</h1>'
                . '<div style="color:#888;font-size:12px;margin-bottom:16px">Packing Slip — ' . htmlspecialchars($o['order_id']) . ' — ' . date('M j, Y', strtotime($o['created_at'] ?? 'now')) . '</div>'
                . '<div style="display:flex;gap:24px"><div class="addr" style="flex:1"><strong>Ship To:</strong><br>' . nl2br(htmlspecialchars($custAddr)) . '</div>'
                . '<div class="addr" style="flex:1"><strong>From:</strong><br>' . nl2br(htmlspecialchars($bizAddr)) . '</div></div>'
                . '<table><tr><th>Item</th><th style="text-align:center">Qty</th><th style="text-align:right">Price</th></tr>' . $itemRows
                . '<tr><td colspan="2" style="padding:8px;text-align:right;font-weight:bold">Total</td><td style="padding:8px;text-align:right;font-weight:bold">$' . number_format($o['total'] ?? 0, 2) . '</td></tr></table>'
                . '<div class="footer">Thank you for your order! — ' . htmlspecialchars($storeName) . '</div>'
                . '</body></html>';
        }
        echo json_encode(['ok' => true, 'html' => $html]);
        break;

    case 'update_order_status':
    case 'archive_order':
    case 'delete_order':
    case 'bulk_orders':
        // Admin-only actions — require admin session
        if (session_status() === PHP_SESSION_NONE) session_start();
        $adminRoles = ['admin','superadmin','super'];
        $isAdmin = !empty($_SESSION['user_role']) && in_array($_SESSION['user_role'], $adminRoles);
        if (!$isAdmin) {
            $authFile = SITE_ROOT . '/admin/data/auth/sessions.json';
            if (is_file($authFile)) {
                $sessions = json_decode(file_get_contents($authFile), true) ?: [];
                $sid = session_id();
                $isAdmin = isset($sessions[$sid]) && in_array($sessions[$sid]['role'] ?? '', $adminRoles);
            }
            if (!$isAdmin) {
                echo json_encode(['ok' => false, 'error' => 'Admin access required']);
                break;
            }
        }

        $ordersFile = $dataBase . 'orders.json';
        $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];

        if ($action === 'update_order_status' || $action === 'archive_order') {
            $orderId = $_POST['order_id'] ?? '';
            $newStatus = $action === 'archive_order' ? 'archived' : ($_POST['new_status'] ?? '');
            $validStatuses = ['completed','pending','processing','on-hold','failed','refunded','cancelled','archived'];
            if (!in_array($newStatus, $validStatuses)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid status']);
                break;
            }
            $found = false;
            foreach ($orders as &$o) {
                if (($o['order_id'] ?? '') === $orderId) {
                    $o['status'] = $newStatus;
                    $o['updated_at'] = date('c');
                    $found = true;
                    break;
                }
            }
            unset($o);
            if (!$found) {
                echo json_encode(['ok' => false, 'error' => 'Order not found']);
                break;
            }
            file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo json_encode(['ok' => true, 'message' => 'Status updated to ' . $newStatus]);
            break;
        }

        if ($action === 'delete_order') {
            $orderId = $_POST['order_id'] ?? '';
            $before = count($orders);
            $orders = array_values(array_filter($orders, function($o) use ($orderId) {
                return ($o['order_id'] ?? '') !== $orderId;
            }));
            if (count($orders) === $before) {
                echo json_encode(['ok' => false, 'error' => 'Order not found']);
                break;
            }
            file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo json_encode(['ok' => true, 'message' => 'Order deleted']);
            break;
        }

        if ($action === 'bulk_orders') {
            $bulkAction = $_POST['bulk_action'] ?? '';
            $orderIds = json_decode($_POST['order_ids'] ?? '[]', true);
            if (!is_array($orderIds) || empty($orderIds)) {
                echo json_encode(['ok' => false, 'error' => 'No orders specified']);
                break;
            }
            $idSet = array_flip($orderIds);
            $affected = 0;

            switch ($bulkAction) {
                case 'archive_selected':
                    foreach ($orders as &$o) {
                        if (isset($idSet[$o['order_id'] ?? ''])) {
                            $o['status'] = 'archived';
                            $o['updated_at'] = date('c');
                            $affected++;
                        }
                    }
                    unset($o);
                    break;
                case 'delete_selected':
                    $before = count($orders);
                    $orders = array_values(array_filter($orders, function($o) use ($idSet) {
                        return !isset($idSet[$o['order_id'] ?? '']);
                    }));
                    $affected = $before - count($orders);
                    break;
                case 'mark_completed':
                    foreach ($orders as &$o) {
                        if (isset($idSet[$o['order_id'] ?? ''])) {
                            $o['status'] = 'completed';
                            $o['updated_at'] = date('c');
                            $affected++;
                        }
                    }
                    unset($o);
                    break;
                case 'mark_processing':
                    foreach ($orders as &$o) {
                        if (isset($idSet[$o['order_id'] ?? ''])) {
                            $o['status'] = 'processing';
                            $o['updated_at'] = date('c');
                            $affected++;
                        }
                    }
                    unset($o);
                    break;
                default:
                    echo json_encode(['ok' => false, 'error' => 'Invalid bulk action']);
                    break 2;
            }
            file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo json_encode(['ok' => true, 'message' => $affected . ' order(s) updated', 'affected' => $affected]);
            break;
        }
        break;

    case 'export_orders_csv':
        // Admin-only
        if (session_status() === PHP_SESSION_NONE) session_start();
        $adminRoles = ['admin','superadmin','super'];
        $isAdmin = !empty($_SESSION['user_role']) && in_array($_SESSION['user_role'], $adminRoles);
        if (!$isAdmin) {
            $authFile = SITE_ROOT . '/admin/data/auth/sessions.json';
            if (is_file($authFile)) {
                $sessions = json_decode(file_get_contents($authFile), true) ?: [];
                $sid = session_id();
                $isAdmin = isset($sessions[$sid]) && in_array($sessions[$sid]['role'] ?? '', $adminRoles);
            }
            if (!$isAdmin) {
                echo json_encode(['ok' => false, 'error' => 'Admin access required']);
                break;
            }
        }
        $ordersFile = $dataBase . 'orders.json';
        $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
        $orders = array_map('mystore_normalize_order', $orders);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=orders-export-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Order ID','Date','Customer Name','Customer Email','Phone','Street','City','State','Zip','Items','Item Count','Subtotal','Tax','Shipping','Total','Payment Method','Payment ID','Status']);
        foreach ($orders as $o) {
            $cust = $o['customer'] ?? [];
            $addr = $cust['address'] ?? [];
            $itemNames = implode('; ', array_map(fn($i) => ($i['name'] ?? '') . ' x' . ($i['quantity'] ?? 1), $o['items'] ?? []));
            $itemCount = array_sum(array_column($o['items'] ?? [], 'quantity'));
            fputcsv($out, [
                $o['order_id'] ?? '', $o['created_at'] ?? '', $cust['name'] ?? '', $cust['email'] ?? '', $cust['phone'] ?? '',
                $addr['street'] ?? '', $addr['city'] ?? '', $addr['state'] ?? '', $addr['zip'] ?? '',
                $itemNames, $itemCount,
                number_format($o['subtotal'] ?? 0, 2, '.', ''), number_format($o['tax'] ?? 0, 2, '.', ''), number_format($o['shipping'] ?? 0, 2, '.', ''), number_format($o['total'] ?? 0, 2, '.', ''),
                $o['payment_method'] ?? '', $o['payment_id'] ?? '', $o['status'] ?? ''
            ]);
        }
        fclose($out);
        exit;

    case 'submit_help':
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if (!$name || !$email || !$message) { echo json_encode(['ok' => false, 'error' => 'Missing required fields']); break; }

        // 1. Pipe to AudienceBuilder leads
        $abLeadsDir = SITE_ROOT . '/admin/data/AudienceBuilder/leads';
        if (!is_dir($abLeadsDir)) @mkdir($abLeadsDir, 0775, true);
        $abFile = $abLeadsDir . '/' . date('Y-m') . '.json';
        $abLeads = is_file($abFile) ? (json_decode(file_get_contents($abFile), true) ?: []) : [];
        $abLeads[] = [
            'id' => 'help-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            'source' => 'store-help',
            'source_url' => $_SERVER['HTTP_REFERER'] ?? 'store',
            'submitted_at' => date('c'),
            'domain' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'fields' => ['name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $message],
            'status' => 'new',
            'tags' => ['store-help', 'support-request'],
        ];
        file_put_contents($abFile, json_encode($abLeads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        // 2. Telegram alert
        $tgLib = SITE_ROOT . '/admin/lib/TelegramNotify.php';
        if (is_file($tgLib)) {
            require_once $tgLib;
            $tgMsg = "\xF0\x9F\x86\x98 *Store Help Request*\n"
                   . "From: {$name}\n"
                   . "Email: {$email}\n"
                   . ($phone ? "Phone: {$phone}\n" : '')
                   . "Message: {$message}";
            tg_notify($tgMsg);
        }

        // 3. Send confirmation email to customer
        $storeName = $settings['storeName'] ?? 'Our Store';
        $confHtml = '<div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px">'
            . '<h2 style="color:#333">We got your message!</h2>'
            . '<p>Hi ' . htmlspecialchars($name) . ',</p>'
            . '<p>Thanks for reaching out to <strong>' . htmlspecialchars($storeName) . '</strong>. We\'ll get back to you as soon as possible.</p>'
            . '<p style="background:#f5f5f5;padding:12px;border-radius:6px;color:#555;font-size:0.9rem"><em>"' . htmlspecialchars(substr($message, 0, 200)) . '"</em></p>'
            . '<p style="color:#999;font-size:0.75rem">' . htmlspecialchars($storeName) . '</p></div>';
        require_once SITE_ROOT . '/admin/includes/mail.php';
        $opt = !empty($settings['businessEmail']) ? ['from_email' => $settings['businessEmail']] : [];
        luminal_send_mail($email, 'We got your message — ' . $storeName, $confHtml, '', $opt);

        echo json_encode(['ok' => true]);
        break;

    case 'log_error':
        $rawError = $_POST['error'] ?? '';
        if (strlen($rawError) > 1024) $rawError = substr($rawError, 0, 1024);
        $error = json_decode($rawError, true);
        if ($error && is_array($error)) {
            // Whitelist fields
            $entry = [
                'time' => $error['time'] ?? date('c'),
                'error' => substr($error['error'] ?? '', 0, 500),
                'page' => substr($error['page'] ?? '', 0, 200),
            ];
            $logFile = $dataBase . 'error_log.json';
            $log = is_file($logFile) ? json_decode(file_get_contents($logFile), true) : [];
            $log[] = $entry;
            if (count($log) > 200) $log = array_slice($log, -200);
            @file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
        }
        echo json_encode(['ok' => true]);
        break;

    case 'manual_order':
        // Admin-only — create an order from manual input (e.g. payments captured outside the system)
        if (session_status() === PHP_SESSION_NONE) session_start();
        $adminRoles = ['admin','superadmin','super'];
        $isAdmin = !empty($_SESSION['user_role']) && in_array($_SESSION['user_role'], $adminRoles);
        if (!$isAdmin) {
            $authFile = SITE_ROOT . '/admin/data/auth/sessions.json';
            if (is_file($authFile)) {
                $sessions = json_decode(file_get_contents($authFile), true) ?: [];
                $sid = session_id();
                $isAdmin = isset($sessions[$sid]) && in_array($sessions[$sid]['role'] ?? '', $adminRoles);
            }
            if (!$isAdmin) {
                echo json_encode(['ok' => false, 'error' => 'Admin access required']);
                break;
            }
        }

        $orderJson = $_POST['order_data'] ?? '';
        $orderData = json_decode($orderJson, true);
        if (!$orderData || !is_array($orderData)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid order data JSON']);
            break;
        }

        // Validate required fields
        $customerName = trim($orderData['customer']['name'] ?? '');
        $customerEmail = trim($orderData['customer']['email'] ?? '');
        if (empty($customerName) && empty($customerEmail)) {
            echo json_encode(['ok' => false, 'error' => 'Customer name or email is required']);
            break;
        }

        $orderItems = $orderData['items'] ?? [];
        if (empty($orderItems)) {
            echo json_encode(['ok' => false, 'error' => 'At least one item is required']);
            break;
        }

        // Build normalized order
        $normalizedItems = [];
        $subtotal = 0;
        foreach ($orderItems as $item) {
            $qty = max(1, intval($item['quantity'] ?? 1));
            $price = floatval($item['price'] ?? 0);
            $itemTotal = round($price * $qty, 2);
            $normalizedItems[] = [
                'name'       => $item['name'] ?? 'Item',
                'sku'        => $item['sku'] ?? '',
                'quantity'   => $qty,
                'price'      => $price,
                'total'      => $itemTotal,
                'variations' => $item['variations'] ?? [],
            ];
            $subtotal += $itemTotal;
        }

        $manualTax = floatval($orderData['tax'] ?? ($orderData['totals']['tax'] ?? 0));
        $manualShipping = floatval($orderData['shipping_cost'] ?? ($orderData['totals']['shipping'] ?? ($orderData['shipping'] ?? 0)));
        $manualTotal = floatval($orderData['total'] ?? ($orderData['totals']['total'] ?? ($subtotal + $manualTax + $manualShipping)));

        $orderPrefix = $settings['orderPrefix'] ?? 'ORD';
        $manualOrderId = $orderData['order_id'] ?? ($orderPrefix . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6));

        // Prevent duplicate order_id
        $ordersFile = $dataBase . 'orders.json';
        $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
        foreach ($orders as $existing) {
            if (($existing['order_id'] ?? '') === $manualOrderId) {
                echo json_encode(['ok' => false, 'error' => 'Order ID already exists: ' . $manualOrderId]);
                break 2;
            }
        }

        // Also check for duplicate PayPal txn_id if provided
        $paypalTxnId = $orderData['paypal_txn_id'] ?? ($orderData['payment_id'] ?? '');
        if ($paypalTxnId) {
            foreach ($orders as $existing) {
                $existTxn = $existing['paypal_txn_id'] ?? ($existing['payment_id'] ?? '');
                if ($existTxn === $paypalTxnId) {
                    echo json_encode(['ok' => false, 'error' => 'PayPal transaction already recorded as order: ' . ($existing['order_id'] ?? '')]);
                    break 2;
                }
            }
        }

        $newOrder = [
            'order_id'       => $manualOrderId,
            'status'         => $orderData['status'] ?? 'processing',
            'customer'       => [
                'name'    => $customerName ?: (trim(($orderData['customer']['first_name'] ?? '') . ' ' . ($orderData['customer']['last_name'] ?? ''))),
                'email'   => $customerEmail,
                'phone'   => $orderData['customer']['phone'] ?? '',
                'address' => [
                    'street'  => $orderData['customer']['address']['street'] ?? ($orderData['shipping']['address'] ?? ''),
                    'city'    => $orderData['customer']['address']['city'] ?? ($orderData['shipping']['city'] ?? ''),
                    'state'   => $orderData['customer']['address']['state'] ?? ($orderData['shipping']['state'] ?? ''),
                    'zip'     => $orderData['customer']['address']['zip'] ?? ($orderData['shipping']['zip'] ?? ''),
                    'country' => $orderData['customer']['address']['country'] ?? ($orderData['shipping']['country'] ?? 'United States'),
                ],
            ],
            'items'          => $normalizedItems,
            'subtotal'       => round($subtotal, 2),
            'tax'            => round($manualTax, 2),
            'tax_rate'       => floatval($settings['taxRate'] ?? 0),
            'shipping'       => round($manualShipping, 2),
            'total'          => round($manualTotal, 2),
            'payment_method' => $orderData['payment_method'] ?? 'manual',
            'payment_id'     => $paypalTxnId,
            'paypal_txn_id'  => $paypalTxnId ?: null,
            'created_at'     => $orderData['created'] ?? $orderData['created_at'] ?? date('c'),
            'updated_at'     => date('c'),
            'source'         => 'manual_entry',
            'notes'          => $orderData['notes'] ?? 'Manually entered by admin',
        ];

        $orders[] = $newOrder;
        $written = file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        if ($written === false) {
            echo json_encode(['ok' => false, 'error' => 'Failed to save order']);
            break;
        }

        // Log it
        $ipnLogFile = $dataBase . 'logs/ipn.log';
        $ipnLogDir = dirname($ipnLogFile);
        if (!is_dir($ipnLogDir)) @mkdir($ipnLogDir, 0775, true);
        $logEntry = "[" . date('Y-m-d H:i:s') . "] [MANUAL] Order {$manualOrderId} created by admin. Customer: {$customerName} <{$customerEmail}>. Total: \$" . number_format($manualTotal, 2) . "\n";
        @file_put_contents($ipnLogFile, $logEntry, FILE_APPEND | LOCK_EX);

        echo json_encode(['ok' => true, 'order_id' => $manualOrderId, 'message' => 'Manual order created']);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
