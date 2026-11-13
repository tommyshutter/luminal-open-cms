<?php
/**
 * Luminal CMS - Unseal key (CLI only)
 * @file /admin/scripts/unseal.php
 *
 * A brand-new site has no admin account and is SEALED: /admin/ asks for an unseal key
 * and accepts nothing else. This prints that key. It does not expire and works
 * once; running this again replaces an unused key. See admin/includes/seal.php.
 *
 * Usage (from the site's folder, or pass the folder):
 *   php admin/scripts/unseal.php
 *   php admin/scripts/unseal.php /var/www/vhosts/example.com
 *
 * No shell on your hosting? You do not need this — see the sealed /admin/ page for the
 * file-proof way (admin/data/UNSEAL.txt).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$root = $argv[1] ?? dirname(__DIR__, 2);
$root = realpath($root) ?: $root;
if (!is_dir("$root/admin/data")) {
    fwrite(STDERR, "Not a Luminal site folder (no admin/data): $root\n");
    exit(2);
}
define('SITE_ROOT', $root);
require_once __DIR__ . '/../includes/seal.php';

// Read users.json directly — auth.php starts sessions and is built for web requests.
$users = json_decode((string)@file_get_contents("$root/admin/data/users.json"), true);
if (!empty($users['users'])) {
    fwrite(STDERR, "This site already has an admin account, so it is not sealed.\n"
                 . "An unseal key only opens a brand-new site.\n");
    exit(1);
}

try {
    $key = seal_mint('cli');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(3);
}

// Run as root? Hand the key file to whoever owns admin/data (the web server user),
// or the site could never read it.
$keyFile = seal_paths()['key'];
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    @chown($keyFile, fileowner("$root/admin/data"));
    @chgrp($keyFile, filegroup("$root/admin/data"));
}

$host = basename($root);
echo "\n  Unseal key for $host\n\n";
echo "      $key\n\n";
echo "  Open https://$host/admin/ and enter it. "
   . (SEAL_KEY_TTL > 0 ? 'Valid ' . round(SEAL_KEY_TTL / 3600) . ' hours' : 'Does not expire') . ", works once.\n";
echo "  It is shown only now — it is not stored anywhere in readable form.\n\n";
