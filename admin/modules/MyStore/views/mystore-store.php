<?php
/**
 * MyStore — Customer-Facing Storefront
 * Loads real products from JSON catalog, localStorage cart with server sync at checkout
 *
 * @package LuminalCMS
 * @module  MyStore
 * @version 3.1.0
 */

if (!defined('MYSTORE_SYSTEM') && !defined('PAYPAL_SYSTEM')) {
    die('Direct access not allowed');
}

// Load real products from JSON files
$_ms_dataBase = SITE_ROOT . '/admin/data/mystore/';
if (!is_dir($_ms_dataBase) && is_dir(SITE_ROOT . '/admin/data/paypal/')) {
    $_ms_dataBase = SITE_ROOT . '/admin/data/paypal/';
}
$_ms_productsDir = $_ms_dataBase . 'products/';

$products = [];
if (is_dir($_ms_productsDir)) {
    foreach (glob($_ms_productsDir . '*.json') as $pf) {
        $p = json_decode(file_get_contents($pf), true);
        if ($p && !empty($p['sku']) && ($p['enabled'] ?? true)) {
            $p['variations'] = $p['variations'] ?? ['groups' => []];
            // Build gallery: main image + any additional images
            $p['images'] = $p['images'] ?? [];
            if (!empty($p['image']) && !in_array($p['image'], $p['images'])) {
                array_unshift($p['images'], $p['image']);
            }
            if (empty($p['images']) && !empty($p['image'])) {
                $p['images'] = [$p['image']];
            }
            $products[] = $p;
        }
    }
}
usort($products, function($a, $b) { return strcasecmp($a['name'] ?? '', $b['name'] ?? ''); });

// Load store settings
$_ms_settings = [];
$_ms_settingsFile = $_ms_dataBase . 'settings.json';
if (is_file($_ms_settingsFile)) {
    $_ms_settings = json_decode(file_get_contents($_ms_settingsFile), true) ?: [];
}
$storeName = $_ms_settings['storeName'] ?? 'Store';
$storeDesc = $_ms_settings['storeDescription'] ?? '';
$taxRate = floatval($_ms_settings['taxRate'] ?? 0);
$shippingCost = floatval($_ms_settings['shippingCost'] ?? 5.99);
$freeShipThreshold = floatval($_ms_settings['freeShippingThreshold'] ?? 75);
require_once dirname(__DIR__) . '/includes/mystore-shipping.php';

// Collect unique categories
$categories = array_unique(array_filter(array_column($products, 'category')));
sort($categories);

// PayPal / Stripe config for checkout
$paypalClientId = $_ms_settings['paypal_client_id'] ?? '';
$paypalMode = $_ms_settings['paypal_mode'] ?? 'sandbox';
$stripePublicKey = $_ms_settings['stripe_public_key'] ?? '';
$stripeMode = $_ms_settings['stripe_mode'] ?? 'test';

// Display settings
$gridColumns = max(2, min(6, intval($_ms_settings['grid_columns'] ?? 4)));
$_perPageRaw = intval($_ms_settings['per_page'] ?? 0);
$perPage = $_perPageRaw === 0 ? 0 : max(4, min(100, $_perPageRaw)); // 0 = show all
$currentPage = max(1, intval($_GET['shop_page'] ?? 1));
$gridMinWidth = [2 => '340px', 3 => '280px', 4 => '240px', 5 => '200px', 6 => '170px'][$gridColumns] ?? '240px';

// Theme colors
$cardBg = $_ms_settings['store_card_bg'] ?? '';
$pageBg = $_ms_settings['store_page_bg'] ?? '';
$accent = $_ms_settings['store_accent'] ?? '';
$bgOpacity = intval($_ms_settings['store_bg_opacity'] ?? 100);

// Pagination
$totalProducts = count($products);
$totalPages = ($perPage > 0) ? (int)ceil($totalProducts / $perPage) : 1;
$currentPage = min($currentPage, $totalPages);
$displayProducts = ($perPage > 0) ? array_slice($products, ($currentPage - 1) * $perPage, $perPage) : $products;

// Handle cart sync + checkout API calls
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ms_action'])) {
    header('Content-Type: application/json');
    if (session_status() === PHP_SESSION_NONE) session_start();

    switch ($_POST['ms_action']) {
        case 'sync_cart':
            // Sync localStorage cart to server session
            $cartData = json_decode($_POST['cart'] ?? '[]', true);
            $_SESSION['mystore_cart'] = $cartData;
            echo json_encode(['ok' => true]);
            exit;

        case 'create_order':
            require_once dirname(__DIR__) . '/api/mystore-checkout.php';
            $cartData = json_decode($_POST['cart'] ?? '[]', true);
            $customer = json_decode($_POST['customer'] ?? '{}', true);
            if (empty($cartData)) { echo json_encode(['ok' => false, 'error' => 'Cart is empty']); exit; }

            // Validate email verified
            if (!mystore_is_email_verified($customer['email'] ?? '')) {
                echo json_encode(['ok' => false, 'error' => 'Please verify your email first']);
                exit;
            }

            // Build order
            $orderItems = [];
            $subtotal = 0;
            $totalQty = 0;
            foreach ($cartData as $ci) {
                $itemTotal = floatval($ci['price']) * intval($ci['quantity']);
                $orderItems[] = [
                    'sku' => $ci['sku'],
                    'name' => $ci['name'],
                    'price' => floatval($ci['price']),
                    'quantity' => intval($ci['quantity']),
                    'variations' => $ci['variations'] ?? [],
                    'total' => $itemTotal,
                ];
                $subtotal += $itemTotal;
                $totalQty += intval($ci['quantity']);
            }
            $tax = round($subtotal * ($taxRate / 100), 2);
            $shipping = mystore_calc_shipping($totalQty, $subtotal, $_ms_settings);
            $total = $subtotal + $tax + $shipping;

            $orderId = ($_ms_settings['orderPrefix'] ?? 'ORD') . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $order = [
                'order_id' => $orderId,
                'status' => 'pending',
                'customer' => $customer,
                'items' => $orderItems,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'tax_rate' => $taxRate,
                'shipping' => $shipping,
                'total' => $total,
                'payment_method' => $_POST['payment_method'] ?? 'pending',
                'payment_id' => null,
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];

            // Save order
            $ordersFile = $_ms_dataBase . 'orders.json';
            $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
            $orders[] = $order;
            file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            echo json_encode(['ok' => true, 'order_id' => $orderId, 'total' => $total]);
            exit;

        case 'complete_payment':
            $orderId = $_POST['order_id'] ?? '';
            $paymentMethod = $_POST['payment_method'] ?? '';
            $paymentId = $_POST['payment_id'] ?? '';

            $ordersFile = $_ms_dataBase . 'orders.json';
            $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
            $found = false;
            foreach ($orders as &$o) {
                if ($o['order_id'] === $orderId) {
                    $o['status'] = 'completed';
                    $o['payment_method'] = $paymentMethod;
                    $o['payment_id'] = $paymentId;
                    $o['updated_at'] = date('c');
                    $found = true;
                    break;
                }
            }
            unset($o);
            if ($found) {
                file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $_SESSION['mystore_cart'] = [];
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Order not found']);
            }
            exit;

        case 'log_error':
            $error = json_decode($_POST['error'] ?? '{}', true);
            if ($error) {
                $logFile = $_ms_dataBase . 'error_log.json';
                $log = is_file($logFile) ? json_decode(file_get_contents($logFile), true) : [];
                $log[] = $error;
                if (count($log) > 500) $log = array_slice($log, -500);
                @file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
            }
            echo json_encode(['ok' => true]);
            exit;

        case 'send_verification':
            require_once dirname(__DIR__) . '/api/mystore-checkout.php';
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            if (!$email) { echo json_encode(['ok' => false, 'error' => 'Invalid email']); exit; }
            $spam = mystore_check_spam_email($email);
            if (count($spam) >= 2) { echo json_encode(['ok' => false, 'error' => 'This email address appears invalid']); exit; }
            if (session_status() === PHP_SESSION_NONE) session_start();
            $v = $_SESSION['mystore_email_verify'] ?? null;
            if ($v && $v['email'] === $email && (time() - ($v['sent_at'] ?? 0)) < 60) {
                echo json_encode(['ok' => false, 'error' => 'Please wait before requesting another code']);
                exit;
            }
            $sent = mystore_send_verification($email);
            if ($sent) {
                $_SESSION['mystore_email_verify']['sent_at'] = time();
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Failed to send verification email']);
            }
            exit;

        case 'verify_code':
            require_once dirname(__DIR__) . '/api/mystore-checkout.php';
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
            $result = mystore_verify_code($email, $code);
            echo json_encode(['ok' => $result['ok'], 'error' => $result['error'] ?? null]);
            exit;
    }
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style id="ms-store-css">
/* MyStore — Self-contained storefront CSS */
.ms-wrap{max-width:1200px;margin:0 auto;padding:24px 16px;font-family:system-ui,-apple-system,sans-serif;color:#e0e0e0;box-sizing:border-box}
.ms-wrap *{box-sizing:border-box}
.ms-header{text-align:center;margin-bottom:40px}
.ms-header h1{font-size:2.5rem;font-weight:800;color:#f59e0b;margin:0 0 8px}
.ms-header p{font-size:1.1rem;color:#888;margin:0 0 20px}
.ms-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer;border:1px solid rgba(255,255,255,0.15);background:none;color:#ccc;font-family:inherit;transition:all 0.15s}
.ms-btn:hover{border-color:rgba(255,255,255,0.3);background:rgba(255,255,255,0.05)}
.ms-btn-primary{background:#f59e0b;color:#000;border-color:#f59e0b}
.ms-btn-primary:hover{background:#fbbf24}
.ms-cats{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-bottom:28px}
.ms-cats .ms-btn.active{background:rgba(245,158,11,0.15);color:#f59e0b;border-color:#f59e0b}
.ms-grid{display:grid;grid-template-columns:repeat(var(--ms-cols,4),1fr);gap:20px}
@media(max-width:900px){.ms-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:500px){.ms-grid{grid-template-columns:1fr}}
.ms-card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:12px;overflow:hidden;transition:transform 0.2s,box-shadow 0.2s,border-color 0.2s;cursor:pointer}
.ms-card:hover{transform:translateY(-3px);box-shadow:0 0 20px rgba(59,130,246,0.25),0 8px 30px rgba(59,130,246,0.1);border-color:rgba(59,130,246,0.4)}
.ms-card-inner{padding:14px}
.ms-card-img{aspect-ratio:1;background:#1a1a2e;border-radius:8px;overflow:hidden;position:relative;cursor:pointer;margin-bottom:12px}
.ms-card-img img{width:100%;height:100%;object-fit:cover;transition:transform 0.3s}
.ms-card:hover .ms-card-img img{transform:scale(1.05)}
.ms-card-name{font-size:0.88rem;font-weight:600;color:#f0f0f0;margin-bottom:2px;cursor:pointer}
.ms-card-price{font-size:1.05rem;font-weight:800;color:#f59e0b;white-space:nowrap}
.ms-card-cat{font-size:0.72rem;color:#888;margin-bottom:6px}
.ms-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px}
.ms-card-vars{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:8px}
.ms-card-vars span{font-size:0.65rem;padding:2px 6px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:4px;color:#999}
.ms-card-actions{display:none}
.ms-empty{display:none;text-align:center;padding:48px;color:#666;font-size:1rem}
.ms-empty.visible{display:block}
.ms-pager{display:flex;justify-content:center;align-items:center;gap:6px;margin-top:28px}
.ms-pager-btn{padding:6px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:6px;background:none;color:#ccc;cursor:pointer;font-size:0.82rem;font-family:inherit;transition:all 0.15s}
.ms-pager-btn:hover{border-color:#f59e0b;color:#f59e0b}
.ms-pager-btn.active{background:#f59e0b;color:#000;border-color:#f59e0b;font-weight:700}
.ms-pager-btn:disabled{opacity:0.3;cursor:default}
.ms-pager-info{font-size:0.75rem;color:#666;margin:0 8px}

/* Modal overlay */
.ms-modal{display:none;position:fixed;inset:0;z-index:9990;background:rgba(0,0,0,0.75);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);overflow-y:auto}
.ms-modal.open{display:flex;align-items:flex-start;justify-content:center;padding:32px 16px}
.ms-modal-box{background:#13151a;border:1px solid rgba(255,255,255,0.08);border-radius:12px;width:100%;max-width:800px;margin-top:20px;overflow:hidden}
.ms-modal-box.ms-modal-sm{max-width:480px}
.ms-modal-box.ms-modal-md{max-width:640px}
.ms-modal-pad{padding:24px}
.ms-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.ms-modal-head h2{font-size:1.4rem;font-weight:700;color:#f0f0f0;margin:0}
.ms-close{background:none;border:1px solid rgba(255,255,255,0.15);color:#888;width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center}
.ms-close:hover{color:#f59e0b;border-color:#f59e0b}
.ms-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
@media(max-width:768px){.ms-modal-grid{grid-template-columns:1fr}}
.ms-gallery-main{border-radius:8px;overflow:hidden;background:#1a1a2e;aspect-ratio:1}
.ms-gallery-main img{width:100%;height:100%;object-fit:cover}
.ms-thumbs{display:flex;gap:6px;margin-top:8px;overflow-x:auto}
.ms-thumb{width:56px;height:56px;border-radius:6px;overflow:hidden;border:2px solid transparent;cursor:pointer;flex-shrink:0}
.ms-thumb.active{border-color:#f59e0b}
.ms-thumb img{width:100%;height:100%;object-fit:cover}
.ms-modal-cat{color:#f59e0b;font-size:0.85rem;font-weight:600}
.ms-modal-price{font-size:1.5rem;font-weight:800;color:#f59e0b}
.ms-modal-desc{color:#aaa;font-size:0.88rem;line-height:1.6;margin-top:8px}
.ms-var-group{margin-bottom:12px}
.ms-var-group label{display:block;font-size:0.78rem;font-weight:600;color:#999;margin-bottom:4px}
.ms-select{width:100%;padding:8px 12px;border:1px solid #333;border-radius:6px;background:#1a1a2e;color:#f0f0f0;font-size:0.88rem;font-family:inherit}
.ms-select:focus{outline:none;border-color:#f59e0b}

/* Cart sidebar */
#cart-sidebar{position:fixed;top:0;right:0;height:100%;width:380px;max-width:100%;background:rgba(13,15,20,0.97);backdrop-filter:blur(8px);border-left:1px solid rgba(255,255,255,0.08);z-index:9980;transform:translateX(100%);transition:transform 0.3s}
#cart-sidebar.ms-open{transform:translateX(0)}
.ms-cart-inner{padding:20px;height:100%;display:flex;flex-direction:column}
.ms-cart-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.ms-cart-head h2{font-size:1.1rem;font-weight:700;color:#f0f0f0;margin:0}
#cart-items{flex:1;overflow-y:auto}
#cart-items>div{display:flex;gap:10px;padding:10px;background:rgba(255,255,255,0.03);border-radius:8px;margin-bottom:8px}
.ms-ci-img{width:56px;height:56px;object-fit:cover;border-radius:6px;flex-shrink:0}
.ms-ci-info{flex:1;min-width:0}
.ms-ci-name{font-size:0.82rem;font-weight:600;color:#f0f0f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ms-ci-price{font-size:0.72rem;color:#888}
.ms-ci-vars{font-size:0.68rem;color:#666}
.ms-ci-qty{display:flex;align-items:center;gap:4px}
.ms-qty-btn{width:26px;height:26px;border:1px solid rgba(255,255,255,0.15);background:none;color:#ccc;border-radius:4px;cursor:pointer;font-size:0.75rem;display:flex;align-items:center;justify-content:center}
.ms-qty-btn:hover{border-color:#f59e0b;color:#f59e0b}
.ms-qty-btn.ms-remove{color:#dc2626}
.ms-qty-btn.ms-remove:hover{border-color:#dc2626}
.ms-cart-empty{flex:1;display:flex;align-items:center;justify-content:center;color:#555;text-align:center;font-size:0.9rem}
.ms-cart-footer{border-top:1px solid rgba(255,255,255,0.08);padding-top:12px;margin-top:auto}
.ms-cart-row{display:flex;justify-content:space-between;font-size:0.82rem;color:#888;margin-bottom:4px}
.ms-cart-total{display:flex;justify-content:space-between;font-size:1.05rem;font-weight:800;color:#f0f0f0;border-top:1px solid rgba(255,255,255,0.08);padding-top:8px;margin:8px 0 12px}
.ms-hidden{display:none!important}

/* Checkout form */
.ms-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.ms-form-row-3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px;margin-bottom:12px}
.ms-form-group{margin-bottom:12px}
.ms-form-group label{display:block;font-size:0.75rem;color:#888;margin-bottom:4px}
.ms-input{width:100%;padding:8px 12px;border:1px solid #333;border-radius:6px;background:#1a1a2e;color:#f0f0f0;font-size:0.88rem;font-family:inherit}
.ms-input:focus{outline:none;border-color:#f59e0b}
.ms-verify-box{padding:14px;border-radius:8px;border:1px solid rgba(255,255,255,0.08);background:rgba(255,255,255,0.02);margin-bottom:16px}
.ms-section-title{font-size:1rem;font-weight:600;color:#f0f0f0;margin:20px 0 12px}
.ms-divider{border-top:1px solid rgba(255,255,255,0.08);padding-top:12px;margin-top:16px}
.ms-success-icon{font-size:4rem;color:#22c55e;margin-bottom:12px}
.ms-text-green{color:#22c55e}
.ms-text-red{color:#dc2626}
.ms-text-amber{color:#f59e0b}
.ms-text-muted{color:#888}
.ms-text-sm{font-size:0.82rem}
.ms-text-xs{font-size:0.72rem}
.ms-text-center{text-align:center}
.ms-mb-4{margin-bottom:16px}

@media(max-width:640px){.ms-grid{grid-template-columns:repeat(2,1fr);gap:10px}.ms-header h1{font-size:1.8rem}.ms-form-row,.ms-form-row-3{grid-template-columns:1fr}}
</style>

<?php if ($cardBg || $pageBg || $accent || $bgOpacity < 100): ?>
<style id="ms-theme-overrides">
<?php if ($pageBg || $bgOpacity < 100):
    $bg = $pageBg ?: '#0f1114';
    if ($bgOpacity < 100) {
        $r = hexdec(substr($bg, 1, 2)); $g = hexdec(substr($bg, 3, 2)); $b = hexdec(substr($bg, 5, 2));
        $bgVal = "rgba($r,$g,$b," . ($bgOpacity / 100) . ")";
    } else {
        $bgVal = htmlspecialchars($bg);
    }
?>.ms-wrap{background:<?= $bgVal ?>;border-radius:12px;padding:24px 16px}<?php endif; ?>
<?php if ($cardBg): ?>.ms-card{background:<?= htmlspecialchars($cardBg) ?>}.ms-card-img{background:<?= htmlspecialchars($cardBg) ?>}.ms-modal-box{background:<?= htmlspecialchars($cardBg) ?>}<?php endif; ?>
<?php if ($accent): ?>.ms-btn-primary{background:<?= htmlspecialchars($accent) ?>;border-color:<?= htmlspecialchars($accent) ?>}.ms-header h1,.ms-modal-cat,.ms-cats .ms-btn.active,.ms-pager-btn.active,.ms-thumb.active{color:<?= htmlspecialchars($accent) ?>;border-color:<?= htmlspecialchars($accent) ?>}.ms-pager-btn.active,.ms-cats .ms-btn.active{background:<?= htmlspecialchars($accent) ?>20}<?php endif; ?>
<?php
$titleColor = $_ms_settings['store_title_color'] ?? '';
$titleSize = $_ms_settings['store_title_size'] ?? '';
$priceColor = $_ms_settings['store_price_color'] ?? $accent;
$priceSize = $_ms_settings['store_price_size'] ?? '';
$descColor = $_ms_settings['store_desc_color'] ?? '';
$metaColor = $_ms_settings['store_meta_color'] ?? '';
$storeFont = $_ms_settings['store_font'] ?? '';
?>
<?php if ($titleColor): ?>.ms-card-name{color:<?= htmlspecialchars($titleColor) ?>}<?php endif; ?>
<?php if ($titleSize): ?>.ms-card-name{font-size:<?= htmlspecialchars($titleSize) ?>rem}<?php endif; ?>
<?php if ($priceColor): ?>.ms-card-price,.ms-modal-price{color:<?= htmlspecialchars($priceColor) ?>}<?php endif; ?>
<?php if ($priceSize): ?>.ms-card-price{font-size:<?= htmlspecialchars($priceSize) ?>rem}<?php endif; ?>
<?php if ($descColor): ?>.ms-card-cat{color:<?= htmlspecialchars($descColor) ?>}<?php endif; ?>
<?php if ($metaColor): ?>.ms-card-vars span{color:<?= htmlspecialchars($metaColor) ?>}<?php endif; ?>
<?php if ($storeFont && $storeFont !== 'system'):
    $ff = $storeFont === 'inherit' ? 'inherit' : htmlspecialchars($storeFont) . ',sans-serif';
?>.ms-wrap{font-family:<?= $ff ?>}<?php endif; ?>
</style>
<?php endif; ?>
<style>
#ms-minicart:hover{border-color:rgba(245,158,11,0.4);box-shadow:0 4px 20px rgba(245,158,11,0.15)}
#ms-minicart.ms-mc-bump{animation:msBump 0.3s ease}
@keyframes msBump{0%{transform:scale(1)}50%{transform:scale(1.12)}100%{transform:scale(1)}}
</style>

<div class="ms-wrap">
    <div class="ms-header">
        <h1><?= htmlspecialchars($storeName) ?></h1>
        <?php if ($storeDesc): ?><p><?= htmlspecialchars($storeDesc) ?></p><?php endif; ?>
        <div style="display:inline-flex;align-items:center;gap:12px">
            <button onclick="msHelpToggle()" style="width:32px;height:32px;border-radius:50%;background:#dc2626;color:#fff;border:none;cursor:pointer;font-weight:900;font-size:0.9rem;box-shadow:0 2px 8px rgba(220,38,38,0.3);transition:transform 0.15s" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform=''">?</button>
            <button id="cart-toggle" class="ms-btn ms-btn-primary" onclick="toggleCart()">
                <i class="fas fa-shopping-cart"></i> Cart (<span id="cart-count">0</span>)
            </button>
            <div id="ms-minicart" onclick="toggleCart()" style="display:none;align-items:center;gap:8px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:6px 12px;cursor:pointer;transition:box-shadow 0.2s,border-color 0.2s">
                <span id="mc-count" style="font-size:0.78rem;font-weight:700;color:#f0f0f0">0</span>
                <span style="color:#444;font-size:0.7rem">items</span>
                <span style="color:#444">|</span>
                <span id="mc-total" style="font-size:0.85rem;font-weight:800;color:#f59e0b">$0.00</span>
            </div>
        </div>
    </div>

    <?php if (empty($products)): ?>
    <div class="ms-text-center" style="padding:60px 0;color:#555"><i class="fas fa-store" style="font-size:3rem;margin-bottom:12px;display:block"></i><p style="font-size:1.1rem">Store coming soon</p></div>
    <?php else: ?>

    <?php if (count($categories) > 1): ?>
    <div class="ms-cats">
        <button class="ms-btn active" data-category="all" onclick="msFilter(this)">All Products</button>
        <?php foreach ($categories as $cat): ?>
        <button class="ms-btn" data-category="<?= htmlspecialchars(strtolower($cat)) ?>" onclick="msFilter(this)"><?= htmlspecialchars($cat) ?></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="ms-grid" id="products-grid" style="--ms-cols:<?= $gridColumns ?>">
        <?php foreach ($displayProducts as $product): ?>
        <div class="ms-card product-card" data-category="<?= htmlspecialchars(strtolower($product['category'] ?? '')) ?>" onclick="showProductModal('<?= htmlspecialchars($product['sku']) ?>')">
            <div class="ms-card-inner">
                <div class="ms-card-img">
                    <?php if (!empty($product['image'])): ?>
                    <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" onerror="this.style.display='none'">
                    <?php endif; ?>
                </div>
                <div class="ms-card-top">
                    <div class="ms-card-name" onclick="showProductModal('<?= htmlspecialchars($product['sku']) ?>')"><?= htmlspecialchars($product['name']) ?></div>
                    <div class="ms-card-price">$<?= htmlspecialchars($product['price']) ?></div>
                </div>
                <?php if (!empty($product['category'])): ?><div class="ms-card-cat"><?= htmlspecialchars($product['category']) ?></div><?php endif; ?>
                <?php if (!empty($product['variations']['groups'])): ?>
                <div class="ms-card-vars">
                    <?php foreach ($product['variations']['groups'] as $group): ?>
                    <span><?= htmlspecialchars($group['name']) ?>: <?= count($group['options']) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="ms-card-actions">
<?php $hasVariations = !empty($product['variations']['groups']); ?>
                    <?php if ($hasVariations): ?>
                    <button class="ms-btn ms-btn-primary" onclick="showProductModal('<?= htmlspecialchars($product['sku']) ?>')"><i class="fas fa-list"></i> Select Options</button>
                    <?php else: ?>
                    <button class="ms-btn ms-btn-primary" onclick="addToCart('<?= htmlspecialchars($product['sku']) ?>')"><i class="fas fa-cart-plus"></i> Add to Cart</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="ms-pager">
        <?php if ($currentPage > 1): ?><a href="?p=<?= htmlspecialchars($_GET['p'] ?? '') ?>&shop_page=<?= $currentPage - 1 ?>" class="ms-pager-btn">&laquo; Prev</a><?php endif; ?>
        <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
        <a href="?p=<?= htmlspecialchars($_GET['p'] ?? '') ?>&shop_page=<?= $pg ?>" class="ms-pager-btn <?= $pg === $currentPage ? 'active' : '' ?>"><?= $pg ?></a>
        <?php endfor; ?>
        <?php if ($currentPage < $totalPages): ?><a href="?p=<?= htmlspecialchars($_GET['p'] ?? '') ?>&shop_page=<?= $currentPage + 1 ?>" class="ms-pager-btn">Next &raquo;</a><?php endif; ?>
        <span class="ms-pager-info"><?= $totalProducts ?> products</span>
    </div>
    <?php endif; ?>
    <div id="empty-state" class="ms-empty">No products found in this category.</div>
    <?php endif; ?>
</div>

<!-- Product Detail Modal -->
<div id="product-modal" class="ms-modal">
    <div class="ms-modal-box"><div class="ms-modal-pad">
        <div class="ms-modal-head"><h2 id="modal-title"></h2><button class="ms-close" onclick="msCloseModal('product-modal')">&times;</button></div>
        <div class="ms-modal-grid">
            <div>
                <div class="ms-gallery-main"><img id="modal-main-image" src="" alt=""></div>
                <div class="ms-thumbs" id="modal-thumbnails"></div>
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span id="modal-category" class="ms-modal-cat"></span>
                    <span id="modal-price" class="ms-modal-price"></span>
                </div>
                <p id="modal-description" class="ms-modal-desc"></p>
                <div id="modal-variations" style="margin-top:16px"></div>
                <!-- Quantity + Add to Cart -->
                <div style="margin-top:20px">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                        <label style="font-size:0.78rem;color:#999;font-weight:600">Qty:</label>
                        <div style="display:flex;align-items:center;gap:0;border:1px solid rgba(255,255,255,0.15);border-radius:8px;overflow:hidden">
                            <button type="button" onclick="msModalQty(-1)" style="width:36px;height:36px;background:rgba(255,255,255,0.05);border:none;color:#ccc;cursor:pointer;font-size:1.1rem;font-weight:bold">-</button>
                            <input type="number" id="modal-qty" value="1" min="1" max="99" onchange="msModalQtyUpdate()" style="width:48px;height:36px;text-align:center;background:none;border:none;border-left:1px solid rgba(255,255,255,0.1);border-right:1px solid rgba(255,255,255,0.1);color:#f0f0f0;font-size:1rem;font-weight:700;font-family:inherit">
                            <button type="button" onclick="msModalQty(1)" style="width:36px;height:36px;background:rgba(255,255,255,0.05);border:none;color:#ccc;cursor:pointer;font-size:1.1rem;font-weight:bold">+</button>
                        </div>
                        <span id="modal-line-total" style="font-size:1.1rem;font-weight:800;color:#f59e0b;margin-left:auto"></span>
                    </div>
                    <button id="modal-add-to-cart" class="ms-btn ms-btn-primary" style="width:100%;justify-content:center;padding:12px" onclick="addToCartFromModal()"><i class="fas fa-cart-plus"></i> Add to Cart</button>
                    <!-- Social Share -->
                    <div id="ms-share-row" style="display:flex;gap:8px;margin-top:12px;justify-content:center"></div>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- Shopping Cart Sidebar -->
<div id="cart-sidebar">
    <div class="ms-cart-inner">
        <div class="ms-cart-head"><h2>Shopping Cart</h2><button class="ms-close" onclick="toggleCart()">&times;</button></div>
        <div id="cart-items"></div>
        <div id="cart-empty" class="ms-cart-empty"><div><i class="fas fa-shopping-cart" style="font-size:3rem;margin-bottom:8px;display:block"></i>Your cart is empty</div></div>
        <div id="cart-footer" class="ms-cart-footer">
            <div class="ms-cart-row"><span>Subtotal:</span><span id="cart-subtotal">$0.00</span></div>
            <div class="ms-cart-row"><span>Tax (<?= $taxRate ?>%):</span><span id="cart-tax">$0.00</span></div>
            <div class="ms-cart-row"><span>Shipping:</span><span id="cart-shipping">$0.00</span></div>
            <div class="ms-cart-total"><span>Total:</span><span id="cart-total">$0.00</span></div>
            <button class="ms-btn ms-btn-primary" style="width:100%;justify-content:center;padding:12px" onclick="goToCheckout()"><i class="fas fa-lock"></i> Secure Checkout</button>
        </div>
    </div>
</div>

<!-- Checkout Modal -->
<div id="checkout-modal" class="ms-modal">
    <div class="ms-modal-box ms-modal-md"><div class="ms-modal-pad">
        <div class="ms-modal-head"><h2><i class="fas fa-lock ms-text-amber"></i> Checkout</h2><button class="ms-close" onclick="msCloseModal('checkout-modal')">&times;</button></div>
        <div class="ms-section-title">Customer Information</div>
        <div class="ms-form-group"><label>Full Name *</label><input type="text" id="co-name" class="ms-input" required></div>

        <!-- Email + Verify inline -->
        <div class="ms-verify-box" id="co-verify-section" style="border:2px solid rgba(59,130,246,0.3);box-shadow:0 0 12px rgba(59,130,246,0.15)">
            <div id="co-verify-prompt">
                <label style="font-size:0.75rem;color:#888;margin-bottom:4px;display:block">Email Address *</label>
                <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px">
                    <input type="email" id="co-email" class="ms-input" required placeholder="your@email.com" style="flex:1">
                    <button type="button" class="ms-btn" onclick="coSendVerify()" style="white-space:nowrap;box-shadow:0 0 10px rgba(59,130,246,0.3);border-color:rgba(59,130,246,0.5);color:#60a5fa"><i class="fas fa-envelope"></i> Verify Email</button>
                </div>
                <p class="ms-text-xs ms-text-muted" style="margin:0"><i class="fas fa-shield-alt ms-text-amber"></i> We'll send a 6-digit code to confirm your email before checkout.</p>
            </div>
            <div id="co-verify-input" style="display:none">
                <label style="font-size:0.75rem;color:#888;margin-bottom:4px;display:block">Email Address *</label>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
                    <input type="email" id="co-email-readonly" class="ms-input" readonly style="flex:1;opacity:0.6">
                </div>
                <p class="ms-text-sm" style="margin:0 0 8px;color:#60a5fa;font-weight:600"><i class="fas fa-envelope-open"></i> Code sent! Check your inbox and enter it below:</p>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="text" id="co-verify-code" class="ms-input" maxlength="6" placeholder="000000" style="width:150px;text-align:center;font-weight:bold;font-size:1.2rem;letter-spacing:0.25em;border-color:rgba(59,130,246,0.5)">
                    <button class="ms-btn ms-btn-primary" onclick="coSubmitVerify()">Verify</button>
                    <button class="ms-btn ms-text-xs" onclick="coSendVerify()" style="color:#888">Resend</button>
                </div>
                <p id="co-verify-error" class="ms-text-red ms-text-sm" style="display:none;margin-top:6px"></p>
            </div>
            <div id="co-verified" style="display:none">
                <label style="font-size:0.75rem;color:#888;margin-bottom:4px;display:block">Email Address *</label>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                    <input type="email" id="co-email-done" class="ms-input" readonly style="flex:1;opacity:0.6">
                    <span class="ms-text-green" style="font-weight:700;white-space:nowrap"><i class="fas fa-check-circle"></i> Verified</span>
                </div>
            </div>
        </div>

        <div class="ms-form-group"><label>Phone</label><input type="tel" id="co-phone" class="ms-input"></div>
        <div class="ms-section-title">Shipping Address</div>
        <div class="ms-form-group"><label>Street Address *</label><input type="text" id="co-street" class="ms-input" required placeholder="123 Main Street"></div>
        <div class="ms-form-row-3">
            <div class="ms-form-group"><label>City *</label><input type="text" id="co-city" class="ms-input" required></div>
            <div class="ms-form-group"><label>State *</label><input type="text" id="co-state" class="ms-input" maxlength="2" required></div>
            <div class="ms-form-group"><label>ZIP *</label><input type="text" id="co-zip" class="ms-input" required></div>
        </div>
        <div class="ms-divider"><div id="co-order-summary"></div></div>
        <div id="co-payment-area" style="margin-top:16px">
            <?php if ($paypalClientId): ?><div id="paypal-button-container" class="ms-mb-4"></div><?php endif; ?>
            <?php if ($stripePublicKey): ?>
            <div class="ms-mb-4">
                <div id="stripe-card-element" style="padding:12px;border:1px solid #333;border-radius:6px;background:#1a1a2e;margin-bottom:10px"></div>
                <button id="stripe-pay-btn" class="ms-btn ms-btn-primary" style="width:100%;justify-content:center;padding:12px" onclick="stripePayNow()"><i class="fas fa-credit-card"></i> Pay with Card</button>
            </div>
            <?php endif; ?>
            <?php if (!$paypalClientId && !$stripePublicKey): ?>
            <div class="ms-text-center ms-text-muted" style="padding:16px"><i class="fas fa-exclamation-triangle ms-text-amber"></i> Payment processing is being configured. Please check back soon.</div>
            <?php endif; ?>
        </div>
    </div></div>
</div>

<!-- Order Success Modal -->
<div id="success-modal" class="ms-modal">
    <div class="ms-modal-box ms-modal-sm"><div class="ms-modal-pad ms-text-center">
        <div class="ms-success-icon"><i class="fas fa-check-circle"></i></div>
        <h2 style="font-size:1.5rem;font-weight:700;color:#f0f0f0;margin:0 0 8px">Order Confirmed!</h2>
        <p id="success-order-id" class="ms-text-muted ms-text-sm"></p>
        <p class="ms-text-muted" style="margin:8px 0 20px">Thank you for your purchase. You'll receive a confirmation email shortly.</p>
        <button class="ms-btn ms-btn-primary" onclick="msCloseModal('success-modal');location.reload()">Continue Shopping</button>
    </div></div>
</div>

<?php
// Get CSRF token — must happen before any script output
if (session_status() === PHP_SESSION_NONE) @session_start();
if (empty($_SESSION['ms_csrf'])) $_SESSION['ms_csrf'] = bin2hex(random_bytes(16));
?>
<script>
<?php
// Whitelist product fields — don't leak internal data
$safeProducts = array_map(function($p) {
    return ['sku'=>$p['sku']??'','name'=>$p['name']??'','price'=>$p['price']??'0','image'=>$p['image']??'','images'=>$p['images']??[],'category'=>$p['category']??'','desc'=>$p['desc']??'','variations'=>$p['variations']??['groups'=>[]]];
}, $displayProducts);
?>
<?php
// FB App ID for share dialog
$_msFbAppId = '';
$_msFbConfig = SITE_ROOT . '/admin/data/FacebookEvents/config.json';
if (is_file($_msFbConfig)) {
    $_msFbCfg = json_decode(file_get_contents($_msFbConfig), true) ?: [];
    $_msFbAppId = $_msFbCfg['fb_app_id'] ?? '';
}
?>
<?php if ($_msFbAppId): ?>var MS_FB_APP_ID = <?= json_encode($_msFbAppId) ?>;<?php endif; ?>

const MS = {
    products: <?= json_encode(array_values($safeProducts)) ?>,
    csrfToken: <?= json_encode($_SESSION['ms_csrf']) ?>,
    taxRate: <?= $taxRate ?>,
    shippingCost: <?= $shippingCost ?>,
    freeShipThreshold: <?= $freeShipThreshold ?>,
    shipping: <?= json_encode(mystore_shipping_config($_ms_settings)) ?>,
    paypalClientId: <?= json_encode($paypalClientId) ?>,
    stripePublicKey: <?= json_encode($stripePublicKey) ?>,
};

// Canonical client-side shipping calculator — mirrors PHP mystore_calc_shipping().
// Driven by MS.shipping so the cart preview and the PayPal charge match the server.
function msCalcShipping(quantity, subtotal) {
    quantity = parseInt(quantity, 10) || 0;
    subtotal = parseFloat(subtotal) || 0;
    if (quantity <= 0 || subtotal <= 0) return 0;
    var s = MS.shipping || {};
    var free = s.freeShipping || {};
    if (free.enabled) {
        if (free.type === 'always') return 0;
        var thr = parseFloat(free.threshold) || 0;
        if (thr > 0 && subtotal >= thr) return 0;
    }
    var r2 = function(n){ return Math.round((parseFloat(n) || 0) * 100) / 100; };
    if (s.mode !== 'per_quantity') return r2(s.flatRate);
    var clean = {};
    (s.tiers || []).forEach(function(t){ var q = parseInt(t.qty, 10); if (q >= 1) clean[q] = r2(t.rate); });
    var qtys = Object.keys(clean).map(Number).sort(function(a, b){ return a - b; });
    if (!qtys.length) return r2(s.flatRate);
    var minQ = qtys[0], maxQ = qtys[qtys.length - 1];
    if (quantity <= minQ) return clean[minQ];
    if (quantity <= maxQ) {
        var rate = clean[minQ];
        for (var i = 0; i < qtys.length; i++) { if (qtys[i] <= quantity) rate = clean[qtys[i]]; else break; }
        return rate;
    }
    if (s.aboveMax === 'increment') return r2(clean[maxQ] + (parseFloat(s.perAdditional) || 0) * (quantity - maxQ));
    return clean[maxQ];
}

let cart = [];
let currentProduct = null;
let emailVerified = false;

// ── Store Help Modal ──
function msHelpToggle() { msOpenModal('ms-help-modal'); }
function msSubmitHelp() {
    var name = document.getElementById('ms-help-name').value.trim();
    var email = document.getElementById('ms-help-email').value.trim();
    var phone = document.getElementById('ms-help-phone').value.trim();
    var message = document.getElementById('ms-help-message').value.trim();
    if (!name || !email || !message) { showToast('Please fill in name, email, and message', 'error'); return; }
    var status = document.getElementById('ms-help-status');
    status.style.display = 'block'; status.style.color = '#888'; status.textContent = 'Sending...';
    var fd = new FormData();
    fd.append('ms_action', 'submit_help');
    fd.append('name', name); fd.append('email', email); fd.append('phone', phone); fd.append('message', message);
    fetch('/admin/modules/MyStore/api/store-api.php', {method:'POST', body:fd})
        .then(function(r){return r.json()})
        .then(function(d) {
            if (d.ok) {
                status.style.color = '#22c55e'; status.textContent = 'Message sent! We\'ll get back to you soon.';
                document.getElementById('ms-help-message').value = '';
                setTimeout(function() { msCloseModal('ms-help-modal'); status.style.display = 'none'; }, 2500);
            } else {
                status.style.color = '#dc2626'; status.textContent = d.error || 'Failed to send';
            }
        }).catch(function() { status.style.color = '#dc2626'; status.textContent = 'Network error — try again'; });
}

// ── XSS helper ──
function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// ── Cart ──
function loadCart() { try { cart = JSON.parse(localStorage.getItem('mystore_cart') || '[]'); } catch(e) { cart = []; } }
function saveCart() {
    localStorage.setItem('mystore_cart', JSON.stringify(cart));
    // Notify the header cart-badge (and any other listeners). The 'storage'
    // event only fires on OTHER tabs, so dispatch a custom event for in-tab.
    try { window.dispatchEvent(new Event('mystore:cart-changed')); } catch(e) {}
}

function addToCart(sku, variations, effectivePrice) {
    variations = variations || {};
    const product = MS.products.find(p => p.sku === sku);
    if (!product) return;
    const price = (effectivePrice !== undefined && effectivePrice !== null) ? parseFloat(effectivePrice) : parseFloat(product.price);
    const existing = cart.findIndex(i => i.sku === sku && JSON.stringify(i.variations) === JSON.stringify(variations));
    if (existing !== -1) { cart[existing].quantity++; }
    else { cart.push({sku, name: product.name, price, image: product.image || '', variations, quantity: 1}); }
    saveCart(); updateCartUI();
    showToast('Added to cart!', 'success');
}

function removeFromCart(idx) { cart.splice(idx, 1); saveCart(); updateCartUI(); }
function updateQty(idx, qty) { if (qty <= 0) removeFromCart(idx); else { cart[idx].quantity = qty; saveCart(); updateCartUI(); } }
function toggleCart() { document.getElementById('cart-sidebar').classList.toggle('ms-open'); }

function updateCartUI() {
    const count = cart.reduce((s, i) => s + i.quantity, 0);
    const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
    const tax = Math.round(subtotal * MS.taxRate) / 100;
    const shipping = msCalcShipping(count, subtotal);
    const total = subtotal + tax + shipping;

    document.getElementById('cart-count').textContent = count;
    // Update minicart
    var mc = document.getElementById('ms-minicart');
    if (mc) {
        document.getElementById('mc-count').textContent = count;
        document.getElementById('mc-total').textContent = '$' + (subtotal + tax + shipping).toFixed(2);
        mc.style.display = count > 0 ? 'flex' : 'none';
        mc.classList.remove('ms-mc-bump');
        void mc.offsetWidth;
        mc.classList.add('ms-mc-bump');
    }
    document.getElementById('cart-subtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('cart-tax').textContent = '$' + tax.toFixed(2);
    document.getElementById('cart-shipping').textContent = shipping === 0 && subtotal > 0 ? 'FREE' : '$' + shipping.toFixed(2);
    document.getElementById('cart-total').textContent = '$' + total.toFixed(2);

    const items = document.getElementById('cart-items');
    const empty = document.getElementById('cart-empty');
    const footer = document.getElementById('cart-footer');
    if (cart.length === 0) { empty.classList.remove('ms-hidden'); items.classList.add('ms-hidden'); footer.classList.add('ms-hidden'); }
    else {
        empty.classList.add('ms-hidden'); items.classList.remove('ms-hidden'); footer.classList.remove('ms-hidden');
        items.innerHTML = cart.map((item, i) => `
            <div>
                ${item.image ? `<img src="${esc(item.image)}" alt="" class="ms-ci-img" onerror="this.style.display='none'">` : ''}
                <div class="ms-ci-info">
                    <div class="ms-ci-name">${esc(item.name)}</div>
                    <div class="ms-ci-price">$${item.price.toFixed(2)}</div>
                    ${Object.keys(item.variations).length ? '<div class="ms-ci-vars">' + Object.entries(item.variations).map(([k,v])=>esc(k)+': '+esc(v)).join(', ') + '</div>' : ''}
                </div>
                <div class="ms-ci-qty">
                    <button onclick="updateQty(${i},${item.quantity-1})" class="ms-qty-btn">-</button>
                    <span style="color:#f0f0f0;font-size:0.82rem;width:20px;text-align:center">${item.quantity}</span>
                    <button onclick="updateQty(${i},${item.quantity+1})" class="ms-qty-btn">+</button>
                    <button onclick="removeFromCart(${i})" class="ms-qty-btn ms-remove">&times;</button>
                </div>
            </div>
        `).join('');
    }
}

// ── Product Modal ──
function showProductModal(sku) {
    const p = MS.products.find(x => x.sku === sku);
    if (!p) return;
    currentProduct = p;
    document.getElementById('modal-title').textContent = p.name;
    document.getElementById('modal-category').textContent = p.category || '';
    document.getElementById('modal-price').textContent = '$' + p.price;
    document.getElementById('modal-description').textContent = p.desc || '';
    const mainImg = document.getElementById('modal-main-image');
    mainImg.src = p.image || '';
    mainImg.alt = p.name;
    const thumbs = document.getElementById('modal-thumbnails');
    const imgs = (p.images && p.images.length) ? p.images : (p.image ? [p.image] : []);
    thumbs.innerHTML = imgs.map((img, i) => `
        <div class="ms-thumb ${i===0?'active':''}" onclick="document.getElementById('modal-main-image').src='${img}';document.querySelectorAll('.ms-thumb').forEach(t=>t.classList.remove('active'));this.classList.add('active')">
            <img src="${img}" alt="" onerror="this.parentElement.style.display='none'">
        </div>
    `).join('');
    const vars = document.getElementById('modal-variations');
    if (p.variations && p.variations.groups && p.variations.groups.length) {
        vars.innerHTML = p.variations.groups.map(g => `
            <div class="ms-var-group"><label>${g.name}</label>
            <select class="ms-select variation-select" data-variation="${g.name}" onchange="msUpdateVariantPrice()">
                <option value="">Select ${g.name}</option>
                ${g.options.map(o => {
                    const val   = (typeof o === 'object' && o !== null) ? o.value : o;
                    const fixed = (typeof o === 'object' && o !== null && o.price != null) ? parseFloat(o.price) : null;
                    const adj   = (typeof o === 'object' && o !== null && o.adj   != null) ? parseFloat(o.adj)   : null;
                    const base  = parseFloat(currentProduct.price) || 0;
                    const final = fixed !== null ? fixed : (adj !== null ? base + adj : null);
                    const label = final !== null ? val + ' \u2014 $' + final.toFixed(2) : val;
                    return `<option value="${val}" data-price="${final !== null ? final : ''}">${label}</option>`;
                }).join('')}
            </select></div>
        `).join('');
    } else { vars.innerHTML = ''; }
    // Reset qty and show line total
    document.getElementById('modal-qty').value = 1;
    msUpdateVariantPrice();

    // Social share buttons with product image
    var shareRow = document.getElementById('ms-share-row');
    var storeUrl = location.origin + '/page.php?p=my-store';
    var imgUrl = p.image || '';
    var shareText = p.name + ' — $' + p.price;

    function msShareFB() {
        var fbUrl;
        if (window.MS_FB_APP_ID) {
            fbUrl = 'https://www.facebook.com/dialog/feed?' +
                'app_id=' + MS_FB_APP_ID +
                '&display=popup' +
                '&link=' + encodeURIComponent(storeUrl) +
                '&picture=' + encodeURIComponent(imgUrl) +
                '&name=' + encodeURIComponent(p.name) +
                '&caption=' + encodeURIComponent('$' + p.price) +
                '&description=' + encodeURIComponent('Check out this product!') +
                '&redirect_uri=' + encodeURIComponent(location.origin);
        } else {
            fbUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(imgUrl) +
                '&quote=' + encodeURIComponent(shareText + ' — Shop: ' + storeUrl);
        }
        window.open(fbUrl, 'fb-share', 'width=600,height=500,scrollbars=yes');
    }

    shareRow.innerHTML =
        '<button onclick="msShareFB()" style="padding:7px 12px;border-radius:6px;background:#1877F2;color:#fff;font-size:.72rem;font-weight:700;cursor:pointer;border:none;font-family:inherit;display:flex;align-items:center;gap:4px"><svg width="13" height="13" fill="white" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>Share</button>' +
        '<a href="https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(storeUrl) + '" target="_blank" style="padding:7px 12px;border-radius:6px;background:#000;color:#fff;font-size:.72rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:4px"><svg width="13" height="13" fill="white" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>Post</a>' +
        '<button onclick="navigator.clipboard.writeText(\'' + p.name.replace(/'/g, '') + ' — $' + p.price + ' ' + storeUrl + '\').then(function(){showToast(\'Link copied!\',\'success\')})" style="padding:7px 12px;border-radius:6px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);color:#ccc;font-size:.72rem;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:4px">&#128279; Copy</button>';

    msOpenModal('product-modal');
}

// Returns the effective price: variant-specific price if selected, else base product price
function msGetVariantPrice() {
    if (!currentProduct) return 0;
    var basePrice = parseFloat(currentProduct.price);
    var variantPrice = null;
    document.querySelectorAll('.variation-select').forEach(function(s) {
        if (s.value) {
            var opt = s.options[s.selectedIndex];
            var dp = opt ? opt.getAttribute('data-price') : '';
            if (dp !== '' && dp !== null) variantPrice = parseFloat(dp);
        }
    });
    return variantPrice !== null ? variantPrice : basePrice;
}

function msUpdateVariantPrice() {
    var qty = Math.max(1, parseInt(document.getElementById('modal-qty').value) || 1);
    var price = msGetVariantPrice();
    document.getElementById('modal-price').textContent = '$' + price.toFixed(2);
    document.getElementById('modal-line-total').textContent = '$' + (price * qty).toFixed(2);
}

// Quantity controls in modal
function msModalQty(delta) {
    var inp = document.getElementById('modal-qty');
    var val = Math.max(1, Math.min(99, parseInt(inp.value) + delta));
    inp.value = val;
    msModalQtyUpdate();
}
function msModalQtyUpdate() {
    var qty = Math.max(1, parseInt(document.getElementById('modal-qty').value) || 1);
    document.getElementById('modal-qty').value = qty;
    msUpdateVariantPrice();
}

function addToCartFromModal() {
    if (!currentProduct) return;
    const vars = {};
    let allSelected = true;
    document.querySelectorAll('.variation-select').forEach(s => { if (s.value) vars[s.dataset.variation] = s.value; else allSelected = false; });
    if (document.querySelectorAll('.variation-select').length > 0 && !allSelected) { showToast('Please select all options', 'error'); return; }
    var qty = Math.max(1, parseInt(document.getElementById('modal-qty').value) || 1);
    var effectivePrice = msGetVariantPrice();
    for (var i = 0; i < qty; i++) { addToCart(currentProduct.sku, vars, effectivePrice); }
    msCloseModal('product-modal');
}

// ── Modal helpers ──
function msOpenModal(id) { document.getElementById(id).classList.add('open'); }
function msCloseModal(id) { document.getElementById(id).classList.remove('open'); }

// ── Toast (standalone — may not exist on public pages) ──
if (typeof showToast === 'undefined') {
    var _msErrorLog = [];
    window.showToast = function(msg, type) {
        type = type || 'info';
        var isError = type === 'error';
        var bg = isError ? '#dc2626' : (type === 'success' ? '#16a34a' : '#f59e0b');
        var color = isError ? '#fff' : '#000';
        var duration = isError ? 8000 : 3000;

        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;background:' + bg + ';color:' + color + ';padding:12px 20px;border-radius:10px;font-weight:600;font-size:0.85rem;box-shadow:0 4px 20px rgba(0,0,0,0.4);max-width:380px;display:flex;align-items:flex-start;gap:10px;font-family:system-ui,sans-serif';

        var textSpan = document.createElement('span');
        textSpan.textContent = msg;
        textSpan.style.flex = '1';
        t.appendChild(textSpan);

        if (isError) {
            // Copy button
            var copyBtn = document.createElement('button');
            copyBtn.innerHTML = '&#128203;';
            copyBtn.title = 'Copy error';
            copyBtn.style.cssText = 'background:none;border:none;color:' + color + ';cursor:pointer;font-size:1rem;padding:0;opacity:0.7;flex-shrink:0';
            copyBtn.onclick = function(e) {
                e.stopPropagation();
                navigator.clipboard.writeText(msg).then(function() { copyBtn.innerHTML = '&#10003;'; });
            };
            t.appendChild(copyBtn);

            // Log the error
            var entry = {time: new Date().toISOString(), error: msg, page: location.pathname};
            _msErrorLog.push(entry);
            // Send to server
            try {
                var fd = new FormData();
                fd.append('ms_action', 'log_error');
                fd.append('error', JSON.stringify(entry));
                fetch('/admin/modules/MyStore/api/store-api.php', {method:'POST', body:fd});
            } catch(e) {}
        }

        document.body.appendChild(t);
        setTimeout(function() {
            t.style.transition = 'opacity 0.3s';
            t.style.opacity = '0';
            setTimeout(function() { t.remove(); }, 300);
        }, duration);
    };
}

// ── Category Filter ──
function msFilter(btn) {
    const cat = btn.dataset.category;
    document.querySelectorAll('.ms-cats .ms-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    let visible = 0;
    document.querySelectorAll('.product-card').forEach(card => {
        const show = cat === 'all' || card.dataset.category === cat;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const es = document.getElementById('empty-state');
    if (es) es.classList.toggle('visible', visible === 0);
}

document.addEventListener('DOMContentLoaded', function() {
    loadCart();
    updateCartUI();
});

// ── Checkout ──
function goToCheckout() {
    if (cart.length === 0) { showToast('Cart is empty', 'error'); return; }
    toggleCart();
    // Build order summary
    const subtotal = cart.reduce((s, i) => s + i.price * i.quantity, 0);
    const count = cart.reduce((s, i) => s + i.quantity, 0);
    const tax = Math.round(subtotal * MS.taxRate) / 100;
    const shipping = msCalcShipping(count, subtotal);
    const total = subtotal + tax + shipping;
    coRenderSummary();
    msOpenModal('checkout-modal');
    initPaymentButtons();
}

function coRenderSummary() {
    var subtotal = cart.reduce(function(s,i){return s+i.price*i.quantity},0);
    var itemCount = cart.reduce(function(s,i){return s+i.quantity},0);
    var tax = Math.round(subtotal * MS.taxRate) / 100;
    var shipping = msCalcShipping(itemCount, subtotal);
    var total = subtotal + tax + shipping;

    if (cart.length === 0) {
        document.getElementById('co-order-summary').innerHTML = '<div style="text-align:center;padding:16px;color:#666;font-size:0.85rem">Cart is empty</div>';
        return;
    }

    document.getElementById('co-order-summary').innerHTML =
        '<div style="font-size:0.82rem;font-weight:700;color:#ccc;margin-bottom:10px">Order Summary (' + itemCount + ' items)</div>'
        + cart.map(function(i,idx) {
            var vars = Object.keys(i.variations||{}).length ? '<div style="font-size:0.68rem;color:#555">' + Object.entries(i.variations).map(function(e){return esc(e[0])+': '+esc(e[1])}).join(', ') + '</div>' : '';
            return '<div style="display:flex;align-items:center;gap:8px;font-size:0.82rem;color:#aaa;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04)">'
                + '<button onclick="coRemoveItem('+idx+')" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:0.9rem;padding:0 4px;flex-shrink:0" title="Remove">&times;</button>'
                + '<div style="flex:1;min-width:0"><div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(i.name) + ' <span style="color:#666">x' + i.quantity + '</span></div>' + vars + '</div>'
                + '<span style="font-weight:600;color:#ccc;white-space:nowrap">$' + (i.price*i.quantity).toFixed(2) + '</span>'
                + '</div>';
        }).join('')
        + '<div style="border-top:1px solid rgba(255,255,255,0.08);margin-top:10px;padding-top:10px">'
        + '<div style="display:flex;justify-content:space-between;font-size:0.82rem;color:#888;margin-bottom:4px"><span>Subtotal</span><span>$' + subtotal.toFixed(2) + '</span></div>'
        + '<div style="display:flex;justify-content:space-between;font-size:0.82rem;color:#888;margin-bottom:4px"><span>Tax</span><span>$' + tax.toFixed(2) + '</span></div>'
        + '<div style="display:flex;justify-content:space-between;font-size:0.82rem;color:#888;margin-bottom:4px"><span>Shipping</span><span>' + (shipping===0?'<span style="color:#22c55e;font-weight:600">FREE</span>':'$'+shipping.toFixed(2)) + '</span></div>'
        + '<div style="display:flex;justify-content:space-between;font-size:1rem;font-weight:800;color:#f0f0f0;margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,0.08)"><span>Total</span><span style="color:#f59e0b">$' + total.toFixed(2) + '</span></div>'
        + '</div>';
}

function coRemoveItem(idx) {
    cart.splice(idx, 1);
    saveCart();
    updateCartUI();
    coRenderSummary();
    if (cart.length === 0) {
        msCloseModal('checkout-modal');
        showToast('Cart is empty', 'info');
    } else {
        showToast('Item removed', 'info');
    }
}

// ── Email Verification ──
function coSendVerify() {
    var email = document.getElementById('co-email').value.trim();
    if (!email) { showToast('Enter your email first', 'error'); return; }

    // Disable button while sending
    var btns = document.querySelectorAll('#co-verify-prompt button, #co-verify-input button');
    btns.forEach(function(b) { b.disabled = true; });

    var fd = new FormData(); fd.append('ms_action', 'send_verification'); fd.append('email', email);
    fetch('/admin/modules/MyStore/api/store-api.php', {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(d) {
        btns.forEach(function(b) { b.disabled = false; });
        if (d.ok) {
            // Swap: hide prompt, show code input
            var prompt = document.getElementById('co-verify-prompt');
            var input = document.getElementById('co-verify-input');
            prompt.style.display = 'none';
            document.getElementById('co-email-readonly').value = email;
            document.getElementById('co-verify-code').value = '';
            input.style.display = '';
            document.getElementById('co-verify-code').focus();
            // Amber glow
            var section = document.getElementById('co-verify-section');
            section.style.borderColor = 'rgba(245,158,11,0.4)';
            section.style.boxShadow = '0 0 12px rgba(245,158,11,0.15)';
            showToast('Verification code sent — check your inbox!', 'success');
        } else {
            showToast(d.error || 'Failed to send code', 'error');
        }
    }).catch(function() {
        btns.forEach(function(b) { b.disabled = false; });
        showToast('Network error — try again', 'error');
    });
}
function coSubmitVerify() {
    var email = document.getElementById('co-email').value.trim();
    var code = document.getElementById('co-verify-code').value.trim();
    if (code.length < 6) { document.getElementById('co-verify-error').textContent = 'Enter the 6-digit code'; document.getElementById('co-verify-error').style.display = ''; return; }
    var fd = new FormData(); fd.append('ms_action', 'verify_code'); fd.append('email', email); fd.append('code', code);
    fetch('/admin/modules/MyStore/api/store-api.php', {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(d) {
        if (d.ok) {
            emailVerified = true;
            document.getElementById('co-verify-input').style.display = 'none';
            var done = document.getElementById('co-verified');
            done.style.display = '';
            document.getElementById('co-email-done').value = email;
            // Green glow
            var section = document.getElementById('co-verify-section');
            section.style.borderColor = 'rgba(34,197,94,0.4)';
            section.style.boxShadow = '0 0 12px rgba(34,197,94,0.15)';
            showToast('Email verified!', 'success');
        } else {
            document.getElementById('co-verify-error').textContent = d.error || 'Invalid code';
            document.getElementById('co-verify-error').style.display = '';
        }
    });
}

// ── Payment Integration ──
function initPaymentButtons() {
    // PayPal
    if (MS.paypalClientId && typeof paypal !== 'undefined') {
        document.getElementById('paypal-button-container').innerHTML = '';
        paypal.Buttons({
            onClick: function(data, actions) {
                if (!validateCheckout()) return actions.reject();
                return actions.resolve();
            },
            createOrder: function(data, actions) {
                var subtotal = cart.reduce(function(s, i) { return s + i.price * i.quantity; }, 0);
                var qty = cart.reduce(function(s, i) { return s + i.quantity; }, 0);
                var tax = Math.round(subtotal * MS.taxRate) / 100;
                var shipping = msCalcShipping(qty, subtotal);
                var total = (subtotal + tax + shipping).toFixed(2);
                return actions.order.create({
                    purchase_units: [{
                        amount: {
                            value: total,
                            currency_code: 'USD',
                            breakdown: {
                                item_total: {value: subtotal.toFixed(2), currency_code: 'USD'},
                                tax_total: {value: tax.toFixed(2), currency_code: 'USD'},
                                shipping: {value: shipping.toFixed(2), currency_code: 'USD'}
                            }
                        },
                        items: cart.map(function(i) {
                            return {
                                name: i.name.substring(0, 127),
                                unit_amount: {value: i.price.toFixed(2), currency_code: 'USD'},
                                quantity: String(i.quantity)
                            };
                        })
                    }]
                });
            },
            onApprove: function(data, actions) {
                return actions.order.capture().then(function(details) {
                    completeOrder('paypal', details.id);
                });
            },
            onError: function(err) {
                var msg = err && err.message ? err.message : (typeof err === 'string' ? err : JSON.stringify(err));
                showToast('PayPal error: ' + msg, 'error');
            }
        }).render('#paypal-button-container');
    }
}

function validateCheckout() {
    const name = document.getElementById('co-name').value.trim();
    const email = document.getElementById('co-email').value.trim();
    const street = document.getElementById('co-street').value.trim();
    const city = document.getElementById('co-city').value.trim();
    const state = document.getElementById('co-state').value.trim();
    const zip = document.getElementById('co-zip').value.trim();
    if (!name || !email || !street || !city || !state || !zip) { showToast('Please fill in all required fields', 'error'); return false; }
    if (!emailVerified) { showToast('Please verify your email first', 'error'); return false; }
    return true;
}

function completeOrder(paymentMethod, paymentId) {
    const customer = {
        name: document.getElementById('co-name').value.trim(),
        email: document.getElementById('co-email').value.trim(),
        phone: document.getElementById('co-phone').value.trim(),
        address: {
            street: document.getElementById('co-street').value.trim(),
            city: document.getElementById('co-city').value.trim(),
            state: document.getElementById('co-state').value.trim(),
            zip: document.getElementById('co-zip').value.trim(),
        }
    };

    // Create order on server
    const fd = new FormData();
    fd.append('ms_action', 'create_order');
    fd.append('csrf_token', MS.csrfToken);
    fd.append('cart', JSON.stringify(cart));
    fd.append('customer', JSON.stringify(customer));
    fd.append('payment_method', paymentMethod);

    fetch('/admin/modules/MyStore/api/store-api.php', {method:'POST', body:fd}).then(r=>r.json()).then(d => {
        if (!d.ok) { showToast(d.error || 'Order failed', 'error'); return; }

        // Mark payment complete
        const fd2 = new FormData();
        fd2.append('ms_action', 'complete_payment');
        fd2.append('csrf_token', MS.csrfToken);
        fd2.append('order_id', d.order_id);
        fd2.append('payment_method', paymentMethod);
        fd2.append('payment_id', paymentId);
        fetch('/admin/modules/MyStore/api/store-api.php', {method:'POST', body:fd2}).then(r=>r.json()).then(d2 => {
            cart = [];
            saveCart();
            updateCartUI();
            msCloseModal('checkout-modal');
            document.getElementById('success-order-id').textContent = 'Order #' + d.order_id;
            msOpenModal('success-modal');
        });
    });
}

// ── Stripe ──
<?php if ($stripePublicKey): ?>
let stripe, stripeCard;
function initStripe() {
    if (typeof Stripe === 'undefined') return;
    stripe = Stripe(MS.stripePublicKey);
    const elements = stripe.elements();
    stripeCard = elements.create('card', {style: {base: {color: '#fff', fontSize: '16px', '::placeholder': {color: '#6b7280'}}}});
    stripeCard.mount('#stripe-card-element');
}
document.addEventListener('DOMContentLoaded', initStripe);
function stripePayNow() {
    if (!validateCheckout()) return;
    const btn = document.getElementById('stripe-pay-btn');
    btn.disabled = true; btn.textContent = 'Processing...';
    // For now, create a token and complete — full Stripe integration needs server-side payment intents
    stripe.createToken(stripeCard).then(result => {
        if (result.error) { showToast(result.error.message, 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-credit-card mr-2"></i>Pay with Card'; return; }
        completeOrder('stripe', result.token.id);
    });
}
<?php endif; ?>
</script>

<!-- Store Help Modal -->
<div id="ms-help-modal" class="ms-modal">
    <div class="ms-modal-box ms-modal-sm"><div class="ms-modal-pad">
        <div class="ms-modal-head"><h2><i class="fas fa-question-circle" style="color:#dc2626"></i> Need Help?</h2><button class="ms-close" onclick="msCloseModal('ms-help-modal')">&times;</button></div>
        <p style="color:#aaa;font-size:0.88rem;margin-bottom:16px">Having trouble with your order, a product question, or need assistance? Send us a message and we'll get back to you right away.</p>
        <div class="ms-form-group"><label>Your Name *</label><input type="text" id="ms-help-name" class="ms-input" placeholder="Your name" required></div>
        <div class="ms-form-group"><label>Email *</label><input type="email" id="ms-help-email" class="ms-input" placeholder="your@email.com" required></div>
        <div class="ms-form-group"><label>Phone</label><input type="tel" id="ms-help-phone" class="ms-input" placeholder="(555) 123-4567"></div>
        <div class="ms-form-group"><label>How can we help? *</label><textarea id="ms-help-message" class="ms-input" rows="4" placeholder="Describe your question or issue..." required style="resize:vertical"></textarea></div>
        <button class="ms-btn ms-btn-primary" style="width:100%;justify-content:center;padding:12px" onclick="msSubmitHelp()"><i class="fas fa-paper-plane"></i> Send Message</button>
        <p id="ms-help-status" style="display:none;margin-top:10px;font-size:0.82rem;text-align:center"></p>
    </div></div>
</div>

<?php if ($paypalClientId): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars($paypalClientId) ?>&currency=USD&enable-funding=venmo"></script>
<?php endif; ?>
<?php if ($stripePublicKey): ?>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>
