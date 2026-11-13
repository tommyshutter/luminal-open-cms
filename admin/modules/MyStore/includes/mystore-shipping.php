<?php
/**
 * My Store — Shipping rules engine
 *
 * File: /admin/modules/MyStore/includes/mystore-shipping.php
 *
 * Single canonical shipping calculator shared by every total-computing path:
 *   - api/store-api.php        (live order creation / charge)
 *   - views/mystore-checkout.php (checkout page display)
 *   - api/mystore-checkout.php  (legacy/mock checkout)
 *   - api/mystore-cart.php      (cart preview)
 *
 * Rules live in settings.json under the `shipping` block:
 *   {
 *     "mode": "per_quantity" | "flat",
 *     "flatRate": 5.99,
 *     "tiers": [ {"qty":1,"rate":6.95}, {"qty":2,"rate":8.95}, {"qty":3,"rate":10.95} ],
 *     "aboveMax": "cap" | "increment",   // behavior when cart qty exceeds the top tier
 *     "perAdditional": 2.00,             // used when aboveMax = "increment"
 *     "freeShipping": { "enabled": false, "type": "threshold"|"always", "threshold": 75 }
 *   }
 *
 * Backward compatible: if no `shipping` block exists, falls back to the legacy
 * flat `shippingCost` + `freeShippingThreshold` keys.
 */

if (!defined('MYSTORE_SYSTEM') && !defined('PAYPAL_SYSTEM')) {
    // allow direct include from admin/view contexts without the API guard
}

if (!function_exists('mystore_cart_quantity')) {
    /**
     * Sum the total item quantity across a cart/order item array.
     * Accepts the common shapes: [{quantity:N}, ...] or assoc cart [{quantity:N}].
     */
    function mystore_cart_quantity($items): int {
        $q = 0;
        if (is_array($items)) {
            foreach ($items as $it) {
                if (is_array($it) && isset($it['quantity'])) {
                    $q += max(0, intval($it['quantity']));
                }
            }
        }
        return $q;
    }
}

if (!function_exists('mystore_shipping_config')) {
    /**
     * Normalized shipping config (always a complete structured array, with the
     * legacy flat keys folded in). Used to expose one consistent shape to the
     * storefront JavaScript so client-side and server-side agree exactly.
     */
    function mystore_shipping_config(array $settings): array {
        $ship = (isset($settings['shipping']) && is_array($settings['shipping'])) ? $settings['shipping'] : null;
        if ($ship === null) {
            $thr = floatval($settings['freeShippingThreshold'] ?? 0);
            return [
                'mode'          => 'flat',
                'flatRate'      => floatval($settings['shippingCost'] ?? 0),
                'tiers'         => [],
                'aboveMax'      => 'cap',
                'perAdditional' => 0.0,
                'freeShipping'  => ['enabled' => $thr > 0, 'type' => 'threshold', 'threshold' => $thr],
            ];
        }
        $tiers = [];
        foreach (($ship['tiers'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $q = intval($t['qty'] ?? 0);
            if ($q < 1) continue;
            $tiers[] = ['qty' => $q, 'rate' => round(floatval($t['rate'] ?? 0), 2)];
        }
        $free = (isset($ship['freeShipping']) && is_array($ship['freeShipping'])) ? $ship['freeShipping'] : [];
        return [
            'mode'          => $ship['mode'] ?? 'flat',
            'flatRate'      => floatval($ship['flatRate'] ?? ($settings['shippingCost'] ?? 0)),
            'tiers'         => $tiers,
            'aboveMax'      => $ship['aboveMax'] ?? 'cap',
            'perAdditional' => floatval($ship['perAdditional'] ?? 0),
            'freeShipping'  => [
                'enabled'   => !empty($free['enabled']),
                'type'      => $free['type'] ?? 'threshold',
                'threshold' => floatval($free['threshold'] ?? 0),
            ],
        ];
    }
}

if (!function_exists('mystore_free_shipping_threshold')) {
    /**
     * The active "free shipping over $X" subtotal threshold, or 0.0 if free
     * shipping is disabled / set to always-free / not threshold-based.
     * Used for the storefront "add $X more for free shipping" nudge.
     */
    function mystore_free_shipping_threshold(array $settings): float {
        $ship = isset($settings['shipping']) && is_array($settings['shipping'])
            ? $settings['shipping'] : null;
        if ($ship === null) {
            // legacy
            return floatval($settings['freeShippingThreshold'] ?? 0);
        }
        $free = (isset($ship['freeShipping']) && is_array($ship['freeShipping']))
            ? $ship['freeShipping'] : [];
        if (empty($free['enabled'])) return 0.0;
        if (($free['type'] ?? 'threshold') !== 'threshold') return 0.0;
        return floatval($free['threshold'] ?? 0);
    }
}

if (!function_exists('mystore_calc_shipping')) {
    /**
     * Canonical shipping cost for a cart.
     *
     * @param int   $quantity total number of items in the cart (sum of line qtys)
     * @param float $subtotal cart subtotal (pre-tax, pre-shipping)
     * @param array $settings decoded settings.json
     * @return float shipping cost (>= 0), rounded to 2dp
     */
    function mystore_calc_shipping(int $quantity, float $subtotal, array $settings): float {
        // Empty cart never ships
        if ($quantity <= 0 || $subtotal <= 0) {
            return 0.0;
        }

        $ship = isset($settings['shipping']) && is_array($settings['shipping'])
            ? $settings['shipping'] : null;

        // ── Legacy fallback: no structured block ────────────────────────────
        if ($ship === null) {
            $flat = floatval($settings['shippingCost'] ?? 0);
            $thr  = floatval($settings['freeShippingThreshold'] ?? 0);
            if ($thr > 0 && $subtotal >= $thr) return 0.0;
            return round($flat, 2);
        }

        // ── Free-shipping rule (selectable) ─────────────────────────────────
        $free = (isset($ship['freeShipping']) && is_array($ship['freeShipping']))
            ? $ship['freeShipping'] : [];
        if (!empty($free['enabled'])) {
            $type = $free['type'] ?? 'threshold';
            if ($type === 'always') {
                return 0.0;
            }
            $thr = floatval($free['threshold'] ?? 0);
            if ($thr > 0 && $subtotal >= $thr) {
                return 0.0;
            }
        }

        $mode = $ship['mode'] ?? 'flat';

        // ── Flat rate ───────────────────────────────────────────────────────
        if ($mode !== 'per_quantity') {
            return round(floatval($ship['flatRate'] ?? ($settings['shippingCost'] ?? 0)), 2);
        }

        // ── Per-quantity tiers ──────────────────────────────────────────────
        // Normalize: { qty => rate }, deduped (last wins), invalid rows dropped.
        $clean = [];
        foreach (($ship['tiers'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $q = intval($t['qty'] ?? 0);
            if ($q < 1) continue;
            $clean[$q] = round(floatval($t['rate'] ?? 0), 2);
        }

        // No usable tiers → fall back to flatRate then legacy flat cost
        if (!$clean) {
            return round(floatval($ship['flatRate'] ?? ($settings['shippingCost'] ?? 0)), 2);
        }

        ksort($clean);
        $qtys = array_keys($clean);
        $minQ = $qtys[0];
        $maxQ = $qtys[count($qtys) - 1];

        // Below the smallest tier → smallest tier's rate
        if ($quantity <= $minQ) {
            return $clean[$minQ];
        }

        // Within the tier range → highest tier whose qty <= cart quantity
        if ($quantity <= $maxQ) {
            $rate = $clean[$minQ];
            foreach ($clean as $q => $r) {
                if ($q <= $quantity) { $rate = $r; } else { break; }
            }
            return $rate;
        }

        // Above the top tier → aboveMax behavior
        $aboveMax = $ship['aboveMax'] ?? 'cap';
        if ($aboveMax === 'increment') {
            $per = round(floatval($ship['perAdditional'] ?? 0), 2);
            return round($clean[$maxQ] + $per * ($quantity - $maxQ), 2);
        }
        // "cap" (default) — every larger order ships at the top tier rate
        return $clean[$maxQ];
    }
}
