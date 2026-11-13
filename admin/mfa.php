<?php
/**
 * Luminal CMS — the second sign-in step. See admin/includes/mfa.php.
 * Reached only with $_SESSION['mfa_pending'] (set by lum_login_finish / mfa_enforce_session).
 *   first time : scan the QR (or type the key) into an authenticator app, confirm one code,
 *                save ten recovery codes, then signed in
 *   later      : the 6-digit code from the app, or one recovery code
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/session_boot.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/mfa.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$host = $h($_SERVER['HTTP_HOST'] ?? 'Site');

// Already through? Nothing to do here.
if (!empty($_SESSION['user_id']) && ($_SESSION['mfa_ok'] ?? '') === $_SESSION['user_id']) { header('Location: /admin/dashboard.php'); exit; }

$user = mfa_pending_user();
if (!$user) { header('Location: /admin/login.php?error=session_invalid'); exit; }
$pend = $_SESSION['mfa_pending'];

if (empty($_SESSION['mfa_csrf'])) $_SESSION['mfa_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['mfa_csrf'];

$error = ''; $notice = ''; $showCodes = null;
$enrolled = mfa_enrolled($user);
$issuer = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'Luminal'));

// The site's logo, like the sign-in page.
$ss = json_decode((string)@file_get_contents(SITE_ROOT . '/admin/data/site-settings.json'), true) ?: [];
$logo = (!empty($ss['enable_logo']) && !empty($ss['logo_path']) && is_file(SITE_ROOT . '/' . ltrim((string)$ss['logo_path'], '/'))) ? '/' . ltrim((string)$ss['logo_path'], '/') : '';

$finish = function (array $u) use ($pend): void {
    if (!empty($_POST['trust'])) mfa_trust_device($u);
    unset($_SESSION['mfa_enrol_secret'], $_SESSION['mfa_codes'], $_SESSION['mfa_csrf']);
    lum_session_set($u, !empty($pend['remember']));
    $dest = (string)($pend['redirect'] ?? '/admin/dashboard.php');
    if (!str_starts_with($dest, '/') || str_starts_with($dest, '//') || str_contains($dest, '/admin/mfa.php')) $dest = '/admin/dashboard.php';
    header('Location: ' . $dest);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'This page expired. Please try again.';
    } elseif (($_POST['act'] ?? '') === 'enrol' && !$enrolled) {
        $secret = (string)($_SESSION['mfa_enrol_secret'] ?? '');
        $step = $secret !== '' ? mfa_verify_code($secret, (string)($_POST['code'] ?? '')) : null;
        if ($step === null) {
            $error = 'That code did not match. Check the time on your phone, then try the newest code.';
        } else {
            $codes = mfa_new_recovery_codes();
            $saved = mfa_save_enrolment($user['id'], $secret, $codes, $step);
            if (!$saved) { $error = 'Could not save. Please try again.'; }
            else { $_SESSION['mfa_codes'] = $codes; $_SESSION['mfa_trust'] = !empty($_POST['trust']); unset($_SESSION['mfa_enrol_secret']); $user = $saved; $enrolled = true; }
        }
    } elseif (($_POST['act'] ?? '') === 'codes_saved' && $enrolled && !empty($_SESSION['mfa_codes'])) {
        if (!empty($_SESSION['mfa_trust'])) $_POST['trust'] = '1';
        unset($_SESSION['mfa_trust']);
        $finish(findUserById($user['id']) ?? $user);
    } elseif (($_POST['act'] ?? '') === 'verify' && $enrolled) {
        $mins = mfa_locked_minutes($user);
        if ($mins) { $error = "Too many wrong codes. Try again in {$mins} minute(s)."; }
        else {
            $ok = mfa_check($user['id'], (string)($_POST['code'] ?? ''));
            if ($ok !== null) {
                $fresh = findUserById($user['id']) ?? $user;
                if ($ok === 'recovery') $_SESSION['admin_notice_mfa'] = 'You signed in with a recovery code. ' . count($fresh['mfa']['recovery'] ?? []) . ' left.';
                $finish($fresh);
            }
            $user = findUserById($user['id']) ?? $user;
            $mins = mfa_locked_minutes($user);
            $error = $mins ? "Too many wrong codes. Try again in {$mins} minute(s)." : 'That code did not match. Try the newest code, or a recovery code.';
        }
    }
}

$showCodes = (!empty($_SESSION['mfa_codes']) && $enrolled) ? $_SESSION['mfa_codes'] : null;
if (!$enrolled) {
    if (empty($_SESSION['mfa_enrol_secret'])) $_SESSION['mfa_enrol_secret'] = mfa_new_secret();
    $secret = $_SESSION['mfa_enrol_secret'];
    $uri = mfa_otpauth_uri($secret, (string)($user['email'] ?? $user['username'] ?? 'admin'), $issuer);
}
$cssV = (int)@filemtime(__DIR__ . '/css/login-dark.css');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= $host ?> — Two-step sign-in</title>
<link rel="stylesheet" href="/admin/css/login-dark.css?v=<?= $cssV ?>">
</head>
<body class="lum-dark">
<div class="login-container">
  <div class="login-header">
    <a class="site-logo-link" href="/" title="Go to <?= $host ?>" aria-label="Go to the <?= $host ?> home page">
    <?php if ($logo !== ''): ?><div class="site-logo site-logo--img"><img src="<?= $h($logo) ?>" alt="<?= $host ?>"></div>
    <?php else: ?><div class="site-logo"><?= $h(strtoupper(substr($host, 0, 1))) ?></div><?php endif; ?>
    </a>
    <?php if ($showCodes): ?>
      <h1>Save your recovery codes</h1>
      <p>Each one works once, if your phone is not to hand.</p>
    <?php elseif (!$enrolled): ?>
      <h1>Set up two-step sign-in</h1>
      <p>One time only. You will need an authenticator app.</p>
    <?php else: ?>
      <h1>Enter your code</h1>
      <p>From the authenticator app for <?= $host ?></p>
    <?php endif; ?>
  </div>

  <?php if ($error): ?><div class="message error" role="alert"><?= $h($error) ?></div><?php endif; ?>

  <?php if ($showCodes): ?>
    <ol class="mfa-codes" aria-label="Recovery codes"><?php foreach ($showCodes as $c): ?><li><code><?= $h($c) ?></code></li><?php endforeach; ?></ol>
    <div class="mfa-row"><button type="button" class="mfa-ghost" id="mfa-copy">Copy all</button><button type="button" class="mfa-ghost" onclick="window.print()">Print</button></div>
    <form method="post" action="/admin/mfa.php">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="codes_saved">
      <button type="submit" class="login-button">I have saved them — continue</button>
    </form>
  <?php elseif (!$enrolled): ?>
    <ol class="mfa-steps">
      <li>Open an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Authy…) and add an account.</li>
      <li>Scan this code, or type the key below.</li>
    </ol>
    <div class="mfa-qr" id="mfa-qr" data-uri="<?= $h($uri) ?>" aria-label="QR code for your authenticator app"></div>
    <p class="mfa-key">Key: <code><?= $h(trim(chunk_split($secret, 4, ' '))) ?></code></p>
    <form method="post" action="/admin/mfa.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="enrol">
      <div class="form-group">
        <label for="code">3. Type the 6-digit code it shows</label>
        <input id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus>
      </div>
      <label class="remember-me"><input type="checkbox" name="trust" value="1"> Remember this computer for 30 days</label>
      <button type="submit" class="login-button">Turn on two-step sign-in</button>
    </form>
  <?php else: ?>
    <form method="post" action="/admin/mfa.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="verify">
      <div class="form-group">
        <label for="code">6-digit code, or a recovery code</label>
        <input id="code" name="code" maxlength="12" autocomplete="one-time-code" required autofocus>
      </div>
      <label class="remember-me"><input type="checkbox" name="trust" value="1"> Remember this computer for 30 days</label>
      <button type="submit" class="login-button">Sign in</button>
    </form>
  <?php endif; ?>
  <p class="back-link"><a href="/admin/logout.php">Cancel and sign out</a></p>
</div>
<?php if (!$enrolled && !$showCodes): ?>
<script src="/admin/js/vendor/qrcode-generator-1.4.4.js"></script>
<script>
(function () {
  var box = document.getElementById('mfa-qr'); if (!box || typeof qrcode !== 'function') return;
  var q = qrcode(0, 'M'); q.addData(box.getAttribute('data-uri')); q.make();
  box.innerHTML = q.createSvgTag({ cellSize: 5, margin: 3, scalable: true });
})();
</script>
<?php endif; ?>
<?php if ($showCodes): ?>
<script>
document.getElementById('mfa-copy').addEventListener('click', function () {
  var t = Array.prototype.map.call(document.querySelectorAll('.mfa-codes code'), function (c) { return c.textContent; }).join('\n');
  var b = this; navigator.clipboard && navigator.clipboard.writeText(t).then(function () { b.textContent = 'Copied'; });
});
</script>
<?php endif; ?>
</body>
</html>
