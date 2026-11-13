<?php
/**
 * Luminal CMS — clear one person's authenticator setup so they enrol again at next sign-in.
 * Root / CLI only. Usage (from the site root):
 *   php admin/scripts/mfa-reset.php someone@example.com
 * The person's password is unchanged; at their next sign-in they scan a new QR code.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
define('SITE_ROOT', $root);
$who = strtolower(trim((string)($argv[1] ?? '')));
if ($who === '') { fwrite(STDERR, "usage: php admin/scripts/mfa-reset.php EMAIL\n"); exit(2); }
$f = $root . '/admin/data/users.json';
$data = json_decode((string)file_get_contents($f), true);
if (!is_array($data) || !isset($data['users'])) { fwrite(STDERR, "users.json unreadable\n"); exit(1); }
$hit = 0;
foreach ($data['users'] as $i => $u) {
    if (strtolower((string)($u['email'] ?? '')) === $who || strtolower((string)($u['username'] ?? '')) === $who) {
        unset($data['users'][$i]['mfa']); $hit++;
    }
}
if (!$hit) { fwrite(STDERR, "no user {$who}\n"); exit(1); }
copy($f, $f . '.bak-mfa-reset-' . gmdate('Ymd-His'));
file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
@chown($f, 'www-data'); @chgrp($f, 'www-data');
echo "cleared two-step sign-in for {$who} ({$hit} account)\n";
