# AudienceBuilder — Skills Reference

## Overview
Audience lead collection module with hub/node architecture. Deployed to all base sites to capture leads via CTA forms, then passes leads upstream to the Pinnacle hub's AudienceCollector. Supports multiple form configurations, connector ports, and export capabilities.

## Capabilities
- Capture leads via configurable CTA forms
- Hub/node lead forwarding architecture
- Multiple form builder with field customization
- Lead management (view, delete, export)
- Sent queue tracking with retry capability
- Connector ports for external integrations
- CSV and markdown export
- Lead statistics and analytics
- Print-friendly lead views

## API Endpoints
- `action=get_mode` — Get hub/node mode
- `action=get_config` — Get module configuration
- `action=save_config` — Save configuration
- `action=list_leads` — List captured leads
- `action=get_lead` — Get single lead details
- `action=delete_lead` — Delete a lead
- `action=stats` — Lead statistics
- `action=export_leads` — Export leads (CSV/MD)
- `action=list_sent` — List forwarded leads
- `action=sent_stats` — Sent queue statistics
- `action=retry_to_hub` — Retry failed hub delivery
- `action=flush_sent_queue` — Clear sent queue
- `action=delete_sent` — Delete sent record
- `action=export_sent` — Export sent records
- `action=lead_print_html` — Print-friendly lead view
- `action=list_connectors` — List output connectors
- `action=list_forms` — List CTA forms
- `action=get_form` — Get form configuration
- `action=save_form` — Save form configuration
- `action=delete_form` — Delete a form

## Data Storage
- `admin/data/AudienceBuilder/` — Leads, forms, connector configs

## Dependencies
- None (pairs with AudienceCollector on Pinnacle)

## Common Tasks
1. **Configure a CTA form**: Create/edit form with field definitions, styling, submit action
2. **Export leads**: Use export_leads with format=csv for spreadsheet use
3. **Retry failed deliveries**: Use retry_to_hub to resend failed lead transmissions
4. **View lead stats**: Check stats action for lead counts and trends
