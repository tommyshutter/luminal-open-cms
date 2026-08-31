# Tools — Skills Reference

## Overview
Admin utilities collection including server diagnostics ("Tricorder"), OG image generation, screenshot upload, gallery self-test, and media item deletion. Superadmin-only diagnostic and maintenance tools.

## Capabilities
- Server-side diagnostics (Tricorder)
- Core path validation
- Media directory health checks
- OG (Open Graph) image generation
- Screenshot upload utility
- Gallery self-test
- Home column media item deletion

## API Endpoints
- `debug_diagnostics.php` — Run Tricorder diagnostics
- `delete_home_items.php` — Delete home column media items

## Data Storage
- No dedicated data storage

## Dependencies
- None

## Common Tasks
1. **Run diagnostics**: Open Tricorder for server health check
2. **Check paths**: Verify SITE_ROOT and media directories
3. **Delete media items**: Remove items from Home Left/Right columns
