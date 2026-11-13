<?php
/**
 * Printful Manager - COMPLETE VERSION WITH STORE SELECTOR
 * 
 * File: SITE_ROOT/admin/printful-mgr/printful-manager.php
 * Version: 2025.11.09.1730
 * 
 * Features:
 * - Auto store detection
 * - Store selector dropdown
 * - Complete product sync
 * - Order management
 * - Real-time status updates
 */

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

if (!defined('ADMIN_ACCESS') && !isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/includes/printful-config.php';
require_once __DIR__ . '/includes/printful-api.php';

$printfulConfig = new PrintfulConfig();
$printfulAPI = new PrintfulAPI($printfulConfig);

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'save_config':
            $referralLink = trim($_POST['referral_link'] ?? '');
            if (empty($referralLink)) {
                $referralLink = 'https://www.printful.com/give-5-get-5/6ENECD';
            }
            $result = $printfulConfig->saveConfig([
                'api_key'         => $_POST['api_key'] ?? '',
                'store_id'        => $_POST['store_id'] ?? '',
                'currency'        => $_POST['currency'] ?? 'USD',
                'sync_enabled'    => isset($_POST['sync_enabled']) ? 1 : 0,
                'referral_link'   => $referralLink,
                'affiliate_id'    => trim($_POST['affiliate_id'] ?? ''),
                'affiliate_link'  => trim($_POST['affiliate_link'] ?? ''),
                'affiliate_notes' => trim($_POST['affiliate_notes'] ?? '')
            ]);
            echo json_encode(['success' => $result]);
            exit;
            
        case 'test_connection':
            $result = $printfulAPI->testConnection();
            echo json_encode($result);
            exit;
            
        case 'get_stores':
            $result = $printfulAPI->getStores();
            echo json_encode($result);
            exit;
            
        case 'sync_products':
            $result = $printfulAPI->syncProducts();
            echo json_encode($result);
            exit;
            
        case 'get_products':
            $result = $printfulAPI->getProducts();
            echo json_encode($result);
            exit;
            
        case 'get_orders':
            $result = $printfulAPI->getOrders();
            echo json_encode($result);
            exit;
            
        case 'get_sync_status':
            $result = $printfulAPI->getSyncStatus();
            echo json_encode($result);
            exit;

        case 'archive_orders':
            // Archive one or more local order records. Takes a JSON-encoded
            // array of printful_ids (or 0-based indices when the record has no
            // printful_id). NEVER touches Printful itself — the local file is
            // just our tracking log (retail/profit/payment data Printful can't
            // see anyway). Moves matched rows into orders_archive.json.
            $idsRaw = $_POST['ids'] ?? '';
            $ids = is_string($idsRaw) ? (json_decode($idsRaw, true) ?: []) : (array)$idsRaw;
            $ids = array_values(array_filter(array_map('strval', $ids), fn($x) => $x !== ''));
            if (empty($ids)) { echo json_encode(['success' => false, 'message' => 'No order ids provided']); exit; }

            $ordersFile  = SITE_ROOT . '/admin/data/printful/orders.json';
            $archiveFile = SITE_ROOT . '/admin/data/printful/orders_archive.json';
            if (!is_file($ordersFile)) { echo json_encode(['success' => false, 'message' => 'Orders file missing']); exit; }

            $orders  = json_decode((string)file_get_contents($ordersFile), true) ?: [];
            $archive = is_file($archiveFile) ? (json_decode((string)file_get_contents($archiveFile), true) ?: []) : [];

            $kept = [];
            $moved = [];
            foreach ($orders as $idx => $o) {
                $pid = (string)($o['printful_id'] ?? '');
                $key = $pid !== '' ? $pid : (string)$idx;
                if (in_array($key, $ids, true) || ($pid !== '' && in_array($pid, $ids, true))) {
                    $o['_archived_at'] = gmdate('c');
                    $o['_archived_reason'] = 'admin_delete';
                    $archive[] = $o;
                    $moved[] = $key;
                } else {
                    $kept[] = $o;
                }
            }

            if (empty($moved)) { echo json_encode(['success' => false, 'message' => 'No matching orders']); exit; }

            file_put_contents($ordersFile, json_encode(array_values($kept), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            file_put_contents($archiveFile, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            @chown($ordersFile, 'www-data'); @chgrp($ordersFile, 'www-data'); @chmod($ordersFile, 0664);
            @chown($archiveFile, 'www-data'); @chgrp($archiveFile, 'www-data'); @chmod($archiveFile, 0664);

            echo json_encode([
                'success'       => true,
                'archived'      => count($moved),
                'archived_ids'  => $moved,
                'orders_remaining' => count($kept),
                'archive_total' => count($archive),
            ]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }
}

$config = $printfulConfig->getConfig();
$connectionStatus = $printfulAPI->testConnection();
$stores = $connectionStatus['stores'] ?? [];

require_once SITE_ROOT . '/admin/auth.php';
requireAuth();

$artistName = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Artist', ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $artistName; ?> - Printful Manager</title>
    <link rel="stylesheet" href="/admin/css/admin-styles.css">
    <link rel="stylesheet" href="assets/css/printful-manager.css">
    <link rel="stylesheet" href="assets/css/stores-panel.css">
</head>
<body>
    <?php @include_once SITE_ROOT . '/admin/includes/admin-bg-apply.inc.php'; ?>

    <div class="admin-container">
        <h1 class="panel_header_h1">Printful Manager</h1>
        <?php include SITE_ROOT . '/admin/admin_menu.php'; ?>

    <?php
    $_pfPageExists = is_file(SITE_ROOT . '/admin/data/pages/printful-store/printful-store.json');
    ?>
    <div class="printful-manager">

        <!-- Luminal CMS Printful affiliate banner — see PrintfulConfig::LUMINAL_AFFILIATE_LINK -->
        <a href="<?php echo htmlspecialchars(PrintfulConfig::LUMINAL_AFFILIATE_LINK); ?>"
           target="_blank" rel="noopener sponsored"
           class="pf-affiliate-banner"
           style="display:flex;align-items:center;gap:14px;padding:14px 20px;margin-bottom:18px;background:linear-gradient(95deg,rgba(220,30,90,0.18),rgba(110,40,200,0.16));border:1px solid rgba(220,30,90,0.35);border-radius:10px;text-decoration:none;color:inherit;transition:transform .15s ease,box-shadow .15s ease"
           onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 6px 20px rgba(220,30,90,0.18)'"
           onmouseout="this.style.transform='';this.style.boxShadow=''">
            <span style="font-size:1.6rem;line-height:1">🚀</span>
            <span style="flex:1;min-width:0">
                <strong style="display:block;font-size:0.95rem;color:#fff;letter-spacing:0.01em">Don't have a Printful store yet?</strong>
                <span style="font-size:0.78rem;color:rgba(255,255,255,0.78);display:block;margin-top:2px">Start one in minutes — print-on-demand apparel, drinkware, prints, and more. No inventory, no fulfillment headaches.</span>
            </span>
            <span style="white-space:nowrap;padding:9px 16px;background:rgba(255,255,255,0.95);color:#111;font-weight:700;font-size:0.82rem;border-radius:6px;letter-spacing:0.02em">Get Started →</span>
        </a>

        <header class="manager-header">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <h1 style="margin:0">🛍️ Printful Manager</h1>
                <?php if ($_pfPageExists): ?>
                <a href="/page.php?p=printful-store" target="_blank" style="font-size:0.72rem;padding:5px 12px;border-radius:6px;background:rgba(22,163,74,0.1);border:1px solid rgba(22,163,74,0.2);color:#16a34a;font-weight:700;text-decoration:none">&#128065; View Storefront</a>
                <?php else: ?>
                <button onclick="pfCreateStorePage()" style="font-size:0.72rem;padding:5px 12px;border-radius:6px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:#f59e0b;font-weight:700;cursor:pointer;font-family:inherit">&#128171; Create Storefront Page</button>
                <?php endif; ?>
            </div>
            <div class="connection-status" id="connectionStatus">
                <?php if ($connectionStatus['success']): ?>
                    <span class="status-indicator connected"></span>
                    <span>Connected - <?php echo count($stores); ?> store(s) detected</span>
                <?php else: ?>
                    <span class="status-indicator disconnected"></span>
                    <span>Disconnected</span>
                <?php endif; ?>
            </div>
        </header>

        <nav class="manager-nav">
            <button class="nav-btn active" data-tab="config">⚙️ Configuration</button>
            <button class="nav-btn" data-tab="products">📦 Products</button>
            <button class="nav-btn" data-tab="orders">🛒 Orders</button>
            <button class="nav-btn" data-tab="sync">🔄 Sync Settings</button>
        </nav>

        <main class="manager-content">
            <!-- Configuration Tab -->
            <div class="tab-content active" id="configTab">
                <div class="content-section">
                    <h2>API Configuration</h2>
                    
                    <form id="configForm" class="config-form">
                        <div class="form-group">
                            <label for="apiKey">Printful API Key</label>
                            <input type="password" 
                                   id="apiKey" 
                                   name="api_key" 
                                   value="<?php echo htmlspecialchars($config['api_key'] ?? ''); ?>"
                                   placeholder="Enter your Printful API key">
                            <small>Get your API key from: <a href="https://www.printful.com/dashboard/settings" target="_blank">Printful Dashboard → Settings → API</a></small>
                        </div>

                        <?php if (!empty($stores)): ?>
                        <div class="form-group">
                            <label for="storeId">Select Store</label>
                            <select id="storeId" name="store_id">
                                <option value="">-- Select a store --</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>" 
                                            <?php echo ($config['store_id'] ?? '') == $store['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($store['name'] ?? 'Store #' . $store['id']); ?> 
                                        (ID: <?php echo $store['id']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small><?php echo count($stores); ?> store(s) detected with this API key</small>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="currency">Currency</label>
                            <select id="currency" name="currency">
                                <option value="USD" <?php echo ($config['currency'] ?? 'USD') === 'USD' ? 'selected' : ''; ?>>USD</option>
                                <option value="EUR" <?php echo ($config['currency'] ?? '') === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                                <option value="GBP" <?php echo ($config['currency'] ?? '') === 'GBP' ? 'selected' : ''; ?>>GBP</option>
                                <option value="CAD" <?php echo ($config['currency'] ?? '') === 'CAD' ? 'selected' : ''; ?>>CAD</option>
                            </select>
                        </div>

                        <div class="form-group checkbox">
                            <label>
                                <input type="checkbox" 
                                       name="sync_enabled" 
                                       <?php echo ($config['sync_enabled'] ?? 0) ? 'checked' : ''; ?>>
                                Enable automatic product sync
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="button" id="testConnection" class="btn btn-secondary">🔍 Test Connection</button>
                            <button type="submit" class="btn btn-primary">💾 Save Configuration</button>
                        </div>
                    </form>

                    <!-- Growth & Referrals Section -->
                    <div style="margin-top:32px;border-top:1px solid rgba(255,255,255,0.08);padding-top:28px">
                        <h2 style="margin-bottom:4px">🤝 Growth &amp; Referrals</h2>
                        <p style="font-size:0.82rem;color:#888;margin-bottom:20px">Referral and affiliate links for sharing Printful and earning rewards.</p>

                        <form id="growthForm" class="config-form">

                            <div class="form-group">
                                <label for="referralLink">Referral Program Link <span style="font-size:0.75rem;color:#f59e0b;font-weight:400">(Give $5, Get $5)</span></label>
                                <div style="display:flex;gap:8px;align-items:center">
                                    <input type="url"
                                           id="referralLink"
                                           name="referral_link"
                                           value="<?php echo htmlspecialchars($config['referral_link'] ?? 'https://www.printful.com/give-5-get-5/6ENECD'); ?>"
                                           placeholder="https://www.printful.com/give-5-get-5/XXXXXX"
                                           style="flex:1">
                                    <button type="button"
                                            onclick="navigator.clipboard.writeText(document.getElementById('referralLink').value).then(()=>pfToast('Referral link copied!'))"
                                            class="btn btn-secondary"
                                            style="white-space:nowrap;padding:8px 14px">📋 Copy</button>
                                </div>
                                <small>Share this link — when someone signs up and places their first order, you both get $5 in Printful credit. Default applies to all stores.</small>
                            </div>

                            <div class="form-group">
                                <label for="affiliateId">Affiliate ID</label>
                                <input type="text"
                                       id="affiliateId"
                                       name="affiliate_id"
                                       value="<?php echo htmlspecialchars($config['affiliate_id'] ?? ''); ?>"
                                       placeholder="Your Printful affiliate ID">
                                <small>Found in your <a href="https://www.printful.com/affiliate" target="_blank">Printful Affiliate Dashboard</a>.</small>
                            </div>

                            <div class="form-group">
                                <label for="affiliateLink">Affiliate Program Link</label>
                                <div style="display:flex;gap:8px;align-items:center">
                                    <input type="url"
                                           id="affiliateLink"
                                           name="affiliate_link"
                                           value="<?php echo htmlspecialchars($config['affiliate_link'] ?? ''); ?>"
                                           placeholder="https://www.printful.com/?aff=yourcode"
                                           style="flex:1">
                                    <button type="button"
                                            onclick="navigator.clipboard.writeText(document.getElementById('affiliateLink').value).then(()=>pfToast('Affiliate link copied!'))"
                                            class="btn btn-secondary"
                                            style="white-space:nowrap;padding:8px 14px">📋 Copy</button>
                                </div>
                                <small>Your unique affiliate tracking link. Earn commission when new customers sign up through this link.</small>
                            </div>

                            <div class="form-group">
                                <label for="affiliateNotes">Notes</label>
                                <textarea id="affiliateNotes"
                                          name="affiliate_notes"
                                          rows="3"
                                          placeholder="Commission rate, payment schedule, program details..."
                                          style="width:100%;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.12);border-radius:6px;color:inherit;padding:10px;font-family:inherit;font-size:0.9rem;resize:vertical"><?php echo htmlspecialchars($config['affiliate_notes'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">💾 Save Growth Settings</button>
                            </div>
                        </form>
                    </div>

                    <?php if (!empty($stores)): ?>
                    <div class="stores-panel">
                        <h3>📍 Detected Stores</h3>
                        <div class="stores-list">
                            <?php foreach ($stores as $store): ?>
                                <div class="store-card <?php echo ($config['store_id'] ?? '') == $store['id'] ? 'active' : ''; ?>">
                                    <div class="store-name"><?php echo htmlspecialchars($store['name'] ?? 'Unnamed Store'); ?></div>
                                    <div class="store-id">ID: <?php echo $store['id']; ?></div>
                                    <?php if (isset($store['currency'])): ?>
                                        <div class="store-currency">Currency: <?php echo $store['currency']; ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Products Tab -->
            <div class="tab-content" id="productsTab">
                <div class="content-section">
                    <div class="section-header">
                        <h2>📦 Products</h2>
                        <div class="button-group">
                            <button id="syncProducts" class="btn btn-primary">🔄 Sync from Printful</button>
                        </div>
                    </div>
                    <?php
                    $pfProducts = [];
                    $pfProductsFile = SITE_ROOT . '/admin/data/printful/products.json';
                    if (is_file($pfProductsFile)) $pfProducts = json_decode(file_get_contents($pfProductsFile), true) ?: [];
                    $pfPrices = [];
                    $pfPriceFile = SITE_ROOT . '/admin/data/printful/price-cache.json';
                    if (is_file($pfPriceFile)) $pfPrices = json_decode(file_get_contents($pfPriceFile), true) ?: [];
                    ?>
                    <?php if (empty($pfProducts)): ?>
                    <p style="color:#888;text-align:center;padding:32px">No products synced. Click "Sync from Printful" to load your catalog.</p>
                    <?php else: ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-top:12px">
                        <?php foreach ($pfProducts as $pfp):
                            $pid = $pfp['id'] ?? '';
                            $pName = $pfp['name'] ?? 'Product';
                            $pThumb = $pfp['thumbnail_url'] ?? '';
                            $pVariants = is_array($pfp['variants'] ?? null) ? count($pfp['variants']) : (int)($pfp['variants'] ?? 0);
                            $pSynced = (int)($pfp['synced'] ?? 0);
                            $pPrice = $pfPrices[$pid] ?? null;
                        ?>
                        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:10px;overflow:hidden;transition:border-color .15s" onmouseover="this.style.borderColor='rgba(245,158,11,0.3)'" onmouseout="this.style.borderColor='rgba(255,255,255,0.06)'">
                            <?php if ($pThumb): ?>
                            <div style="height:180px;overflow:hidden;background:#0a0a0a">
                                <img src="<?= htmlspecialchars($pThumb) ?>" alt="<?= htmlspecialchars($pName) ?>" style="width:100%;height:100%;object-fit:contain" loading="lazy">
                            </div>
                            <?php endif; ?>
                            <div style="padding:12px">
                                <div style="font-weight:700;color:#f0f0f0;font-size:.88rem;margin-bottom:4px"><?= htmlspecialchars($pName) ?></div>
                                <?php if ($pPrice): ?>
                                <div style="color:#f59e0b;font-weight:800;font-size:1.1rem;margin-bottom:6px">From $<?= $pPrice ?></div>
                                <?php endif; ?>
                                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                    <span style="font-size:.65rem;padding:2px 6px;border-radius:3px;background:rgba(255,255,255,0.06);color:#888"><?= $pVariants ?> variant<?= $pVariants !== 1 ? 's' : '' ?></span>
                                    <span style="font-size:.65rem;padding:2px 6px;border-radius:3px;background:rgba(22,163,74,0.1);color:#16a34a"><?= $pSynced ?> synced</span>
                                    <a href="https://www.printful.com/dashboard/product-catalog/<?= $pid ?>" target="_blank" style="font-size:.65rem;padding:2px 6px;border-radius:3px;background:rgba(245,158,11,0.08);color:#f59e0b;text-decoration:none;font-weight:600;margin-left:auto">Printful ↗</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:12px;font-size:.72rem;color:#888;text-align:center"><?= count($pfProducts) ?> products · Prices cached <?= is_file($pfPriceFile) ? date('M j, g:i A', filemtime($pfPriceFile)) : 'never' ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Orders Tab -->
            <div class="tab-content" id="ordersTab">
                <div class="content-section">
                    <div class="section-header">
                        <h2>🛒 Orders</h2>
                        <button class="btn btn-secondary" onclick="location.reload()">🔃 Refresh</button>
                    </div>
                    <?php
                    $localOrders = [];
                    $ordersFile = SITE_ROOT . '/admin/data/printful/orders.json';
                    if (is_file($ordersFile)) {
                        $localOrders = array_reverse(json_decode(file_get_contents($ordersFile), true) ?: []);
                    }
                    ?>
                    <?php
                    // Collect available statuses for the filter dropdown
                    $pfStatuses = [];
                    foreach ($localOrders as $_o) {
                        $_s = $_o['status'] ?? 'draft';
                        $pfStatuses[$_s] = ($pfStatuses[$_s] ?? 0) + 1;
                    }
                    ksort($pfStatuses);
                    ?>

                    <?php if (!empty($localOrders)): ?>
                    <div class="pfo-toolbar" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;padding:.7rem .9rem;margin-bottom:.7rem;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:8px">
                        <label style="display:flex;align-items:center;gap:.4rem;font-size:.78rem;color:#cbd5e1">
                            <input type="checkbox" id="pfoSelectAll" style="width:16px;height:16px"> Select all
                        </label>
                        <span id="pfoSelCount" style="font-size:.74rem;color:#8b94a6">0 selected</span>
                        <div style="flex:1"></div>
                        <label style="font-size:.74rem;color:#8b94a6">Status
                            <select id="pfoFilterStatus" style="margin-left:.35rem;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:#e8edf5;padding:.4rem .6rem;border-radius:5px;font-size:.82rem">
                                <option value="">All (<?= count($localOrders) ?>)</option>
                                <?php foreach ($pfStatuses as $s => $c): ?>
                                <option value="<?= htmlspecialchars($s) ?>"><?= ucfirst($s) ?> (<?= $c ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <input type="search" id="pfoFilterSearch" placeholder="Search customer, ID…" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:#e8edf5;padding:.45rem .7rem;border-radius:5px;font-size:.82rem;min-width:200px">
                        <button id="pfoBulkDelete" class="btn" disabled
                                style="padding:.5rem .85rem;background:rgba(220,38,58,.16);border:1px solid rgba(220,38,58,.38);color:#ff8b98;font-size:.8rem;border-radius:5px;cursor:pointer;opacity:.5"
                                onclick="pfoBulkArchive()">🗑 Archive selected</button>
                    </div>
                    <?php endif; ?>

                    <div id="ordersContainer">
                        <?php if (empty($localOrders)): ?>
                        <p style="color:#888;text-align:center;padding:32px">No orders yet.</p>
                        <?php else: ?>
                        <table style="width:100%;border-collapse:collapse;font-size:.82rem" id="pfoTable">
                            <thead>
                                <tr style="background:rgba(255,255,255,0.03);border-bottom:1px solid rgba(255,255,255,0.08)">
                                    <th style="padding:10px 8px 10px 14px;width:28px"></th>
                                    <th style="padding:10px 14px;text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#888">Order</th>
                                    <th style="padding:10px 14px;text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#888">Customer</th>
                                    <th style="padding:10px 14px;text-align:right;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#888">Retail</th>
                                    <th style="padding:10px 14px;text-align:right;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#888">Cost</th>
                                    <th style="padding:10px 14px;text-align:right;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#888">Profit</th>
                                    <th style="padding:10px 14px;text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#888">Payment</th>
                                    <th style="padding:10px 14px;text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#888">Status</th>
                                    <th style="padding:10px 14px;text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#888">Date</th>
                                    <th style="padding:10px 14px;width:40px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($localOrders as $oi => $o):
                                    $statusColors = ['draft'=>'#f59e0b','confirmed'=>'#3b82f6','pending'=>'#a78bfa','fulfilled'=>'#16a34a','shipped'=>'#16a34a','canceled'=>'#dc2626'];
                                    $st = $o['status'] ?? 'draft';
                                    $sc = $statusColors[$st] ?? '#888';
                                    $date = !empty($o['created_at']) ? date('M j, g:i A', strtotime($o['created_at'])) : '—';
                                    $ordId = $o['printful_id'] ?? $oi;
                                    $rowKey = $o['printful_id'] ?? (string)$oi;
                                ?>
                                <tr class="pfo-row" data-order-key="<?= htmlspecialchars((string)$rowKey) ?>" data-status="<?= htmlspecialchars($st) ?>" style="border-bottom:1px solid rgba(255,255,255,0.04)">
                                    <td style="padding:10px 8px 10px 14px" onclick="event.stopPropagation()">
                                        <input type="checkbox" class="pfo-cb" data-key="<?= htmlspecialchars((string)$rowKey) ?>" style="width:16px;height:16px;cursor:pointer">
                                    </td>
                                    <td style="padding:10px 14px;font-weight:700;font-family:monospace;font-size:.75rem;cursor:pointer" onclick="var d=document.getElementById('pfOrdDetail<?= $oi ?>');d.style.display=d.style.display==='none'?'table-row':'none'" title="Click to expand">
                                        #<?= $ordId ?>
                                    </td>
                                    <td style="padding:10px 14px">
                                        <div style="font-weight:700;color:#f0f0f0"><?= htmlspecialchars($o['customer_name'] ?? '—') ?></div>
                                        <div style="font-size:.7rem;color:#888"><?= htmlspecialchars($o['customer_email'] ?? '') ?></div>
                                    </td>
                                    <td style="padding:10px 14px;text-align:right;font-family:monospace;color:#f0f0f0">$<?= number_format((float)($o['retail_total'] ?? $o['subtotal'] ?? 0), 2) ?></td>
                                    <td style="padding:10px 14px;text-align:right;font-family:monospace;color:#888">$<?= number_format((float)($o['printful_cost'] ?? 0), 2) ?></td>
                                    <td style="padding:10px 14px;text-align:right;font-family:monospace;color:#16a34a;font-weight:700">$<?= number_format((float)($o['profit'] ?? 0), 2) ?></td>
                                    <td style="padding:10px 14px">
                                        <?php if (!empty($o['payment_id'])): ?>
                                        <span style="font-size:.65rem;padding:2px 6px;border-radius:3px;background:rgba(22,163,74,0.1);color:#16a34a;font-weight:700"><?= ucfirst($o['payment_method'] ?? 'paid') ?></span>
                                        <?php else: ?>
                                        <span style="font-size:.65rem;padding:2px 6px;border-radius:3px;background:rgba(245,158,11,0.1);color:#f59e0b;font-weight:700">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:10px 14px">
                                        <span style="padding:2px 8px;border-radius:4px;font-size:.65rem;font-weight:700;background:<?= $sc ?>20;color:<?= $sc ?>"><?= ucfirst($st) ?></span>
                                    </td>
                                    <td style="padding:10px 14px;font-size:.75rem;color:#888"><?= $date ?></td>
                                    <td style="padding:10px 14px;text-align:right" onclick="event.stopPropagation()">
                                        <button title="Archive this order" onclick="pfoArchive('<?= htmlspecialchars((string)$rowKey, ENT_QUOTES) ?>', '<?= htmlspecialchars((string)$ordId, ENT_QUOTES) ?>')"
                                                style="background:rgba(220,38,58,.14);border:1px solid rgba(220,38,58,.32);color:#ff8b98;font-size:.7rem;padding:4px 8px;border-radius:4px;cursor:pointer">🗑</button>
                                    </td>
                                </tr>
                                <!-- Expandable detail row -->
                                <tr id="pfOrdDetail<?= $oi ?>" style="display:none;background:rgba(255,255,255,0.02)">
                                    <td colspan="10" style="padding:16px 20px">
                                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;font-size:.82rem">
                                            <!-- Customer -->
                                            <div>
                                                <div style="font-size:.68rem;color:#888;text-transform:uppercase;font-weight:700;margin-bottom:6px">Customer</div>
                                                <div style="color:#f0f0f0;font-weight:600"><?= htmlspecialchars($o['customer_name'] ?? '') ?></div>
                                                <div style="color:#888"><?= htmlspecialchars($o['customer_email'] ?? '') ?></div>
                                            </div>
                                            <!-- Financials -->
                                            <div>
                                                <div style="font-size:.68rem;color:#888;text-transform:uppercase;font-weight:700;margin-bottom:6px">Financials</div>
                                                <div>Retail: <strong style="color:#f0f0f0">$<?= number_format((float)($o['retail_total'] ?? $o['subtotal'] ?? 0), 2) ?></strong></div>
                                                <div>Cost: <span style="color:#888">$<?= number_format((float)($o['printful_cost'] ?? 0), 2) ?></span></div>
                                                <div>Shipping: <span style="color:#888">$<?= number_format((float)($o['shipping_cost'] ?? 0), 2) ?></span></div>
                                                <div>Profit: <strong style="color:#16a34a">$<?= number_format((float)($o['profit'] ?? 0), 2) ?></strong></div>
                                            </div>
                                            <!-- Payment & Actions -->
                                            <div>
                                                <div style="font-size:.68rem;color:#888;text-transform:uppercase;font-weight:700;margin-bottom:6px">Payment & Status</div>
                                                <?php if (!empty($o['payment_id'])): ?>
                                                <div>Method: <strong style="color:#16a34a"><?= ucfirst($o['payment_method'] ?? 'paid') ?></strong></div>
                                                <div style="font-size:.7rem;color:#888;font-family:monospace;word-break:break-all">TX: <?= htmlspecialchars($o['payment_id']) ?></div>
                                                <?php else: ?>
                                                <div style="color:#f59e0b;font-weight:600">No payment collected</div>
                                                <?php endif; ?>
                                                <div style="margin-top:8px;display:flex;gap:6px">
                                                    <?php if (!empty($o['dashboard_url'])): ?>
                                                    <a href="<?= htmlspecialchars($o['dashboard_url']) ?>" target="_blank" style="font-size:.7rem;padding:4px 10px;border-radius:4px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);color:#f59e0b;text-decoration:none;font-weight:600">View in Printful ↗</a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="font-size:.65rem;color:#666;margin-top:10px;text-align:right">Items: <?= (int)($o['items'] ?? 0) ?> · Created: <?= $date ?></div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php
                        // Summary stats
                        $totalRevenue = array_sum(array_map(fn($o) => (float)($o['retail_total'] ?? $o['subtotal'] ?? 0), $localOrders));
                        $totalCost = array_sum(array_map(fn($o) => (float)($o['printful_cost'] ?? 0), $localOrders));
                        $totalProfit = array_sum(array_map(fn($o) => (float)($o['profit'] ?? 0), $localOrders));
                        ?>
                        <div style="display:flex;gap:16px;margin-top:16px;flex-wrap:wrap">
                            <div style="padding:14px 20px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;text-align:center;flex:1;min-width:120px">
                                <div style="font-size:1.4rem;font-weight:900;color:#f0f0f0"><?= count($localOrders) ?></div>
                                <div style="font-size:.68rem;color:#888;text-transform:uppercase">Orders</div>
                            </div>
                            <div style="padding:14px 20px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;text-align:center;flex:1;min-width:120px">
                                <div style="font-size:1.4rem;font-weight:900;color:#f0f0f0">$<?= number_format($totalRevenue, 2) ?></div>
                                <div style="font-size:.68rem;color:#888;text-transform:uppercase">Revenue</div>
                            </div>
                            <div style="padding:14px 20px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;text-align:center;flex:1;min-width:120px">
                                <div style="font-size:1.4rem;font-weight:900;color:#888">$<?= number_format($totalCost, 2) ?></div>
                                <div style="font-size:.68rem;color:#888;text-transform:uppercase">Cost</div>
                            </div>
                            <div style="padding:14px 20px;background:rgba(22,163,74,0.06);border:1px solid rgba(22,163,74,0.15);border-radius:8px;text-align:center;flex:1;min-width:120px">
                                <div style="font-size:1.4rem;font-weight:900;color:#16a34a">$<?= number_format($totalProfit, 2) ?></div>
                                <div style="font-size:.68rem;color:#16a34a;text-transform:uppercase">Profit</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sync Settings Tab -->
            <div class="tab-content" id="syncTab">
                <div class="content-section">
                    <h2>🔄 Sync Settings</h2>
                    <div class="sync-status">
                        <div class="status-item">
                            <label>Last Sync:</label>
                            <span id="lastSync"><?php echo $config['last_sync'] ?? 'Never'; ?></span>
                        </div>
                        <div class="status-item">
                            <label>Products Synced:</label>
                            <span id="productCount">0</span>
                        </div>
                        <div class="status-item">
                            <label>Active Store:</label>
                            <span id="activeStore"><?php echo $config['store_id'] ?? 'None selected'; ?></span>
                        </div>
                    </div>
                    <button id="forceSyncNow" class="btn btn-primary">⚡ Force Sync Now</button>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/js/printful-manager.js"></script>
<script>
/* ── Printful Orders: filter + bulk archive ───────────────────────────── */
(function(){
    var selAll    = document.getElementById('pfoSelectAll');
    var bulkBtn   = document.getElementById('pfoBulkDelete');
    var filterSt  = document.getElementById('pfoFilterStatus');
    var filterSrc = document.getElementById('pfoFilterSearch');
    var selCount  = document.getElementById('pfoSelCount');
    if (!bulkBtn) return; // orders tab empty — nothing to wire

    function visibleRows() {
        return Array.prototype.filter.call(
            document.querySelectorAll('.pfo-row'),
            function(r){ return r.style.display !== 'none'; }
        );
    }
    function checkedRows() {
        return Array.prototype.filter.call(
            document.querySelectorAll('.pfo-cb'),
            function(cb){ return cb.checked && cb.closest('tr').style.display !== 'none'; }
        );
    }
    function updateBulkState() {
        var n = checkedRows().length;
        if (selCount) selCount.textContent = n + ' selected';
        bulkBtn.disabled = (n === 0);
        bulkBtn.style.opacity = (n === 0) ? '.5' : '1';
        bulkBtn.textContent = '🗑 Archive selected' + (n > 0 ? ' (' + n + ')' : '');
    }

    function applyFilter() {
        var st = filterSt ? filterSt.value : '';
        var q  = filterSrc ? filterSrc.value.toLowerCase().trim() : '';
        Array.prototype.forEach.call(document.querySelectorAll('.pfo-row'), function(row){
            var ok = true;
            if (st && row.getAttribute('data-status') !== st) ok = false;
            if (ok && q) {
                var txt = row.textContent.toLowerCase();
                if (txt.indexOf(q) === -1) ok = false;
            }
            row.style.display = ok ? '' : 'none';
            // hide matching detail row
            var idCell = row.cells[1];
            var next = row.nextElementSibling;
            if (next && next.id && next.id.indexOf('pfOrdDetail') === 0) {
                if (!ok) next.style.display = 'none';
            }
        });
        // Uncheck hidden rows
        Array.prototype.forEach.call(document.querySelectorAll('.pfo-cb'), function(cb){
            if (cb.closest('tr').style.display === 'none') cb.checked = false;
        });
        if (selAll) selAll.checked = false;
        updateBulkState();
    }

    if (selAll) selAll.addEventListener('change', function(){
        Array.prototype.forEach.call(visibleRows(), function(row){
            var cb = row.querySelector('.pfo-cb');
            if (cb) cb.checked = selAll.checked;
        });
        updateBulkState();
    });
    Array.prototype.forEach.call(document.querySelectorAll('.pfo-cb'), function(cb){
        cb.addEventListener('change', updateBulkState);
    });
    if (filterSt)  filterSt.addEventListener('change', applyFilter);
    if (filterSrc) filterSrc.addEventListener('input', applyFilter);

    window.pfoArchive = function(key, displayId) {
        if (!confirm('Archive order #' + displayId + '? This removes the local tracking record. Printful itself is NOT touched.')) return;
        pfoDoArchive([key]);
    };
    window.pfoBulkArchive = function() {
        var keys = checkedRows().map(function(cb){ return cb.getAttribute('data-key'); });
        if (keys.length === 0) return;
        if (!confirm('Archive ' + keys.length + ' order(s)? This removes LOCAL tracking records only — Printful fulfillment is unaffected.')) return;
        pfoDoArchive(keys);
    };
    function pfoDoArchive(keys) {
        var fd = new FormData();
        fd.append('action', 'archive_orders');
        fd.append('ids', JSON.stringify(keys));
        fetch(location.pathname, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) {
                    // Remove archived rows from DOM
                    keys.forEach(function(k){
                        var row = document.querySelector('.pfo-row[data-order-key="' + CSS.escape(k) + '"]');
                        if (row) {
                            var next = row.nextElementSibling;
                            if (next && next.id && next.id.indexOf('pfOrdDetail') === 0) next.remove();
                            row.remove();
                        }
                    });
                    updateBulkState();
                    // If no more rows, show empty state
                    if (document.querySelectorAll('.pfo-row').length === 0) {
                        var c = document.getElementById('ordersContainer');
                        if (c) c.innerHTML = '<p style="color:#888;text-align:center;padding:32px">No orders yet.</p>';
                    }
                } else {
                    alert('Archive failed: ' + (d.message || 'unknown error'));
                }
            })
            .catch(function(e){ alert('Network error: ' + e.message); });
    }
})();

function pfCreateStorePage() {
    var fd = new FormData();
    fd.append('__save', '1');
    fd.append('page_name', 'printful-store');
    fd.append('page_title', 'Printful Store');
    fd.append('left_width', '100');
    fd.append('use_wrapper', '1');
    fd.append('include_bg', '1');
    fd.append('components[main_content][content]', '[[panel:panel-printful.php]]');
    fd.append('components[right_column][content]', '');
    fetch('/admin/modules/PageManager/PageManager.php', {method:'POST', body:fd})
    .then(function(r){return r.json()}).then(function(d){
        if (d.ok) {
            location.reload();
        } else {
            alert(d.error || 'Failed to create page');
        }
    });
}
<?php if (($_GET['tab'] ?? '') === 'orders'): ?>
// Auto-open orders tab
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.tab-content').forEach(function(t){t.classList.remove('active')});
    document.querySelectorAll('.nav-btn').forEach(function(b){b.classList.remove('active')});
    var ot = document.getElementById('ordersTab');
    if(ot) ot.classList.add('active');
    document.querySelectorAll('.nav-btn').forEach(function(b){if(b.getAttribute('data-tab')==='orders')b.classList.add('active')});
});
<?php endif; ?>
</script>
    </div><!-- /.admin-container -->
</body>
</html>
