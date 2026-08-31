<?php
/**
 * YouTube Playlist Studio — Admin UI
 * @file admin/modules/YouTubePlaylist/YouTubePlaylist.php
 *
 * Card grid for managing multiple YouTube playlists.
 * Each playlist has its own API key, playlist ID, and display settings.
 * Shortcode: [[youtube-playlist:slug]]
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once __DIR__ . '/../_runtime/guard.php';
guard_require_auth();

/* ---- Auto-migrate from old single-playlist settings ---- */
$dataDir = SITE_ROOT . '/admin/data/youtube_playlists';
$cacheDir = $dataDir . '/cache';
$oldSettings = SITE_ROOT . '/admin/data/youtube_playlist_settings.json';

if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); @chown($dataDir, 'www-data'); @chgrp($dataDir, 'www-data'); }
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); @chown($cacheDir, 'www-data'); @chgrp($cacheDir, 'www-data'); }

// Migrate old settings if youtube_playlists/ is empty and old file exists
$existingPlaylists = glob("$dataDir/*.json");
if (empty($existingPlaylists) && is_file($oldSettings)) {
    $old = json_decode(file_get_contents($oldSettings), true);
    if (is_array($old) && !empty($old['playlist_id'])) {
        $migrated = [
            'title'            => 'Default Playlist',
            'slug'             => 'default',
            'api_key'          => $old['api_key'] ?? '',
            'playlist_id'      => $old['playlist_id'] ?? '',
            'per_page'         => 24,
            'show_description' => (bool)($old['show_description'] ?? false),
            'columns'          => 4,
            'player_max'       => (int)($old['player_max'] ?? 100),
            'grid_match'       => (bool)($old['grid_match'] ?? false),
            'card_gap'         => (int)($old['card_gap'] ?? 10),
            'created'          => date('c'),
            'updated'          => date('c'),
        ];
        $mf = "$dataDir/default.json";
        file_put_contents($mf, json_encode($migrated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chown($mf, 'www-data');
        @chgrp($mf, 'www-data');
    }
}

@include SITE_ROOT . '/admin/admin_header.php';
?>
<h1 class="panel_header_h1" style="color:white">YT Playlist Studio</h1>

<?php
/* Cache-bust on file mtime. Without this the browser keeps serving the JS/CSS it
   already has: you get the new server-rendered markup but the OLD openModal(),
   which is the one that leaves the YouTube URL box blank — so a shipped fix looks
   like nothing changed. Same treatment footer.php already gives age-gate.js/.css
   for exactly this reason (Brave and other aggressive caches). */
$ypsCssV = @filemtime(__DIR__ . '/css/yt-playlist-studio.css') ?: time();
$ypsJsV  = @filemtime(__DIR__ . '/js/yt-playlist-studio.js')  ?: time();
?>
<link rel="stylesheet" href="/admin/modules/YouTubePlaylist/css/yt-playlist-studio.css?v=<?= $ypsCssV ?>">

<div class="yps-wrap">
    <div class="yps-grid" id="yps-grid">
        <div class="yps-empty">Loading...</div>
    </div>
</div>

<!-- Modal -->
<div class="yps-overlay" id="yps-overlay" onclick="if(event.target===this)ytStudio.closeModal()">
    <div class="yps-modal">
        <div class="yps-modal-header">
            <h3 id="yps-modal-title">Edit Playlist</h3>
            <div class="yps-modal-actions">
                <button class="yps-btn yps-btn-danger yps-btn-sm" id="yps-btn-delete" onclick="ytStudio.deletePlaylist()">Delete</button>
                <button class="yps-btn yps-btn-secondary yps-btn-sm" onclick="ytStudio.closeModal()">Close</button>
            </div>
        </div>

        <div class="yps-modal-body">
            <!-- ⭐ Smart Add — paste ANY YouTube URL; we detect playlist vs single -->
            <div class="yps-field yps-smart">
                <h4 class="yps-sec-head yps-sec-smart">⭐ Smart Add</h4>
                <label for="yps-f-smart-url">YouTube URL <span class="yps-smart-sub">— playlist or single, we'll figure it out</span></label>
                <div class="yps-inline-row">
                    <input type="text" id="yps-f-smart-url" placeholder="Paste a YouTube playlist or video URL…">
                    <button class="yps-btn yps-btn-primary yps-btn-sm" onclick="ytStudio.smartResolve()" type="button">Resolve</button>
                </div>
                <small class="yps-hint" id="yps-smart-status"></small>
                <div class="yps-preview-grid" id="yps-smart-preview" hidden></div>
            </div>

            <h4 class="yps-sec-head yps-sec-identity">🏷️ Type &amp; Naming</h4>

            <!-- Type selector — auto-set by Resolve; override if needed -->
            <div class="yps-type-row">
                <label class="yps-type-opt">
                    <input type="radio" name="yps-f-type" value="playlist" id="yps-f-type-playlist" checked>
                    <span>📋 Playlist</span>
                </label>
                <label class="yps-type-opt">
                    <input type="radio" name="yps-f-type" value="video" id="yps-f-type-video">
                    <span>▶️ Single Video</span>
                </label>
                <label class="yps-type-opt">
                    <input type="radio" name="yps-f-type" value="channel" id="yps-f-type-channel">
                    <span>📺 Channel</span>
                </label>
            </div>

            <div class="yps-field">
                <label for="yps-f-title">Title</label>
                <input type="text" id="yps-f-title" placeholder="My Playlist or Video">
            </div>

            <div class="yps-field">
                <label for="yps-f-slug">Slug</label>
                <input type="text" id="yps-f-slug" placeholder="my-playlist">
            </div>

            <!-- ── SOURCE — always visible, for BOTH types ──────────────
                 These fields hold what the record actually points at. They used
                 to be split across two mode-gated blocks (and the API key was
                 buried in a collapsed <details>), so reopening a saved record
                 showed an empty URL box and no obvious way to see the stored ID.
                 Nothing here is secret: every value is already sent to this page
                 by the list endpoint. Visible and editable, per
                 feedback_design_old_school_analog_visible_compact. -->
            <fieldset class="yps-source">
                <legend class="yps-sec-head yps-sec-source">🔗 Source — what this entry points at</legend>

                <div class="yps-field">
                    <label for="yps-f-playlist-id">YouTube Playlist ID <span class="yps-smart-sub">(or paste a full playlist URL)</span></label>
                    <input type="text" id="yps-f-playlist-id" placeholder="PLxxx… — used when type is Playlist" spellcheck="false">
                </div>

                <div class="yps-field">
                    <label for="yps-f-channel-url">YouTube Channel URL <span class="yps-smart-sub">(used when type is Channel — /channel/UC…, /@handle, or an auto-generated “- Topic” channel)</span></label>
                    <div class="yps-inline-row">
                        <input type="text" id="yps-f-channel-url" placeholder="https://www.youtube.com/channel/UC… or https://www.youtube.com/@handle" spellcheck="false">
                        <button class="yps-btn yps-btn-secondary yps-btn-sm" onclick="ytStudio.resolveChannel()" type="button">Resolve</button>
                    </div>
                    <small class="yps-hint" id="yps-channel-resolve-status"></small>
                </div>

                <div class="yps-field">
                    <label for="yps-f-channel-id">Channel ID <span class="yps-smart-sub">(UC… — filled by Resolve; this is the value that gets saved)</span></label>
                    <input type="text" id="yps-f-channel-id" placeholder="UCxxxxxxxxxxxxxxxxxxxxxx" spellcheck="false">
                </div>

                <div class="yps-field">
                    <label for="yps-f-video-url">YouTube Video URL or ID <span class="yps-smart-sub">(used when type is Single Video)</span></label>
                    <div class="yps-inline-row">
                        <input type="text" id="yps-f-video-url" placeholder="https://www.youtube.com/watch?v=… or the 11-character ID" spellcheck="false">
                        <button class="yps-btn yps-btn-secondary yps-btn-sm" onclick="ytStudio.resolveVideo()" type="button">Resolve</button>
                    </div>
                    <small class="yps-hint" id="yps-video-resolve-status"></small>
                    <div class="yps-preview-grid" id="yps-video-preview" hidden></div>
                </div>

                <div class="yps-field">
                    <label for="yps-f-api-key">YouTube API Key <span class="yps-smart-sub">(blank = use the site default from Settings)</span></label>
                    <input type="text" id="yps-f-api-key" placeholder="Uses site default key" spellcheck="false">
                </div>

                <!-- Read-back of every value actually on disk for this record,
                     including whatever the YouTube API returned at save time. -->
                <div class="yps-stored" id="yps-stored-data" hidden></div>
            </fieldset>

            <!-- ── Single-Video presentation ───────────────────────────── -->
            <div class="yps-mode yps-mode-video" hidden>
                <h4 class="yps-sec-head yps-sec-video">▶️ Single Video — Display</h4>
                <div class="yps-field">
                    <label for="yps-f-video-desc">Description (optional, shown under player)</label>
                    <textarea id="yps-f-video-desc" rows="3" placeholder="Optional video description"></textarea>
                </div>
                <div class="yps-row">
                    <div class="yps-field">
                        <label for="yps-f-aspect">Aspect Ratio</label>
                        <select id="yps-f-aspect">
                            <option value="16/9">16:9 (landscape)</option>
                            <option value="9/16">9:16 (portrait / shorts)</option>
                            <option value="1/1">1:1 (square)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ── Playlist presentation ───────────────────────────────
                 API key and Playlist ID moved up into the always-visible
                 Source fieldset, 2026-08-17. -->
            <div class="yps-mode yps-mode-playlist">
                <h4 class="yps-sec-head yps-sec-playlist">📋 Grid &amp; Player <span class="yps-smart-sub">— playlists and channels</span></h4>
                <div class="yps-row">
                    <div class="yps-field">
                        <label for="yps-f-per-page">Videos per Page (1-50)</label>
                        <input type="number" id="yps-f-per-page" value="24" min="1" max="50">
                    </div>
                    <div class="yps-field">
                        <label for="yps-f-max-pull">Max Pull from YT (1-50)</label>
                        <input type="number" id="yps-f-max-pull" value="50" min="1" max="50">
                    </div>
                    <div class="yps-field">
                        <label for="yps-f-columns">Grid Columns</label>
                        <input type="number" id="yps-f-columns" value="4" min="1" max="8">
                    </div>
                    <div class="yps-field">
                        <label for="yps-f-card-gap">Card Spacing (px)</label>
                        <input type="number" id="yps-f-card-gap" value="10" min="0" max="80">
                    </div>
                    <div class="yps-field">
                        <label for="yps-f-player-size">Player Size <span id="yps-f-player-size-val" class="yps-slider-val">100%</span></label>
                        <input type="range" id="yps-f-player-size" value="100" min="30" max="100" step="5"
                               oninput="var v=document.getElementById('yps-f-player-size-val'); if(v) v.textContent=this.value+'%';">
                        <label class="yps-inline-check"><input type="checkbox" id="yps-f-grid-match"> Match player width (cards)</label>
                    </div>
                </div>
            </div>

            <div class="yps-check">
                <input type="checkbox" id="yps-f-show-desc">
                <label for="yps-f-show-desc">Show description by default</label>
            </div>

            <h4 class="yps-sec-head yps-sec-playback yps-mode-playlist">🎛️ Playback</h4>

            <!-- Playback (playlist only) — visible, not buried in an Advanced drawer -->
            <div class="yps-check yps-mode-playlist">
                <input type="checkbox" id="yps-f-auto-advance" checked>
                <label for="yps-f-auto-advance">Auto-advance to next video when one ends</label>
            </div>
            <div class="yps-check yps-mode-playlist">
                <input type="checkbox" id="yps-f-loop-playlist">
                <label for="yps-f-loop-playlist">Loop entire playlist (restart at video 1 after the last)</label>
            </div>

            <!-- Preview (playlist only) -->
            <div class="yps-preview-section yps-mode yps-mode-playlist">
                <h4 class="yps-sec-head yps-sec-preview">👁️ Preview</h4>
                <div class="yps-preview-bar">
                    <button class="yps-btn yps-btn-secondary yps-btn-sm" onclick="ytStudio.loadPreview()">Load Preview</button>
                    <span class="yps-preview-status" id="yps-preview-status"></span>
                </div>
                <div class="yps-preview-grid" id="yps-preview-grid"></div>
            </div>
        </div>

        <div class="yps-modal-footer">
            <span class="yps-shortcode-hint" id="yps-shortcode-hint">[[youtube-playlist:...]]</span>
            <button class="yps-btn yps-btn-primary" onclick="ytStudio.savePlaylist()">Save</button>
        </div>
    </div>
</div>

<script src="/admin/modules/YouTubePlaylist/js/yt-playlist-studio.js?v=<?= $ypsJsV ?>"></script>
