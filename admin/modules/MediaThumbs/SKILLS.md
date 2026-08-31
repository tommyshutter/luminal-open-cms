# MediaThumbs — Skills Reference

## Overview
Media thumbnail management module with bulk generation, diagnostics, and health checks. Generates and manages thumbnail cache for images, videos, and PDFs across the site media directory.

## Capabilities
- Bulk thumbnail generation for media folders
- Thumbnail cache diagnostics
- Health checks for missing thumbnails
- Folder-based thumbnail management
- Cache size reporting
- Exclusion list for folders to skip

## API Endpoints
- Internal PHP processing (no REST API)
- `api/thumb-trace.php` — Trace thumbnail resolution for a file
- `api/media-cache.php` — Ensure thumbnail exists for a file

## Data Storage
- `.cache/` directories within media folders

## Dependencies
- None

## Common Tasks
1. **Generate thumbnails**: Select folders, run bulk generation
2. **Run diagnostics**: Use diagnostics view to find missing thumbnails
3. **Clear cache**: Delete .cache folders to regenerate
