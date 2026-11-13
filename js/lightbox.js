/**
 * @appname     The Site UI
 * @file        SITE-ROOT/js/lightbox.js
 * @version     2025.09.05.0110-EST
 * @author      ChatGPT
 * @license     Business Source License
 * @description Unified lightbox (images, PDFs, and videos).
 *               Triggers on any element (or ancestor) that has data-lightbox="image|pdf|video".
 *               PDF uses <embed>; video auto-detects YouTube/Vimeo or uses <video>.
 */

(function () {
  'use strict';

  // Elements
  const lightbox = document.getElementById('lightbox');
  const content  = document.getElementById('lightbox-content');
  const closeBtn = document.getElementById('lightbox-close');

  if (!lightbox || !content || !closeBtn) {
    console.error('[lightbox] Missing lightbox container/parts in footer.php');
    return;
  }

  function clearContent() {
    while (content.firstChild) content.removeChild(content.firstChild);
  }

  function closeLightbox() {
    clearContent();
    lightbox.classList.remove('active');
    // allow page scroll again
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
  }

  function openWithImage(src, alt) {
    const img = document.createElement('img');
    img.src = src;
    if (alt) img.alt = alt;
    img.decoding = 'async';
    img.loading = 'eager';
    content.appendChild(img);
    openShell();
  }

  function openWithPDF(src) {
    // Use <embed> with fallback to <iframe> if needed
    const embed = document.createElement('embed');
    embed.type = 'application/pdf';
    embed.src = src;
    embed.style.width  = '80vw';
    embed.style.height = '90vh';
    content.appendChild(embed);
    openShell();
  }

  function ytId(url) {
    // Minimal YouTube ID parse
    const m = String(url).match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([A-Za-z0-9_\-]{6,})/i);
    return m ? m[1] : null;
  }

  function vimeoId(url) {
    const m = String(url).match(/vimeo\.com\/(?:video\/)?(\d+)/i);
    return m ? m[1] : null;
  }

  function openWithVideo(url) {
    const y = ytId(url);
    if (y) {
      const iframe = document.createElement('iframe');
      iframe.src = `https://www.youtube.com/embed/${y}?autoplay=1&rel=0`;
      iframe.width = '1280';
      iframe.height = '720';
      iframe.allow = 'autoplay; fullscreen; picture-in-picture';
      iframe.allowFullscreen = true;
      iframe.frameBorder = '0';
      content.appendChild(iframe);
      openShell();
      return;
    }
    const v = vimeoId(url);
    if (v) {
      const iframe = document.createElement('iframe');
      iframe.src = `https://player.vimeo.com/video/${v}?autoplay=1`;
      iframe.width = '1280';
      iframe.height = '720';
      iframe.allow = 'autoplay; fullscreen; picture-in-picture';
      iframe.allowFullscreen = true;
      iframe.frameBorder = '0';
      content.appendChild(iframe);
      openShell();
      return;
    }
    // Local/other videos
    const video = document.createElement('video');
    video.src = url;
    video.controls = true;
    video.autoplay = true;
    video.playsInline = true;
    video.setAttribute('data-vf-float', '');
    video.style.maxWidth = '90vw';
    video.style.maxHeight = '80vh';
    content.appendChild(video);
    openShell();
  }

  function openShell() {
    lightbox.classList.add('active');
    // prevent background scroll
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
  }

  // Delegated click handler
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-lightbox]');
    if (!trigger) return;

    const type = (trigger.getAttribute('data-lightbox') || '').toLowerCase().trim();
    if (!type) return;

    let href = trigger.getAttribute('href') || trigger.dataset.href || '';
    if (!href && trigger.dataset.src) href = trigger.dataset.src;
    if (!href) return;

    // Never follow the link; we handle it.
    e.preventDefault();
    e.stopPropagation();

    if (type === 'image') {
      const alt = trigger.getAttribute('data-alt') || trigger.getAttribute('title') || '';
      openWithImage(href, alt);
    } else if (type === 'pdf') {
      openWithPDF(href);
    } else if (type === 'video') {
      openWithVideo(href);
    }
  });

  // Closing hooks
  closeBtn.addEventListener('click', closeLightbox);
  lightbox.addEventListener('click', (e) => {
    if (e.target === lightbox) closeLightbox();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeLightbox();
  });
})();
