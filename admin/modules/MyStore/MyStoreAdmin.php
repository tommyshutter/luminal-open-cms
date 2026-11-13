<?php
/**
 * Luminal CMS — My Store Module (Admin Shell Entry Point)
 *
 * Renders inside the admin panel with nav rail, header, and auth guard.
 *
 * @package    LuminalCMS
 * @module     MyStore
 * @version    3.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) require_once __DIR__ . '/../../config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

// Include PHP logic (product functions, POST handler, settings loader)
require_once __DIR__ . '/includes/mystore-admin-functions.php';

$moduleBase = sc_asset('/admin/modules/MyStore');

// Include Luminal admin header (provides <!DOCTYPE>, <head>, nav rail, content-area div)
require_once SITE_ROOT . '/admin/admin_header.php';
?>

<?php
// Check if store page exists
$_storePageExists = is_file(SITE_ROOT . '/admin/data/pages/my-store/my-store.json');
$_storePageUrl = '/page.php?p=my-store';
?>
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h1 class="panel_header_h1" style="margin:0">My Store</h1>
    <div style="display:flex;gap:6px;align-items:center">
        <?php if ($_storePageExists): ?>
        <a href="<?= $_storePageUrl ?>" target="_blank" style="font-size:0.72rem;padding:5px 12px;border-radius:6px;background:rgba(22,163,74,0.1);border:1px solid rgba(22,163,74,0.2);color:#16a34a;font-weight:700;text-decoration:none">&#128065; View Storefront</a>
        <?php else: ?>
        <button onclick="msCreateStorePage()" style="font-size:0.72rem;padding:5px 12px;border-radius:6px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:#f59e0b;font-weight:700;cursor:pointer;font-family:inherit">&#128171; Create Storefront Page</button>
        <?php endif; ?>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/MyStore/css/mystore-admin.css') ?>">

<?php include __DIR__ . '/views/mystore-admin-content.php'; ?>

<script>
<?php if (empty($_SESSION['ms_csrf'])) $_SESSION['ms_csrf'] = bin2hex(random_bytes(16)); ?>
<?php
    $allOrders = mystore_load_all_orders();
?>
window.PS_BOOT = {
    apiUrl: '<?= $moduleBase ?>/MyStoreAdmin.php',
    storeApiUrl: '/admin/modules/MyStore/api/store-api.php',
    products: <?= json_encode($products) ?>,
    orders: <?= json_encode($allOrders) ?>,
    currentView: '<?= $view ?>',
    settings: <?= json_encode($settings) ?>,
    csrfToken: <?= json_encode($_SESSION['ms_csrf']) ?>
};
</script>
<script>
function msCreateStorePage() {
    var fd = new FormData();
    fd.append('__save', '1');
    fd.append('page_name', 'my-store');
    fd.append('page_title', 'My Store');
    fd.append('left_width', '100');
    fd.append('use_wrapper', '1');
    fd.append('include_bg', '1');
    fd.append('components[main_content][content]', '[[mystore]]');
    fd.append('components[right_column][content]', '');
    fetch('/admin/modules/PageManager/PageManager.php', {method:'POST', body:fd})
    .then(function(r){return r.json()}).then(function(d){
        if (d.ok) {
            location.reload();
        } else {
            alert(d.error || 'Failed to create page');
        }
    });
}
</script>
<script src="<?= sc_asset('/admin/modules/MyStore/js/mystore-admin.js') ?>"></script>
<?php if (!empty($_GET['openSettings'])): ?>
<script>window.addEventListener('load', function(){ if (window.PS && PS.showSettingsModal) PS.showSettingsModal(); });</script>
<?php endif; ?>
