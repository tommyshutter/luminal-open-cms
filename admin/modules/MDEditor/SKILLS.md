# MDEditor — Skills Reference

## Overview
Standalone Markdown editor with live preview, document save/load, and export capabilities. Provides a full-page editing experience with keyboard shortcuts and dirty state tracking.

## Capabilities
- Full-page markdown editor with live preview
- Document save/load (JSON storage)
- Export as .md or .html
- Keyboard shortcuts (Ctrl+S save)
- Dirty state indicator (unsaved changes warning)

## API Endpoints
- `action=list` — List saved documents
- `action=load` — Load a document
- `action=save` — Save a document
- `action=delete` — Delete a document

## Data Storage
- `admin/data/md-documents/` — Saved markdown documents

## Dependencies
- None

## Common Tasks
1. **Create a document**: Type markdown, preview renders live, save
2. **Export as HTML**: Export document for use in pages or external systems
3. **Keyboard save**: Ctrl+S saves current document
