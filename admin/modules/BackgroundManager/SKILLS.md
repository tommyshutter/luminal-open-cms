# BackgroundManager — Skills Reference

## Overview
Background image and video manager with slideshow support, drag-and-drop upload pad, and live preview. Controls the site's background display including overlay colors, opacity, and sizing options.

## Capabilities
- Set background mode (image, video, slideshow)
- Upload and manage background media
- Drag-and-drop upload pad
- SortableJS playlist ordering for slideshows
- Live preview panel
- Overlay controls (color, opacity)
- Sizing controls (cover, contain, etc.)

## API Endpoints
- `action=save_master_settings` — Save background configuration
- `action=delete_media` — Delete a background media file
- `action=upload_media` — Upload new background media

## Data Storage
- `admin/data/data/` — Background settings and media references

## Dependencies
- None

## Common Tasks
1. **Set a background image**: Upload image, select it, adjust overlay settings, save
2. **Create a slideshow**: Upload multiple images, reorder with drag-and-drop, set transition timing
3. **Set video background**: Upload video file, configure sizing and overlay
