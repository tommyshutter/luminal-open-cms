#!/bin/bash
#
# fix-perms.sh — Fix ownership & permissions for a Luminal CMS site
#
# Usage:
#   fix-perms.sh [/path/to/site-root]
#
# If no path given, auto-detects from this script's location.
#
# When run as root:  fixes chown (www-data:www-data) + chmod
# When run as other: skips chown, still fixes chmod
#
# Targets: dirs 0775, files 0664, scripts in admin/scripts/ re-marked +x
#

set -euo pipefail

OWNER="www-data"
GROUP="www-data"
DIR_PERM="0775"
FILE_PERM="0664"

# ── Resolve site root ─────────────────────────────────────────────────

if [ -n "${1:-}" ]; then
    SITE_ROOT="$1"
else
    # Auto-detect: this script lives in {site}/admin/scripts/
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    SITE_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
fi

if [ ! -d "$SITE_ROOT/admin" ]; then
    echo "ERROR: Not a valid CMS site root: $SITE_ROOT"
    exit 1
fi

DOMAIN=$(basename "$SITE_ROOT")
CHANGED=0

# ── Ownership (root only) ────────────────────────────────────────────

if [ "$(id -u)" -eq 0 ]; then
    BAD_OWNER=$(find "$SITE_ROOT" -not -user "$OWNER" -o -not -group "$GROUP" 2>/dev/null | head -1 || true)
    if [ -n "$BAD_OWNER" ]; then
        chown -R "${OWNER}:${GROUP}" "$SITE_ROOT"
        CHANGED=$((CHANGED + 1))
        echo "[$DOMAIN] chown: fixed ownership to ${OWNER}:${GROUP}"
    else
        echo "[$DOMAIN] chown: ok"
    fi
else
    echo "[$DOMAIN] chown: skipped (not root)"
fi

# ── Directory permissions ────────────────────────────────────────────

BAD_DIRS=$(find "$SITE_ROOT" -type d -not -perm "$DIR_PERM" 2>/dev/null | wc -l)
if [ "$BAD_DIRS" -gt 0 ]; then
    find "$SITE_ROOT" -type d -not -perm "$DIR_PERM" -exec chmod "$DIR_PERM" {} + 2>/dev/null
    CHANGED=$((CHANGED + BAD_DIRS))
    echo "[$DOMAIN] dirs: fixed $BAD_DIRS directories to $DIR_PERM"
else
    echo "[$DOMAIN] dirs: ok"
fi

# ── File permissions ─────────────────────────────────────────────────

BAD_FILES=$(find "$SITE_ROOT" -type f -not -perm "$FILE_PERM" 2>/dev/null | wc -l)
if [ "$BAD_FILES" -gt 0 ]; then
    find "$SITE_ROOT" -type f -not -perm "$FILE_PERM" -exec chmod "$FILE_PERM" {} + 2>/dev/null
    CHANGED=$((CHANGED + BAD_FILES))
    echo "[$DOMAIN] files: fixed $BAD_FILES files to $FILE_PERM"
else
    echo "[$DOMAIN] files: ok"
fi

# ── Re-mark scripts executable ───────────────────────────────────────
#
# The blanket `chmod 0664` above strips the executable bit from EVERY file in
# the site, so anything meant to be run has to be restored here by name.
#
# This used to cover only admin/scripts/*.sh, which silently broke every other
# executable the CMS ships. ServerSentinel's privilege wrapper
# (admin/modules/ServerSentinel/bin/ss-run.sh) was reset to 0664 on every
# deploy; sudo answers "command not found" for a file it cannot execute, so the
# module computed ~200 firewall bans every 6 hours and applied none of them.
# Correcting the mode in git was not enough — this script overwrote it again on
# the next deploy.
#
# Keep this rule in step with admin/scripts/check-file-modes.sh, which asserts
# the same convention at source: a file is executable if it is a .sh, lives in a
# bin/ directory, or starts with a #! shebang.

restore_exec() {
    local path="$1"
    [ -f "$path" ] || return 0
    chmod 0775 "$path" 2>/dev/null && EXEC_RESTORED=$((EXEC_RESTORED + 1))
}

EXEC_RESTORED=0

# 1. Shell scripts anywhere under admin/ (covers admin/scripts and module bin/)
while IFS= read -r -d '' f; do
    restore_exec "$f"
done < <(find "$SITE_ROOT/admin" -type f -name '*.sh' -print0 2>/dev/null)

# 2. Anything living in a bin/ directory, whatever its extension
while IFS= read -r -d '' f; do
    restore_exec "$f"
done < <(find "$SITE_ROOT/admin" -type d -name bin -exec find {} -type f -print0 \; 2>/dev/null)

# 3. Shebanged files under admin/ — catches CLI entry points such as
#    modules/*/cli/*.php that are invoked directly rather than via `php x.php`.
while IFS= read -r -d '' f; do
    if [ "$(head -c 2 "$f" 2>/dev/null)" = '#!' ]; then
        restore_exec "$f"
    fi
done < <(find "$SITE_ROOT/admin" -type f \( -name '*.php' -o -name '*.py' \) -print0 2>/dev/null)

echo "[$DOMAIN] scripts: re-marked $EXEC_RESTORED file(s) as executable"

# ── Re-tighten credential files ──────────────────────────────────────
#
# The blanket `chmod 0664` above makes EVERY file group- and world-readable,
# including ones holding live API keys. That silently undid credential
# hardening on every single deploy: tts_config.json (ElevenLabs/Google/OpenAI
# keys) and telegram/config.json (bot token) were both put back to 0664 by a
# deploy on 2026-08-12, hours after being set to 0600 by hand.
#
# Content-based rather than a filename list, so a credential file added later
# is covered without anyone remembering to update this script. These files are
# owned by the web user, so 0600 still leaves them readable by the site.

SECRETS_TIGHTENED=0
if [ -d "$SITE_ROOT/admin/data" ]; then
    while IFS= read -r -d '' f; do
        if grep -qE '"(api_key|apikey|bot_token|api_token|access_token|refresh_token|client_secret|secret|password|passwd)"[[:space:]]*:[[:space:]]*"[^"]+"' "$f" 2>/dev/null; then
            chmod 0600 "$f" 2>/dev/null && SECRETS_TIGHTENED=$((SECRETS_TIGHTENED + 1))
        fi
    done < <(find "$SITE_ROOT/admin/data" -type f \( -name '*.json' -o -name '*.conf' \) -size -256k -print0 2>/dev/null)
fi
echo "[$DOMAIN] secrets: tightened $SECRETS_TIGHTENED file(s) to 0600"

# admin/data/secrets/ holds credvault.key — the master key that everything else
# is sealed against. It is NOT json, so the content sweep above cannot see it,
# and the blanket 0664 would hand out the one file that decrypts all the rest.
# The hub's 5-minute perms cron already carves this directory out; match it.
if [ -d "$SITE_ROOT/admin/data/secrets" ]; then
    chmod 0700 "$SITE_ROOT/admin/data/secrets" 2>/dev/null
    find "$SITE_ROOT/admin/data/secrets" -type f -exec chmod 0600 {} + 2>/dev/null
    echo "[$DOMAIN] secrets dir: 0700, keys 0600"
fi

# ── Summary ──────────────────────────────────────────────────────────

if [ "$CHANGED" -eq 0 ]; then
    echo "[$DOMAIN] DONE: no changes needed"
else
    echo "[$DOMAIN] DONE: $CHANGED fixes applied"
fi
