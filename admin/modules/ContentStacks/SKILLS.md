# ContentStacks — Skills Reference

_Last refresh: 2026-04-23_

## Overview
Reusable content blocks embedded across multiple pages. Each stack is a JSON file that produces HTML when referenced by shortcode. Stacks support multi-column layouts, drag-and-drop media, HTML + markdown blocks, and YouTube/Facebook/X embeds.

## Capabilities
- Card-based stack browser (3-col grid, 2-col ≤1200px, 1-col ≤720px)
- Modal editor with multi-column layout (1–3 columns)
- HTML blocks + **markdown blocks** (Parsedown-rendered; pre-rendered `html` field preferred)
- Drag-and-drop media browser integration
- YouTube, Facebook, X embed detection
- SortableJS ordering
- Slug-based filenames (2026-04-22) — human-readable + stable
- "Back to Content Stacks" button in modal header (2026-04-22)
- Shortcode: `[[stack:slug]]` primary form; `[[panel:content-stack-{N}.php]]` legacy fallback

## API Endpoints
- `action=get_content` — Load stack by id (slug-first, numeric-fallback resolver)
- `action=save_content` — Save stack content
- `action=create_stack` — Create new stack (writes `content-stack-{slug}.json` from the start + panel wrapper)
- `action=rename_stack` — Rename slug (COPIES data file to new slug name + dedupes via `-2` suffix)
- `action=delete_stack` — Remove both slug + numeric files

## Data Storage
- **Primary** (2026-04-22+): `admin/data/content-stack-{slug}.json`
- **Safety alias** (7-day retention during migration): `admin/data/content-stack-{N}.json` — numeric, kept readable by legacy shortcodes
- **Registry**: `admin/data/content-stacks-registry.json` — id → slug → title mapping. Maintained by api.php.
- **Panel wrappers**: `panels/content-stack-{N}.php` — slug-first / numeric-fallback pattern (`is_file($slugFile) ? $slugFile : $numFile`). Kept so old `[[panel:content-stack-N.php]]` shortcodes continue to resolve.

## Shortcode resolution
`includes/shortcodes.php → sc_render_named_stack`:
1. Look up id by slug in registry
2. Try `content-stack-{slug}.json`
3. Fall back to `content-stack-{N}.json`
4. Render via `includes/renderers/content_stack.renderer.php → csr_collect_from_array`

Block types handled: `html`, `markdown`/`md`/`text`, `youtube`, `facebook`, `x`, `image`, `video`.

## Dependencies
- `Parsedown` (vendored in includes/) for markdown block rendering
- `SortableJS` (vendored) for block ordering

## Common Tasks
1. **Create a stack**: Click + New Stack, name it, add blocks, save. New stacks write slug-based filenames from the start.
2. **Embed on a page**: `[[stack:slug]]` in page content. (Legacy `[[panel:content-stack-N.php]]` also works.)
3. **Rename a stack**: The rename operation COPIES the data to the new slug filename. Old file remains as 7-day alias.
4. **Delete a stack**: Removes both slug + numeric files + registry entry.
5. **Migrate legacy numeric → slug filenames**: After user validates, drop any `content-stack-{N}.json` where a `content-stack-{slug}.json` exists AND the registry `slug` resolves back to the same numeric id.
