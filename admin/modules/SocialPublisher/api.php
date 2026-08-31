<?php
/**
 * SocialPublisher — API Endpoints
 * CRUD for providers, compose posts, AI generation, image upload, post history
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Data Helpers ────────────────────────────────────────────

function sp_data_dir(): string
{
    $dir = SITE_ROOT . '/admin/data/SocialPublisher';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function sp_posts_dir(): string
{
    $dir = sp_data_dir() . '/posts';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function sp_load_providers(): array
{
    $file = sp_data_dir() . '/providers.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function sp_save_providers(array $providers): void
{
    file_put_contents(sp_data_dir() . '/providers.json', json_encode($providers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function sp_mask_credentials(array $provider): array
{
    $masked = $provider;
    if (isset($masked['credentials'])) {
        foreach ($masked['credentials'] as $key => $val) {
            if (is_string($val) && strlen($val) > 8) {
                $masked['credentials'][$key] = substr($val, 0, 4) . str_repeat('*', 8) . substr($val, -4);
            }
        }
    }
    return $masked;
}

function sp_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sp_get_platform(array $provider): ?SocialPlatform
{
    $platform = $provider['platform'] ?? '';
    $creds    = $provider['credentials'] ?? [];

    switch ($platform) {
        case 'facebook':
            require_once __DIR__ . '/lib/FacebookPlatform.php';
            return new FacebookPlatform($creds);
        case 'twitter':
            require_once __DIR__ . '/lib/TwitterPlatform.php';
            return new TwitterPlatform($creds);
        default:
            return null;
    }
}

function sp_save_post_record(array $record): void
{
    $id = $record['id'] ?? ('sp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)));
    $record['id'] = $id;
    file_put_contents(sp_posts_dir() . '/' . $id . '.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ── Routes ──────────────────────────────────────────────────

switch ($action) {

    // ── Provider CRUD ───────────────────────────────────────

    case 'list_providers':
        $providers = sp_load_providers();
        $masked = array_map('sp_mask_credentials', $providers);
        sp_json(['ok' => true, 'providers' => array_values($masked)]);
        break;

    case 'save_provider': {
        $id       = $input['id'] ?? '';
        $name     = trim($input['name'] ?? '');
        $platform = $input['platform'] ?? '';
        $creds    = $input['credentials'] ?? [];
        $enabled  = $input['enabled'] ?? true;

        if (!$name) sp_json(['ok' => false, 'error' => 'Provider name is required'], 400);
        if (!in_array($platform, ['facebook', 'twitter'])) sp_json(['ok' => false, 'error' => 'Invalid platform'], 400);

        $providers = sp_load_providers();

        if ($id) {
            // Update existing — merge credentials (keep existing if masked values sent)
            $idx = array_search($id, array_column($providers, 'id'));
            if ($idx === false) sp_json(['ok' => false, 'error' => 'Provider not found'], 404);

            $existing = $providers[$idx];
            foreach ($creds as $k => $v) {
                if (is_string($v) && strpos($v, '****') !== false) {
                    $creds[$k] = $existing['credentials'][$k] ?? $v;
                }
            }

            $providers[$idx]['name']        = $name;
            $providers[$idx]['platform']    = $platform;
            $providers[$idx]['credentials'] = $creds;
            $providers[$idx]['enabled']     = (bool) $enabled;
        } else {
            // Create new
            $id = strtolower(substr($platform, 0, 2)) . '_' . preg_replace('/[^a-z0-9]/', '', strtolower($name)) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
            $providers[] = [
                'id'          => $id,
                'name'        => $name,
                'platform'    => $platform,
                'credentials' => $creds,
                'enabled'     => (bool) $enabled,
                'created_at'  => date('c'),
                'last_post_at' => null,
            ];
        }

        sp_save_providers($providers);
        sp_json(['ok' => true, 'id' => $id]);
        break;
    }

    case 'delete_provider': {
        $id = $input['id'] ?? '';
        $providers = sp_load_providers();
        $providers = array_values(array_filter($providers, fn($p) => $p['id'] !== $id));
        sp_save_providers($providers);
        sp_json(['ok' => true]);
        break;
    }

    case 'test_provider': {
        $id = $input['id'] ?? '';
        $providers = sp_load_providers();
        $provider = null;
        foreach ($providers as $p) {
            if ($p['id'] === $id) { $provider = $p; break; }
        }
        if (!$provider) sp_json(['ok' => false, 'error' => 'Provider not found'], 404);

        $adapter = sp_get_platform($provider);
        if (!$adapter) sp_json(['ok' => false, 'error' => 'Unsupported platform: ' . ($provider['platform'] ?? '')], 400);

        $result = $adapter->testConnection();
        sp_json($result);
        break;
    }

    // ── Compose / Post ──────────────────────────────────────

    case 'compose_post': {
        $providerIds = $input['provider_ids'] ?? [];
        $content     = trim($input['content'] ?? '');
        $imageUrl    = trim($input['image_url'] ?? '');
        $imagePath   = trim($input['image_path'] ?? '');
        $linkUrl     = trim($input['link_url'] ?? '');
        $postType    = $input['post_type'] ?? 'auto'; // auto, text, photo

        if (empty($providerIds)) sp_json(['ok' => false, 'error' => 'Select at least one provider'], 400);
        if (!$content) sp_json(['ok' => false, 'error' => 'Content is required'], 400);

        $providers = sp_load_providers();
        $results = [];
        $imageSource = null;

        // Resolve image
        if ($imageUrl) {
            $imageSource = $imageUrl;
        } elseif ($imagePath) {
            // Local path — resolve relative to site root or media dir
            $fullPath = $imagePath;
            if (strpos($imagePath, '/') !== 0) {
                $fullPath = SITE_ROOT . '/' . ltrim($imagePath, '/');
            }
            if (file_exists($fullPath)) {
                $imageSource = $fullPath;
            }
        }

        $hasImage = ($imageSource !== null);
        if ($postType === 'auto') {
            $postType = $hasImage ? 'photo' : 'text';
        }

        foreach ($providerIds as $pid) {
            $provider = null;
            foreach ($providers as &$p) {
                if ($p['id'] === $pid) { $provider = &$p; break; }
            }
            unset($p);
            if (!$provider || !$provider['enabled']) {
                $results[] = ['provider_id' => $pid, 'ok' => false, 'message' => 'Provider not found or disabled'];
                continue;
            }

            $adapter = sp_get_platform($provider);
            if (!$adapter) {
                $results[] = ['provider_id' => $pid, 'ok' => false, 'message' => 'Unsupported platform'];
                continue;
            }

            if ($postType === 'photo' && $imageSource) {
                $res = $adapter->postPhoto($content, $imageSource, $linkUrl ?: null);
            } else {
                $res = $adapter->postStatus($content, $linkUrl ?: null);
            }

            $results[] = array_merge(['provider_id' => $pid, 'provider_name' => $provider['name'], 'platform' => $provider['platform']], $res);

            // Update last_post_at
            if ($res['ok']) {
                $provider['last_post_at'] = date('c');
            }
        }

        sp_save_providers($providers);

        // Save post record
        $allOk = count(array_filter($results, fn($r) => $r['ok'])) > 0;
        sp_save_post_record([
            'content'     => $content,
            'image'       => $imageSource,
            'link_url'    => $linkUrl,
            'post_type'   => $postType,
            'providers'   => $providerIds,
            'results'     => $results,
            'status'      => $allOk ? 'posted' : 'failed',
            'created_at'  => date('c'),
            'source'      => 'manual',
        ]);

        sp_json(['ok' => $allOk, 'results' => $results]);
        break;
    }

    // ── AI Content Generation ───────────────────────────────

    case 'generate_content': {
        $prompt = trim($input['prompt'] ?? '');
        $tone   = $input['tone'] ?? 'professional';
        if (!$prompt) sp_json(['ok' => false, 'error' => 'Prompt is required'], 400);

        // Use ProviderRegistry from AIResources
        $providerRegistryPath = SITE_ROOT . '/admin/modules/AIResources/providers/ProviderRegistry.php';
        if (!file_exists($providerRegistryPath)) {
            sp_json(['ok' => false, 'error' => 'AIResources module not available'], 500);
        }
        require_once $providerRegistryPath;
        $registry = new ProviderRegistry();
        $aiProvider = $registry->getActiveProvider();
        if (!$aiProvider) sp_json(['ok' => false, 'error' => 'No active AI provider configured'], 500);

        $systemPrompt = "You are a social media copywriter. Write engaging social media post text based on the user's prompt. " .
            "Tone: {$tone}. Keep it concise (under 280 characters ideal for Twitter, can be longer for Facebook). " .
            "Do NOT include hashtags unless specifically asked. Return ONLY the post text, no quotes or labels.";

        $result = $aiProvider->generate($systemPrompt, $prompt);
        $text = trim($result['content'] ?? '');

        sp_json(['ok' => (bool) $text, 'content' => $text, 'model' => $result['model'] ?? '', 'usage' => $result['usage'] ?? []]);
        break;
    }

    case 'generate_image': {
        $prompt = trim($input['prompt'] ?? '');
        if (!$prompt) sp_json(['ok' => false, 'error' => 'Image prompt is required'], 400);

        $aiFunctionsPath = SITE_ROOT . '/admin/modules/AIResources/AIFunctions.php';
        if (!file_exists($aiFunctionsPath)) {
            sp_json(['ok' => false, 'error' => 'AIResources/AIFunctions not available'], 500);
        }
        require_once $aiFunctionsPath;

        // Load AI config for DALL-E API key
        $aiConfigFile = SITE_ROOT . '/admin/data/AIResources/config.json';
        $aiConfig = file_exists($aiConfigFile) ? json_decode(file_get_contents($aiConfigFile), true) : [];
        $dalleConfig = [
            'apiKey'  => $aiConfig['image_providers']['dalle']['api_key'] ?? $aiConfig['openai_api_key'] ?? '',
            'model'   => 'dall-e-3',
            'size'    => '1024x1024',
            'quality' => 'standard',
        ];

        if (!$dalleConfig['apiKey']) {
            sp_json(['ok' => false, 'error' => 'No DALL-E / OpenAI API key configured in AIResources'], 500);
        }

        $result = apm_generate_image_dalle($prompt, $dalleConfig);
        if (empty($result['data'])) {
            sp_json(['ok' => false, 'error' => 'Image generation failed']);
        }

        // Save to /media/images/
        $mediaDir = SITE_ROOT . '/media/images';
        if (!is_dir($mediaDir)) mkdir($mediaDir, 0755, true);

        $filename = 'ai_social_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.png';
        $savePath = $mediaDir . '/' . $filename;
        file_put_contents($savePath, $result['data']);

        $webPath = '/media/images/' . $filename;
        sp_json([
            'ok'              => true,
            'path'            => $webPath,
            'filename'        => $filename,
            'revised_prompt'  => $result['revised_prompt'] ?? '',
        ]);
        break;
    }

    // ── Image Upload (drag-drop) ────────────────────────────

    case 'upload_image': {
        if (empty($_FILES['image'])) {
            sp_json(['ok' => false, 'error' => 'No file uploaded'], 400);
        }

        $file = $_FILES['image'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            sp_json(['ok' => false, 'error' => 'Invalid image type: ' . $mime], 400);
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            sp_json(['ok' => false, 'error' => 'File too large (max 10MB)'], 400);
        }

        $mediaDir = SITE_ROOT . '/media/images';
        if (!is_dir($mediaDir)) mkdir($mediaDir, 0755, true);

        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'jpg';
        $filename = 'social_upload_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
        $savePath = $mediaDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $savePath)) {
            sp_json(['ok' => false, 'error' => 'Failed to save uploaded file'], 500);
        }

        $webPath = '/media/images/' . $filename;
        sp_json(['ok' => true, 'path' => $webPath, 'filename' => $filename]);
        break;
    }

    // ── Post History ────────────────────────────────────────

    case 'list_posts': {
        $dir = sp_posts_dir();
        $files = glob($dir . '/*.json');
        $posts = [];

        foreach ($files as $f) {
            $post = json_decode(file_get_contents($f), true);
            if ($post) $posts[] = $post;
        }

        // Sort by created_at descending
        usort($posts, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

        // Pagination
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(50, max(10, (int) ($_GET['per_page'] ?? 20)));
        $total   = count($posts);
        $posts   = array_slice($posts, ($page - 1) * $perPage, $perPage);

        sp_json(['ok' => true, 'posts' => $posts, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;
    }

    default:
        sp_json(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
}
