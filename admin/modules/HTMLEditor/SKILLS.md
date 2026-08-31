# HTMLEditor — Skills Reference

## Overview
Native WYSIWYG HTML editor with full formatting toolbar, document management, and media integration. Provides a contenteditable-based editing experience with document save/load, export, and find/replace.

## Capabilities
- Native contenteditable WYSIWYG editor
- Full formatting toolbar (bold, italic, headers, lists, etc.)
- Document save/load/export
- Media browser integration
- Font Awesome icon picker
- Find and Replace
- Source code view toggle
- Auto-save and unsaved changes warning

## API Endpoints
- `action=list` — List saved documents
- `action=load` — Load a document
- `action=save` — Save a document
- `action=delete` — Delete a document

## Data Storage
- `admin/data/html-documents/` — Saved HTML documents

## Dependencies
- None

## Common Tasks
1. **Create a document**: Start typing in editor, use toolbar for formatting, save
2. **Load existing document**: Select from document list
3. **Export**: Save as HTML file for external use
4. **Embed media**: Use media browser to insert images/videos
