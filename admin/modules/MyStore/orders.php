<?php
/**
 * MyStore — Standalone Orders Page
 * Full-featured: search, filter, sort, bulk actions, status changes, invoice email
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();
require_once __DIR__ . '/includes/mystore-admin-functions.php';
if (!defined('PAYPAL_SYSTEM')) define('PAYPAL_SYSTEM', true);
if (!defined('MYSTORE_SYSTEM')) define('MYSTORE_SYSTEM', true);
require_once __DIR__ . '/includes/mystore-config.php';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['ord_action'] ?? '';
    $ordersFile = SITE_ROOT . $MYSTORE_DATA_BASE . 'orders.json';
    $orders = is_file($ordersFile) ? json_decode(file_get_contents($ordersFile), true) : [];
    if (!is_array($orders)) $orders = [];

    // ── Push all order customers into AudienceBuilder leads (deduped by email) ──
    if ($action === 'push_leads') {
        header('Content-Type: application/json; charset=UTF-8');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $abLeadsDir = SITE_ROOT . '/admin/data/AudienceBuilder/leads';
        if (!is_dir($abLeadsDir)) @mkdir($abLeadsDir, 0775, true);

        // Existing lead emails across all monthly files (dedupe)
        $existing = [];
        foreach (glob($abLeadsDir . '/*.json') ?: [] as $lf) {
            $recs = json_decode((string)file_get_contents($lf), true);
            if (is_array($recs)) {
                foreach ($recs as $r) {
                    $e = strtolower(trim($r['fields']['email'] ?? ''));
                    if ($e !== '') $existing[$e] = true;
                }
            }
        }

        // Unique customers from orders
        $seen = []; $added = 0; $skipped = 0;
        $newLeads = [];
        foreach ($orders as $o) {
            $email = strtolower(trim($o['customer']['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
            if (isset($seen[$email])) continue;
            $seen[$email] = true;
            if (isset($existing[$email])) { $skipped++; continue; }
            $cust = $o['customer'] ?? [];
            $name = trim($cust['name'] ?? trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')));
            $newLeads[] = [
                'id'           => 'AB-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 6),
                'domain'       => $host,
                'source_site'  => $host,
                'submitted_at' => date('c'),
                'ip_hash'      => '',
                'source_url'   => '/admin/modules/MyStore/orders.php',
                'utm'          => ['source' => 'mystore', 'medium' => 'order', 'campaign' => ''],
                'fields'       => [
                    'form_slug' => 'mystore-order',
                    'name'      => $name,
                    'email'     => $cust['email'] ?? '',
                    'phone'     => $cust['phone'] ?? '',
                    'source_url'=> '',
                ],
                'status'       => 'stored',
                'hub_status'   => 'local',
                'email_status' => 'skipped',
            ];
            $existing[$email] = true;
            $added++;
        }

        if ($newLeads) {
            $monthFile = $abLeadsDir . '/' . date('Y-m') . '.json';
            $month = is_file($monthFile) ? json_decode((string)file_get_contents($monthFile), true) : [];
            if (!is_array($month)) $month = [];
            $month = array_merge($month, $newLeads);
            file_put_contents($monthFile, json_encode($month, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            @chmod($monthFile, 0664);
        }
        echo json_encode(['ok' => true, 'added' => $added, 'skipped' => $skipped]);
        exit;
    }

    if ($action === 'update_status' && !empty($_POST['order_id']) && !empty($_POST['new_status'])) {
        $oid = $_POST['order_id'];
        $newStatus = $_POST['new_status'];
        foreach ($orders as &$o) {
            if (($o['order_id'] ?? '') === $oid) {
                $o['status'] = $newStatus;
                $o['updated_at'] = date('c');
            }
        }
        unset($o);
        file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'send_invoice' && !empty($_POST['order_id'])) {
        $oid = $_POST['order_id'];
        foreach ($orders as $o) {
            if (($o['order_id'] ?? '') === $oid) {
                $o = function_exists('mystore_normalize_order') ? mystore_normalize_order($o) : $o;
                $cName = htmlspecialchars($o['customer']['name'] ?? 'Customer');
                $cEmail = $o['customer']['email'] ?? '';
                if (!$cEmail) { header('Location: ' . $_SERVER['REQUEST_URI'] . '&msg=no_email'); exit; }
                $itemsHtml = '';
                foreach ($o['items'] ?? [] as $it) {
                    $iTotal = number_format(floatval($it['total'] ?? ($it['price'] ?? 0) * ($it['quantity'] ?? 1)), 2);
                    $itemsHtml .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee">' . htmlspecialchars($it['name'] ?? '') . ' x' . intval($it['quantity'] ?? 1) . '</td><td style="padding:6px 0;text-align:right;border-bottom:1px solid #eee">$' . $iTotal . '</td></tr>';
                }
                $sub = number_format(floatval($o['totals']['subtotal'] ?? $o['total'] ?? 0), 2);
                $tax = number_format(floatval($o['totals']['tax'] ?? 0), 2);
                $ship = floatval($o['totals']['shipping'] ?? 0);
                $tot = number_format(floatval($o['total'] ?? 0), 2);
                $addr = $o['customer']['address'] ?? $o['shipping'] ?? [];
                $addrHtml = htmlspecialchars($cName) . '<br>';
                if (is_array($addr)) {
                    if ($addr['street'] ?? $addr['address'] ?? '') $addrHtml .= htmlspecialchars($addr['street'] ?? $addr['address'] ?? '') . '<br>';
                    $addrHtml .= htmlspecialchars(trim(($addr['city'] ?? '') . ', ' . ($addr['state'] ?? '') . ' ' . ($addr['zip'] ?? ''), ', '));
                }
                $siteName = 'Store';
                $sitelogo = '';
                $ssFile = SITE_ROOT . '/admin/data/site-settings.json';
                if (is_file($ssFile)) {
                    $ss = json_decode(file_get_contents($ssFile), true);
                    $siteName = $ss['site_name'] ?? 'Store';
                    $sitelogo = $ss['logo'] ?? $ss['logo_full'] ?? '';
                }
                // Auto-detect logo if not in settings
                if (!$sitelogo) {
                    foreach (['/media/images/logos/logo.png', '/media/images/brand/logo.png', '/media/images/site/logo.png'] as $lp) {
                        if (is_file(SITE_ROOT . $lp)) { $sitelogo = $lp; break; }
                    }
                }
                $logoUrl = $sitelogo ? 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . $sitelogo : '';
                $subject = "Invoice — {$oid} — {$siteName}";
                $body = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto'>";
                if ($logoUrl) $body .= "<div style='text-align:center;padding:20px 0'><img src='{$logoUrl}' alt='" . htmlspecialchars($siteName) . "' style='max-height:80px;max-width:200px'></div>";
                $body .= "<h2 style='color:#f59e0b;text-align:center;margin:0 0 20px'>{$siteName}</h2>";
                $body .= "<p>Hi {$cName},</p><p>Here is your invoice:</p>";
                $body .= "<table style='width:100%;font-size:14px'><tr><td style='padding:4px 0;color:#888'>Order</td><td style='text-align:right;font-weight:700'>{$oid}</td></tr>";
                $body .= "<tr><td style='padding:4px 0;color:#888'>Date</td><td style='text-align:right'>" . date('M j, Y', strtotime($o['created_at'] ?? 'now')) . "</td></tr>";
                if ($o['paypal_txn_id'] ?? '') $body .= "<tr><td style='padding:4px 0;color:#888'>Transaction</td><td style='text-align:right;font-family:monospace;font-size:12px'>" . htmlspecialchars($o['paypal_txn_id']) . "</td></tr>";
                $body .= "</table><hr style='margin:16px 0'>";
                $body .= "<table style='width:100%;font-size:14px'>{$itemsHtml}</table>";
                $body .= "<table style='width:100%;font-size:14px;margin-top:8px'>";
                $body .= "<tr><td style='padding:3px 0;color:#888'>Subtotal</td><td style='text-align:right'>\${$sub}</td></tr>";
                $body .= "<tr><td style='padding:3px 0;color:#888'>Tax</td><td style='text-align:right'>\${$tax}</td></tr>";
                $body .= "<tr><td style='padding:3px 0;color:#888'>Shipping</td><td style='text-align:right'>" . ($ship == 0 ? 'FREE' : '$' . number_format($ship, 2)) . "</td></tr>";
                $body .= "<tr style='border-top:2px solid #333'><td style='padding:8px 0;font-weight:800;font-size:16px'>Total</td><td style='text-align:right;font-weight:800;font-size:16px;color:#f59e0b'>\${$tot}</td></tr>";
                $body .= "</table><hr style='margin:16px 0'>";
                $body .= "<p style='font-size:13px;color:#888'><strong>Ship To:</strong><br>{$addrHtml}</p>";
                $body .= "<p style='margin-top:20px;color:#888;font-size:12px'>Thank you for your order!</p></body></html>";
                require_once (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/includes/mail.php';
                $adminEmail = $ss['contact_email'] ?? '';
                luminal_send_mail($cEmail, $subject, $body, '', [
                    'from_name' => (string)$siteName,
                    'cc'        => ($adminEmail && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) ? $adminEmail : '',
                ]);
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?msg=invoice_sent&to=' . urlencode($cEmail));
                exit;
            }
        }
    }

    // ── Notify customer the order has shipped (resendable, no limit) ──
    if ($action === 'ship_notify' && !empty($_POST['order_id'])) {
        $oid          = $_POST['order_id'];
        $carrierIn    = trim((string)($_POST['ship_carrier'] ?? ''));
        $carrierOther = trim((string)($_POST['ship_carrier_other'] ?? ''));
        $tracking     = trim((string)($_POST['ship_tracking'] ?? ''));
        $includeRcpt  = !empty($_POST['include_receipt']);
        $carrier      = ($carrierIn === 'Other' && $carrierOther !== '') ? $carrierOther : $carrierIn;

        // Carrier tracking deep-links
        $trackUrl = '';
        if ($tracking !== '') {
            $tEnc = rawurlencode($tracking);
            switch (strtoupper($carrierIn)) {
                case 'USPS':  $trackUrl = "https://tools.usps.com/go/TrackConfirmAction?tLabels={$tEnc}"; break;
                case 'FEDEX': $trackUrl = "https://www.fedex.com/fedextrack/?trknbr={$tEnc}"; break;
                case 'UPS':   $trackUrl = "https://www.ups.com/track?tracknum={$tEnc}"; break;
                case 'DHL':   $trackUrl = "https://www.dhl.com/us-en/home/tracking.html?tracking-id={$tEnc}"; break;
            }
        }

        $targetEmail = '';
        foreach ($orders as &$o) {
            if (($o['order_id'] ?? '') === $oid) {
                $o = function_exists('mystore_normalize_order') ? mystore_normalize_order($o) : $o;
                if (!isset($o['fulfillment']) || !is_array($o['fulfillment'])) $o['fulfillment'] = [];
                $o['fulfillment']['carrier']  = $carrier;
                $o['fulfillment']['tracking'] = $tracking;
                if (empty($o['fulfillment']['shipped_at'])) $o['fulfillment']['shipped_at'] = date('c');
                $o['fulfillment']['notify_count']     = intval($o['fulfillment']['notify_count'] ?? 0) + 1;
                $o['fulfillment']['last_notified_at'] = date('c');
                $o['status']     = 'shipped';
                $o['updated_at'] = date('c');
                $targetEmail = $o['customer']['email'] ?? '';
                $shipOrder = $o; // snapshot for email building
            }
        }
        unset($o);

        if (!$targetEmail) { header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?msg=no_email'); exit; }
        file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        // ── Build shipped-notification email (mirrors invoice styling) ──
        $cName  = htmlspecialchars($shipOrder['customer']['name'] ?? 'Customer');
        $cEmail = $shipOrder['customer']['email'];

        $siteName = 'Store'; $sitelogo = ''; $ss = [];
        $ssFile = SITE_ROOT . '/admin/data/site-settings.json';
        if (is_file($ssFile)) {
            $ss = json_decode(file_get_contents($ssFile), true) ?: [];
            $siteName = $ss['site_name'] ?? 'Store';
            $sitelogo = $ss['logo'] ?? $ss['logo_full'] ?? '';
        }
        if (!$sitelogo) {
            foreach (['/media/images/logos/logo.png', '/media/images/brand/logo.png', '/media/images/site/logo.png'] as $lp) {
                if (is_file(SITE_ROOT . $lp)) { $sitelogo = $lp; break; }
            }
        }
        $host    = $_SERVER['HTTP_HOST'] ?? '';
        $logoUrl = $sitelogo ? 'https://' . $host . $sitelogo : '';

        $itemsHtml = '';
        foreach ($shipOrder['items'] ?? [] as $it) {
            $itemsHtml .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee">' . htmlspecialchars($it['name'] ?? '') . ' &times;' . intval($it['quantity'] ?? 1) . '</td></tr>';
        }
        $addr = $shipOrder['customer']['address'] ?? $shipOrder['shipping'] ?? [];
        $addrHtml = '';
        if (is_array($addr)) {
            if (($addr['street'] ?? $addr['address'] ?? '')) $addrHtml .= htmlspecialchars($addr['street'] ?? $addr['address'] ?? '') . '<br>';
            $addrHtml .= htmlspecialchars(trim(($addr['city'] ?? '') . ', ' . ($addr['state'] ?? '') . ' ' . ($addr['zip'] ?? ''), ', '));
        }

        $carrierEsc  = htmlspecialchars($carrier ?: 'Carrier');
        $trackingEsc = htmlspecialchars($tracking);

        $subject = "Your order has shipped — {$oid} — {$siteName}";
        $body  = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#222'>";
        if ($logoUrl) $body .= "<div style='text-align:center;padding:20px 0'><img src='{$logoUrl}' alt='" . htmlspecialchars($siteName) . "' style='max-height:80px;max-width:200px'></div>";
        $body .= "<h2 style='color:#f59e0b;text-align:center;margin:0 0 8px'>{$siteName}</h2>";
        $body .= "<h3 style='text-align:center;color:#16a34a;margin:0 0 18px'>&#128230; Your order is on its way!</h3>";
        $body .= "<p>Hi {$cName},</p><p>Good news — your order <strong>{$oid}</strong> has shipped.</p>";
        $body .= "<table style='width:100%;font-size:14px;background:#f8f8f8;border-radius:8px'>";
        $body .= "<tr><td style='padding:10px 14px;color:#888'>Carrier</td><td style='padding:10px 14px;text-align:right;font-weight:700'>{$carrierEsc}</td></tr>";
        if ($trackingEsc !== '') {
            $body .= "<tr><td style='padding:10px 14px;color:#888'>Tracking #</td><td style='padding:10px 14px;text-align:right;font-family:monospace;font-weight:700'>{$trackingEsc}</td></tr>";
        }
        $body .= "</table>";
        if ($trackUrl) {
            $body .= "<div style='text-align:center;margin:18px 0'><a href='{$trackUrl}' style='display:inline-block;background:#f59e0b;color:#000;text-decoration:none;font-weight:700;padding:12px 26px;border-radius:8px'>Track Your Package</a></div>";
        }
        if ($itemsHtml) {
            $body .= "<p style='color:#888;font-size:13px;margin:18px 0 4px'><strong>Items shipped:</strong></p>";
            $body .= "<table style='width:100%;font-size:14px'>{$itemsHtml}</table>";
        }
        if ($addrHtml) {
            $body .= "<p style='font-size:13px;color:#888;margin-top:16px'><strong>Shipping to:</strong><br>{$cName}<br>{$addrHtml}</p>";
        }
        if ($includeRcpt && !empty($shipOrder['fulfillment']['receipt_url']) && is_file(SITE_ROOT . $shipOrder['fulfillment']['receipt_url'])) {
            $rcptUrl = 'https://' . $host . $shipOrder['fulfillment']['receipt_url'];
            if (preg_match('/\.pdf$/i', $rcptUrl)) {
                $body .= "<p style='font-size:13px;margin-top:16px'><strong>Shipping receipt:</strong> <a href='{$rcptUrl}'>View receipt (PDF)</a></p>";
            } else {
                $body .= "<div style='margin-top:18px'><p style='font-size:13px;color:#888;margin:0 0 6px'><strong>Shipping receipt:</strong></p><a href='{$rcptUrl}'><img src='{$rcptUrl}' alt='Shipping receipt' style='max-width:100%;border:1px solid #ddd;border-radius:6px'></a></div>";
            }
        }
        $body .= "<p style='margin-top:24px;color:#888;font-size:12px'>Thank you for your order!</p></body></html>";

        // CR/LF stripping now happens inside mail.php for every transport, so it is no longer
        // done by hand here. The address validation is kept: it decides whether to Cc at all.
        require_once (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/includes/mail.php';
        $adminEmail = $ss['contact_email'] ?? '';
        luminal_send_mail($cEmail, $subject, $body, '', [
            'from_name' => (string)$siteName,
            'cc'        => ($adminEmail && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) ? $adminEmail : '',
        ]);

        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?msg=shipped_notified&to=' . urlencode($cEmail));
        exit;
    }

    if ($action === 'edit_order' && !empty($_POST['order_id'])) {
        $oid = $_POST['order_id'];
        $updates = json_decode($_POST['order_json'] ?? '{}', true);
        if (is_array($updates) && !empty($updates)) {
            foreach ($orders as &$o) {
                if (($o['order_id'] ?? '') === $oid) {
                    // Update allowed fields
                    if (isset($updates['customer'])) $o['customer'] = array_merge($o['customer'] ?? [], $updates['customer']);
                    if (isset($updates['items'])) $o['items'] = $updates['items'];
                    if (isset($updates['totals'])) { $o['totals'] = $updates['totals']; $o['total'] = floatval($updates['totals']['total'] ?? $o['total']); }
                    if (isset($updates['shipping'])) $o['shipping'] = $updates['shipping'];
                    if (isset($updates['notes'])) $o['notes'] = $updates['notes'];
                    if (isset($updates['status'])) $o['status'] = $updates['status'];
                    $o['updated_at'] = date('c');
                }
            }
            unset($o);
            file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    if ($action === 'bulk_action' && !empty($_POST['bulk_op']) && !empty($_POST['selected'])) {
        $op = $_POST['bulk_op'];
        $selected = $_POST['selected'];
        foreach ($orders as &$o) {
            if (in_array($o['order_id'] ?? '', $selected)) {
                if ($op === 'complete') $o['status'] = 'completed';
                elseif ($op === 'processing') $o['status'] = 'processing';
                elseif ($op === 'archive') $o['status'] = 'archived';
                elseif ($op === 'delete') $o['_delete'] = true;
                $o['updated_at'] = date('c');
            }
        }
        unset($o);
        if ($op === 'delete') $orders = array_values(array_filter($orders, fn($o) => empty($o['_delete'])));
        file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// Load and normalize orders
$allOrders = mystore_load_all_orders();

// ── Customer export (CSV / Markdown / Mailchimp) — streamed download, deduped by email ──
if (!empty($_GET['export'])) {
    $exp = $_GET['export'];
    $customers = []; // email(lc) => aggregate
    foreach ($allOrders as $o) {
        $cust  = $o['customer'] ?? [];
        $email = trim($cust['email'] ?? '');
        if ($email === '') continue;
        $key = strtolower($email);
        if (!isset($customers[$key])) {
            $first = $cust['first_name'] ?? '';
            $last  = $cust['last_name'] ?? '';
            $name  = trim($cust['name'] ?? '') ?: trim("$first $last");
            if ($first === '' && $last === '' && $name !== '') {
                $parts = preg_split('/\s+/', $name, 2);
                $first = $parts[0] ?? '';
                $last  = $parts[1] ?? '';
            }
            $customers[$key] = [
                'name' => $name, 'first' => $first, 'last' => $last,
                'email' => $email, 'phone' => $cust['phone'] ?? '',
                'orders' => 0, 'spent' => 0.0, 'last_order' => '',
            ];
        }
        $customers[$key]['orders']++;
        $customers[$key]['spent'] += floatval($o['total'] ?? 0);
        $ts = $o['created_at'] ?? '';
        if ($ts && (!$customers[$key]['last_order'] || strtotime($ts) > strtotime($customers[$key]['last_order']))) {
            $customers[$key]['last_order'] = $ts;
        }
    }
    uasort($customers, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    $stamp = date('Y-m-d');
    $hostSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($_SERVER['HTTP_HOST'] ?? 'store'));

    if ($exp === 'mailchimp') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="mailchimp-' . $hostSlug . '-' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Email Address', 'First Name', 'Last Name', 'Phone']);
        foreach ($customers as $c) fputcsv($out, [$c['email'], $c['first'], $c['last'], $c['phone']]);
        fclose($out);
        exit;
    }
    if ($exp === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="customers-' . $hostSlug . '-' . $stamp . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Email', 'Phone', 'Orders', 'Total Spent', 'Last Order']);
        foreach ($customers as $c) {
            fputcsv($out, [$c['name'], $c['email'], $c['phone'], $c['orders'], number_format($c['spent'], 2, '.', ''), $c['last_order'] ? date('Y-m-d', strtotime($c['last_order'])) : '']);
        }
        fclose($out);
        exit;
    }
    if ($exp === 'md') {
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="customers-' . $hostSlug . '-' . $stamp . '.md"');
        echo "# Customers — " . ($_SERVER['HTTP_HOST'] ?? 'Store') . " ({$stamp})\n\n";
        echo "Total unique customers: " . count($customers) . "\n\n";
        echo "| Name | Email | Phone | Orders | Total Spent |\n";
        echo "| --- | --- | --- | ---: | ---: |\n";
        foreach ($customers as $c) {
            $n = str_replace('|', '\\|', $c['name']);
            $e = str_replace('|', '\\|', $c['email']);
            $p = str_replace('|', '\\|', $c['phone']);
            echo "| {$n} | {$e} | {$p} | {$c['orders']} | \$" . number_format($c['spent'], 2) . " |\n";
        }
        exit;
    }
    // Unknown export type — fall through to normal page
}

// Filter
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');
$sortCol = $_GET['sort'] ?? 'date';
$sortDir = $_GET['dir'] ?? 'desc';

$filtered = $allOrders;
if ($statusFilter) {
    $filtered = array_filter($filtered, fn($o) => ($o['status'] ?? '') === $statusFilter);
}
if ($search) {
    $q = strtolower($search);
    $filtered = array_filter($filtered, function($o) use ($q) {
        return str_contains(strtolower($o['customer']['name'] ?? ''), $q)
            || str_contains(strtolower($o['customer']['email'] ?? ''), $q)
            || str_contains(strtolower($o['order_id'] ?? ''), $q)
            || str_contains(strtolower(implode(' ', array_column($o['items'] ?? [], 'name'))), $q);
    });
}
$filtered = array_values($filtered);

// Sort
usort($filtered, function($a, $b) use ($sortCol, $sortDir) {
    $dir = $sortDir === 'asc' ? 1 : -1;
    switch ($sortCol) {
        case 'customer': $va = $a['customer']['name'] ?? ''; $vb = $b['customer']['name'] ?? ''; return strcasecmp($va, $vb) * $dir;
        case 'total': return (floatval($a['total'] ?? 0) - floatval($b['total'] ?? 0)) * $dir;
        case 'status': return strcasecmp($a['status'] ?? '', $b['status'] ?? '') * $dir;
        case 'order': return strcasecmp($a['order_id'] ?? '', $b['order_id'] ?? '') * $dir;
        default: return (strtotime($a['created_at'] ?? '0') - strtotime($b['created_at'] ?? '0')) * $dir;
    }
});

// Stats — revenue by time period
$now = time();
$todayStart = strtotime('today midnight');
$weekStart = strtotime('monday this week');
$monthStart = strtotime('first day of this month midnight');
$yearStart = strtotime('january 1 this year midnight');

$totalOrders = count($allOrders);
$revenueAll = 0; $revenueToday = 0; $revenueWeek = 0; $revenueMonth = 0; $revenueYear = 0;
$completed = 0; $processing = 0; $onHold = 0;
$ordersToday = 0; $ordersWeek = 0; $ordersMonth = 0; $ordersYear = 0;

foreach ($allOrders as $o) {
    $t = floatval($o['total'] ?? 0);
    $ts = strtotime($o['created_at'] ?? '0');
    $revenueAll += $t;
    if ($ts >= $todayStart) { $revenueToday += $t; $ordersToday++; }
    if ($ts >= $weekStart) { $revenueWeek += $t; $ordersWeek++; }
    if ($ts >= $monthStart) { $revenueMonth += $t; $ordersMonth++; }
    if ($ts >= $yearStart) { $revenueYear += $t; $ordersYear++; }
    if (($o['status'] ?? '') === 'completed') $completed++;
    if (($o['status'] ?? '') === 'processing') $processing++;
    if (($o['status'] ?? '') === 'on-hold') $onHold++;
}

// ── Date-range sales picker (Last 7 / 30 days / Year-to-Date / All-time) ──
$range = $_GET['range'] ?? '30d';
$rangeDefs = [
    '7d'  => ['label' => 'Last 7 Days',  'start' => $now - 7 * 86400],
    '30d' => ['label' => 'Last 30 Days', 'start' => $now - 30 * 86400],
    'ytd' => ['label' => 'Year to Date', 'start' => $yearStart],
    'all' => ['label' => 'All Time',     'start' => 0],
];
if (!isset($rangeDefs[$range])) $range = '30d';
$rangeStart = $rangeDefs[$range]['start'];
$revenueRange = 0; $ordersRange = 0;
foreach ($allOrders as $o) {
    $ts = strtotime($o['created_at'] ?? '0');
    if ($ts >= $rangeStart) { $revenueRange += floatval($o['total'] ?? 0); $ordersRange++; }
}
$aovRange = $ordersRange > 0 ? $revenueRange / $ordersRange : 0;

// Sort link helper
function sortLink($col, $currentCol, $currentDir) {
    $newDir = ($col === $currentCol && $currentDir === 'desc') ? 'asc' : 'desc';
    $params = $_GET;
    $params['sort'] = $col;
    $params['dir'] = $newDir;
    $arrow = ($col === $currentCol) ? ($currentDir === 'asc' ? ' &#9650;' : ' &#9660;') : ' &#8597;';
    return '?' . http_build_query($params) . '">' . ucfirst($col) . $arrow;
}

require_once SITE_ROOT . '/admin/admin_header.php';
?>
<?php $_storePageExists = is_file(SITE_ROOT . '/admin/data/pages/my-store/my-store.json'); ?>
<div class="ord-topnav">
    <div class="ord-topnav-left">
        <a class="ord-nav-btn" href="MyStoreAdmin.php"><i class="fas fa-box"></i> Products</a>
        <a class="ord-nav-btn ord-nav-active" href="orders.php"><i class="fas fa-clipboard-list"></i> Orders</a>
        <a class="ord-nav-btn" href="MyStoreAdmin.php?openSettings=1"><i class="fas fa-cogs"></i> Settings</a>
        <?php if ($_storePageExists): ?>
        <a class="ord-nav-btn" href="/page.php?p=my-store" target="_blank"><i class="fas fa-eye"></i> View Storefront</a>
        <?php endif; ?>
    </div>
    <div class="ord-topnav-right">
        <span class="ord-export-label"><i class="fas fa-users"></i> Export customers:</span>
        <a class="ord-nav-btn ord-nav-export" href="?export=mailchimp"><i class="fab fa-mailchimp"></i> Mailchimp CSV</a>
        <a class="ord-nav-btn ord-nav-export" href="?export=csv"><i class="fas fa-file-csv"></i> CSV</a>
        <a class="ord-nav-btn ord-nav-export" href="?export=md"><i class="fas fa-file-alt"></i> Markdown</a>
        <button type="button" class="ord-nav-btn ord-nav-export" onclick="ordPushLeads()"><i class="fas fa-user-plus"></i> Add to Audience Leads</button>
    </div>
</div>
<h1 class="panel_header_h1">My Store Orders</h1>
<?php if (($_GET['msg'] ?? '') === 'invoice_sent'): ?>
<div style="padding:10px 16px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:8px;color:#4ade80;font-size:0.82rem;margin-bottom:12px">
    <i class="fas fa-check-circle"></i> Invoice sent to <?= htmlspecialchars($_GET['to'] ?? '') ?>
</div>
<?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'shipped_notified'): ?>
<div style="padding:10px 16px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:8px;color:#4ade80;font-size:0.82rem;margin-bottom:12px">
    <i class="fas fa-truck"></i> Shipping notification sent to <?= htmlspecialchars($_GET['to'] ?? '') ?>
</div>
<?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'no_email'): ?>
<div style="padding:10px 16px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;color:#f87171;font-size:0.82rem;margin-bottom:12px">
    <i class="fas fa-exclamation-circle"></i> No customer email on file — could not send notification.
</div>
<?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
.ord-stats{display:flex;gap:12px;margin:12px 0}
.ord-stat{background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:14px 20px;text-align:center;flex:1}
.ord-stat-num{font-size:1.6rem;font-weight:800;color:#f59e0b}
.ord-stat-label{font-size:0.68rem;color:#888;text-transform:uppercase;letter-spacing:0.05em;margin-top:2px}
.ord-toolbar{display:flex;gap:10px;align-items:center;margin:14px 0;flex-wrap:wrap}
.ord-search{padding:7px 12px;border:1px solid rgba(255,255,255,0.1);border-radius:6px;background:rgba(255,255,255,0.04);color:#fff;font-size:0.82rem;width:200px;font-family:inherit}
.ord-search:focus{outline:none;border-color:#f59e0b}
.ord-filter{padding:7px 10px;border:1px solid rgba(255,255,255,0.1);border-radius:6px;background:rgba(255,255,255,0.04);color:#fff;font-size:0.78rem;font-family:inherit;cursor:pointer}
.ord-filter:focus{outline:none;border-color:#f59e0b}
.ord-btn{padding:7px 14px;border:1px solid rgba(255,255,255,0.1);border-radius:6px;background:rgba(255,255,255,0.04);color:#ccc;font-size:0.78rem;cursor:pointer;font-family:inherit;font-weight:600}
.ord-btn:hover{border-color:#f59e0b;color:#f59e0b}
.ord-btn-primary{background:#f59e0b;color:#000;border-color:#f59e0b}
.ord-btn-primary:hover{background:#fbbf24}
.ord-table{width:100%;border-collapse:collapse;font-size:0.82rem;margin-top:8px}
.ord-table th{padding:8px 10px;color:#888;font-size:0.68rem;text-transform:uppercase;letter-spacing:0.05em;text-align:left;border-bottom:2px solid rgba(255,255,255,0.08)}
.ord-table th a{color:#888;text-decoration:none}
.ord-table th a:hover{color:#f59e0b}
.ord-table td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,0.04);vertical-align:top}
.ord-table tr.ord-row{cursor:pointer}
.ord-table tr.ord-row:hover td{background:rgba(255,255,255,0.02)}
.ord-name{font-weight:700;color:#fff;font-size:0.82rem}
.ord-email{font-size:0.72rem;color:#888}
.ord-id{font-family:monospace;font-size:0.75rem;color:#f59e0b;font-weight:600}
.ord-total{font-weight:700;color:#f59e0b;white-space:nowrap}
.ord-items{font-size:0.75rem;color:#ccc;max-width:280px}
.ord-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;text-transform:uppercase}
.ord-badge-processing{background:rgba(59,130,246,0.15);color:#60a5fa}
.ord-badge-completed{background:rgba(34,197,94,0.15);color:#4ade80}
.ord-badge-shipped{background:rgba(16,185,129,0.18);color:#34d399}
.ord-badge-on-hold{background:rgba(234,179,8,0.15);color:#facc15}
.ord-badge-refunded{background:rgba(239,68,68,0.15);color:#f87171}
.ord-badge-cancelled,.ord-badge-archived{background:rgba(107,114,128,0.15);color:#9ca3af}
.ord-date{white-space:nowrap;font-size:0.75rem;color:#aaa}
.ord-detail{display:none;background:rgba(255,255,255,0.02);padding:14px;border-radius:8px}
.ord-detail.open{display:block}
.ord-status-select{padding:4px 8px;border:1px solid rgba(255,255,255,0.1);border-radius:4px;background:rgba(255,255,255,0.04);color:#fff;font-size:0.72rem;font-family:inherit;cursor:pointer}
.ord-cb{width:16px;height:16px;cursor:pointer;accent-color:#f59e0b}
.ord-count{font-size:0.78rem;color:#888}
.ord-range-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px;background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.2);border-radius:12px;padding:12px 16px;margin:12px 0}
.ord-range-tabs{display:flex;gap:6px;flex-wrap:wrap}
.ord-range-tab{padding:6px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;color:#aaa;text-decoration:none;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.03);transition:all .15s}
.ord-range-tab:hover{color:#f59e0b;border-color:rgba(245,158,11,0.4)}
.ord-range-tab.active{background:#f59e0b;color:#000;border-color:#f59e0b}
.ord-range-headline{display:flex;gap:24px}
.ord-range-metric{display:flex;flex-direction:column;align-items:flex-end;line-height:1.1}
.ord-range-num{font-size:1.5rem;font-weight:800;color:#f59e0b}
.ord-range-lbl{font-size:0.62rem;color:#999;text-transform:uppercase;letter-spacing:0.05em;margin-top:3px}
/* ── Top nav + export strip ── */
.ord-topnav{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:12px}
.ord-topnav-left,.ord-topnav-right{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.ord-nav-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1px solid rgba(255,255,255,0.12);border-radius:6px;background:rgba(255,255,255,0.04);color:#ccc;font-size:0.78rem;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none}
.ord-nav-btn:hover{border-color:#f59e0b;color:#f59e0b}
.ord-nav-active{background:#f59e0b;color:#000;border-color:#f59e0b}
.ord-nav-active:hover{color:#000}
.ord-nav-export{font-size:0.74rem;padding:6px 10px}
.ord-nav-export:hover{border-color:#34d399;color:#34d399}
.ord-export-label{font-size:0.72rem;color:#888;margin-right:2px}
/* ── Shipping & Fulfillment ── */
.ord-ship{margin-top:12px;padding:12px;border:1px solid rgba(16,185,129,0.25);background:rgba(16,185,129,0.05);border-radius:8px}
.ord-ship-h{font-size:0.68rem;color:#34d399;text-transform:uppercase;letter-spacing:0.05em;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.ord-ship-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}
.ord-ship-input{padding:6px 10px;border:1px solid rgba(255,255,255,0.12);border-radius:6px;background:rgba(255,255,255,0.04);color:#fff;font-size:0.78rem;font-family:inherit}
.ord-ship-input:focus{outline:none;border-color:#34d399}
.ord-ship-carrier{min-width:110px}
.ord-ship-track{flex:1;min-width:160px;font-family:monospace}
.ord-ship-notify-btn{width:100%;padding:9px 14px;border:none;border-radius:6px;background:#16a34a;color:#fff;font-size:0.82rem;font-weight:700;cursor:pointer;font-family:inherit}
.ord-ship-notify-btn:hover{background:#22c55e}
.ord-ship-meta{font-size:0.7rem;color:#888;margin-top:6px}
.ord-ship-chk{display:flex;align-items:center;gap:6px;font-size:0.74rem;color:#bbb;cursor:pointer;margin-top:4px}
.ord-ship-chk input{accent-color:#34d399;cursor:pointer}
/* receipt drop pad */
.ord-rcpt-pad{border:1.5px dashed rgba(255,255,255,0.18);border-radius:8px;padding:14px;text-align:center;font-size:0.74rem;color:#999;cursor:pointer;transition:all .15s;background:rgba(255,255,255,0.02)}
.ord-rcpt-pad:hover,.ord-rcpt-pad.dragover{border-color:#34d399;color:#34d399;background:rgba(16,185,129,0.06)}
.ord-rcpt-pad.has-file{border-style:solid;border-color:rgba(16,185,129,0.4)}
.ord-rcpt-thumb{max-height:90px;max-width:100%;border-radius:6px;margin:0 auto 6px;display:block}
.ord-rcpt-remove{color:#f87171;font-size:0.7rem;cursor:pointer;text-decoration:underline;background:none;border:none;font-family:inherit;margin-top:4px}
.ord-rcpt-busy{color:#facc15}
</style>

<!-- Date-range sales picker -->
<div class="ord-range-bar">
    <div class="ord-range-tabs">
        <?php foreach ($rangeDefs as $rk => $rd):
            $rp = $_GET; $rp['range'] = $rk; ?>
        <a href="?<?= htmlspecialchars(http_build_query($rp)) ?>" class="ord-range-tab<?= $rk === $range ? ' active' : '' ?>"><?= $rd['label'] ?></a>
        <?php endforeach; ?>
    </div>
    <div class="ord-range-headline">
        <div class="ord-range-metric"><span class="ord-range-num">$<?= number_format($revenueRange, 2) ?></span><span class="ord-range-lbl">Revenue · <?= htmlspecialchars($rangeDefs[$range]['label']) ?></span></div>
        <div class="ord-range-metric"><span class="ord-range-num"><?= $ordersRange ?></span><span class="ord-range-lbl">Orders</span></div>
        <div class="ord-range-metric"><span class="ord-range-num">$<?= number_format($aovRange, 2) ?></span><span class="ord-range-lbl">Avg Order</span></div>
    </div>
</div>

<!-- Revenue Scoreboard -->
<div class="ord-stats">
    <div class="ord-stat"><div class="ord-stat-num" style="color:#4ade80">$<?= number_format($revenueToday, 2) ?></div><div class="ord-stat-label">Today (<?= $ordersToday ?> orders)</div></div>
    <div class="ord-stat"><div class="ord-stat-num" style="color:#4ade80">$<?= number_format($revenueWeek, 2) ?></div><div class="ord-stat-label">This Week (<?= $ordersWeek ?>)</div></div>
    <div class="ord-stat"><div class="ord-stat-num" style="color:#4ade80">$<?= number_format($revenueMonth, 2) ?></div><div class="ord-stat-label">This Month (<?= $ordersMonth ?>)</div></div>
    <div class="ord-stat"><div class="ord-stat-num" style="color:#4ade80">$<?= number_format($revenueYear, 2) ?></div><div class="ord-stat-label">This Year (<?= $ordersYear ?>)</div></div>
</div>
<div class="ord-stats" style="margin-top:0">
    <div class="ord-stat"><div class="ord-stat-num"><?= $totalOrders ?></div><div class="ord-stat-label">Total Orders</div></div>
    <div class="ord-stat"><div class="ord-stat-num" style="color:#4ade80">$<?= number_format($revenueAll, 2) ?></div><div class="ord-stat-label">All-Time Revenue</div></div>
    <div class="ord-stat"><div class="ord-stat-num" style="color:#4ade80"><?= $completed ?></div><div class="ord-stat-label">Completed</div></div>
    <div class="ord-stat"><div class="ord-stat-num" style="color:#60a5fa"><?= $processing ?></div><div class="ord-stat-label">Processing</div></div>
    <div class="ord-stat"><div class="ord-stat-num" style="color:#facc15"><?= $onHold ?></div><div class="ord-stat-label">On Hold</div></div>
</div>

<!-- Toolbar -->
<form method="get" class="ord-toolbar">
    <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
    <input type="text" name="q" class="ord-search" placeholder="Search orders..." value="<?= htmlspecialchars($search) ?>">
    <select name="status" class="ord-filter" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <option value="processing" <?= $statusFilter === 'processing' ? 'selected' : '' ?>>Processing</option>
        <option value="shipped" <?= $statusFilter === 'shipped' ? 'selected' : '' ?>>Shipped</option>
        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="on-hold" <?= $statusFilter === 'on-hold' ? 'selected' : '' ?>>On Hold</option>
        <option value="refunded" <?= $statusFilter === 'refunded' ? 'selected' : '' ?>>Refunded</option>
        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
    </select>
    <button type="submit" class="ord-btn">Search</button>
    <?php if ($search || $statusFilter): ?>
        <a href="?" class="ord-btn">Clear</a>
    <?php endif; ?>
    <span class="ord-count" style="margin-left:auto">Showing <?= count($filtered) ?> of <?= $totalOrders ?> orders</span>
</form>

<!-- Bulk Actions -->
<form method="post" id="bulkForm">
    <input type="hidden" name="ord_action" value="bulk_action">
    <div class="ord-toolbar" style="margin-top:0">
        <select name="bulk_op" class="ord-filter">
            <option value="">Bulk Actions</option>
            <option value="complete">Mark Completed</option>
            <option value="processing">Mark Processing</option>
            <option value="archive">Archive</option>
            <option value="delete">Delete</option>
        </select>
        <button type="submit" class="ord-btn" onclick="return confirm('Apply bulk action to selected orders?')">Apply</button>
        <button type="button" class="ord-btn" onclick="document.querySelectorAll('.ord-cb').forEach(c=>c.checked=!c.checked)">Toggle All</button>
    </div>

<!-- Orders Table -->
<table class="ord-table">
<thead>
<tr>
    <th style="width:30px"><input type="checkbox" class="ord-cb" onclick="document.querySelectorAll('.ord-row-cb').forEach(c=>c.checked=this.checked)"></th>
    <th><a href="<?= sortLink('customer', $sortCol, $sortDir) ?></a></th>
    <th><a href="<?= sortLink('order', $sortCol, $sortDir) ?></a></th>
    <th><a href="<?= sortLink('date', $sortCol, $sortDir) ?></a></th>
    <th>Items</th>
    <th><a href="<?= sortLink('total', $sortCol, $sortDir) ?></a></th>
    <th>Payment</th>
    <th><a href="<?= sortLink('status', $sortCol, $sortDir) ?></a></th>
</tr>
</thead>
<tbody>
<?php foreach ($filtered as $idx => $o):
    $name = htmlspecialchars($o['customer']['name'] ?? 'Unknown');
    $email = htmlspecialchars($o['customer']['email'] ?? '');
    $oid = htmlspecialchars($o['order_id'] ?? '');
    $total = number_format(floatval($o['total'] ?? 0), 2);
    $status = $o['status'] ?? 'unknown';
    $payment = htmlspecialchars($o['payment_method'] ?? '');
    $created = $o['created_at'] ?? '';
    $dateStr = $created ? date('M j, g:i A', strtotime($created)) : '';
    $itemsStr = implode(', ', array_map(function($i) {
        return htmlspecialchars($i['name'] ?? '') . ' x' . intval($i['quantity'] ?? 1);
    }, $o['items'] ?? []));
    $badgeClass = 'ord-badge-' . str_replace(' ', '-', $status);
    $txn = htmlspecialchars($o['paypal_txn_id'] ?? '');
    $addr = $o['customer']['address'] ?? $o['shipping'] ?? [];
    if (is_array($addr)) {
        $addrStr = implode(', ', array_filter([$addr['street'] ?? $addr['address'] ?? '', $addr['city'] ?? '', $addr['state'] ?? '', $addr['zip'] ?? '']));
    } else {
        $addrStr = (string)$addr;
    }
?>
<tr class="ord-row" onclick="if(event.target.type!=='checkbox')this.nextElementSibling.querySelector('.ord-detail').classList.toggle('open')">
    <td onclick="event.stopPropagation()"><input type="checkbox" name="selected[]" value="<?= $oid ?>" class="ord-cb ord-row-cb"></td>
    <td><div class="ord-name"><?= $name ?></div><div class="ord-email"><?= $email ?></div></td>
    <td><div class="ord-id"><?= $oid ?></div></td>
    <td><div class="ord-date"><?= $dateStr ?></div></td>
    <td><div class="ord-items"><?= $itemsStr ?></div></td>
    <td><div class="ord-total">$<?= $total ?></div></td>
    <td style="font-size:0.75rem;color:#aaa"><?= $payment ?></td>
    <td><span class="ord-badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span></td>
</tr>
<tr><td colspan="8" style="padding:0 10px">
    <div class="ord-detail">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:14px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);font-size:0.8rem">
            <!-- Customer / Actions column -->
            <div>
                <div style="font-size:0.68rem;color:#888;text-transform:uppercase;margin-bottom:6px;font-weight:700">Customer</div>
                <div style="color:#ccc;margin-bottom:4px"><?= $name ?></div>
                <div style="color:#888;margin-bottom:4px"><?= $email ?></div>
                <?php if ($o['customer']['phone'] ?? ''): ?><div style="color:#888;margin-bottom:4px"><?= htmlspecialchars($o['customer']['phone']) ?></div><?php endif; ?>

                <div style="margin-top:8px;padding:8px 10px;background:rgba(255,255,255,0.03);border-radius:6px;font-size:0.78rem;line-height:1.6">
                    <div style="font-size:0.65rem;color:#555;text-transform:uppercase;margin-bottom:4px;font-weight:700">Shipping Address</div>
                    <div style="color:#ccc"><?= $name ?></div>
                    <?php
                    $street = htmlspecialchars($addr['street'] ?? $addr['address'] ?? '');
                    $city = htmlspecialchars($addr['city'] ?? '');
                    $state = htmlspecialchars($addr['state'] ?? '');
                    $zip = htmlspecialchars($addr['zip'] ?? '');
                    ?>
                    <?php if ($street): ?><div style="color:#aaa"><?= $street ?></div><?php endif; ?>
                    <div style="color:#aaa"><?= trim("$city, $state $zip", ', ') ?></div>
                </div>

                <div style="font-size:0.68rem;color:#888;text-transform:uppercase;margin:12px 0 6px;font-weight:700">Payment</div>
                <div style="color:#aaa"><?= $payment ?></div>
                <?php if ($txn): ?><div style="color:#555;font-size:0.72rem;font-family:monospace;margin-top:2px"><?= $txn ?></div><?php endif; ?>
                <div style="color:#666;font-size:0.72rem;margin-top:4px"><?= htmlspecialchars($o['created_at'] ?? '') ?></div>

                <!-- Actions -->
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:14px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.06)" onclick="event.stopPropagation()">
                    <select class="ord-status-select" onchange="if(this.value)ordChangeStatus('<?= $oid ?>',this.value)">
                        <option value="">Change Status...</option>
                        <?php foreach (['completed','processing','shipped','on-hold','refunded','cancelled','archived'] as $s): ?>
                            <?php if ($s !== $status): ?><option value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <button class="ord-btn" onclick="ordSendInvoice('<?= $oid ?>')"><i class="fas fa-envelope"></i> Send Invoice</button>
                    <button class="ord-btn" onclick="ordPrint('<?= $oid ?>','invoice')"><i class="fas fa-file-invoice"></i> Print Invoice</button>
                    <button class="ord-btn" onclick="ordPrint('<?= $oid ?>','packing')"><i class="fas fa-box"></i> Packing Slip</button>
                    <button class="ord-btn" onclick="ordEdit('<?= $oid ?>')"><i class="fas fa-pen"></i> Edit Order</button>
                </div>

                <!-- Shipping & Fulfillment -->
                <?php
                $ful            = $o['fulfillment'] ?? [];
                $knownCarriers  = ['USPS','FedEx','UPS','DHL'];
                $savedCarrier   = $ful['carrier'] ?? '';
                $carrierSel     = in_array($savedCarrier, $knownCarriers, true) ? $savedCarrier : ($savedCarrier !== '' ? 'Other' : '');
                $carrierOther   = ($carrierSel === 'Other') ? htmlspecialchars($savedCarrier) : '';
                $savedTracking  = htmlspecialchars($ful['tracking'] ?? '');
                $rcptUrl        = htmlspecialchars($ful['receipt_url'] ?? '');
                $notifyCount    = intval($ful['notify_count'] ?? 0);
                $lastNotified   = $ful['last_notified_at'] ?? '';
                ?>
                <div class="ord-ship" onclick="event.stopPropagation()">
                    <div class="ord-ship-h"><i class="fas fa-truck"></i> Shipping &amp; Fulfillment</div>
                    <div class="ord-ship-row">
                        <select class="ord-ship-input ord-ship-carrier" id="shipCarrier<?= $idx ?>" onchange="ordShipCarrierToggle(<?= $idx ?>)">
                            <option value="">Carrier…</option>
                            <?php foreach ($knownCarriers as $cc): ?>
                            <option value="<?= $cc ?>" <?= $carrierSel === $cc ? 'selected' : '' ?>><?= $cc ?></option>
                            <?php endforeach; ?>
                            <option value="Other" <?= $carrierSel === 'Other' ? 'selected' : '' ?>>Other…</option>
                        </select>
                        <input type="text" class="ord-ship-input ord-ship-carrier" id="shipCarrierOther<?= $idx ?>" placeholder="Carrier name" value="<?= $carrierOther ?>" style="<?= $carrierSel === 'Other' ? '' : 'display:none' ?>">
                        <input type="text" class="ord-ship-input ord-ship-track" id="shipTrack<?= $idx ?>" placeholder="Tracking number" value="<?= $savedTracking ?>">
                    </div>

                    <!-- Receipt drop pad (auto-saves on drop) -->
                    <div class="ord-rcpt-pad<?= $rcptUrl ? ' has-file' : '' ?>" id="rcptPad<?= $idx ?>"
                         data-oid="<?= $oid ?>" data-idx="<?= $idx ?>"
                         onclick="document.getElementById('rcptInput<?= $idx ?>').click()">
                        <?php if ($rcptUrl): ?>
                            <?php if (preg_match('/\.pdf$/i', $rcptUrl)): ?>
                                <div><i class="fas fa-file-pdf" style="font-size:1.6rem;color:#34d399"></i></div>
                                <div style="margin-top:4px;color:#34d399">Receipt attached (PDF)</div>
                            <?php else: ?>
                                <img src="<?= $rcptUrl ?>" alt="Receipt" class="ord-rcpt-thumb">
                                <div style="color:#34d399">Receipt attached</div>
                            <?php endif; ?>
                            <button type="button" class="ord-rcpt-remove" onclick="event.stopPropagation();ordRcptRemove(<?= $idx ?>)">Remove receipt</button>
                        <?php else: ?>
                            <i class="fas fa-receipt"></i> Drop a shipping receipt here, or click to upload
                        <?php endif; ?>
                    </div>
                    <input type="file" id="rcptInput<?= $idx ?>" accept="image/*,application/pdf" style="display:none" onchange="ordRcptUpload(<?= $idx ?>, this.files[0])">

                    <label class="ord-ship-chk"><input type="checkbox" id="shipIncRcpt<?= $idx ?>" <?= $rcptUrl ? 'checked' : '' ?>> Include shipping receipt in the email?</label>

                    <div style="margin-top:10px">
                        <button type="button" class="ord-ship-notify-btn" onclick="ordShipNotify(<?= $idx ?>)">
                            <i class="fas fa-paper-plane"></i> <?= $notifyCount > 0 ? 'Re-send Shipping Notification' : 'Notify Customer — Item Has Shipped' ?>
                        </button>
                    </div>
                    <?php if ($notifyCount > 0): ?>
                    <div class="ord-ship-meta"><i class="fas fa-check"></i> Notified <?= $notifyCount ?>&times; · last <?= htmlspecialchars(date('M j, g:i A', strtotime($lastNotified))) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Items / Invoice column -->
            <div>
                <div style="font-size:0.68rem;color:#888;text-transform:uppercase;margin-bottom:6px;font-weight:700">Items</div>
                <?php foreach ($o['items'] ?? [] as $item):
                    $iName = htmlspecialchars($item['name'] ?? '');
                    $iQty = intval($item['quantity'] ?? 1);
                    $iTotal = number_format(floatval($item['total'] ?? ($item['price'] ?? 0) * $iQty), 2);
                    $iVars = '';
                    if (!empty($item['variations']) && is_array($item['variations'])) {
                        $parts = [];
                        foreach ($item['variations'] as $vk => $vv) $parts[] = htmlspecialchars("$vk: $vv");
                        $iVars = implode(', ', $parts);
                    }
                ?>
                <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.04)">
                    <span style="color:#ccc"><?= $iName ?> <span style="color:#666">x<?= $iQty ?></span><?php if ($iVars): ?><br><span style="font-size:0.68rem;color:#555"><?= $iVars ?></span><?php endif; ?></span>
                    <span style="color:#aaa;font-weight:600">$<?= $iTotal ?></span>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:8px;padding-top:6px;border-top:1px solid rgba(255,255,255,0.08)">
                    <?php
                    $subtotal = number_format(floatval($o['totals']['subtotal'] ?? $o['subtotal'] ?? $o['total'] ?? 0), 2);
                    $tax = number_format(floatval($o['totals']['tax'] ?? $o['tax'] ?? 0), 2);
                    $shipping = floatval($o['totals']['shipping'] ?? $o['shipping_cost'] ?? 0);
                    $orderTotal = number_format(floatval($o['total'] ?? 0), 2);
                    ?>
                    <div style="display:flex;justify-content:space-between;color:#888"><span>Subtotal</span><span>$<?= $subtotal ?></span></div>
                    <div style="display:flex;justify-content:space-between;color:#888"><span>Tax</span><span>$<?= $tax ?></span></div>
                    <div style="display:flex;justify-content:space-between;color:#888"><span>Shipping</span><span><?= $shipping == 0 ? 'FREE' : '$' . number_format($shipping, 2) ?></span></div>
                    <div style="display:flex;justify-content:space-between;font-weight:700;color:#f59e0b;margin-top:4px;font-size:0.9rem"><span>Total</span><span>$<?= $orderTotal ?></span></div>
                </div>
            </div>
        </div>
        <?php if (!empty($o['notes'])): ?>
        <div style="margin-top:10px;padding:6px 10px;background:rgba(255,255,255,0.03);border-radius:6px;font-size:0.75rem;color:#aaa">
            <strong>Notes:</strong> <?= htmlspecialchars($o['notes']) ?>
        </div>
        <?php endif; ?>
    </div>
</td></tr>
<?php endforeach; ?>
</tbody>
</table>
</form>

<?php if (empty($filtered)): ?>
<div style="text-align:center;padding:40px;color:#888">
    <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:10px;display:block"></i>
    No orders match your filters.
</div>
<?php endif; ?>

<!-- Hidden forms (outside bulkForm) -->
<form method="post" id="statusForm" style="display:none">
    <input type="hidden" name="ord_action" value="update_status">
    <input type="hidden" name="order_id" id="statusOrderId">
    <input type="hidden" name="new_status" id="statusNewStatus">
</form>
<form method="post" id="invoiceForm" style="display:none">
    <input type="hidden" name="ord_action" value="send_invoice">
    <input type="hidden" name="order_id" id="invoiceOrderId">
</form>
<form method="post" id="shipNotifyForm" style="display:none">
    <input type="hidden" name="ord_action" value="ship_notify">
    <input type="hidden" name="order_id" id="shipOrderId">
    <input type="hidden" name="ship_carrier" id="shipCarrierVal">
    <input type="hidden" name="ship_carrier_other" id="shipCarrierOtherVal">
    <input type="hidden" name="ship_tracking" id="shipTrackingVal">
    <input type="hidden" name="include_receipt" id="shipIncRcptVal">
</form>
<form method="post" id="editForm" style="display:none">
    <input type="hidden" name="ord_action" value="edit_order">
    <input type="hidden" name="order_id" id="editOrderId">
    <input type="hidden" name="order_json" id="editOrderJson">
</form>

<!-- Edit Modal -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:none;align-items:center;justify-content:center">
<div style="background:#13151a;border:1px solid rgba(255,255,255,0.1);border-radius:14px;width:700px;max-width:94vw;max-height:90vh;overflow-y:auto;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,0.5)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3 style="margin:0;color:#f59e0b">Edit Order</h3>
        <button onclick="document.getElementById('editModal').style.display='none'" style="background:none;border:none;color:#888;font-size:1.5rem;cursor:pointer">&times;</button>
    </div>
    <div id="editModalBody"></div>
    <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
        <button onclick="document.getElementById('editModal').style.display='none'" class="ord-btn">Cancel</button>
        <button onclick="ordSaveEdit()" class="ord-btn ord-btn-primary">Save Changes</button>
    </div>
</div>
</div>

<?php
// Pass orders data to JS for edit modal
$ordersJson = json_encode($filtered);
?>
<script>
<?php
// Resolve logo for JS
$_jsLogo = '';
$_jsSiteName = 'Store';
$_ssFile = SITE_ROOT . '/admin/data/site-settings.json';
if (is_file($_ssFile)) {
    $_ss = json_decode(file_get_contents($_ssFile), true);
    $_jsSiteName = $_ss['site_name'] ?? 'Store';
    $_jsLogo = $_ss['logo'] ?? $_ss['logo_full'] ?? '';
}
if (!$_jsLogo) {
    foreach (['/media/images/logos/logo.png', '/media/images/brand/logo.png', '/media/images/site/logo.png'] as $_lp) {
        if (is_file(SITE_ROOT . $_lp)) { $_jsLogo = $_lp; break; }
    }
}
?>
var _siteLogo = <?= json_encode($_jsLogo) ?>;
var _siteName = <?= json_encode($_jsSiteName) ?>;
var _ordersData = <?= $ordersJson ?>;

function ordChangeStatus(oid, status) {
    document.getElementById('statusOrderId').value = oid;
    document.getElementById('statusNewStatus').value = status;
    document.getElementById('statusForm').submit();
}

function ordSendInvoice(oid) {
    if (!confirm('Send invoice email to the customer?')) return;
    document.getElementById('invoiceOrderId').value = oid;
    document.getElementById('invoiceForm').submit();
}

function ordPrint(oid, type) {
    var ord = _ordersData.find(function(o){ return o.order_id === oid; });
    if (!ord) return;
    var w = window.open('', 'print_' + oid, 'width=600,height=700');
    var h = '<html><head><title>' + (type === 'packing' ? 'Packing Slip' : 'Invoice') + ' — ' + oid + '</title>';
    h += '<style>body{font-family:Arial,sans-serif;padding:30px;color:#333}table{width:100%;border-collapse:collapse}td,th{padding:6px 0;text-align:left}.r{text-align:right}.line{border-bottom:1px solid #ddd}.tot{border-top:2px solid #333;font-weight:800;font-size:16px}h1{font-size:20px;margin-bottom:4px}.logo{text-align:center;margin-bottom:10px}@media print{button{display:none}}</style></head><body>';
    h += '<div class="logo"><img src="' + _siteLogo + '" alt="" style="max-height:80px;max-width:200px"></div>';
    h += '<h1 style="text-align:center">' + _siteName + '</h1>';
    h += '<h2 style="text-align:center;color:#888;font-size:14px;font-weight:400;margin-bottom:20px">' + (type === 'packing' ? 'Packing Slip' : 'Invoice') + '</h2>';
    h += '<p style="color:#888;margin-bottom:20px">Order: ' + oid + ' | Date: ' + (ord.created_at || '') + '</p>';
    if (type !== 'packing') {
        h += '<table><tr><td style="vertical-align:top;width:50%"><strong>Bill To:</strong><br>' + (ord.customer.name||'') + '<br>' + (ord.customer.email||'') + '</td>';
    } else {
        h += '<table><tr>';
    }
    var addr = ord.customer.address || ord.shipping || {};
    h += '<td style="vertical-align:top"><strong>Ship To:</strong><br>' + (ord.customer.name||'') + '<br>';
    h += (addr.street || addr.address || '') + '<br>' + (addr.city||'') + ', ' + (addr.state||'') + ' ' + (addr.zip||'') + '</td></tr></table>';
    h += '<hr style="margin:16px 0"><table>';
    h += '<tr style="border-bottom:2px solid #333"><th>Item</th><th class="r">Qty</th>' + (type !== 'packing' ? '<th class="r">Price</th>' : '') + '</tr>';
    (ord.items||[]).forEach(function(it){
        h += '<tr class="line"><td>' + (it.name||'') + '</td><td class="r">' + (it.quantity||1) + '</td>';
        if (type !== 'packing') h += '<td class="r">$' + (it.total||0).toFixed(2) + '</td>';
        h += '</tr>';
    });
    if (type !== 'packing') {
        h += '<tr><td></td><td class="r" style="color:#888">Subtotal</td><td class="r">$' + ((ord.totals||{}).subtotal||ord.total||0).toFixed(2) + '</td></tr>';
        h += '<tr><td></td><td class="r" style="color:#888">Tax</td><td class="r">$' + ((ord.totals||{}).tax||0).toFixed(2) + '</td></tr>';
        h += '<tr><td></td><td class="r" style="color:#888">Shipping</td><td class="r">' + (((ord.totals||{}).shipping||0)==0?'FREE':'$'+((ord.totals||{}).shipping||0).toFixed(2)) + '</td></tr>';
        h += '<tr class="tot"><td></td><td class="r">Total</td><td class="r">$' + (ord.total||0).toFixed(2) + '</td></tr>';
    }
    h += '</table>';
    if (ord.notes) h += '<p style="margin-top:16px;color:#888"><strong>Notes:</strong> ' + ord.notes + '</p>';
    h += '<button onclick="window.print()" style="margin-top:20px;padding:10px 24px;background:#f59e0b;color:#000;border:none;border-radius:6px;font-weight:700;cursor:pointer;font-size:14px">Print</button>';
    h += '</body></html>';
    w.document.write(h);
    w.document.close();
}

var _editingOid = '';
function ordEdit(oid) {
    var ord = _ordersData.find(function(o){ return o.order_id === oid; });
    if (!ord) return;
    _editingOid = oid;
    var addr = ord.customer.address || ord.shipping || {};
    var body = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:0.82rem">';
    body += '<div><label class="ord-edit-label">Name</label><input type="text" id="editName" value="' + (ord.customer.name||'').replace(/"/g,'&quot;') + '" class="ord-edit-input"></div>';
    body += '<div><label class="ord-edit-label">Email</label><input type="text" id="editEmail" value="' + (ord.customer.email||'').replace(/"/g,'&quot;') + '" class="ord-edit-input"></div>';
    body += '<div><label class="ord-edit-label">Phone</label><input type="text" id="editPhone" value="' + (ord.customer.phone||'').replace(/"/g,'&quot;') + '" class="ord-edit-input"></div>';
    body += '<div><label class="ord-edit-label">Status</label><select id="editStatus" class="ord-edit-input">';
    ['processing','shipped','completed','on-hold','refunded','cancelled','archived'].forEach(function(s){
        body += '<option value="'+s+'"'+(s===ord.status?' selected':'')+'>'+s.charAt(0).toUpperCase()+s.slice(1)+'</option>';
    });
    body += '</select></div>';
    body += '<div><label class="ord-edit-label">Street</label><input type="text" id="editStreet" value="' + (addr.street||addr.address||'').replace(/"/g,'&quot;') + '" class="ord-edit-input"></div>';
    body += '<div><label class="ord-edit-label">City</label><input type="text" id="editCity" value="' + (addr.city||'').replace(/"/g,'&quot;') + '" class="ord-edit-input"></div>';
    body += '<div><label class="ord-edit-label">State</label><input type="text" id="editState" value="' + (addr.state||'').replace(/"/g,'&quot;') + '" class="ord-edit-input"></div>';
    body += '<div><label class="ord-edit-label">Zip</label><input type="text" id="editZip" value="' + (addr.zip||'').replace(/"/g,'&quot;') + '" class="ord-edit-input"></div>';
    body += '</div>';
    body += '<div style="margin-top:12px"><label class="ord-edit-label">Notes</label><textarea id="editNotes" class="ord-edit-input" rows="3">' + (ord.notes||'') + '</textarea></div>';
    document.getElementById('editModalBody').innerHTML = body;
    document.getElementById('editModal').style.display = 'flex';
}

function ordSaveEdit() {
    var updates = {
        customer: {
            name: document.getElementById('editName').value,
            email: document.getElementById('editEmail').value,
            phone: document.getElementById('editPhone').value,
            address: {
                street: document.getElementById('editStreet').value,
                city: document.getElementById('editCity').value,
                state: document.getElementById('editState').value,
                zip: document.getElementById('editZip').value
            }
        },
        status: document.getElementById('editStatus').value,
        notes: document.getElementById('editNotes').value
    };
    document.getElementById('editOrderId').value = _editingOid;
    document.getElementById('editOrderJson').value = JSON.stringify(updates);
    document.getElementById('editForm').submit();
}

// ── Shipping & Fulfillment ──
var _rcptUploadUrl = '<?= htmlspecialchars(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/')) ?>/api/order-receipt-upload.php';

function ordShipCarrierToggle(idx) {
    var sel = document.getElementById('shipCarrier' + idx);
    var other = document.getElementById('shipCarrierOther' + idx);
    other.style.display = (sel.value === 'Other') ? '' : 'none';
}

function ordShipNotify(idx) {
    var oid = document.getElementById('rcptPad' + idx).getAttribute('data-oid');
    var carrier = document.getElementById('shipCarrier' + idx).value;
    var other   = document.getElementById('shipCarrierOther' + idx).value.trim();
    var track   = document.getElementById('shipTrack' + idx).value.trim();
    var incRcpt = document.getElementById('shipIncRcpt' + idx).checked;
    if (!carrier) { alert('Please choose a shipping carrier.'); return; }
    if (carrier === 'Other' && !other) { alert('Please enter the carrier name.'); return; }
    if (!confirm('Email the customer that this order has shipped?')) return;
    document.getElementById('shipOrderId').value = oid;
    document.getElementById('shipCarrierVal').value = carrier;
    document.getElementById('shipCarrierOtherVal').value = other;
    document.getElementById('shipTrackingVal').value = track;
    document.getElementById('shipIncRcptVal').value = incRcpt ? '1' : '';
    document.getElementById('shipNotifyForm').submit();
}

function ordRcptUpload(idx, file) {
    if (!file) return;
    var pad = document.getElementById('rcptPad' + idx);
    var oid = pad.getAttribute('data-oid');
    var fd = new FormData();
    fd.append('op', 'upload');
    fd.append('order_id', oid);
    fd.append('receipt', file);
    pad.innerHTML = '<span class="ord-rcpt-busy"><i class="fas fa-spinner fa-spin"></i> Uploading…</span>';
    fetch(_rcptUploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) { pad.innerHTML = '<span style="color:#f87171">' + (d.error || 'Upload failed') + '</span>'; return; }
            var isPdf = /\.pdf$/i.test(d.url);
            pad.classList.add('has-file');
            pad.innerHTML = (isPdf
                    ? '<div><i class="fas fa-file-pdf" style="font-size:1.6rem;color:#34d399"></i></div><div style="margin-top:4px;color:#34d399">Receipt attached (PDF)</div>'
                    : '<img src="' + d.url + '" alt="Receipt" class="ord-rcpt-thumb"><div style="color:#34d399">Receipt attached</div>')
                + '<button type="button" class="ord-rcpt-remove" onclick="event.stopPropagation();ordRcptRemove(' + idx + ')">Remove receipt</button>';
            var chk = document.getElementById('shipIncRcpt' + idx);
            if (chk) chk.checked = true;
        })
        .catch(function(){ pad.innerHTML = '<span style="color:#f87171">Upload error</span>'; });
}

function ordRcptRemove(idx) {
    var pad = document.getElementById('rcptPad' + idx);
    var oid = pad.getAttribute('data-oid');
    if (!confirm('Remove the uploaded receipt?')) return;
    var fd = new FormData();
    fd.append('op', 'remove');
    fd.append('order_id', oid);
    fetch(_rcptUploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(){
            pad.classList.remove('has-file');
            pad.innerHTML = '<i class="fas fa-receipt"></i> Drop a shipping receipt here, or click to upload';
            var chk = document.getElementById('shipIncRcpt' + idx);
            if (chk) chk.checked = false;
        });
}

// Push all order customers into AudienceBuilder leads
function ordPushLeads() {
    if (!confirm('Add all order customers (by email) to Audience Leads? Existing leads are skipped automatically.')) return;
    var fd = new FormData();
    fd.append('ord_action', 'push_leads');
    fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d && d.ok) { alert('Audience Leads updated:\n' + d.added + ' added, ' + d.skipped + ' skipped (already a lead or no email).'); }
            else { alert('Could not add leads.'); }
        })
        .catch(function(){ alert('Error adding leads.'); });
}

// Drag-and-drop wiring for every receipt pad
document.querySelectorAll('.ord-rcpt-pad').forEach(function(pad){
    var idx = pad.getAttribute('data-idx');
    ['dragenter','dragover'].forEach(function(ev){
        pad.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); pad.classList.add('dragover'); });
    });
    ['dragleave','drop'].forEach(function(ev){
        pad.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); pad.classList.remove('dragover'); });
    });
    pad.addEventListener('drop', function(e){
        var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) ordRcptUpload(idx, f);
    });
});
</script>
<style>
.ord-edit-label{display:block;font-size:0.68rem;color:#888;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;font-weight:700}
.ord-edit-input{width:100%;padding:7px 10px;border:1px solid rgba(255,255,255,0.1);border-radius:6px;background:rgba(255,255,255,0.04);color:#fff;font-size:0.82rem;font-family:inherit}
.ord-edit-input:focus{outline:none;border-color:#f59e0b}
</style>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
