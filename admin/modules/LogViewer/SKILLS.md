# LogViewer — Skills Reference

## Overview
Site log viewer with threat detection dashboard. Provides three views: Dashboard (threat cards, status codes, top pages, top offenders), Access Log (paginated viewer with IP resolution), and Error Log (level filtering). Single-site focused analytics.

## Capabilities
- Threat detection dashboard with security cards
- Access log viewer with pagination and tail mode
- Error log viewer with level filtering
- IP address resolution
- Status code analysis
- Top pages and top offenders reports
- Time range filtering (today, 24h, 7d, 30d)
- Settings configuration

## API Endpoints
- Uses internal LogViewerFunctions.php for data processing
- AJAX-based dashboard, access log, and error log loading

## Data Storage
- `admin/data/LogViewer/` — Configuration and cache files
- Reads from Apache access and error logs

## Dependencies
- None

## Common Tasks
1. **View threat dashboard**: Open LogViewer, Dashboard tab shows threat summary
2. **Browse access logs**: Switch to Access Log tab, filter by IP or status code
3. **Check errors**: Error Log tab with level filtering (warning, error, critical)
4. **Change time range**: Use range buttons (Today, 24h, 7 Days, 30 Days)
