#!/usr/bin/env bash
#
# check-file-modes.sh — assert executable bits in the GIT INDEX
#
# The mode git records is the mode that gets deployed. A file that is 0755 in
# your working tree but 100644 in the index will land non-executable on every
# site, forever, and no amount of chmod on a server will fix it permanently —
# the next deploy puts 0644 straight back.
#
# That is exactly how ServerSentinel's ss-run.sh broke: tracked as 100644, so
# sudo answered "command not found" and ~200 firewall bans per scan were
# computed and silently discarded for days.
#
# A file is expected to be executable if ANY of:
#   - it ends in .sh
#   - it lives in a bin/ directory
#   - its first line is a #! shebang
#
# Usage:
#   check-file-modes.sh          # report violations, exit 1 if any
#   check-file-modes.sh --fix    # stage the corrected modes, then report
#
# Exit codes: 0 clean · 1 violations found · 2 not a git repo
set -uo pipefail

FIX=0
[[ "${1:-}" == "--fix" ]] && FIX=1

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Not a git repository." >&2
    exit 2
fi

cd "$(git rev-parse --show-toplevel)" || exit 2

violations=()
checked=0

# NUL-delimited so paths with spaces survive.
while IFS= read -r -d '' path; do
    # Skip anything git no longer has on disk (deleted but staged).
    [[ -f "$path" ]] || continue

    expect_exec=0
    case "$path" in
        *.sh)    expect_exec=1 ;;
        */bin/*) expect_exec=1 ;;
    esac

    if [[ $expect_exec -eq 0 ]]; then
        # Cheap shebang sniff — read only the first two bytes.
        if [[ "$(head -c 2 "$path" 2>/dev/null)" == "#!" ]]; then
            expect_exec=1
        fi
    fi

    [[ $expect_exec -eq 1 ]] || continue
    checked=$((checked + 1))

    mode=$(git ls-files -s -- "$path" | awk '{print $1}')
    if [[ "$mode" != "100755" ]]; then
        violations+=("$path (index mode $mode)")
        if [[ $FIX -eq 1 ]]; then
            git update-index --chmod=+x -- "$path" && echo "  fixed: $path"
        fi
    fi
done < <(git ls-files -z)

echo
echo "File-mode check: ${checked} executable-by-convention files inspected."

if [[ ${#violations[@]} -eq 0 ]]; then
    echo "All correct (100755 in the index)."
    exit 0
fi

if [[ $FIX -eq 1 ]]; then
    echo "Fixed ${#violations[@]} file(s). Review with 'git diff --cached --summary', then commit."
    exit 0
fi

echo
echo "NOT EXECUTABLE IN THE INDEX (${#violations[@]}):"
for v in "${violations[@]}"; do
    echo "  - $v"
done
cat <<'EOF'

These will deploy as non-executable to every site.
Fix with:

    admin/scripts/check-file-modes.sh --fix

or individually:

    git update-index --chmod=+x <file>
EOF
exit 1
