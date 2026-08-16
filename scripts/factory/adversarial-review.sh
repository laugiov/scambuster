#!/usr/bin/env bash
#
# adversarial-review.sh — run an adversarial review of the current stage's
# artifact and emit objections in the factory's standard format.
#
# ---------------------------------------------------------------------------
# TWO MODES
# ---------------------------------------------------------------------------
#
# 1. LOCAL (default). Runs the `adversarial-critic` subagent defined in
#    .claude/agents/adversarial-critic.md. Nothing leaves this machine beyond
#    whatever your Claude Code session already sends.
#
# 2. EXTERNAL. Sends the artifact to a different model's API and maps its reply
#    into the standard objection format. A second model disagrees in different
#    places than the first, which is the entire point of the mode.
#
#    ⚠  In external mode the artifact — your spec, your plan, or your diff —
#    IS SENT TO A THIRD-PARTY API. Never point this at an artifact describing an
#    unfixed vulnerability without deciding, deliberately, that the provider may
#    hold that text. The security pipeline's default answer is no.
#
# The script prints which provider is in use before doing anything, so you always
# know whether content is leaving the machine.
#
# ---------------------------------------------------------------------------
# CONFIGURATION — environment only. No key is ever hardcoded or written to disk.
# ---------------------------------------------------------------------------
#
#   FACTORY_REVIEW_PROVIDER   local (default) | openai | anthropic | custom
#   FACTORY_REVIEW_MODEL      model id for the external provider
#   FACTORY_REVIEW_API_KEY    API key for the external provider. Unset => local.
#   FACTORY_REVIEW_ENDPOINT   required when provider=custom
#
# Usage:
#   scripts/factory/adversarial-review.sh <artifact-path> [stage-label]
#
#   scripts/factory/adversarial-review.sh specs/042-persona-mirror/spec.md spec
#   git diff main...HEAD > /tmp/d.patch && scripts/factory/adversarial-review.sh /tmp/d.patch diff
#
# Output: objection lines on stdout, diagnostics on stderr. Paste the objections
# into the gate report. Exit codes: 0 review ran (with or without objections),
# 1 usage or configuration error, 2 provider call failed.
#
# The executor is deliberately swappable: the factory's gates do not depend on
# which model runs this. Adding a provider means adding one branch below and
# nothing else.

set -euo pipefail

ARTIFACT="${1:-}"
STAGE="${2:-unspecified}"

if [[ -z "$ARTIFACT" ]]; then
    echo "usage: $0 <artifact-path> [stage-label]" >&2
    exit 1
fi

if [[ ! -f "$ARTIFACT" ]]; then
    echo "error: artifact not found: $ARTIFACT" >&2
    exit 1
fi

PROVIDER="${FACTORY_REVIEW_PROVIDER:-local}"

# An API key alone does not switch modes. Sending an artifact off the machine is
# an explicit choice, so it takes an explicit FACTORY_REVIEW_PROVIDER.
if [[ "$PROVIDER" != "local" && -z "${FACTORY_REVIEW_API_KEY:-}" ]]; then
    echo "error: FACTORY_REVIEW_PROVIDER=$PROVIDER but FACTORY_REVIEW_API_KEY is unset." >&2
    echo "       Set the key, or unset the provider to review locally." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Announce the provider before anything else happens.
# ---------------------------------------------------------------------------
{
    echo "───────────────────────────────────────────────────────────────"
    if [[ "$PROVIDER" == "local" ]]; then
        echo " provider : local (adversarial-critic subagent)"
        echo " content  : stays on this machine"
    else
        echo " provider : $PROVIDER  [EXTERNAL]"
        echo " model    : ${FACTORY_REVIEW_MODEL:-<unset>}"
        echo " content  : ⚠  '$ARTIFACT' WILL BE SENT TO A THIRD PARTY"
    fi
    echo " artifact : $ARTIFACT"
    echo " stage    : $STAGE"
    echo "───────────────────────────────────────────────────────────────"
} >&2

read -r -d '' PROMPT_HEADER <<'EOF' || true
You are an adversarial critic reviewing one artifact of a software factory stage.
Do not improve it. Try to break it: find what would have to be true for it to be
wrong, and check whether it is.

Reply with objections ONLY, one per line, in exactly this format:

BLOCKING|ADVISORY ; requirement ID or failing-test path ; short description

Rules:
- BLOCKING only if the objection cites a requirement ID that exists in the
  artifact (FR-### or SC-###), or comes with a failing executable test.
  Everything else is ADVISORY, however certain you are.
- Do not invent requirement IDs. An ID absent from the artifact makes the
  objection wrong, not strong.
- Field 3 must not contain a semicolon.
- Be concrete: name the input, the line, or the requirement. If you cannot,
  do not write the objection.
- Finding nothing real is a valid answer. Do not pad.
Output nothing except objection lines.
EOF

case "$PROVIDER" in

    local)
        # The subagent carries its own instructions; the script only needs to
        # say which artifact and which stage.
        cat <<EOF
Run the adversarial-critic subagent against this artifact.

  artifact: $ARTIFACT
  stage:    $STAGE

Its profile is .claude/agents/adversarial-critic.md. It attacks this stage's
artifact only, emits objections in the standard format, and stops after at most
2 iterations.
EOF
        exit 0
        ;;

    openai|anthropic|custom)
        for tool in curl jq; do
            command -v "$tool" >/dev/null 2>&1 || { echo "error: $tool is required for external mode" >&2; exit 1; }
        done

        MODEL="${FACTORY_REVIEW_MODEL:-}"
        [[ -n "$MODEL" ]] || { echo "error: FACTORY_REVIEW_MODEL must be set for provider=$PROVIDER" >&2; exit 1; }

        PAYLOAD_TEXT="$(printf '%s\n\n--- ARTIFACT (%s) ---\n%s\n' "$PROMPT_HEADER" "$STAGE" "$(cat "$ARTIFACT")")"

        case "$PROVIDER" in
            openai)
                ENDPOINT="${FACTORY_REVIEW_ENDPOINT:-https://api.openai.com/v1/chat/completions}"
                BODY="$(jq -n --arg m "$MODEL" --arg c "$PAYLOAD_TEXT" \
                    '{model:$m, messages:[{role:"user", content:$c}]}')"
                RESPONSE="$(curl -sS -X POST "$ENDPOINT" \
                    -H "Authorization: Bearer ${FACTORY_REVIEW_API_KEY}" \
                    -H "Content-Type: application/json" \
                    -d "$BODY")" || { echo "error: provider call failed" >&2; exit 2; }
                RAW="$(printf '%s' "$RESPONSE" | jq -r '.choices[0].message.content // empty')"
                ;;
            anthropic)
                ENDPOINT="${FACTORY_REVIEW_ENDPOINT:-https://api.anthropic.com/v1/messages}"
                BODY="$(jq -n --arg m "$MODEL" --arg c "$PAYLOAD_TEXT" \
                    '{model:$m, max_tokens:4096, messages:[{role:"user", content:$c}]}')"
                RESPONSE="$(curl -sS -X POST "$ENDPOINT" \
                    -H "x-api-key: ${FACTORY_REVIEW_API_KEY}" \
                    -H "anthropic-version: 2023-06-01" \
                    -H "Content-Type: application/json" \
                    -d "$BODY")" || { echo "error: provider call failed" >&2; exit 2; }
                RAW="$(printf '%s' "$RESPONSE" | jq -r '.content[0].text // empty')"
                ;;
            custom)
                ENDPOINT="${FACTORY_REVIEW_ENDPOINT:-}"
                [[ -n "$ENDPOINT" ]] || { echo "error: FACTORY_REVIEW_ENDPOINT must be set for provider=custom" >&2; exit 1; }
                BODY="$(jq -n --arg m "$MODEL" --arg c "$PAYLOAD_TEXT" \
                    '{model:$m, messages:[{role:"user", content:$c}]}')"
                RESPONSE="$(curl -sS -X POST "$ENDPOINT" \
                    -H "Authorization: Bearer ${FACTORY_REVIEW_API_KEY}" \
                    -H "Content-Type: application/json" \
                    -d "$BODY")" || { echo "error: provider call failed" >&2; exit 2; }
                # Try the two common shapes before giving up.
                RAW="$(printf '%s' "$RESPONSE" | jq -r '.choices[0].message.content // .content[0].text // empty')"
                ;;
        esac

        if [[ -z "$RAW" ]]; then
            echo "error: provider returned no usable content. Raw response follows on stderr." >&2
            printf '%s\n' "$RESPONSE" >&2
            exit 2
        fi

        # Map to the standard format: keep only lines that already match it, and
        # normalise the spacing. A model that wrote prose around its objections
        # loses the prose; a model that wrote no valid objection line produces
        # nothing, which is reported rather than silently passed as "no findings".
        # Note: sed's ERE has no lazy quantifiers, so field 2 cannot be trimmed
        # with `([^;]+?)[[:space:]]*`. Normalising the separators globally does
        # the same job and is easier to read.
        MAPPED="$(printf '%s\n' "$RAW" \
            | sed -E 's/^[[:space:]]*[-*][[:space:]]*//' \
            | grep -E '^(BLOCKING|ADVISORY)[[:space:]]*;' \
            | sed -E 's/[[:space:]]*;[[:space:]]*/ ; /g; s/[[:space:]]+$//' \
            || true)"

        if [[ -z "$MAPPED" ]]; then
            echo "warning: the provider replied but produced no line in the standard format." >&2
            echo "         Treat this as 'review did not run', not as 'no objections'." >&2
            printf '%s\n' "$RAW" >&2
            exit 2
        fi

        printf '%s\n' "$MAPPED"
        ;;

    *)
        echo "error: unknown FACTORY_REVIEW_PROVIDER '$PROVIDER' (local|openai|anthropic|custom)" >&2
        exit 1
        ;;
esac
