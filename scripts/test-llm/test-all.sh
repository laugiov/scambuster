#!/usr/bin/env bash
# Test all configured LLM providers.
# Usage: bash scripts/test-llm/test-all.sh
#
# Set these env vars before running:
#   LLM_API_KEY=sk-...           (for OpenAI)
#   ANTHROPIC_API_KEY=sk-ant-... (for Anthropic)
#   OLLAMA_BASE_URL=...          (for Ollama, default: http://localhost:11434)
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PASS=0
FAIL=0
SKIP=0

run_test() {
    local name="$1"
    local script="$2"
    local required_var="$3"
    local value="${!required_var:-}"

    echo "=========================================="
    echo "  $name"
    echo "=========================================="

    if [ -z "$value" ]; then
        echo "[SKIP] $required_var not set"
        SKIP=$((SKIP + 1))
        echo ""
        return
    fi

    if bash "$script" 2>&1; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
    fi
    echo ""
}

# OpenAI
run_test "OpenAI" "$SCRIPT_DIR/test-openai.sh" "LLM_API_KEY"

# Anthropic
run_test "Anthropic" "$SCRIPT_DIR/test-anthropic.sh" "ANTHROPIC_API_KEY"

# Ollama (check if running instead of env var)
echo "=========================================="
echo "  Ollama"
echo "=========================================="
OLLAMA_URL="${OLLAMA_BASE_URL:-http://localhost:11434}"
if curl -sf "$OLLAMA_URL/api/tags" > /dev/null 2>&1; then
    if bash "$SCRIPT_DIR/test-ollama.sh" 2>&1; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
    fi
else
    echo "[SKIP] Ollama not running at $OLLAMA_URL"
    SKIP=$((SKIP + 1))
fi
echo ""

# Summary
echo "=========================================="
echo "  SUMMARY: $PASS passed, $FAIL failed, $SKIP skipped"
echo "=========================================="

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
