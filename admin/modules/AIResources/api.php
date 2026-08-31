<?php
/**
 * AIResources — API Endpoint
 *
 * Central AI management API. Replaces AIPageMaker's API for:
 * - Provider CRUD (add/update/remove/test/set-active)
 * - MCP server management (list/add/update/remove/test)
 * - Image provider config
 * - get_state (used by PageManager AI Assist dropdown)
 * - generate_fragment (used by PageManager AI Assist)
 * - save_config (provider + defaults)
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) define('SITE_ROOT', dirname(__DIR__, 3));

require_once __DIR__ . '/../_runtime/guard.php';
guard_require_auth();

require_once __DIR__ . '/providers/ProviderRegistry.php';
require_once __DIR__ . '/mcp/MCPRegistry.php';
require_once __DIR__ . '/AIFunctions.php';

/**
 * JSON response helper — sends JSON and exits.
 */
function api_json(array $data, int $code = 200): never {
    if ($code >= 400) http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Parse input ──
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if (!$action) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';
} else {
    $input = ($_SERVER['REQUEST_METHOD'] === 'POST')
        ? (json_decode(file_get_contents('php://input'), true) ?: $_POST)
        : $_GET;
}

// ── Data directory (with auto-migration from AIPageMaker) ──
$dataDir    = dirname(__DIR__, 2) . '/data/AIResources';
$oldDataDir = dirname(__DIR__, 2) . '/data/AIPageMaker';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
    // Auto-migrate from AIPageMaker data if it exists
    if (is_dir($oldDataDir)) {
        foreach (['config.json', 'mcp_servers.json'] as $f) {
            $src = $oldDataDir . '/' . $f;
            $dst = $dataDir . '/' . $f;
            if (is_file($src) && !is_file($dst)) {
                @copy($src, $dst);
                @chmod($dst, 0664);
            }
        }
    }
}

$configFile = $dataDir . '/config.json';
$mcpFile    = $dataDir . '/mcp_servers.json';

// Load registries
$providerReg = new ProviderRegistry($configFile);
$mcpReg      = new MCPRegistry($mcpFile);

// ═══════════════════════════════════════
// Actions
// ═══════════════════════════════════════

try {
switch ($action) {

    // ── Overview (dashboard summary) ──
    case 'overview':
        $config    = $providerReg->getConfig();
        $providers = $config['providers'] ?? [];
        $servers   = $mcpReg->loadServers();
        $active    = $config['activeProvider'] ?? null;
        $images    = $config['imageProviders'] ?? [];

        $enabledImages = 0;
        foreach ($images as $ip) {
            if (!empty($ip['enabled'])) $enabledImages++;
        }

        api_json([
            'ok' => true,
            'provider_count'  => count($providers),
            'active_provider'  => $active,
            'active_type'      => $providers[$active]['type'] ?? null,
            'active_model'     => $providers[$active]['model'] ?? null,
            'server_count'     => count($servers),
            'image_providers'  => $enabledImages,
        ]);
        break;

    // ═══════════════════════════════════
    // GET STATE (PageManager AI Assist)
    // ═══════════════════════════════════

    case 'get_state':
        $config    = $providerReg->getConfig();
        $providers = $providerReg->listProviders();
        $servers   = $mcpReg->loadServers();

        // Get vhost list (filesystem scan — fast, no MCP overhead)
        $vhosts = [];
        $vhostsBase = '/var/www/vhosts';
        if (is_dir($vhostsBase)) {
            foreach (scandir($vhostsBase) as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (strpos($entry, '.') === false) continue;
                if (!is_dir($vhostsBase . '/' . $entry)) continue;
                $vhosts[] = ['domain' => $entry, 'type' => 'unknown'];
            }
        }

        // Server status summary
        $serverStatus = [];
        foreach ($servers as $id => $s) {
            $serverStatus[$id] = [
                'id'          => $id,
                'name'        => $s['name'] ?? $id,
                'type'        => $s['type'] ?? 'unknown',
                'enabled'     => !empty($s['enabled']),
                'builtin'     => !empty($s['builtin']),
                'roles'       => $s['roles'] ?? [],
                'tags'        => $s['tags'] ?? [],
                'description' => $s['description'] ?? '',
            ];
        }

        // Strip sensitive data from config before sending to client
        $safeConfig = $config;
        // Strip provider API keys
        if (!empty($safeConfig['providers'])) {
            foreach ($safeConfig['providers'] as $id => &$p) {
                if (!empty($p['apiKey'])) {
                    $p['apiKey'] = '••••' . substr($p['apiKey'], -4);
                }
            }
            unset($p);
        }
        // Strip NLM private_key but keep client_email + project_id for display
        if (!empty($safeConfig['notebookLM']['serviceAccountKey'])) {
            $saKey = $safeConfig['notebookLM']['serviceAccountKey'];
            $safeConfig['notebookLM']['serviceAccountKey'] = [
                'client_email' => $saKey['client_email'] ?? null,
                'project_id'   => $saKey['project_id'] ?? null,
            ];
        }
        // Strip image provider keys
        if (!empty($safeConfig['imageProviders'])) {
            foreach ($safeConfig['imageProviders'] as $id => &$ip) {
                if (!empty($ip['apiKey'])) {
                    $ip['apiKey'] = '••••' . substr($ip['apiKey'], -4);
                }
            }
            unset($ip);
        }

        api_json([
            'ok'        => true,
            'data'      => [
                'config'    => $safeConfig,
                'providers' => $providers,
                'vhosts'    => $vhosts,
                'templates' => [],
                'history'   => [],
                'servers'   => $serverStatus,
            ],
        ]);
        break;

    // ═══════════════════════════════════
    // GENERATE FRAGMENT (PageManager AI Assist)
    // ═══════════════════════════════════

    case 'generate_fragment':
        $prompt        = trim($input['prompt'] ?? '');
        $tone          = trim($input['tone'] ?? 'professional');
        $contentType   = trim($input['contentType'] ?? 'article');
        $wordCount     = max(50, min(3000, (int)($input['wordCount'] ?? 300)));
        $pageTitle     = trim($input['pageTitle'] ?? '');
        $existingContent = trim($input['existingContent'] ?? '');
        $includeImages  = !empty($input['includeImages']);
        $imageCount     = max(1, min(8, (int)($input['imageCount'] ?? 3)));
        $imageLayout    = in_array($input['imageLayout'] ?? '', ['mixed', 'fixed', 'mixed-inset'], true)
                            ? $input['imageLayout'] : 'mixed';
        $imageRelevant  = $input['imageRelevant'] ?? true;

        if ($prompt === '') {
            api_json(['ok' => false, 'error' => 'Prompt is required'], 400);
        }

        // Minimal site context — no MCP overhead for fast fragment generation
        $domain = apm_sanitize_domain($input['domain'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $siteContext = [
            'domain' => $domain,
            'config' => ['name' => $domain],
            'theme'  => [],
            'pages'  => [],
            'menu'   => [],
        ];

        // Optionally gather richer site context via MCP
        if (!empty($input['useSiteContext'])) {
            try {
                $client = $mcpReg->getClient('site-context');
                $result = $client->callTool('get_site_context', ['domain' => $domain]);
                $text = $result['content'][0]['text'] ?? '{}';
                $siteContext = json_decode($text, true) ?: $siteContext;
                $mcpReg->disconnectAll();
            } catch (\Throwable $e) {
                // Continue with minimal context
            }
        }

        $systemPrompt = apm_build_system_prompt($siteContext, [
            'contentType'       => $contentType,
            'tone'              => $tone,
            'outputFormat'      => 'fragment',
            'wordCount'         => $wordCount,
            'imagePlaceholders' => $includeImages,
            'imageCount'        => $imageCount,
            'imageLayout'       => $imageLayout,
            'imageRelevant'     => $imageRelevant,
        ]);

        // Build user message
        $userMsg = '';
        if ($pageTitle !== '') {
            $userMsg .= "Page title: \"{$pageTitle}\"\n\n";
        }
        $userMsg .= "Generate this content:\n{$prompt}\n";
        if ($existingContent !== '') {
            $snippet = substr($existingContent, 0, 1500);
            $userMsg .= "\nExisting page content for reference:\n```html\n{$snippet}\n```\n";
        }

        // Single-turn AI call — no tools for speed
        $providerKey = trim($input['provider'] ?? '');
        $provider = $providerKey !== ''
            ? $providerReg->getProviderByKey($providerKey)
            : $providerReg->getActiveProvider();

        // Bump output tokens when generating with image placeholders (shortcodes add ~500 tokens)
        if ($includeImages) {
            $provider->setMaxTokens(12000);
        }

        $aiResult = $provider->generate($systemPrompt, $userMsg, [], null);

        // Strip markdown fences
        $html = $aiResult['content'] ?? '';
        $html = preg_replace('/^.*?```html\s*/is', '', $html);
        $html = preg_replace('/```\s*$/', '', $html);
        $html = trim($html);

        $responseData = [
            'html'  => $html,
            'model' => $aiResult['model'] ?? '',
            'usage' => $aiResult['usage'] ?? [],
        ];

        // Phase 1 of two-phase image generation: parse shortcodes, return metadata + preview
        if ($includeImages) {
            $images = apm_parse_image_shortcodes($html);
            if (!empty($images)) {
                $genId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
                $previewHtml = apm_render_image_placeholders($html, $images, $domain);
                $responseData['images']      = $images;
                $responseData['genId']       = $genId;
                $responseData['previewHtml'] = $previewHtml;
            }
        }

        api_json([
            'ok'   => true,
            'data' => $responseData,
        ]);
        break;

    // ═══════════════════════════════════
    // GENERATE SINGLE IMAGE (Phase 2 — per-image DALL-E call)
    // ═══════════════════════════════════

    case 'generate_single_image':
        $imgPrompt = trim($input['prompt'] ?? '');
        $genId     = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['genId'] ?? '');
        $idx       = (int)($input['idx'] ?? 0);
        $imgStyle  = trim($input['style'] ?? 'inline');
        $domain    = apm_sanitize_domain($input['domain'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        if ($imgPrompt === '' || $genId === '') {
            api_json(['ok' => false, 'error' => 'prompt and genId are required'], 400);
        }

        // Load image provider config
        $config = $providerReg->getConfig();
        $defaultImgProvider = $config['defaultImageProvider'] ?? 'none';
        $imgProviders = $config['imageProviders'] ?? [];
        $imgConf = $imgProviders[$defaultImgProvider] ?? [];

        if (empty($imgConf['enabled']) || empty($imgConf['apiKey'])) {
            api_json(['ok' => false, 'error' => 'No image provider configured. Set up DALL-E in AI Resources.'], 400);
        }

        // Override size based on shortcode style
        $imgConf['size'] = apm_dalle_size_for_style($imgStyle);

        // Call DALL-E
        $result = apm_generate_image_dalle($imgPrompt, $imgConf);
        if ($result === null) {
            api_json(['ok' => false, 'error' => 'Image generation failed — check API key and prompt'], 500);
        }

        // Save to filesystem
        $src = apm_save_resolved_image($domain, $genId, $idx, $result['data'], $result['format']);
        if ($src === '') {
            api_json(['ok' => false, 'error' => 'Failed to save generated image to disk'], 500);
        }

        // Add to image library
        apm_library_add_image([
            'src'            => $src,
            'prompt'         => $imgPrompt,
            'alt'            => $imgPrompt,
            'style'          => $imgStyle,
            'width'          => 0,
            'height'         => 0,
            'domain'         => $domain,
            'sourceGenId'    => $genId,
            'revisedPrompt'  => $result['revised_prompt'] ?? '',
        ]);

        api_json([
            'ok'   => true,
            'data' => [
                'src'            => $src,
                'idx'            => $idx,
                'revised_prompt' => $result['revised_prompt'] ?? '',
            ],
        ]);
        break;

    // ═══════════════════════════════════
    // SAVE CONFIG
    // ═══════════════════════════════════

    case 'save_config':
        // Provider settings
        if (isset($input['providers'])) {
            foreach ($input['providers'] as $id => $pConf) {
                $providerReg->updateProvider($id, $pConf);
            }
        }

        if (isset($input['activeProvider'])) {
            $providerReg->setActiveProvider($input['activeProvider']);
        }

        // Defaults
        if (isset($input['defaults'])) {
            $config = $providerReg->getConfig();
            $config['defaults'] = array_merge($config['defaults'] ?? [], $input['defaults']);
            $providerReg->saveConfig($config);
        }

        api_json(['ok' => true, 'data' => ['saved' => true]]);
        break;

    // ═══════════════════════════════════
    // PROVIDERS
    // ═══════════════════════════════════

    case 'providers':
        $config    = $providerReg->getConfig();
        $providers = $config['providers'] ?? [];
        $active    = $config['activeProvider'] ?? null;
        $list = [];
        foreach ($providers as $id => $p) {
            $list[] = [
                'id'       => $id,
                'label'    => $p['label'] ?? '',
                'type'     => $p['type'] ?? 'unknown',
                'model'    => $p['model'] ?? '',
                'maxTokens'=> $p['maxTokens'] ?? 4096,
                'enabled'  => $p['enabled'] ?? true,
                'active'   => ($id === $active),
                'apiKey'   => !empty($p['apiKey']) ? '••••' . substr($p['apiKey'], -4) : '(not set)',
                'baseUrl'  => $p['baseUrl'] ?? '',
            ];
        }
        api_json(['ok' => true, 'providers' => $list, 'active' => $active]);
        break;

    case 'provider_types':
        $types = $providerReg->getSupportedTypes();
        api_json(['ok' => true, 'types' => $types]);
        break;

    case 'add_provider':
        $id   = $input['id'] ?? '';
        $type = $input['type'] ?? '';
        $key  = $input['apiKey'] ?? '';
        $model= $input['model'] ?? '';
        $max  = (int)($input['maxTokens'] ?? 4096);
        $baseUrl = $input['baseUrl'] ?? '';
        $label = trim($input['label'] ?? '');

        if (!$id || !$type) {
            api_json(['ok' => false, 'error' => 'ID and type are required'], 400);
        }

        $config = $providerReg->getConfig();
        if (isset($config['providers'][$id])) {
            api_json(['ok' => false, 'error' => 'Provider ID already exists'], 400);
        }

        $providerConf = [
            'type'     => $type,
            'apiKey'   => $key,
            'model'    => $model,
            'maxTokens'=> $max,
            'enabled'  => true,
        ];
        if ($label !== '') {
            $providerConf['label'] = $label;
        }
        if ($type === 'custom' && $baseUrl !== '') {
            $providerConf['baseUrl'] = $baseUrl;
        }

        $config['providers'][$id] = $providerConf;

        // Set active if first provider
        if (count($config['providers']) === 1) {
            $config['activeProvider'] = $id;
        }

        $providerReg->saveConfig($config);
        api_json(['ok' => true, 'id' => $id]);
        break;

    case 'update_provider':
        $id = $input['id'] ?? '';
        if (!$id) {
            api_json(['ok' => false, 'error' => 'Provider ID required'], 400);
        }

        $config = $providerReg->getConfig();
        if (!isset($config['providers'][$id])) {
            api_json(['ok' => false, 'error' => 'Provider not found'], 404);
        }

        $updates = [];
        foreach (['type','model','maxTokens','enabled','baseUrl','label'] as $field) {
            if (isset($input[$field])) $updates[$field] = $input[$field];
        }
        // Only update apiKey if non-masked value provided
        if (!empty($input['apiKey']) && strpos($input['apiKey'], '••••') === false) {
            $updates['apiKey'] = $input['apiKey'];
        }

        $config['providers'][$id] = array_merge($config['providers'][$id], $updates);
        $providerReg->saveConfig($config);
        api_json(['ok' => true]);
        break;

    case 'remove_provider':
        $id = $input['id'] ?? '';
        if (!$id) {
            api_json(['ok' => false, 'error' => 'Provider ID required'], 400);
        }

        $config = $providerReg->getConfig();
        if (!isset($config['providers'][$id])) {
            api_json(['ok' => false, 'error' => 'Provider not found'], 404);
        }

        // Can't remove active provider if others exist
        if (($config['activeProvider'] ?? '') === $id && count($config['providers']) > 1) {
            api_json(['ok' => false, 'error' => 'Set a different active provider first'], 400);
        }

        unset($config['providers'][$id]);
        if (($config['activeProvider'] ?? '') === $id) {
            $config['activeProvider'] = array_key_first($config['providers']) ?? null;
        }

        $providerReg->saveConfig($config);
        api_json(['ok' => true]);
        break;

    case 'set_active':
        $id = $input['id'] ?? '';
        if (!$id) {
            api_json(['ok' => false, 'error' => 'Provider ID required'], 400);
        }
        $ok = $providerReg->setActiveProvider($id);
        api_json(['ok' => $ok, 'error' => $ok ? null : 'Provider not found']);
        break;

    // ── Pipeline routing defaults ──
    case 'get_pipeline_defaults':
        $defaults = $providerReg->getPipelineDefaults();
        api_json(['ok' => true, 'pipelineDefaults' => (object)$defaults]);
        break;

    case 'save_pipeline_defaults':
        $defaults = $input['pipelineDefaults'] ?? [];
        if (!is_array($defaults)) {
            api_json(['ok' => false, 'error' => 'pipelineDefaults must be an object'], 400);
        }
        // Validate that referenced provider IDs exist (or are empty for "global default")
        $config = $providerReg->getConfig();
        $providerIds = array_keys($config['providers'] ?? []);
        foreach ($defaults as $pipeline => $providerId) {
            if ($providerId !== '' && !in_array($providerId, $providerIds, true)) {
                api_json(['ok' => false, 'error' => "Provider '{$providerId}' not found"], 400);
            }
        }
        // Strip empty values (means "use global default")
        $defaults = array_filter($defaults, fn($v) => $v !== '');
        $ok = $providerReg->setPipelineDefaults($defaults);
        api_json(['ok' => $ok, 'error' => $ok ? null : 'Failed to save']);
        break;

    case 'test_provider':
        $id = $input['id'] ?? '';
        if (!$id) {
            api_json(['ok' => false, 'error' => 'Provider ID required'], 400);
        }
        try {
            $provider = $providerReg->getProvider($id);
            $result   = $provider->testConnection();
            api_json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            api_json(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ═══════════════════════════════════
    // MCP SERVERS
    // ═══════════════════════════════════

    case 'mcp_servers':
        $servers = $mcpReg->loadServers();
        $list = [];
        foreach ($servers as $id => $s) {
            $list[] = [
                'id'       => $s['id'] ?? $id,
                'name'     => $s['name'] ?? $id,
                'type'     => $s['type'] ?? 'stdio',
                'enabled'  => $s['enabled'] ?? true,
                'builtin'  => $s['builtin'] ?? false,
                'roles'    => $s['roles'] ?? [],
                'tags'     => $s['tags'] ?? [],
                'description' => $s['description'] ?? '',
            ];
        }
        api_json(['ok' => true, 'servers' => $list]);
        break;

    case 'mcp_add':
        $name = $input['name'] ?? '';
        $type = $input['type'] ?? 'stdio';
        if (!$name) {
            api_json(['ok' => false, 'error' => 'Server name required'], 400);
        }

        $serverConfig = [
            'name'     => $name,
            'type'     => $type,
            'enabled'  => $input['enabled'] ?? true,
            'roles'    => $input['roles'] ?? [],
            'tags'     => $input['tags'] ?? [],
            'description' => $input['description'] ?? '',
        ];

        if ($type === 'stdio') {
            $serverConfig['command'] = $input['command'] ?? '';
            $serverConfig['args']    = $input['args'] ?? [];
        } elseif ($type === 'http') {
            $serverConfig['url']  = $input['url'] ?? '';
            $serverConfig['auth'] = $input['auth'] ?? null;
        }

        $newId = $mcpReg->addServer($serverConfig);
        api_json(['ok' => true, 'id' => $newId]);
        break;

    case 'mcp_update':
        $id = $input['id'] ?? '';
        if (!$id) {
            api_json(['ok' => false, 'error' => 'Server ID required'], 400);
        }
        $ok = $mcpReg->updateServer($id, $input);
        api_json(['ok' => $ok, 'error' => $ok ? null : 'Server not found']);
        break;

    case 'mcp_remove':
        $id = $input['id'] ?? '';
        if (!$id) {
            api_json(['ok' => false, 'error' => 'Server ID required'], 400);
        }
        $ok = $mcpReg->removeServer($id);
        api_json(['ok' => $ok, 'error' => $ok ? null : 'Cannot remove built-in server']);
        break;

    case 'mcp_test':
        $id = $input['id'] ?? '';
        if (!$id) {
            api_json(['ok' => false, 'error' => 'Server ID required'], 400);
        }
        try {
            $result = $mcpReg->testServer($id);
            api_json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            api_json(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ═══════════════════════════════════
    // IMAGE PROVIDERS
    // ═══════════════════════════════════

    case 'image_providers':
        $config = $providerReg->getConfig();
        $images = $config['imageProviders'] ?? [];
        $list = [];
        foreach ($images as $id => $ip) {
            $list[] = [
                'id'      => $id,
                'enabled' => $ip['enabled'] ?? false,
                'model'   => $ip['model'] ?? '',
                'apiKey'  => !empty($ip['apiKey']) ? '••••' . substr($ip['apiKey'], -4) : '(not set)',
                'size'    => $ip['size'] ?? null,
                'quality' => $ip['quality'] ?? null,
                'provider'=> $ip['provider'] ?? null,
            ];
        }
        api_json([
            'ok' => true,
            'image_providers' => $list,
            'default_provider' => $config['defaultImageProvider'] ?? 'flux',
            'auto_save_library' => $config['autoSaveToLibrary'] ?? true,
        ]);
        break;

    case 'save_image_provider':
        $id = $input['id'] ?? '';
        if (!$id) {
            api_json(['ok' => false, 'error' => 'Provider ID required'], 400);
        }

        $config = $providerReg->getConfig();
        if (!isset($config['imageProviders'][$id])) {
            api_json(['ok' => false, 'error' => 'Image provider not found'], 404);
        }

        $updates = [];
        foreach (['enabled','model','size','quality','provider'] as $field) {
            if (isset($input[$field])) $updates[$field] = $input[$field];
        }
        if (!empty($input['apiKey']) && strpos($input['apiKey'], '••••') === false) {
            $updates['apiKey'] = $input['apiKey'];
        }

        $config['imageProviders'][$id] = array_merge($config['imageProviders'][$id], $updates);

        // Update defaults if provided
        if (isset($input['defaultImageProvider'])) {
            $config['defaultImageProvider'] = $input['defaultImageProvider'];
        }
        if (isset($input['autoSaveToLibrary'])) {
            $config['autoSaveToLibrary'] = (bool)$input['autoSaveToLibrary'];
        }

        $providerReg->saveConfig($config);
        api_json(['ok' => true]);
        break;

    // ═══════════════════════════════════
    // NOTEBOOKLM PODCAST
    // ═══════════════════════════════════

    case 'nlm_save_config':
        $config = $providerReg->getConfig();
        $nlm = $config['notebookLM'] ?? ['enabled' => false, 'projectId' => '', 'serviceAccountKey' => []];

        if (isset($input['enabled']))           $nlm['enabled']   = (bool)$input['enabled'];
        if (isset($input['projectId']))          $nlm['projectId'] = trim($input['projectId']);
        if (isset($input['serviceAccountKey'])) {
            $key = $input['serviceAccountKey'];
            // Accept JSON string or object
            if (is_string($key)) {
                $key = json_decode($key, true);
                if (!is_array($key)) {
                    api_json(['ok' => false, 'error' => 'Invalid service account key JSON'], 400);
                }
            }
            $nlm['serviceAccountKey'] = $key;
            // Auto-fill projectId from key if empty
            if ($nlm['projectId'] === '' && !empty($key['project_id'])) {
                $nlm['projectId'] = $key['project_id'];
            }
        }

        $config['notebookLM'] = $nlm;
        $providerReg->saveConfig($config);
        api_json(['ok' => true, 'notebookLM' => [
            'enabled'   => $nlm['enabled'],
            'projectId' => $nlm['projectId'],
            'hasKey'    => !empty($nlm['serviceAccountKey']['private_key']),
        ]]);
        break;

    case 'nlm_test_connection':
        $config = $providerReg->getConfig();
        $nlm = $config['notebookLM'] ?? [];

        require_once __DIR__ . '/providers/NotebookLMClient.php';
        $client = new NotebookLMClient($nlm);
        $result = $client->testConnection();
        api_json(['ok' => true, 'result' => $result]);
        break;

    case 'nlm_generate_podcast':
        $config = $providerReg->getConfig();
        $nlm = $config['notebookLM'] ?? [];

        if (empty($nlm['enabled'])) {
            api_json(['ok' => false, 'error' => 'NotebookLM is not enabled — configure it in AI Resources'], 400);
        }

        $pageSlug    = trim($input['pageSlug'] ?? '');
        $pageTitle   = trim($input['pageTitle'] ?? 'Untitled');
        $pageContent = trim($input['pageContent'] ?? '');
        $focus       = trim($input['focus'] ?? '');
        $length      = in_array($input['length'] ?? '', ['SHORT', 'STANDARD', 'LONG']) ? $input['length'] : 'STANDARD';
        $format      = in_array($input['format'] ?? '', ['CONVERSATION', 'DEEP_DIVE', 'DEBATE']) ? $input['format'] : 'CONVERSATION';

        if ($pageContent === '') {
            api_json(['ok' => false, 'error' => 'Page content cannot be empty'], 400);
        }
        if (strlen($pageContent) > 400000) {
            api_json(['ok' => false, 'error' => 'Content exceeds 400k character limit'], 400);
        }

        require_once __DIR__ . '/providers/NotebookLMClient.php';
        $client = new NotebookLMClient($nlm);
        $result = $client->generatePodcast($pageTitle, $pageContent, $focus, $length, 'en-US', $format);

        api_json([
            'ok'            => true,
            'operationName' => $result['name'] ?? '',
            'metadata'      => $result['metadata'] ?? [],
        ]);
        break;

    case 'nlm_check_status':
        $opName = trim($input['operationName'] ?? '');
        if ($opName === '') {
            api_json(['ok' => false, 'error' => 'operationName is required'], 400);
        }

        $config = $providerReg->getConfig();
        $nlm = $config['notebookLM'] ?? [];

        require_once __DIR__ . '/providers/NotebookLMClient.php';
        $client = new NotebookLMClient($nlm);
        $result = $client->checkOperation($opName);

        api_json([
            'ok'       => true,
            'done'     => !empty($result['done']),
            'error'    => $result['error'] ?? null,
            'metadata' => $result['metadata'] ?? [],
        ]);
        break;

    case 'nlm_save_audio':
        $opName       = trim($input['operationName'] ?? '');
        $pageSlug     = trim($input['pageSlug'] ?? '');
        $notebookName = trim($input['notebookName'] ?? '') ?: $pageSlug;
        $podTitle     = trim($input['title'] ?? 'Podcast');
        $podFocus     = trim($input['focus'] ?? '');
        $podLength    = trim($input['length'] ?? 'STANDARD');
        $podFormat    = trim($input['format'] ?? 'CONVERSATION');

        if ($opName === '' || $notebookName === '') {
            api_json(['ok' => false, 'error' => 'operationName and notebookName are required'], 400);
        }

        // Sanitize notebook name for filesystem
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $notebookName);
        $safeName = trim($safeName, '-');
        if ($safeName === '') $safeName = 'unnamed';

        $config = $providerReg->getConfig();
        $nlm = $config['notebookLM'] ?? [];

        require_once __DIR__ . '/providers/NotebookLMClient.php';
        $client = new NotebookLMClient($nlm);
        $audioBytes = $client->downloadPodcast($opName);

        if (strlen($audioBytes) < 100) {
            api_json(['ok' => false, 'error' => 'Downloaded audio is too small — may be empty or an error response'], 500);
        }

        // Save to /media/notebooklm/{notebook-name}/assets/
        $assetsDir = SITE_ROOT . '/media/notebooklm/' . $safeName . '/assets';
        if (!is_dir($assetsDir)) {
            @mkdir($assetsDir, 0775, true);
        }

        $timestamp = date('Ymd_His');
        $audioFile = $assetsDir . '/' . $timestamp . '.mp3';
        $metaFile  = $assetsDir . '/' . $timestamp . '.json';

        file_put_contents($audioFile, $audioBytes);
        @chmod($audioFile, 0664);

        $metadata = [
            'title'      => $podTitle,
            'focus'      => $podFocus,
            'length'     => $podLength,
            'format'     => $podFormat,
            'page_slug'  => $pageSlug,
            'notebook'   => $safeName,
            'created_at' => date('c'),
            'file_size'  => strlen($audioBytes),
            'filename'   => $timestamp,
        ];
        file_put_contents($metaFile, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($metaFile, 0664);

        api_json([
            'ok'       => true,
            'filename' => $timestamp,
            'path'     => '/media/notebooklm/' . $safeName . '/assets/' . $timestamp . '.mp3',
            'size'     => strlen($audioBytes),
            'metadata' => $metadata,
        ]);
        break;

    case 'nlm_list_podcasts':
        $pageSlug = trim($input['pageSlug'] ?? '');

        $nlmBase = SITE_ROOT . '/media/notebooklm';
        $podcasts = [];

        if (is_dir($nlmBase)) {
            foreach (scandir($nlmBase) as $nbDir) {
                if ($nbDir === '.' || $nbDir === '..') continue;
                $assetsPath = $nlmBase . '/' . $nbDir . '/assets';
                if (!is_dir($assetsPath)) continue;

                foreach (glob($assetsPath . '/*.json') as $metaPath) {
                    $meta = json_decode(file_get_contents($metaPath), true);
                    if (!is_array($meta)) continue;

                    // If pageSlug given, filter; otherwise return all
                    if ($pageSlug !== '' && ($meta['page_slug'] ?? '') !== $pageSlug) continue;

                    $tsBase  = basename($metaPath, '.json');
                    $mp3Path = $assetsPath . '/' . $tsBase . '.mp3';

                    $podcasts[] = [
                        'notebook'   => $nbDir,
                        'filename'   => $tsBase,
                        'title'      => $meta['title'] ?? 'Podcast',
                        'focus'      => $meta['focus'] ?? '',
                        'length'     => $meta['length'] ?? 'STANDARD',
                        'format'     => $meta['format'] ?? 'CONVERSATION',
                        'page_slug'  => $meta['page_slug'] ?? '',
                        'created_at' => $meta['created_at'] ?? '',
                        'file_size'  => $meta['file_size'] ?? (is_file($mp3Path) ? filesize($mp3Path) : 0),
                        'audioUrl'   => '/media/notebooklm/' . $nbDir . '/assets/' . $tsBase . '.mp3',
                    ];
                }
            }
        }

        // Sort newest first
        usort($podcasts, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        api_json(['ok' => true, 'podcasts' => $podcasts]);
        break;

    case 'nlm_delete_podcast':
        $notebookName = trim($input['notebookName'] ?? '');
        $filename     = trim($input['filename'] ?? '');

        if ($notebookName === '' || $filename === '') {
            api_json(['ok' => false, 'error' => 'notebookName and filename are required'], 400);
        }

        // Sanitize
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $notebookName);
        $safeFile = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);

        $assetsDir = SITE_ROOT . '/media/notebooklm/' . $safeName . '/assets';
        $mp3  = $assetsDir . '/' . $safeFile . '.mp3';
        $json = $assetsDir . '/' . $safeFile . '.json';

        $deleted = false;
        if (is_file($mp3))  { @unlink($mp3);  $deleted = true; }
        if (is_file($json)) { @unlink($json); $deleted = true; }

        // Clean up empty directory
        if (is_dir($assetsDir) && count(glob($assetsDir . '/*')) === 0) {
            @rmdir($assetsDir);
            $parentDir = dirname($assetsDir);
            if (is_dir($parentDir) && count(glob($parentDir . '/*')) === 0) {
                @rmdir($parentDir);
            }
        }

        api_json(['ok' => $deleted, 'error' => $deleted ? null : 'Files not found']);
        break;

    case 'nlm_add_to_podcast_manager':
        $notebookName = trim($input['notebookName'] ?? '');
        $filename     = trim($input['filename'] ?? '');
        $epTitle      = trim($input['title'] ?? 'NotebookLM Podcast');
        $epDescription = trim($input['description'] ?? '');

        if ($notebookName === '' || $filename === '') {
            api_json(['ok' => false, 'error' => 'notebookName and filename are required'], 400);
        }

        // Sanitize
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $notebookName);
        $safeFile = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);

        // Source NLM audio
        $srcMp3  = SITE_ROOT . '/media/notebooklm/' . $safeName . '/assets/' . $safeFile . '.mp3';
        $srcMeta = SITE_ROOT . '/media/notebooklm/' . $safeName . '/assets/' . $safeFile . '.json';

        if (!is_file($srcMp3)) {
            api_json(['ok' => false, 'error' => 'Source MP3 not found'], 404);
        }

        // Read NLM metadata for defaults
        $nlmMeta = [];
        if (is_file($srcMeta)) {
            $nlmMeta = json_decode(file_get_contents($srcMeta), true) ?: [];
        }
        if ($epTitle === 'NotebookLM Podcast' && !empty($nlmMeta['title'])) {
            $epTitle = $nlmMeta['title'];
        }
        if ($epDescription === '') {
            $parts = [];
            if (!empty($nlmMeta['focus'])) $parts[] = $nlmMeta['focus'];
            $parts[] = 'Generated by NotebookLM';
            if (!empty($nlmMeta['format']) && $nlmMeta['format'] !== 'CONVERSATION') {
                $parts[] = 'Format: ' . $nlmMeta['format'];
            }
            $epDescription = implode(' — ', $parts);
        }

        // Destination in PodcastManager episodes dir
        $episodesDir = SITE_ROOT . '/media/podcasts/episodes';
        $dataDir     = SITE_ROOT . '/admin/data/podcasts';
        if (!is_dir($episodesDir)) @mkdir($episodesDir, 0775, true);
        if (!is_dir($dataDir))     @mkdir($dataDir, 0775, true);

        // Copy MP3 to episodes dir (unique filename to avoid collisions)
        $destFilename = 'nlm-' . $safeName . '-' . $safeFile . '.mp3';
        $destMp3 = $episodesDir . '/' . $destFilename;
        if (!@copy($srcMp3, $destMp3)) {
            api_json(['ok' => false, 'error' => 'Failed to copy MP3 to episodes directory'], 500);
        }
        @chmod($destMp3, 0664);

        // Get MP3 duration via ffprobe if available
        $duration = '0:00';
        $ffprobe = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
        if ($ffprobe !== '') {
            $durRaw = trim(shell_exec(
                escapeshellcmd($ffprobe) . ' -v quiet -show_entries format=duration -of csv=p=0 ' .
                escapeshellarg($destMp3) . ' 2>/dev/null'
            ) ?? '');
            if (is_numeric($durRaw)) {
                $secs = (int)round((float)$durRaw);
                $mins = intdiv($secs, 60);
                $remainSecs = $secs % 60;
                $duration = $mins . ':' . str_pad((string)$remainSecs, 2, '0', STR_PAD_LEFT);
            }
        }

        // Load PodcastManager config + functions to use saveMetadata()
        $pmConfigFile = SITE_ROOT . '/admin/modules/PodcastManager/config/config.php';
        $pmFunctions  = SITE_ROOT . '/admin/modules/PodcastManager/admin/episode_tagger_functions.php';

        if (!is_file($pmConfigFile) || !is_file($pmFunctions)) {
            api_json(['ok' => false, 'error' => 'PodcastManager module not found on this site'], 404);
        }

        // PodcastManager config defines constants (DOMAIN, EPISODES_DIR, JSON_FILE_PATH, etc.)
        if (!defined('DOMAIN')) {
            require_once $pmConfigFile;
        }
        require_once $pmFunctions;

        // Build episode metadata
        $guid = 'episode_' . uniqid();
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $domain = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? basename(SITE_ROOT));
        $audioUrl = $domain . '/media/podcasts/episodes/' . rawurlencode($destFilename);

        $episodeData = [
            'guid'          => $guid,
            'audioUrl'      => $audioUrl,
            'relativePath'  => $destFilename,
            'title'         => $epTitle,
            'description'   => $epDescription,
            'pubDate'       => date('r'),
            'duration'      => $duration,
            'album'         => '',
            'artist'        => 'NotebookLM',
            'year'          => date('Y'),
            'track'         => '',
            'genre'         => '',
            'episodeNumber' => null,
            'seasonNumber'  => null,
            'appleTitle'    => '',
            'appleDescription' => '',
            'explicit'      => 'no',
            'type'          => 'full',
            'coverUrl'      => '',
            'nlm_source'    => [
                'notebook'  => $safeName,
                'filename'  => $safeFile,
                'format'    => $nlmMeta['format'] ?? 'CONVERSATION',
                'length'    => $nlmMeta['length'] ?? 'STANDARD',
            ],
        ];

        $saved = saveMetadata($guid, $episodeData, $audioUrl);

        if ($saved) {
            api_json([
                'ok'       => true,
                'guid'     => $guid,
                'filename' => $destFilename,
                'duration' => $duration,
                'audioUrl' => $audioUrl,
            ]);
        } else {
            // Clean up copied file on save failure
            @unlink($destMp3);
            api_json(['ok' => false, 'error' => 'Failed to save episode metadata'], 500);
        }
        break;

    // ═══════════════════════════════════
    // PROMPT COMMONS — reusable prompt library
    // ═══════════════════════════════════

    case 'prompt_commons_list':
        $pcFile = $dataDir . '/prompt_commons.json';
        $prompts = is_file($pcFile) ? (json_decode(file_get_contents($pcFile), true) ?: []) : [];
        usort($prompts, fn($a, $b) => ($b['used_at'] ?? $b['created_at'] ?? '') <=> ($a['used_at'] ?? $a['created_at'] ?? ''));
        api_json(['ok' => true, 'prompts' => $prompts]);
        break;

    case 'prompt_commons_save':
        $pcFile = $dataDir . '/prompt_commons.json';
        $prompts = is_file($pcFile) ? (json_decode(file_get_contents($pcFile), true) ?: []) : [];

        $name     = trim($input['name'] ?? '');
        $prompt   = trim($input['prompt'] ?? '');
        $tone     = trim($input['tone'] ?? 'professional');
        $type     = trim($input['contentType'] ?? 'article');
        $wordCount = (int)($input['wordCount'] ?? 300);

        if ($name === '' || $prompt === '') {
            api_json(['ok' => false, 'error' => 'Name and prompt are required'], 400);
        }

        $id = $input['id'] ?? ('pc_' . substr(md5($name . microtime()), 0, 10));

        // Update existing or add new
        $found = false;
        foreach ($prompts as &$p) {
            if ($p['id'] === $id) {
                $p['name']        = $name;
                $p['prompt']      = $prompt;
                $p['tone']        = $tone;
                $p['contentType'] = $type;
                $p['wordCount']   = $wordCount;
                $p['updated_at']  = date('c');
                $found = true;
                break;
            }
        }
        unset($p);

        if (!$found) {
            $prompts[] = [
                'id'          => $id,
                'name'        => $name,
                'prompt'      => $prompt,
                'tone'        => $tone,
                'contentType' => $type,
                'wordCount'   => $wordCount,
                'created_at'  => date('c'),
                'used_at'     => null,
            ];
        }

        file_put_contents($pcFile, json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($pcFile, 0664);
        api_json(['ok' => true, 'id' => $id]);
        break;

    case 'prompt_commons_delete':
        $pcFile = $dataDir . '/prompt_commons.json';
        $prompts = is_file($pcFile) ? (json_decode(file_get_contents($pcFile), true) ?: []) : [];
        $delId = trim($input['id'] ?? '');
        if ($delId === '') {
            api_json(['ok' => false, 'error' => 'id is required'], 400);
        }
        $prompts = array_values(array_filter($prompts, fn($p) => $p['id'] !== $delId));
        file_put_contents($pcFile, json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($pcFile, 0664);
        api_json(['ok' => true]);
        break;

    case 'prompt_commons_use':
        $pcFile = $dataDir . '/prompt_commons.json';
        $prompts = is_file($pcFile) ? (json_decode(file_get_contents($pcFile), true) ?: []) : [];
        $useId = trim($input['id'] ?? '');
        foreach ($prompts as &$p) {
            if ($p['id'] === $useId) {
                $p['used_at'] = date('c');
                break;
            }
        }
        unset($p);
        file_put_contents($pcFile, json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($pcFile, 0664);
        api_json(['ok' => true]);
        break;

    // ═══════════════════════════════════
    // PUSH GLOBAL (copy config to all sites)
    // ═══════════════════════════════════

    case 'push_global':
        $vhostsDir = '/var/www/vhosts';
        $currentDomain = basename(SITE_ROOT);
        $pushMcp = !empty($input['include_mcp']);

        $updated = 0;
        $skipped = 0;
        $errors  = [];

        foreach (scandir($vhostsDir) as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === false) continue;
            $siteRoot = $vhostsDir . '/' . $entry;
            if (!is_dir($siteRoot . '/admin')) continue;
            if ($entry === $currentDomain) continue;

            $targetDir = $siteRoot . '/admin/data/AIResources';
            if (!is_dir($targetDir)) {
                if (!@mkdir($targetDir, 0775, true)) {
                    $errors[] = $entry . ': cannot create directory';
                    continue;
                }
            }

            // Copy config.json
            $ok = @copy($configFile, $targetDir . '/config.json');
            if ($ok) {
                @chmod($targetDir . '/config.json', 0664);
                @chown($targetDir . '/config.json', 'www-data');
            } else {
                $errors[] = $entry . ': failed to copy config';
                continue;
            }

            // Copy MCP servers if requested
            if ($pushMcp && is_file($mcpFile)) {
                $ok2 = @copy($mcpFile, $targetDir . '/mcp_servers.json');
                if ($ok2) {
                    @chmod($targetDir . '/mcp_servers.json', 0664);
                    @chown($targetDir . '/mcp_servers.json', 'www-data');
                }
            }

            $updated++;
        }

        api_json([
            'ok'      => true,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
            'source'  => $currentDomain,
        ]);
        break;

    // ═══════════════════════════════════
    // AI DRAFTS — auto-save & retrieve
    // ═══════════════════════════════════

    case 'save_ai_draft':
        $draftsDir = dirname(__DIR__, 2) . '/data/ai_drafts';
        if (!is_dir($draftsDir)) {
            @mkdir($draftsDir, 0775, true);
        }

        $genId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['genId'] ?? '');
        if ($genId === '') {
            api_json(['ok' => false, 'error' => 'genId is required'], 400);
        }

        $draft = [
            'genId'      => $genId,
            'prompt'     => trim($input['prompt'] ?? ''),
            'html'       => $input['html'] ?? '',
            'previewHtml'=> $input['previewHtml'] ?? '',
            'images'     => $input['images'] ?? [],
            'settings'   => $input['settings'] ?? [],
            'provider'   => $input['provider'] ?? '',
            'model'      => $input['model'] ?? '',
            'usage'      => $input['usage'] ?? [],
            'pageSlug'   => trim($input['pageSlug'] ?? ''),
            'pageTitle'  => trim($input['pageTitle'] ?? ''),
            'domain'     => trim($input['domain'] ?? ''),
            'created_at' => $input['created_at'] ?? date('c'),
            'inserted'   => !empty($input['inserted']),
        ];

        $draftFile = $draftsDir . '/' . $genId . '.json';
        $ok = file_put_contents($draftFile, json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($ok !== false) {
            @chmod($draftFile, 0664);
        }

        // Auto-prune: keep max 50 drafts, delete oldest
        $allDrafts = glob($draftsDir . '/*.json');
        if (count($allDrafts) > 50) {
            usort($allDrafts, fn($a, $b) => filemtime($a) <=> filemtime($b));
            $toDelete = array_slice($allDrafts, 0, count($allDrafts) - 50);
            foreach ($toDelete as $old) {
                @unlink($old);
            }
        }

        api_json(['ok' => $ok !== false, 'genId' => $genId]);
        break;

    case 'list_ai_drafts':
        $draftsDir = dirname(__DIR__, 2) . '/data/ai_drafts';
        $pageFilter = trim($input['pageSlug'] ?? $_GET['pageSlug'] ?? '');
        $drafts = [];

        if (is_dir($draftsDir)) {
            foreach (glob($draftsDir . '/*.json') as $f) {
                $d = json_decode((string)file_get_contents($f), true);
                if (!is_array($d)) continue;
                if ($pageFilter !== '' && ($d['pageSlug'] ?? '') !== $pageFilter) continue;
                $drafts[] = [
                    'genId'      => $d['genId'] ?? basename($f, '.json'),
                    'prompt'     => mb_substr($d['prompt'] ?? '', 0, 120),
                    'model'      => $d['model'] ?? '',
                    'provider'   => $d['provider'] ?? '',
                    'usage'      => $d['usage'] ?? [],
                    'pageSlug'   => $d['pageSlug'] ?? '',
                    'pageTitle'  => $d['pageTitle'] ?? '',
                    'domain'     => $d['domain'] ?? '',
                    'created_at' => $d['created_at'] ?? '',
                    'inserted'   => !empty($d['inserted']),
                    'hasImages'  => !empty($d['images']),
                ];
            }
        }

        // Sort newest first
        usort($drafts, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        api_json(['ok' => true, 'drafts' => $drafts]);
        break;

    case 'get_ai_draft':
        $draftsDir = dirname(__DIR__, 2) . '/data/ai_drafts';
        $genId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['genId'] ?? $_GET['genId'] ?? '');
        if ($genId === '') {
            api_json(['ok' => false, 'error' => 'genId is required'], 400);
        }

        $draftFile = $draftsDir . '/' . $genId . '.json';
        if (!is_file($draftFile)) {
            api_json(['ok' => false, 'error' => 'Draft not found'], 404);
        }

        $draft = json_decode((string)file_get_contents($draftFile), true);
        api_json(['ok' => true, 'draft' => $draft]);
        break;

    case 'delete_ai_draft':
        $draftsDir = dirname(__DIR__, 2) . '/data/ai_drafts';
        $genId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['genId'] ?? '');
        if ($genId === '') {
            api_json(['ok' => false, 'error' => 'genId is required'], 400);
        }

        $draftFile = $draftsDir . '/' . $genId . '.json';
        $deleted = is_file($draftFile) && @unlink($draftFile);
        api_json(['ok' => $deleted, 'error' => $deleted ? null : 'Draft not found']);
        break;

    default:
        api_json(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
}
} catch (\Throwable $e) {
    api_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
