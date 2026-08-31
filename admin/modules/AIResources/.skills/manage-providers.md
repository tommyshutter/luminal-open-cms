# Skill: Manage AI Providers

## Overview
Add, configure, test, and route AI providers (OpenAI, Anthropic, Google, etc.) for use across CMS pipelines.

## Prerequisites
- Admin or superadmin access
- Valid API key for the provider

## Procedure
1. Navigate to Admin > AI Resources
2. Click "Add Provider" button
3. Select provider type (openai, anthropic, google, etc.)
4. Enter API key and optional model override
5. Click Save
6. Click Test to verify connectivity
7. Optionally set as default or assign to specific pipelines via Pipeline Defaults

## Verification
- Provider card shows "Connected" status after test
- `api.php?action=test_provider&id=PROVIDER_ID` returns success
