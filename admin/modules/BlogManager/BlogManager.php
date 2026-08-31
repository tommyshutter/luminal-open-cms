<?php
/**
 * Luminal CMS — Blog Manager
 *
 * Admin interface for creating, editing, and publishing blog articles.
 * Articles stored as JSON in admin/data/articles/{slug}.json
 *
 * @package    LuminalCMS
 * @module     BlogManager
 * @version    1.0.0
 * @file       /admin/modules/BlogManager/BlogManager.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
guard_require_auth();

require_once SITE_ROOT . '/admin/admin_header.php';
?>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/BlogManager/css/blog-manager.css') ?>">

<div class="bm-wrap">

    <!-- Header -->
    <div class="bm-header">
        <div class="bm-header__left">
            <h1 class="bm-title">Blog Manager</h1>
            <span class="bm-subtitle" id="bm-article-count">Loading…</span>
        </div>
        <div class="bm-header__right">
            <button class="bm-btn bm-btn--amber" onclick="bmOpenNew()">+ New Article</button>
        </div>
    </div>

    <!-- Article List -->
    <div class="bm-list-wrap" id="bm-list-section">
        <div class="bm-table-header">
            <div class="bm-col bm-col--title">Title</div>
            <div class="bm-col bm-col--cat">Category</div>
            <div class="bm-col bm-col--status">Status</div>
            <div class="bm-col bm-col--date">Date</div>
            <div class="bm-col bm-col--author">Author</div>
            <div class="bm-col bm-col--actions">Actions</div>
        </div>
        <div id="bm-article-list">
            <div class="bm-loading">Loading articles…</div>
        </div>
    </div>

    <!-- Edit / Create Panel -->
    <div class="bm-edit-panel" id="bm-edit-panel" style="display:none">
        <div class="bm-edit-header">
            <h2 class="bm-edit-title" id="bm-edit-title">New Article</h2>
            <button class="bm-btn bm-btn--ghost" onclick="bmCloseEdit()">✕ Cancel</button>
        </div>

        <form id="bm-article-form" onsubmit="bmSaveArticle(event)">
            <input type="hidden" id="bm-orig-slug" name="orig_slug" value="">

            <div class="bm-form-grid">
                <!-- Left column -->
                <div class="bm-form-col">

                    <div class="bm-field">
                        <label class="bm-label">Title <span class="bm-req">*</span></label>
                        <input type="text" id="bm-f-title" name="title" class="bm-input"
                               placeholder="Article title" oninput="bmAutoSlug()" required>
                    </div>

                    <div class="bm-field">
                        <label class="bm-label">Slug</label>
                        <input type="text" id="bm-f-slug" name="slug" class="bm-input bm-input--mono"
                               placeholder="auto-generated-from-title">
                        <span class="bm-hint">Leave blank to auto-generate from title</span>
                    </div>

                    <div class="bm-field bm-field--row">
                        <div style="flex:1">
                            <label class="bm-label">Category</label>
                            <input type="text" id="bm-f-category" name="category" class="bm-input"
                                   placeholder="e.g. News" list="bm-cat-list">
                            <datalist id="bm-cat-list"></datalist>
                        </div>
                        <div style="flex:1">
                            <label class="bm-label">Author</label>
                            <input type="text" id="bm-f-author" name="author" class="bm-input"
                                   placeholder="Admin" value="Admin">
                        </div>
                    </div>

                    <div class="bm-field">
                        <label class="bm-label">Tags <span class="bm-hint">(comma-separated)</span></label>
                        <input type="text" id="bm-f-tags" name="tags" class="bm-input"
                               placeholder="tag1, tag2, tag3">
                    </div>

                    <div class="bm-field bm-field--row">
                        <div style="flex:1">
                            <label class="bm-label">Published Date</label>
                            <input type="date" id="bm-f-date" name="published_date" class="bm-input">
                        </div>
                        <div style="flex:1">
                            <label class="bm-label">Read Time</label>
                            <input type="text" id="bm-f-readtime" name="read_time" class="bm-input"
                                   placeholder="3 min read" value="3 min read">
                        </div>
                    </div>

                    <div class="bm-field">
                        <label class="bm-label">Hero Image URL</label>
                        <input type="url" id="bm-f-hero" name="hero_image_url" class="bm-input"
                               placeholder="https://…/image.jpg">
                    </div>

                    <div class="bm-field">
                        <label class="bm-label">Meta Description</label>
                        <textarea id="bm-f-metadesc" name="meta_description" class="bm-textarea bm-textarea--sm"
                                  rows="3" placeholder="Short description for SEO and article cards"></textarea>
                    </div>

                    <div class="bm-field bm-field--check">
                        <label class="bm-check-label">
                            <input type="checkbox" id="bm-f-published" name="is_published" value="1">
                            <span class="bm-check-text">Published (visible on site)</span>
                        </label>
                    </div>

                </div>

                <!-- Right column — Body HTML -->
                <div class="bm-form-col bm-form-col--body">
                    <div class="bm-field bm-field--full">
                        <label class="bm-label">Body HTML</label>
                        <textarea id="bm-f-body" name="body_html" class="bm-textarea bm-textarea--body"
                                  placeholder="<p>Article content as raw HTML...</p>"></textarea>
                    </div>
                </div>

            </div><!-- /grid -->

            <div class="bm-form-actions">
                <button type="submit" class="bm-btn bm-btn--amber" id="bm-save-btn">Save Article</button>
                <button type="button" class="bm-btn bm-btn--ghost" onclick="bmCloseEdit()">Cancel</button>
                <span class="bm-save-status" id="bm-save-status"></span>
            </div>

        </form>
    </div><!-- /edit panel -->

</div><!-- /bm-wrap -->

<script>
(function() {
    'use strict';

    const API = '/admin/modules/BlogManager/api.php';
    let _articles = [];
    let _categories = [];
    let _editingSlug = null;

    // ── Init ─────────────────────────────────────────────────────────────────
    function init() {
        loadCategories();
        loadArticles();
        // Set today as default date
        document.getElementById('bm-f-date').value = new Date().toISOString().slice(0, 10);
    }

    // ── Load articles list ───────────────────────────────────────────────────
    function loadArticles() {
        fetch(API + '?action=list_articles')
            .then(r => r.json())
            .then(d => {
                if (!d.success) { renderError(d.error); return; }
                _articles = d.articles || [];
                renderArticleList(_articles);
                document.getElementById('bm-article-count').textContent =
                    _articles.length + ' article' + (_articles.length !== 1 ? 's' : '');
            })
            .catch(e => renderError(e.message));
    }

    function loadCategories() {
        fetch(API + '?action=list_categories')
            .then(r => r.json())
            .then(d => {
                _categories = d.categories || [];
                const dl = document.getElementById('bm-cat-list');
                dl.innerHTML = '';
                _categories.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c;
                    dl.appendChild(opt);
                });
            });
    }

    // ── Render list ──────────────────────────────────────────────────────────
    function renderArticleList(articles) {
        const el = document.getElementById('bm-article-list');
        if (!articles.length) {
            el.innerHTML = '<div class="bm-empty">No articles yet. Click <strong>+ New Article</strong> to create one.</div>';
            return;
        }
        el.innerHTML = articles.map(a => {
            const statusCls = a.is_published ? 'bm-badge--published' : 'bm-badge--draft';
            const statusTxt = a.is_published ? 'Published' : 'Draft';
            const dateStr   = a.published_date ? fmtDate(a.published_date) : '—';
            return `
            <div class="bm-row" data-slug="${esc(a.slug)}">
                <div class="bm-col bm-col--title">
                    <span class="bm-row-title">${esc(a.title)}</span>
                    <span class="bm-row-slug">${esc(a.slug)}</span>
                </div>
                <div class="bm-col bm-col--cat">${esc(a.category || '—')}</div>
                <div class="bm-col bm-col--status"><span class="bm-badge ${statusCls}">${statusTxt}</span></div>
                <div class="bm-col bm-col--date">${dateStr}</div>
                <div class="bm-col bm-col--author">${esc(a.author || '—')}</div>
                <div class="bm-col bm-col--actions">
                    <button class="bm-btn bm-btn--sm bm-btn--ghost" onclick="window.bmEdit('${esc(a.slug)}')">Edit</button>
                    <button class="bm-btn bm-btn--sm bm-btn--danger" onclick="window.bmDelete('${esc(a.slug)}', this)">Delete</button>
                </div>
            </div>`;
        }).join('');
    }

    function renderError(msg) {
        document.getElementById('bm-article-list').innerHTML =
            '<div class="bm-error">Error: ' + esc(msg) + '</div>';
    }

    // ── Open new article ─────────────────────────────────────────────────────
    window.bmOpenNew = function() {
        _editingSlug = null;
        document.getElementById('bm-edit-title').textContent = 'New Article';
        document.getElementById('bm-article-form').reset();
        document.getElementById('bm-f-date').value = new Date().toISOString().slice(0, 10);
        document.getElementById('bm-f-author').value = 'Admin';
        document.getElementById('bm-f-readtime').value = '3 min read';
        document.getElementById('bm-orig-slug').value = '';
        document.getElementById('bm-save-status').textContent = '';
        showPanel();
    };

    // ── Edit existing ────────────────────────────────────────────────────────
    window.bmEdit = function(slug) {
        _editingSlug = slug;
        document.getElementById('bm-save-status').textContent = 'Loading…';
        showPanel();
        fetch(API + '?action=get_article&slug=' + encodeURIComponent(slug))
            .then(r => r.json())
            .then(d => {
                if (!d.success) { alert('Error: ' + d.error); return; }
                const a = d.article;
                document.getElementById('bm-edit-title').textContent = 'Edit: ' + a.title;
                document.getElementById('bm-f-title').value       = a.title || '';
                document.getElementById('bm-f-slug').value        = a.slug || '';
                document.getElementById('bm-f-category').value    = a.category || '';
                document.getElementById('bm-f-tags').value        = (a.tags || []).join(', ');
                document.getElementById('bm-f-author').value      = a.author || '';
                document.getElementById('bm-f-date').value        = a.published_date || '';
                document.getElementById('bm-f-readtime').value    = a.read_time || '3 min read';
                document.getElementById('bm-f-hero').value        = a.hero_image_url || '';
                document.getElementById('bm-f-metadesc').value    = a.meta_description || '';
                document.getElementById('bm-f-body').value        = a.body_html || '';
                document.getElementById('bm-f-published').checked = !!a.is_published;
                document.getElementById('bm-orig-slug').value     = a.slug || '';
                document.getElementById('bm-save-status').textContent = '';
            })
            .catch(e => { document.getElementById('bm-save-status').textContent = 'Load error: ' + e.message; });
    };

    // ── Save ─────────────────────────────────────────────────────────────────
    window.bmSaveArticle = function(e) {
        e.preventDefault();
        const statusEl = document.getElementById('bm-save-status');
        const saveBtn  = document.getElementById('bm-save-btn');
        statusEl.textContent = 'Saving…';
        saveBtn.disabled = true;

        const form = document.getElementById('bm-article-form');
        const payload = {
            action:           'save_article',
            title:            form.title.value.trim(),
            slug:             form.slug.value.trim(),
            category:         form.category.value.trim(),
            tags:             form.tags.value,
            author:           form.author.value.trim(),
            published_date:   form.published_date.value,
            read_time:        form.read_time.value.trim(),
            hero_image_url:   form.hero_image_url.value.trim(),
            meta_description: form.meta_description.value.trim(),
            body_html:        form.body_html.value,
            is_published:     form.is_published.checked,
        };

        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(d => {
            saveBtn.disabled = false;
            if (!d.success) {
                statusEl.textContent = '✗ ' + (d.error || 'Unknown error');
                statusEl.className = 'bm-save-status bm-save-status--error';
                return;
            }
            statusEl.textContent = '✓ Saved';
            statusEl.className = 'bm-save-status bm-save-status--ok';
            loadArticles();
            loadCategories();
            setTimeout(() => {
                statusEl.textContent = '';
                statusEl.className = 'bm-save-status';
            }, 2500);
        })
        .catch(err => {
            saveBtn.disabled = false;
            statusEl.textContent = '✗ ' + err.message;
            statusEl.className = 'bm-save-status bm-save-status--error';
        });
    };

    // ── Delete ───────────────────────────────────────────────────────────────
    window.bmDelete = function(slug, btn) {
        const row = btn.closest('.bm-row');
        if (btn.dataset.confirm === '1') {
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_article', slug }),
            })
            .then(r => r.json())
            .then(d => {
                if (!d.success) { alert('Error: ' + d.error); return; }
                row.style.opacity = '0';
                row.style.transition = 'opacity 0.3s';
                setTimeout(() => { loadArticles(); }, 320);
            })
            .catch(e => alert('Error: ' + e.message));
        } else {
            btn.textContent = 'Confirm?';
            btn.dataset.confirm = '1';
            btn.classList.add('bm-btn--danger-confirm');
            setTimeout(() => {
                if (btn.dataset.confirm === '1') {
                    btn.textContent = 'Delete';
                    btn.dataset.confirm = '0';
                    btn.classList.remove('bm-btn--danger-confirm');
                }
            }, 3000);
        }
    };

    // ── Auto-slug from title ─────────────────────────────────────────────────
    window.bmAutoSlug = function() {
        if (_editingSlug !== null) return; // Don't change slug when editing
        const title = document.getElementById('bm-f-title').value;
        const slug  = title.toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/[\s-]+/g, '-')
            .replace(/^-+|-+$/g, '');
        document.getElementById('bm-f-slug').value = slug;
    };

    // ── Panel show/hide ──────────────────────────────────────────────────────
    function showPanel() {
        document.getElementById('bm-edit-panel').style.display = 'block';
        document.getElementById('bm-edit-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    window.bmCloseEdit = function() {
        document.getElementById('bm-edit-panel').style.display = 'none';
        _editingSlug = null;
    };

    // ── Helpers ──────────────────────────────────────────────────────────────
    function esc(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function fmtDate(str) {
        if (!str) return '—';
        const d = new Date(str + 'T00:00:00');
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    // Boot
    document.addEventListener('DOMContentLoaded', init);
})();
</script>

<?php require_once SITE_ROOT . '/admin/admin_footer.php'; ?>
