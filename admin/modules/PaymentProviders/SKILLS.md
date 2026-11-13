# PaymentProviders — Skills Reference

## Overview
Payment gateway configuration for PayPal, Stripe, and Square. Manages API credentials, gateway enablement, default gateway selection, and currency settings.

## Capabilities
- Configure PayPal credentials
- Configure Stripe credentials
- Configure Square credentials
- Enable/disable individual gateways
- Set default payment gateway
- Currency selection
- Test gateway connectivity

## API Endpoints
- `action=get_state` — Get all gateway configurations
- `action=update_gateway` — Update gateway credentials
- `action=enable_gateway` — Enable/disable a gateway
- `action=set_default` — Set default gateway
- `action=set_currency` — Set currency
- `action=test_connection` — Test gateway connectivity

## Data Storage
- Payment configuration in site data directory

## Dependencies
- None

## Common Tasks
1. **Configure Stripe**: Enter publishable key and secret key, enable, set as default
2. **Test connection**: Verify API keys work with test_connection
3. **Set currency**: Choose USD, EUR, GBP, etc.
