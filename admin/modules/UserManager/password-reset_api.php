<?php
/**
 * Password Reset API — Self-service email-based reset
 * @file /admin/user-mgr/password-reset_api.php
 *
 * No auth required (user is locked out).
 *
 * Actions:
 *   request  — generate token, send reset email via Mailgun
 *   validate — check if a token is valid and not expired
 *   reset    — set new password using valid token
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function get_json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

require_once __DIR__ . '/../../includes/mail.php';

/*
 * load_mailgun_config() and send_mailgun_email() lived here until 2026-08-15. Both were
 * dead: send_mailgun_email() was never called after this module moved to
 * luminal_send_mail(), and load_mailgun_config()'s only caller assigned the result to a
 * variable that was never read. Transport selection belongs to admin/includes/mail.php.
 */

/**
 * Load Telegram bot config for reset delivery. Returns null if unavailable.
 * Reads the site-local Telegram config.
 * Requires bot_token + admin_chat_id.
 */
function load_telegram_config(): ?array {
    $candidates = [
        SITE_ROOT . '/admin/data/telegram/config.json',
    ];
    foreach ($candidates as $file) {
        if (!is_file($file)) continue;
        $cfg = json_decode((string)file_get_contents($file), true);
        if (is_array($cfg) && !empty($cfg['bot_token']) && !empty($cfg['admin_chat_id'])) {
            return $cfg;
        }
    }
    return null;
}

/**
 * Deliver a reset link to the site administrator's Telegram chat.
 * The link ONLY ever goes to the configured admin_chat_id — it never
 * reaches the requester unless they are the admin, which removes the
 * email-enumeration/interception surface for admin resets. Plain text
 * (no parse_mode) so tokens/URLs never trip Markdown escaping.
 */
function send_telegram_reset(array $tgCfg, string $resetUrl, string $domain, array $user): array {
    $token  = $tgCfg['bot_token'];
    $chatId = $tgCfg['admin_chat_id'];
    $who    = $user['display_name'] ?? $user['username'] ?? $user['email'] ?? 'an admin';
    $text = "🔐 Password reset requested\n"
          . "Site: {$domain}\n"
          . "Account: {$who}\n\n"
          . "Open this link to set a new password (valid 1 hour):\n{$resetUrl}\n\n"
          . "If you didn't request this, ignore this message — nothing changes until the link is used.";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://api.telegram.org/bot{$token}/sendMessage",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'disable_web_page_preview' => 'true',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if ($resp === false) return ['ok' => false, 'error' => "cURL: $err"];
    $data = json_decode($resp, true);
    return ['ok' => ($code >= 200 && $code < 300 && !empty($data['ok'])), 'data' => $data];
}

/**
 * Find a user by token across all users.
 */
function find_user_by_token(string $token): ?array {
    $data = loadUsersData();
    foreach ($data['users'] as $i => $user) {
        if (!empty($user['password_reset_token']) && hash_equals($user['password_reset_token'], $token)) {
            return ['user' => $user, 'index' => $i];
        }
    }
    return null;
}

/**
 * Build the password reset email HTML.
 */
function build_reset_email(string $resetUrl, string $domain): array {
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: 'Helvetica Neue', Arial, sans-serif; background: #f5f5f5; padding: 40px 20px;">
  <div style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
    <h2 style="color: #202124; font-weight: 400; margin: 0 0 16px;">Password Reset</h2>
    <p style="color: #5f6368; font-size: 14px; line-height: 1.6;">
      We received a request to reset your password for <strong>{$domain}</strong>.
      Click the button below to choose a new password.
    </p>
    <div style="text-align: center; margin: 32px 0;">
      <a href="{$resetUrl}" style="display: inline-block; background: #1a73e8; color: #ffffff; text-decoration: none; padding: 12px 32px; border-radius: 4px; font-size: 15px; font-weight: 500;">Reset Password</a>
    </div>
    <p style="color: #5f6368; font-size: 13px; line-height: 1.6;">
      This link is valid for <strong>1 hour</strong>. If you didn't request this, you can safely ignore this email.
    </p>
    <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 24px 0;">
    <p style="color: #9aa0a6; font-size: 12px;">
      If the button doesn't work, copy and paste this URL into your browser:<br>
      <span style="word-break: break-all;">{$resetUrl}</span>
    </p>
  </div>
</body>
</html>
HTML;

    $text = "Password Reset — {$domain}\n\n"
        . "We received a request to reset your password.\n"
        . "Visit this link to choose a new password (valid for 1 hour):\n\n"
        . "{$resetUrl}\n\n"
        . "If you didn't request this, you can safely ignore this email.\n";

    return ['html' => $html, 'text' => $text];
}

/* ================================================================ */
/*  Route                                                            */
/* ================================================================ */

switch ($action) {

    case 'request':
        $input = get_json_input();
        $email = strtolower(trim($input['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
        }

        // Available delivery channels: Telegram (admin resets) and/or email.
        $tgCfg = load_telegram_config();
        // Email is always deliverable: luminal_send_mail() falls back to the host's own
        // MTA when Mailgun is not configured. This previously bailed out entirely, so a
        // site without Mailgun could never reset an admin password — one forgotten
        // password locked you out of your own CMS permanently.

        // Generic success response (prevents account enumeration)
        $genericOk = ['ok' => true, 'message' => 'If an account with that email exists, a password reset link has been sent.'];

        // Find user
        $user = findUserByIdentifier($email);
        if (!$user || $user['status'] !== 'active') {
            json_out($genericOk);
        }

        // Pick channel: admins → Telegram (link only ever hits the admin chat);
        // everyone else → email. Fall back to email if an admin has no Telegram.
        $isAdmin = in_array(($user['role'] ?? ''), ['superadmin', 'admin'], true);
        $channel = ($tgCfg && $isAdmin) ? 'telegram' : 'email';

        // Rate limit: don't regenerate if token was created < 5 min ago
        if (!empty($user['password_reset_expires'])) {
            $expiresAt = strtotime($user['password_reset_expires']);
            // Token lasts 1 hour; if > 55 min remain, it was created < 5 min ago
            if ($expiresAt && ($expiresAt - time()) > 3300) {
                json_out($genericOk);
            }
        }

        // Generate token
        $token = generateSecureToken(32);
        $expires = date('c', time() + 3600); // 1 hour

        // Save to user record
        $data = loadUsersData();
        foreach ($data['users'] as $i => &$u) {
            if ($u['id'] === $user['id']) {
                $u['password_reset_token'] = $token;
                $u['password_reset_expires'] = $expires;
                break;
            }
        }
        unset($u);
        saveUsersData($data);

        // Build reset URL
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'] ?? basename(SITE_ROOT);
        $resetUrl = "{$scheme}://{$domain}/admin/modules/UserManager/password-reset.php?token={$token}";

        // Deliver via the chosen channel
        if ($channel === 'telegram') {
            $sendResult = send_telegram_reset($tgCfg, $resetUrl, $domain, $user);
        } else {
            $emailContent = build_reset_email($resetUrl, $domain);
            $sendResult = luminal_send_mail(
                $user['email'],
                "Password Reset — {$domain}",
                $emailContent['html'],
                $emailContent['text']
            );
        }

        if (!$sendResult['ok']) {
            // Clear token on send failure
            $data = loadUsersData();
            foreach ($data['users'] as $i => &$u) {
                if ($u['id'] === $user['id']) {
                    $u['password_reset_token'] = null;
                    $u['password_reset_expires'] = null;
                    break;
                }
            }
            unset($u);
            saveUsersData($data);
            json_out(['ok' => false, 'error' => 'Failed to send reset link. Please contact your administrator.'], 500);
        }

        json_out($genericOk);
        break;

    case 'validate':
        $token = trim($_GET['token'] ?? '');
        if (empty($token) || strlen($token) !== 64) {
            json_out(['ok' => true, 'valid' => false, 'reason' => 'Invalid token format.']);
        }

        $result = find_user_by_token($token);
        if (!$result) {
            json_out(['ok' => true, 'valid' => false, 'reason' => 'This reset link is invalid or has already been used.']);
        }

        $user = $result['user'];
        $expiresAt = strtotime($user['password_reset_expires'] ?? '');
        if (!$expiresAt || time() > $expiresAt) {
            json_out(['ok' => true, 'valid' => false, 'reason' => 'This reset link has expired. Please request a new one.']);
        }

        json_out(['ok' => true, 'valid' => true]);
        break;

    case 'reset':
        $input = get_json_input();
        $token    = trim($input['token'] ?? '');
        $password = $input['password'] ?? '';
        $confirm  = $input['confirm'] ?? '';

        if (empty($token) || strlen($token) !== 64) {
            json_out(['ok' => false, 'error' => 'Invalid token.'], 400);
        }
        if (empty($password)) {
            json_out(['ok' => false, 'error' => 'Password is required.'], 400);
        }
        if ($password !== $confirm) {
            json_out(['ok' => false, 'error' => 'Passwords do not match.'], 400);
        }

        // Validate password strength
        $pwdCheck = validatePassword($password);
        if (!empty($pwdCheck)) {
            json_out(['ok' => false, 'error' => implode(' ', $pwdCheck)], 400);
        }

        // Find user by token
        $result = find_user_by_token($token);
        if (!$result) {
            json_out(['ok' => false, 'error' => 'This reset link is invalid or has already been used.'], 400);
        }

        $user = $result['user'];
        $idx  = $result['index'];

        // Check expiry
        $expiresAt = strtotime($user['password_reset_expires'] ?? '');
        if (!$expiresAt || time() > $expiresAt) {
            json_out(['ok' => false, 'error' => 'This reset link has expired. Please request a new one.'], 400);
        }

        // Update password and clear token
        $data = loadUsersData();
        $data['users'][$idx]['password_hash'] = hashPassword($password);
        $data['users'][$idx]['password_reset_token'] = null;
        $data['users'][$idx]['password_reset_expires'] = null;
        $data['users'][$idx]['updated_at'] = date('c');
        saveUsersData($data);

        json_out(['ok' => true, 'message' => 'Password has been reset successfully.']);
        break;

    default:
        json_out(['ok' => false, 'error' => "Unknown action: $action"], 400);
}
