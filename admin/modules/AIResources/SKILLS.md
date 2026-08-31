# AIResources — Skills Reference

## Overview
AI provider and MCP server management module. Provides visual resource cards for configuring API keys, selecting models, managing tool servers, and routing AI providers to specific pipelines across the CMS.

## Capabilities
- Manage AI provider credentials (OpenAI, Anthropic, Google, etc.)
- Set active/default providers per pipeline
- Configure MCP (Model Context Protocol) tool servers
- Manage image generation providers (DALL-E, Stability, etc.)
- NotebookLM integration for AI-generated podcasts
- Prompt Commons — shared prompt library
- Test provider connectivity
- Generate text fragments and images via AI

## API Endpoints
- `action=overview` — Dashboard summary of all providers
- `action=get_state` — Full configuration state
- `action=providers` — List all configured providers
- `action=provider_types` — Available provider types
- `action=add_provider` — Add a new AI provider
- `action=update_provider` — Update provider settings
- `action=remove_provider` — Remove a provider
- `action=set_active` — Set the active provider
- `action=get_pipeline_defaults` — Get per-pipeline provider routing
- `action=save_pipeline_defaults` — Save pipeline routing
- `action=test_provider` — Test provider connectivity
- `action=save_config` — Save general configuration
- `action=mcp_servers` — List MCP servers
- `action=mcp_add` — Add MCP server
- `action=mcp_update` — Update MCP server
- `action=mcp_remove` — Remove MCP server
- `action=mcp_test` — Test MCP server
- `action=image_providers` — List image providers
- `action=save_image_provider` — Save image provider config
- `action=generate_fragment` — Generate text via AI
- `action=generate_single_image` — Generate an image via AI
- `action=nlm_save_config` — Save NotebookLM config
- `action=nlm_test_connection` — Test NotebookLM connection
- `action=nlm_generate_podcast` — Generate podcast via NotebookLM
- `action=nlm_check_status` — Check NotebookLM generation status
- `action=nlm_save_audio` — Save generated audio
- `action=nlm_list_podcasts` — List NLM podcasts
- `action=nlm_delete_podcast` — Delete NLM podcast
- `action=nlm_add_to_podcast_manager` — Import NLM podcast to PodcastManager
- `action=prompt_commons_list` — List shared prompts

## Data Storage
- `admin/data/AIResources/` — Provider configs, API keys, MCP server definitions

## Dependencies
- None (foundational module)

## Common Tasks
1. **Add an AI provider**: Navigate to AIResources, click Add Provider, select type, enter API key, save
2. **Route a provider to a pipeline**: Use Pipeline Defaults tab, select provider per pipeline
3. **Test connectivity**: Click Test on any provider card to verify API key works
4. **Add an MCP server**: Go to MCP Servers tab, add URL/transport config, test connection
