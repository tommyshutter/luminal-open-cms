# FontManager — Skills Reference

## Overview
Custom font upload and management for web typography. Allows uploading custom font files and making them available for use across the site's CSS.

## Capabilities
- Upload custom font files (WOFF, WOFF2, TTF, OTF)
- Manage uploaded fonts
- Generate @font-face CSS declarations

## API Endpoints
- `action=upload` — Upload a font file

## Data Storage
- Font files stored in site font directory

## Dependencies
- None

## Common Tasks
1. **Upload a custom font**: Select font file (WOFF2 preferred), upload, font becomes available in CSS
2. **Use uploaded font**: Reference font family name in SiteSettings typography or custom CSS
