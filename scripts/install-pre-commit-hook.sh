#!/usr/bin/env bash
#
# install-pre-commit-hook.sh — wire scripts/check-honeypot-leak.sh as the
# repo's pre-commit hook. Idempotent.
#
# Usage:
#   bash scripts/install-pre-commit-hook.sh

set -euo pipefail

ROOT=$(git rev-parse --show-toplevel)
HOOK_DIR="${ROOT}/.git/hooks"
HOOK_FILE="${HOOK_DIR}/pre-commit"
TARGET="${ROOT}/scripts/check-honeypot-leak.sh"

if [[ ! -x "$TARGET" ]]; then
    echo "FAIL: ${TARGET} is not executable. Run: chmod +x ${TARGET}" >&2
    exit 1
fi

mkdir -p "$HOOK_DIR"

if [[ -L "$HOOK_FILE" && "$(readlink "$HOOK_FILE")" == "$TARGET" ]]; then
    echo "pre-commit hook already installed (symlink → ${TARGET#$ROOT/})"
    exit 0
fi

if [[ -e "$HOOK_FILE" || -L "$HOOK_FILE" ]]; then
    backup="${HOOK_FILE}.backup-$(date +%Y%m%d%H%M%S)"
    mv "$HOOK_FILE" "$backup"
    echo "existing pre-commit moved to ${backup#$ROOT/}"
fi

ln -s "$TARGET" "$HOOK_FILE"
echo "pre-commit hook installed → ${TARGET#$ROOT/}"
echo ""
echo "Next step: populate local/honeypot-names.txt with your honeypot identifiers"
echo "  (one per line; # for comments). The file is gitignored."
echo "  Template available at scripts/honeypot-names.txt.example"
