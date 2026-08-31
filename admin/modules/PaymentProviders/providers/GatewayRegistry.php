<?php
/**
 * GatewayRegistry — Config-file-backed payment gateway registry
 */
declare(strict_types=1);

class GatewayRegistry {
    private string $configFile;
    private array $config;

    private static array $GATEWAY_DEFS = [
        'paypal' => [
            'name' => 'PayPal',
            'icon' => 'PP',
            'icon_class' => 'paypal',
            'fields' => [
                ['key' => 'clientId', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
                ['key' => 'clientSecret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
            ],
            'sdk_url' => 'https://www.paypal.com/sdk/js',
            'docs_url' => 'https://developer.paypal.com/docs/checkout/',
        ],
        'stripe' => [
            'name' => 'Stripe',
            'icon' => 'S',
            'icon_class' => 'stripe',
            'fields' => [
                ['key' => 'publishableKey', 'label' => 'Publishable Key', 'type' => 'text', 'required' => true],
                ['key' => 'secretKey', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ],
            'sdk_url' => 'https://js.stripe.com/v3/',
            'docs_url' => 'https://stripe.com/docs/payments',
        ],
        'square' => [
            'name' => 'Square',
            'icon' => 'Sq',
            'icon_class' => 'square',
            'fields' => [
                ['key' => 'applicationId', 'label' => 'Application ID', 'type' => 'text', 'required' => true],
                ['key' => 'accessToken', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
                ['key' => 'locationId', 'label' => 'Location ID', 'type' => 'text', 'required' => true],
            ],
            'sdk_url' => 'https://sandbox.web.squarecdn.com/v1/square.js',
            'docs_url' => 'https://developer.squareup.com/docs/web-payments/overview',
        ],
    ];

    public function __construct(string $dataDir) {
        $this->configFile = $dataDir . '/PaymentProviders/config.json';
        $this->config = $this->loadConfig();
    }

    private function loadConfig(): array {
        if (is_file($this->configFile)) {
            $data = json_decode(file_get_contents($this->configFile), true);
            if (is_array($data)) {
                // Credential Vault: un-seal any encrypted secrets (secretKey/clientSecret/
                // accessToken) at load. Plaintext passes through untouched.
                if (!function_exists('cred_vault_reveal_deep') && defined('SITE_ROOT')
                    && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
                    require_once SITE_ROOT . '/admin/config/cred_vault.php';
                }
                return function_exists('cred_vault_reveal_deep') ? cred_vault_reveal_deep($data) : $data;
            }
        }
        return $this->getDefaultConfig();
    }

    private function getDefaultConfig(): array {
        $gateways = [];
        foreach (self::$GATEWAY_DEFS as $id => $def) {
            $gateways[$id] = [
                'type' => $id,
                'enabled' => false,
                'sandbox' => true,
                'status' => 'untested',
            ];
            foreach ($def['fields'] as $f) {
                $gateways[$id][$f['key']] = '';
            }
        }
        return [
            'gateways' => $gateways,
            'defaultGateway' => null,
            'currency' => 'USD',
        ];
    }

    public function save(): bool {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return (bool)file_put_contents(
            $this->configFile,
            json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function listGateways(): array {
        $result = [];
        foreach (self::$GATEWAY_DEFS as $id => $def) {
            $cfg = $this->config['gateways'][$id] ?? [];
            $result[] = [
                'id' => $id,
                'name' => $def['name'],
                'icon' => $def['icon'],
                'icon_class' => $def['icon_class'],
                'enabled' => $cfg['enabled'] ?? false,
                'sandbox' => $cfg['sandbox'] ?? true,
                'status' => $cfg['status'] ?? 'untested',
                'isDefault' => ($this->config['defaultGateway'] === $id),
                'configured' => $this->isConfigured($id),
                'fields' => $def['fields'],
                'docs_url' => $def['docs_url'],
            ];
        }
        return $result;
    }

    public function getGateway(string $id): ?array {
        if (!isset(self::$GATEWAY_DEFS[$id])) return null;
        $def = self::$GATEWAY_DEFS[$id];
        $cfg = $this->config['gateways'][$id] ?? [];
        return array_merge($cfg, [
            'id' => $id,
            'name' => $def['name'],
            'fields' => $def['fields'],
            'docs_url' => $def['docs_url'],
        ]);
    }

    public function updateGateway(string $id, array $data): bool {
        if (!isset(self::$GATEWAY_DEFS[$id])) return false;
        $existing = $this->config['gateways'][$id] ?? ['type' => $id];
        foreach (self::$GATEWAY_DEFS[$id]['fields'] as $f) {
            $key = $f['key'];
            if (isset($data[$key]) && $data[$key] !== '') {
                $existing[$key] = $data[$key];
            }
        }
        if (isset($data['sandbox'])) $existing['sandbox'] = (bool)$data['sandbox'];
        if (isset($data['enabled'])) $existing['enabled'] = (bool)$data['enabled'];
        $this->config['gateways'][$id] = $existing;
        return $this->save();
    }

    public function enableGateway(string $id, bool $enable): bool {
        if (!isset(self::$GATEWAY_DEFS[$id])) return false;
        $this->config['gateways'][$id]['enabled'] = $enable;
        if (!$enable && $this->config['defaultGateway'] === $id) {
            $this->config['defaultGateway'] = null;
        }
        return $this->save();
    }

    public function setDefault(string $id): bool {
        if (!isset(self::$GATEWAY_DEFS[$id])) return false;
        $this->config['defaultGateway'] = $id;
        // Auto-enable
        $this->config['gateways'][$id]['enabled'] = true;
        return $this->save();
    }

    public function setCurrency(string $currency): bool {
        $this->config['currency'] = strtoupper($currency);
        return $this->save();
    }

    public function getCurrency(): string {
        return $this->config['currency'] ?? 'USD';
    }

    public function getDefaultGateway(): ?string {
        return $this->config['defaultGateway'] ?? null;
    }

    public function isConfigured(string $id): bool {
        if (!isset(self::$GATEWAY_DEFS[$id])) return false;
        $cfg = $this->config['gateways'][$id] ?? [];
        foreach (self::$GATEWAY_DEFS[$id]['fields'] as $f) {
            if ($f['required'] && empty($cfg[$f['key']])) return false;
        }
        return true;
    }

    public function testConnection(string $id): array {
        if (!$this->isConfigured($id)) {
            return ['ok' => false, 'message' => 'Gateway not fully configured'];
        }

        $cfg     = $this->config['gateways'][$id] ?? [];
        $sandbox = !empty($cfg['sandbox']);
        $result  = ['ok' => false, 'message' => 'Unknown gateway'];

        switch ($id) {
            case 'paypal':
                $result = $this->testPayPal($cfg, $sandbox);
                break;
            case 'stripe':
                $result = $this->testStripe($cfg);
                break;
            case 'square':
                $result = $this->testSquare($cfg, $sandbox);
                break;
        }

        $this->config['gateways'][$id]['status']    = $result['ok'] ? 'ok' : 'error';
        $this->config['gateways'][$id]['last_test']  = date('c');
        $this->save();
        return $result;
    }

    private function testPayPal(array $cfg, bool $sandbox): array {
        $host = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $ch = curl_init($host . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_USERPWD        => ($cfg['clientId'] ?? '') . ':' . ($cfg['clientSecret'] ?? ''),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($err) return ['ok' => false, 'message' => 'Connection error: ' . $err];
        if ($code === 200) {
            $data = json_decode($resp, true);
            return ['ok' => true, 'message' => 'PayPal connected — token type: ' . ($data['token_type'] ?? 'Bearer')];
        }
        $data = json_decode($resp, true);
        return ['ok' => false, 'message' => 'PayPal auth failed: ' . ($data['error_description'] ?? "HTTP $code")];
    }

    private function testStripe(array $cfg): array {
        $ch = curl_init('https://api.stripe.com/v1/balance');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . ($cfg['secretKey'] ?? '')],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($err) return ['ok' => false, 'message' => 'Connection error: ' . $err];
        if ($code === 200) return ['ok' => true, 'message' => 'Stripe connected successfully'];
        $data = json_decode($resp, true);
        return ['ok' => false, 'message' => 'Stripe auth failed: ' . ($data['error']['message'] ?? "HTTP $code")];
    }

    private function testSquare(array $cfg, bool $sandbox): array {
        $host = $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
        $ch = curl_init($host . '/v2/locations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . ($cfg['accessToken'] ?? ''),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($err) return ['ok' => false, 'message' => 'Connection error: ' . $err];
        if ($code === 200) {
            $data = json_decode($resp, true);
            $count = count($data['locations'] ?? []);
            return ['ok' => true, 'message' => "Square connected — $count location(s) found"];
        }
        $data = json_decode($resp, true);
        $detail = $data['errors'][0]['detail'] ?? "HTTP $code";
        return ['ok' => false, 'message' => 'Square auth failed: ' . $detail];
    }

    public function getClientConfig(string $id): ?array {
        if (!isset(self::$GATEWAY_DEFS[$id])) return null;
        $cfg = $this->config['gateways'][$id] ?? [];
        $def = self::$GATEWAY_DEFS[$id];
        // Return only public keys (not secrets)
        $publicFields = [];
        foreach ($def['fields'] as $f) {
            if ($f['type'] !== 'password') {
                $publicFields[$f['key']] = $cfg[$f['key']] ?? '';
            }
        }
        return [
            'id' => $id,
            'name' => $def['name'],
            'sandbox' => $cfg['sandbox'] ?? true,
            'sdk_url' => $def['sdk_url'],
            'config' => $publicFields,
        ];
    }

    public function getEnabledGateways(): array {
        $enabled = [];
        foreach ($this->config['gateways'] as $id => $cfg) {
            if ($cfg['enabled'] && $this->isConfigured($id)) {
                $enabled[] = $id;
            }
        }
        return $enabled;
    }
}
