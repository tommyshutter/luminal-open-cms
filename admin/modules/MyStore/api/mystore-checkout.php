<?php
/**
 * My Store — Checkout API Functions
 * Handles cart, shipping, tax, and payment processing
 *
 * File: /admin/modules/MyStore/api/mystore-checkout.php
 */

if (!defined('MYSTORE_SYSTEM') && !defined('PAYPAL_SYSTEM')) {
    die('Direct access denied');
}

require_once __DIR__ . '/../includes/mystore-shipping.php';

/**
 * Check if an email address looks like spam/gibberish
 * Returns array of reasons if suspicious, empty array if clean
 */
function mystore_check_spam_email($email) {
    $reasons = [];
    $parts = explode('@', $email);
    if (count($parts) !== 2) return ['invalid_format'];
    $local = strtolower($parts[0]);
    $domain = strtolower($parts[1]);

    // Suspicious TLDs
    $spam_tlds = ['xyz', 'top', 'click', 'buzz', 'gdn', 'men', 'work',
                  'date', 'racing', 'win', 'bid', 'stream', 'download',
                  'accountant', 'cricket', 'faith', 'loan', 'party',
                  'review', 'science', 'trade', 'webcam'];
    $tld = substr($domain, strrpos($domain, '.') + 1);
    if (in_array($tld, $spam_tlds)) {
        $reasons[] = 'suspicious_tld';
    }

    // High consonant density
    $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxz]/', $local);
    $vowels = preg_match_all('/[aeiou]/', $local);
    $alpha_len = $consonants + $vowels;
    if ($alpha_len > 4 && $vowels > 0 && ($consonants / $vowels) > 5) {
        $reasons[] = 'consonant_heavy';
    }
    if ($alpha_len > 4 && $vowels === 0) {
        $reasons[] = 'no_vowels';
    }

    // Excessive digits
    $digit_count = preg_match_all('/[0-9]/', $local);
    if (strlen($local) > 4 && $digit_count / strlen($local) > 0.6) {
        $reasons[] = 'mostly_digits';
    }

    // Long random local part (entropy check)
    if (strlen($local) > 20) {
        $freq = array_count_values(str_split($local));
        $len = strlen($local);
        $entropy = 0;
        foreach ($freq as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }
        if ($entropy > 3.5) {
            $reasons[] = 'high_entropy';
        }
    }

    // Disposable email domains
    $disposable = ['mailinator.com', 'guerrillamail.com', 'tempmail.com',
                   'throwaway.email', 'fakeinbox.com', 'sharklasers.com',
                   'yopmail.com', 'trashmail.com', 'dispostable.com',
                   'guerrillamailblock.com', 'grr.la'];
    if (in_array($domain, $disposable)) {
        $reasons[] = 'disposable_domain';
    }

    // Consecutive repeated chars
    if (preg_match('/(.)\1{4,}/', $local)) {
        $reasons[] = 'repeated_chars';
    }

    return $reasons;
}

/**
 * Email verification for checkout — sends a 6-digit code via Mailgun or mail()
 */
function mystore_send_verification($email) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['mystore_email_verify'] = [
        'email' => $email,
        'code' => $code,
        'expires' => time() + 600, // 10 min
        'attempts' => 0,
    ];

    $siteName = 'Our Store';
    $settingsFile = (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/data/site-settings.json';
    if (is_file($settingsFile)) {
        $ss = json_decode(file_get_contents($settingsFile), true);
        if (!empty($ss['site_name'])) $siteName = $ss['site_name'];
    }

    $subject = "Your verification code: $code";
    $html = "<div style='font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px'>"
          . "<h2 style='margin:0 0 16px'>Verify your email</h2>"
          . "<p>Due to increased fraud activity, we need to verify your email before processing your order.</p>"
          . "<div style='background:#f5f5f5;border-radius:8px;padding:20px;text-align:center;margin:20px 0'>"
          . "<span style='font-size:2rem;font-weight:bold;letter-spacing:0.3em;color:#333'>$code</span>"
          . "</div>"
          . "<p style='color:#666;font-size:0.85rem'>This code expires in 10 minutes. If you didn't initiate a purchase, please ignore this email.</p>"
          . "<p style='color:#999;font-size:0.75rem;margin-top:24px'>— $siteName</p>"
          . "</div>";
    $text = "Your verification code is: $code\n\nThis code expires in 10 minutes.\n\nIf you didn't initiate a purchase, please ignore this email.\n\n— $siteName";

    // Transport selection (SMTP / Mailgun / host MTA) belongs to mail.php. This function
    // used to carry its own Mailgun client and its own mail() fallback; that duplication is
    // how the codebase ended up with several different answers to "how does this site send
    // email". The curl handle here was also never closed on the success path.
    require_once (defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 3)) . '/admin/includes/mail.php';
    $res = luminal_send_mail($email, $subject, $html, $text);
    return !empty($res['ok']);
}

function mystore_verify_code($email, $code) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $v = $_SESSION['mystore_email_verify'] ?? null;
    if (!$v) return ['ok' => false, 'error' => 'No verification pending. Please request a new code.'];
    if ($v['email'] !== $email) return ['ok' => false, 'error' => 'Email mismatch. Please request a new code.'];
    if (time() > $v['expires']) { unset($_SESSION['mystore_email_verify']); return ['ok' => false, 'error' => 'Code expired. Please request a new code.']; }
    if ($v['attempts'] >= 5) { unset($_SESSION['mystore_email_verify']); return ['ok' => false, 'error' => 'Too many attempts. Please request a new code.']; }

    $_SESSION['mystore_email_verify']['attempts']++;

    if ($v['code'] !== $code) return ['ok' => false, 'error' => 'Incorrect code. Please try again.'];

    // Mark email as verified
    $_SESSION['mystore_email_verified'] = $email;
    unset($_SESSION['mystore_email_verify']);
    return ['ok' => true];
}

function mystore_is_email_verified($email) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return (($_SESSION['mystore_email_verified'] ?? '') === $email);
}

/**
 * Get cart items from session
 */
function paypal_get_cart_items() {
    if (!isset($_SESSION['cart'])) {
        return [];
    }
    
    $cart_items = [];
    $products = paypal_get_products();
    
    foreach ($_SESSION['cart'] as $sku => $cart_item) {
        $product = array_find($products, function($p) use ($sku) {
            return $p['sku'] === $sku;
        });
        
        if ($product && $product['enabled']) {
            $cart_items[] = [
                'sku' => $sku,
                'name' => $product['name'],
                'price' => floatval($product['price']),
                'quantity' => intval($cart_item['quantity']),
                'variations' => $cart_item['variations'] ?? [],
                'image' => $product['image'] ?? '',
                'total' => floatval($product['price']) * intval($cart_item['quantity'])
            ];
        }
    }
    
    return $cart_items;
}

/**
 * Add item to cart
 */
function paypal_add_to_cart($sku, $quantity = 1, $variations = []) {
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    $cart_key = $sku . '_' . md5(serialize($variations));
    
    if (isset($_SESSION['cart'][$cart_key])) {
        $_SESSION['cart'][$cart_key]['quantity'] += $quantity;
    } else {
        $_SESSION['cart'][$cart_key] = [
            'sku' => $sku,
            'quantity' => $quantity,
            'variations' => $variations,
            'added' => time()
        ];
    }
    
    return true;
}

/**
 * Update cart item quantity
 */
function paypal_update_cart_item($cart_key, $quantity) {
    if (!isset($_SESSION['cart'][$cart_key])) {
        return false;
    }
    
    if ($quantity <= 0) {
        unset($_SESSION['cart'][$cart_key]);
    } else {
        $_SESSION['cart'][$cart_key]['quantity'] = intval($quantity);
    }
    
    return true;
}

/**
 * Remove item from cart
 */
function paypal_remove_from_cart($cart_key) {
    if (isset($_SESSION['cart'][$cart_key])) {
        unset($_SESSION['cart'][$cart_key]);
        return true;
    }
    return false;
}

/**
 * Clear entire cart
 */
function paypal_clear_cart() {
    $_SESSION['cart'] = [];
    return true;
}

/**
 * Calculate cart total
 */
function paypal_calculate_cart_total($cart_items) {
    $total = 0;
    foreach ($cart_items as $item) {
        $total += $item['total'];
    }
    return $total;
}

/**
 * Get cart item count
 */
function paypal_get_cart_count() {
    if (!isset($_SESSION['cart'])) {
        return 0;
    }
    
    $count = 0;
    foreach ($_SESSION['cart'] as $item) {
        $count += intval($item['quantity']);
    }
    
    return $count;
}

/**
 * Get shipping costs. Delegates to the canonical settings-driven calculator
 * (mystore_calc_shipping). $method is retained for signature compatibility but
 * shipping is now rule-based, not method-based.
 */
function paypal_get_shipping_costs($method, $subtotal, $items = []) {
    $settings = function_exists('paypal_get_all_settings') ? paypal_get_all_settings() : [];
    if (!is_array($settings)) $settings = [];
    $qty = mystore_cart_quantity($items);
    if ($qty <= 0 && floatval($subtotal) > 0) $qty = 1; // legacy callers without item list
    return mystore_calc_shipping($qty, floatval($subtotal), $settings);
}

/**
 * Calculate tax based on state
 */
function paypal_calculate_tax($subtotal, $state) {
    // Basic tax calculation - you should implement proper tax rules
    $tax_rates = [
        'CA' => 0.0875, // California
        'NY' => 0.08,   // New York
        'TX' => 0.0625, // Texas
        'FL' => 0.06,   // Florida
        'WA' => 0.065,  // Washington
        // Add more states as needed
    ];
    
    $rate = $tax_rates[$state] ?? 0;
    return $subtotal * $rate;
}

/**
 * Get US states for shipping
 */
function paypal_get_us_states() {
    return [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming'
    ];
}

/**
 * Process payment (mock implementation — payment providers handle real payments in Phase 3)
 */
function paypal_process_payment($checkout_data, $cart_items, $total) {
    // In a real implementation, this would:
    // 1. Create payment request via active payment provider
    // 2. Handle provider API response
    // 3. Save order to data store
    // 4. Send confirmation email
    
    // Mock successful payment
    $order_id = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(time()), 0, 8));
    
    // Build normalized customer block
    $custFirstName = $checkout_data['shipping']['first_name'] ?? '';
    $custLastName = $checkout_data['shipping']['last_name'] ?? '';
    $custName = trim($custFirstName . ' ' . $custLastName);

    $subtotalCalc = paypal_calculate_cart_total($cart_items);
    $shippingCalc = paypal_get_shipping_costs($checkout_data['shipping_method'] ?? 'standard', $subtotalCalc, $cart_items);
    $taxCalc = paypal_calculate_tax($subtotalCalc, $checkout_data['shipping']['state'] ?? '');

    // Save order data — normalized format with both legacy and new fields
    $order = [
        'order_id' => $order_id,
        'customer' => [
            'name' => $custName ?: ($checkout_data['shipping']['email'] ?? 'Unknown'),
            'first_name' => $custFirstName,
            'last_name' => $custLastName,
            'email' => $checkout_data['shipping']['email'] ?? '',
            'phone' => $checkout_data['shipping']['phone'] ?? '',
            'address' => [
                'street' => $checkout_data['shipping']['address1'] ?? '',
                'city' => $checkout_data['shipping']['city'] ?? '',
                'state' => $checkout_data['shipping']['state'] ?? '',
                'zip' => $checkout_data['shipping']['zip'] ?? '',
                'country' => $checkout_data['shipping']['country'] ?? 'US',
            ],
        ],
        'billing' => $checkout_data['billing'],
        'items' => $cart_items,
        'subtotal' => $subtotalCalc,
        'tax' => $taxCalc,
        'shipping' => $shippingCalc,
        'total' => $total,
        'totals' => [
            'subtotal' => $subtotalCalc,
            'shipping' => $shippingCalc,
            'tax' => $taxCalc,
            'total' => $total,
        ],
        'status' => 'processing',
        'payment_method' => 'store',
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'notes' => '',
    ];
    
    // Save order to file (in production, use database)
    $orders_file = PAYPAL_DATA_PATH . '/orders.json';
    $orders = [];
    
    if (file_exists($orders_file)) {
        $orders = json_decode(file_get_contents($orders_file), true) ?: [];
    }
    
    $orders[] = $order;
    file_put_contents($orders_file, json_encode($orders, JSON_PRETTY_PRINT));
    
    // Clear cart
    paypal_clear_cart();
    
    // Send confirmation email (mock)
    paypal_send_order_confirmation($order);
    
    return [
        'success' => true,
        'order_id' => $order_id,
        'message' => 'Payment processed successfully'
    ];
}

/**
 * Send order confirmation email (mock)
 */
function paypal_send_order_confirmation($order) {
    // In production, implement actual email sending
    $to = $order['customer']['email'];
    $subject = 'Order Confirmation - ' . $order['order_id'];
    
    $message = "
    <h2>Thank you for your order!</h2>
    <p>Order ID: {$order['order_id']}</p>
    <p>Total: \${$order['totals']['total']}</p>
    <p>We'll send you tracking information once your order ships.</p>
    ";
    
    // Mock email sending
    error_log("Email sent to {$to}: {$subject}");
    
    return true;
}

/**
 * Get customer orders
 */
function paypal_get_customer_orders($email) {
    $orders_file = PAYPAL_DATA_PATH . '/orders.json';
    if (!file_exists($orders_file)) {
        return [];
    }
    
    $orders = json_decode(file_get_contents($orders_file), true) ?: [];
    
    return array_filter($orders, function($order) use ($email) {
        return $order['customer']['email'] === $email;
    });
}

/**
 * Get all orders (admin)
 */
function paypal_get_all_orders() {
    $orders_file = PAYPAL_DATA_PATH . '/orders.json';
    if (!file_exists($orders_file)) {
        return [];
    }

    $orders = json_decode(file_get_contents($orders_file), true) ?: [];

    // Normalize all orders for consistent field access
    if (function_exists('mystore_normalize_order')) {
        $orders = array_map('mystore_normalize_order', $orders);
    }

    // Sort by creation date (newest first)
    usort($orders, function($a, $b) {
        return strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0');
    });

    return $orders;
}

/**
 * Update order status
 */
function paypal_update_order_status($order_id, $status, $notes = '') {
    $orders_file = PAYPAL_DATA_PATH . '/orders.json';
    if (!file_exists($orders_file)) {
        return false;
    }
    
    $orders = json_decode(file_get_contents($orders_file), true) ?: [];
    
    foreach ($orders as &$order) {
        if ($order['order_id'] === $order_id) {
            $order['status'] = $status;
            $order['updated_at'] = date('c');
            if ($notes) {
                $order['notes'] = $notes;
            }
            
            file_put_contents($orders_file, json_encode($orders, JSON_PRETTY_PRINT));
            return true;
        }
    }
    
    return false;
}

/**
 * Validate checkout data
 */
function paypal_validate_checkout_data($data) {
    $errors = [];
    
    // Validate shipping info
    if (empty($data['shipping']['first_name'])) {
        $errors[] = 'First name is required';
    }
    
    if (empty($data['shipping']['last_name'])) {
        $errors[] = 'Last name is required';
    }
    
    if (empty($data['shipping']['email']) || !filter_var($data['shipping']['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email address is required';
    }

    // Gibberish/spam email check
    if (!empty($data['shipping']['email'])) {
        $spam_flags = mystore_check_spam_email($data['shipping']['email']);
        if (count($spam_flags) >= 2) {
            $errors[] = 'This email address appears invalid. Please use a real email address.';
        }
    }

    // Email verification check — must have verified code before order can proceed
    if (!empty($data['shipping']['email']) && !mystore_is_email_verified($data['shipping']['email'])) {
        $errors[] = 'Please verify your email address before placing your order.';
    }

    if (empty($data['shipping']['address1'])) {
        $errors[] = 'Street address is required';
    }
    
    if (empty($data['shipping']['city'])) {
        $errors[] = 'City is required';
    }
    
    if (empty($data['shipping']['state'])) {
        $errors[] = 'State is required';
    }
    
    if (empty($data['shipping']['zip'])) {
        $errors[] = 'ZIP code is required';
    }
    
    return $errors;
}

/**
 * Helper function for array_find
 */
if (!function_exists('array_find')) {
    function array_find($array, $callback) {
        foreach ($array as $item) {
            if ($callback($item)) {
                return $item;
            }
        }
        return null;
    }
}
?>