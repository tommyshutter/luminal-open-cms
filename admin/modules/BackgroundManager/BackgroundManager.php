<?php
/**
 * Luminal CMS — Background Manager Module
 *
 * Background image/video manager with slideshow support, drag-and-drop
 * upload pad, SortableJS playlist ordering, and live preview panel.
 *
 * @package    LuminalCMS
 * @module     BackgroundManager
 * @version    1.0.0
 * @file       /admin/modules/BackgroundManager/BackgroundManager.php
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}

require_once SITE_ROOT . '/admin/config/site_config.php';
require_once SITE_ROOT . '/admin/modules/UserManager/guard.php';
require_once __DIR__ . '/BackgroundManagerFunctions.php';

guard_require_auth();

// Fetch all data required for the manager in one go
$bg_data = bgm_get_all_data();
$settings = $bg_data['settings'];
$playlists = $bg_data['playlists'];

// Prepare color and opacity values for the form controls
preg_match('/rgba\((\d+),(\d+),(\d+),([\d\.]+)\)/', $settings['overlay']['color_rgba'], $rgba_matches);
$hex_color = sprintf("#%02x%02x%02x", $rgba_matches[1] ?? 0, $rgba_matches[2] ?? 0, $rgba_matches[3] ?? 0);
$opacity = $rgba_matches[4] ?? 0.7;

// Include Luminal admin header
require_once SITE_ROOT . '/admin/admin_header.php';
?>

<!-- =========================================================================
     Luminal Background Manager — Stage 2 Phase 1 workspace
     Structural refactor: replaces the accordion layout with a tabbed
     workspace (Master · Video · Image · Slideshow) and a persistent live
     preview rail on the right. All existing element IDs are preserved so
     the existing JS module keeps functioning; Phase 2+ iterations polish
     the contents inside each tab.
     ========================================================================= -->
<h1 class="panel_header_h1" style="color:white">Background Manager</h1>

<link rel="stylesheet" href="<?= sc_asset('/admin/modules/BackgroundManager/css/background_mgr.css') ?>">
<link rel="stylesheet" href="<?= sc_asset('/admin/modules/BackgroundManager/css/background_mgr_media.css') ?>">

<div class="content-area">
  <div class="bgm-workspace">

    <!-- =================  PREVIEW BAR (top, above tabs)  ================= -->
    <aside class="bgm-preview-rail" aria-label="Live preview">
      <div class="bgm-preview-rail-head">
        <h2>Live Preview</h2>
        <span class="bgm-preview-rail-sub">updates as you work</span>
      </div>
      <div id="background-preview-panel"></div>
    </aside>

    <!-- =================  MAIN COLUMN  ================= -->
    <main class="bgm-main-column">

      <div class="bgm-utility-bar">
        <span class="bgm-utility-bar-spacer"></span>
        <button type="button" id="bgm-clear-cache-btn" class="bgm-utility-btn" onclick="bgmClearCache(event)"
                title="Wipe thumbnail cache and reload — fixes corrupt or out-of-sync gallery thumbnails">&#8635; Clear Cache</button>
      </div>

      <div id="admin-message-panel" class="admin-message-panel"></div>

      <!-- ============= MASTER (always visible) ============= -->
      <section class="bgm-tab-panel is-active" id="bgm-panel-master" data-bgm-panel="master">
        <div class="bgm-section-head">
          <h2>Master</h2>
          <p class="bgm-section-sub">Cross-cutting settings &mdash; active mode, sizing, overlay, and the currently wired playlist or YouTube background.</p>
        </div>

        <div class="control-panel bgm-panel-inset">
          <div class="master-controls-wrapper">
            <div class="master-controls-col">
              <div class="control-group">
                <label>Active Mode:</label>
                <div class="radio-group">
                  <label><input type="radio" name="active_mode" value="image"> Image</label>
                  <label><input type="radio" name="active_mode" value="video"> Video</label>
                  <label><input type="radio" name="active_mode" value="slideshow"> Slideshow</label>
                </div>
              </div>
              <div class="control-group">
                <label>Sizing:</label>
                <div class="radio-group">
                  <label><input type="radio" name="background_sizing" value="cover"> Cover</label>
                  <label><input type="radio" name="background_sizing" value="contain"> Contain</label>
                </div>
              </div>
            </div>
            <div class="master-controls-col">
              <div class="control-group">
                <label>Overlay Color:</label>
                <input type="color" id="overlay-color-input" value="<?php echo $hex_color; ?>">
              </div>
              <div class="control-group">
                <label>Overlay Opacity (<span id="opacity-value"><?php echo $opacity; ?></span>):</label>
                <input type="range" id="overlay-opacity-slider" min="0" max="1" step="0.05" value="<?php echo $opacity; ?>">
              </div>
            </div>
          </div>
          <div class="control-group">
            <label>Active Playlist:</label>
            <select id="active-playlist-select" class="form-control">
              <option value="">-- None --</option>
              <?php if (is_array($playlists)) : foreach ($playlists as $playlist): ?>
                <option value="<?php echo htmlspecialchars($playlist['name']); ?>"><?php echo htmlspecialchars($playlist['name']); ?></option>
              <?php endforeach; endif; ?>
            </select>
          </div>
          <div class="control-group">
            <label for="youtube-url-input">Set or Add YouTube BG:</label>
            <div class="youtube-input-group">
              <input type="text" id="youtube-url-input" class="form-control" placeholder="Paste YouTube URL here...">
              <button id="add-youtube-btn" class="admin-button-sm" title="Add this YouTube video to your permanent Video Gallery">Add to Gallery</button>
            </div>
          </div>
          <div class="button-group">
            <button id="save-master-settings-btn" class="admin-button-success full-width">Save Master Settings</button>
          </div>
        </div>
      </section>

      <!-- ============= VIDEO (inlines when Active Mode = video) ============= -->
      <section class="bgm-tab-panel bgm-mode-panel" id="bgm-panel-video" data-bgm-panel="video" data-bgm-mode="video" hidden>
        <div class="bgm-section-head">
          <h2>Video Gallery</h2>
          <p class="bgm-section-sub">Pick a video to use as your background, or drop in new ones. YouTube URLs added above show up here too. When a thumb is selected, hit <em>Save Master Settings</em> to apply it.</p>
        </div>
        <div class="bgm-tab-toolbar" role="toolbar" aria-label="Video gallery filters">
          <label class="bgm-chooser">
            <span class="bgm-chooser-label">Show</span>
            <select id="video-filter-source" class="fc-select" data-bgm-filter="video-source">
              <option value="all">All videos</option>
              <option value="local">Local files only</option>
              <option value="youtube">YouTube only</option>
            </select>
          </label>
          <label class="bgm-chooser">
            <span class="bgm-chooser-label">Sort</span>
            <select id="video-sort" class="fc-select" data-bgm-sort="video">
              <option value="name-asc">Name &mdash; A to Z</option>
              <option value="name-desc">Name &mdash; Z to A</option>
              <option value="date-desc" selected>Newest first</option>
              <option value="date-asc">Oldest first</option>
            </select>
          </label>
          <span class="bgm-toolbar-spacer"></span>
          <span class="bgm-toolbar-count"><span id="video-visible-count">0</span> of <span id="video-total-count">0</span></span>
        </div>
        <div class="upload-dropzone" data-type="videos" id="video-dropzone">
          <input type="file" class="dropzone-file-input" multiple accept="video/mp4,video/webm,video/ogg">
          <div class="dropzone-content">
            <span class="dropzone-icon">&#8679;</span>
            <span class="dropzone-text">Drop video files here or <strong>click to browse</strong></span>
            <span class="dropzone-hint">MP4, WebM, OGV</span>
          </div>
          <div class="dropzone-progress" style="display:none;">
            <div class="dropzone-progress-bar"></div>
            <span class="dropzone-progress-text">Uploading...</span>
          </div>
        </div>
        <div id="video-grid" class="media-grid"></div>
      </section>

      <!-- ============= IMAGE (inlines when Active Mode = image) ============= -->
      <section class="bgm-tab-panel bgm-mode-panel" id="bgm-panel-image" data-bgm-panel="image" data-bgm-mode="image" hidden>
        <div class="bgm-section-head">
          <h2>Image Gallery</h2>
          <p class="bgm-section-sub">Pick an image to use as your background, or drop in new ones. When a thumb is selected, hit <em>Save Master Settings</em> to apply it.</p>
        </div>
        <div class="bgm-tab-toolbar" role="toolbar" aria-label="Image gallery filters">
          <label class="bgm-chooser">
            <span class="bgm-chooser-label">Sort</span>
            <select id="image-sort" class="fc-select" data-bgm-sort="image">
              <option value="name-asc">Name &mdash; A to Z</option>
              <option value="name-desc">Name &mdash; Z to A</option>
              <option value="date-desc" selected>Newest first</option>
              <option value="date-asc">Oldest first</option>
            </select>
          </label>
          <span class="bgm-toolbar-spacer"></span>
          <span class="bgm-toolbar-count"><span id="image-visible-count">0</span> of <span id="image-total-count">0</span></span>
        </div>
        <div class="upload-dropzone" data-type="images" id="image-dropzone">
          <input type="file" class="dropzone-file-input" multiple accept="image/jpeg,image/png,image/gif,image/webp">
          <div class="dropzone-content">
            <span class="dropzone-icon">&#8679;</span>
            <span class="dropzone-text">Drop image files here or <strong>click to browse</strong></span>
            <span class="dropzone-hint">JPG, PNG, GIF, WebP</span>
          </div>
          <div class="dropzone-progress" style="display:none;">
            <div class="dropzone-progress-bar"></div>
            <span class="dropzone-progress-text">Uploading...</span>
          </div>
        </div>
        <div id="image-grid" class="media-grid"></div>
      </section>

      <!-- ============= SLIDESHOW (inlines when Active Mode = slideshow) ============= -->
      <section class="bgm-tab-panel bgm-mode-panel" id="bgm-panel-slideshow" data-bgm-panel="slideshow" data-bgm-mode="slideshow" hidden>
        <div class="bgm-section-head">
          <h2>Slideshow Editor</h2>
          <p class="bgm-section-sub">Build a playlist from your galleries. Drag media into the tray, reorder, set slide duration. <em>Save Playlist</em> writes the playlist itself; <em>Save Master Settings</em> makes it the active background for the site.</p>
        </div>

        <div class="slideshow-controls-grid">
          <div class="form-group">
            <label for="playlist-select">Load Playlist to Edit:</label>
            <select id="playlist-select" class="form-control">
              <option value="">-- Start a New Playlist --</option>
              <?php if (is_array($playlists)) : foreach ($playlists as $playlist): ?>
                <option value="<?php echo htmlspecialchars($playlist['name']); ?>"><?php echo htmlspecialchars($playlist['name']); ?></option>
              <?php endforeach; endif; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="playlist-name-input">Playlist Name:</label>
            <input type="text" id="playlist-name-input" class="form-control" placeholder="Enter a name to save or rename...">
          </div>
          <div class="form-group">
            <label for="playlist-duration-select">Slide Duration:</label>
            <select id="playlist-duration-select" class="form-control">
              <option value="5000">5 Seconds</option>
              <option value="10000">10 Seconds</option>
              <option value="20000">20 Seconds</option>
              <option value="40000">40 Seconds</option>
              <option value="60000">1 Minute</option>
              <option value="600000">10 Minutes</option>
              <option value="1800000">30 Minutes</option>
              <option value="3600000">1 Hour</option>
              <option value="86400000">24 Hours</option>
            </select>
          </div>
        </div>

        <div class="playlist-editor-section">
          <p class="playlist-editor-hint">Drag images or videos into this tray from the library below. Drag within the tray to reorder.</p>
          <div id="slideshow-tray" class="media-grid slideshow-tray"></div>
          <div class="button-group">
            <button id="save-playlist-btn"   class="admin-button-success">Save Playlist</button>
            <button id="clear-tray-btn"      class="admin-button">Clear</button>
            <button id="delete-playlist-btn" class="admin-button-danger">Delete</button>
          </div>
        </div>

        <!-- ───────────── Inline Media Library (slideshow-local) ─────────────
             Read-only copies of the Image + Video galleries so the user
             can drag into the tray without tab-hopping. CRUD still lives
             on the Video / Image tabs — no delete buttons here. Phase 2
             will upgrade the filter pulldown to the full fc-combobox
             pattern; for now the fc-select is the first pulldown in the
             new component catalog. -->
        <div class="bgm-slideshow-library">
          <div class="bgm-library-head">
            <h3>Media Library</h3>
            <label class="bgm-chooser">
              <span class="bgm-chooser-label">Show</span>
              <select id="slideshow-library-filter" class="fc-select">
                <option value="all">Images &amp; Videos</option>
                <option value="images">Images only</option>
                <option value="videos">Videos only</option>
              </select>
            </label>
          </div>
          <p class="bgm-library-hint">Drag any thumb into the tray above. Deleting or uploading happens on the Image and Video tabs — this library stays in sync after a refresh.</p>

          <div class="bgm-library-section" data-library-kind="videos">
            <div class="bgm-library-section-head"><span>Videos</span><span class="bgm-library-count" id="slideshow-lib-video-count">0</span></div>
            <div id="slideshow-lib-videos" class="media-grid"></div>
          </div>

          <div class="bgm-library-section" data-library-kind="images">
            <div class="bgm-library-section-head"><span>Images</span><span class="bgm-library-count" id="slideshow-lib-image-count">0</span></div>
            <div id="slideshow-lib-images" class="media-grid"></div>
          </div>
        </div>
      </section>

      <div class="bgm-workspace-footer">
        <button id="rescan-media-btn" class="admin-button">&#8635; Refresh Media Library</button>
      </div>

    </main>

  </div>
</div>

<script>const initialBgData = <?php echo json_encode($bg_data, JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<link rel="stylesheet" href="<?= sc_asset('/admin/shared/upload-progress/upload-progress.css') ?>">
<script src="<?= sc_asset('/admin/shared/upload-progress/upload-progress.js') ?>"></script>
<script src="<?= sc_asset('/admin/modules/BackgroundManager/js/background_mgr.js') ?>"></script>
<script>
function bgmClearCache(e) {
    e.stopPropagation(); // don't toggle accordion
    var btn = document.getElementById('bgm-clear-cache-btn');
    btn.disabled = true;
    btn.textContent = 'Clearing…';
    fetch('/admin/modules/BackgroundManager/api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'clear_thumb_cache'})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.success) {
            btn.textContent = '✓ Cleared — reloading…';
            btn.style.color = '#4ade80';
            setTimeout(function(){ location.reload(); }, 800);
        } else {
            btn.textContent = '✗ ' + (d.message || 'Error');
            btn.style.color = '#f87171';
            btn.disabled = false;
        }
    })
    .catch(function(){
        btn.textContent = '✗ Network error';
        btn.disabled = false;
    });
}
</script>