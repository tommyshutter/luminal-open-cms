<?php
/**
 * Luminal CMS - Site Seal
 * @file /admin/includes/seal.php
 * @description A site with NO admin account is SEALED. The first admin can only be
 *              created by someone who brings an UNSEAL KEY, and only someone who controls
 *              the server can make one.
 *
 * Before this, an empty site showed "create your
 * superadmin" to whoever arrived first — and scanners arrive within the hour.
 *
 * Two ways to prove you own the install, both shown on the sealed page itself:
 *
 *   1. SHELL (the default; a provisioning script can run it for you)
 *        php admin/scripts/unseal.php
 *      prints a one-time key. Only its HASH is stored (admin/data/.unseal_key).
 *      It does not expire (a new site may sit unclaimed for a while);
 *      it still works once, and minting again replaces an unused key.
 *
 *   2. FILE PROOF (shared hosting with no shell — FTP / cPanel File Manager)
 *      create admin/data/UNSEAL.txt containing a passphrase of 20+ characters,
 *      then type that passphrase at /admin/. Only someone who can write into the
 *      site's files can do that.
 *
 * Absence is the safe state: with neither file present, no key can be right, and the
 * site stays sealed. Breaking the seal deletes both files. admin/data/ is 403 to the web.
 */

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

const SEAL_KEY_TTL        = 0;      // seconds; 0 = never expires
const SEAL_PROOF_MIN_LEN  = 20;
const SEAL_MAX_FAILURES   = 10;     // then a pause, so a weak passphrase cannot be guessed at speed
const SEAL_LOCKOUT_SECS   = 900;
const SEAL_UNSEALED_TTL   = 900;    // how long a correct key keeps the account form open (session)

function seal_paths(): array {
    $d = SITE_ROOT . '/admin/data';
    return [
        'dir'      => $d,
        'key'      => "$d/.unseal_key",
        'proof'    => "$d/UNSEAL.txt",
        'attempts' => "$d/.unseal_attempts",
    ];
}

/** Keys are shown grouped (ABCD-EFGH-…); accept them typed with or without dashes/spaces. */
function seal_normalize_key(string $k): string {
    return strtoupper(preg_replace('/[\s-]+/', '', $k));
}

/** A live (unexpired) shell key record, or null. An expired one is removed.
 *  A record with no expires_at never expires. */
function seal_live_key(): ?array {
    $p = seal_paths()['key'];
    if (!is_file($p)) return null;
    $rec = json_decode((string)@file_get_contents($p), true);
    if (!is_array($rec) || empty($rec['hash'])
        || (!empty($rec['expires_at']) && strtotime($rec['expires_at']) <= time())) {
        @unlink($p);
        return null;
    }
    return $rec;
}

/** The file-proof passphrase, or null if there is no usable UNSEAL.txt. */
function seal_proof_phrase(): ?string {
    $p = seal_paths()['proof'];
    if (!is_file($p)) return null;
    $phrase = trim((string)@file_get_contents($p));
    return strlen($phrase) >= SEAL_PROOF_MIN_LEN ? $phrase : null;
}

/** Is there anything that COULD unseal this site right now? (Used to word the page.) */
function seal_armed(): bool {
    return seal_live_key() !== null || seal_proof_phrase() !== null;
}

/** Why a present UNSEAL.txt is not usable — so the page can say so instead of just "wrong key". */
function seal_proof_problem(): ?string {
    $p = seal_paths()['proof'];
    if (!is_file($p)) return null;
    $len = strlen(trim((string)@file_get_contents($p)));
    return $len < SEAL_PROOF_MIN_LEN
        ? "admin/data/UNSEAL.txt is there but its passphrase is only $len characters; it needs at least " . SEAL_PROOF_MIN_LEN . '.'
        : null;
}

function seal_verify(string $offered): bool {
    $offered = trim($offered);
    if ($offered === '') return false;
    $rec = seal_live_key();
    if ($rec && password_verify(seal_normalize_key($offered), $rec['hash'])) return true;
    $phrase = seal_proof_phrase();
    if ($phrase !== null && hash_equals($phrase, $offered)) return true;
    return false;
}

/** Minutes left on a failed-attempts pause (0 = not paused). */
function seal_locked_minutes(): int {
    $a = json_decode((string)@file_get_contents(seal_paths()['attempts']), true);
    $until = (int)($a['until'] ?? 0);
    return $until > time() ? (int)ceil(($until - time()) / 60) : 0;
}

function seal_record_failure(): void {
    $p = seal_paths()['attempts'];
    $a = json_decode((string)@file_get_contents($p), true) ?: [];
    $a['count'] = (int)($a['count'] ?? 0) + 1;
    if ($a['count'] >= SEAL_MAX_FAILURES) {
        $a = ['count' => 0, 'until' => time() + SEAL_LOCKOUT_SECS];
    }
    @file_put_contents($p, json_encode($a));
}

/** The seal is broken: remove every way back in. */
function seal_consume(): void {
    foreach (['key', 'proof', 'attempts'] as $k) {
        @unlink(seal_paths()[$k]);
    }
}

/**
 * Mint a fresh shell key (replacing any unused one). Returns the key in plain text —
 * the ONLY time it exists anywhere; the file keeps its hash.
 */
function seal_mint(string $source = 'cli'): string {
    $alphabet = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';   // no 0/O, 1/I/L, U — easy to read aloud and retype
    $raw = '';
    for ($i = 0; $i < 28; $i++) {                    // 28 chars from 30 symbols ≈ 137 bits
        $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $rec = [
        'hash'       => password_hash($raw, PASSWORD_DEFAULT),
        'created_at' => date('c'),
        'expires_at' => SEAL_KEY_TTL > 0 ? date('c', time() + SEAL_KEY_TTL) : null,
        'source'     => $source,
    ];
    $p = seal_paths()['key'];
    if (file_put_contents($p, json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        throw new RuntimeException("Could not write $p");
    }
    @chmod($p, 0640);
    return implode('-', str_split($raw, 4));
}
