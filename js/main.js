/**
 * @file: SITE-ROOT/js/main.js
 * @version: 2025.08.06.1100.EST
 * @description: Repaired to correctly handle all background modes.
 * @change: The applyBackground function's cleanup logic was made more precise to prevent it from removing the server-rendered #background-video element, fixing the "flicker" issue for MP4s.
 */
document.addEventListener('DOMContentLoaded', () => {
    const backgroundContainer = document.getElementById('background-container');
    const backgroundVideoEl = document.getElementById('background-video');
    const backgroundOverlay = document.getElementById('background-overlay');
    if (!backgroundContainer) return;

    let slideshowIntervalId = null;

    async function loadBackgroundSettings() {
        try {
            const response = await fetch('/admin/modules/BackgroundManager/api/get_frontend_background_settings.php');
            const settings = await response.json();
            if (settings.success) applyBackground(settings);
        } catch (error) {
            console.error('Failed to fetch background settings:', error);
        }
    }

    function applyBackground(settings) {
        // --- THIS IS THE CORRECTED CLEANUP LOGIC ---
        if (slideshowIntervalId) clearInterval(slideshowIntervalId);

        // Selectively remove old slides or iframes, but preserve the #background-video element.
        Array.from(backgroundContainer.children).forEach(child => {
            if (child.id !== 'background-video') {
                backgroundContainer.removeChild(child);
            }
        });
        
        backgroundContainer.style.backgroundImage = 'none';
        
        // Reset the video element if it exists, but don't remove it.
        if (backgroundVideoEl) {
            backgroundVideoEl.style.display = 'none';
            // We only change the src if it's NOT the one we're about to play.
            // This prevents the flicker if the server already rendered the correct video.
            const newSrc = (settings.background_mode === 'video' && (settings.selected_videos[0]?.type === 'local' || settings.selected_videos[0]?.type === 'local_video')) 
                ? settings.selected_videos[0].url 
                : '';
            if (backgroundVideoEl.getAttribute('src') !== newSrc) {
                backgroundVideoEl.src = '';
            }
        }
        // --- END OF CORRECTED CLEANUP ---

        // Overlay
        if (backgroundOverlay && settings.overlay_color) {
            backgroundOverlay.style.backgroundColor = settings.overlay_color;
            backgroundOverlay.style.opacity = settings.overlay_opacity;
            backgroundOverlay.style.display = 'block';
        } else if (backgroundOverlay) {
            backgroundOverlay.style.display = 'none';
        }

        // Apply Background
        switch (settings.background_mode) {
            case 'image':
            case 'slideshow':
                const hasImages = settings.selected_images && settings.selected_images.length > 0;
                const hasVideos = settings.selected_videos && settings.selected_videos.length > 0;
                if (hasImages || hasVideos) {
                    startSlideshow(settings.selected_images || [], settings.selected_videos || [], settings.slideshow_interval, settings.background_sizing, settings.background_mode === 'slideshow');
                }
                break;
            case 'video':
                if (settings.selected_videos && settings.selected_videos.length > 0) {
                    applyVideoBackground(settings.selected_videos[0], settings.background_sizing);
                }
                break;
        }
    }

    function applySizing(element, sizing, isVideo = false) {
        const prop = isVideo ? 'objectFit' : 'backgroundSize';
        let val = 'cover';
        if (sizing === 'contain') val = 'contain';
        else if (sizing === 'fill') val = '100% 100%';
        element.style[prop] = val;
        if (!isVideo) {
            element.style.backgroundPosition = 'center center';
            element.style.backgroundRepeat = 'no-repeat';
        }
    }

    function startSlideshow(images, videos, interval, sizing, shouldCycle) {
        // Build combined slide list: images first, then videos
        images.forEach((img, index) => {
            const slide = document.createElement('div');
            slide.className = 'slide';
            slide.style.backgroundImage = `url('${img.url}')`;
            applySizing(slide, sizing);
            if (index === 0) slide.classList.add('active');
            backgroundContainer.appendChild(slide);
        });
        videos.forEach((vid, index) => {
            const slide = document.createElement('div');
            slide.className = 'slide slide-video';
            if (vid.type === 'youtube') {
                const videoId = (vid.url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i) || [])[1];
                if (videoId) {
                    const iframe = document.createElement('iframe');
                    iframe.src = `https://www.youtube.com/embed/${videoId}?autoplay=0&mute=1&loop=1&playlist=${videoId}&controls=0&rel=0&showinfo=0&iv_load_policy=3&enablejsapi=1`;
                    iframe.className = 'background-media youtube-background';
                    iframe.setAttribute('frameborder', '0');
                    iframe.setAttribute('allow', 'autoplay');
                    slide.appendChild(iframe);
                }
            } else {
                const video = document.createElement('video');
                video.src = vid.url;
                video.autoplay = false;
                video.muted = true;
                video.loop = true;
                video.playsInline = true;
                video.className = 'background-media';
                applySizing(video, sizing, true);
                slide.appendChild(video);
            }
            if (images.length === 0 && index === 0) slide.classList.add('active');
            backgroundContainer.appendChild(slide);
        });
        const allSlides = backgroundContainer.querySelectorAll('.slide');
        // Start first slide's video/iframe
        if (allSlides.length > 0) {
            const firstVid = allSlides[0].querySelector('video');
            const firstIframe = allSlides[0].querySelector('iframe');
            if (firstVid) firstVid.play();
            if (firstIframe) firstIframe.contentWindow.postMessage('{"event":"command","func":"playVideo","args":""}', '*');
        }
        if (shouldCycle && allSlides.length > 1) {
            let currentIndex = 0;
            slideshowIntervalId = setInterval(() => {
                allSlides[currentIndex].classList.remove('active');
                // Pause outgoing media
                const outVid = allSlides[currentIndex].querySelector('video');
                if (outVid) outVid.pause();
                const outIframe = allSlides[currentIndex].querySelector('iframe');
                if (outIframe) outIframe.contentWindow.postMessage('{"event":"command","func":"pauseVideo","args":""}', '*');
                currentIndex = (currentIndex + 1) % allSlides.length;
                allSlides[currentIndex].classList.add('active');
                // Play incoming media
                const inVid = allSlides[currentIndex].querySelector('video');
                if (inVid) inVid.play();
                const inIframe = allSlides[currentIndex].querySelector('iframe');
                if (inIframe) inIframe.contentWindow.postMessage('{"event":"command","func":"playVideo","args":""}', '*');
            }, interval);
        }
    }

    function applyVideoBackground(video, sizing) {
        if (video.type === 'youtube') {
            const videoId = (video.url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i) || [])[1];
            if (videoId) {
                const iframe = document.createElement('iframe');
                iframe.src = `https://www.youtube.com/embed/${videoId}?autoplay=1&mute=1&loop=1&playlist=${videoId}&controls=0&rel=0&showinfo=0&iv_load_policy=3`;
                iframe.className = 'background-media youtube-background';
                iframe.setAttribute('frameborder', '0');
                iframe.setAttribute('allow', 'autoplay');
                backgroundContainer.appendChild(iframe);
            }
        } else if (video.type === 'local' || video.type === 'local_video') { // Handle both type names
            if (!backgroundVideoEl) return;
            // Only set the src if it's not already set correctly by the server
            if (!backgroundVideoEl.src || !backgroundVideoEl.src.endsWith(video.url)) {
                 backgroundVideoEl.src = video.url;
            }
            backgroundVideoEl.style.display = 'block';
            applySizing(backgroundVideoEl, sizing, true);
            backgroundVideoEl.play().catch(e => console.warn("Autoplay was prevented.", e));
        }
    }

    loadBackgroundSettings();
    
    // Hamburger Menu Logic (No changes needed)
    const menuToggle = document.querySelector('.menu-toggle');
    const body = document.body;
    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            body.classList.toggle('mobile-menu-active');
        });
    }
});