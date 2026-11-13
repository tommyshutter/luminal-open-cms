# FileManager — Skills Reference

## Overview
Dual-pane file browser with upload, edit, zip/unzip, backup, and ownership management. Provides a web-based file management interface for the CMS site directory.

## Capabilities
- Dual-pane file browsing
- File upload with drag-and-drop
- In-browser file editing
- File/folder creation, rename, delete
- Copy and move operations
- Zip and unzip archives
- File backup creation
- Video thumbnail generation
- Disk usage reporting
- Ownership fix (chown)
- File stat information

## API Endpoints
- `action=list` — List directory contents
- `action=dirs` — List directories only
- `action=mkdir` — Create directory
- `action=rename` — Rename file/folder
- `action=delete` — Delete file/folder
- `action=upload` — Upload file
- `action=download` — Download file
- `action=preview` — Preview file
- `action=copy` / `action=move` — Copy or move files
- `action=zip` — Create zip archive
- `action=unzip` — Extract zip archive
- `action=stat` — Get file statistics
- `action=read` — Read file contents
- `action=write` — Write file contents
- `action=thumb_video` — Generate video thumbnail
- `action=backup` — Create file backup
- `action=chown_fix` — Fix file ownership
- `action=list_backups` — List available backups
- `action=disk_usage` — Report disk usage

## Data Storage
- `admin/data/data/` — File manager cache
- Operates on site filesystem directly

## Dependencies
- None

## Common Tasks
1. **Upload files**: Drag files into upload zone or click upload button
2. **Edit a file**: Click file to open in editor, modify, save
3. **Create backup**: Select files, click backup to create timestamped copy
4. **Fix permissions**: Use chown_fix to reset ownership to www-data
