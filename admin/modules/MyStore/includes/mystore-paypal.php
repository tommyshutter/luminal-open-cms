<?php
/**
 * MyStore — PayPal on the server (2026-11).
 *
 * The server handles the money side, the way PaymentProviders/checkout.php does it:
 *
 *   create_order      the server prices the cart, saves the order (pending) and creates the PayPal
 *                     order for exactly that total, with our order id as invoice_id
 *   complete_payment  the server captures that PayPal order itself and checks PayPal's answer:
 *                     COMPLETED, our invoice id, the same amount and currency. Only then is the
 *                     order marked completed, once (a repeat call changes nothing and sends nothing)
 *
 * Credentials are the site's Payment Providers PayPal keys (admin/data/PaymentProviders/config.json,
 * the secret may be sealed with cred_vault). The browser's PayPal button uses the same client id, as
 * PayPal requires. A store with no keys shows no PayPal button.
 *
 * @module MyStore
 */
declare(strict_types=1);

if (!function_exists('ms_pp_creds')) {

    /** ['id','secret','host'] for this site's PayPal app, or null when it is not set up. */
    function ms_pp_creds(array $msSettings = []): ?array {
        $f = SITE_ROOT . '/admin/data/PaymentProviders/config.json';
        $cfg = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        if (!function_exists('cred_vault_reveal_deep') && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
            require_once SITE_ROOT . '/admin/config/cred_vault.php';
        }
        if (function_exists('cred_vault_reveal_deep')) {
            try { $cfg = cred_vault_reveal_deep($cfg); } catch (\Throwable $e) { return null; }
        }
        $pp = (array)($cfg['gateways']['paypal'] ?? []);
        $id = (string)($pp['clientId'] ?? ''); $secret = (string)($pp['clientSecret'] ?? '');
        if ($id === '' || $secret === '' || (isset($pp['enabled']) && !$pp['enabled'])) return null;
        $sandbox = ($msSettings['paypal_mode'] ?? '') !== '' ? ($msSettings['paypal_mode'] === 'sandbox') : !empty($pp['sandbox']);
        $host = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        if (defined('MS_PAYPAL_TEST_BASE')) $host = (string)MS_PAYPAL_TEST_BASE;   // test rigs only
        return ['id' => $id, 'secret' => $secret, 'host' => $host];
    }

    /** [http code, decoded body]. Code 0 = no answer. */
    function ms_pp_http(string $method, string $url, array $headers, ?string $body = null, ?string $userpwd = null): array {
        $ch = curl_init($url);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false];
        if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $body;
        if ($userpwd !== null) $opt[CURLOPT_USERPWD] = $userpwd;
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $code = is_string($raw) ? (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
        curl_close($ch);
        return [$code, is_string($raw) ? (json_decode($raw, true) ?: []) : []];
    }

    function ms_pp_token(array $c): string {
        [$code, $j] = ms_pp_http('POST', $c['host'] . '/v1/oauth2/token', ['Accept: application/json'],
            'grant_type=client_credentials', $c['id'] . ':' . $c['secret']);
        return ($code === 200 && !empty($j['access_token'])) ? (string)$j['access_token'] : '';
    }

    function ms_money(float $v): string { return number_format(round($v, 2), 2, '.', ''); }
    function ms_cents($v): int { return (int)round((float)$v * 100); }

    /**
     * Create the PayPal order for a saved (pending) order. Returns ['ok'=>true,'id'=>…] or ['ok'=>false,'error'=>…].
     * No item lines: PayPal only needs the total and its breakdown, and that avoids rounding refusals.
     */
    function ms_pp_create(array $c, array $order, string $currency = 'USD'): array {
        $tok = ms_pp_token($c);
        if ($tok === '') return ['ok' => false, 'error' => 'PayPal is not answering right now. Please try again in a minute.'];
        $cur = strtoupper($currency);
        $sub = ms_money((float)$order['subtotal']); $tax = ms_money((float)$order['tax']); $ship = ms_money((float)$order['shipping']);
        $total = ms_money((float)$sub + (float)$tax + (float)$ship);
        $body = ['intent' => 'CAPTURE', 'purchase_units' => [[
            'reference_id' => 'default', 'invoice_id' => (string)$order['order_id'], 'custom_id' => (string)$order['order_id'],
            'amount' => ['currency_code' => $cur, 'value' => $total, 'breakdown' => [
                'item_total' => ['currency_code' => $cur, 'value' => $sub],
                'tax_total'  => ['currency_code' => $cur, 'value' => $tax],
                'shipping'   => ['currency_code' => $cur, 'value' => $ship]]],
        ]]];
        [$code, $j] = ms_pp_http('POST', $c['host'] . '/v2/checkout/orders',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $tok, 'PayPal-Request-Id: ms-' . $order['order_id']],
            json_encode($body, JSON_UNESCAPED_SLASHES));
        if (($code !== 200 && $code !== 201) || empty($j['id'])) return ['ok' => false, 'error' => 'PayPal could not start the payment.'];
        return ['ok' => true, 'id' => (string)$j['id'], 'total' => $total];
    }

    /**
     * Capture the PayPal order on the server and check PayPal's answer against our order.
     * Returns ['state' => 'verified'|'refused'|'unknown', 'reason' => …, 'capture_id', 'amount', 'currency', 'payer'].
     *   verified  PayPal holds the money for exactly this order
     *   refused   PayPal says no, or the payment is for something else (final)
     *   unknown   no clear answer (keys missing, PayPal down): nothing is marked paid; the buyer may try again
     */
    function ms_pp_capture_verify(array $c, string $ppId, array $order, string $currency = 'USD'): array {
        if (!preg_match('/^[A-Z0-9]{8,40}$/', $ppId)) return ['state' => 'refused', 'reason' => 'bad PayPal order id'];
        $tok = ms_pp_token($c);
        if ($tok === '') return ['state' => 'unknown', 'reason' => 'no PayPal token'];
        $h = ['Content-Type: application/json', 'Authorization: Bearer ' . $tok];
        [$code, $j] = ms_pp_http('POST', $c['host'] . '/v2/checkout/orders/' . $ppId . '/capture',
            array_merge($h, ['PayPal-Request-Id: ms-cap-' . $ppId, 'Prefer: return=representation']), '{}');
        $captured = $code >= 200 && $code < 300 && ($j['status'] ?? '') === 'COMPLETED';
        $already = $code === 422 && str_contains(json_encode($j), 'ORDER_ALREADY_CAPTURED');
        $thin = $captured && empty($j['purchase_units'][0]['payments']['captures'][0]['invoice_id']) && empty($j['purchase_units'][0]['invoice_id']);
        if ($already || $thin) {
            [$code, $j] = ms_pp_http('GET', $c['host'] . '/v2/checkout/orders/' . $ppId, $h);   // read the full order from PayPal
        }
        if ($code === 0 || $code >= 500) return ['state' => 'unknown', 'reason' => 'PayPal HTTP ' . $code];
        if ($code < 200 || $code >= 300) return ['state' => 'refused', 'reason' => 'PayPal HTTP ' . $code . ' ' . (string)($j['details'][0]['issue'] ?? $j['name'] ?? '')];
        if (($j['status'] ?? '') !== 'COMPLETED') return ['state' => 'refused', 'reason' => 'PayPal order status ' . (string)($j['status'] ?? '?')];
        $pu = (array)($j['purchase_units'][0] ?? []);
        $cap = (array)($pu['payments']['captures'][0] ?? []);
        $inv = (string)($cap['invoice_id'] ?? $pu['invoice_id'] ?? '');
        if ($inv !== (string)$order['order_id']) return ['state' => 'refused', 'reason' => 'payment is for another order (' . $inv . ')'];
        if (($cap['status'] ?? '') !== 'COMPLETED') return ['state' => 'refused', 'reason' => 'capture status ' . (string)($cap['status'] ?? '?')];
        $paid = (string)($cap['amount']['value'] ?? ''); $cur = strtoupper((string)($cap['amount']['currency_code'] ?? ''));
        $want = (string)($order['paypal_total'] ?? ms_money((float)$order['total']));   // the exact total PayPal was asked for
        if ($cur !== strtoupper($currency) || ms_cents($paid) !== ms_cents($want)) {
            return ['state' => 'refused', 'reason' => "paid $paid $cur, expected $want " . strtoupper($currency)];
        }
        return ['state' => 'verified', 'reason' => 'ok', 'capture_id' => (string)($cap['id'] ?? ''), 'amount' => $paid, 'currency' => $cur,
                'payer' => trim((string)($j['payer']['email_address'] ?? ''))];
    }

    /**
     * Change one order in orders.json under a lock. $fn(array &$order, array $all): mixed; its return is passed back.
     * Returns ['found' => bool, 'result' => …, 'order' => the order after $fn].
     */
    function ms_orders_update(string $ordersFile, string $orderId, callable $fn): array {
        $fp = @fopen($ordersFile, 'c+');
        if (!$fp) return ['found' => false, 'result' => null, 'order' => null];
        flock($fp, LOCK_EX);
        try {
            $all = json_decode((string)stream_get_contents($fp), true);
            $all = is_array($all) ? $all : [];
            foreach ($all as $i => $o) {
                if (($o['order_id'] ?? '') !== $orderId) continue;
                $res = $fn($all[$i], $all);
                ftruncate($fp, 0); rewind($fp);
                fwrite($fp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                fflush($fp);
                return ['found' => true, 'result' => $res, 'order' => $all[$i]];
            }
            return ['found' => false, 'result' => null, 'order' => null];
        } finally {
            flock($fp, LOCK_UN); fclose($fp);
        }
    }

    /** Append an order to orders.json under the same lock. */
    function ms_orders_append(string $ordersFile, array $order): bool {
        $fp = @fopen($ordersFile, 'c+');
        if (!$fp) return false;
        flock($fp, LOCK_EX);
        try {
            $all = json_decode((string)stream_get_contents($fp), true);
            $all = is_array($all) ? $all : [];
            $all[] = $order;
            ftruncate($fp, 0); rewind($fp);
            $ok = fwrite($fp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
            fflush($fp);
            return $ok;
        } finally {
            flock($fp, LOCK_UN); fclose($fp);
        }
    }
}
