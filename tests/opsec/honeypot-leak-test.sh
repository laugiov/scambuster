#!/usr/bin/env bash
#
# tests/opsec/honeypot-leak-test.sh — exercise scripts/check-honeypot-leak.sh
# against 4 scenarios in a sandbox tmp repo. Exits 0 if all pass, 1 on first
# failure.
#
# Usage:
#   bash tests/opsec/honeypot-leak-test.sh

set -euo pipefail

REPO=$(git rev-parse --show-toplevel)
SCRIPT="${REPO}/scripts/check-honeypot-leak.sh"
PASS=0
FAIL=0

run_case() {
    local name="$1"
    local expected_exit="$2"
    local body="$3"
    local list_content="${4:-}"

    local tmp
    tmp=$(mktemp -d)
    trap 'rm -rf "$tmp"' RETURN

    (
        cd "$tmp"
        git init -q
        git config user.email t@t
        git config user.name t
        mkdir -p local
        if [[ -n "$list_content" ]]; then
            printf '%s\n' "$list_content" > local/honeypot-names.txt
        fi
        printf '%s\n' "$body" > target.txt
        git add target.txt
    )

    set +e
    actual_exit=$(bash "$SCRIPT" --root "$tmp" > /tmp/honeypot-leak-out.$$ 2>&1; echo $?)
    set -e

    if [[ "$actual_exit" == "$expected_exit" ]]; then
        echo "  ✓ $name (exit $actual_exit)"
        PASS=$((PASS + 1))
    else
        echo "  ✗ $name (expected exit $expected_exit, got $actual_exit)"
        echo "    output:"
        sed 's/^/      /' /tmp/honeypot-leak-out.$$
        FAIL=$((FAIL + 1))
    fi

    rm -f /tmp/honeypot-leak-out.$$
    rm -rf "$tmp"
}

echo "── honeypot-leak-test ──"

# Case 1: list file absent → exit 0 (graceful skip).
run_case "list-absent-skips-cleanly" 0 "any content here"

# Case 2: list present, no match → exit 0.
run_case "no-match-passes" 0 "innocent content" "secretname1
secretname2"

# Case 3: list present, match → exit 1.
run_case "match-fails" 1 "this file mentions secretname1 inline" "secretname1"

# Case 4: case-insensitive match → exit 1.
run_case "case-insensitive-match-fails" 1 "SecretName1 here" "secretname1"

# Case 5: comments + blanks in list are ignored.
run_case "comments-ignored" 0 "regular text" "# this is a comment

# another comment
unusedname"

# Case 6: empty list (only comments) → exit 0.
run_case "empty-list-after-comments-skips" 0 "any content" "# only comments"

echo ""
if [[ "$FAIL" -gt 0 ]]; then
    echo "honeypot-leak-test: ${FAIL} failure(s), ${PASS} pass"
    exit 1
fi

echo "honeypot-leak-test: all ${PASS} cases passed"
exit 0
