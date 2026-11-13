/*
 * File: /js/panel-youtube_playlist.js
 * Description: JavaScript for YouTube playlist panel with API integration and video controls
 * Version: 2025.07.29.1130.00
 */

(function() {
    'use strict';

    const container = document.querySelector('.youtube_container[data-playlist-id]');
    if (!container) { return; }

    // --- MODIFICATION: Read all settings from data-* attributes ---
    const PLAYLIST_ID = container.dataset.playlistId;
    const API_KEY = container.dataset.apiKey;
    const DEBUG_MODE = container.dataset.debugEnabled === 'true';

    // --- NEW: Debug Logger ---
    // A helper to only log messages when debug mode is enabled.
    function debugLog(message, data = '') {
        if (DEBUG_MODE) {
            console.log(`[YT Playlist Debug] ${message}`, data);
        }
    }

    debugLog('Script Initialized.');

    let player;

    function showError(message) { /* ... same as before ... */ }
    debugLog('Reading settings from panel:', {
        playlistId: PLAYLIST_ID,
        apiKey: API_KEY ? `...${API_KEY.slice(-4)}` : '(Not Set)', // Avoid logging full key
        debug: DEBUG_MODE
    });
    
    if (!PLAYLIST_ID || !API_KEY) {
        showError('Configuration is missing. Please set the YouTube Playlist ID and API Key in the admin manager.');
        debugLog('ERROR: Playlist ID or API Key is missing. Halting execution.');
        return;
    }

    function initPlaylistPlayer() {
        debugLog('Gatekeeper ready. Initializing player for container #playlist-player-container.');
        try {
            player = new YT.Player('playlist-player-container', {
                height: '360',
                width: '640',
                playerVars: { listType: 'playlist', list: PLAYLIST_ID, rel: 0, modestbranding: 1, showinfo: 0 },
                events: { 'onReady': onPlayerReady, 'onStateChange': onPlayerStateChange, 'onError': onPlayerError }
            });
        } catch (error) {
            debugLog('CRITICAL ERROR during YT.Player creation.', error);
            showError('Error initializing video player.');
        }
    }

    addYouTubeApiCallback(initPlaylistPlayer);
    debugLog('Player initialization function has been sent to the API gatekeeper.');

    function onPlayerReady(event) {
        debugLog('Player is ready. Muting player by default.');
        event.target.mute();
        fetchPlaylistItems();
    }

    function onPlayerError(event) {
        debugLog('A player error occurred.', event.data);
    }

    function onPlayerStateChange(event) {
        if (event.data === YT.PlayerState.PLAYING) {
            debugLog('Player state changed to PLAYING.');
            updateCurrentVideo(player.getVideoData().video_id);
        }
    }

    async function fetchPlaylistItems() {
        const url = `https://www.googleapis.com/youtube/v3/playlistItems?part=snippet,contentDetails&maxResults=50&playlistId=${PLAYLIST_ID}&key=${API_KEY}`;
        debugLog('Fetching playlist items from Google API...', url);
        try {
            const response = await fetch(url);
            debugLog('API Response Status:', response.status);
            if (!response.ok) {
                const errorText = await response.text();
                debugLog('API Error Response Body:', errorText);
                if(response.status === 404) throw new Error(`Playlist not found. Check the Playlist ID.`);
                if(response.status === 400) throw new Error(`API Key is invalid or restricted. Check the API Key.`);
                throw new Error(`API Error: ${response.statusText} (Status: ${response.status})`);
            }
            const data = await response.json();
            debugLog('API data received successfully.', data);
            displayPlaylist(data.items);
        } catch (error) {
            debugLog('ERROR fetching playlist items.', error.message);
            showError(`Could not load playlist: ${error.message}`);
        }
    }

    // --- (All other functions remain the same) ---
    function showError(message) { const grid = document.getElementById('playlist-grid'); if (grid) grid.innerHTML = `<div>${message}</div>`; }
    function escapeHtml(unsafe) { if (!unsafe) return ''; return unsafe.replace(/&/g, "&").replace(/</g, "<").replace(/>/g, ">").replace(/"/g, "\"").replace(/'/g, "'"); }
    function formatDate(dateString) { return typeof moment !== 'undefined' ? moment(dateString).fromNow() : new Date(dateString).toLocaleDateString(); }
    function showLoading(message) { const grid = document.getElementById('playlist-grid'); if (grid) grid.innerHTML = `<div>${escapeHtml(message)}</div>`; }
    let currentVideoId;
    function displayPlaylist(items) { /* ... as before ... */ }
    window.playVideo = function(videoId) { /* ... as before ... */ }
    async function updateCurrentVideo(videoId) { /* ... as before ... */ }
    
    // Paste in the full functions if they were lost
    function displayPlaylist(items) {
        const grid = document.getElementById('playlist-grid');
        if (!items || items.length === 0) { showError('No videos found in this playlist.'); return; }
        debugLog(`Displaying ${items.length} items.`);
        grid.innerHTML = items.map(item => {
            const { snippet } = item; if (!item.contentDetails?.videoId) return '';
            return `<div class="playlist-item" data-video-id="${item.contentDetails.videoId}" tabindex="0"><div class="thumbnail-container"><img src="${snippet.thumbnails?.high?.url || ''}" alt="${escapeHtml(snippet.title)}" class="thumbnail" loading="lazy"></div><div class="video-info"><h3 class="video-title">${escapeHtml(snippet.title)}</h3><div class="video-meta">${formatDate(snippet.publishedAt)}</div><p class="video-desc">${escapeHtml(snippet.description)}</p></div></div>`;
        }).join('');
        grid.querySelectorAll('.playlist-item').forEach(item => {
            item.addEventListener('click', () => playVideo(item.dataset.videoId));
            item.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); playVideo(item.dataset.videoId); } });
        });
    }
    window.playVideo = function(videoId) {
        if (!player) return;
        debugLog(`Playing video ID: ${videoId}`);
        player.loadVideoById(videoId);
        player.unMute();
        updateCurrentVideo(videoId);
        container?.scrollTo({ top: 0, behavior: 'smooth' });
    }
    async function updateCurrentVideo(videoId) {
        if (videoId === currentVideoId) return; currentVideoId = videoId;
        try {
            const response = await fetch(`https://www.googleapis.com/youtube/v3/videos?part=snippet&id=${videoId}&key=${API_KEY}`);
            const data = await response.json(); if (data.items?.length > 0) {
                const { title, description } = data.items[0].snippet;
                document.getElementById('current-title').textContent = title;
                document.getElementById('current-desc').textContent = description;
            }
        } catch (error) { debugLog('Error fetching video details:', error); }
    }

})();