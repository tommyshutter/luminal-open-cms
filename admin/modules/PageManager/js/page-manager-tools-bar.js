/**
 * @appname     Page Manager — Sticky Tools Bar + Search + Sort
 * @file        SITE-ROOT/admin/modules/PageManager/js/page-manager-tools-bar.js
 * @description Attaches a class to the existing tools region (+New Page / Home Page /
 *              Enable Debug / Minimize), injects Search + Sort controls into the same
 *              bar, and makes it sticky to the top of the viewport while scrolling the
 *              page-card grid. No PHP template edits required.
 *
 *              Filter pipeline:
 *                user types in search  → card title+slug substring match, hide non-matches
 *                user picks sort       → reorder visible cards by title | slug | updated
 *                active tab changes    → search/sort respect the current visible set
 */
(function(){
    'use strict';

    function boot() {
        var newBtn = document.getElementById('btn-new-page');
        if (!newBtn) return;              // not on the page-manager shell
        var toolsBar = newBtn.parentElement;
        if (!toolsBar || toolsBar.classList.contains('pm-tools-bar')) return;

        toolsBar.classList.add('pm-tools-bar');

        // ── Build the search + sort group and append to the tools bar ──
        var group = document.createElement('div');
        group.className = 'pm-tools-searchsort';
        group.innerHTML =
          '<div class="pm-tools-search-wrap">' +
            '<i class="fa-solid fa-magnifying-glass pm-tools-search-icon"></i>' +
            '<input type="search" id="pm-tools-search" placeholder="Search pages…" autocomplete="off" spellcheck="false">' +
            '<button type="button" id="pm-tools-search-clear" title="Clear search" aria-label="Clear search">&times;</button>' +
            '<span class="pm-tools-search-count" id="pm-tools-search-count"></span>' +
          '</div>' +
          '<div class="pm-tools-sort-wrap">' +
            '<label for="pm-tools-sort"><i class="fa-solid fa-arrow-down-wide-short"></i> Sort</label>' +
            '<select id="pm-tools-sort">' +
              '<option value="title-asc">Title A→Z</option>' +
              '<option value="title-desc">Title Z→A</option>' +
              '<option value="slug-asc">Slug A→Z</option>' +
              '<option value="updated-desc" selected>Most recent</option>' +
              '<option value="updated-asc">Oldest first</option>' +
            '</select>' +
          '</div>';
        toolsBar.appendChild(group);

        // ── Add a sentinel *before* the tools bar so we can detect when it sticks ──
        // (position:sticky doesn't fire events; IntersectionObserver on a zero-height
        //  sentinel gives us the same information reliably.)
        var sentinel = document.createElement('div');
        sentinel.className = 'pm-tools-bar-sentinel';
        toolsBar.parentNode.insertBefore(sentinel, toolsBar);

        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function(entries){
                toolsBar.classList.toggle('is-stuck', entries[0].intersectionRatio < 1);
            }, { threshold: [1] });
            io.observe(sentinel);
        }

        // ── Filter / sort wiring ──
        var searchEl = document.getElementById('pm-tools-search');
        var sortEl   = document.getElementById('pm-tools-sort');
        var countEl  = document.getElementById('pm-tools-search-count');
        var clearBtn = document.getElementById('pm-tools-search-clear');

        // Grab both grids (regular + aip) so we can filter the visible tab.
        function grids() {
            return Array.prototype.slice.call(document.querySelectorAll('#pm-cards-grid, .pm-preview-cards'));
        }
        function cards() {
            var out = [];
            grids().forEach(function(g){
                Array.prototype.slice.call(g.querySelectorAll('.pm-preview-card, [data-page]'))
                    .forEach(function(c){ out.push(c); });
            });
            return out;
        }

        // Cached card metadata — built once per render
        function indexCard(card) {
            if (card._pmIdx) return card._pmIdx;
            var titleEl = card.querySelector('.pm-preview-card-title, .pm-card-title, [data-title]');
            var slugEl  = card.querySelector('.pm-preview-card-slug, .pm-card-slug');
            var title = (titleEl ? (titleEl.dataset.title || titleEl.textContent) : card.dataset.title || '').trim();
            var slug  = (slugEl  ? slugEl.textContent : card.dataset.page || '').trim().replace(/^\//, '');
            var mtimeAttr = card.dataset.mtime || card.dataset.updated || '';
            var mtime = parseInt(mtimeAttr, 10);
            if (isNaN(mtime)) {
                // Try to read from "· YYYY-MM-DD" text in the slug line
                var m = /(\d{4}-\d{2}-\d{2})/.exec(card.textContent || '');
                mtime = m ? Date.parse(m[1]) / 1000 : 0;
            }
            card._pmIdx = { title: title, titleLower: title.toLowerCase(), slug: slug, slugLower: slug.toLowerCase(), mtime: mtime };
            return card._pmIdx;
        }

        function applyFilter() {
            var q = (searchEl.value || '').trim().toLowerCase();
            var shown = 0, total = 0;
            cards().forEach(function(card){
                total++;
                var idx = indexCard(card);
                var match = !q || idx.titleLower.indexOf(q) !== -1 || idx.slugLower.indexOf(q) !== -1;
                card.style.display = match ? '' : 'none';
                card.classList.toggle('pm-hidden-by-search', !match);
                if (match) shown++;
            });
            if (q) {
                countEl.textContent = shown + ' of ' + total;
                clearBtn.style.display = 'inline-block';
            } else {
                countEl.textContent = '';
                clearBtn.style.display = 'none';
            }
        }

        function applySort() {
            var mode = sortEl.value;
            grids().forEach(function(grid){
                var children = Array.prototype.slice.call(grid.querySelectorAll('.pm-preview-card, [data-page]'));
                if (!children.length) return;
                children.sort(function(a, b){
                    var ia = indexCard(a), ib = indexCard(b);
                    switch (mode) {
                        case 'title-asc':   return ia.titleLower.localeCompare(ib.titleLower);
                        case 'title-desc':  return ib.titleLower.localeCompare(ia.titleLower);
                        case 'slug-asc':    return ia.slugLower.localeCompare(ib.slugLower);
                        case 'updated-asc': return ia.mtime - ib.mtime;
                        case 'updated-desc':
                        default:            return ib.mtime - ia.mtime;
                    }
                });
                // Reattach in new order (preserves attached event listeners in jQuery/plain)
                children.forEach(function(c){ grid.appendChild(c); });
            });
        }

        searchEl.addEventListener('input', applyFilter);
        clearBtn.addEventListener('click', function(){ searchEl.value = ''; applyFilter(); searchEl.focus(); });
        sortEl.addEventListener('change', applySort);

        // Keyboard: press / to jump to the search box from anywhere
        document.addEventListener('keydown', function(e){
            if (e.key === '/' && !/^(input|textarea|select)$/i.test((e.target.tagName || ''))) {
                e.preventDefault();
                searchEl.focus();
            }
        });

        // Initial sort
        applySort();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
