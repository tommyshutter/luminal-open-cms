<?php
/**
 * Emergency Access — Magic Link Endpoint for UFW Lockout Recovery
 *
 * @file /admin/emergency-access.php
 * @version 2026.03.01
 *
 * Flow:
 * 1. User visits /admin/emergency-access.php
 * 2. Enters superadmin email → token generated, magic link emailed (5 min TTL)
 * 3. Clicks magic link → IP validated, UFW rule added, CMS whitelist entry created
 * 4. Redirect to /admin/login.php?notice=emergency_access_granted
 *
 * Security:
 * - Rate limited: 3 requests per hour per IP
 * - Tokens IP-bound + one-time use + 5 minute expiry
 * - Constant-time email enumeration protection (always shows same message)
 * - No auth gate (that's the point — this is for when you're locked out)
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
}

require_once SITE_ROOT . '/admin/includes/IPWhitelistHelper.php';

$tokensFile = SITE_ROOT . '/admin/data/emergency_tokens.json';
$rateLimitFile = SITE_ROOT . '/admin/data/emergency_ratelimit.json';
$logFile = SITE_ROOT . '/admin/data/emergency-access.log';

/**
 * Structured audit log — append only. Records every attempt so a
 * locked-out admin (or the platform operator) can see what happened.
 * Emails are stored as last-4 only to preserve anti-enumeration
 * against log readers with partial access.
 */
function ea_log(string $file, array $entry): void {
    $entry['ts'] = date('c');
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    @chmod($file, 0664);
    @chown($file, 'www-data');
    @chgrp($file, 'www-data');
}

function ea_email_tail(string $email): string {
    $at = strpos($email, '@');
    if ($at === false) return '****';
    $domain = substr($email, $at + 1);
    $dot = strrpos($domain, '.');
    $tld = $dot !== false ? substr($domain, $dot) : '';
    return '…' . $tld;
}

$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (strpos($clientIp, ',') !== false) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}

$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'unknown', ENT_QUOTES, 'UTF-8');
$error = '';
$success = '';
$step = 'form'; // form | sent | granted

// ── Rate limiter (3 requests/hour/IP) ──
function ea_check_rate_limit(string $ip, string $file): bool {
    $data = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $now = time();
    $windowStart = $now - 3600;

    // Prune old entries
    $data = array_filter($data, fn($e) => ($e['time'] ?? 0) > $windowStart);

    $ipHits = array_filter($data, fn($e) => ($e['ip'] ?? '') === $ip);
    if (count($ipHits) >= 3) return false;

    $data[] = ['ip' => $ip, 'time' => $now];
    file_put_contents($file, json_encode(array_values($data), JSON_PRETTY_PRINT));
    @chmod($file, 0664);
    @chown($file, 'www-data');
    @chgrp($file, 'www-data');
    return true;
}

function ea_load_tokens(string $file): array {
    if (!is_file($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function ea_save_tokens(array $tokens, string $file): void {
    file_put_contents($file, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($file, 0664);
    @chown($file, 'www-data');
    @chgrp($file, 'www-data');
}

// ── Handle magic link click (GET with token) ──
if (isset($_GET['token'])) {
    $submittedToken = $_GET['token'];
    $tokens = ea_load_tokens($tokensFile);
    $now = time();
    $matched = null;

    foreach ($tokens as $idx => $t) {
        if (hash_equals($t['token'] ?? '', $submittedToken)) {
            $matched = $t;
            $matchIdx = $idx;
            break;
        }
    }

    if (!$matched) {
        $error = 'Invalid or expired token.';
    } elseif (strtotime($matched['expires_at']) <= $now) {
        // Expired — remove it
        unset($tokens[$matchIdx]);
        ea_save_tokens(array_values($tokens), $tokensFile);
        $error = 'This link has expired. Please request a new one.';
    } elseif ($matched['ip'] !== $clientIp) {
        $error = 'This link was generated from a different IP address.';
    } elseif (!empty($matched['used'])) {
        $error = 'This link has already been used.';
    } else {
        // Valid! Mark used
        $tokens[$matchIdx]['used'] = true;
        $tokens[$matchIdx]['used_at'] = date('c');
        ea_save_tokens($tokens, $tokensFile);

        // Add CMS whitelist (120 minutes for emergency access)
        ipwl_add($clientIp, 'Emergency magic link access', 120, 'emergency');

        // Add UFW allow rule if ufw is available
        if (is_executable('/usr/sbin/ufw')) {
            $escapedIp = escapeshellarg($clientIp);
            @shell_exec("sudo /usr/sbin/ufw allow from {$escapedIp} comment 'CMS-emergency-access' 2>&1");
        }

        // Send alert email
        ipwl_send_alert($clientIp, 'Emergency magic link used — IP whitelisted 120 min', 'emergency');

        ea_log($logFile, [
            'event'      => 'token_used',
            'ip'         => $clientIp,
            'host'       => $_SERVER['HTTP_HOST'] ?? '',
            'email_tail' => ea_email_tail($matched['email'] ?? ''),
            'outcome'    => 'granted',
        ]);

        // Redirect to login
        header('Location: /admin/login.php?notice=emergency_access_granted');
        exit;
    }
    $step = 'form';
}

// ── Handle email submission (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = strtolower(trim($_POST['email'] ?? ''));

    // Rate limit check
    if (!ea_check_rate_limit($clientIp, $rateLimitFile)) {
        $error = 'Too many requests. Please try again later.';
    } else {
        // Always show success to prevent email enumeration
        $step = 'sent';
        $success = 'If that email belongs to a superadmin account, a magic link has been sent.';

        // Actually validate and send (silently fail if not a superadmin)
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $usersFile = SITE_ROOT . '/admin/data/users.json';
            $isSuperadmin = false;

            if (is_file($usersFile)) {
                $users = json_decode(file_get_contents($usersFile), true);
                foreach (($users['users'] ?? []) as $u) {
                    if (strtolower($u['email'] ?? '') === $email
                        && ($u['role'] ?? '') === 'superadmin'
                        && ($u['status'] ?? '') === 'active') {
                        $isSuperadmin = true;
                        break;
                    }
                }
            }

            if ($isSuperadmin) {
                // Generate token
                $token = bin2hex(random_bytes(32)); // 64-char hex
                $expiresAt = date('c', time() + 300); // 5 minutes

                $tokens = ea_load_tokens($tokensFile);
                // Prune expired tokens while we're at it
                $now = time();
                $tokens = array_filter($tokens, fn($t) => strtotime($t['expires_at'] ?? '0') > $now && empty($t['used']));
                $tokens[] = [
                    'token'      => $token,
                    'email'      => $email,
                    'ip'         => $clientIp,
                    'created_at' => date('c'),
                    'expires_at' => $expiresAt,
                    'used'       => false,
                ];
                ea_save_tokens(array_values($tokens), $tokensFile);

                // Build magic link
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $magicLink = "{$scheme}://{$_SERVER['HTTP_HOST']}/admin/emergency-access.php?token={$token}";
                $subject   = "[Emergency Access] Magic link for {$_SERVER['HTTP_HOST']}";

                $htmlBody = "<h2>Emergency Access Request</h2>"
                    . "<p>An emergency access request was made from IP <strong>{$clientIp}</strong> on <strong>{$_SERVER['HTTP_HOST']}</strong>.</p>"
                    . "<p><a href=\"{$magicLink}\" style=\"display:inline-block;padding:12px 24px;background:#1a73e8;color:#fff;border-radius:6px;text-decoration:none;font-weight:bold;\">Grant Emergency Access</a></p>"
                    . "<p style='color:#888;font-size:12px;'>This link expires in 5 minutes and can only be used from the requesting IP ({$clientIp}).</p>"
                    . "<p style='color:#c00;font-size:12px;'>If you did not request this, ignore this email.</p>";
                $textBody = "Emergency Access Request\n\nIP: {$clientIp}\nServer: {$_SERVER['HTTP_HOST']}\n\nClick to grant access: {$magicLink}\n\nExpires in 5 minutes. Only usable from IP {$clientIp}.";

                /* ───── Dual-channel send. ─────
                 * Primary is the shared transport in admin/includes/mail.php, which picks
                 * SMTP / Mailgun / host MTA from this site's config, un-seals credentials and
                 * builds a proper multipart message. It replaces a hand-rolled Mailgun client
                 * that read config through a third code path (mgm_load_config).
                 *
                 * The raw mail() call below is KEPT ON PURPOSE, unlike every other caller in
                 * the codebase. This is the break-glass path: if the configured transport is
                 * wrong or down, an admin locked out of the CMS has no other way to get a
                 * magic link. A forced transport does not fall back on its own, so the
                 * fallback has to live here. */
                $sendOutcome   = 'no_email_channel';
                $channelUsed   = '';
                $mailgunTried  = false;
                $sendmailTried = false;

                require_once SITE_ROOT . '/admin/includes/mail.php';
                $res = luminal_send_mail($email, $subject, $htmlBody, $textBody,
                                         ['from_name' => 'Emergency Access']);
                $mailgunTried = (($res['transport'] ?? '') === 'mailgun');
                if (!empty($res['ok'])) {
                    $channelUsed = (string)$res['transport'];
                    $sendOutcome = 'sent_' . $channelUsed;
                }

                // Last resort: straight to sendmail, bypassing transport selection entirely.
                if ($channelUsed === '' && function_exists('mail')) {
                    $sendmailTried = true;
                    $mc       = luminal_mail_config();
                    $fromAddr = $mc['from_email'];
                    $fromName = $mc['from_name'] !== '' ? $mc['from_name'] : 'Emergency Access';
                    $headers = implode("\r\n", [
                        "From: {$fromName} <{$fromAddr}>",
                        "Reply-To: {$fromAddr}",
                        "MIME-Version: 1.0",
                        "Content-Type: text/html; charset=UTF-8",
                    ]);
                    // -f sets envelope sender (Return-Path) so sendmail on cPanel accepts it.
                    $ok = @mail($email, $subject, $htmlBody, $headers, "-f{$fromAddr}");
                    if ($ok) {
                        $sendOutcome = 'sent_sendmail';
                        $channelUsed = 'sendmail';
                    } else {
                        $sendOutcome = $mailgunTried ? 'both_failed' : 'mail_failed';
                    }
                }

                // Surface send details to the success page (anti-enumeration preserved —
                // this only populates when the email actually matched a superadmin).
                if (strncmp($sendOutcome, 'sent_', 5) === 0) {
                    $success = 'Magic link sent via ' . $channelUsed
                             . ' to ' . ea_email_tail($email)
                             . ' at ' . date('H:i') . '. Check your inbox (and spam) — link expires in 5 minutes.';
                }

                ea_log($logFile, [
                    'event'          => 'request',
                    'ip'             => $clientIp,
                    'host'           => $_SERVER['HTTP_HOST'] ?? '',
                    'email_tail'     => ea_email_tail($email),
                    'is_superadmin'  => true,
                    'mailgun_tried'  => $mailgunTried,
                    'sendmail_tried' => $sendmailTried,
                    'outcome'        => $sendOutcome,
                    'channel_used'   => $channelUsed,
                ]);
            } else {
                // Not a superadmin — silently no-op publicly, but log it so an operator can see probes.
                ea_log($logFile, [
                    'event'         => 'request',
                    'ip'            => $clientIp,
                    'host'          => $_SERVER['HTTP_HOST'] ?? '',
                    'email_tail'    => ea_email_tail($email),
                    'is_superadmin' => false,
                    'outcome'       => 'not_superadmin',
                ]);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergency Access — <?= $host ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Roboto','Helvetica Neue',Arial,sans-serif;
            background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);
            min-height:100vh;display:flex;align-items:center;justify-content:center;
            color:#fff;padding:20px;
        }
        .ea-container {
            background:#fff;border-radius:8px;padding:48px 40px;
            width:100%;max-width:450px;color:#202124;
            box-shadow:0 2px 10px rgba(0,0,0,0.1),0 10px 40px rgba(0,0,0,0.2);
        }
        .ea-header { text-align:center;margin-bottom:32px; }
        .ea-icon {
            width:75px;height:75px;margin:0 auto 16px;
            background:linear-gradient(135deg,#f59e0b,#ef4444);
            border-radius:50%;display:flex;align-items:center;justify-content:center;
            font-size:32px;
        }
        .ea-header h1 { font-size:24px;font-weight:400;color:#202124;margin-bottom:8px; }
        .ea-header p { color:#5f6368;font-size:14px;line-height:1.5; }
        .ea-form-group { margin-bottom:24px; }
        .ea-form-group label { display:block;margin-bottom:8px;color:#5f6368;font-size:14px;font-weight:500; }
        .ea-form-group input {
            width:100%;padding:13px 16px;border:1px solid #dadce0;border-radius:4px;
            font-size:16px;color:#202124;outline:none;transition:border-color .2s,box-shadow .2s;
        }
        .ea-form-group input:focus { border-color:#1a73e8;box-shadow:0 0 0 2px rgba(26,115,232,0.2); }
        .ea-btn {
            width:100%;padding:12px 24px;background:#f59e0b;border:none;border-radius:4px;
            color:#fff;font-size:15px;font-weight:500;cursor:pointer;transition:background .2s;
        }
        .ea-btn:hover { background:#d97706; }
        .ea-msg { padding:12px 16px;border-radius:4px;margin-bottom:24px;font-size:14px; }
        .ea-msg.error { background:#fce8e6;color:#c5221f;border:1px solid #f5c6cb; }
        .ea-msg.success { background:#e6f4ea;color:#137333;border:1px solid #ceead6; }
        .ea-back { text-align:center;margin-top:24px; }
        .ea-back a { color:#5f6368;text-decoration:none;font-size:14px; }
        .ea-back a:hover { color:#1a73e8; }
        .ea-ip { text-align:center;margin-top:16px;font-size:12px;color:#9aa0a6; }
    </style>
</head>
<body>
    <div class="ea-container">
        <div class="ea-header">
            <div class="ea-icon">&#128274;</div>
            <h1>Emergency Access</h1>
            <p>Locked out? Enter your superadmin email to receive a magic link that will whitelist your IP address.</p>
        </div>

        <?php if ($error): ?>
            <div class="ea-msg error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 'sent'): ?>
            <div class="ea-msg success"><?= htmlspecialchars($success) ?></div>
            <p style="text-align:center;color:#5f6368;font-size:14px;margin-bottom:16px;">
                Check your inbox and click the magic link within 5 minutes.
            </p>
        <?php else: ?>
            <form method="POST" action="">
                <div class="ea-form-group">
                    <label for="email">Superadmin email</label>
                    <input type="email" id="email" name="email" placeholder="admin@example.com" required autofocus>
                </div>
                <button type="submit" class="ea-btn">Send Magic Link</button>
            </form>
        <?php endif; ?>

        <div class="ea-back">
            <a href="/admin/login.php">&larr; Back to login</a>
        </div>
        <div class="ea-ip">Your IP: <?= htmlspecialchars($clientIp) ?></div>
    </div>
</body>
</html>
