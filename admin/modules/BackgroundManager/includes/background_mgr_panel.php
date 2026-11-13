<?php
/**
 * @location    SITE-ROOT/admin/background_mgr/background_mgr_panel.php
 * @version     2025.07.29.1315
 * @description The UI panel with the two-column layout.
 */
?>
<div class="background-manager-layout">

    <div class="bm-left-column">
        <div class="control-panel">
            <h2>Master Controls</h2>
            <div class="master-controls-wrapper">
                <div class="master-controls-col">
                    <div class="control-group">
                        <label>Active Background Mode</label>
                        <div class="radio-group" id="mode-shifter">
                            <label><input type="radio" name="active_mode" value="image"> Image</label>
                            <label><input type="radio" name="active_mode" value="video"> Video</label>
                            <label><input type="radio" name="active_mode" value="slideshow"> Slideshow</label>
                        </div>
                    </div>
                    <div class="control-group">
                        <label>Background Sizing</label>
                        <div class="radio-group">
                            <label><input type="radio" name="background_sizing" value="cover"> Cover</label>
                            <label><input type="radio" name="background_sizing" value="contain"> Contain</label>
                        </div>
                    </div>
                     <div class="control-group">
                        <label for="active-playlist-select">Active Slideshow Playlist</label>
                         <select id="active-playlist-select">
                            <option value="">-- None --</option>
                            <?php foreach ($playlists as $playlist): ?>
                                <option value="<?php echo htmlspecialchars($playlist['id']); ?>"><?php echo htmlspecialchars($playlist['name']); ?></option>
                            <?php endforeach; ?>
                         </select>
                    </div>
                </div>
                <div class="master-controls-col">
                     <div class="control-group">
                        <label>Overlay Color</label>
                        <input type="color" id="overlay-color-input">
                    </div>
                    <div class="control-group">
                        <label>Overlay Opacity (<span id="opacity-value"></span>)</label>
                        <input type="range" id="overlay-opacity-slider" min="0" max="1" step="0.05">
                    </div>
                     <div class="control-group">
                        <label for="slideshow-duration">Slide Duration (sec)</label>
                        <input type="number" id="slideshow-duration" min="1">
                    </div>
                    <div class="control-group">
                        <label for="slideshow-transition">Transition (sec)</label>
                        <input type="number" id="slideshow-transition" min="0" step="0.1">
                    </div>
                </div>
            </div>
             <div class="control-group">
                <label for="youtube-url-input">YouTube Video URL</label>
                <div class="youtube-input-group">
                    <input type="url" id="youtube-url-input" placeholder="e.g., https://www.youtube.com/watch?v=...">
                    <button type="button" id="add-youtube-btn" class="admin-button-sm" title="Permanently add this video to your media gallery">Add to Gallery</button>
                </div>
            </div>
            <button id="save-master-settings-btn" class="admin-button-primary full-width">Save Master Settings</button>
        </div>

        <div class="preview-panel-wrapper">
            <h2>Live Preview</h2>
            <div id="background-preview-panel"></div>
        </div>
    </div>

    <div class="bm-right-column">
        <div class="accordion-item" id="slideshow-editor-accordion">
            <div class="accordion-header"><h3>Slideshow Editor</h3><span class="accordion-icon"></span></div>
            <div class="accordion-content">
                <div class="slideshow-controls">
                    <select id="playlist-select">
                        <option value="">-- Load Playlist to Edit --</option>
                         <?php foreach ($playlists as $playlist): ?>
                            <option value="<?php echo htmlspecialchars($playlist['id']); ?>"><?php echo htmlspecialchars($playlist['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="playlist-name-input" placeholder="Enter new or existing playlist name">
                    <button id="save-playlist-btn" class="admin-button-primary">Save</button>
                    <button id="delete-playlist-btn" class="admin-button-danger">Delete</button>
                    <button id="clear-tray-btn" class="admin-button">Clear Tray</button>
                </div>
                <div id="slideshow-tray" class="slideshow-tray"><p>Drag images from the library here.</p></div>
            </div>
        </div>
        <div class="accordion-item" id="image-gallery-accordion">
            <div class="accordion-header"><h3>Image Gallery</h3><span class="accordion-icon"></span></div>
            <div class="accordion-content">
                <div id="image-grid" class="media-grid"></div>
            </div>
        </div>
        <div class="accordion-item" id="video-gallery-accordion">
            <div class="accordion-header"><h3>Video Gallery</h3><span class="accordion-icon"></span></div>
            <div class="accordion-content">
                <div id="video-grid" class="media-grid"></div>
            </div>
        </div>
    </div>
</div>