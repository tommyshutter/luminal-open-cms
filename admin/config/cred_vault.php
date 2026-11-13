<?php
/**
 * cred_vault.php — Credential Vault crypto core (field-level at-rest encryption).
 *
 * The single crypto core behind the Luminal Credential Vault: encrypts API keys /
 * secrets AT REST so a stolen disk image / config tarball is gibberish. Modeled on
 * CardManager/lib/msg_crypto.php (the house pattern), but for inline string FIELDS
 * (a key inside a config), not whole JSON files.
 *
 * Envelope (inline string):  "ENC:v1:" . base64( nonce[24] || secretbox_ciphertext )
 * Cipher: libsodium sodium_crypto_secretbox (XSalsa20-Poly1305, authenticated).
 *
 * Backward-compatible migration: reveal() returns a NON-enveloped (plaintext) value
 * UNCHANGED. So wrapping a read site with cred_vault_reveal() is a no-op while the
 * value is still plaintext, and starts decrypting the instant the value is sealed.
 * That lets us wire every read path first (zero risk), then seal per-secret with
 * live verification. Rollback = reveal-in-place back to plaintext.
 *
 * Threat model (identical to msg_crypto, stated honestly): protects against server
 * compromise / disk-image leak. Does NOT protect against an attacker who reads BOTH
 * the ciphertext AND the key (admin/data/secrets/credvault.key). Defense-in-depth,
 * not an oracle. The master key is www-data-readable (request-time decrypt), lives
 * outside the webroot's reach (admin/data is .htaccess-denied + not deployed), and
 * may be backed up separately by the operator.
 *
 * @package LuminalCMS
 */
declare(strict_types=1);

if (!defined('CRED_VAULT_PREFIX')) define('CRED_VAULT_PREFIX', 'ENC:v1:');

if (!function_exists('cred_vault_key_path')) {
    function cred_vault_key_path(): string {
        $root = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__, 2);
        return $root . '/admin/data/secrets/credvault.key';
    }
}

if (!function_exists('cred_vault_is_sealed')) {
    /** True if a value is a vault envelope (safe on null / non-strings). */
    function cred_vault_is_sealed($v): bool {
        return is_string($v) && strncmp($v, CRED_VAULT_PREFIX, strlen(CRED_VAULT_PREFIX)) === 0;
    }
}

if (!function_exists('cred_vault_available')) {
    /** Can we decrypt right now? (libsodium present + key file readable.) Never generates. */
    function cred_vault_available(): bool {
        return function_exists('sodium_crypto_secretbox') && is_readable(cred_vault_key_path());
    }
}

if (!function_exists('cred_vault_key')) {
    /**
     * Load the 32-byte master key, generating it on first use (only ever called by
     * seal — reveal on plaintext never reaches here). Key dir is 0700 www-data with a
     * deny-.htaccess; key file 0600 www-data.
     */
    function cred_vault_key(): string {
        static $cached = null;
        if ($cached !== null) return $cached;
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('cred_vault: libsodium unavailable.');
        }
        $path = cred_vault_key_path();
        if (!is_file($path)) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
                @chown($dir, 'www-data'); @chgrp($dir, 'www-data');
            }
            if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            $key = sodium_crypto_secretbox_keygen();
            if (@file_put_contents($path, $key) === false) {
                throw new RuntimeException('cred_vault: cannot write key file ' . $path);
            }
            @chmod($path, 0600); @chown($path, 'www-data'); @chgrp($path, 'www-data');
            $cached = $key;
            return $key;
        }
        $key = (string)@file_get_contents($path);
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('cred_vault: key wrong length (' . strlen($key) . ') — refusing to operate on a broken key.');
        }
        $cached = $key;
        return $key;
    }
}

if (!function_exists('cred_vault_seal')) {
    /** Encrypt a plaintext secret → envelope. Empty → '' (never seal empties). Idempotent. */
    function cred_vault_seal(string $plain): string {
        if ($plain === '') return '';
        if (cred_vault_is_sealed($plain)) return $plain;
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct = sodium_crypto_secretbox($plain, $nonce, cred_vault_key());
        return CRED_VAULT_PREFIX . base64_encode($nonce . $ct);
    }
}

if (!function_exists('cred_vault_reveal')) {
    /**
     * Decrypt an envelope → plaintext. A NON-enveloped value is returned unchanged
     * (plaintext passthrough — the property that makes migration safe). Throws only
     * on a malformed/undecryptable envelope (fail-closed: never returns ciphertext).
     */
    function cred_vault_reveal($v): string {
        if (!is_string($v) || $v === '') return (string)$v;
        if (!cred_vault_is_sealed($v)) return $v;
        $raw = base64_decode(substr($v, strlen(CRED_VAULT_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('cred_vault: malformed envelope.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ct, $nonce, cred_vault_key());
        if ($plain === false) {
            throw new RuntimeException('cred_vault: decryption failed (wrong key or tampered ciphertext).');
        }
        return $plain;
    }
}

if (!function_exists('cred_vault_reveal_deep')) {
    /**
     * Walk a decoded config array and reveal every sealed value in place (plaintext
     * values pass through). Lets a module un-seal its whole config at load with one
     * call, without enumerating which fields are secret. Safe + idempotent.
     */
    function cred_vault_reveal_deep(array $config): array {
        array_walk_recursive($config, function (&$v) {
            if (cred_vault_is_sealed($v)) $v = cred_vault_reveal($v);
        });
        return $config;
    }
}

if (!function_exists('cred_load_json')) {
    /**
     * Read + json_decode + reveal a config file in one call — the drop-in replacement for
     * `json_decode(file_get_contents($f), true)` at any credential-bearing config load site.
     * Missing/unparseable → [] (assoc) so callers with `?: []` behave identically.
     */
    function cred_load_json(string $file): array {
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) return [];
        return cred_vault_reveal_deep($data);
    }
}
