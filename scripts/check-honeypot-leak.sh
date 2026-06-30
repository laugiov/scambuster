#!/usr/bin/env bash
#
# check-honeypot-leak.sh — guard against accidental honeypot-name leaks
# in committed code.
#
# Reads a private list of honeypot identifiers from local/honeypot-names.txt
# (gitignored — operator maintains it locally) and scans either:
#   - the currently staged files (default mode, used by .git/hooks/pre-commit)
#   - the whole tracked tree (--full mode, used by preflight gate 9)
#
# Exits non-zero when any name from the list is found in a target file,
# printing the offending file:line:matched-name.
#
# Exits zero (with a one-line skip note) when local/honeypot-names.txt is
# absent — OSS contributors who clone the repo and have no honeypots get a
# clean experience.
#
# Usage:
#   bash scripts/check-honeypot-leak.sh                # staged-files mode
#   bash scripts/check-honeypot-leak.sh --full         # full-tree mode
#   bash scripts/check-honeypot-leak.sh --list <path>  # override list path (tests)
#   bash scripts/check-honeypot-leak.sh --root <path>  # override repo root (tests)

set -euo pipefail

MODE="staged"
LIST_PATH=""
ROOT_PATH=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --full)
            MODE="full"
            shift
            ;;
        --list)
            LIST_PATH="$2"
            shift 2
            ;;
        --root)
            ROOT_PATH="$2"
            shift 2
            ;;
        -h|--help)
            sed -n '2,22p' "$0"
            exit 0
            ;;
        *)
            echo "unknown arg: $1" >&2
            exit 2
            ;;
    esac
done

# Resolve repo root: explicit --root for tests, else git toplevel.
if [[ -z "$ROOT_PATH" ]]; then
    ROOT_PATH=$(git rev-parse --show-toplevel 2>/dev/null || pwd)
fi

# Default list path lives under the gitignored local/ directory.
if [[ -z "$LIST_PATH" ]]; then
    LIST_PATH="${ROOT_PATH}/local/honeypot-names.txt"
fi

# Graceful skip when no list configured.
if [[ ! -f "$LIST_PATH" ]]; then
    echo "honeypot-leak: skipped (no list at ${LIST_PATH#$ROOT_PATH/})"
    exit 0
fi

# Read names: one per line, skip blank lines and # comments.
mapfile -t NAMES < <(grep -vE '^\s*(#|$)' "$LIST_PATH" || true)

if [[ ${#NAMES[@]} -eq 0 ]]; then
    echo "honeypot-leak: skipped (empty list)"
    exit 0
fi

# Build the list of files to scan.
FILES=()
if [[ "$MODE" == "staged" ]]; then
    # Only files with a staged change of type Added/Copied/Modified/Renamed.
    while IFS= read -r f; do
        [[ -n "$f" ]] && FILES+=("$f")
    done < <(git -C "$ROOT_PATH" diff --cached --name-only --diff-filter=ACMR 2>/dev/null || true)
else
    # Full tree — scan tracked files only, exclude the gitignored sinks.
    while IFS= read -r f; do
        case "$f" in
            local/*|backend-symfony/var/*|node_modules/*|vendor/*) ;;
            *) FILES+=("$f") ;;
        esac
    done < <(git -C "$ROOT_PATH" ls-files 2>/dev/null || true)
fi

if [[ ${#FILES[@]} -eq 0 ]]; then
    echo "honeypot-leak: 0 files to scan (${MODE} mode), 0 matches"
    exit 0
fi

# Scan. Case-insensitive substring match (-iF for fixed-string).
HITS=()
for name in "${NAMES[@]}"; do
    # Strip leading/trailing whitespace.
    name="${name#"${name%%[![:space:]]*}"}"
    name="${name%"${name##*[![:space:]]}"}"
    [[ -z "$name" ]] && continue

    # Scan against staged content for staged mode, working tree for full mode.
    if [[ "$MODE" == "staged" ]]; then
        for f in "${FILES[@]}"; do
            # Read the staged version (handles `git add -p` correctly).
            staged=$(git -C "$ROOT_PATH" show ":${f}" 2>/dev/null || true)
            if [[ -z "$staged" ]]; then
                continue
            fi
            line=$(printf '%s' "$staged" | grep -inF -- "$name" | head -1 || true)
            if [[ -n "$line" ]]; then
                HITS+=("${f}:${line%%:*}: matched '${name}'")
            fi
        done
    else
        for f in "${FILES[@]}"; do
            [[ ! -r "${ROOT_PATH}/${f}" ]] && continue
            line=$(grep -inF -- "$name" "${ROOT_PATH}/${f}" 2>/dev/null | head -1 || true)
            if [[ -n "$line" ]]; then
                HITS+=("${f}:${line%%:*}: matched '${name}'")
            fi
        done
    fi
done

if [[ ${#HITS[@]} -gt 0 ]]; then
    echo "honeypot-leak: FAIL — ${#HITS[@]} match(es):" >&2
    for h in "${HITS[@]}"; do
        echo "  ${h}" >&2
    done
    echo "" >&2
    echo "Remove the matched name(s) before committing." >&2
    echo "If this is a false positive, edit ${LIST_PATH#$ROOT_PATH/} or use --no-verify (NOT recommended)." >&2
    exit 1
fi

echo "honeypot-leak: PASS (${MODE} mode, ${#FILES[@]} file(s) scanned against ${#NAMES[@]} name(s))"
exit 0
