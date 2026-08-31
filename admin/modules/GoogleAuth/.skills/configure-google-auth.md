# Skill: Configure Google OAuth

## Overview
Set up Google OAuth 2.0 for passwordless admin login via Google accounts.

## Prerequisites
- Superadmin access
- Google Cloud Console project with OAuth 2.0 credentials

## Procedure
1. Create OAuth 2.0 credentials in Google Cloud Console
2. Set authorized redirect URI to `https://yourdomain.com/admin/modules/GoogleAuth/callback.php`
3. Navigate to Admin > Google Auth (system admin section)
4. Enter Client ID and Client Secret
5. Save configuration
6. Test with test_config action
7. "Sign in with Google" button appears on login page

## Verification
- Test config returns success
- Login page shows Google sign-in button
- Clicking button redirects to Google consent, then back to admin
