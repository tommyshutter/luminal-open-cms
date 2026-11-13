<?php
/**
 * Provider Registry
 *
 * Manages AI provider configurations. Loads/saves from data/config.json.
 * Instantiates the active provider on demand.
 */
declare(strict_types=1);

require_once __DIR__ . '/ClaudeProvider.php';
require_once __DIR__ . '/OpenAIProvider.php';
require_once __DIR__ . '/GoogleProvider.php';
require_once __DIR__ . '/ZaiProvider.php';
require_once __DIR__ . '/CustomProvider.php';

class ProviderRegistry
{
    private string $configFile;
    private ?array $config = null;

    public function __construct(string $configFile)
    {
        $this->configFile = $configFile;
    }

    public function getConfig(): array
    {
        if ($this->config === null) {
            $this->config = $this->revealSecrets($this->loadConfig());
        }
        return $this->config;
    }

    public function saveConfig(array $config): bool
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // SECURITY 2026-07-26: seal on write. Previously every save path
        // (add_provider / update_provider / save_image_provider / save_config) wrote
        // apiKey in PLAINTEXT, so a one-off migration could be silently undone by the
        // next UI edit — and admin/data/ is rclone-synced to GDrive unfiltered nightly.
        // Sealing HERE (the single write choke point) is what makes it durable.
        $ok = file_put_contents(
            $this->configFile,
            json_encode($this->sealSecrets($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        if ($ok !== false) {
            @chmod($this->configFile, 0664);
            // Cache the REVEALED shape — callers expect usable keys back from getConfig().
            $this->config = $config;
        }
        return $ok !== false;
    }

    /** Load cred_vault on demand. Returns false if sealing/revealing is impossible. */
    private function vaultReady(): bool
    {
        if (!function_exists('cred_vault_seal')
            && defined('SITE_ROOT')
            && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
            require_once SITE_ROOT . '/admin/config/cred_vault.php';
        }
        // Check the primitive, not cred_vault_available(): available() requires the key
        // file to already exist, but seal() legitimately GENERATES it on first use — and
        // no spoke has one yet.
        return function_exists('cred_vault_seal') && function_exists('sodium_crypto_secretbox');
    }

    /**
     * Seal every apiKey in the tree. Generic by key NAME rather than by an enumerated
     * list of groups, so a provider group added later is covered automatically.
     * Idempotent (cred_vault_seal passes sealed values through) and mask-aware.
     */
    private function sealSecrets(array $config): array
    {
        if (!$this->vaultReady()) return $config;   // degrade to previous behaviour

        array_walk_recursive($config, function (&$v, $k) {
            if ($k !== 'apiKey' && $k !== 'api_key') return;
            if (!is_string($v) || $v === '') return;
            // Never seal a UI mask placeholder — that would persist "••••1234" as a secret.
            if (strpos($v, '••••') !== false || strpos($v, '****') !== false) return;
            try { $v = cred_vault_seal($v); } catch (\Throwable $e) { /* leave as-is */ }
        });
        return $config;
    }

    /**
     * Reveal every sealed value at load. Plaintext passes through untouched, so this is
     * safe on un-migrated sites and makes migration a no-op flip.
     *
     * On an undecryptable envelope we blank the field rather than propagate ciphertext:
     * a provider reporting "no API key configured" is an actionable error, whereas
     * shipping ciphertext as a bearer token produces a baffling 401 from the vendor.
     */
    private function revealSecrets(array $config): array
    {
        if (!function_exists('cred_vault_reveal')
            && defined('SITE_ROOT')
            && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
            require_once SITE_ROOT . '/admin/config/cred_vault.php';
        }
        if (!function_exists('cred_vault_reveal')) return $config;

        array_walk_recursive($config, function (&$v) {
            if (!function_exists('cred_vault_is_sealed') || !cred_vault_is_sealed($v)) return;
            try {
                $v = cred_vault_reveal($v);
            } catch (\Throwable $e) {
                error_log('AIResources: cred_vault reveal failed — blanking field: ' . $e->getMessage());
                $v = '';
            }
        });
        return $config;
    }

    /**
     * Get the active provider instance.
     */
    public function getActiveProvider(): AIResources\Providers\AIProvider
    {
        $config = $this->getConfig();
        $activeId = $config['activeProvider'] ?? 'claude';
        $providerConf = $config['providers'][$activeId] ?? [];

        return $this->createProvider($activeId, $providerConf);
    }

    /**
     * Get a specific provider by ID.
     */
    public function getProvider(string $id): AIResources\Providers\AIProvider
    {
        $config = $this->getConfig();
        $providerConf = $config['providers'][$id] ?? [];
        return $this->createProvider($id, $providerConf);
    }

    /**
     * List configured providers with masked keys.
     */
    public function listProviders(): array
    {
        $config = $this->getConfig();
        return $config['providers'] ?? [];
    }

    /**
     * Update a provider's configuration.
     * Mask-aware: skips apiKey fields containing mask characters (••••, ****)
     * to prevent UI round-trips from overwriting real keys.
     */
    public function updateProvider(string $id, array $updates): bool
    {
        $config = $this->getConfig();

        if (!isset($config['providers'][$id])) {
            $config['providers'][$id] = [];
        }

        // Strip masked API key values to prevent overwriting real keys
        if (isset($updates['apiKey'])) {
            $key = $updates['apiKey'];
            if (str_contains($key, '••••') || str_contains($key, '****') || preg_match('/^\*+$/', $key)) {
                unset($updates['apiKey']);
            }
        }

        $config['providers'][$id] = array_merge($config['providers'][$id], $updates);
        return $this->saveConfig($config);
    }

    /**
     * Set the active provider.
     */
    public function setActiveProvider(string $id): bool
    {
        $config = $this->getConfig();
        $config['activeProvider'] = $id;
        return $this->saveConfig($config);
    }

    /**
     * Get a specific provider by key from config, or create from explicit config.
     * Supports per-task provider selection: pass a provider key from config.json,
     * or an inline config array with {type, apiKey, model, maxTokens}.
     */
    public function getProviderByKey(string $key): AIResources\Providers\AIProvider
    {
        $config = $this->getConfig();
        $providerConf = $config['providers'][$key] ?? null;
        if ($providerConf === null) {
            throw new \RuntimeException("Provider '{$key}' not found in config");
        }
        return $this->createProvider($key, $providerConf);
    }

    /**
     * Get the provider for a specific pipeline.
     * Routing: task override → pipeline default → global default.
     */
    public function getProviderForPipeline(string $pipeline): AIResources\Providers\AIProvider
    {
        $config   = $this->getConfig();
        $defaults = $config['pipelineDefaults'] ?? [];

        // Per-pipeline model override (e.g. haiku for health_check, opus for ticket_analysis)
        $modelOverride = $config['pipelineModelOverrides'][$pipeline] ?? null;

        // Per-pipeline feature options (e.g. thinking: true for site_audit)
        $pipelineOptions = $config['pipelineOptions'][$pipeline] ?? [];

        if (!empty($defaults[$pipeline])) {
            $key = $defaults[$pipeline];
            if (isset($config['providers'][$key])) {
                return $this->createProvider($key, $config['providers'][$key], $modelOverride, $pipelineOptions);
            }
        }

        $activeId   = $config['activeProvider'] ?? 'claude';
        $provConf   = $config['providers'][$activeId] ?? [];
        return $this->createProvider($activeId, $provConf, $modelOverride, $pipelineOptions);
    }

    /**
     * Get pipeline default routing map.
     */
    public function getPipelineDefaults(): array
    {
        $config = $this->getConfig();
        return $config['pipelineDefaults'] ?? [];
    }

    /**
     * Set pipeline default routing map.
     */
    public function setPipelineDefaults(array $defaults): bool
    {
        $config = $this->getConfig();
        $config['pipelineDefaults'] = $defaults;
        return $this->saveConfig($config);
    }

    /**
     * List all supported provider types with their default models.
     */
    public function getSupportedTypes(): array
    {
        return [
            'anthropic' => [
                'label'  => 'Anthropic Claude',
                'models' => (new ClaudeProvider('', ''))->getModels(),
            ],
            'openai' => [
                'label'  => 'OpenAI',
                'models' => (new OpenAIProvider('', ''))->getModels(),
            ],
            'google' => [
                'label'  => 'Google Gemini',
                'models' => (new GoogleProvider('', ''))->getModels(),
            ],
            'xai' => [
                'label'  => 'xAI (Grok)',
                'models' => [
                    ['id' => 'grok-3',      'name' => 'Grok 3',      'maxTokens' => 131072],
                    ['id' => 'grok-3-mini',  'name' => 'Grok 3 Mini',  'maxTokens' => 131072],
                    ['id' => 'grok-2',      'name' => 'Grok 2',      'maxTokens' => 131072],
                ],
            ],
            'zai' => [
                'label'  => 'Z.ai (Zhipu)',
                'models' => (new ZaiProvider('', ''))->getModels(),
            ],
            'custom' => [
                'label'  => 'Custom (OpenAI-compatible)',
                'models' => [],
            ],
        ];
    }

    private function createProvider(string $id, array $conf, ?string $modelOverride = null, array $pipelineOptions = []): AIResources\Providers\AIProvider
    {
        $type = $conf['type'] ?? 'anthropic';

        // Credential Vault: un-seal the provider API key before it reaches any provider
        // constructor. Plaintext passes through untouched (safe until the key is sealed).
        if (isset($conf['apiKey']) && $conf['apiKey'] !== '') {
            if (!function_exists('cred_vault_reveal') && defined('SITE_ROOT')
                && is_file(SITE_ROOT . '/admin/config/cred_vault.php')) {
                require_once SITE_ROOT . '/admin/config/cred_vault.php';
            }
            if (function_exists('cred_vault_reveal')) $conf['apiKey'] = cred_vault_reveal($conf['apiKey']);
        }

        switch ($type) {
            case 'anthropic':
                $apiKey   = $conf['apiKey'] ?? '';
                $model    = $modelOverride ?? ($conf['model'] ?? 'claude-sonnet-4-6');
                $maxTok   = (int)($conf['maxTokens'] ?? 10000);
                // Merge base options from provider config with per-pipeline overrides
                $options  = array_merge($conf['options'] ?? [], $pipelineOptions);
                return new ClaudeProvider($apiKey, $model, $maxTok, $options);

            case 'openai':
                $apiKey   = $conf['apiKey'] ?? '';
                $model    = $conf['model'] ?? 'gpt-4o';
                $maxTok   = (int)($conf['maxTokens'] ?? 10000);
                return new OpenAIProvider($apiKey, $model, $maxTok);

            case 'google':
                $apiKey   = $conf['apiKey'] ?? '';
                $model    = $conf['model'] ?? 'gemini-2.5-flash';
                $maxTok   = (int)($conf['maxTokens'] ?? 65536);
                return new GoogleProvider($apiKey, $model, $maxTok);

            case 'xai':
                $apiKey   = $conf['apiKey'] ?? '';
                $model    = $conf['model'] ?? 'grok-3-mini';
                $maxTok   = (int)($conf['maxTokens'] ?? 131072);
                return new CustomProvider($apiKey, $model, $maxTok, 'https://api.x.ai/v1');

            case 'zai':
                $apiKey   = $conf['apiKey'] ?? '';
                $model    = $conf['model'] ?? 'glm-4.7-flash';
                $maxTok   = (int)($conf['maxTokens'] ?? 16384);
                return new ZaiProvider($apiKey, $model, $maxTok);

            case 'custom':
                $apiKey   = $conf['apiKey'] ?? '';
                $model    = $conf['model'] ?? '';
                $maxTok   = (int)($conf['maxTokens'] ?? 10000);
                $baseUrl  = $conf['baseUrl'] ?? '';
                return new CustomProvider($apiKey, $model, $maxTok, $baseUrl);

            default:
                // Treat unknown types as custom/OpenAI-compatible
                $apiKey   = $conf['apiKey'] ?? '';
                $model    = $conf['model'] ?? '';
                $maxTok   = (int)($conf['maxTokens'] ?? 10000);
                $baseUrl  = $conf['baseUrl'] ?? '';
                return new CustomProvider($apiKey, $model, $maxTok, $baseUrl);
        }
    }

    private function loadConfig(): array
    {
        if (is_file($this->configFile)) {
            $data = json_decode((string)file_get_contents($this->configFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // Return defaults
        return [
            'providers' => [
                'claude' => [
                    'type'      => 'anthropic',
                    'apiKey'    => '',
                    'model'     => 'claude-sonnet-4-5-20250929',
                    'maxTokens' => 10000,
                    'enabled'   => true,
                ],
            ],
            'activeProvider' => 'claude',
            'imageProviders' => [
                'flux' => [
                    'enabled'  => false,
                    'apiKey'   => '',
                    'model'    => 'flux-schnell',
                    'provider' => 'replicate',
                ],
                'dalle' => [
                    'enabled' => false,
                    'apiKey'  => '',
                    'model'   => 'dall-e-3',
                    'size'    => '1024x1024',
                    'quality' => 'standard',
                ],
                'stability' => [
                    'enabled' => false,
                    'apiKey'  => '',
                    'model'   => 'sd3-large',
                ],
            ],
            'defaultImageProvider' => 'flux',
            'autoSaveToLibrary'    => true,
            'defaults' => [
                'tone'           => 'professional',
                'contentType'    => 'article',
                'outputFormat'   => 'full_page',
                'seoEnabled'     => true,
                'imagePlaceholders' => false,
                'publishPathPrefix' => 'pages/',
                'addToMenu'      => false,
                'wordCount'      => 800,
            ],
        ];
    }
}
