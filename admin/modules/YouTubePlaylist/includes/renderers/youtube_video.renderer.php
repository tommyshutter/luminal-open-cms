<?php
/**
 * YouTube Single Video Shortcode Renderer
 * @file includes/renderers/youtube_video.renderer.php
 *
 * Usage: [[youtube-video:slug]] or [[yt-video:slug]]
 * Attributes: title="override", description="true|false", aspect="16:9|9:16"
 *
 * Loads config from admin/data/youtube_playlists/{slug}.json (entries with type="video")
 * No API key needed — video metadata is captured at save-time via noembed.
 */
declare(strict_types=1);

if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../../../..') ?: dirname(__DIR__, 5));
}

function render_youtube_video(array $attrs = []): string {
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($attrs['slug'] ?? $attrs['name'] ?? '')));
    if (!$slug) return '<!-- youtube-video: missing slug -->';

    $configFile = SITE_ROOT . "/admin/data/youtube_playlists/$slug.json";
    if (!is_file($configFile)) {
        return "<!-- youtube-video: '$slug' not found -->";
    }

    $config = json_decode(file_get_contents($configFile), true);
    // Playlist records share this store. If a [[youtube-video:slug]] tag points at a
    // playlist-type record, delegate to the playlist renderer (symmetry with the
    // playlist renderer's video delegation) so either tag resolves either record type.
    if (is_array($config) && (($config['type'] ?? '') === 'playlist' || (empty($config['video_id']) && !empty($config['playlist_id'])))) {
        if (!function_exists('render_youtube_playlist')) require_once __DIR__ . '/youtube_playlist.renderer.php';
        return function_exists('render_youtube_playlist')
            ? render_youtube_playlist($attrs)
            : "<!-- youtube-video: '$slug' is a playlist but the playlist renderer is unavailable -->";
    }
    if (!is_array($config) || empty($config['video_id'])) {
        return "<!-- youtube-video: '$slug' missing video_id -->";
    }

    $videoId = preg_replace('/[^A-Za-z0-9_-]/', '', $config['video_id']);
    if ($videoId === '') return "<!-- youtube-video: '$slug' invalid video_id -->";

    $title       = (string)($attrs['title'] ?? $config['video_title'] ?? $config['title'] ?? '');
    $description = (string)($config['video_description'] ?? '');
    $showDesc    = $attrs['description'] ?? ($config['show_description'] ?? false);
    if (is_string($showDesc)) $showDesc = !in_array(strtolower($showDesc), ['false', '0', 'no'], true);

    $aspect = str_replace(':', '/', (string)($attrs['aspect'] ?? $config['aspect_ratio'] ?? '16/9'));
    $aspect = in_array($aspect, ['9/16', '1/1'], true) ? $aspect : '16/9';

    static $instance = 0;
    $instance++;
    $uid = "ytv-{$slug}-{$instance}";
    $eUid = htmlspecialchars($uid, ENT_QUOTES, 'UTF-8');
    $eVid = htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8');
    $eTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    static $cssInjected = false;
    $css = '';
    if (!$cssInjected) {
        $cssInjected = true;
        $css = <<<'YTVCSS'
<style>
.ytv-container{ margin:0 0 12px; }
.ytv-container .ytv-player iframe{ width:100%; aspect-ratio:16/9; display:block; border:0; border-radius:8px; }
.ytv-container.ytv-portrait .ytv-player{ max-width:420px; margin:0 auto; }
.ytv-container.ytv-portrait .ytv-player iframe{ aspect-ratio:9/16; }
.ytv-container.ytv-square .ytv-player{ max-width:480px; margin:0 auto; }
.ytv-container.ytv-square .ytv-player iframe{ aspect-ratio:1/1; }
.ytv-meta{ background:rgba(0,0,0,.35); border:1px solid rgba(255,255,255,.12);
  border-radius:12px; padding:10px 12px; margin-top:8px; }
.ytv-meta h2{ font-size:1rem; margin:0 0 4px; color:#fff; }
.ytv-meta p{ font-size:0.88rem; color:#ccc; line-height:1.5; margin:6px 0 0; white-space:pre-line; }
@media(max-width:640px){
  .ytv-container .ytv-player iframe{ border-radius:4px; }
  .ytv-meta{ border-radius:8px; padding:8px 10px; }
}
</style>
YTVCSS;
    }

    $ratioClass = $aspect === '9/16' ? ' ytv-portrait' : ($aspect === '1/1' ? ' ytv-square' : '');
    $html  = $css;
    $html .= '<div class="ytv-container' . $ratioClass . '" id="' . $eUid . '">' . "\n";
    $html .= '  <div class="ytv-player">' . "\n";
    $html .= '    <iframe src="https://www.youtube.com/embed/' . $eVid . '?rel=0" allow="autoplay; fullscreen; encrypted-media" allowfullscreen loading="lazy"></iframe>' . "\n";
    $html .= '  </div>' . "\n";

    if ($title !== '' || ($showDesc && $description !== '')) {
        $html .= '  <div class="ytv-meta">' . "\n";
        if ($title !== '') {
            $html .= '    <h2>' . $eTitle . '</h2>' . "\n";
        }
        if ($showDesc && $description !== '') {
            $html .= '    <p>' . nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')) . '</p>' . "\n";
        }
        $html .= '  </div>' . "\n";
    }
    $html .= '</div>' . "\n";

    return $html;
}
