# SiteSettings — Skills Reference

## Overview
Site identity and configuration module covering typography, logo management, landing page settings, footer configuration, SEO tools, and maintenance utilities. The central settings panel for site-wide appearance and identity.

## Capabilities
- Site identity (name, tagline, description)
- Typography settings (font family, sizes)
- Logo upload and management
- Landing page configuration
- Footer content management
- Open Graph image generation
- SEO scanning and optimization
- Permission fixing utility
- Path recreation utility
- Page listing

## API Endpoints
- `action=load` — Load all site settings
- `action=get_pages` — List site pages
- `action=save` — Save site settings
- `action=upload_logo` — Upload site logo
- `action=generate_og` — Generate Open Graph image
- `action=fix_permissions` — Fix file permissions
- `action=recreate_paths` — Recreate data directory paths
- `action=seo_scan` — Run SEO scan
- `action=seo_generate` — Generate SEO content for a page
- `action=seo_apply` — Apply SEO content to a page
- `action=seo_apply_all` — Apply SEO to all pages

## Data Storage
- `admin/data/data/` — Site settings JSON files
- `site-settings.json` — Primary settings file

## Dependencies
- None

## Common Tasks
1. **Update site name**: Load settings, change site name, save
2. **Upload logo**: Use upload_logo to set site logo image
3. **Run SEO scan**: Scan all pages for SEO issues, generate and apply fixes
4. **Configure footer**: Edit footer content (static HTML or dynamic from settings)
5. **Fix permissions**: Run fix_permissions to reset www-data ownership
