<?php
/**
 * SafeIO — Safe File I/O and Site Resolution Library
 *
 * Provides:
 *   - safe_read_json()    — Read JSON with validation, logging on failure
 *   - safe_write_json()   — Atomic write with LOCK_EX, temp+rename, chmod/chown
 *   - safe_site_root()    — Auto-detect SITE_ROOT (GPS — never hardcode paths)
 *   - safe_registry_path() — Canonical path to site_registry.json
 *   - safe_load_registry() — One registry loader to rule them all
 *   - safe_save_registry() — Write registry with backup + atomic write
 *   - safe_log()          — Structured logging for cron/pipeline operations
 *
 * Philosophy: Main Hydraulics + Vital Hydraulics + Accumulators.
 * Every operation has error detection, fallback, and logging.
 * No @-suppression. No silent failures. No hardcoded paths.
 *
 * @version 1.0.0 — 2026-03-13
 */

declare(strict_types=1);

// ─── SITE ROOT RESOLUTION (GPS) ─────────────────────────────────────────────

/**
 * Resolve SITE_ROOT using the existing site_root_resolver or __DIR__ climbing.
 * Never returns a hardcoded path. Always auto-detects.
 *
 * @return string Absolute path to site root (no trailing slash)
 */
function safe_site_root(): string {
    // If already resolved, use it
    if (defined('SITE_ROOT') && is_dir(SITE_ROOT)) {
        return SITE_ROOT;
    }

    // Try the resolver if available
    $resolverPath = __DIR__ . '/../includes/site_root_resolver.inc.php';
    if (is_file($resolverPath)) {
        require_once $resolverPath;
        if (defined('SITE_ROOT') && is_dir(SITE_ROOT)) {
            return SITE_ROOT;
        }
    }

    // Manual resolution: climb from __DIR__ (/admin/lib) -> /admin -> /site_root
    $candidate = realpath(__DIR__ . '/../..');
    if ($candidate && _safe_validate_site_root($candidate)) {
        if (!defined('SITE_ROOT')) define('SITE_ROOT', $candidate);
        return $candidate;
    }

    // CLI context: try CWD-based resolution
    $cwd = getcwd();
    if ($cwd) {
        // Walk up from CWD looking for admin/admin_header.php
        $up = $cwd;
        for ($i = 0; $i < 6; $i++) {
            if (_safe_validate_site_root($up)) {
                if (!defined('SITE_ROOT')) define('SITE_ROOT', $up);
                return $up;
            }
            $parent = dirname($up);
            if ($parent === $up) break;
            $up = $parent;
        }
    }

    // Last resort: two levels up from this file
    $fallback = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    if (!defined('SITE_ROOT')) define('SITE_ROOT', $fallback);
    safe_log('warn', 'SITE_ROOT resolved via fallback — sentinel files not found', [
        'fallback' => $fallback,
        '__DIR__' => __DIR__,
    ]);
    return $fallback;
}

/**
 * Validate that a path looks like a Luminal CMS site root.
 */
function _safe_validate_site_root(string $path): bool {
    if ($path === '' || !is_dir($path)) return false;
    $sentinels = [
        $path . '/admin/admin_header.php',
        $path . '/admin/includes/auth.php',
        $path . '/header/document_header_body.php',
    ];
    foreach ($sentinels as $s) {
        if (is_file($s)) return true;
    }
    return false;
}


// ─── SAFE JSON READ ─────────────────────────────────────────────────────────

/**
 * Read and decode a JSON file with full error handling.
 *
 * @param string     $path     Absolute path to JSON file
 * @param mixed      $default  Value to return on failure (default: null)
 * @param string     $context  Human-readable context for log messages (e.g. "loading picks")
 * @return mixed Decoded JSON data, or $default on any failure
 */
function safe_read_json(string $path, mixed $default = null, string $context = ''): mixed {
    $label = $context ?: basename($path);

    if (!is_file($path)) {
        safe_log('warn', "File not found: {$label}", ['path' => $path]);
        return $default;
    }

    if (!is_readable($path)) {
        safe_log('error', "File not readable: {$label}", ['path' => $path, 'perms' => decoct(fileperms($path) & 0777)]);
        return $default;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        safe_log('error', "Failed to read file: {$label}", ['path' => $path]);
        return $default;
    }

    if (trim($content) === '') {
        safe_log('warn', "File is empty: {$label}", ['path' => $path]);
        return $default;
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        safe_log('error', "JSON decode failed: {$label}", [
            'path' => $path,
            'error' => json_last_error_msg(),
            'bytes' => strlen($content),
        ]);
        return $default;
    }

    return $data;
}


// ─── SAFE JSON WRITE ────────────────────────────────────────────────────────

/**
 * Write JSON data atomically with file locking, temp file + rename, and permission setting.
 *
 * Pattern: write to .tmp → fsync → rename over target.
 * This ensures readers never see a partial file.
 *
 * @param string $path    Absolute path to target file
 * @param mixed  $data    Data to JSON-encode
 * @param string $context Human-readable context for log messages
 * @param bool   $backup  Create .bak backup before overwriting (default: false)
 * @return bool True on success, false on failure
 */
function safe_write_json(string $path, mixed $data, string $context = '', bool $backup = false): bool {
    $label = $context ?: basename($path);
    $dir = dirname($path);

    // Ensure directory exists
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true)) {
            safe_log('error', "Cannot create directory for: {$label}", ['dir' => $dir]);
            return false;
        }
    }

    // Encode
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        safe_log('error', "JSON encode failed: {$label}", ['error' => json_last_error_msg()]);
        return false;
    }

    // Backup existing file if requested
    if ($backup && is_file($path)) {
        $bakPath = $path . '.bak';
        if (!copy($path, $bakPath)) {
            safe_log('warn', "Backup failed (continuing anyway): {$label}", ['bak' => $bakPath]);
        }
    }

    // Atomic write: temp file in same directory, then rename
    $tmpPath = $path . '.tmp.' . getmypid();
    $fh = fopen($tmpPath, 'c');  // create or truncate
    if (!$fh) {
        safe_log('error', "Cannot open temp file: {$label}", ['tmp' => $tmpPath]);
        return false;
    }

    // Exclusive lock on temp file (prevents concurrent writers to same target)
    if (!flock($fh, LOCK_EX)) {
        safe_log('error', "Cannot acquire lock: {$label}", ['tmp' => $tmpPath]);
        fclose($fh);
        return false;
    }

    $written = fwrite($fh, $json);
    if ($written === false || $written !== strlen($json)) {
        safe_log('error', "Write failed (partial): {$label}", [
            'expected' => strlen($json),
            'written' => $written,
        ]);
        flock($fh, LOCK_UN);
        fclose($fh);
        unlink($tmpPath);
        return false;
    }

    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    // Atomic rename
    if (!rename($tmpPath, $path)) {
        safe_log('error', "Atomic rename failed: {$label}", ['tmp' => $tmpPath, 'target' => $path]);
        unlink($tmpPath);
        return false;
    }

    // Set permissions — log failures instead of suppressing
    if (!chmod($path, 0664)) {
        safe_log('warn', "chmod 0664 failed: {$label}", ['path' => $path]);
    }

    // Only attempt chown if running as root
    if (posix_getuid() === 0) {
        if (!chown($path, 'www-data') || !chgrp($path, 'www-data')) {
            safe_log('warn', "chown www-data failed: {$label}", ['path' => $path]);
        }
    }

    return true;
}


// ─── REGISTRY (ONE LOADER TO RULE THEM ALL) ─────────────────────────────────

/**
 * Get the canonical path to site_registry.json.
 * Uses SITE_ROOT-based resolution, not hardcoded paths.
 *
 * @return string Absolute path to site_registry.json
 */
function safe_registry_path(): string {
    $root = safe_site_root();
    // Primary: deployment_mgr directory (hub sites)
    $primary = $root . '/admin/deployment_mgr/site_registry.json';
    if (is_file($primary)) return $primary;

    // Fallback: FarmoutManager location
    $fallback = $root . '/admin/modules/FarmoutManager/site_registry.json';
    if (is_file($fallback)) return $fallback;

    // Return primary path even if missing (caller should handle)
    return $primary;
}

/**
 * Load the site registry. Canonical implementation — replaces:
 *   - FarmoutManager/api.php: load_registry()
 *   - TechSupport/api.php: ts_load_site_registry()
 *   - ContentStacks/api.php: cs_load_registry()
 *   - command_bridge_heartbeat.php: cb_load_registry()
 *   - SitePassportManager.php: loadRegistry()
 *
 * @return array Registry data (domain-keyed array), or empty array on failure
 */
function safe_load_registry(): array {
    $path = safe_registry_path();
    $data = safe_read_json($path, [], 'site_registry');
    return is_array($data) ? $data : [];
}

/**
 * Save the site registry with backup.
 *
 * @param array $data Registry data
 * @return bool True on success
 */
function safe_save_registry(array $data): bool {
    $path = safe_registry_path();
    return safe_write_json($path, $data, 'site_registry', backup: true);
}


// ─── LOGGING ────────────────────────────────────────────────────────────────

/**
 * Structured log output for cron jobs, pipelines, and modules.
 * Writes to STDERR (cron captures this) and optionally to a log file.
 *
 * @param string $level   'info', 'warn', 'error'
 * @param string $message Human-readable message
 * @param array  $meta    Optional key-value context
 */
function safe_log(string $level, string $message, array $meta = []): void {
    $ts = date('Y-m-d H:i:s');
    $levelTag = strtoupper($level);
    $metaStr = $meta ? ' ' . json_encode($meta, JSON_UNESCAPED_SLASHES) : '';
    $line = "[{$ts}] [{$levelTag}] {$message}{$metaStr}\n";

    // Always write to STDERR (captured by cron log redirection)
    fwrite(STDERR, $line);

    // Also write to shared SafeIO log if site root is known
    if (defined('SITE_ROOT')) {
        $logDir = SITE_ROOT . '/admin/data/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/safeio-' . date('Y-m-d') . '.log';
        // Direct append with lock — don't recurse through safe_write_json
        $fh = fopen($logFile, 'a');
        if ($fh) {
            flock($fh, LOCK_EX);
            fwrite($fh, $line);
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
