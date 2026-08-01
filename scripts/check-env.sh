#!/usr/bin/env bash
# ScamBuster -- Environment Variable Validator
# Checks that required variables are set before starting the stack.
# Usage: bash scripts/check-env.sh

set -euo pipefail

ENV_FILE="${1:-.env}"

if [ ! -f "$ENV_FILE" ]; then
  echo "=== ScamBuster Environment Check ==="
  echo ""
  echo "[FAIL] $ENV_FILE not found."
  echo "       Run: cp .env.dist .env"
  echo "       Then edit .env with your values."
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

ERRORS=0
WARNINGS=0

echo "=== ScamBuster Environment Check ==="
echo ""

# --- Required variables (stack will not work without these) ---

check_required() {
  local var_name="$1"
  local hint="$2"
  local value="${!var_name:-}"

  if [ -z "$value" ] || [[ "$value" == *"change-me"* ]]; then
    echo "[FAIL] $var_name is not set -- $hint"
    ERRORS=$((ERRORS + 1))
  else
    echo "[OK]   $var_name"
  fi
}

check_optional() {
  local var_name="$1"
  local hint="$2"
  local value="${!var_name:-}"

  if [ -z "$value" ] || [[ "$value" == *"change-me"* ]] || [[ "$value" == *"your-"* ]]; then
    echo "[WARN] $var_name is not set -- $hint"
    WARNINGS=$((WARNINGS + 1))
  else
    echo "[OK]   $var_name"
  fi
}

echo "-- Required --"
check_required POSTGRES_PASSWORD    "choose a strong password"
check_required DATABASE_URL         "must match POSTGRES_PASSWORD (e.g. postgresql://postgres:YOURPASS@postgres:5432/scambuster)"
check_required APP_SECRET           "generate with: php -r \"echo bin2hex(random_bytes(16));\""
check_required JWT_PASSPHRASE        "generate with: openssl rand -hex 32"

echo ""
echo "-- Optional (features may be limited without these) --"
check_optional LLM_API_KEY          "required for LLM reply generation (https://platform.openai.com/api-keys)"
check_optional N8N_ENCRYPTION_KEY   "required for n8n workflow encryption"
check_optional VAULT_TOKEN          "required for Vault secret management"

echo ""
echo "=== Result: $ERRORS error(s), $WARNINGS warning(s) ==="

if [ "$ERRORS" -gt 0 ]; then
  echo ""
  echo "Fix the errors above before starting the stack."
  exit 1
else
  if [ "$WARNINGS" -gt 0 ]; then
    echo "Stack will start but some features may not work."
  else
    echo "All checks passed."
  fi
  exit 0
fi
