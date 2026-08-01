#!/usr/bin/env bash
#
# GUARD pre-push hook (opt-in) — remind (or, with GUARD_ON_PUSH=1, enforce) the real-LLM
# prompt-regression gate whenever a push changes files that can alter or gate model output.
#
# Install it with:  make guard-hook-install
#
# By default it only PRINTS a reminder and lets the push through — the real-LLM gate takes
# ~35min and ~$0.14, so it must not run unexpectedly on every push. Set GUARD_ON_PUSH=1 to
# actually run `make guard` and BLOCK the push on a regression. Override the mainline used to
# scope a brand-new branch with GUARD_BASE_REF (default: origin/main).
#
# git feeds a pre-push hook one line per ref on stdin:
#   <local ref> <local sha> <remote ref> <remote sha>
# We inspect the commits actually being pushed (not merely local HEAD).
#
set -euo pipefail

BASE="${GUARD_BASE_REF:-origin/main}"

# Paths whose change can alter or gate model output, and therefore warrant the regression
# gate: the generative + safety runtime (reply orchestrator, PolicyGuard, PaymentInstigation
# guard, prompt catalog/provider/builder, the safety oracle and comparator) and the operator
# prompt/settings config. Anchored to src/ and config/ so tests and the frontend do not trip it.
PATTERNS='config/scambuster/|src/Application/LLM/|src/Application/Guard/'
ZERO='0000000000000000000000000000000000000000'

should_gate=0

while read -r _local_ref local_sha _remote_ref remote_sha; do
  [ "$local_sha" = "$ZERO" ] && continue # branch deletion — nothing to push/gate

  if [ "$remote_sha" = "$ZERO" ]; then
    # New branch on the remote: scope against the mainline merge-base (best-effort).
    base="$(git merge-base "$local_sha" "$BASE" 2>/dev/null || true)"
  else
    base="$remote_sha"
  fi
  [ -z "$base" ] && continue # cannot resolve a range — do not get in the way of the push

  changed="$(git diff --name-only "$base" "$local_sha" 2>/dev/null || true)"

  if printf '%s\n' "$changed" | grep -Eq "$PATTERNS"; then
    should_gate=1
  fi
done

[ "$should_gate" -eq 0 ] && exit 0 # nothing model-affecting in this push — nothing to gate

echo "── GUARD ──────────────────────────────────────────────────────────────"
echo "This push changes files that can alter model output. Run the real-LLM"
echo "regression gate before it reaches ${BASE}:   make guard"
echo "───────────────────────────────────────────────────────────────────────"

if [ "${GUARD_ON_PUSH:-0}" = "1" ]; then
  echo "GUARD_ON_PUSH=1 → running the gate now (real LLM, ~\$0.14, ~35min)…"
  make guard # set -e: a regression makes this fail, which blocks the push
else
  echo "(reminder only — set GUARD_ON_PUSH=1 to enforce the gate on push)"
fi
