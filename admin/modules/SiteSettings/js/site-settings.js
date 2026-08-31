/**
 * Luminal Open CMS
 * Licensed under the Apache License, Version 2.0. See LICENSE and NOTICE.
 *
 * Module: SiteSettings v1.0.0
 * File: site-settings.js
 *
 * Merged from: identity.js, fonts.js, logo.js, landing.js,
 *              footer.js, maintenance.js, save-all.js
 */
(function() {
    'use strict';

    var BOOT = window.SS_BOOT || {};
    var API  = (BOOT.endpoints && BOOT.endpoints.api) || '';

    /* =================================================================== */
    /* Helpers                                                              */
    /* =================================================================== */

    function $(id) { return document.getElementById(id); }

    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function toast(msg, type) {
        var container = $('ss-toasts');
        if (!container) return;
        var el = document.createElement('div');
        el.className = 'ss-toast ' + (type || 'info');
        el.innerHTML = '<span>' + escapeHtml(msg) + '</span>' +
            '<button class="ss-toast-close" onclick="this.parentElement.remove()">\u00d7</button>';
        container.appendChild(el);
        requestAnimationFrame(function() { el.classList.add('show'); });
        setTimeout(function() {
            el.classList.remove('show');
            setTimeout(function() { el.remove(); }, 300);
        }, 4000);
    }

    /* =================================================================== */
    /* Identity                                                             */
    /* =================================================================== */

    var Identity = {
        apply: function(s) {
            $('site_name').value        = s.site_name || '';
            $('site_title').value       = s.site_title || '';
            $('meta_description').value = s.meta_description || '';
            $('meta_keywords').value    = s.meta_keywords || '';
            if ($('llms_about')) $('llms_about').value = s.llms_about || '';
            // Default to true if not set (backward compat with existing sites)
            $('show_site_title').checked = (s.show_site_title === undefined || s.show_site_title === null || s.show_site_title === 1 || s.show_site_title === true || s.show_site_title === '1');
            this.updatePreview();
        },
        getData: function() {
            return {
                site_name:        $('site_name').value,
                site_title:       $('site_title').value,
                meta_description: $('meta_description').value,
                meta_keywords:    $('meta_keywords').value,
                show_site_title:  $('show_site_title').checked ? 1 : 0
            };
        },
        updatePreview: function() {
            var name = $('site_name').value || 'SITE NAME';
            var el   = $('live-preview-text');
            if (el) el.textContent = name.toUpperCase();
        },
        bindEvents: function() {
            var self = this;
            $('site_name').addEventListener('input', function() { self.updatePreview(); });
        }
    };

    /* =================================================================== */
    /* Fonts                                                                */
    /* =================================================================== */

    var Fonts = {
        localFonts:  [],
        googleFonts: ['Arial','Helvetica','Times New Roman','Georgia','Verdana',
                      'Roboto','Open Sans','Lato','Montserrat','Inter'],

        loadLocalFonts: function(cb) {
            var self = this;
            fetch('/css/custom-fonts.css')
                .then(function(r) { return r.text(); })
                .then(function(css) {
                    var m = css.match(/font-family:'([^']+)'/g);
                    if (m) {
                        var set = {};
                        m.forEach(function(s) {
                            set[s.replace(/font-family:'([^']+)'/, '$1')] = 1;
                        });
                        self.localFonts = Object.keys(set).sort();
                    }
                    cb();
                })
                .catch(function() { cb(); });
        },

        pickers: {},

        populateSelects: function() {
            var self = this;
            ['header_font', 'body_font'].forEach(function(id) {
                var sel = $(id);
                if (!sel) return;
                sel.innerHTML = '';

                if (self.localFonts.length) {
                    var lg = document.createElement('optgroup');
                    lg.label = 'Local Fonts (' + self.localFonts.length + ')';
                    self.localFonts.forEach(function(f) {
                        var o = document.createElement('option');
                        o.value = f; o.textContent = f;
                        lg.appendChild(o);
                    });
                    sel.appendChild(lg);
                }

                var gg = document.createElement('optgroup');
                gg.label = 'Google / System Fonts';
                self.googleFonts.forEach(function(f) {
                    var o = document.createElement('option');
                    o.value = f; o.textContent = f;
                    gg.appendChild(o);
                });
                sel.appendChild(gg);
            });

            self.buildFontPickers();
        },

        buildFontPickers: function() {
            var self = this;
            ['header_font', 'body_font'].forEach(function(id) {
                var sel = $(id);
                if (!sel) return;

                /* Wrap the select */
                var wrap = document.createElement('div');
                wrap.className = 'ss-fp-wrap';
                sel.parentNode.insertBefore(wrap, sel);
                wrap.appendChild(sel);

                /* Trigger button */
                var trigger = document.createElement('div');
                trigger.className = 'ss-fp-trigger';
                trigger.innerHTML = '<span class="ss-fp-trigger-text">Select a font...</span>' +
                    '<span class="ss-fp-trigger-arrow">\u25BC</span>';
                wrap.appendChild(trigger);

                /* Dropdown panel */
                var dd = document.createElement('div');
                dd.className = 'ss-fp-dropdown';

                var search = document.createElement('input');
                search.className = 'ss-fp-search';
                search.type = 'text';
                search.placeholder = 'Search fonts...';
                dd.appendChild(search);

                var list = document.createElement('div');
                list.className = 'ss-fp-list';

                var empty = document.createElement('div');
                empty.className = 'ss-fp-empty';
                empty.textContent = 'No fonts match';
                empty.style.display = 'none';

                /* Build items from localFonts + googleFonts */
                if (self.localFonts.length) {
                    var gh = document.createElement('div');
                    gh.className = 'ss-fp-group';
                    gh.textContent = 'Local Fonts (' + self.localFonts.length + ')';
                    gh.setAttribute('data-group', 'local');
                    list.appendChild(gh);
                    self.localFonts.forEach(function(f) {
                        var item = document.createElement('div');
                        item.className = 'ss-fp-item';
                        item.textContent = f;
                        item.style.fontFamily = "'" + f + "', sans-serif";
                        item.setAttribute('data-font', f);
                        item.setAttribute('data-group', 'local');
                        list.appendChild(item);
                    });
                }

                var sgh = document.createElement('div');
                sgh.className = 'ss-fp-group';
                sgh.textContent = 'Google / System Fonts';
                sgh.setAttribute('data-group', 'system');
                list.appendChild(sgh);
                self.googleFonts.forEach(function(f) {
                    var item = document.createElement('div');
                    item.className = 'ss-fp-item';
                    item.textContent = f;
                    item.style.fontFamily = "'" + f + "', sans-serif";
                    item.setAttribute('data-font', f);
                    item.setAttribute('data-group', 'system');
                    list.appendChild(item);
                });

                dd.appendChild(list);
                dd.appendChild(empty);
                wrap.appendChild(dd);

                /* Store refs */
                var picker = { wrap: wrap, trigger: trigger, dd: dd, search: search, list: list, empty: empty, sel: sel };
                self.pickers[id] = picker;

                /* Click trigger to open/close */
                trigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var isOpen = dd.classList.contains('open');
                    self.closeAllPickers();
                    if (!isOpen) {
                        dd.classList.add('open');
                        trigger.classList.add('open');
                        search.value = '';
                        self.filterFontItems(picker, '');
                        search.focus();
                        /* Scroll active item into view */
                        var active = list.querySelector('.ss-fp-item.active');
                        if (active) active.scrollIntoView({ block: 'center' });
                    }
                });

                /* Click item to select */
                list.addEventListener('click', function(e) {
                    var item = e.target.closest('.ss-fp-item');
                    if (!item) return;
                    var font = item.getAttribute('data-font');
                    sel.value = font;
                    sel.dispatchEvent(new Event('change'));
                    self.updateTrigger(picker);
                    self.closeAllPickers();
                });

                /* Search filter */
                search.addEventListener('input', function() {
                    self.filterFontItems(picker, this.value);
                });

                /* Prevent dropdown click from closing */
                dd.addEventListener('click', function(e) { e.stopPropagation(); });
            });

            /* Close on outside click */
            document.addEventListener('click', function() { self.closeAllPickers(); });

            /* Close on Escape */
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') self.closeAllPickers();
            });
        },

        updateTrigger: function(picker) {
            var val = picker.sel.value;
            var text = picker.trigger.querySelector('.ss-fp-trigger-text');
            if (val) {
                text.textContent = val;
                text.style.fontFamily = "'" + val + "', sans-serif";
            } else {
                text.textContent = 'Select a font...';
                text.style.fontFamily = '';
            }
            /* Update active state in list */
            var items = picker.list.querySelectorAll('.ss-fp-item');
            for (var i = 0; i < items.length; i++) {
                items[i].classList.toggle('active', items[i].getAttribute('data-font') === val);
            }
        },

        filterFontItems: function(picker, query) {
            var q = query.toLowerCase();
            var items = picker.list.querySelectorAll('.ss-fp-item');
            var groups = picker.list.querySelectorAll('.ss-fp-group');
            var groupCounts = {};

            for (var i = 0; i < items.length; i++) {
                var font = items[i].getAttribute('data-font').toLowerCase();
                var match = !q || font.indexOf(q) !== -1;
                items[i].classList.toggle('hidden', !match);
                var g = items[i].getAttribute('data-group');
                groupCounts[g] = (groupCounts[g] || 0) + (match ? 1 : 0);
            }

            var totalVisible = 0;
            for (var j = 0; j < groups.length; j++) {
                var gName = groups[j].getAttribute('data-group');
                var cnt = groupCounts[gName] || 0;
                groups[j].style.display = cnt ? '' : 'none';
                totalVisible += cnt;
            }

            picker.empty.style.display = totalVisible === 0 ? '' : 'none';
        },

        closeAllPickers: function() {
            var self = this;
            ['header_font', 'body_font'].forEach(function(id) {
                var p = self.pickers[id];
                if (p) {
                    p.dd.classList.remove('open');
                    p.trigger.classList.remove('open');
                }
            });
        },

        apply: function(s) {
            if (s.header_font_family) $('header_font').value = s.header_font_family;
            if (s.header_font_size) {
                $('header_size').value = s.header_font_size;
                $('header_size_val').textContent = s.header_font_size;
            }
            if (s.header_font_color)  $('header_color').value   = s.header_font_color;
            if (s.header_font_bold)   $('header_bold').checked  = true;
            if (s.header_font_italic) $('header_italic').checked = true;

            if (s.body_font_family) $('body_font').value = s.body_font_family;
            if (s.body_font_size) {
                $('body_size').value = s.body_font_size;
                $('body_size_val').textContent = s.body_font_size;
            }
            if (s.body_font_color) $('body_color').value = s.body_font_color;

            /* Sync custom font picker triggers */
            if (this.pickers.header_font) this.updateTrigger(this.pickers.header_font);
            if (this.pickers.body_font) this.updateTrigger(this.pickers.body_font);

            this.updatePreviews();
        },

        getData: function() {
            return {
                header_font_family: $('header_font').value,
                header_font_size:   parseFloat($('header_size').value),
                header_font_color:  $('header_color').value,
                header_font_bold:   $('header_bold').checked ? 1 : 0,
                header_font_italic: $('header_italic').checked ? 1 : 0,
                body_font_family:   $('body_font').value,
                body_font_size:     parseFloat($('body_size').value),
                body_font_color:    $('body_color').value
            };
        },

        updatePreviews: function() {
            var hp = $('live-preview-text');
            if (hp) {
                hp.style.fontFamily  = $('header_font').value;
                hp.style.fontSize    = $('header_size').value + 'rem';
                hp.style.color       = $('header_color').value;
                hp.style.fontWeight  = $('header_bold').checked ? 'bold' : 'normal';
                hp.style.fontStyle   = $('header_italic').checked ? 'italic' : 'normal';
            }
            var bp = $('body-preview');
            if (bp) {
                bp.style.fontFamily = $('body_font').value;
                bp.style.fontSize   = $('body_size').value + 'rem';
                bp.style.color      = $('body_color').value;
            }
        },

        bindEvents: function() {
            var self = this;
            ['header_font','header_size','header_color','header_bold','header_italic',
             'body_font','body_size','body_color'].forEach(function(id) {
                var el = $(id);
                if (!el) return;
                el.addEventListener('change', function() { self.updatePreviews(); });
                el.addEventListener('input',  function() { self.updatePreviews(); });
            });
            $('header_size').addEventListener('input', function() {
                $('header_size_val').textContent = this.value;
            });
            $('body_size').addEventListener('input', function() {
                $('body_size_val').textContent = this.value;
            });
        }
    };

    /* =================================================================== */
    /* Logo                                                                 */
    /* =================================================================== */

    var Logo = {
        file: null,
        path: '',

        apply: function(s) {
            if (s.enable_logo)        $('enable_logo').checked = true;
            if (s.full_width_header)  $('full_width').checked  = true;

            if (s.logo_path) {
                this.path = s.logo_path;
                var bg = $('logo-background');
                bg.style.backgroundImage    = 'url(/' + s.logo_path + '?t=' + Date.now() + ')';
                bg.style.backgroundSize     = 'contain';
                bg.style.backgroundPosition = 'center';
                bg.style.backgroundRepeat   = 'no-repeat';
            }

            if (s.logo_max_width) {
                $('logo_width').value = s.logo_max_width;
                $('logo_width_val').textContent = s.logo_max_width;
                this.updateSize();
            }
            if (s.logo_margin_bottom !== undefined) {
                $('logo_margin').value = s.logo_margin_bottom;
                $('logo_margin_val').textContent = s.logo_margin_bottom;
            }
        },

        getData: function() {
            return {
                enable_logo:        $('enable_logo').checked ? 1 : 0,
                full_width_header:  $('full_width').checked  ? 1 : 0,
                logo_path:          this.path,
                logo_max_width:     parseInt($('logo_width').value),
                logo_margin_bottom: parseFloat($('logo_margin').value)
            };
        },

        updateSize: function() {
            var w  = $('logo_width').value;
            var bg = $('logo-background');
            bg.style.width    = w + 'px';
            bg.style.maxWidth = w + 'px';
        },

        handleFile: function(file) {
            var allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
            if (allowed.indexOf(file.type) === -1) {
                return toast('Invalid file type. Use JPG, PNG, GIF, WEBP, or SVG.', 'error');
            }
            this.file = file;
            var self = this;
            var reader = new FileReader();
            reader.onload = function(e) {
                var bg = $('logo-background');
                bg.style.backgroundImage    = 'url(' + e.target.result + ')';
                bg.style.backgroundSize     = 'contain';
                bg.style.backgroundPosition = 'center';
                bg.style.backgroundRepeat   = 'no-repeat';
                self.updateSize();
                self.uploadLogo();
            };
            reader.readAsDataURL(file);
        },

        uploadLogo: function() {
            if (!this.file) return;
            toast('Uploading logo...');
            var self = this;
            var fd = new FormData();
            fd.append('logo', this.file);
            fd.append('action', 'upload_logo');

            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.ok && resp.data) {
                        self.path = resp.data.path;
                        self.file = null;
                        toast('Logo uploaded: ' + resp.data.filename, 'success');
                        setTimeout(function() { self.generateOG(true); }, 500);
                    } else {
                        toast('Logo upload failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                })
                .catch(function(err) { toast('Upload error: ' + err.message, 'error'); });
        },

        uploadIfNeeded: function(cb) {
            if (!this.file) return cb();
            var self = this;
            var fd = new FormData();
            fd.append('logo', this.file);
            fd.append('action', 'upload_logo');

            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.ok && resp.data) {
                        self.path = resp.data.path;
                        self.file = null;
                        toast('Logo uploaded: ' + resp.data.filename, 'success');
                        // Auto-generate OG images after Save-All upload path too
                        setTimeout(function() { self.generateOG(true); cb(); }, 500);
                        return;
                    }
                    cb();
                });
        },

        generateOG: function(autoMode) {
            if (!this.path) return toast('No logo to generate from', 'error');
            if (!autoMode && !confirm('Generate OG images and favicons from current logo?')) return;

            toast('Generating OG images...');
            var fd = new FormData();
            fd.append('action', 'generate_og');
            fd.append('logo_path', this.path);

            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.ok && resp.data && resp.data.generated) {
                        toast('Generated ' + resp.data.generated.length + ' OG images', 'success');
                    } else {
                        toast('OG generation failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                })
                .catch(function(err) { toast('OG error: ' + err.message, 'error'); });
        },

        bindEvents: function() {
            var self    = this;
            var dz      = $('logo-drop-zone');

            dz.addEventListener('dragover', function(e) {
                e.preventDefault(); dz.classList.add('drag-over');
            });
            dz.addEventListener('dragleave', function() {
                dz.classList.remove('drag-over');
            });
            dz.addEventListener('drop', function(e) {
                e.preventDefault(); dz.classList.remove('drag-over');
                if (e.dataTransfer.files[0]) self.handleFile(e.dataTransfer.files[0]);
            });
            dz.addEventListener('click', function() { $('logo-file').click(); });

            $('choose-btn').addEventListener('click', function(e) {
                e.stopPropagation(); $('logo-file').click();
            });
            $('logo-file').addEventListener('change', function() {
                if (this.files[0]) self.handleFile(this.files[0]);
            });
            $('logo_width').addEventListener('input', function() {
                $('logo_width_val').textContent = this.value;
                self.updateSize();
            });
            $('logo_margin').addEventListener('input', function() {
                $('logo_margin_val').textContent = this.value;
            });
            $('gen-og-btn').addEventListener('click', function() {
                self.generateOG(false);
            });
        }
    };

    /* =================================================================== */
    /* Landing Page                                                         */
    /* =================================================================== */

    var Landing = {
        apply: function(s, pages) {
            var sel = $('landing_page');
            sel.innerHTML = '';
            (pages || ['home']).forEach(function(p) {
                var o = document.createElement('option');
                o.value = p; o.textContent = p;
                sel.appendChild(o);
            });
            if (s.home_page_slug)      sel.value = s.home_page_slug;
            if (s.main_content_margin_top !== undefined) {
                $('content_gap').value = s.main_content_margin_top;
                $('content_gap_val').textContent = s.main_content_margin_top;
            }
        },
        getData: function() {
            return {
                home_page_slug:          $('landing_page').value,
                main_content_margin_top: parseInt($('content_gap').value)
            };
        },
        bindEvents: function() {
            $('content_gap').addEventListener('input', function() {
                $('content_gap_val').textContent = this.value;
            });
        }
    };

    /* =================================================================== */
    /* Footer                                                               */
    /* =================================================================== */

    var Footer = {
        /* Icon files this site can offer, fetched once from the API (the shipped
         * /panels/footer_images/ contents). Rows built before it arrives are
         * re-populated by refreshIconOptions(). */
        iconChoices: [],

        apply: function(s) {
            var f = s.footer || {};
            $('footer_title').value         = f.title || '';
            $('footer_contact_email').value = f.contact_email || '';
            $('footer_phone').value         = f.phone || '';
            $('footer_address').value       = f.address || '';
            $('footer_credits').value       = f.credits || '';
            $('footer_tagline').value       = f.tagline || '';
            $('footer_custom_html').value   = f.custom_html || '';

            if (f.social_links && f.social_links.length) {
                f.social_links.forEach(function(l) { Footer.addSocialLink(l.platform, l.url, l.icon); });
            } else {
                this.addSocialLink();
            }
            if (f.affiliate_links && f.affiliate_links.length) {
                f.affiliate_links.forEach(function(l) { Footer.addAffiliateLink(l.label, l.url, l.logo, l.mono); });
            } else {
                this.addAffiliateLink();
            }
            // Legal links have no "start with one blank row" default: an empty
            // legal row is noise on most sites, and the + button is right there.
            (f.legal_links || []).forEach(function(l) { Footer.addLegalLink(l.label, l.url); });
        },

        /* Pull the icon list, then fill every <select> already on the page. */
        loadIconChoices: function() {
            return fetch(API + '?action=footer_icons')
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    Footer.iconChoices = (resp.ok && resp.data && resp.data.icons) ? resp.data.icons : [];
                    Footer.refreshIconOptions();
                })
                .catch(function() { /* picker degrades to Auto / Text only */ });
        },

        iconOptionsHtml: function(selected) {
            var sel = selected || '';
            var html = '<option value=""' + (sel === '' ? ' selected' : '') + '>Auto (match platform)</option>' +
                       '<option value="none"' + (sel === 'none' ? ' selected' : '') + '>Text only</option>';
            var known = false;
            this.iconChoices.forEach(function(ic) {
                if (ic.value === sel) known = true;
                html += '<option value="' + escapeHtml(ic.value) + '"' + (ic.value === sel ? ' selected' : '') + '>' + escapeHtml(ic.label) + '</option>';
            });
            // A stored icon that is no longer on disk must stay selectable, or
            // simply opening Site Settings would silently reset it to Auto.
            if (sel && sel !== 'none' && !known) {
                html += '<option value="' + escapeHtml(sel) + '" selected>' + escapeHtml(sel) + ' (missing)</option>';
            }
            return html;
        },

        refreshIconOptions: function() {
            document.querySelectorAll('#social-links-container .social-icon').forEach(function(selEl) {
                selEl.innerHTML = Footer.iconOptionsHtml(selEl.dataset.value || '');
                Footer.updateIconPreview(selEl);
            });
        },

        updateIconPreview: function(selEl) {
            var row = selEl.closest('.ss-link-row');
            var img = row && row.querySelector('.ss-icon-preview');
            if (!img) return;
            var v = selEl.value;
            selEl.dataset.value = v;
            if (v && v !== 'none') {
                img.src = '/' + v.replace(/^\/+/, '');
                img.classList.remove('is-empty');
            } else {
                // Auto: show what the platform would resolve to, so "TikTok" is
                // visibly a TikTok icon before anything is saved.
                var plat = row.querySelector('.social-platform');
                var auto = Footer.autoIconFor(plat ? plat.value : '');
                img.src = auto || 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
                img.classList.toggle('is-empty', !auto);
            }
        },

        /* Mirror of luminal_social_icon_map() in /includes/social_icons.php.
         * Preview only \u2014 the server is still the authority at render time. */
        autoIconMap: {
            facebook: 'facebook-icon.png', fb: 'facebook-icon.png',
            instagram: 'insta_color.png', insta: 'insta_color.png', ig: 'insta_color.png',
            youtube: 'youtube.jpg', yt: 'youtube.jpg',
            tiktok: 'tik-tok.png',
            x: 'twitterx.png', twitter: 'twitterx.png',
            email: 'email.png', mail: 'email.png', contact: 'email.png',
            applepodcasts: 'Apple_PodCasts.png', apple: 'Apple_PodCasts.png', podcasts: 'Apple_PodCasts.png'
        },

        autoIconFor: function(platform) {
            var key = String(platform || '').toLowerCase().replace(/[^a-z0-9]/g, '');
            var f = this.autoIconMap[key];
            return f ? '/panels/footer_images/' + f : '';
        },

        getData: function() {
            var social = [];
            document.querySelectorAll('#social-links-container .ss-link-row').forEach(function(row) {
                var p = row.querySelector('.social-platform').value.trim();
                var u = row.querySelector('.social-url').value.trim();
                var i = row.querySelector('.social-icon').value.trim();
                if (p || u) social.push({ platform: p, url: u, icon: i });
            });

            var aff = [];
            document.querySelectorAll('#affiliate-links-container .ss-link-row').forEach(function(row) {
                var l = row.querySelector('.affiliate-label').value.trim();
                var u = row.querySelector('.affiliate-url').value.trim();
                var g = row.querySelector('.affiliate-logo').value.trim();
                var m = row.querySelector('.affiliate-mono').checked;
                if (l || u) aff.push({ label: l, url: u, logo: g, mono: m ? 1 : 0 });
            });

            var legal = [];
            document.querySelectorAll('#legal-links-container .ss-link-row').forEach(function(row) {
                var l = row.querySelector('.legal-label').value.trim();
                var u = row.querySelector('.legal-url').value.trim();
                if (l || u) legal.push({ label: l, url: u });
            });

            return {
                footer: {
                    title:           $('footer_title').value.trim(),
                    contact_email:   $('footer_contact_email').value.trim(),
                    phone:           $('footer_phone').value.trim(),
                    address:         $('footer_address').value.trim(),
                    credits:         $('footer_credits').value.trim(),
                    tagline:         $('footer_tagline').value.trim(),
                    custom_html:     $('footer_custom_html').value.trim(),
                    social_links:    social,
                    affiliate_links: aff,
                    // Was omitted entirely until 2026-08-17, and because the server
                    // replaces the whole `footer` object on save, every Save All
                    // silently DELETED a site's legal links. Always send the key.
                    legal_links:     legal
                }
            };
        },

        addSocialLink: function(platform, url, icon) {
            var c = $('social-links-container');
            var r = document.createElement('div');
            r.className = 'ss-link-row';
            r.innerHTML =
                '<input type="text" class="ss-input social-platform" placeholder="Platform (e.g., TikTok)" value="' + escapeHtml(platform || '') + '">' +
                '<input type="url"  class="ss-input social-url" placeholder="https://..." value="' + escapeHtml(url || '') + '">' +
                '<select class="ss-input social-icon" title="Icon">' + this.iconOptionsHtml(icon || '') + '</select>' +
                '<img class="ss-icon-preview is-empty" alt="">' +
                '<button type="button" class="ss-btn ss-btn-danger ss-btn-sm ss-remove-btn" onclick="this.parentElement.remove()">\u00d7</button>';
            c.appendChild(r);
            var selEl = r.querySelector('.social-icon');
            selEl.dataset.value = icon || '';
            selEl.addEventListener('change', function() { Footer.updateIconPreview(this); });
            r.querySelector('.social-platform').addEventListener('input', function() { Footer.updateIconPreview(selEl); });
            this.updateIconPreview(selEl);
        },

        addAffiliateLink: function(label, url, logo, mono) {
            var c = $('affiliate-links-container');
            var r = document.createElement('div');
            r.className = 'ss-link-row';
            r.innerHTML =
                '<input type="text" class="ss-input affiliate-label" placeholder="Link Label" value="' + escapeHtml(label || '') + '">' +
                '<input type="url"  class="ss-input affiliate-url" placeholder="https://..." value="' + escapeHtml(url || '') + '">' +
                // footer.php has always rendered affiliate logos; the form never
                // had a field for one, so they were unreachable from the admin.
                '<input type="text" class="ss-input affiliate-logo" placeholder="Logo path (optional)" value="' + escapeHtml(logo || '') + '">' +
                '<label class="ss-mono-toggle" title="Flatten the logo to a white silhouette"><input type="checkbox" class="affiliate-mono"' + (mono ? ' checked' : '') + '> Mono</label>' +
                '<button type="button" class="ss-btn ss-btn-danger ss-btn-sm ss-remove-btn" onclick="this.parentElement.remove()">\u00d7</button>';
            c.appendChild(r);
        },

        addLegalLink: function(label, url) {
            var c = $('legal-links-container');
            var r = document.createElement('div');
            r.className = 'ss-link-row';
            r.innerHTML =
                '<input type="text" class="ss-input legal-label" placeholder="Privacy Policy" value="' + escapeHtml(label || '') + '">' +
                '<input type="url"  class="ss-input legal-url" placeholder="https://..." value="' + escapeHtml(url || '') + '">' +
                '<button type="button" class="ss-btn ss-btn-danger ss-btn-sm ss-remove-btn" onclick="this.parentElement.remove()">\u00d7</button>';
            c.appendChild(r);
        },

        /* Show what the server says is ON DISK after a save. A write that fails
         * (or never happens) can no longer read as success. */
        showReadback: function(resp) {
            var box = $('footer-save-readback');
            if (!box) return;
            box.hidden = false;
            box.classList.remove('is-ok', 'is-fail');
            if (!resp || !resp.ok) {
                box.classList.add('is-fail');
                box.innerHTML = '<b>Not saved.</b> ' + escapeHtml((resp && resp.error) || 'The server did not confirm the write.') +
                                ' &mdash; nothing on disk has changed.';
                return;
            }
            var f = (resp.data && resp.data.footer) || {};
            var n = function(a) { return (a && a.length) || 0; };
            box.classList.add('is-ok');
            box.innerHTML = '<b>Saved and read back from disk:</b> ' +
                n(f.social_links) + ' social, ' + n(f.affiliate_links) + ' affiliate, ' + n(f.legal_links) + ' legal' +
                (resp.data && resp.data.saved_at ? ' &middot; ' + escapeHtml(resp.data.saved_at) : '') +
                (n(f.social_links) ? '<br>' + f.social_links.map(function(l) {
                    return escapeHtml(l.platform || l.url || '?');
                }).join(' &middot; ') : '');
        }
    };

    /* =================================================================== */
    /* Floating Video Player                                                */
    /* =================================================================== */

    var FloatingVideo = {
        apply: function(s) {
            var vf = s.video_float || {};
            $('vf_enabled').checked = vf.enabled !== undefined ? !!vf.enabled : true;
            if (vf.mode) $('vf_mode').value = vf.mode;
            // Admin session timeout (shares site-settings.json; read at login by session_boot.php).
            var ash = $('admin_session_hours');
            if (ash) ash.value = String(s.admin_session_hours || 12);
            // Idle cutoff — fed to idle-logout.js via window.LUM_IDLE in admin_footer.php.
            // Whichever of the two fires first ends the session; idle usually wins.
            var aim = $('admin_idle_minutes');
            if (aim) aim.value = String(s.admin_idle_minutes || 15);
        },
        getData: function() {
            var d = {
                video_float: {
                    enabled: $('vf_enabled').checked ? 1 : 0,
                    mode:    $('vf_mode').value
                }
            };
            var ash = $('admin_session_hours');
            if (ash) d.admin_session_hours = parseInt(ash.value, 10) || 12;
            var aim = $('admin_idle_minutes');
            if (aim) d.admin_idle_minutes = parseInt(aim.value, 10) || 15;
            return d;
        },
        bindEvents: function() {}
    };

    /* =================================================================== */
    /* Maintenance                                                          */
    /* =================================================================== */

    var Maintenance = {
        fixPermissions: function() {
            toast('Fixing permissions...');
            var fd = new FormData();
            fd.append('action', 'fix_permissions');
            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.ok && resp.data) {
                        var box = $('output-box');
                        box.textContent = resp.data.output;
                        box.classList.add('show');
                        toast('Permissions fixed', 'success');
                    } else {
                        toast('Failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                });
        },

        recreatePaths: function() {
            toast('Recreating paths...');
            var fd = new FormData();
            fd.append('action', 'recreate_paths');
            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.ok && resp.data) {
                        var box = $('output-box');
                        box.textContent = resp.data.output;
                        box.classList.add('show');
                        toast('Paths recreated', 'success');
                    } else {
                        toast('Failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                });
        },

        bindEvents: function() {
            $('fix-perms-btn').addEventListener('click', Maintenance.fixPermissions);
            $('recreate-btn').addEventListener('click',  Maintenance.recreatePaths);
        }
    };

    /* =================================================================== */
    /* Dashboard Stats Config                                              */
    /* =================================================================== */

    var DashboardStats = {
        load: function() {
            fetch(API + '?action=get_stats_config')
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (!resp.ok) return;
                    var cfg = resp.data.config || {};
                    var det = resp.data.detected || [];
                    var pathEl = $('stats-log-path');
                    var userEl = $('stats-cpanel-user');
                    if (pathEl) pathEl.value = cfg.custom_log_path || '';
                    if (userEl) userEl.value = cfg.cpanel_user || '';
                    // Show cpanel group if cpanel_user present
                    var cpGroup = $('stats-cpanel-group');
                    if (cpGroup && cfg.cpanel_user) cpGroup.style.display = '';
                    // Show detected logs
                    var detEl = $('stats-detected');
                    if (detEl && det.length) {
                        detEl.textContent = 'Detected: ' + det.join(', ');
                        detEl.style.display = '';
                    }
                })
                .catch(function() {});
        },

        save: function() {
            var pathEl = $('stats-log-path');
            var userEl = $('stats-cpanel-user');
            var fd = new FormData();
            fd.append('action', 'save_stats_config');
            fd.append('custom_log_path', (pathEl ? pathEl.value.trim() : ''));
            fd.append('cpanel_user', (userEl ? userEl.value.trim() : ''));
            toast('Saving stats config...');
            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    var box = $('stats-output-box');
                    if (resp.ok) {
                        if (box) { box.textContent = resp.data.message || 'Saved'; box.classList.add('show'); }
                        toast('Stats config saved', 'success');
                    } else {
                        toast('Failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                });
        },

        clearCache: function() {
            var fd = new FormData();
            fd.append('action', 'clear_stats_cache');
            toast('Clearing stats cache...');
            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    var box = $('stats-output-box');
                    if (resp.ok) {
                        if (box) { box.textContent = resp.data.message; box.classList.add('show'); }
                        toast(resp.data.message, 'success');
                    } else {
                        toast('Failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                });
        },

        bindEvents: function() {
            var saveBtn  = $('stats-save-btn');
            var clearBtn = $('stats-clear-cache-btn');
            if (saveBtn)  saveBtn.addEventListener('click',  DashboardStats.save);
            if (clearBtn) clearBtn.addEventListener('click', DashboardStats.clearCache);
        }
    };

    /* =================================================================== */
    /* Save All                                                             */
    /* =================================================================== */

    function saveAll() {
        toast('Saving all settings...');

        Logo.uploadIfNeeded(function() {
            var all = {};
            // Collect each section separately. Previously one throwing getData()
            // (a renamed field id, a section that failed to render) aborted the
            // whole click handler BEFORE the fetch — no request, no toast, no
            // saved file, and nothing on screen to say so.
            try {
                [Identity, Fonts, Logo, Landing, Footer, FloatingVideo].forEach(function(mod) {
                    var d = mod.getData();
                    Object.keys(d).forEach(function(k) { all[k] = d[k]; });
                });
            } catch (err) {
                toast('Nothing was saved — could not read the form: ' + err.message, 'error');
                Footer.showReadback({ ok: false, error: 'Could not read the form: ' + err.message });
                return;
            }

            var fd = new FormData();
            fd.append('action', 'save');
            fd.append('file', 'site-settings.json');
            fd.append('data', JSON.stringify(all));

            fetch(API, { method: 'POST', body: fd })
                .then(function(r) {
                    // A WAF page, a session-expiry redirect or a PHP fatal all
                    // arrive as non-JSON. Report the status instead of dying in
                    // r.json() with an opaque "Unexpected token <".
                    return r.text().then(function(t) {
                        try { return JSON.parse(t); }
                        catch (e) { return { ok: false, error: 'HTTP ' + r.status + ' — server did not return JSON (' + t.slice(0, 120) + ')' }; }
                    });
                })
                .then(function(resp) {
                    if (resp.ok) {
                        toast('All settings saved!', 'success');
                    } else {
                        toast('Save failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                    Footer.showReadback(resp);
                })
                .catch(function(err) {
                    toast('Save error: ' + err.message, 'error');
                    Footer.showReadback({ ok: false, error: err.message });
                });
        });
    }

    /* =================================================================== */
    /* SEO Scanner                                                          */
    /* =================================================================== */

    var SeoScanner = {
        pages: [],
        suggestions: [],

        scan: function() {
            var self = this;
            var btn = $('seo-scan-btn');
            btn.disabled = true;
            btn.textContent = 'Scanning...';
            $('seo-generate-btn').disabled = true;
            $('seo-apply-all-btn').disabled = true;

            var fd = new FormData();
            fd.append('action', 'seo_scan');

            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    btn.disabled = false;
                    btn.textContent = 'Scan Pages';
                    if (resp.ok && resp.data && resp.data.pages) {
                        self.pages = resp.data.pages;
                        self.suggestions = [];
                        self.renderResults();
                        $('seo-generate-btn').disabled = false;
                        toast('Scanned ' + self.pages.length + ' pages', 'success');
                    } else {
                        toast('Scan failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = 'Scan Pages';
                    toast('Scan error: ' + err.message, 'error');
                });
        },

        generate: function() {
            var self = this;
            var btn = $('seo-generate-btn');
            btn.disabled = true;
            btn.textContent = 'Generating...';

            var fd = new FormData();
            fd.append('action', 'seo_generate');

            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    btn.disabled = false;
                    btn.textContent = 'Generate AI Suggestions';
                    if (resp.ok && resp.data) {
                        if (resp.data.pages) self.pages = resp.data.pages;
                        self.suggestions = resp.data.suggestions || [];
                        self.renderResults();
                        $('seo-apply-all-btn').disabled = self.suggestions.length === 0;
                        toast('Generated ' + self.suggestions.length + ' suggestions', 'success');
                    } else {
                        toast('Generate failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = 'Generate AI Suggestions';
                    toast('Generate error: ' + err.message, 'error');
                });
        },

        applyOne: function(slug, btn) {
            var row = btn.closest('tr');
            var textarea = row.querySelector('.seo-suggestion-input');
            var desc = textarea ? textarea.value.trim() : '';
            if (!desc) return toast('No description to apply', 'error');

            btn.disabled = true;
            btn.textContent = 'Applying...';

            var fd = new FormData();
            fd.append('action', 'seo_apply');
            fd.append('slug', slug);
            fd.append('description', desc);

            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.ok) {
                        btn.textContent = 'Applied';
                        btn.classList.add('ss-btn-applied');
                        row.querySelector('.seo-badge').className = 'seo-badge seo-badge-applied';
                        row.querySelector('.seo-badge').textContent = 'APPLIED';
                        toast('Applied to ' + slug, 'success');
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Apply';
                        toast('Failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = 'Apply';
                    toast('Error: ' + err.message, 'error');
                });
        },

        applyAll: function() {
            var self = this;
            var items = [];
            var rows = document.querySelectorAll('#seo-results-table tbody tr');
            rows.forEach(function(row) {
                var textarea = row.querySelector('.seo-suggestion-input');
                var slug = row.getAttribute('data-slug');
                if (textarea && textarea.value.trim() && slug) {
                    var applyBtn = row.querySelector('.seo-apply-btn');
                    if (applyBtn && !applyBtn.classList.contains('ss-btn-applied')) {
                        items.push({ slug: slug, description: textarea.value.trim() });
                    }
                }
            });

            if (!items.length) return toast('No suggestions to apply', 'error');

            var btn = $('seo-apply-all-btn');
            btn.disabled = true;
            btn.textContent = 'Applying...';

            var fd = new FormData();
            fd.append('action', 'seo_apply_all');
            fd.append('items', JSON.stringify(items));

            fetch(API, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    btn.textContent = 'Apply All';
                    if (resp.ok && resp.data) {
                        toast('Applied ' + resp.data.applied + ' descriptions', 'success');
                        // Mark all applied rows: badge → APPLIED, button → locked "Applied"
                        rows.forEach(function(row) {
                            var applyBtn = row.querySelector('.seo-apply-btn');
                            if (applyBtn && !applyBtn.classList.contains('ss-btn-applied')) {
                                var textarea = row.querySelector('.seo-suggestion-input');
                                if (textarea && textarea.value.trim()) {
                                    applyBtn.textContent = 'Applied';
                                    applyBtn.disabled = true;
                                    applyBtn.classList.add('ss-btn-applied');
                                    var badge = row.querySelector('.seo-badge');
                                    if (badge) {
                                        badge.className = 'seo-badge seo-badge-applied';
                                        badge.textContent = 'APPLIED';
                                    }
                                }
                            }
                        });
                        // Flip Apply All to visible "Done" state
                        btn.textContent = 'All Applied ✓';
                        btn.classList.add('ss-btn-applied');
                        if (resp.data.errors && resp.data.errors.length) {
                            toast('Failed: ' + resp.data.errors.join(', '), 'error');
                        }
                    } else {
                        btn.disabled = false;
                        toast('Apply failed: ' + (resp.error || 'Unknown'), 'error');
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = 'Apply All';
                    toast('Error: ' + err.message, 'error');
                });
        },

        renderResults: function() {
            var container = $('seo-results');
            if (!container) return;

            if (!this.pages.length) {
                container.style.display = 'none';
                return;
            }

            // Index suggestions by slug
            var sugMap = {};
            this.suggestions.forEach(function(s) { sugMap[s.slug] = s; });

            var badgeClass = { missing: 'seo-badge-missing', weak: 'seo-badge-weak', long: 'seo-badge-long', ok: 'seo-badge-ok' };
            var badgeLabel = { missing: 'MISSING', weak: 'WEAK', long: 'LONG', ok: 'OK' };

            var html = '<table class="seo-results-table" id="seo-results-table">';
            html += '<thead><tr><th>Page</th><th>Status</th><th>Current</th><th>Suggestion</th><th></th></tr></thead>';
            html += '<tbody>';

            this.pages.forEach(function(p) {
                var sug = sugMap[p.slug] || null;
                var sugVal = sug ? sug.description : '';
                var charInfo = p.description ? ' (' + p.char_count + ' chars)' : '';
                var sugChars = sugVal ? ' (' + sug.char_count + ' chars)' : '';

                html += '<tr data-slug="' + escapeHtml(p.slug) + '">';
                html += '<td class="seo-col-page"><strong>' + escapeHtml(p.title) + '</strong><br><small style="color:var(--ss-text-muted)">' + escapeHtml(p.slug) + '</small></td>';
                html += '<td><span class="seo-badge ' + (badgeClass[p.status] || '') + '">' + (badgeLabel[p.status] || p.status) + '</span></td>';
                html += '<td class="seo-col-current"><span style="font-size:12px;color:var(--ss-text-muted)">' + escapeHtml(p.description || '(none)') + charInfo + '</span></td>';
                html += '<td class="seo-col-suggestion">';
                if (p.status !== 'ok' || sugVal) {
                    html += '<textarea class="seo-suggestion-input" rows="2" placeholder="AI suggestion will appear here...">' + escapeHtml(sugVal) + '</textarea>';
                    if (sugVal) html += '<small class="seo-char-count">' + sugChars + '</small>';
                } else {
                    html += '<span style="color:var(--ss-text-muted);font-size:12px">No changes needed</span>';
                }
                html += '</td>';
                html += '<td class="seo-col-action">';
                if (p.status !== 'ok' || sugVal) {
                    html += '<button class="ss-btn ss-btn-primary ss-btn-sm seo-apply-btn" onclick="SS.seoApplyOne(\'' + escapeHtml(p.slug) + '\', this)"' + (sugVal ? '' : ' disabled') + '>Apply</button>';
                }
                html += '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            container.innerHTML = html;
            container.style.display = 'block';

            // Wire up textarea char count updates
            container.querySelectorAll('.seo-suggestion-input').forEach(function(ta) {
                ta.addEventListener('input', function() {
                    var countEl = ta.parentElement.querySelector('.seo-char-count');
                    var len = ta.value.trim().length;
                    if (countEl) {
                        countEl.textContent = ' (' + len + ' chars)';
                    } else if (len > 0) {
                        var small = document.createElement('small');
                        small.className = 'seo-char-count';
                        small.textContent = ' (' + len + ' chars)';
                        ta.parentElement.appendChild(small);
                    }
                    // Enable/disable the row's Apply button
                    var row = ta.closest('tr');
                    var applyBtn = row ? row.querySelector('.seo-apply-btn') : null;
                    if (applyBtn && !applyBtn.classList.contains('ss-btn-applied')) {
                        applyBtn.disabled = len === 0;
                    }
                });
            });
        },

        bindEvents: function() {
            var self = this;
            $('seo-scan-btn').addEventListener('click', function() { self.scan(); });
            $('seo-generate-btn').addEventListener('click', function() { self.generate(); });
            $('seo-apply-all-btn').addEventListener('click', function() { self.applyAll(); });
        }
    };

    /* =================================================================== */
    /* Init                                                                 */
    /* =================================================================== */

    function init() {
        /* Bind UI events */
        Identity.bindEvents();
        Fonts.bindEvents();
        Logo.bindEvents();
        Landing.bindEvents();
        Maintenance.bindEvents();
        DashboardStats.bindEvents();
        DashboardStats.load();
        SeoScanner.bindEvents();

        $('save-all-btn').addEventListener('click', saveAll);
        $('open-site-btn').addEventListener('click', function() {
            window.open('/', '_blank');
        });

        /* Load data — fonts, settings, and pages all in parallel */
        var fontsReady = new Promise(function(resolve) {
            Fonts.loadLocalFonts(function() {
                Fonts.populateSelects();
                resolve();
            });
        });

        // Footer icon choices — fetched alongside the settings so the picker is
        // populated by the time Footer.apply() builds the rows.
        Footer.loadIconChoices();

        var settingsReady = fetch(API + '?action=load&file=site-settings.json')
            .then(function(r) { return r.json(); })
            .then(function(resp) { return (resp.ok && resp.data) ? resp.data : {}; })
            .catch(function() { return {}; });

        var pagesReady = fetch(API + '?action=get_pages')
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                return (resp.ok && resp.data && resp.data.pages) ? resp.data.pages : ['home'];
            })
            .catch(function() { return ['home']; });

        /* Apply once all three are ready */
        Promise.all([fontsReady, settingsReady, pagesReady]).then(function(results) {
            var settings = results[1];
            var pages    = results[2];

            Identity.apply(settings);
            Fonts.apply(settings);
            Logo.apply(settings);
            Landing.apply(settings, pages);
            Footer.apply(settings);
            FloatingVideo.apply(settings);
        });
        wireVault();
    }

    /* =================================================================== */
    /* The Vault (llms.txt) — Add Sources · Generate About · Save & Regen    */
    /* =================================================================== */
    function wireVault() {
        var fbBtn = $('vault-import-fb-btn'), genBtn = $('vault-gen-about-btn'),
            saveBtn = $('vault-save-regen-btn'), fbStat = $('vault-fb-status'), stat = $('vault-status');
        if (!saveBtn) return;
        function post(action, extra) {
            var fd = new FormData(); fd.append('action', action);
            if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
            return fetch(API, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
        }
        if (fbBtn) fbBtn.addEventListener('click', function () {
            fbBtn.disabled = true; if (fbStat) fbStat.textContent = 'Importing…';
            post('vault_import_fb').then(function (d) {
                fbBtn.disabled = false;
                if (d.ok) {
                    var fb = d.data.facebook || {};
                    if (fbStat) fbStat.textContent = '✓ ' + (fb.name || 'Profile') + (fb.category ? ' · ' + fb.category : '');
                    toast('Facebook profile imported', 'success');
                } else { if (fbStat) fbStat.textContent = '✗ ' + (d.error || 'failed'); toast(d.error || 'Import failed', 'error'); }
            }).catch(function () { fbBtn.disabled = false; if (fbStat) fbStat.textContent = '✗ network error'; });
        });
        if (genBtn) genBtn.addEventListener('click', function () {
            genBtn.disabled = true; if (stat) stat.textContent = 'Generating…';
            post('vault_gen_about').then(function (d) {
                genBtn.disabled = false;
                if (d.ok) { $('llms_about').value = d.data.about || ''; if (stat) stat.textContent = '✓ Generated — review, then Save'; toast('About text generated', 'success'); }
                else { if (stat) stat.textContent = '✗ ' + (d.error || 'failed'); toast(d.error || 'Generate failed', 'error'); }
            }).catch(function () { genBtn.disabled = false; if (stat) stat.textContent = '✗ network error'; });
        });
        saveBtn.addEventListener('click', function () {
            saveBtn.disabled = true; if (stat) stat.textContent = 'Saving & regenerating…';
            post('vault_save_regen', { llms_about: $('llms_about').value }).then(function (d) {
                saveBtn.disabled = false;
                if (d.ok) { if (stat) stat.textContent = '✓ Vault updated (' + d.data.idx + 'b · ' + d.data.full + 'b full)'; toast('Vault regenerated', 'success'); loadStatus(); }
                else { if (stat) stat.textContent = '✗ ' + (d.error || 'failed'); toast(d.error || 'Save failed', 'error'); }
            }).catch(function () { saveBtn.disabled = false; if (stat) stat.textContent = '✗ network error'; });
        });

        /* ---- Freshness strip: status · Refresh now · auto-refresh toggle ---- */
        var refreshBtn = $('vault-refresh-btn'), freshPill = $('vault-fresh-pill'),
            freshMeta = $('vault-fresh-meta'), autoChk = $('vault-auto-refresh');
        function kb(b) { return b >= 1024 ? (b / 1024).toFixed(1) + ' KB' : (b || 0) + ' B'; }
        function ago(t, now) {
            if (!t) return 'never';
            var s = Math.max(0, (now || Math.floor(Date.now() / 1000)) - t);
            if (s < 90) return 'just now';
            if (s < 5400) return Math.round(s / 60) + ' min ago';
            if (s < 172800) return Math.round(s / 3600) + ' hr ago';
            return Math.round(s / 86400) + ' days ago';
        }
        function loadStatus() {
            if (!freshPill) return;
            post('vault_status').then(function (d) {
                if (!d.ok) { freshMeta.textContent = 'status unavailable'; return; }
                var s = d.data;
                var fresh = !s.stale;
                freshPill.textContent = s.last_gen ? (fresh ? '● Fresh' : '● Stale') : '● Not generated';
                freshPill.style.background = s.last_gen ? (fresh ? 'rgba(80,200,120,.22)' : 'rgba(240,180,60,.25)') : 'rgba(240,120,120,.25)';
                freshMeta.textContent = 'Generated ' + ago(s.last_gen, s.now) + ' · ' + kb(s.idx) + ' index / ' + kb(s.full) + ' full'
                    + (s.stale ? ' · content changed since' : '');
                if (autoChk) autoChk.checked = !!s.auto_refresh;
            }).catch(function () { if (freshMeta) freshMeta.textContent = 'status unavailable'; });
        }
        if (refreshBtn) refreshBtn.addEventListener('click', function () {
            refreshBtn.disabled = true; var t0 = refreshBtn.textContent; refreshBtn.textContent = 'Refreshing…';
            post('vault_refresh').then(function (d) {
                refreshBtn.disabled = false; refreshBtn.textContent = t0;
                if (d.ok) { toast('Vault refreshed', 'success'); loadStatus(); }
                else { toast(d.error || 'Refresh failed', 'error'); }
            }).catch(function () { refreshBtn.disabled = false; refreshBtn.textContent = t0; toast('Network error', 'error'); });
        });
        if (autoChk) autoChk.addEventListener('change', function () {
            post('vault_set_auto', { auto_refresh: autoChk.checked ? '1' : '0' }).then(function (d) {
                if (d.ok) { toast(autoChk.checked ? 'Auto-refresh on' : 'Auto-refresh off', 'success'); loadStatus(); }
            });
        });
        loadStatus();
    }

    /* =================================================================== */
    /* Public API                                                           */
    /* =================================================================== */

    window.SS = {
        saveAll:          saveAll,
        toast:            toast,
        addSocialLink:    function(p, u, i)    { Footer.addSocialLink(p, u, i); },
        addAffiliateLink: function(l, u, g, m) { Footer.addAffiliateLink(l, u, g, m); },
        addLegalLink:     function(l, u)       { Footer.addLegalLink(l, u); },
        generateOG:       function()     { Logo.generateOG(false); },
        seoApplyOne:      function(slug, btn) { SeoScanner.applyOne(slug, btn); }
    };

    document.addEventListener('DOMContentLoaded', init);
})();
