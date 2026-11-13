# SiteTextReceiver — Skills Reference

## Overview
Key-value content slots that AI agents push to and shortcodes render. Place `[[site-text:key]]` in any page to display dynamic content managed by agents or manually.

## Capabilities
- Key-value content slot management
- Shortcode rendering: `[[site-text:key]]`
- Content push from AI agents
- Content history with revert capability
- Manual content editing
- Content deletion

## API Endpoints
- `action=list` — List all content slots
- `action=get` — Get content for a key
- `action=push` — Push new content to a key
- `action=history` — View content history for a key
- `action=revert` — Revert to previous version
- `action=delete` — Delete a content slot

## Data Storage
- `admin/data/site-text/` — Content slot JSON files

## Dependencies
- None

## Common Tasks
1. **Create a content slot**: Push content to a new key
2. **Embed in page**: Add `[[site-text:key]]` shortcode to page content
3. **Update via agent**: AI agents push new content via the push API
4. **Revert content**: Use history to restore previous version
