# MembershipManager — Skills Reference

## Overview
Invite-only membership system with referral tracking, leaderboards, and activity logging. Manages member accounts, invitation codes, and gamified engagement for the financial platform.

## Capabilities
- Invite-only membership management
- Invitation code generation and tracking
- Referral chain tracking
- Member leaderboards
- Activity logging
- Membership type management
- Member-facing portal (my membership, my invites)

## API Endpoints
- `action=get_config` — Get membership configuration
- `action=save_config` — Save configuration
- `action=list_members` — List all members
- `action=get_member` — Get member details
- `action=set_membership_type` — Change membership tier
- `action=generate_codes` — Generate invitation codes
- `action=list_codes` — List invitation codes
- `action=revoke_code` — Revoke an invitation code
- `action=get_referrals` — Get referral chain data
- `action=log_activity` — Log member activity
- `action=get_activity` — Get activity history
- `action=get_leaderboard` — Get member leaderboard
- `action=my_membership` — Member self-service portal
- `action=my_invites` — Member's invitation codes

## Data Storage
- `admin/data/MembershipManager/` — Member data, codes, activity logs

## Dependencies
- None

## Common Tasks
1. **Generate invite codes**: Create batch of codes with optional expiration
2. **Manage members**: View, update type, or deactivate members
3. **View leaderboard**: Check member engagement rankings
4. **Track referrals**: See referral chains and attribution
