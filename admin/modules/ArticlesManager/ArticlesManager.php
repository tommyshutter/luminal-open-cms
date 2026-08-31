<?php
/**
 * ArticlesManager — Admin Dashboard
 *
 * Card grid of all articles on the site with search, filter, and
 * click-to-edit CRUD. Single source of truth for every article
 * producer and reader.
 *
 * @module  ArticlesManager
 * @version 1.0.0
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    require_once __DIR__ . '/../../config/site_config.php';
}
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

require_once __DIR__ . '/lib/ArticleStore.php';

$moduleBase = sc_asset('/admin/modules/ArticlesManager');
$store      = new \ArticlesManager\ArticleStore(SITE_ROOT);
$stats      = $store->stats();
$storeEmpty = ($stats['total'] === 0);

require_once SITE_ROOT . '/admin/admin_header.php';
?>
<h1 class="panel_header_h1">📰 Articles Manager</h1>
<p class="panel_sub">Canonical store for every article on this site. Edit, publish, pin, delete. Pipelines and shortcodes both read from here.</p>

<link rel="stylesheet" href="<?= htmlspecialchars($moduleBase) ?>/css/articles-manager.css?v=<?= @filemtime(__DIR__ . '/css/articles-manager.css') ?: time() ?>">

<div class="am-wrap">

  <div class="am-stats">
    <div class="am-stat"><span class="am-stat-value" id="amStatTotal"><?= (int)$stats['total'] ?></span><span class="am-stat-label">Articles</span></div>
    <div class="am-stat"><span class="am-stat-value" id="amStatPublished"><?= (int)$stats['published'] ?></span><span class="am-stat-label">Published</span></div>
    <div class="am-stat"><span class="am-stat-value" id="amStatDraft"><?= (int)$stats['draft'] ?></span><span class="am-stat-label">Drafts</span></div>
    <div class="am-stat"><span class="am-stat-value" id="amStatPinned"><?= (int)$stats['pinned'] ?></span><span class="am-stat-label">Pinned</span></div>
  </div>

  <div class="am-toolbar">
    <input type="search" class="am-search" id="amSearch" placeholder="Search title, eyebrow, body, tags…">
    <select class="am-select" id="amFilterStatus">
      <option value="">All statuses</option>
      <option value="published">Published</option>
      <option value="draft">Draft</option>
    </select>
    <button class="am-btn am-btn--accent" id="amNewBtn">+ New Article</button>
    <button class="am-btn am-btn--ghost" id="amInjectBtn" title="Right-column defaults for ALL articles (Affiliate / UCS)">🧩 Article Defaults</button>

    <?php if ($storeEmpty): ?>
    <button class="am-btn am-btn--warn" id="amMigrateBtn" title="Import existing articles from legacy storage">⬆ Migrate legacy</button>
    <?php endif; ?>
  </div>

  <div class="am-grid-admin" id="amGrid">
    <div class="am-loading">Loading…</div>
  </div>

</div>

<!-- Edit modal -->
<div class="am-modal" id="amModal">
  <div class="am-modal-inner">
    <div class="am-modal-header">
      <h2 id="amModalTitle">Edit Article</h2>
      <button class="am-btn am-btn--ghost" id="amModalClose">✕</button>
    </div>
    <form class="am-form" id="amForm" onsubmit="return false">
      <input type="hidden" name="original_slug" id="amOriginalSlug">
      <label>Title<input type="text" name="title" id="amTitle" required></label>
      <div class="am-row-2">
        <label>Slug<input type="text" name="slug" id="amSlug" required pattern="[a-z0-9-]+"></label>
        <label>Eyebrow<input type="text" name="eyebrow" id="amEyebrow" placeholder="CATEGORY LABEL"></label>
      </div>
      <label>Excerpt<textarea name="excerpt" id="amExcerpt" rows="2"></textarea></label>
      <label>Body HTML<textarea name="body_html" id="amBody" rows="16" class="am-code"></textarea></label>
      <div class="am-row-3">
        <label>Hero image URL<input type="text" name="hero_image" id="amHero" placeholder="/media/images/articles/slug.jpg"></label>
        <label>Category<input type="text" name="category" id="amCategory"></label>
        <label>Tags (comma-separated)<input type="text" name="tags" id="amTags" placeholder="outlander, starz, drama"></label>
      </div>
      <div class="am-row-3">
        <label>Author<input type="text" name="author" id="amAuthor"></label>
        <label class="am-checkbox"><input type="checkbox" name="published" id="amPublished"> Published</label>
        <label class="am-checkbox"><input type="checkbox" name="pinned" id="amPinned"> Pinned</label>
      </div>
      <div class="am-row-1">
        <label class="am-checkbox am-checkbox--wide" title="Only one article at a time can be featured. Enabling this clears it on all others.">
          <input type="checkbox" name="featured_home" id="amFeaturedHome">
          <span>★ Featured on Home Page <small style="opacity:.7;font-weight:normal">— renders as a full-width hero above the article cards on /articles. Only one article can be featured at a time.</small></span>
        </label>
      </div>
      <div class="am-form-actions">
        <button type="button" class="am-btn am-btn--danger" id="amDeleteBtn">Delete</button>
        <div class="am-form-actions-right">
          <button type="button" class="am-btn am-btn--ghost" id="amCancelBtn">Cancel</button>
          <button type="button" class="am-btn am-btn--accent" id="amSaveBtn">Save</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Global article right-column defaults — writes content_injection.article (same config as
     Page Manager → Global Settings → Right-Column Defaults, Articles row). One source of truth. -->
<div class="am-modal" id="amInjectModal">
  <div class="am-modal-inner am-modal-inner--narrow">
    <div class="am-modal-header">
      <h2>🧩 Article Right-Column Defaults</h2>
      <button class="am-btn am-btn--ghost" id="amInjectClose">✕</button>
    </div>
    <div class="am-form">
      <p class="am-universal-help">Applies to <strong>every</strong> article's right column (the site default). Override any single article via its card chips or the editor.</p>
      <label class="am-checkbox am-checkbox--wide"><input type="checkbox" id="amInjAffiliate"><span>🛒 Affiliate placement on all articles</span></label>
      <label class="am-checkbox am-checkbox--wide"><input type="checkbox" id="amInjUcs"><span>🧩 Universal Content Stack on all articles</span></label>
      <div class="am-row-2">
        <label>Columns
          <select class="am-select" id="amInjColumns"><option value="1">1</option><option value="2">2</option><option value="3">3</option></select>
        </label>
        <label>UCS stack
          <select class="am-select" id="amInjUcsSlug"></select>
        </label>
      </div>
      <p class="am-universal-note">Same setting as Page Manager → Global Settings → Right-Column Defaults (Articles row). Edit a stack's contents in Content Stacks.</p>
      <div class="am-form-actions">
        <div></div>
        <div class="am-form-actions-right">
          <button type="button" class="am-btn am-btn--ghost" id="amInjCancel">Cancel</button>
          <button type="button" class="am-btn am-btn--accent" id="amInjSave">Save</button>
        </div>
      </div>
      <div class="am-universal-status" id="amInjStatus"></div>
    </div>
  </div>
</div>

<script src="<?= htmlspecialchars($moduleBase) ?>/js/articles-manager.js?v=<?= @filemtime(__DIR__ . '/js/articles-manager.js') ?: time() ?>"></script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
