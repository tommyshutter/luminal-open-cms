# Skill: Configure MCP Tool Server

## Overview
Register and configure Model Context Protocol (MCP) servers that expose tools for AI agents to call.

## Prerequisites
- Admin access
- Running MCP server endpoint URL

## Procedure
1. Navigate to Admin > AI Resources > MCP Servers tab
2. Click "Add MCP Server"
3. Enter server name, URL, and transport type (stdio/sse/http)
4. Save configuration
5. Click Test to verify the server responds
6. Tools from this server will now be available to AI agents

## Verification
- MCP server card shows connected status
- `api.php?action=mcp_test&id=SERVER_ID` returns tool list
