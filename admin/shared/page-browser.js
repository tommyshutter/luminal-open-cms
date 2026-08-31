/**
 * Luminal CMS — Page Browser Component
 *
 * Shared modal overlay that lists CMS pages as browsable cards.
 * Any module can include this and call PageBrowser.open(callback).
 *
 * Usage:
 *   PageBrowser.open(function(slug, title, html) { ... });
 *   PageBrowser.fetchPage(slug, function(slug, title, html) { ... });
 *
 * @module  Shared/PageBrowser
 * @version 1.0.0
 */
var PageBrowser = (function () {
  'use strict';

  var API_LIST = '/admin/shared/ajax_page_list.php';
  var API_LOAD = '/admin/modules/PageManager/PageManager.php';

  var overlay = null;
  var onSelectCallback = null;

  function esc(s) {
    return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ── Create modal DOM (once) ─────────────────── */
  function createDOM() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'pb-overlay';
    overlay.style.display = 'none';
    overlay.innerHTML =
      '<div class="pb-modal">' +
        '<div class="pb-header">' +
          '<h3 class="pb-title">Browse Pages</h3>' +
          '<input type="text" class="pb-search" placeholder="Search pages...">' +
          '<button class="pb-close">&times;</button>' +
        '</div>' +
        '<div class="pb-body">' +
          '<div class="pb-loading">Loading pages\u2026</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    // Close on overlay click or X button
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay || e.target.closest('.pb-close')) {
        close();
      }
    });

    // Search filter
    overlay.querySelector('.pb-search').addEventListener('input', function () {
      var q = this.value.toLowerCase();
      var cards = overlay.querySelectorAll('.pb-card');
      for (var i = 0; i < cards.length; i++) {
        var text = (cards[i].dataset.title + ' ' + cards[i].dataset.slug).toLowerCase();
        cards[i].style.display = text.indexOf(q) >= 0 ? '' : 'none';
      }
    });

    // Card button clicks (delegated)
    overlay.querySelector('.pb-body').addEventListener('click', function (e) {
      var loadBtn = e.target.closest('.pb-card-load');
      if (loadBtn) {
        e.stopPropagation();
        doLoadPage(loadBtn.dataset.slug, loadBtn.dataset.title);
        return;
      }
      var previewBtn = e.target.closest('.pb-card-preview');
      if (previewBtn) {
        e.stopPropagation();
        window.open('/' + previewBtn.dataset.slug, '_blank');
        return;
      }
      // Card body click = load
      var card = e.target.closest('.pb-card');
      if (card && !e.target.closest('.pb-card-actions')) {
        doLoadPage(card.dataset.slug, card.dataset.title);
      }
    });

    // Escape to close
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay && overlay.style.display !== 'none') {
        close();
      }
    });
  }

  /* ── Load a page's content and fire callback ── */
  function doLoadPage(slug, title) {
    var body = overlay.querySelector('.pb-body');
    body.innerHTML = '<div class="pb-loading">Loading page content\u2026</div>';

    fetchPage(slug, function (s, t, html) {
      close();
      if (onSelectCallback) {
        onSelectCallback(s, t, html);
      }
    }, function (err) {
      body.innerHTML = '<div class="pb-loading" style="color:#ff6b6b;">Failed to load page: ' + esc(err) + '</div>';
    });
  }

  /* ── Fetch page content (public helper) ──────── */
  function fetchPage(slug, callback, errCallback) {
    fetch(API_LOAD + '?__load&slug=' + encodeURIComponent(slug))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var html = '';
        if (data.components && data.components.main_content) {
          html = data.components.main_content.content || '';
        } else if (data.left_content) {
          html = data.left_content;
        }
        var pageTitle = data.page_title || data.title || slug;
        var pageCss = data._page_css || '';
        var pageJs = data._page_js || '';
        callback(slug, pageTitle, html, pageCss, pageJs);
      })
      .catch(function (err) {
        if (errCallback) errCallback(err.message || 'Unknown error');
      });
  }

  /* ── Render page cards ───────────────────────── */
  function renderCards(pages) {
    var body = overlay.querySelector('.pb-body');
    if (!pages.length) {
      body.innerHTML = '<div class="pb-loading">No pages found.</div>';
      return;
    }
    var html = '<div class="pb-cards">';
    for (var i = 0; i < pages.length; i++) {
      var pg = pages[i];
      html +=
        '<div class="pb-card" data-slug="' + esc(pg.slug) + '" data-title="' + esc(pg.title) + '">' +
          '<div class="pb-card-title">' + esc(pg.title) + '</div>' +
          '<div class="pb-card-slug">/' + esc(pg.slug) + (pg.date ? ' \u00b7 ' + esc(pg.date) : '') + '</div>' +
          (pg.snippet ? '<div class="pb-card-snippet">' + esc(pg.snippet) + '</div>' : '') +
          '<div class="pb-card-actions">' +
            '<button class="pb-card-btn pb-card-load" data-slug="' + esc(pg.slug) + '" data-title="' + esc(pg.title) + '">Load</button>' +
            '<button class="pb-card-btn pb-card-preview" data-slug="' + esc(pg.slug) + '">Preview</button>' +
          '</div>' +
        '</div>';
    }
    html += '</div>';
    body.innerHTML = html;
  }

  /* ── Open the browser modal ──────────────────── */
  function open(onSelect) {
    createDOM();
    onSelectCallback = onSelect;
    overlay.style.display = '';
    overlay.querySelector('.pb-search').value = '';
    overlay.querySelector('.pb-body').innerHTML = '<div class="pb-loading">Loading pages\u2026</div>';

    fetch(API_LIST)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          renderCards(data.pages);
        } else {
          overlay.querySelector('.pb-body').innerHTML =
            '<div class="pb-loading" style="color:#ff6b6b;">Failed to load page list.</div>';
        }
      })
      .catch(function (err) {
        overlay.querySelector('.pb-body').innerHTML =
          '<div class="pb-loading" style="color:#ff6b6b;">Error: ' + esc(err.message) + '</div>';
      });

    setTimeout(function () { overlay.querySelector('.pb-search').focus(); }, 50);
  }

  /* ── Close the modal ─────────────────────────── */
  function close() {
    if (overlay) overlay.style.display = 'none';
  }

  return {
    open: open,
    close: close,
    fetchPage: fetchPage
  };
})();
