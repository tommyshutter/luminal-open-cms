/**
 * @location    SITE-ROOT/admin/background_mgr/background_mgr.js
 * @version     2025.07.30.60 (Final & Complete)
 * @description The definitive, unified code. All AJAX functions are present, and the
 * preview logic is now fully robust for all modes, including Slideshow.
 * @change: Corrected the fetch URL in savePlaylist to point to the correct file.
 */
document.addEventListener('DOMContentLoaded', () => {

    const elements = {
        messagePanel: document.getElementById('admin-message-panel'),
        rescanBtn: document.getElementById('rescan-media-btn'),
        previewPanel: document.getElementById('background-preview-panel'),
        youtubeInput: document.getElementById('youtube-url-input'),
        addYoutubeBtn: document.getElementById('add-youtube-btn'),
        saveMasterBtn: document.getElementById('save-master-settings-btn'),
        overlayColor: document.getElementById('overlay-color-input'),
        overlayOpacity: document.getElementById('overlay-opacity-slider'),
        opacityValueSpan: document.getElementById('opacity-value'),
        modeRadios: document.querySelectorAll('input[name="active_mode"]'),
        sizingRadios: document.querySelectorAll('input[name="background_sizing"]'),
        activePlaylistSelect: document.getElementById('active-playlist-select'),
        imageGrid: document.getElementById('image-grid'),
        videoGrid: document.getElementById('video-grid'),
        slideshowTray: document.getElementById('slideshow-tray'),
        playlistSelect: document.getElementById('playlist-select'),
        playlistNameInput: document.getElementById('playlist-name-input'),
        savePlaylistBtn: document.getElementById('save-playlist-btn'),
        deletePlaylistBtn: document.getElementById('delete-playlist-btn'),
        clearTrayBtn: document.getElementById('clear-tray-btn'),
        mainColumn: document.querySelector('.bgm-main-column'),
    };

    /* ─── Sub-panel visibility ──────────────────────────────────────────
     * Master is always visible. The video / image / slideshow panels
     * inline below Master and only the one matching Active Mode is shown.
     * Replaces the old tab-nav workspace — one mode, one save flow.
     */
    function bgmShowModePanel(mode) {
        document.querySelectorAll('.bgm-mode-panel').forEach(panel => {
            const isTarget = panel.getAttribute('data-bgm-mode') === mode;
            panel.classList.toggle('is-active', isTarget);
            if (isTarget) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
        });
    }
    /* Back-compat: older callers (Clear Cache) looked up bgmSwitchTab */
    window.bgmSwitchTab = function(name) { bgmShowModePanel(name); };

    let previewSlideshowInterval = null;
    /* The playlist currently LOADED IN THE EDITOR. Distinct from the Master
     * panel's Active Playlist, which is what the SITE serves. Picking a
     * playlist to edit used to touch neither the preview nor anything else
     * visible, so the editor looked inert. */
    let editingPlaylistName = '';

    function showMessage(type, message, permanent = false) {
        if (!elements.messagePanel) return;
        elements.messagePanel.className = `admin-message-panel ${type}`;
        elements.messagePanel.innerHTML = message;
        elements.messagePanel.style.display = 'block';
        if (!permanent) {
             setTimeout(() => {
                if (elements.messagePanel.innerHTML === message) {
                    elements.messagePanel.style.display = 'none';
                }
             }, 7000);
        }
    }

    function initialize() {
        if (typeof initialBgData === 'undefined' || !initialBgData) {
            showMessage('error', 'Failed to load background configuration data.', true);
            return;
        }
        if (initialBgData.errors && initialBgData.errors.length > 0) {
            const errorHtml = "<strong>System Status Report:</strong><ul>" + initialBgData.errors.map(e => `<li>${e}</li>`).join('') + "</ul>";
            showMessage('warning', errorHtml, true);
        } else {
            showMessage('info', 'Welcome to the Background Manager. All systems nominal.');
        }

        /* Reflect persisted filter/sort state into the pulldowns before
         * first render so the visible pulldown matches the rendered grid. */
        const vFilter = document.getElementById('video-filter-source');
        const vSort   = document.getElementById('video-sort');
        const iSort   = document.getElementById('image-sort');
        if (vFilter && fs.video.filter) vFilter.value = fs.video.filter;
        if (vSort   && fs.video.sort)   vSort.value   = fs.video.sort;
        if (iSort   && fs.image.sort)   iSort.value   = fs.image.sort;

        renderImageGrid();
        renderVideoGrid();
        /* Slideshow tab's inline library — read-only clones of the Image
         * and Video galleries, drag-enabled into the tray. The Slideshow
         * editor needs both libraries visible in one workspace; this
         * restores parity with the old accordion behavior without making
         * the user tab-hop. */
        populateSlideshowLibrary();
        initializeSortable();
        setupEventListeners();
        setupDropzones();

        const settings = initialBgData.settings || {};
        const modeRadio = document.querySelector(`input[name="active_mode"][value="${settings.active_mode || 'image'}"]`);
        if (modeRadio) modeRadio.checked = true;
        const sizingRadio = document.querySelector(`input[name="background_sizing"][value="${settings.background_sizing || 'cover'}"]`);
        if (sizingRadio) sizingRadio.checked = true;
        if (settings.overlay?.color_rgba) {
            const m = settings.overlay.color_rgba.match(/rgba?\((\d+),(\d+),(\d+),([\d.]+)\)/);
            if (m && elements.overlayColor) {
                elements.overlayColor.value = '#' + [m[1],m[2],m[3]].map(c => parseInt(c).toString(16).padStart(2,'0')).join('');
            }
            if (m && elements.overlayOpacity) {
                elements.overlayOpacity.value = m[4];
                if (elements.opacityValueSpan) elements.opacityValueSpan.textContent = m[4];
            }
        }
        if (settings.slideshow?.playlist_name && elements.activePlaylistSelect) {
            elements.activePlaylistSelect.value = settings.slideshow.playlist_name;
        }
        if (settings.slideshow?.duration) {
            const durSelect = document.getElementById('playlist-duration-select');
            if (durSelect) durSelect.value = settings.slideshow.duration;
        }
        handleModeChange();
    }

    function setupEventListeners() {
        elements.rescanBtn.addEventListener('click', rescanMedia);
        elements.addYoutubeBtn.addEventListener('click', addYoutubeToGallery);
        elements.modeRadios.forEach(radio => radio.addEventListener('change', handleModeChange));
        elements.sizingRadios.forEach(radio => radio.addEventListener('change', updatePreview));
        elements.activePlaylistSelect.addEventListener('change', () => {
            editingPlaylistName = '';   // Master pick wins once it is touched
            updatePreview();
        });
        elements.overlayColor.addEventListener('input', updatePreview);
        elements.overlayOpacity.addEventListener('input', () => {
            elements.opacityValueSpan.textContent = elements.overlayOpacity.value;
            updatePreview();
        });
        /* Delegated selection handler (thumb clicks). Fires across the
         * mode panels' media grids and the slideshow tray. */
        const delegationRoot = elements.mainColumn || document.querySelector('.bgm-workspace');
        if (delegationRoot) delegationRoot.addEventListener('click', handleSelection);
        elements.saveMasterBtn.addEventListener('click', saveMasterSettings);
        elements.savePlaylistBtn.addEventListener('click', () => savePlaylist('save'));
        elements.deletePlaylistBtn.addEventListener('click', () => savePlaylist('delete'));
        elements.clearTrayBtn.addEventListener('click', () => { elements.slideshowTray.innerHTML = ''; updatePreview(); });
        elements.playlistSelect.addEventListener('change', loadPlaylistIntoTray);
        elements.youtubeInput.addEventListener('input', handleYoutubeInput);

        /* Slideshow library filter pulldown (fc-select). CSS-only show/hide
         * of the two library sections; no re-render needed. */
        const libFilter = document.getElementById('slideshow-library-filter');
        if (libFilter) libFilter.addEventListener('change', applySlideshowLibraryFilter);

        /* Video tab filter + sort pulldowns */
        const videoFilter = document.getElementById('video-filter-source');
        const videoSort   = document.getElementById('video-sort');
        if (videoFilter) videoFilter.addEventListener('change', () => {
            fs.video.filter = videoFilter.value; saveFS(); renderVideoGrid();
        });
        if (videoSort) videoSort.addEventListener('change', () => {
            fs.video.sort = videoSort.value; saveFS(); renderVideoGrid();
        });

        /* Image tab sort pulldown (filter deferred — only "all" makes sense
         * until we add tags or folders to the image data model) */
        const imageSort = document.getElementById('image-sort');
        if (imageSort) imageSort.addEventListener('change', () => {
            fs.image.sort = imageSort.value; saveFS(); renderImageGrid();
        });
    }
    
    function handleModeChange() {
        const activeMode = document.querySelector('input[name="active_mode"]:checked').value;
        bgmShowModePanel(activeMode);
        updatePreview();
    }

    // **THE DEFINITIVE FIX IS HERE**
    function updatePreview() {
        clearInterval(previewSlideshowInterval);
        elements.previewPanel.innerHTML = '';
        
        const activeMode = document.querySelector('input[name="active_mode"]:checked').value;
        const sizing = document.querySelector('input[name="background_sizing"]:checked').value;
        
        const overlay = document.createElement('div');
        overlay.id = 'preview-overlay';
        overlay.style.backgroundColor = `rgba(${hexToRgb(elements.overlayColor.value)}, ${elements.overlayOpacity.value})`;
        elements.previewPanel.appendChild(overlay);

        const createPlaceholder = (text) => {
            const p = document.createElement('p');
            p.className = 'panel-placeholder-text';
            p.textContent = text;
            elements.previewPanel.appendChild(p);
        };
        
        const selectedThumb = document.querySelector('.media-thumb.selected');
        const youtubeUrl = elements.youtubeInput.value.trim();
        
        switch (activeMode) {
            case 'image': {
                const source = selectedThumb ? selectedThumb.dataset.optimized : initialBgData.settings.single.image_url;
                renderPreviewElement(source, 'image', sizing);
                break;
            }
            case 'video': {
                let source, type;
                if (youtubeUrl) {
                    source = youtubeUrl;
                    type = 'youtube';
                } else if (selectedThumb) {
                    source = selectedThumb.dataset.path;
                    type = selectedThumb.dataset.type;
                } else {
                    const saved = initialBgData.settings.video;
                    source = saved.url || (saved.youtube_id ? `https://www.youtube.com/watch?v=${saved.youtube_id}` : null);
                    type = saved.source_type;
                }
                renderPreviewElement(source, type, sizing);
                break;
            }
            case 'slideshow': {
                /* Show what the operator is looking at. While a playlist is loaded in
                 * the editor the TRAY is the live truth — it carries drags and
                 * reorders that have not been saved yet — and the Master select
                 * governs only what the site serves. Previewing the Master pick while
                 * the operator edits a different playlist is what read as "the preview
                 * is not working". */
                if (editingPlaylistName) {
                    const traySlides = slidesFromTray();
                    if (traySlides.length) { startSlideshowPreview(traySlides, sizing); break; }
                    createPlaceholder('Editing "' + editingPlaylistName + '" — tray is empty. Drag media in from the library below.');
                    break;
                }
                const playlistName = elements.activePlaylistSelect.value;
                if (!playlistName) {
                    createPlaceholder('Slide Show Error: No active playlist selected.');
                    return;
                }
                const playlist = initialBgData.playlists.find(p => p.name === playlistName);
                if (playlist) {
                    const slides = playlist.slides || (playlist.images || []).map(p => ({ type: 'image', path: p }));
                    if (slides.length > 0) {
                        startSlideshowPreview(slides, sizing);
                    } else {
                        createPlaceholder('Slide Show Error: Selected playlist is empty.');
                    }
                } else {
                    createPlaceholder('Slide Show Error: Selected playlist cannot be found.');
                }
                break;
            }
        }
    }
    
    async function rescanMedia() {
        showMessage('info', 'Requesting a full media scan... Page will reload.');
        elements.rescanBtn.disabled = true;
        elements.rescanBtn.textContent = 'SCANNING...';
        setTimeout(() => location.reload(), 2000);
    }

    async function addYoutubeToGallery() {
        const youtubeUrl = elements.youtubeInput.value.trim();
        if (!youtubeUrl || !extractYouTubeId(youtubeUrl)) {
            showMessage('error', 'Please enter a valid YouTube URL first.');
            return;
        }
        showMessage('info', 'Adding video to gallery...');
        elements.addYoutubeBtn.disabled = true;
        try {
            const response = await fetch('/admin/modules/BackgroundManager/api/save_youtube_url_to_gallery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ youtube_url: youtubeUrl })
            });
            const data = await response.json();
            showMessage(data.success ? 'success' : 'error', data.message);
            if (data.success && !data.message.includes('already in your gallery')) {
                setTimeout(() => location.reload(), 2000);
            } else {
                elements.addYoutubeBtn.disabled = false;
            }
        } catch (error) {
            showMessage('error', 'A network error occurred while adding the video.');
            elements.addYoutubeBtn.disabled = false;
        }
    }
    
    function saveMasterSettings() {
        showMessage('info', 'Saving master settings...');
        const activeMode = document.querySelector('input[name="active_mode"]:checked').value;
        const selectedThumb = document.querySelector('.media-thumb.selected');
        const youtubeUrl = elements.youtubeInput.value.trim();
        let payload = {
            active_mode: activeMode,
            background_sizing: document.querySelector('input[name="background_sizing"]:checked').value,
            overlay: { color_rgba: `rgba(${hexToRgb(elements.overlayColor.value)},${elements.overlayOpacity.value})` },
            single: {}, video: {}, slideshow: {}
        };
        let isYoutubeSelected = (selectedThumb && selectedThumb.dataset.type === 'youtube') || youtubeUrl;
        switch (activeMode) {
            case 'image':
                payload.single.image_url = selectedThumb ? selectedThumb.dataset.optimized : initialBgData.settings.single.image_url;
                break;
            case 'video':
                if (isYoutubeSelected) {
                    payload.video.source_type = 'youtube';
                    payload.video.youtube_id = extractYouTubeId(youtubeUrl || selectedThumb.dataset.path);
                    payload.video.url = '';
                } else if (selectedThumb && selectedThumb.dataset.type === 'local_video') {
                    payload.video.source_type = 'local';
                    payload.video.url = selectedThumb.dataset.path;
                    payload.video.youtube_id = '';
                } else {
                    payload.video = initialBgData.settings.video;
                }
                break;
            case 'slideshow':
                 payload.slideshow.playlist_name = elements.activePlaylistSelect.value;
                 const durEl = document.getElementById('playlist-duration-select');
                 payload.slideshow.duration = parseInt(durEl?.value || '5000', 10);
                 payload.slideshow.transition = 1500;
                break;
        }
        fetch('/admin/modules/BackgroundManager/api/save_background_settings.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.new_settings) {
                initialBgData.settings = data.new_settings;
                showMessage('success', data.message);
                updatePreview();
            } else {
                showMessage('error', data.message || 'An unknown error occurred.');
            }
        }).catch(err => showMessage('error', 'Network or server error.'));
    }
    
    function savePlaylist(action) {
        const playlistName = elements.playlistNameInput.value.trim();
        if (!playlistName) {
            showMessage('error', `Please enter a playlist name to ${action}.`);
            return;
        }
        let payload = { action, name: playlistName };
        if (action === 'save') {
             const slideElements = elements.slideshowTray.querySelectorAll('.media-thumb');
             if (slideElements.length === 0) {
                 showMessage('error', 'Cannot save an empty playlist.'); return;
             }
             payload.slides = Array.from(slideElements).map(el => ({
                 type: (el.dataset.type === 'image') ? 'image' : 'video',
                 path: el.dataset.optimized || el.dataset.path,
                 source_path: el.dataset.path
             }));
             payload.images = payload.slides.filter(s => s.type === 'image').map(s => s.path);
             const durSelect = document.getElementById('playlist-duration-select');
             payload.duration = parseInt(durSelect?.value || '5000');
        }
        fetch('/admin/modules/BackgroundManager/api/save_slideshow_playlist.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) { showMessage('error', data.message); return; }
            if (action === 'delete') applyPlaylistDeleted(playlistName, data.message);
            else                     applyPlaylistSaved(playlistName, payload, data.message);
        })
        .catch(() => showMessage('error', 'Network or server error.'));
    }

    /* ── Durability ──────────────────────────────────────────────────────────
     * savePlaylist used to finish with location.reload(). That threw away every
     * unsaved Master edit sitting beside it — overlay colour, opacity, sizing,
     * the mode itself — so saving a playlist silently discarded your other work.
     * It also meant the page had to round-trip before either pulldown showed the
     * playlist you had just created. The three helpers below apply the result in
     * place instead, and say plainly what IS and IS NOT the live background.
     */

    /* Rebuild both playlist pulldowns from state. They are separate controls with
     * separate jobs: the editor's picks what you are EDITING, the Master's picks
     * what the SITE SERVES. */
    function refreshPlaylistSelects(editorName, activeName) {
        const names = (initialBgData.playlists || []).map(p => p.name).filter(Boolean);
        const fill = (sel, placeholder, want) => {
            if (!sel) return;
            sel.innerHTML = '';
            const blank = document.createElement('option');
            blank.value = '';
            blank.textContent = placeholder;
            sel.appendChild(blank);
            names.forEach(n => {
                const o = document.createElement('option');
                o.value = n;
                o.textContent = n;
                sel.appendChild(o);
            });
            sel.value = names.indexOf(want) !== -1 ? want : '';
        };
        fill(elements.playlistSelect,       '-- Start a New Playlist --', editorName);
        fill(elements.activePlaylistSelect, '-- None --',                 activeName);
    }

    /* Persist ONLY the active playlist. Sends the STORED sizing and overlay rather
     * than whatever the Master inputs happen to show, so adopting a playlist can
     * never quietly commit unsaved edits sitting in the panel beside it. */
    function persistActivePlaylist(name, message) {
        const stored = initialBgData.settings || {};
        const durEl = document.getElementById('playlist-duration-select');
        const body = {
            active_mode: 'slideshow',
            background_sizing: stored.background_sizing || 'cover',
            overlay: stored.overlay || { color_rgba: 'rgba(0,0,0,0.5)' },
            single: {}, video: {},
            slideshow: {
                playlist_name: name,
                duration: parseInt((durEl && durEl.value) || '5000', 10),
                transition: (stored.slideshow && stored.slideshow.transition) || 1500
            }
        };
        const fallback = message + ' Could not set it active — use Save Master Settings.';
        fetch('/admin/modules/BackgroundManager/api/save_background_settings.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
        })
        .then(r => r.json())
        .then(d => {
            if (d.success && d.new_settings) {
                initialBgData.settings = d.new_settings;
                showMessage('success', message + ' It is now the active background.');
            } else {
                showMessage('error', fallback);
            }
        })
        .catch(() => showMessage('error', fallback));
    }

    function applyPlaylistSaved(name, payload, message) {
        const list = initialBgData.playlists = initialBgData.playlists || [];
        const idx = list.findIndex(p => p.name === name);
        const record = { name: name, slides: payload.slides, images: payload.images, duration: payload.duration };
        list[idx >= 0 ? idx : list.length] = idx >= 0 ? Object.assign({}, list[idx], record) : record;

        editingPlaylistName = name;

        const stored   = initialBgData.settings || {};
        const liveName = (stored.slideshow && stored.slideshow.playlist_name) || '';
        /* Adopt only when nothing is being taken away: the site is already in
         * slideshow mode AND either has no playlist or already has this one.
         * A different playlist that is live is never switched out from under
         * the operator — they are told, and they decide. */
        const adopt = (stored.active_mode === 'slideshow') && (!liveName || liveName === name);

        refreshPlaylistSelects(name, adopt ? name : liveName);

        if (adopt && liveName !== name) {
            persistActivePlaylist(name, message);
        } else if (!adopt && liveName && liveName !== name) {
            showMessage('success', message + ' Note: "' + liveName + '" is still the site background — ' +
                        'pick this one under Active Playlist and hit Save Master Settings to switch.', true);
        } else if (!adopt) {
            showMessage('success', message + ' Set Active Mode to Slideshow and Save Master Settings to use it.', true);
        } else {
            showMessage('success', message);
        }
        updatePreview();
    }

    function applyPlaylistDeleted(name, message) {
        initialBgData.playlists = (initialBgData.playlists || []).filter(p => p.name !== name);
        if (editingPlaylistName === name) {
            editingPlaylistName = '';
            elements.slideshowTray.innerHTML = '';
            elements.playlistNameInput.value = '';
        }
        const stored   = initialBgData.settings || {};
        const liveName = (stored.slideshow && stored.slideshow.playlist_name) || '';
        const wasLive  = (liveName === name);
        refreshPlaylistSelects('', wasLive ? '' : liveName);
        showMessage(wasLive ? 'error' : 'success',
            wasLive ? message + ' It WAS the site background — pick another under Active Playlist and Save Master Settings.'
                    : message,
            wasLive);
        updatePreview();
    }

    function handleSelection(event) {
        const thumb = event.target.closest('.media-thumb');
        if (!thumb || event.target.closest('#slideshow-tray')) return;
        document.querySelectorAll('.media-thumb.selected').forEach(el => el.classList.remove('selected'));
        thumb.classList.add('selected');
        elements.youtubeInput.value = '';
        const type = thumb.dataset.type;
        const mode = (type === 'image') ? 'image' : 'video';
        const modeRadio = document.querySelector(`input[name="active_mode"][value="${mode}"]`);
        if (modeRadio) {
            if (!modeRadio.checked) {
                modeRadio.checked = true;
                handleModeChange();
            } else {
                updatePreview();
            }
        }
    }

    function handleYoutubeInput() {
        if (elements.youtubeInput.value.trim()) {
            document.querySelector(`input[name="active_mode"][value="video"]`).checked = true;
            document.querySelectorAll('.media-thumb.selected').forEach(el => el.classList.remove('selected'));
            handleModeChange();
        }
    }

    /**
     * Fallback placeholder — real SVG bundled inside the module (the old
     * /admin/background_mgr/ path returned 404 fleet-wide after a module
     * rename). Used when no cache thumb exists AND the browser can't snap
     * a video frame.
     */
    const VIDEO_PLACEHOLDER = '/admin/modules/BackgroundManager/assets/video-placeholder.svg';

    /**
     * Replace an <img> placeholder with a <video> that will render frame 1
     * as a natural poster. Preserves layout (video inherits the img's
     * display metrics). Falls back to the SVG placeholder if the video
     * itself errors out (unsupported codec, 404, etc.).
     */
    function swapInVideoThumb(imgEl, videoPath) {
        if (!imgEl || !videoPath) return;
        const vid = document.createElement('video');
        vid.src = videoPath;
        vid.muted = true;
        vid.preload = 'metadata';
        vid.playsInline = true;
        vid.setAttribute('aria-hidden', 'true');
        // Copy inline-ish attributes that CSS rules target .media-thumb img for.
        vid.loading = 'lazy';
        // Seek to a small offset so we don't render a black first frame.
        vid.addEventListener('loadedmetadata', () => {
            try { if (!isNaN(vid.duration)) vid.currentTime = Math.min(0.3, vid.duration * 0.05); } catch (_) {}
        }, { once: true });
        vid.addEventListener('error', () => {
            const fallback = document.createElement('img');
            fallback.src = VIDEO_PLACEHOLDER;
            fallback.loading = 'lazy';
            if (vid.parentNode) vid.parentNode.replaceChild(fallback, vid);
        }, { once: true });
        // Put <video> where the <img> was.
        // We swap immediately — the metadata load is async but the element
        // itself sits correctly in the DOM from the start.
        if (imgEl.parentNode) imgEl.parentNode.replaceChild(vid, imgEl);
    }

    /* createThumbElement(item, opts)
     *   opts.readOnly — omit delete button. Used by the Slideshow tab's
     *     inline library, which is for picking/dragging only — deletion
     *     belongs to the Video / Image tabs where the source-of-truth
     *     grids live. */
    function createThumbElement(item, opts) {
        const options = opts || {};
        let itemType;
        if (item.path && /\.(mp4|webm|ogv|mov|m4v)$/i.test(item.path)) {
            itemType = 'local_video';
        } else if (item.path && (item.path.includes('youtube.com') || item.path.includes('youtu.be'))) {
            itemType = 'youtube';
        } else {
            itemType = 'image';
        }
        const thumb = document.createElement('div');
        thumb.className = 'media-thumb' + (options.readOnly ? ' media-thumb-readonly' : '');
        thumb.dataset.path = item.path;
        thumb.dataset.optimized = item.optimized || item.path;
        thumb.dataset.type = itemType;

        // Check whether the server-supplied thumb is real (not the placeholder
        // fallback path, not empty). Only true cache thumbs skip the
        // browser-native fallback chain below.
        const hasRealThumb = item.thumb &&
            item.thumb !== VIDEO_PLACEHOLDER &&
            !/video-placeholder/i.test(item.thumb);

        const img = document.createElement('img');
        img.src = item.thumb || VIDEO_PLACEHOLDER;
        img.loading = 'lazy';

        if (itemType === 'local_video' && !hasRealThumb) {
            /* No ffmpeg-generated thumb available. Swap the <img> for a
             * <video preload="metadata" muted> so the browser itself
             * decodes and displays frame 1 — no server work required.
             * Done eagerly for local videos without a real thumb so the
             * user never sees the generic placeholder if the browser
             * can load the video at all. */
            swapInVideoThumb(img, item.path);
        } else {
            /* Images + YouTube + videos with a real cached thumb: fall
             * back to SVG placeholder on image load error. */
            img.addEventListener('error', () => { img.src = VIDEO_PLACEHOLDER; });
        }
        thumb.appendChild(img);
        if (!options.readOnly) {
            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'thumb-delete-btn';
            deleteBtn.innerHTML = '&times;';
            deleteBtn.title = 'Remove from gallery';
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                deleteMediaItem(item, thumb, itemType);
            });
            thumb.appendChild(deleteBtn);
        }
        return thumb;
    }

    function populateGrid(grid, mediaList, opts) {
        if (!grid) return;
        grid.innerHTML = '';
        if (!mediaList || !Array.isArray(mediaList)) return;
        mediaList.forEach(item => grid.appendChild(createThumbElement(item, opts)));
    }

    function appendToGrid(grid, item) {
        if (!grid) return;
        const thumb = createThumbElement(item);
        thumb.style.opacity = '0';
        thumb.style.transition = 'opacity 0.3s ease';
        grid.appendChild(thumb);
        requestAnimationFrame(() => { thumb.style.opacity = '1'; });
    }

    /* ───────── Filter / Sort state (per-tab, localStorage-backed) ─────────
     * Each gallery tab owns a small state bundle. Defaults match the DOM
     * option marked "selected". Changes persist across reloads so users
     * return to their preferred view. */
    const BGM_FS_KEY = 'bgm_fs_v1';
    const fs = {
        video: { filter: 'all',      sort: 'date-desc' },
        image: {                     sort: 'date-desc' },
    };
    (function loadFS() {
        try {
            const saved = JSON.parse(localStorage.getItem(BGM_FS_KEY) || 'null');
            if (saved && typeof saved === 'object') {
                if (saved.video) Object.assign(fs.video, saved.video);
                if (saved.image) Object.assign(fs.image, saved.image);
            }
        } catch (_) {}
    })();
    function saveFS() { try { localStorage.setItem(BGM_FS_KEY, JSON.stringify(fs)); } catch (_) {} }

    function isYouTubeItem(item) {
        if (item.source === 'youtube') return true;
        return !!(item.path && (item.path.includes('youtube.com') || item.path.includes('youtu.be')));
    }

    /** Apply the Video tab's current filter + sort to the raw list and
     *  return a new array. Non-destructive; doesn't touch initialBgData. */
    function applyVideoFilterSort(list) {
        const s = fs.video;
        let out = (list || []).slice();
        if (s.filter === 'local')   out = out.filter(v => !isYouTubeItem(v));
        if (s.filter === 'youtube') out = out.filter(v => isYouTubeItem(v));
        return sortMediaList(out, s.sort);
    }
    function applyImageFilterSort(list) {
        const s = fs.image;
        return sortMediaList((list || []).slice(), s.sort);
    }
    function sortMediaList(arr, sortKey) {
        const nameOf = it => String(it.title || it.path || '').toLowerCase();
        const timeOf = it => Number(it.mtime || 0);
        switch (sortKey) {
            case 'name-asc':  arr.sort((a,b) => nameOf(a).localeCompare(nameOf(b))); break;
            case 'name-desc': arr.sort((a,b) => nameOf(b).localeCompare(nameOf(a))); break;
            case 'date-asc':  arr.sort((a,b) => timeOf(a) - timeOf(b)); break;
            case 'date-desc': arr.sort((a,b) => timeOf(b) - timeOf(a)); break;
        }
        return arr;
    }

    /** Render the Video grid respecting current filter+sort. Replaces the
     *  raw populateGrid() call for this tab. Also updates count readout. */
    function renderVideoGrid() {
        const all = (initialBgData && initialBgData.media && initialBgData.media.videos) || [];
        const visible = applyVideoFilterSort(all);
        populateGrid(elements.videoGrid, visible);
        const vCnt = document.getElementById('video-visible-count');
        const tCnt = document.getElementById('video-total-count');
        if (vCnt) vCnt.textContent = String(visible.length);
        if (tCnt) tCnt.textContent = String(all.length);
        /* Re-bind sortable since populateGrid replaced the inner nodes */
        initializeSortable();
    }
    function renderImageGrid() {
        const all = (initialBgData && initialBgData.media && initialBgData.media.images) || [];
        const visible = applyImageFilterSort(all);
        populateGrid(elements.imageGrid, visible);
        const vCnt = document.getElementById('image-visible-count');
        const tCnt = document.getElementById('image-total-count');
        if (vCnt) vCnt.textContent = String(visible.length);
        if (tCnt) tCnt.textContent = String(all.length);
        initializeSortable();
    }

    /* Slideshow tab's inline library — populates two read-only grids
     * (videos + images) so users can drag items into the tray without
     * leaving the Slideshow tab. Kept in sync with initialBgData which
     * is the single source of truth the rest of the module already uses. */
    function populateSlideshowLibrary() {
        const libV = document.getElementById('slideshow-lib-videos');
        const libI = document.getElementById('slideshow-lib-images');
        const cntV = document.getElementById('slideshow-lib-video-count');
        const cntI = document.getElementById('slideshow-lib-image-count');
        const videos = (initialBgData && initialBgData.media && initialBgData.media.videos) || [];
        const images = (initialBgData && initialBgData.media && initialBgData.media.images) || [];
        populateGrid(libV, videos, { readOnly: true });
        populateGrid(libI, images, { readOnly: true });
        if (cntV) cntV.textContent = String(videos.length);
        if (cntI) cntI.textContent = String(images.length);
    }

    /* Apply the filter pulldown — show all, just images, or just videos.
     * Pure CSS toggle; no re-render needed. */
    function applySlideshowLibraryFilter() {
        const sel = document.getElementById('slideshow-library-filter');
        if (!sel) return;
        const v = sel.value;
        const vSection = document.querySelector('.bgm-library-section[data-library-kind="videos"]');
        const iSection = document.querySelector('.bgm-library-section[data-library-kind="images"]');
        if (vSection) vSection.style.display = (v === 'videos' || v === 'all') ? '' : 'none';
        if (iSection) iSection.style.display = (v === 'images' || v === 'all') ? '' : 'none';
    }

    async function deleteMediaItem(item, thumbElement, itemType) {
        const name = item.path.split('/').pop();
        if (!confirm(`Delete "${name}" from the gallery?`)) return;
        try {
            const resp = await fetch('/admin/modules/BackgroundManager/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_media', path: item.path, type: itemType })
            });
            const data = await resp.json();
            if (data.success) {
                thumbElement.remove();
                showMessage('success', data.message);
            } else {
                showMessage('error', data.message);
            }
        } catch (e) {
            showMessage('error', 'Network error while deleting.');
        }
    }

    function initializeSortable() {
        new Sortable(elements.imageGrid, { group: { name: 'shared', pull: 'clone', put: false }, sort: false, animation: 150 });
        new Sortable(elements.videoGrid, { group: { name: 'shared', pull: 'clone', put: false }, sort: false, animation: 150 });
        /* Slideshow tab's inline library grids — same 'shared' group so
         * clones land in the tray exactly like they would from the
         * primary Video / Image tabs. */
        const libV = document.getElementById('slideshow-lib-videos');
        const libI = document.getElementById('slideshow-lib-images');
        if (libV) new Sortable(libV, { group: { name: 'shared', pull: 'clone', put: false }, sort: false, animation: 150 });
        if (libI) new Sortable(libI, { group: { name: 'shared', pull: 'clone', put: false }, sort: false, animation: 150 });
        new Sortable(elements.slideshowTray, { group: 'shared', animation: 150 });
    }
    
    /* Slides as they stand in the tray right now, in tray order. Shares its shape
     * with savePlaylist()'s payload so the preview shows what a save would write. */
    function slidesFromTray() {
        return Array.from(elements.slideshowTray.querySelectorAll('.media-thumb')).map(el => ({
            type: (el.dataset.type === 'image') ? 'image' : 'video',
            path: el.dataset.optimized || el.dataset.path,
            source_path: el.dataset.path
        }));
    }

    function loadPlaylistIntoTray() {
        const playlistName = elements.playlistSelect.value;
        elements.slideshowTray.innerHTML = '';
        elements.playlistNameInput.value = playlistName;
        editingPlaylistName = playlistName;
        if (!playlistName) { updatePreview(); return; }
        const playlist = initialBgData.playlists.find(p => p.name === playlistName);
        if (!playlist) { updatePreview(); return; }

        if (playlist.duration) {
            const durSelect = document.getElementById('playlist-duration-select');
            if (durSelect) durSelect.value = playlist.duration;
        }

        const slides = playlist.slides || (playlist.images || []).map(p => ({ type: 'image', path: p }));
        slides.forEach(slide => {
            let mediaItem;
            if (slide.type === 'image') {
                mediaItem = initialBgData.media.images.find(img => img.optimized === slide.path);
            } else {
                mediaItem = initialBgData.media.videos.find(vid => vid.path === slide.path || vid.path === slide.source_path);
            }
            const thumb = document.createElement('div');
            thumb.className = 'media-thumb';
            thumb.dataset.path = mediaItem?.path || slide.source_path || slide.path;
            thumb.dataset.optimized = mediaItem?.optimized || slide.path;
            thumb.dataset.type = slide.type || 'image';
            // Slide-tray thumb resolution: if we have a real cached thumb, use it;
            // otherwise for video slides, fall through to the browser-native
            // <video> thumb (same logic as the media grid). Keeps the tray
            // consistent with the gallery above it.
            const trayThumb = mediaItem?.thumb;
            const hasRealThumb = trayThumb &&
                trayThumb !== VIDEO_PLACEHOLDER &&
                !/video-placeholder/i.test(trayThumb);
            if ((slide.type === 'video' || slide.type === 'local_video') && !hasRealThumb) {
                const videoSrc = mediaItem?.path || slide.source_path || slide.path;
                const imgEl = document.createElement('img');
                imgEl.src = VIDEO_PLACEHOLDER;
                imgEl.loading = 'lazy';
                thumb.appendChild(imgEl);
                swapInVideoThumb(imgEl, videoSrc);
            } else {
                const src = trayThumb || (slide.type === 'image' ? slide.path : VIDEO_PLACEHOLDER);
                thumb.innerHTML = `<img src="${src}" loading="lazy">`;
            }
            elements.slideshowTray.appendChild(thumb);
        });
        updatePreview();
    }
    
    function renderPreviewElement(source, type, sizing) {
        if (!source) {
            const p = document.createElement('p');
            p.className = 'panel-placeholder-text';
            p.textContent = 'No background configured for this mode.';
            elements.previewPanel.appendChild(p);
            return;
        }
        let element;
        let isYouTube = false;
        if (type === 'image') {
            element = document.createElement('img');
            element.src = source;
        } else if (type === 'local' || type === 'local_video') {
             element = document.createElement('video');
             element.src = source;
             element.autoplay = true; element.loop = true; element.muted = true; element.playsinline = true;
        } else if (type === 'youtube') {
            const videoId = extractYouTubeId(source);
            if(videoId) {
                element = document.createElement('iframe');
                element.src = `https://www.youtube.com/embed/${videoId}?autoplay=1&mute=1&loop=1&playlist=${videoId}&controls=0&modestbranding=1&showinfo=0&rel=0`;
                element.setAttribute('frameborder', '0');
                element.setAttribute('allow', 'autoplay');
                isYouTube = true;
            }
        }
        if (element) {
            element.className = 'preview-content';
            elements.previewPanel.appendChild(element);
            if (isYouTube) {
                // iframes ignore object-fit. Compute size manually to mimic cover/contain/fill.
                applyYouTubeSizing(element, sizing);
            } else {
                element.style.objectFit = sizing;
            }
        }
    }

    /**
     * iframes don't honor object-fit, so compute pixel dims that mimic cover/contain/fill
     * within the preview panel. Iframe is 16:9 by default; we anchor it centered and
     * size such that it covers (overflow), contains (letterbox), or fills (stretch).
     */
    function applyYouTubeSizing(iframe, sizing) {
        const panel = elements.previewPanel;
        const pw = panel.clientWidth || panel.offsetWidth;
        const ph = panel.clientHeight || panel.offsetHeight;
        const ratio = 16 / 9;
        let w, h;
        if (sizing === 'fill') {
            w = pw; h = ph;                                       // stretch — accepted distortion
        } else if (sizing === 'contain') {
            if (pw / ph > ratio) { h = ph; w = ph * ratio; }      // panel wider than 16:9 → height matches
            else                  { w = pw; h = pw / ratio; }      // panel taller → width matches
        } else { // cover (default)
            if (pw / ph > ratio) { w = pw; h = pw / ratio; }      // panel wider → width matches, vertical overflow
            else                  { h = ph; w = ph * ratio; }      // panel taller → height matches, horizontal overflow
        }
        // Make the panel a positioning context if it isn't already
        if (getComputedStyle(panel).position === 'static') panel.style.position = 'relative';
        panel.style.overflow = 'hidden';
        iframe.style.position  = 'absolute';
        iframe.style.left      = '50%';
        iframe.style.top       = '50%';
        iframe.style.transform = 'translate(-50%, -50%)';
        iframe.style.width     = w + 'px';
        iframe.style.height    = h + 'px';
        iframe.style.maxWidth  = 'none';
        iframe.style.maxHeight = 'none';
        iframe.dataset.sizing  = sizing;   // for debugging / re-application

        // On window/panel resize, re-compute
        if (!iframe._resizeObs) {
            iframe._resizeObs = new ResizeObserver(() => applyYouTubeSizing(iframe, iframe.dataset.sizing || 'cover'));
            iframe._resizeObs.observe(panel);
        }
    }

    function startSlideshowPreview(slides, sizing) {
        const normalized = (slides || []).map(s => typeof s === 'string' ? { type: 'image', path: s } : s);
        if (normalized.length === 0) return;
        let currentIndex = 0;

        const showNextSlide = () => {
            const slide = normalized[currentIndex];
            let el;
            const isYoutube = slide.path && (slide.path.includes('youtube.com') || slide.path.includes('youtu.be'));
            if (isYoutube) {
                const videoId = extractYouTubeId(slide.path);
                if (videoId) {
                    el = document.createElement('iframe');
                    el.src = `https://www.youtube.com/embed/${videoId}?autoplay=1&mute=1&loop=1&playlist=${videoId}&controls=0&modestbranding=1&showinfo=0&rel=0`;
                    el.setAttribute('frameborder', '0');
                    el.setAttribute('allow', 'autoplay');
                }
            } else if (slide.type === 'video' || (slide.path && slide.path.endsWith('.mp4'))) {
                el = document.createElement('video');
                el.src = slide.path;
                el.autoplay = true;
                el.muted = true;
                el.playsinline = true;
                el.loop = false;
            } else {
                el = document.createElement('img');
                el.src = slide.path;
            }
            el.className = 'preview-content';
            if (el.tagName !== 'IFRAME') el.style.objectFit = sizing;
            el.style.opacity = 0;
            el.style.transition = 'opacity 0.7s ease-in-out';
            const existing = elements.previewPanel.querySelector('.preview-content');
            if (existing) {
                elements.previewPanel.insertBefore(el, existing);
                if (el.tagName === 'IFRAME') applyYouTubeSizing(el, sizing);
                setTimeout(() => { el.style.opacity = 1; existing.style.opacity = 0; }, 50);
                setTimeout(() => { if (existing.parentElement) existing.remove(); }, 750);
            } else {
                elements.previewPanel.appendChild(el);
                if (el.tagName === 'IFRAME') applyYouTubeSizing(el, sizing);
                setTimeout(() => { el.style.opacity = 1; }, 50);
            }
            currentIndex = (currentIndex + 1) % normalized.length;
        };

        showNextSlide();
        if (normalized.length > 1) {
            previewSlideshowInterval = setInterval(showNextSlide, 4000);
        }
    }

    function extractYouTubeId(url) {
        if (!url) return null;
        const regex = /(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/;
        const match = url.match(regex);
        return match ? match[1] : null;
    }
    
    function hexToRgb(hex) {
        if (!hex) return '0,0,0';
        const r = parseInt(hex.slice(1, 3), 16) || 0;
        const g = parseInt(hex.slice(3, 5), 16) || 0;
        const b = parseInt(hex.slice(5, 7), 16) || 0;
        return `${r},${g},${b}`;
    }

    // --- DRAG-AND-DROP UPLOAD SYSTEM ---
    function setupDropzones() {
        document.querySelectorAll('.upload-dropzone').forEach(zone => {
            const fileInput = zone.querySelector('.dropzone-file-input');
            const content = zone.querySelector('.dropzone-content');

            // Click to open file picker
            content.addEventListener('click', () => fileInput.click());

            // File input change
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    uploadFiles(zone, fileInput.files);
                    fileInput.value = '';
                }
            });

            // Drag events
            zone.addEventListener('dragenter', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.add('dragover');
            });
            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.add('dragover');
            });
            zone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (!zone.contains(e.relatedTarget)) {
                    zone.classList.remove('dragover');
                }
            });
            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                zone.classList.remove('dragover');
                if (e.dataTransfer.files.length > 0) {
                    uploadFiles(zone, e.dataTransfer.files);
                }
            });
        });
    }

    async function uploadFiles(zone, files) {
        const type = zone.dataset.type; // 'images' or 'videos'

        // Push freshly-uploaded items into the source-of-truth arrays, then
        // re-render the affected tabs. Re-render (vs naive append) keeps the
        // current sort/filter honest (e.g. "Newest first" floats new items up).
        let hitImages = false, hitVideos = false;
        const absorb = (item) => {
            const mt = item.media_type || type;
            if (!item.mtime) item.mtime = Math.floor(Date.now() / 1000);
            if (mt === 'images') { initialBgData.media.images.push(item); hitImages = true; }
            else { if (!item.source) item.source = 'local'; initialBgData.media.videos.push(item); hitVideos = true; }
        };
        const rerender = () => {
            if (hitImages) renderImageGrid();
            if (hitVideos) renderVideoGrid();
            populateSlideshowLibrary();   // keep Slideshow tab's inline library current
            applySlideshowLibraryFilter();
            initializeSortable();
        };

        // Shared per-file progress meter (LumUpload): one XHR per file = true
        // per-file progress, anchored at this dropzone. Falls back to the legacy
        // single-request bar if the shared component isn't loaded.
        const LU = (window.LumUpload && window.LumUpload.__real) ? window.LumUpload : null;
        if (LU && LU.uploadEach) {
            LU.uploadEach({
                url: '/admin/modules/BackgroundManager/api.php', files: files, field: 'files[]',
                data: { action: 'upload_media', type: type },
                anchor: zone,
                onItem: r => {
                    if (r.ok && r.data && Array.isArray(r.data.uploaded)) r.data.uploaded.forEach(absorb);
                },
                onAllDone: results => {
                    const bad = results.filter(r => !r.ok);
                    if (bad.length) {
                        let msg = bad.length + ' upload(s) failed.';
                        const det = bad.map(r => r.data && (r.data.message || (r.data.errors && r.data.errors.join('; ')))).filter(Boolean);
                        if (det.length) msg += '<br>' + det.join('<br>');
                        showMessage('error', msg);
                    }
                    const ok = results.length - bad.length;
                    if (ok) showMessage('success', ok + ' file(s) uploaded.');
                    rerender();
                }
            });
            return;
        }

        // Legacy fallback (no progress component present).
        const progressWrap = zone.querySelector('.dropzone-progress');
        const progressBar = zone.querySelector('.dropzone-progress-bar');
        const progressText = zone.querySelector('.dropzone-progress-text');
        const content = zone.querySelector('.dropzone-content');

        content.style.display = 'none';
        progressWrap.style.display = 'flex';
        progressBar.style.width = '0%';
        progressText.textContent = `Uploading ${files.length} file(s)...`;

        const formData = new FormData();
        formData.append('action', 'upload_media');
        formData.append('type', type);
        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/admin/modules/BackgroundManager/api.php', true);

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    progressBar.style.width = pct + '%';
                    progressText.textContent = `Uploading... ${pct}%`;
                }
            });

            xhr.onload = function() {
                content.style.display = '';
                progressWrap.style.display = 'none';

                let data;
                try { data = JSON.parse(xhr.responseText); } catch(e) {
                    showMessage('error', 'Invalid server response.');
                    return;
                }

                if (data.success && data.uploaded) {
                    showMessage('success', data.message);
                    // Push to the source-of-truth data arrays, then re-render
                    // (vs naive append) so new items land per current sort.
                    data.uploaded.forEach(absorb);
                    rerender();
                } else {
                    let msg = data.message || 'Upload failed.';
                    if (data.errors && data.errors.length) {
                        msg += '<br>' + data.errors.join('<br>');
                    }
                    showMessage('error', msg);
                }
            };

            xhr.onerror = function() {
                content.style.display = '';
                progressWrap.style.display = 'none';
                showMessage('error', 'Network error during upload.');
            };

            xhr.send(formData);
        } catch (err) {
            content.style.display = '';
            progressWrap.style.display = 'none';
            showMessage('error', 'Upload failed: ' + err.message);
        }
    }

    initialize();
});

/* ─── Column splitter ────────────────────────────────────────────────────────
 * Drag the divider between Master and the active-mode column. Self-contained
 * and outside the main module closure: it touches layout only, so nothing here
 * can affect saving or the preview. Width is stored per browser, clamped so a
 * column can never be dragged away entirely, and double-click restores 35%.
 */
(function () {
    'use strict';
    var KEY = 'bgm.splitLeft', MIN = 22, MAX = 60, DEFAULT = '35%';

    document.addEventListener('DOMContentLoaded', function () {
        var split  = document.getElementById('bgm-split');
        var gutter = document.getElementById('bgm-split-gutter');
        if (!split || !gutter) return;

        try {
            var saved = localStorage.getItem(KEY);
            if (saved) split.style.setProperty('--bgm-split-left', saved);
        } catch (e) { /* private mode — the default in CSS still applies */ }

        function apply(pct, persist) {
            var v = Math.min(MAX, Math.max(MIN, pct)).toFixed(2) + '%';
            split.style.setProperty('--bgm-split-left', v);
            if (persist) { try { localStorage.setItem(KEY, v); } catch (e) {} }
        }

        function pctFrom(clientX) {
            var box = split.getBoundingClientRect();
            if (!box.width) return null;
            return ((clientX - box.left) / box.width) * 100;
        }

        var dragging = false;

        function onMove(ev) {
            if (!dragging) return;
            var x = (ev.touches && ev.touches[0]) ? ev.touches[0].clientX : ev.clientX;
            var pct = pctFrom(x);
            if (pct !== null) apply(pct, false);
            ev.preventDefault();
        }

        function onUp(ev) {
            if (!dragging) return;
            dragging = false;
            gutter.classList.remove('is-dragging');
            document.body.classList.remove('bgm-splitting');
            var x = (ev.changedTouches && ev.changedTouches[0]) ? ev.changedTouches[0].clientX : ev.clientX;
            var pct = pctFrom(x);
            if (pct !== null) apply(pct, true);
        }

        function onDown(ev) {
            dragging = true;
            gutter.classList.add('is-dragging');
            document.body.classList.add('bgm-splitting');
            ev.preventDefault();
        }

        gutter.addEventListener('mousedown', onDown);
        gutter.addEventListener('touchstart', onDown, { passive: false });
        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchend', onUp);

        gutter.addEventListener('dblclick', function () {
            split.style.setProperty('--bgm-split-left', DEFAULT);
            try { localStorage.setItem(KEY, DEFAULT); } catch (e) {}
        });

        /* Keyboard: the divider is focusable, so it should move. */
        gutter.addEventListener('keydown', function (ev) {
            var cur = parseFloat(getComputedStyle(split).getPropertyValue('--bgm-split-left')) || 35;
            if (ev.key === 'ArrowLeft')       { apply(cur - 2, true); ev.preventDefault(); }
            else if (ev.key === 'ArrowRight') { apply(cur + 2, true); ev.preventDefault(); }
        });
    });
})();
