# MailgunManager — Skills Reference

## Overview
Mailgun email service configuration and testing module. Manages API credentials, domain settings, and provides email testing capabilities including DNS verification.

## Capabilities
- Configure Mailgun API credentials
- Set sending domain
- Test email delivery
- DNS record verification
- Send test emails

## API Endpoints
- `action=load` — Load current configuration
- `action=save` — Save Mailgun configuration
- `action=test` — Test Mailgun connectivity
- `action=send_test` — Send a test email
- `action=check_dns` — Verify DNS records for domain

## Data Storage
- `admin/data/data/` — Mailgun configuration file

## Dependencies
- None

## Common Tasks
1. **Configure Mailgun**: Enter API key, domain, and from address
2. **Test connectivity**: Use test action to verify API key works
3. **Send test email**: Use send_test to verify end-to-end delivery
4. **Check DNS**: Verify SPF/DKIM/MX records are configured correctly
