# GoogleAuth — Skills Reference

## Overview
Google OAuth 2.0 sign-in module providing passwordless authentication via Google accounts. Handles the full OAuth flow (init, Google consent, callback, JWT decode, session creation) and integrates with the CMS login page.

## Capabilities
- Google OAuth 2.0 authentication flow
- Passwordless sign-in via Google accounts
- JWT token decoding and session management
- Settings panel with setup guide
- Login page integration ("Sign in with Google" button)

## API Endpoints
- `action=get_config` — Get OAuth configuration
- `action=save_config` — Save OAuth credentials
- `action=test_config` — Test OAuth configuration

## Data Storage
- `admin/data/GoogleAuth/` — OAuth credentials and configuration

## Dependencies
- None

## Common Tasks
1. **Configure Google OAuth**: Enter Client ID, Client Secret, and Redirect URI from Google Cloud Console
2. **Test configuration**: Use test_config to verify OAuth settings
3. **Enable on login page**: Configuration automatically adds Google button to login
