/**
 * Luminal CMS — Floating Video Player + Picture-in-Picture
 * @file /js/video-float.js
 * @description Adds Float (in-browser) and PiP (OS-level) buttons to opt-in <video> elements.
 *   Videos must have `data-vf-float` attribute to receive Float/PiP controls.
 *   Disabled by default — background videos and other non-opted-in videos are excluded.
 *   Float persists across page navigation via sessionStorage.
 *   PiP state saved on navigation — restores to Float with one-click PiP re-entry.
 *   Volume/mute state persists across sessions.
 *   Config via window.LUMINAL_VIDEO_FLOAT = { enabled, mode: "both"|"float"|"pip" }
 */
document.addEventListener('DOMContentLoaded', () => {
    const CFG = window.LUMINAL_VIDEO_FLOAT || { enabled: true, mode: 'both' };
    if (!CFG.enabled) return;

    const showFloat = CFG.mode === 'float' || CFG.mode === 'both';
    const showPip   = CFG.mode === 'pip'   || CFG.mode === 'both';
    const pipSupported = 'pictureInPictureEnabled' in document && document.pictureInPictureEnabled;

    const SK = {
        SRC:    'luminalFloatingVideoSrc',
        TIME:   'luminalFloatingVideoTime',
        X:      'luminalFloatingVideoX',
        Y:      'luminalFloatingVideoY',
        W:      'luminalFloatingVideoWidth',
        H:      'luminalFloatingVideoHeight',
        TITLE:  'luminalFloatingVideoTitle',
        VOL:    'luminalFloatingVideoVol',
        MUTED:  'luminalFloatingVideoMuted',
        WAS_PIP:'luminalFloatingVideoWasPiP'
    };

    let floatingVideoModal = null;
    let floatingVideoElement = null;
    let isInPiP = false;

    const DEFAULT_WIDTH = 320;
    const DEFAULT_HEIGHT = 180;
    const DEFAULT_RIGHT_OFFSET = 20;
    const DEFAULT_BOTTOM_OFFSET = 20;

    /**
     * Creates the floating video modal with volume controls.
     */
    function createFloatingVideoModal(src, currentTime, posX, posY, width, height, title) {
        if (floatingVideoModal) {
            floatingVideoModal.remove();
        }

        floatingVideoModal = document.createElement('div');
        floatingVideoModal.id = 'floating-video-modal';
        document.body.appendChild(floatingVideoModal);

        const pipBtn = pipSupported
            ? `<button class="fv-btn fv-pip-btn" title="Picture-in-Picture">⧉</button>`
            : '';

        const savedVol = parseFloat(sessionStorage.getItem(SK.VOL) ?? '1');
        const savedMuted = sessionStorage.getItem(SK.MUTED) === '1';

        floatingVideoModal.innerHTML = `
            <div class="fv-header">
                <span class="fv-title">${title || 'Floating Video'}</span>
                <div class="fv-controls">
                    <button class="fv-btn fv-mute-btn" title="Mute/Unmute">${savedMuted ? '🔇' : '🔊'}</button>
                    <input type="range" class="fv-volume" min="0" max="1" step="0.05" value="${savedMuted ? 0 : savedVol}">
                    ${pipBtn}
                    <button class="fv-btn fv-close-btn" title="Close">&times;</button>
                </div>
            </div>
            <div class="fv-content">
                <video controls autoplay playsinline></video>
            </div>
        `;

        floatingVideoElement = floatingVideoModal.querySelector('video');
        floatingVideoElement.src = src;
        floatingVideoElement.currentTime = currentTime;
        floatingVideoElement.volume = savedVol;
        floatingVideoElement.muted = savedMuted;

        // --- Position & Size ---
        let finalW = width && !isNaN(width) ? width : DEFAULT_WIDTH;
        let finalH = height && !isNaN(height) ? height : DEFAULT_HEIGHT;
        let finalX = posX;
        let finalY = posY;

        if (isNaN(finalX) || finalX === null) {
            finalX = window.innerWidth - finalW - DEFAULT_RIGHT_OFFSET;
        }
        if (isNaN(finalY) || finalY === null) {
            finalY = window.innerHeight - finalH - DEFAULT_BOTTOM_OFFSET;
        }

        floatingVideoModal.style.left   = `${finalX}px`;
        floatingVideoModal.style.top    = `${finalY}px`;
        floatingVideoModal.style.width  = `${finalW}px`;
        floatingVideoModal.style.height = `${finalH}px`;

        sessionStorage.setItem(SK.X, finalX);
        sessionStorage.setItem(SK.Y, finalY);
        sessionStorage.setItem(SK.W, finalW);
        sessionStorage.setItem(SK.H, finalH);

        // --- Volume Controls ---
        const muteBtn   = floatingVideoModal.querySelector('.fv-mute-btn');
        const volSlider = floatingVideoModal.querySelector('.fv-volume');

        muteBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            floatingVideoElement.muted = !floatingVideoElement.muted;
            muteBtn.textContent = floatingVideoElement.muted ? '🔇' : '🔊';
            volSlider.value = floatingVideoElement.muted ? 0 : floatingVideoElement.volume;
            sessionStorage.setItem(SK.MUTED, floatingVideoElement.muted ? '1' : '0');
        });

        volSlider.addEventListener('input', (e) => {
            e.stopPropagation();
            const v = parseFloat(volSlider.value);
            floatingVideoElement.volume = v;
            floatingVideoElement.muted = v === 0;
            muteBtn.textContent = v === 0 ? '🔇' : '🔊';
            sessionStorage.setItem(SK.VOL, v);
            sessionStorage.setItem(SK.MUTED, v === 0 ? '1' : '0');
        });

        // Prevent drag from starting on slider
        volSlider.addEventListener('mousedown', (e) => e.stopPropagation());

        // --- Dragging ---
        let isDragging = false;
        let offset = { x: 0, y: 0 };

        const header = floatingVideoModal.querySelector('.fv-header');
        header.addEventListener('mousedown', (e) => {
            if (e.target.closest('.fv-btn') || e.target.closest('.fv-volume')) return;
            isDragging = true;
            offset.x = e.clientX - floatingVideoModal.getBoundingClientRect().left;
            offset.y = e.clientY - floatingVideoModal.getBoundingClientRect().top;
            floatingVideoModal.style.cursor = 'grabbing';
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            let newX = e.clientX - offset.x;
            let newY = e.clientY - offset.y;
            newX = Math.max(0, Math.min(newX, window.innerWidth - floatingVideoModal.offsetWidth));
            newY = Math.max(0, Math.min(newY, window.innerHeight - floatingVideoModal.offsetHeight));
            floatingVideoModal.style.left = `${newX}px`;
            floatingVideoModal.style.top  = `${newY}px`;
            sessionStorage.setItem(SK.X, newX);
            sessionStorage.setItem(SK.Y, newY);
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                floatingVideoModal.style.cursor = 'grab';
                if (floatingVideoElement && !floatingVideoElement.paused) {
                    sessionStorage.setItem(SK.TIME, floatingVideoElement.currentTime);
                }
            }
        });

        // --- Resizing ---
        let resizeTimeout;
        const saveDimensions = () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                sessionStorage.setItem(SK.W, floatingVideoModal.offsetWidth);
                sessionStorage.setItem(SK.H, floatingVideoModal.offsetHeight);
            }, 200);
        };
        const resizeObserver = new ResizeObserver(saveDimensions);
        resizeObserver.observe(floatingVideoModal);

        // --- PiP pop-out from float modal ---
        const pipBtnEl = floatingVideoModal.querySelector('.fv-pip-btn');
        if (pipBtnEl) {
            pipBtnEl.addEventListener('click', (e) => {
                e.stopPropagation();
                launchPiP(floatingVideoElement);
            });
        }

        // --- PiP events: track enter/leave ---
        floatingVideoElement.addEventListener('enterpictureinpicture', () => {
            isInPiP = true;
            sessionStorage.setItem(SK.WAS_PIP, '1');
            if (floatingVideoModal) floatingVideoModal.classList.add('fv-pip-active');
        });
        floatingVideoElement.addEventListener('leavepictureinpicture', () => {
            isInPiP = false;
            sessionStorage.removeItem(SK.WAS_PIP);
            if (floatingVideoModal) floatingVideoModal.classList.remove('fv-pip-active');
        });

        // --- Close ---
        floatingVideoModal.querySelector('.fv-close-btn').addEventListener('click', () => {
            // Exit PiP first if active
            if (document.pictureInPictureElement) {
                document.exitPictureInPicture().catch(() => {});
            }
            closeFloatingModal(saveInterval, resizeObserver);
        });

        // --- Video ended ---
        floatingVideoElement.addEventListener('ended', () => {
            if (document.pictureInPictureElement) {
                document.exitPictureInPicture().catch(() => {});
            }
            closeFloatingModal(saveInterval, resizeObserver);
        });

        // --- Periodic time save ---
        let saveInterval = setInterval(() => {
            if (floatingVideoElement && !floatingVideoElement.paused) {
                sessionStorage.setItem(SK.TIME, floatingVideoElement.currentTime);
            }
        }, 5000);

        // --- Save on unload (handles PiP persistence) ---
        window.addEventListener('beforeunload', () => {
            clearInterval(saveInterval);
            if (floatingVideoElement) {
                if (!floatingVideoElement.paused) {
                    sessionStorage.setItem(SK.TIME, floatingVideoElement.currentTime);
                }
                // If in PiP, mark so next page restores to Float
                if (isInPiP || document.pictureInPictureElement) {
                    sessionStorage.setItem(SK.WAS_PIP, '1');
                }
            }
        });

        return floatingVideoModal;
    }

    /**
     * Closes the floating modal and cleans up.
     */
    function closeFloatingModal(saveInterval, resizeObserver) {
        clearFloatingVideoState();
        clearInterval(saveInterval);
        isInPiP = false;
        if (floatingVideoModal) {
            resizeObserver.unobserve(floatingVideoModal);
            floatingVideoModal.remove();
        }
        floatingVideoModal = null;
        floatingVideoElement = null;
        addButtonsToVideos();
    }

    /**
     * Launches Picture-in-Picture for a video element.
     */
    function launchPiP(video) {
        if (!pipSupported || !video) return;
        video.requestPictureInPicture().catch(err => {
            console.log('PiP request failed:', err);
        });
    }

    /**
     * Finds a caption/title for a video element by scanning nearby DOM.
     */
    function findVideoCaption(video) {
        const card = video.closest('.ri-card');
        if (card) {
            const cap = card.querySelector('.ri-caption');
            if (cap && cap.textContent.trim()) return cap.textContent.trim();
        }
        const stackDiv = video.closest('.stack-video');
        if (stackDiv && stackDiv.nextElementSibling) {
            const next = stackDiv.nextElementSibling;
            if (next.classList.contains('ri-caption') || next.tagName === 'FIGCAPTION') {
                if (next.textContent.trim()) return next.textContent.trim();
            }
        }
        return video.dataset.title || video.getAttribute('title') || '';
    }

    /**
     * Clears all floating video state from sessionStorage.
     */
    function clearFloatingVideoState() {
        Object.values(SK).forEach(k => sessionStorage.removeItem(k));
    }

    /**
     * Restores floating player on page load from sessionStorage.
     * If was in PiP, restores to Float and pulses PiP button.
     */
    function initFloatingPlayer() {
        const storedSrc = sessionStorage.getItem(SK.SRC);
        if (!storedSrc) return;
        // Need at least Float mode to restore (PiP-only can't restore without Float)
        if (!showFloat && !showPip) return;

        const storedTime   = parseFloat(sessionStorage.getItem(SK.TIME) || '0');
        const storedX      = parseFloat(sessionStorage.getItem(SK.X));
        const storedY      = parseFloat(sessionStorage.getItem(SK.Y));
        const storedW      = parseFloat(sessionStorage.getItem(SK.W));
        const storedH      = parseFloat(sessionStorage.getItem(SK.H));
        const storedTitle  = sessionStorage.getItem(SK.TITLE) || '';
        const wasPiP       = sessionStorage.getItem(SK.WAS_PIP) === '1';

        // Clear PiP flag (consumed)
        sessionStorage.removeItem(SK.WAS_PIP);

        const modal = createFloatingVideoModal(storedSrc, storedTime, storedX, storedY, storedW, storedH, storedTitle);
        if (modal) {
            modal.querySelector('video').play().catch(() => {});

            // If was in PiP, pulse the PiP button to invite re-entry
            if (wasPiP && pipSupported) {
                const pipBtn = modal.querySelector('.fv-pip-btn');
                if (pipBtn) {
                    pipBtn.classList.add('fv-pip-pulse');
                    setTimeout(() => pipBtn.classList.remove('fv-pip-pulse'), 3000);
                }
            }
        }
    }

    /**
     * Scans for opted-in video elements and adds Float / PiP buttons.
     * Only videos with `data-vf-float` attribute receive controls.
     * Background videos (#background-video) are always excluded.
     */
    function addButtonsToVideos() {
        document.querySelectorAll('video[data-vf-float]:not(#background-video)').forEach(video => {
            if (video.closest('#floating-video-modal')) return;
            if (video.parentNode.querySelector('.vf-btn-wrap')) return;

            const currentFloatingSrc = sessionStorage.getItem(SK.SRC);
            const videoSrc = video.currentSrc || video.src;
            if (currentFloatingSrc && videoSrc === currentFloatingSrc) {
                video.style.display = 'none';
                return;
            }

            const wrap = document.createElement('div');
            wrap.className = 'vf-btn-wrap';

            if (showFloat) {
                const floatBtn = document.createElement('button');
                floatBtn.className = 'float-player-btn';
                floatBtn.textContent = 'Float';
                floatBtn.title = 'Float video while browsing';
                floatBtn.addEventListener('click', () => {
                    const src = video.currentSrc || video.src;
                    const currentTime = video.currentTime;
                    const caption = findVideoCaption(video);

                    sessionStorage.setItem(SK.SRC, src);
                    sessionStorage.setItem(SK.TIME, currentTime);
                    if (caption) sessionStorage.setItem(SK.TITLE, caption);
                    sessionStorage.removeItem(SK.X);
                    sessionStorage.removeItem(SK.Y);
                    sessionStorage.removeItem(SK.W);
                    sessionStorage.removeItem(SK.H);

                    video.style.display = 'none';
                    wrap.remove();

                    createFloatingVideoModal(src, currentTime, null, null, null, null, caption);
                    if (floatingVideoElement) {
                        floatingVideoElement.play().catch(() => {});
                    }
                });
                wrap.appendChild(floatBtn);
            }

            if (showPip && pipSupported) {
                const pipBtn = document.createElement('button');
                pipBtn.className = 'float-player-btn pip-player-btn';
                pipBtn.textContent = 'PiP';
                pipBtn.title = 'Picture-in-Picture (OS window)';
                pipBtn.addEventListener('click', () => {
                    if (video.paused) {
                        video.play().then(() => launchPiP(video)).catch(() => launchPiP(video));
                    } else {
                        launchPiP(video);
                    }
                });
                wrap.appendChild(pipBtn);
            }

            if (wrap.children.length > 0) {
                video.parentNode.insertBefore(wrap, video.nextSibling);
            }
        });
    }

    // Watch for dynamically added videos (e.g. lightbox) that opt in
    const vfObserver = new MutationObserver((mutations) => {
        for (const m of mutations) {
            for (const node of m.addedNodes) {
                if (node.nodeType !== 1) continue;
                if (node.matches && node.matches('video[data-vf-float]')) {
                    addButtonsToVideos();
                    return;
                }
                if (node.querySelector && node.querySelector('video[data-vf-float]')) {
                    addButtonsToVideos();
                    return;
                }
            }
        }
    });
    vfObserver.observe(document.body, { childList: true, subtree: true });

    // Boot
    initFloatingPlayer();
    addButtonsToVideos();
});
