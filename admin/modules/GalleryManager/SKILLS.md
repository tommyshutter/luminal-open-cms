# GalleryManager — Skills Reference

## Overview
Unified gallery management for images, videos, PDFs, and combined galleries. Features live iframe preview, drag-and-drop media selection, layout controls, and shortcode generation for embedding galleries in pages.

## Capabilities
- Create and manage galleries (images, videos, PDFs, combined)
- Tab-based interface for gallery types
- Pill-based gallery browser
- Live iframe preview
- Drag-and-drop media selection
- Layout controls (grid, masonry, rows)
- Column configuration
- Item reordering via drag-and-drop
- Shortcode generation for page embedding

## API Endpoints
- `action=list_galleries` — List all galleries
- `action=get_gallery` — Get gallery details
- `action=save_gallery` — Create or update gallery
- `action=delete_gallery` — Delete a gallery

## Data Storage
- `admin/data/galleries/images/` — Image gallery configs
- `admin/data/galleries/videos/` — Video gallery configs
- `admin/data/galleries/pdfs/` — PDF gallery configs
- `admin/data/galleries/combined/` — Combined gallery configs

## Dependencies
- MediaThumbs (for thumbnail generation)

## Common Tasks
1. **Create an image gallery**: Click New Gallery, select images from media browser, set layout, save
2. **Embed gallery on page**: Copy generated shortcode, paste into page content
3. **Reorder gallery items**: Drag items to desired order in the gallery editor
4. **Set layout**: Choose grid, masonry, or rows layout with column count
