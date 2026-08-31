# TechSupport — Skills Reference

## Overview
Support ticket system with modal widget, multi-select form fields, agent library integration, and AgentScheduler pipeline for automated triage. Provides ticket submission, tracking, dashboard with agent assignment, and full lifecycle management.

## Capabilities
- Ticket submission via modal widget
- Multi-select pulldown fields (affected areas, change types, protocols)
- Agent Library integration (auto-assigns triage + category-specific agents)
- Ticket status tracking and lifecycle management
- Dashboard with agent assignment display
- Ticket search and similar ticket detection
- Note/comment system
- Ticket verification and resolution
- Bulk archive of resolved tickets
- Inter-ticket linking
- Ticket statistics
- Index rebuilding

## API Endpoints
- `action=submit_ticket` — Submit new support ticket
- `action=check_status` — Check ticket status
- `action=list_tickets` — List tickets with filters
- `action=search_tickets` — Search tickets
- `action=similar_tickets` — Find similar tickets
- `action=ticket_stats` — Get ticket statistics
- `action=rebuild_index` — Rebuild ticket index
- `action=get_ticket` — Get ticket details
- `action=update_status` — Update ticket status
- `action=add_note` — Add note to ticket
- `action=resolve_ticket` — Mark ticket resolved
- `action=save_verification` — Save verification results
- `action=delete_ticket` — Soft delete ticket
- `action=permanently_delete_ticket` — Hard delete
- `action=bulk_archive_resolved` — Bulk archive resolved tickets
- `action=inter_ticket` — Link tickets together

## Data Storage
- `admin/data/support_tickets/inbox/` — Active tickets
- `admin/data/support_tickets/archive/` — Archived tickets

## Dependencies
- None (optional AgentScheduler integration for AI triage)

## Common Tasks
1. **Submit a ticket**: Use widget to describe issue, select categories
2. **Triage tickets**: Review dashboard, update status, assign agents
3. **Add notes**: Comment on tickets for tracking
4. **Resolve and archive**: Mark resolved, bulk archive completed tickets
5. **Search tickets**: Find tickets by keyword or similarity
