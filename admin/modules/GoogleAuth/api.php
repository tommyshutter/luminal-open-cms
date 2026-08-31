<?php
/**
 * GoogleAuth — API Endpoint
 *
 * Admin actions (auth required): get_config, save_config
 * Public actions (no auth): oauth_init, oauth_callback
 *
 * @package  LuminalCMS
 * @module   GoogleAuth
 * @version  1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/utils.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Helper: JSON response ──
function ga_json(array $data, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Data helpers ──
function ga_data_dir(): string {
    $dir = SITE_ROOT . '/admin/data/GoogleAuth';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @chown($dir, 'www-data');
    }
    return $dir;
}

function ga_load_config(): array {
    $file = ga_data_dir() . '/config.json';
    if (!is_file($file)) {
        return [
            'enabled' => false,
            'client_id' => '',
            'client_secret' => '',
            'allowed_domains' => [],
            'auto_create_users' => true,
            'default_role' => 'staff',
        ];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function ga_save_config(array $config): bool {
    $file = ga_data_dir() . '/config.json';
    $result = file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($result !== false) {
        @chmod($file, 0664);
        @chown($file, 'www-data');
    }
    return $result !== false;
}

// ── Public: OAuth initiation (redirects to Google) ──
if ($action === 'oauth_init') {
    $config = ga_load_config();
    if (empty($config['enabled']) || empty($config['client_id'])) {
        header('Location: /admin/login.php?error=google_auth_not_configured');
        exit;
    }

    // Build Google OAuth URL
    $redirectUri = ga_get_redirect_uri();
    $state = bin2hex(random_bytes(16));

    // Store state in session for CSRF protection
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['google_oauth_state'] = $state;

    // Track login source — admin panel vs member portal
    $source = $_GET['source'] ?? 'admin';
    $_SESSION['google_oauth_source'] = $source;

    // Optional return_to for public-facing flows (e.g. invite redemption)
    if (!empty($_GET['return_to'])) {
        $returnTo = $_GET['return_to'];
        // Only allow same-origin relative paths or same-host URLs
        if (str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
            $_SESSION['google_oauth_return_to'] = $returnTo;
        }
    }

    $params = http_build_query([
        'client_id'     => $config['client_id'],
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

// ── Public: OAuth callback (Google redirects here) ──
if ($action === 'oauth_callback') {
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Check for errors from Google
    if (!empty($_GET['error'])) {
        $err = htmlspecialchars($_GET['error']);
        header("Location: /admin/login.php?error=google_denied&detail={$err}");
        exit;
    }

    $code  = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';

    // CSRF check
    if (empty($state) || !isset($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
        header('Location: /admin/login.php?error=google_state_mismatch');
        exit;
    }
    unset($_SESSION['google_oauth_state']);

    if (empty($code)) {
        header('Location: /admin/login.php?error=google_no_code');
        exit;
    }

    $config = ga_load_config();
    if (empty($config['client_id']) || empty($config['client_secret'])) {
        header('Location: /admin/login.php?error=google_auth_not_configured');
        exit;
    }

    $redirectUri = ga_get_redirect_uri();

    // Exchange code for tokens
    $tokenData = ga_exchange_code($code, $config, $redirectUri);
    if (!$tokenData) {
        header('Location: /admin/login.php?error=google_token_exchange_failed');
        exit;
    }

    // Decode ID token to get user info
    $userInfo = ga_decode_id_token($tokenData['id_token'] ?? '');
    if (!$userInfo || empty($userInfo['email'])) {
        // Fallback: use the access token to fetch userinfo
        $userInfo = ga_fetch_userinfo($tokenData['access_token'] ?? '');
        if (!$userInfo || empty($userInfo['email'])) {
            header('Location: /admin/login.php?error=google_no_email');
            exit;
        }
    }

    // Domain restriction check
    if (!empty($config['allowed_domains'])) {
        $emailDomain = strtolower(substr($userInfo['email'], strpos($userInfo['email'], '@') + 1));
        $allowed = array_map('strtolower', array_map('trim', $config['allowed_domains']));
        if (!in_array($emailDomain, $allowed, true)) {
            header('Location: /admin/login.php?error=google_domain_not_allowed');
            exit;
        }
    }

    // Find or create user
    require_once SITE_ROOT . '/admin/includes/auth.php';

    $email = strtolower(trim($userInfo['email']));
    $existingUser = findUserByIdentifier($email);

    if ($existingUser) {
        // Existing user — check status
        if ($existingUser['status'] !== 'active') {
            header('Location: /admin/login.php?error=account_deactivated');
            exit;
        }

        // Update OAuth fields if not set yet
        $data = loadUsersData();
        foreach ($data['users'] as &$u) {
            if ($u['id'] === $existingUser['id']) {
                $u['oauth_provider'] = 'google';
                $u['oauth_id'] = $userInfo['sub'] ?? '';
                if (empty($u['avatar_url']) && !empty($userInfo['picture'])) {
                    $u['avatar_url'] = $userInfo['picture'];
                }
                $u['last_login'] = date('c');
                break;
            }
        }
        unset($u);
        saveUsersData($data);

        $user = $existingUser;
    } elseif (!empty($config['auto_create_users'])) {
        // SECURITY: auto_create requires allowed_domains to be set — no open door
        if (empty($config['allowed_domains'])) {
            header('Location: /admin/login.php?error=google_domain_not_allowed');
            exit;
        }

        // SECURITY: auto-created users can NEVER be admin/superadmin
        $autoRole = $config['default_role'] ?? 'staff';
        if (in_array($autoRole, ['admin', 'superadmin', 'super'], true)) {
            $autoRole = 'staff'; // Force downgrade — admins must be created manually
        }

        // Auto-create new user
        $displayName = trim(($userInfo['name'] ?? '') ?: explode('@', $email)[0]);
        $username = preg_replace('/[^a-z0-9_]/', '', strtolower(explode('@', $email)[0]));

        $data = loadUsersData();
        $newUser = [
            'id'                     => generateUserId(),
            'email'                  => $email,
            'username'               => $username,
            'password_hash'          => '',  // No password — OAuth only
            'role'                   => $autoRole,
            'display_name'           => $displayName,
            'created_at'             => date('c'),
            'updated_at'             => date('c'),
            'last_login'             => date('c'),
            'status'                 => 'active',
            'password_reset_token'   => null,
            'password_reset_expires' => null,
            'oauth_provider'         => 'google',
            'oauth_id'              => $userInfo['sub'] ?? '',
            'avatar_url'            => $userInfo['picture'] ?? '',
            'settings'              => ['notify_on_login' => false],
        ];

        $data['users'][] = $newUser;
        saveUsersData($data);
        $user = $newUser;
    } else {
        // No auto-create — user must exist
        header('Location: /admin/login.php?error=google_no_account');
        exit;
    }

    // Set session (same 5 vars as standard login)
    session_regenerate_id(true);
    $_SESSION['user_id']           = $user['id'];
    $_SESSION['user_role']         = $user['role'];
    $_SESSION['user_email']        = $user['email'];
    $_SESSION['user_display_name'] = $user['display_name'];
    $_SESSION['login_time']        = time();

    // Check for return_to redirect (public flows like invite redemption)
    $returnTo = $_SESSION['google_oauth_return_to'] ?? '';
    unset($_SESSION['google_oauth_return_to']);
    if ($returnTo && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
        header('Location: ' . $returnTo);
    } else {
        header('Location: /admin/dashboard.php');
    }
    exit;
}

// ── Helper: build redirect URI ──
function ga_get_redirect_uri(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/admin/modules/GoogleAuth/api.php?action=oauth_callback';
}

// ── Helper: exchange authorization code for tokens ──
function ga_exchange_code(string $code, array $config, string $redirectUri): ?array {
    $postData = http_build_query([
        'code'          => $code,
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 200 || !$response) {
        error_log("GoogleAuth: Token exchange failed. HTTP {$httpCode}. Response: " . ($response ?: 'empty'));
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

// ── Helper: decode JWT ID token (no verification — Google already signed it) ──
function ga_decode_id_token(string $idToken): ?array {
    if (empty($idToken)) return null;
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) return null;

    $payload = $parts[1];
    // Fix base64url encoding
    $payload = str_replace(['-', '_'], ['+', '/'], $payload);
    $payload = base64_decode($payload);
    if (!$payload) return null;

    $data = json_decode($payload, true);
    return is_array($data) ? $data : null;
}

// ── Helper: fetch userinfo from Google API (fallback) ──
function ga_fetch_userinfo(string $accessToken): ?array {
    if (empty($accessToken)) return null;

    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 200 || !$response) return null;
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

// ── Admin endpoints (auth required) ──
require_once SITE_ROOT . '/admin/auth.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

switch ($action) {
    case 'get_config':
        $cfg = ga_load_config();
        // Mask the client secret for display
        if (!empty($cfg['client_secret'])) {
            $cfg['client_secret_masked'] = substr($cfg['client_secret'], 0, 8) . '****';
        }
        $cfg['redirect_uri'] = ga_get_redirect_uri();
        ga_json(['ok' => true, 'config' => $cfg]);
        break;

    case 'save_config':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            ga_json(['ok' => false, 'error' => 'Invalid JSON input.'], 400);
        }

        $current = ga_load_config();
        $allowed = ['enabled', 'client_id', 'client_secret', 'allowed_domains', 'auto_create_users', 'default_role'];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                // Don't overwrite secret with masked value
                if ($key === 'client_secret' && str_contains($input[$key], '****')) {
                    continue;
                }
                $current[$key] = $input[$key];
            }
        }

        if (ga_save_config($current)) {
            $current['redirect_uri'] = ga_get_redirect_uri();
            if (!empty($current['client_secret'])) {
                $current['client_secret_masked'] = substr($current['client_secret'], 0, 8) . '****';
            }
            ga_json(['ok' => true, 'config' => $current]);
        } else {
            ga_json(['ok' => false, 'error' => 'Failed to save config.'], 500);
        }
        break;

    case 'test_config':
        $cfg = ga_load_config();
        $issues = [];
        if (empty($cfg['client_id'])) $issues[] = 'Client ID is empty';
        if (empty($cfg['client_secret'])) $issues[] = 'Client Secret is empty';
        if (empty($cfg['enabled'])) $issues[] = 'Google Auth is disabled';

        // Quick check: does Google respond to our client ID?
        if (empty($issues)) {
            $testUrl = 'https://accounts.google.com/o/oauth2/v2/auth?client_id=' . urlencode($cfg['client_id']) . '&response_type=code&scope=openid&redirect_uri=' . urlencode(ga_get_redirect_uri());
            $ch = curl_init($testUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // Google returns 302 for valid client IDs, 400 for invalid
            if ($httpCode === 400) {
                $issues[] = 'Google rejected the Client ID — check it in Google Cloud Console';
            }
        }

        ga_json([
            'ok'     => empty($issues),
            'issues' => $issues,
            'redirect_uri' => ga_get_redirect_uri(),
        ]);
        break;

    default:
        ga_json(['ok' => false, 'error' => "Unknown action: {$action}"], 400);
}
