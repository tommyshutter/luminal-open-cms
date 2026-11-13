<?php
/**
 * Install a Google Font — self-hosted, never hotlinked.
 * @file /admin/modules/FontManager/api/install-google-font.php
 *
 * Fetches a family's @font-face CSS from Google, downloads the font files to
 * /media/fonts, rewrites every url() to the local copy, and appends the blocks
 * to /css/custom-fonts.css.
 *
 * The point is that the PUBLIC site never talks to Google. Hotlinking
 * fonts.googleapis.com puts a third-party request on every page view and has
 * been found to be a data-protection problem in the EU. This fetches once, at
 * install time, from an authenticated admin request only.
 *
 * POST: family=Jost  [weights=400,500,600,700]  [italics=1]
 * Returns: { ok:1, family, files:[...], blocks:N, skipped:N, errors:[...] }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../_runtime/guard.php';
guard_require_auth();
require_once __DIR__ . '/../lib/user_fonts.php';

header('Content-Type: application/json; charset=utf-8');

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(dirname(__DIR__, 4)) ?: dirname(__DIR__, 4));
}
$FONTS_DIR = SITE_ROOT . '/media/fonts';
$CSS_FILE  = SITE_ROOT . '/css/custom-fonts.css';

/** Only these hosts are ever contacted. Not configurable — this is the SSRF guard. */
const GF_CSS_HOST   = 'fonts.googleapis.com';
const GF_ASSET_HOST = 'fonts.gstatic.com';

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
}

$family = trim((string)($_POST['family'] ?? $_GET['family'] ?? ''));
// A family name, nothing else. Blocks path traversal and URL injection into the query.
if ($family === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 ]{0,49}$/', $family)) {
    out(['ok' => 0, 'error' => 'Invalid family name. Letters, digits and spaces only.'], 400);
}

$weights = preg_replace('/[^0-9,]/', '', (string)($_POST['weights'] ?? '400,500,600,700'));
$weights = array_values(array_filter(array_unique(explode(',', $weights)), static fn($w) =>
    $w !== '' && (int)$w >= 100 && (int)$w <= 900));
if (!$weights) $weights = ['400', '700'];
sort($weights, SORT_NUMERIC);
$italics = !empty($_POST['italics']);

// Build the css2 request. ital,wght needs pairs in ascending axis order.
if ($italics) {
    $spec = [];
    foreach ($weights as $w) $spec[] = "0,$w";
    foreach ($weights as $w) $spec[] = "1,$w";
    $axis = 'ital,wght@' . implode(';', $spec);
} else {
    $axis = 'wght@' . implode(';', $weights);
}
$cssUrl = 'https://' . GF_CSS_HOST . '/css2?family=' . rawurlencode($family) . ':' . $axis . '&display=swap';

/** A modern UA is required, or Google serves legacy ttf instead of woff2. */
function gf_fetch(string $url, bool $binary = false): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $binary ? [] : ['Accept: text/css,*/*;q=0.1'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200 && $body !== '') ? (string)$body : null;
}

$css = gf_fetch($cssUrl);
if ($css === null) {
    out(['ok' => 0, 'error' => "Could not fetch \"$family\" from Google Fonts. Check the name and the server's outbound access."], 502);
}

if (!is_dir($FONTS_DIR) && !@mkdir($FONTS_DIR, 0775, true) && !is_dir($FONTS_DIR)) {
    out(['ok' => 0, 'error' => 'Could not create /media/fonts'], 500);
}

$slug     = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $family));
$existing = is_file($CSS_FILE) ? (@file_get_contents($CSS_FILE) ?: '') : '';
$files = []; $errors = []; $blocks = []; $skipped = 0;

// Walk each @font-face block, localise its url()s.
preg_match_all('/@font-face\s*\{[^}]*\}/i', $css, $m);
foreach ($m[0] as $block) {
    if (!preg_match_all('#url\((https://' . preg_quote(GF_ASSET_HOST, '#') . '/[^)]+)\)#i', $block, $u)) {
        continue;
    }
    $localBlock = $block;
    foreach ($u[1] as $assetUrl) {
        // Re-verify the host after parsing; never trust the regex alone.
        $host = parse_url($assetUrl, PHP_URL_HOST);
        if ($host !== GF_ASSET_HOST) { $errors[] = "Refused non-Google asset host: $host"; continue 2; }

        $ext  = strtolower(pathinfo(parse_url($assetUrl, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'woff2';
        if (!in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)) { $errors[] = "Unexpected font type: $ext"; continue 2; }

        $name  = $slug . '-' . substr(sha1($assetUrl), 0, 10) . '.' . $ext;
        $dest  = $FONTS_DIR . '/' . $name;
        $public = '/media/fonts/' . $name;

        if (!is_file($dest)) {
            $bin = gf_fetch($assetUrl, true);
            if ($bin === null) { $errors[] = "Download failed: $name"; continue 2; }
            if (@file_put_contents($dest, $bin) === false) { $errors[] = "Could not write $name"; continue 2; }
            @chmod($dest, 0664);
            $files[] = $public;
            // Operator-chosen font: record it so no sweep can remove it.
            fm_user_fonts_record($name, $family, 'google');
        }
        $localBlock = str_replace($assetUrl, $public, $localBlock);
    }
    // Skip blocks already registered (same local url already declared).
    if (preg_match('#url\(([^)]+)\)#', $localBlock, $lu)
        && strpos($existing, trim($lu[1], "'\" ")) !== false) { $skipped++; continue; }
    $blocks[] = $localBlock;
}

// Everything already present is a success, not a failure — re-installing a font
// the operator already has must not read as an error.
if (!$blocks && !$files && $skipped > 0) {
    out(['ok' => 1, 'family' => $family, 'already_installed' => true, 'blocks' => 0,
         'skipped' => $skipped, 'files' => [], 'errors' => $errors]);
}
if (!$blocks && !$files) {
    out(['ok' => 0, 'error' => "No usable @font-face blocks returned for \"$family\".", 'errors' => $errors], 502);
}

if ($blocks) {
    $header = "\n/* {$family} — installed from Google Fonts, self-hosted. SIL Open Font License. */\n";
    if (@file_put_contents($CSS_FILE, $existing . $header . implode("\n", $blocks) . "\n", LOCK_EX) === false) {
        out(['ok' => 0, 'error' => 'Fonts downloaded but custom-fonts.css could not be written.', 'files' => $files], 500);
    }
    @chmod($CSS_FILE, 0664);
}

out([
    'ok' => 1, 'family' => $family, 'weights' => $weights, 'italics' => $italics,
    'files' => $files, 'blocks' => count($blocks), 'skipped' => $skipped, 'errors' => $errors,
]);
