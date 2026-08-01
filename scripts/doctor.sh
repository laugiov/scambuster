#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# ScamBuster Doctor — Environment & Connectivity Health Check
# Usage: make doctor (or ./scripts/doctor.sh)
# Exit: 0 = all required checks pass, 1 = at least one fails
# ═══════════════════════════════════════════════════════════════

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
GREY='\033[0;90m'
NC='\033[0m' # No Color

ERRORS=0

ok()   { echo -e "  ${GREEN}✅${NC} $1"; }
fail() { echo -e "  ${RED}❌${NC} $1"; ERRORS=$((ERRORS + 1)); }
warn() { echo -e "  ${YELLOW}⚠️${NC}  $1"; }
skip() { echo -e "  ${GREY}⬜${NC} $1"; }

# Load .env if it exists
if [ -f .env ]; then
  set -a
  . ./.env
  set +a
fi

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║       ScamBuster Doctor                  ║"
echo "╚══════════════════════════════════════════╝"
echo ""

# ─── REQUIRED ENV VARS ───
echo "REQUIRED ENVIRONMENT"

check_var() {
  local var_name="$1"
  local placeholder="${2:-}"
  local value
  eval "value=\${$var_name:-}"

  if [ -z "$value" ]; then
    fail "$var_name — not set"
  elif [ -n "$placeholder" ] && [ "$value" = "$placeholder" ]; then
    warn "$var_name — appears to be a placeholder ($placeholder)"
  else
    ok "$var_name — set"
  fi
}

check_var "POSTGRES_PASSWORD" ""
check_var "JWT_PASSPHRASE" ""
check_var "LLM_API_KEY" "sk-your-api-key-here"
check_var "HONEYPOT_IMAP_HOST" ""
check_var "HONEYPOT_IMAP_USER" "your-honeypot@gmail.com"
check_var "HONEYPOT_IMAP_PASSWORD" "your-app-password-here"
check_var "MAILER_DSN" "null://null"
check_var "N8N_ENCRYPTION_KEY" "dev-only-change-in-production-openssl-rand-hex-32"
check_var "SCAMBUSTER_API_URL" ""
check_var "INGEST_LOGIN" "user@example.com"

echo ""

# ─── CONNECTIVITY ───
echo "CONNECTIVITY"

# Backend API
BACKEND_URL="${SCAMBUSTER_API_URL:-http://localhost:8081}"
if curl -sf "${BACKEND_URL}/api/health" > /dev/null 2>&1; then
  ok "Backend API — ${BACKEND_URL} → OK"
elif curl -sf "http://localhost:8081/api/health" > /dev/null 2>&1; then
  ok "Backend API — http://localhost:8081 → OK"
else
  fail "Backend API — unreachable (tried ${BACKEND_URL})"
fi

# PostgreSQL (try Docker first, then local)
if docker compose exec -T postgres pg_isready -U postgres 2>/dev/null | grep -q "accepting connections"; then
  ok "PostgreSQL — connected"
elif command -v pg_isready > /dev/null 2>&1 && pg_isready -h "${POSTGRES_HOST:-localhost}" -p "${POSTGRES_PORT:-5432}" -U postgres > /dev/null 2>&1; then
  ok "PostgreSQL — connected (local)"
else
  fail "PostgreSQL — unreachable"
fi

# Redis
if docker compose exec -T redis redis-cli ping > /dev/null 2>&1; then
  ok "Redis — connected"
else
  warn "Redis — cannot check"
fi

# n8n
N8N_URL="${N8N_HOST:-localhost}:${N8N_HTTP_PORT:-5678}"
if curl -sf "http://${N8N_URL}/healthz" > /dev/null 2>&1; then
  ok "n8n — http://${N8N_URL} → healthy"
else
  if curl -sf "http://localhost:5678/healthz" > /dev/null 2>&1; then
    ok "n8n — http://localhost:5678 → healthy"
  else
    fail "n8n — unreachable"
  fi
fi

echo ""

# ─── n8n WORKFLOWS ───
echo "n8n WORKFLOWS"

# Try REST API first, then CLI
N8N_API_AVAILABLE=false
if [ -n "${N8N_API_KEY:-}" ]; then
  WF_LIST=$(curl -sf -H "X-N8N-API-KEY: ${N8N_API_KEY}" "http://localhost:5678/api/v1/workflows" 2>/dev/null || echo "")
  if [ -n "$WF_LIST" ]; then
    N8N_API_AVAILABLE=true
  fi
fi

if [ "$N8N_API_AVAILABLE" = true ]; then
  for wf_name in "WF-INTAKE-EMAIL-V2" "WF-REPLY-GENERATE-V2" "WF-REPLY-SEND-v1" "WF-EXTRACT-AND-ENRICH-IOC"; do
    wf_data=$(echo "$WF_LIST" | jq -r ".data[] | select(.name == \"$wf_name\")" 2>/dev/null || echo "")
    if [ -n "$wf_data" ]; then
      active=$(echo "$wf_data" | jq -r '.active' 2>/dev/null || echo "false")
      if [ "$active" = "true" ]; then
        ok "$wf_name — imported, active"
      else
        warn "$wf_name — imported, inactive"
      fi
    else
      fail "$wf_name — not found"
    fi
  done

  for wf_name in "ScamBuster - Gmail Scam Simulator - INIT" "ScamBuster - Gmail Scam Simulator - REPLY"; do
    wf_data=$(echo "$WF_LIST" | jq -r ".data[] | select(.name == \"$wf_name\")" 2>/dev/null || echo "")
    if [ -n "$wf_data" ]; then
      skip "$wf_name — imported, inactive (expected)"
    else
      skip "$wf_name — not imported (optional)"
    fi
  done
else
  warn "n8n API not available — set N8N_API_KEY to check workflow status"
  skip "Workflow checks skipped"
fi

echo ""

# ─── OPTIONAL ───
echo "OPTIONAL"

check_optional() {
  local var_name="$1"
  local value
  eval "value=\${$var_name:-}"
  if [ -n "$value" ]; then
    ok "$var_name — configured"
  else
    skip "$var_name — not configured"
  fi
}

check_optional "VIRUSTOTAL_API_KEY"
check_optional "URLSCAN_API_KEY"

echo ""

# ─── SUMMARY ───
echo "════════════════════════════════════════════"
if [ "$ERRORS" -eq 0 ]; then
  echo -e "${GREEN}[ScamBuster Doctor] All required checks passed. System ready.${NC}"
  exit 0
else
  echo -e "${RED}[ScamBuster Doctor] $ERRORS required check(s) failed.${NC}"
  exit 1
fi
