<?php
/**
 * Luminal CMS — Font Manager Module
 *
 * Custom font upload and management for web typography. Supports
 * TTF, OTF, WOFF, and WOFF2 formats with duplicate detection.
 *
 * @package    LuminalCMS
 * @module     FontManager
 * @version    1.0.0
 * @file       /admin/modules/FontManager/FontManager.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
require_once __DIR__ . '/FontManagerFunctions.php';

guard_require_auth();

$FONTS_DIR = SITE_ROOT . '/media/fonts';
$fonts = fm_get_fonts($FONTS_DIR);

// Include admin header
require_once SITE_ROOT . '/admin/admin_header.php';
?>

<h1 class="panel_header_h1" style="color:white">Font Manager</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/FontManager/css/font-manager.css') ?>">

<style id="font-face-registry">
<?php foreach ($fonts as $fname):
    $family = fm_safe_family($fname);
    $ext    = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    $url    = '/media/fonts/' . rawurlencode($fname);
    $fmt    = fm_src_format($ext);
?>
@font-face {
    font-family: '<?= $family ?>';
    src: url('<?= $url ?>') <?= $fmt ?>;
    font-display: swap;
}
<?php endforeach; ?>
</style>

<div class="content-area font-manager-grid">
    <div class="font-list-panel">
        <h2>Installed Fonts</h2>
        <div class="font-list-scroll">
            <?php if (empty($fonts)): ?>
                <p class="empty-text">No fonts found in <code>/media/fonts</code>.</p>
            <?php else: ?>
                <ul class="font-list">
                    <?php foreach ($fonts as $fname): ?>
                        <?php
                        $family = htmlspecialchars(fm_safe_family($fname), ENT_QUOTES);
                        $safe   = htmlspecialchars($fname, ENT_QUOTES);
                        ?>
                        <li data-file="<?= $safe ?>" style="font-family:'<?= $family ?>', system-ui, sans-serif;"><?= $safe ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="font-upload-panel">
        <h2>Install a Google Font</h2>
        <p class="fm-hint">Downloads the font to this site and serves it from
           <code>/media/fonts</code>. Your visitors never contact Google, and the font is
           yours to keep &mdash; Google Fonts are open-licensed.</p>
        <div class="fm-gf-row">
            <input type="text" id="gf-family" class="fm-input" placeholder="Font name, e.g. Jost"
                   autocomplete="off" spellcheck="false">
            <label class="fm-gf-italics"><input type="checkbox" id="gf-italics"> italics</label>
            <button type="button" id="gf-install" class="fm-btn">Install Font</button>
        </div>
        <div id="gf-status" class="fm-status"></div>
    </div>

    <div class="font-upload-panel">
        <h2>Add New Font</h2>
        <form id="font-upload-form" class="drop-zone" method="post" enctype="multipart/form-data">
            <input type="file" name="fontfile" id="fontfile" accept=".ttf,.otf,.woff,.woff2" hidden>
            <label for="fontfile" class="drop-zone-label">Drag & Drop or Click to Upload Font</label>
        </form>
        <div id="upload-status"></div>
    </div>
</div>

<script src="<?= sc_asset('/admin/modules/FontManager/js/font-manager.js') ?>"></script>
