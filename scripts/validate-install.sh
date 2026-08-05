#!/usr/bin/env bash
# ScamBuster -- Installation Validator
# Checks that all services are running and reachable.
# Usage: bash scripts/validate-install.sh  (or: make validate)

set -uo pipefail

ERRORS=0

echo "=== ScamBuster Installation Validator ==="
echo ""

check_service() {
  local name="$1"
  local cmd="$2"
  if eval "$cmd" > /dev/null 2>&1; then
    echo "[OK]   $name"
  else
    echo "[FAIL] $name"
    ERRORS=$((ERRORS + 1))
  fi
}

check_optional() {
  local name="$1"
  local cmd="$2"
  if eval "$cmd" > /dev/null 2>&1; then
    echo "[OK]   $name"
  else
    echo "[WARN] $name (optional)"
  fi
}

# -- Docker services --
echo "-- Docker Services --"
for svc in postgres redis backend-dev frontend; do
  check_service "$svc running" "docker compose ps $svc 2>/dev/null | grep -qE 'running|Up'"
done
echo ""

# -- Health checks --
echo "-- Health Checks --"
check_service "PostgreSQL ready" "docker compose exec -T postgres pg_isready -U postgres"
check_service "Redis responding" "docker compose exec -T redis redis-cli ping 2>/dev/null | grep -q PONG"
check_service "Backend API (:8081)" "curl -sf http://localhost:8081/healthz"
check_service "Frontend (:3002)" "curl -sf http://localhost:3002"
echo ""

# -- Optional services --
echo "-- Optional Services --"
check_optional "n8n (:5678)" "curl -sf http://localhost:5678"
check_optional "Vault (:8200)" "curl -sf http://localhost:8200/v1/sys/health"
check_optional "Backend preprod (:8082)" "curl -sf http://localhost:8082/healthz"
echo ""

# -- Authentication --
echo "-- Authentication --"
TOKEN=$(curl -sf -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"Un1que$trongPassword2024"}' 2>/dev/null \
  | python3 -c "import sys,json; print(json.load(sys.stdin).get('access_token',''))" 2>/dev/null || true)

if [ -n "$TOKEN" ] && [ "$TOKEN" != "None" ] && [ "$TOKEN" != "" ]; then
  echo "[OK]   JWT login works"
else
  echo "[FAIL] JWT login failed (run: make fixtures-dev)"
  ERRORS=$((ERRORS + 1))
fi
echo ""

# -- Environment --
echo "-- Environment --"
if [ -f .env ]; then
  echo "[OK]   .env file exists"
else
  echo "[FAIL] .env file missing (run: cp .env.dist .env)"
  ERRORS=$((ERRORS + 1))
fi

echo ""
echo "=== Result: $ERRORS error(s) ==="

if [ "$ERRORS" -eq 0 ]; then
  echo "ScamBuster is ready."
  exit 0
else
  echo "Fix the errors above and re-run: make validate"
  exit 1
fi
