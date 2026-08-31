/**
 * YouTube Playlist Studio — Admin JS
 */
const ytStudio = (() => {
    const API = '/admin/modules/YouTubePlaylist/api.php';
    let _playlists = [];
    let _currentSlug = null;
    let _isNew = false;
    // Channel metadata that Resolve learned and Save has to send back, but which has
    // no field of its own (title/handle/topic-ness are read FROM YouTube, not typed).
    let _chMeta = { title: '', handle: '', is_topic: false };

    /** Which type radio is selected right now. */
    function currentType() {
        if (document.getElementById('yps-f-type-video')?.checked) return 'video';
        if (document.getElementById('yps-f-type-channel')?.checked) return 'channel';
        return 'playlist';
    }

    /** Set the type radios from a type string. */
    function setType(type) {
        const pl = document.getElementById('yps-f-type-playlist');
        const vi = document.getElementById('yps-f-type-video');
        const ch = document.getElementById('yps-f-type-channel');
        if (pl) pl.checked = (type === 'playlist');
        if (vi) vi.checked = (type === 'video');
        if (ch) ch.checked = (type === 'channel');
    }

    /** Normalise whatever a stored record claims to be. */
    function typeOf(p) {
        if (!p) return 'playlist';
        if (p.type === 'video') return 'video';
        if (p.type === 'channel') return 'channel';
        return 'playlist';
    }

    function shortcodeFor(type, slug) {
        if (type === 'video')   return `[[youtube-video:${slug}]]`;
        if (type === 'channel') return `[[youtube-channel:${slug}]]`;
        return `[[youtube-playlist:${slug}]]`;
    }

    /**
     * A channel's uploads playlist — derived here exactly as the renderer derives it
     * (UC… → UU…), never stored on the record. Shown in the UI because it is the thing
     * that actually plays, and hiding it is what made the old IDs feel "top secret".
     */
    function uploadsFor(p) {
        const id = ((p && p.channel_id) || '').trim();
        return /^UC[A-Za-z0-9_-]{22}$/.test(id) ? 'UU' + id.slice(2) : '';
    }

    /* ---- helpers ---- */

    async function apiGet(action, params = {}) {
        const qs = new URLSearchParams({ action, ...params });
        const res = await fetch(`${API}?${qs}`);
        return res.json();
    }

    async function apiPost(action, body = {}) {
        const res = await fetch(`${API}?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        return res.json();
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    /**
     * esc() goes through textContent -> innerHTML, which escapes & < > but NOT
     * quotes — safe for element content, NOT safe inside an attribute. Use this
     * for anything landing in href="…" / src="…".
     * (yt_normalize_playlist_id() rejects / ? & = : but not a double quote, so a
     * crafted playlist ID really can reach an attribute.)
     */
    function escAttr(s) {
        return esc(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Only emit a link for a URL we actually built ourselves or that is plainly http(s). */
    function safeUrl(u) {
        const v = String(u ?? '').trim();
        return /^https:\/\/(www\.youtube\.com|youtu\.be|img\.youtube\.com|i\.ytimg\.com)\//i.test(v) ? v : '';
    }

    function toast(msg, type = 'success') {
        let el = document.getElementById('yps-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'yps-toast';
            el.style.cssText = 'position:fixed;bottom:20px;right:20px;padding:12px 20px;border-radius:6px;font-size:0.85rem;z-index:10000;transition:opacity .3s;pointer-events:none;';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.style.background = type === 'error' ? '#7f1d1d' : '#064e3b';
        el.style.color = type === 'error' ? '#fca5a5' : '#34d399';
        el.style.opacity = '1';
        clearTimeout(el._tid);
        el._tid = setTimeout(() => el.style.opacity = '0', 4000);
    }

    function generateSlug(title) {
        return title.toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-{2,}/g, '-')
            .replace(/^-|-$/g, '')
            .substring(0, 60);
    }

    /* ---- Card Grid ---- */

    async function loadPlaylists() {
        const grid = document.getElementById('yps-grid');
        grid.innerHTML = '<div class="yps-empty">Loading...</div>';
        try {
            const data = await apiGet('list_playlists');
            if (!data.ok) throw new Error(data.error || 'Unknown');
            _playlists = data.playlists || [];
            renderCards();
        } catch (e) {
            grid.innerHTML = `<div class="yps-empty" style="color:#f87171">${esc(e.message)}</div>`;
        }
    }

    function renderCards() {
        const grid = document.getElementById('yps-grid');
        let html = '';

        for (const p of _playlists) {
            const type = typeOf(p);
            const isVideo = (type === 'video');
            const badge = isVideo
                ? '<span class="yps-badge yps-badge-video">▶️ VIDEO</span>'
                : type === 'channel'
                    ? `<span class="yps-badge yps-badge-channel">📺 ${p.is_topic ? 'TOPIC' : 'CHANNEL'}</span>`
                    : '<span class="yps-badge yps-badge-playlist">📋 PLAYLIST</span>';
            // FULL ids, never truncated. The playlist id used to be chopped to
            // 16 chars + "…" here and then ellipsis-clipped by the CSS on top,
            // so the one value you actually need to check or copy was the one
            // value you could not read. Tom, 2026-08-18: "the YouTube links,
            // URLs are obfuscated."
            let meta;
            if (isVideo) {
                meta = `<div class="yps-card-meta"><span class="yps-k">Video ID</span> <span class="yps-v">${esc(p.video_id || 'Not set')}</span></div>
                   <div class="yps-card-meta"><span class="yps-k">Aspect</span> <span class="yps-v">${esc(p.aspect_ratio || '16/9')}</span></div>`;
            } else if (type === 'channel') {
                meta = `<div class="yps-card-meta"><span class="yps-k">Channel ID</span> <span class="yps-v">${esc(p.channel_id || 'Not set')}</span></div>
                   <div class="yps-card-meta"><span class="yps-k">Uploads feed</span> <span class="yps-v">${esc(uploadsFor(p) || 'Not set')}</span></div>`;
            } else {
                meta = `<div class="yps-card-meta"><span class="yps-k">Playlist ID</span> <span class="yps-v">${esc(p.playlist_id || 'Not set')}</span></div>
                   <div class="yps-card-meta"><span class="yps-k">Per page</span> <span class="yps-v">${p.per_page || 24} &middot; ${p.columns || 4} cols</span></div>`;
            }
            const sc = shortcodeFor(type, esc(p.slug));
            html += `<div class="yps-card" onclick="ytStudio.openModal('${esc(p.slug)}')">
                <div class="yps-card-header">${badge}</div>
                <div class="yps-card-title">${esc(p.title)}</div>
                ${meta}
                <code class="yps-card-shortcode">${sc}</code>
            </div>`;
        }

        html += `<div class="yps-card yps-card-new" onclick="ytStudio.openNewModal()">
            <span>+</span> New Entry
        </div>`;

        grid.innerHTML = html;
    }

    /**
     * Toggle visibility of mode-specific field groups based on current
     * type radio. Called on radio change + on modal open.
     */
    function applyTypeMode() {
        const type = currentType();
        const isVideo = (type === 'video');
        document.querySelectorAll('.yps-mode-video').forEach(el => el.hidden = !isVideo);
        // A channel renders THROUGH its uploads playlist, so it shares every grid,
        // player and playback control with a playlist — same markup, no second copy.
        document.querySelectorAll('.yps-mode-playlist').forEach(el => el.hidden = isVideo);
        // Update the shortcode hint
        const slug = document.getElementById('yps-f-slug').value || '...';
        document.getElementById('yps-shortcode-hint').textContent = shortcodeFor(type, slug);
    }

    /* ---- Modal ---- */

    function openModal(slug) {
        _isNew = false;
        _currentSlug = slug;
        const p = _playlists.find(x => x.slug === slug);
        if (!p) return;

        const type = typeOf(p);
        const isVideo = (type === 'video');
        setType(type);

        document.getElementById('yps-modal-title').textContent = `Edit: ${p.title}`;
        document.getElementById('yps-f-title').value = p.title || '';
        document.getElementById('yps-f-slug').value = p.slug || '';
        document.getElementById('yps-f-slug').readOnly = true;
        // Playlist fields
        document.getElementById('yps-f-api-key').value = p.api_key || '';
        document.getElementById('yps-f-playlist-id').value = p.playlist_id || '';
        document.getElementById('yps-f-per-page').value = p.per_page || 24;
        document.getElementById('yps-f-max-pull').value = p.max_pull || 50;
        document.getElementById('yps-f-columns').value = p.columns || 4;
        var _ps = p.player_max || 100;
        document.getElementById('yps-f-player-size').value = _ps;
        var _psv = document.getElementById('yps-f-player-size-val'); if (_psv) _psv.textContent = _ps + '%';
        document.getElementById('yps-f-grid-match').checked = !!p.grid_match;
        document.getElementById('yps-f-card-gap').value = (p.card_gap != null ? p.card_gap : 10);
        // Playback — auto_advance defaults ON for records saved before the field existed.
        document.getElementById('yps-f-auto-advance').checked = (p.auto_advance !== false);
        document.getElementById('yps-f-loop-playlist').checked = !!p.loop_playlist;
        // Channel fields
        document.getElementById('yps-f-channel-id').value  = p.channel_id || '';
        document.getElementById('yps-f-channel-url').value = p.channel_id ? `https://www.youtube.com/channel/${p.channel_id}` : '';
        document.getElementById('yps-channel-resolve-status').textContent =
            p.channel_id ? `Current: ${p.channel_id}${p.is_topic ? ' (Topic channel)' : ''}` : '';
        _chMeta = { title: p.channel_title || '', handle: p.channel_handle || '', is_topic: !!p.is_topic };
        // Video fields
        document.getElementById('yps-f-video-url').value = p.video_id ? `https://www.youtube.com/watch?v=${p.video_id}` : '';
        document.getElementById('yps-f-video-desc').value = p.video_description || '';
        document.getElementById('yps-f-aspect').value = p.aspect_ratio || '16/9';
        document.getElementById('yps-video-resolve-status').textContent = p.video_id ? `Current: ${p.video_id}` : '';
        renderVideoPreview(isVideo ? p.video_id : '', p.video_title || p.title, p.video_thumbnail);
        // Common
        document.getElementById('yps-f-show-desc').checked = !!p.show_description;
        document.getElementById('yps-btn-delete').style.display = '';
        document.getElementById('yps-preview-grid').innerHTML = '';
        document.getElementById('yps-preview-status').textContent = '';
        resetSmart();

        // Show the source URL this record resolves to. resetSmart() blanks the
        // field, and nothing ever refilled it — so reopening a saved entry
        // presented an empty "YouTube URL" box at the top of the modal and it
        // looked as though the link had never been stored. It always had been.
        const srcUrl = sourceUrlFor(p);
        document.getElementById('yps-f-smart-url').value = srcUrl;
        // And SAY which case you are in. A silently blank box is the whole
        // complaint: it reads as "the link is missing" when sometimes the link
        // genuinely was never stored (crack-in-the-frame has no playlist_id).
        const smartStat = document.getElementById('yps-smart-status');
        if (smartStat) {
            if (srcUrl) {
                smartStat.style.color = '';
                smartStat.innerHTML = 'Current source &mdash; <a href="' + escAttr(srcUrl) +
                    '" target="_blank" rel="noopener noreferrer">open on YouTube</a>. ' +
                    'Paste a different URL and press Resolve to repoint this entry.';
            } else {
                smartStat.style.color = '#fbbf24';
                smartStat.textContent = 'No source URL stored for this entry yet — paste one and press Resolve.';
            }
        }
        renderStoredData(p);

        applyTypeMode();
        document.getElementById('yps-overlay').classList.add('open');
    }

    /** Canonical YouTube URL for a stored record ('' when it has no source yet). */
    function sourceUrlFor(p) {
        if (!p) return '';
        if (typeOf(p) === 'channel') {
            return p.channel_id ? 'https://www.youtube.com/channel/' + p.channel_id : '';
        }
        if (p.type === 'video' || (p.video_id && !p.playlist_id)) {
            return p.video_id ? 'https://www.youtube.com/watch?v=' + p.video_id : '';
        }
        return p.playlist_id ? 'https://www.youtube.com/playlist?list=' + p.playlist_id : '';
    }

    /**
     * Read-back of what is actually on disk for this record, including the
     * data the YouTube API handed back when it was saved. Nothing here is
     * privileged — it all arrives in the same list response that drew the card.
     */
    function renderStoredData(p) {
        const box = document.getElementById('yps-stored-data');
        if (!box) return;
        if (!p) { box.hidden = true; box.innerHTML = ''; return; }

        const rows = [];
        const add = (k, v, opts) => {
            if (v === undefined || v === null || v === '') return;
            rows.push({ k: k, v: String(v), mono: !!(opts && opts.mono), link: (opts && opts.link) || '' });
        };
        add('Type', p.type || 'playlist');
        add('Playlist ID', p.playlist_id, { mono: true, link: p.playlist_id ? 'https://www.youtube.com/playlist?list=' + p.playlist_id : '' });
        add('Channel ID', p.channel_id, { mono: true, link: p.channel_id ? 'https://www.youtube.com/channel/' + p.channel_id : '' });
        add('Channel title', p.channel_title);
        add('Handle', p.channel_handle, { link: p.channel_handle ? 'https://www.youtube.com/' + p.channel_handle : '' });
        if (typeOf(p) === 'channel') {
            add('Topic channel', p.is_topic ? 'yes — auto-generated for a distributor release feed' : 'no');
            add('Uploads feed (derived, not stored)', uploadsFor(p), { mono: true, link: uploadsFor(p) ? 'https://www.youtube.com/playlist?list=' + uploadsFor(p) : '' });
        }
        add('Video ID', p.video_id, { mono: true, link: p.video_id ? 'https://www.youtube.com/watch?v=' + p.video_id : '' });
        add('Video title', p.video_title);
        add('Thumbnail', p.video_thumbnail, { mono: true, link: p.video_thumbnail });
        add('API key', p.api_key ? p.api_key : '(site default from Settings)', { mono: !!p.api_key });
        add('Aspect', p.aspect_ratio);
        add('Created', p.created);
        add('Updated', p.updated);

        // The whole point of this panel is the un-set case: say so plainly
        // rather than rendering a blank strip.
        const missing = (p.type === 'video') ? !p.video_id : !p.playlist_id;
        const warn = missing
            ? '<div class="yps-stored-warn">⚠ No ' + (p.type === 'video' ? 'video ID' : 'playlist ID') +
              ' stored — this entry renders nothing on a page until you set one above.</div>'
            : '';

        box.hidden = false;
        box.innerHTML =
            '<div class="yps-stored-head">Stored for this entry</div>' + warn +
            '<dl class="yps-stored-list">' + rows.map(function (r) {
                const href = safeUrl(r.link);
                const val = href
                    ? '<a href="' + escAttr(href) + '" target="_blank" rel="noopener noreferrer">' + esc(r.v) + '</a>'
                    : esc(r.v);
                return '<dt>' + esc(r.k) + '</dt><dd' + (r.mono ? ' class="yps-mono"' : '') + '>' + val + '</dd>';
            }).join('') + '</dl>';
    }

    function openNewModal() {
        _isNew = true;
        _currentSlug = null;

        document.getElementById('yps-f-type-playlist').checked = true;
        document.getElementById('yps-f-type-video').checked = false;

        document.getElementById('yps-modal-title').textContent = 'New Entry';
        document.getElementById('yps-f-title').value = '';
        document.getElementById('yps-f-slug').value = '';
        document.getElementById('yps-f-slug').readOnly = false;
        document.getElementById('yps-f-api-key').value = '';
        document.getElementById('yps-f-playlist-id').value = '';
        document.getElementById('yps-f-per-page').value = '24';
        document.getElementById('yps-f-max-pull').value = '50';
        document.getElementById('yps-f-columns').value = '4';
        document.getElementById('yps-f-player-size').value = '100';
        var _psr = document.getElementById('yps-f-player-size-val'); if (_psr) _psr.textContent = '100%';
        document.getElementById('yps-f-grid-match').checked = false;
        document.getElementById('yps-f-card-gap').value = '10';
        document.getElementById('yps-f-auto-advance').checked = true;
        document.getElementById('yps-f-loop-playlist').checked = false;
        document.getElementById('yps-f-channel-url').value = '';
        document.getElementById('yps-f-channel-id').value = '';
        document.getElementById('yps-channel-resolve-status').textContent = '';
        _chMeta = { title: '', handle: '', is_topic: false };
        document.getElementById('yps-f-video-url').value = '';
        document.getElementById('yps-f-video-desc').value = '';
        document.getElementById('yps-f-aspect').value = '16/9';
        document.getElementById('yps-video-resolve-status').textContent = '';
        renderVideoPreview('');
        document.getElementById('yps-f-show-desc').checked = false;
        document.getElementById('yps-shortcode-hint').textContent = '[[youtube-playlist:...]]';
        document.getElementById('yps-btn-delete').style.display = 'none';
        document.getElementById('yps-preview-grid').innerHTML = '';
        document.getElementById('yps-preview-status').textContent = '';
        resetSmart();
        renderStoredData(null);   // nothing on disk yet for a new entry

        applyTypeMode();
        document.getElementById('yps-overlay').classList.add('open');

        // Auto-slug from title
        const titleInput = document.getElementById('yps-f-title');
        const slugInput = document.getElementById('yps-f-slug');
        titleInput.oninput = () => {
            if (_isNew && !slugInput.readOnly) {
                slugInput.value = generateSlug(titleInput.value);
                applyTypeMode();
            }
        };
    }

    /**
     * Paint a single-video preview (thumbnail + title) into the video-mode modal,
     * mirroring the playlist "Load Preview" grid. Pass a falsy videoId to clear.
     */
    function renderVideoPreview(videoId, title, thumb) {
        const pv = document.getElementById('yps-video-preview');
        if (!pv) return;
        if (!videoId) { pv.hidden = true; pv.innerHTML = ''; return; }
        const src = thumb || `https://img.youtube.com/vi/${videoId}/hqdefault.jpg`;
        pv.hidden = false;
        pv.innerHTML = `<div class="yps-preview-item">
            <img src="${escAttr(src)}" alt="" loading="lazy">
            <div class="yps-pv-title">${esc(title || videoId)}</div>
        </div>`;
    }

    /**
     * Resolve a YouTube video URL into video_id + title via noembed.
     * Auto-fills the title field when blank, and stamps a status line.
     */
    async function resolveVideo() {
        const urlEl = document.getElementById('yps-f-video-url');
        const statusEl = document.getElementById('yps-video-resolve-status');
        const titleEl = document.getElementById('yps-f-title');
        const slugEl = document.getElementById('yps-f-slug');

        const url = urlEl.value.trim();
        if (!url) { toast('Paste a YouTube URL first', 'error'); return; }

        statusEl.textContent = 'Resolving...';
        try {
            const data = await apiGet('resolve_video', { url });
            if (!data.ok) throw new Error(data.error || 'Resolve failed');
            statusEl.textContent = `✓ Video ID: ${data.video_id}` + (data.title ? ` — "${data.title}"` : '');
            statusEl.style.color = '';
            renderVideoPreview(data.video_id, data.title, data.thumbnail);
            // Auto-fill title + slug only when both are blank (don't trample edits)
            if (!titleEl.value.trim() && data.title) {
                titleEl.value = data.title;
                if (_isNew && !slugEl.readOnly) {
                    slugEl.value = generateSlug(data.title);
                    applyTypeMode();
                }
            }
            toast(`Resolved: ${data.video_id}`);
        } catch (e) {
            statusEl.textContent = `Error: ${e.message}`;
            statusEl.style.color = '#f87171';
            renderVideoPreview('');
            toast(e.message, 'error');
        }
    }

    /**
     * ⭐ Smart Add: paste ANY YouTube URL → server detects playlist vs single,
     * flips the type, auto-fills the fields, and paints a preview. The API key
     * stays server-side. User never has to pick a type.
     */
    async function smartResolve() {
        const urlEl    = document.getElementById('yps-f-smart-url');
        const statusEl = document.getElementById('yps-smart-status');
        const pvEl     = document.getElementById('yps-smart-preview');
        const titleEl  = document.getElementById('yps-f-title');
        const slugEl   = document.getElementById('yps-f-slug');

        const url = (urlEl.value || '').trim();
        if (!url) { toast('Paste a YouTube URL first', 'error'); return; }

        statusEl.style.color = '';
        statusEl.textContent = 'Resolving…';
        pvEl.hidden = true; pvEl.innerHTML = '';

        try {
            // Pass the Advanced API-key field along: sites with no Settings default
            // otherwise can never resolve a playlist (the Studio has no Settings UI).
            const advKey = (document.getElementById('yps-f-api-key')?.value || '').trim();
            const data = await apiGet('smart_resolve', advKey ? { url, api_key: advKey } : { url });
            if (!data.ok) throw new Error(data.error || 'Resolve failed');

            const kind = (data.kind === 'video' || data.kind === 'channel') ? data.kind : 'playlist';
            const isVideo = (kind === 'video');
            setType(kind);
            applyTypeMode();

            if (kind === 'channel') {
                document.getElementById('yps-f-channel-id').value = data.channel_id || '';
                document.getElementById('yps-f-channel-url').value = data.channel_id
                    ? `https://www.youtube.com/channel/${data.channel_id}` : url;
                _chMeta = { title: data.title || '', handle: data.handle || '', is_topic: !!data.is_topic };
                document.getElementById('yps-channel-resolve-status').textContent =
                    `✓ ${data.channel_id}${data.is_topic ? ' (Topic channel)' : ''}`;

                const label = data.is_topic ? '📺 Topic channel detected' : '📺 Channel detected';
                if (data.no_key) {
                    statusEl.style.color = '#fbbf24';
                    statusEl.textContent = `${label} — ` + (data.note || 'no API key set; it will render as a player.');
                } else {
                    statusEl.style.color = '';
                    statusEl.textContent = `${label} — ${data.count} recent upload${data.count !== 1 ? 's' : ''}`
                        + (data.title ? ` — "${data.title}"` : '');
                }
                pvEl.hidden = false;
                pvEl.innerHTML = (data.items || []).map(it =>
                    `<div class="yps-preview-item">
                        <img src="${escAttr(it.thumbnail)}" alt="" loading="lazy">
                        <div class="yps-pv-title">${esc(it.title)}</div>
                    </div>`).join('')
                    || `<div class="yps-hint">Uploads feed <code>${esc(data.uploads_playlist || '')}</code> — this is what will render. Add an API key to preview the videos here.</div>`;
            } else if (isVideo) {
                document.getElementById('yps-f-video-url').value = data.video_id
                    ? `https://www.youtube.com/watch?v=${data.video_id}` : url;
                document.getElementById('yps-video-resolve-status').textContent =
                    data.video_id ? `✓ ${data.video_id}` : '';
                statusEl.textContent = '▶️ Single detected' + (data.title ? ` — "${data.title}"` : '');
                pvEl.hidden = false;
                pvEl.innerHTML = `<div class="yps-preview-item">
                    <img src="${escAttr(data.thumbnail || 'https://img.youtube.com/vi/' + data.video_id + '/hqdefault.jpg')}" alt="" loading="lazy">
                    <div class="yps-pv-title">${esc(data.title || data.video_id)}</div>
                </div>`;
            } else {
                document.getElementById('yps-f-playlist-id').value = data.playlist_id || '';
                if (data.no_key) {
                    // No key is a valid, supported state — say what you get, not what failed.
                    statusEl.style.color = '#fbbf24';
                    statusEl.textContent = '📋 Playlist detected — ' + (data.note || 'no API key set; it will render as a player.');
                } else {
                    statusEl.style.color = '';
                    statusEl.textContent = `📋 Playlist detected — ${data.count} video${data.count !== 1 ? 's' : ''}`
                        + (data.title ? ` — "${data.title}"` : '');
                }
                pvEl.hidden = false;
                pvEl.innerHTML = (data.items || []).map(it =>
                    `<div class="yps-preview-item">
                        <img src="${escAttr(it.thumbnail)}" alt="" loading="lazy">
                        <div class="yps-pv-title">${esc(it.title)}</div>
                    </div>`).join('');
            }

            // Auto-fill title + slug only when blank (never trample a manual edit)
            if (!titleEl.value.trim() && data.title) {
                titleEl.value = data.title;
                if (_isNew && !slugEl.readOnly) slugEl.value = generateSlug(data.title);
            }
            applyTypeMode(); // refresh shortcode hint with the (possibly new) slug
            toast(kind === 'video' ? 'Single video detected'
                : kind === 'channel' ? (data.is_topic ? 'Topic channel detected' : 'Channel detected')
                : 'Playlist detected');
        } catch (e) {
            statusEl.textContent = `Error: ${e.message}`;
            statusEl.style.color = '#f87171';
            toast(e.message, 'error');
        }
    }

    /**
     * Resolve just the Channel field in the Source fieldset. Same endpoint as Smart Add,
     * but it refuses a non-channel URL here rather than silently switching the record
     * type out from under a field labelled "Channel".
     */
    async function resolveChannel() {
        const el       = document.getElementById('yps-f-channel-url');
        const statusEl = document.getElementById('yps-channel-resolve-status');
        const url = (el.value || '').trim();
        if (!url) { toast('Paste a channel URL first', 'error'); return; }

        statusEl.style.color = '';
        statusEl.textContent = 'Resolving…';
        try {
            const data = await apiGet('smart_resolve', { url });
            if (!data.ok) throw new Error(data.error || 'Resolve failed');
            if (data.kind !== 'channel') {
                throw new Error(`That URL resolves to a ${data.kind}, not a channel — paste it into Smart Add instead.`);
            }
            document.getElementById('yps-f-channel-id').value = data.channel_id || '';
            _chMeta = { title: data.title || '', handle: data.handle || '', is_topic: !!data.is_topic };
            setType('channel');

            statusEl.textContent = `✓ ${data.channel_id}${data.is_topic ? ' (Topic channel)' : ''}`
                + (data.title ? ` — ${data.title}` : '');

            const titleEl = document.getElementById('yps-f-title');
            const slugEl  = document.getElementById('yps-f-slug');
            if (!titleEl.value.trim() && data.title) {
                titleEl.value = data.title;
                if (_isNew && !slugEl.readOnly) slugEl.value = generateSlug(data.title);
            }
            applyTypeMode();
            toast(data.is_topic ? 'Topic channel resolved' : 'Channel resolved');
        } catch (e) {
            statusEl.style.color = '#f87171';
            statusEl.textContent = `Error: ${e.message}`;
            toast(e.message, 'error');
        }
    }

    /** Reset the Smart Add bar (status + preview + url). */
    function resetSmart() {
        const u = document.getElementById('yps-f-smart-url'); if (u) u.value = '';
        const s = document.getElementById('yps-smart-status'); if (s) { s.textContent = ''; s.style.color = ''; }
        const p = document.getElementById('yps-smart-preview'); if (p) { p.hidden = true; p.innerHTML = ''; }
    }

    function closeModal() {
        document.getElementById('yps-overlay').classList.remove('open');
        _currentSlug = null;
        _isNew = false;
        // Remove auto-slug handler
        document.getElementById('yps-f-title').oninput = null;
    }

    async function savePlaylist() {
        const type     = currentType();
        const isVideo  = (type === 'video');
        const title    = document.getElementById('yps-f-title').value.trim();
        const slug     = document.getElementById('yps-f-slug').value.trim();
        const showDesc = document.getElementById('yps-f-show-desc').checked;

        if (!title) { toast('Title is required', 'error'); return; }
        if (!slug)  { toast('Slug is required', 'error'); return; }

        let body;
        if (isVideo) {
            const videoUrl = document.getElementById('yps-f-video-url').value.trim();
            const videoDesc = document.getElementById('yps-f-video-desc').value;
            const aspect = document.getElementById('yps-f-aspect').value;
            if (!videoUrl) { toast('YouTube video URL is required', 'error'); return; }
            body = {
                type: 'video',
                title, slug,
                video_url: videoUrl,
                video_description: videoDesc,
                aspect_ratio: aspect,
                show_description: showDesc,
            };
        } else {
            const apiKey     = document.getElementById('yps-f-api-key').value.trim();
            const playlistId = document.getElementById('yps-f-playlist-id').value.trim();
            const perPage    = parseInt(document.getElementById('yps-f-per-page').value, 10) || 24;
            const maxPull    = parseInt(document.getElementById('yps-f-max-pull').value, 10) || 50;
            const columns    = parseInt(document.getElementById('yps-f-columns').value, 10) || 4;
            const playerMax  = parseInt(document.getElementById('yps-f-player-size').value, 10) || 100;
            const gridMatch  = document.getElementById('yps-f-grid-match').checked;
            const cgVal      = parseInt(document.getElementById('yps-f-card-gap').value, 10);
            const cardGap    = isNaN(cgVal) ? 10 : cgVal;
            const autoAdvance = document.getElementById('yps-f-auto-advance').checked;
            const loopAll     = document.getElementById('yps-f-loop-playlist').checked;
            body = {
                type: 'playlist',
                title, slug,
                api_key: apiKey, playlist_id: playlistId,
                per_page: perPage, max_pull: maxPull, columns, player_max: playerMax, grid_match: gridMatch, card_gap: cardGap,
                show_description: showDesc,
                auto_advance: autoAdvance, loop_playlist: loopAll,
            };
            // A channel reuses every display/playback field above — it differs only in
            // WHAT it points at, so only the source keys change.
            if (type === 'channel') {
                const chanId  = document.getElementById('yps-f-channel-id').value.trim();
                const chanUrl = document.getElementById('yps-f-channel-url').value.trim();
                if (!chanId && !chanUrl) {
                    toast('Channel URL or ID is required — press Resolve', 'error');
                    return;
                }
                body.type           = 'channel';
                body.channel_id     = chanId;
                body.channel_url    = chanUrl;
                body.channel_title  = _chMeta.title;
                body.channel_handle = _chMeta.handle;
                body.is_topic       = _chMeta.is_topic;
                delete body.playlist_id;
            }
        }
        if (_isNew) body._creating = true;

        try {
            const data = await apiPost('save_playlist', body);
            if (!data.ok) throw new Error(data.error || 'Save failed');
            toast(data.created ? `Created "${title}"` : `Saved "${title}"`);
            closeModal();
            loadPlaylists();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    async function deletePlaylist() {
        const slug = _currentSlug;
        if (!slug) return;
        if (!confirm(`Delete playlist "${slug}"?\n\nThis cannot be undone.`)) return;

        try {
            const data = await apiPost('delete_playlist', { slug });
            if (!data.ok) throw new Error(data.error || 'Delete failed');
            toast(`Deleted "${slug}"`);
            closeModal();
            loadPlaylists();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    async function loadPreview() {
        const apiKey     = document.getElementById('yps-f-api-key').value.trim();
        const statusEl   = document.getElementById('yps-preview-status');
        const gridEl     = document.getElementById('yps-preview-grid');

        // A channel previews through its uploads feed — the same playlist the public
        // renderer derives, so the preview shows what the page will actually show.
        let playlistId;
        if (currentType() === 'channel') {
            const cid = document.getElementById('yps-f-channel-id').value.trim();
            playlistId = /^UC[A-Za-z0-9_-]{22}$/.test(cid) ? 'UU' + cid.slice(2) : '';
            if (!apiKey || !playlistId) {
                toast('Resolve the channel first, and set an API key to list its videos', 'error');
                return;
            }
        } else {
            playlistId = document.getElementById('yps-f-playlist-id').value.trim();
            if (!apiKey || !playlistId) {
                toast('Enter API Key and Playlist ID first', 'error');
                return;
            }
        }

        statusEl.textContent = 'Fetching...';
        gridEl.innerHTML = '';

        try {
            const data = await apiGet('preview_playlist', { api_key: apiKey, playlist_id: playlistId });
            if (!data.ok) throw new Error(data.error || 'Preview failed');

            statusEl.textContent = `${data.count} video${data.count !== 1 ? 's' : ''} found`;
            gridEl.innerHTML = (data.items || []).map(it =>
                `<div class="yps-preview-item">
                    <img src="${escAttr(it.thumbnail)}" alt="" loading="lazy">
                    <div class="yps-pv-title">${esc(it.title)}</div>
                </div>`
            ).join('');
        } catch (e) {
            statusEl.textContent = `Error: ${e.message}`;
            statusEl.style.color = '#f87171';
        }
    }

    /* ---- Init ---- */
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('yps-grid')) loadPlaylists();
        // Wire type radio change → re-apply mode visibility + shortcode hint
        document.querySelectorAll('input[name="yps-f-type"]').forEach(r => {
            r.addEventListener('change', applyTypeMode);
        });
        // Slug input → live shortcode hint update
        document.getElementById('yps-f-slug')?.addEventListener('input', applyTypeMode);
        // Enter in the Smart Add bar triggers resolve
        document.getElementById('yps-f-smart-url')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); smartResolve(); }
        });
    });

    return {
        loadPlaylists, openModal, openNewModal, closeModal,
        savePlaylist, deletePlaylist, loadPreview, resolveVideo, resolveChannel, smartResolve,
    };
})();
