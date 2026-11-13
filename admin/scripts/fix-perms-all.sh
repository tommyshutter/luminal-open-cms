#!/bin/bash
#
# fix-perms-all.sh — Run fix-perms.sh for every CMS site on this server
#
# Loops /var/www/vhosts/*/admin/scripts/fix-perms.sh and runs each one.
# Used by cron (root) and manual SSH.
#

set -euo pipefail

VHOSTS="/var/www/vhosts"
COUNT=0
ERRORS=0

echo "=== fix-perms-all: scanning $VHOSTS ==="

for SCRIPT in "$VHOSTS"/*/admin/scripts/fix-perms.sh; do
    [ -f "$SCRIPT" ] || continue
    SITE_ROOT="$(dirname "$(dirname "$(dirname "$SCRIPT")")")"
    DOMAIN="$(basename "$SITE_ROOT")"

    if bash "$SCRIPT" "$SITE_ROOT" 2>&1; then
        COUNT=$((COUNT + 1))
    else
        echo "[$DOMAIN] ERROR: fix-perms.sh failed (exit $?)"
        ERRORS=$((ERRORS + 1))
    fi
    echo ""
done

echo "=== fix-perms-all: complete — $COUNT sites processed, $ERRORS errors ==="
