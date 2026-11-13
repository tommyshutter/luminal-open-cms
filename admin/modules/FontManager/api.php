<?php
/**
 * FontManager — API Endpoint
 *
 * Actions: upload
 *
 * @file /admin/modules/FontManager/api.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
require_once SITE_ROOT . '/admin/modules/_runtime/api_helpers.php';
require_once __DIR__ . '/FontManagerFunctions.php';

guard_require_auth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$action = $_GET['action'] ?? $_POST['action'] ?? 'upload';

/* ---- Actions ---- */

switch ($action) {

    // Self-host a Google Font: fetch once here, serve locally forever.
    // The public site never contacts Google.
    case 'install_google':
        require __DIR__ . '/api/install-google-font.php';
        break;

    case 'upload':
        $TARGET_DIR = SITE_ROOT . '/media/fonts';
        $ALLOWED    = ['ttf', 'otf', 'woff', 'woff2'];
        $MAX_BYTES  = 20 * 1024 * 1024; // 20MB

        // Ensure target dir exists and is writable
        if (!is_dir($TARGET_DIR)) {
            @mkdir($TARGET_DIR, 0755, true);
        }
        if (!is_dir($TARGET_DIR) || !is_writable($TARGET_DIR)) {
            bad('Fonts folder not writable: /media/fonts');
        }

        // POST only
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            bad('POST only', 405);
        }

        // Accept several common field names
        $field = null;
        foreach (['fontfile', 'file', 'upload', 'uploadfile'] as $k) {
            if (!empty($_FILES[$k])) {
                $field = $k;
                break;
            }
        }

        if (!$field) {
            bad('No file was uploaded', 400);
        }

        $f   = $_FILES[$field];
        $err = (int)($f['error'] ?? UPLOAD_ERR_OK);

        if ($err !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE    => 'The uploaded file exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE   => 'The uploaded file exceeds MAX_FILE_SIZE in form',
                UPLOAD_ERR_PARTIAL     => 'The uploaded file was only partially uploaded',
                UPLOAD_ERR_NO_FILE     => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR  => 'Missing a temporary folder (upload_tmp_dir)',
                UPLOAD_ERR_CANT_WRITE  => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION   => 'A PHP extension stopped the upload',
            ];
            bad($map[$err] ?? ('Upload error code ' . $err), 400);
        }

        if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            bad('Upload failed (tmp not found)');
        }

        $orig = $f['name'] ?? 'font';
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        if (!in_array($ext, $ALLOWED, true)) {
            bad('Unsupported extension (ttf, otf, woff, woff2)', 400);
        }

        if (($f['size'] ?? 0) > $MAX_BYTES) {
            bad('File too large (max 20MB)', 400);
        }

        /* --- Duplicate detection by content hash (fast path: size match, then hash) --- */
        $tmpSize = (int)($f['size'] ?? 0);
        $tmpHash = ''; // compute only if needed

        $existingFiles = @scandir($TARGET_DIR) ?: [];
        foreach ($existingFiles as $ef) {
            if ($ef === '.' || $ef === '..') continue;
            $epath = $TARGET_DIR . '/' . $ef;
            if (!is_file($epath)) continue;

            $eext = strtolower(pathinfo($epath, PATHINFO_EXTENSION));
            if (!in_array($eext, $ALLOWED, true)) continue;

            // Quick size check
            $esz = @filesize($epath);
            if ($esz !== false && (int)$esz === $tmpSize) {
                // Compute hashes
                if ($tmpHash === '') {
                    $tmpHash = fm_hash_file($f['tmp_name']);
                }
                $eh = fm_hash_file($epath);

                if ($eh !== '' && $eh === $tmpHash) {
                    // Exact duplicate: do NOT save; return ok with duplicate flag
                    ok([
                        'duplicate' => true,
                        'name'      => $ef,
                        'url'       => '/media/fonts/' . rawurlencode($ef)
                    ]);
                }
            }
        }

        /* --- Not a duplicate: save it --- */
        $base  = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
        $fname = $base . '.' . $ext;
        $dest  = $TARGET_DIR . '/' . $fname;

        // Avoid overwrite if same name (different content)
        if (file_exists($dest)) {
            $stamp = date('Ymd-His');
            $fname = $base . '-' . $stamp . '.' . $ext;
            $dest  = $TARGET_DIR . '/' . $fname;
        }

        // Move (fallback to copy)
        $success = @move_uploaded_file($f['tmp_name'], $dest);
        if (!$success) {
            $success = @copy($f['tmp_name'], $dest);
            if ($success) {
                @unlink($f['tmp_name']);
            }
        }

        if (!$success) {
            bad('Failed to save file (move/copy failed)');
        }

        @chmod($dest, 0644);

        ok([
            'duplicate' => false,
            'name'      => $fname,
            'url'       => '/media/fonts/' . rawurlencode($fname)
        ]);
        break;

    default:
        bad("Unknown action: $action", 400);
}
