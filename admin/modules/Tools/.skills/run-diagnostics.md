# Skill: Run Server Diagnostics

## Overview
Execute the Tricorder diagnostic tool to check server and CMS health.

## Prerequisites
- Superadmin access

## Procedure
1. Navigate to Admin > Tools (system section)
2. Click "Run Diagnostics" or open debug_diagnostics.php
3. Review diagnostic results:
   - Core pathing (SITE_ROOT)
   - Media directory existence and permissions
   - AJAX handler availability
   - PHP configuration checks
4. Address any reported issues

## Verification
- All checks show green success indicators
- No critical path or permission issues
