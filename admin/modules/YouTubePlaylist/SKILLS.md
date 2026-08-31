# YouTubePlaylist — Skills Reference

## Overview
Multi-playlist YouTube manager with per-playlist API keys and shortcodes. Create multiple YouTube playlists, each with its own YouTube Data API key, and embed them on site pages via shortcodes.

## Capabilities
- Create and manage multiple YouTube playlists
- Per-playlist YouTube Data API key configuration
- Playlist preview with video thumbnails
- Shortcode generation for page embedding
- Cache management
- Playlist deletion

## API Endpoints
- `action=list_playlists` — List all playlists
- `action=get_playlist` — Get playlist details
- `action=preview_playlist` — Preview playlist videos
- `action=save_playlist` — Create or update playlist
- `action=delete_playlist` — Delete a playlist
- `action=clear_cache` — Clear playlist cache

## Data Storage
- Playlist configurations in module data directory

## Dependencies
- None (requires YouTube Data API key)

## Common Tasks
1. **Add a playlist**: Enter playlist ID, API key, save
2. **Preview**: Click preview to see playlist videos
3. **Embed on page**: Copy shortcode and paste into page content
4. **Clear cache**: Force refresh of cached playlist data
