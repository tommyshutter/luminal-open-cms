# EventsManagerPro — Skills Reference

## Overview
Full-featured event management with calendar views, social posting integration, email sharing, venue management, and image optimization. Handles the complete event lifecycle from creation to social media promotion.

## Capabilities
- Create, edit, duplicate, and delete events
- Calendar and list view display
- Venue management
- Event status management (draft, published, cancelled)
- Social media posting (Facebook, etc.)
- YouTube import for event videos
- Image optimization and thumbnail management
- File uploads for event media
- Event settings and credential management

## API Endpoints
- `action=fetch_data` — Fetch events data
- `action=save_event` — Create or update an event
- `action=delete_event` — Delete an event
- `action=duplicate_event` — Clone an event
- `action=set_status` — Set event status
- `action=upload_file` — Upload event media
- `action=import_yt` — Import from YouTube
- `action=get_settings` — Get module settings
- `action=save_settings` — Save settings
- `action=get_venues` — List venues
- `action=save_venue` — Create/update venue
- `action=delete_venue` — Delete venue
- `action=get_creds` — Get social media credentials
- `action=save_creds` — Save social credentials
- `action=push_social` — Post event to social media
- `action=optimize_image` — Optimize single event image
- `action=bulk_optimize` — Bulk optimize images
- `action=refresh_image_cache` — Refresh image cache
- `action=flush_events_thumbs` — Clear thumbnail cache
- `action=rebuild_events_thumbs` — Rebuild thumbnails

## Data Storage
- `admin/data/events/` — Event data files

## Dependencies
- None

## Common Tasks
1. **Create an event**: Fill in title, date, time, venue, description, save
2. **Post to social media**: Select event, click Push Social, choose platform
3. **Manage venues**: Add/edit venue with name, address, capacity
4. **Import YouTube video**: Use import_yt with video URL to attach to event
