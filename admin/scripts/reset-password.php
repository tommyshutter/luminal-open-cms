<?php
/**
 * Luminal CMS - Password reset link (CLI only)
 * @file /admin/scripts/reset-password.php
 *
 * "Forgot password?" sends its link by email or Telegram, so it only works where one of
 * those is set up. This is the way back in that works on every server: whoever controls
 * the server prints the reset link here and opens it. The shell is the proof — the same
 * idea as the unseal key (admin/scripts/unseal.php).
 *
 * Usage (from the site's folder, or pass the folder):
 *   php admin/scripts/reset-password.php                       list the accounts
 *   php admin/scripts/reset-password.php <email-or-username>   print a reset link
 *   php admin/scripts/reset-password.php /var/www/vhosts/example.com <email-or-username>
 *
 * The link opens the normal reset page, where the new password is chosen. It is valid for
 * 1 hour and works once; running this again replaces an unused link. No password is ever
 * typed on the command line.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$args = array_slice($argv, 1);
$root = dirname(__DIR__, 2);
if (isset($args[0]) && is_dir($args[0] . '/admin/data')) {
    $root = array_shift($args);
}
$root  = realpath($root) ?: $root;
$ident = strtolower(trim((string)($args[0] ?? '')));
if (count($args) > 1) {
    fwrite(STDERR, "Too many arguments. Usage: php admin/scripts/reset-password.php [site_root] <email-or-username>\n");
    exit(2);
}
if (!is_dir("$root/admin/data")) {
    fwrite(STDERR, "Not a Luminal site folder (no admin/data): $root\n");
    exit(2);
}

// Read users.json directly — auth.php starts sessions and is built for web requests.
$file = "$root/admin/data/users.json";
$data = json_decode((string)@file_get_contents($file), true);
if (!is_array($data) || empty($data['users'])) {
    fwrite(STDERR, "This site has no accounts, so there is no password to reset.\n"
                 . "A site with no admin account is sealed — see: php admin/scripts/unseal.php\n");
    exit(1);
}

$host = basename($root);

if ($ident === '') {
    echo "\n  Accounts on $host\n\n";
    foreach ($data['users'] as $u) {
        printf("      %-28s %-12s %-10s %s\n", $u['username'] ?? '-', $u['role'] ?? '-',
               $u['status'] ?? '-', $u['email'] ?? '-');
    }
    echo "\n  Run again with the email or username of the account to reset.\n\n";
    exit(0);
}

$idx = null;
foreach ($data['users'] as $i => $u) {
    if (strtolower((string)($u['email'] ?? '')) === $ident || strtolower((string)($u['username'] ?? '')) === $ident) {
        $idx = $i;
        break;
    }
}
if ($idx === null) {
    fwrite(STDERR, "No account on $host matches '$ident'. Run without a name to list them.\n");
    exit(1);
}
if (($data['users'][$idx]['status'] ?? 'active') !== 'active') {
    fwrite(STDERR, "That account is not active (status: " . ($data['users'][$idx]['status'] ?? '?') . ").\n"
                 . "A reset link would not let it sign in. Reactivate it from another admin account first.\n");
    exit(1);
}

// Same token shape and lifetime as the web reset (UserManager/password-reset_api.php).
$token = bin2hex(random_bytes(32));
$data['users'][$idx]['password_reset_token']   = $token;
$data['users'][$idx]['password_reset_expires'] = date('c', time() + 3600);

// Written in place, so users.json keeps its owner and mode even when this runs as root.
if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    fwrite(STDERR, "Could not write $file\n");
    exit(3);
}

$who = $data['users'][$idx]['username'] ?? $data['users'][$idx]['email'];
echo "\n  Password reset link for $who on $host\n\n";
echo "      https://$host/admin/modules/UserManager/password-reset.php?token=$token\n\n";
echo "  Open it and choose a new password. Valid 1 hour, works once.\n";
echo "  If the site answers on another address, keep everything from /admin/ onward.\n\n";
