#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════
# ScamBuster Preflight — checks run BEFORE quickstart destroys anything
# Usage: bash scripts/preflight.sh   (called by `make quickstart`)
# Exit: 0 = safe to proceed, 1 = abort before any destructive step
#
# `make quickstart` starts with `docker compose down -v`, which deletes the
# project's volumes. Everything here runs before that line, so a failure
# costs the operator nothing.
# ═══════════════════════════════════════════════════════════════

set -uo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✅${NC} $1"; }
fail() { echo -e "  ${RED}❌${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠️${NC}  $1"; }

ERRORS=0

echo ""
echo "PREFLIGHT"

# ─── 0. Available memory ───
# The default stack (~6 containers: postgres, redis, backend-dev, scheduler,
# frontend, n8n) is comfortable from ~4 GB of available RAM. Warning only —
# never a blocker: the stack boots on less, just slowly (or OOMs later, which
# this warning helps diagnose).
AVAIL_KB=""
if [ -r /proc/meminfo ]; then
  AVAIL_KB=$(awk '/^MemAvailable:/{print $2}' /proc/meminfo)
elif command -v sysctl > /dev/null 2>&1 && sysctl -n hw.memsize > /dev/null 2>&1; then
  # macOS has no MemAvailable — use total RAM as a rough proxy.
  AVAIL_KB=$(( $(sysctl -n hw.memsize) / 1024 ))
fi

if [ -n "${AVAIL_KB:-}" ]; then
  AVAIL_GB=$(awk -v kb="$AVAIL_KB" 'BEGIN{printf "%.1f", kb/1024/1024}')
  if [ "$AVAIL_KB" -lt $((4 * 1024 * 1024)) ]; then
    warn "~${AVAIL_GB} GB RAM available — below the ~4 GB the stack is comfortable with (may be slow or OOM)"
  else
    ok "~${AVAIL_GB} GB RAM available (≥4 GB recommended)"
  fi
else
  warn "could not determine available RAM — skipping the memory check"
fi

# ─── 1. Host ports ───
# Ports come from the merged compose config, so a docker-compose.override.yml
# that remaps them is respected. Fall back to the defaults of
# docker-compose.yml if compose cannot render the config.
PORTS=$(docker compose config 2>/dev/null \
  | awk '/^  [a-z][a-z0-9-]*:/{svc=$1} /published:/{gsub(/"/,"",$2); print svc" "$2}')

if [ -z "$PORTS" ]; then
  warn "Could not read compose config — falling back to default port list."
  PORTS="backend-dev: 8081
backend-preprod: 8082
frontend: 3002
n8n: 5678
postgres-preprod: 5433"
fi

# Containers of THIS compose project may legitimately hold the ports when
# quickstart is re-run on a running stack — `down -v` is about to free them.
PROJECT=$(docker compose config --format json 2>/dev/null \
  | sed -n 's/.*"name": *"\([^"]*\)".*/\1/p' | head -1)
[ -z "$PROJECT" ] && PROJECT="scambuster"

port_holder() {
  # Prints "<container> (project <p>)" for whoever publishes $1, else nothing.
  docker ps --format '{{.Names}}\t{{.Ports}}\t{{.Label "com.docker.compose.project"}}' 2>/dev/null \
    | awk -F'\t' -v p=":$1->" '$2 ~ p {print $1"\t"$3; exit}'
}

while read -r svc port; do
  [ -z "${port:-}" ] && continue
  svc=${svc%:}

  if ! command -v ss > /dev/null 2>&1; then
    warn "ss not available — skipping port check for $port ($svc)."
    continue
  fi

  if ! ss -ltn "sport = :$port" 2>/dev/null | grep -q LISTEN; then
    ok "port $port free ($svc)"
    continue
  fi

  holder=$(port_holder "$port")
  holder_name=$(echo "$holder" | cut -f1)
  holder_proj=$(echo "$holder" | cut -f2)

  if [ -n "$holder_name" ] && [ "$holder_proj" = "$PROJECT" ]; then
    ok "port $port held by our own $holder_name — freed by 'down -v'"
  elif [ -n "$holder_name" ]; then
    fail "port $port ($svc) is taken by container '$holder_name'"
    echo "       Stop it, or remap the port in docker-compose.override.yml:"
    echo "         services:"
    echo "           $svc:"
    echo "             ports: !override   # Compose APPENDS without this tag"
    echo "               - \"<free-port>:<container-port>\""
    ERRORS=$((ERRORS + 1))
  else
    fail "port $port ($svc) is in use by a non-Docker process on this host"
    ERRORS=$((ERRORS + 1))
  fi
done <<< "$PORTS"

# ─── 2. Existing volumes (quickstart is destructive) ───
VOLUMES=$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep "^${PROJECT}_" || true)

if [ -n "$VOLUMES" ]; then
  echo ""
  warn "'make quickstart' runs 'docker compose down -v' and will DELETE these volumes:"
  echo "$VOLUMES" | sed 's/^/       /'
  echo "       Any ScamBuster data they hold (captured conversations, IOCs,"
  echo "       n8n credentials) is lost and cannot be recovered."
  echo ""

  if [ "${FORCE:-0}" = "1" ]; then
    warn "FORCE=1 set — proceeding without confirmation."
  elif [ ! -t 0 ]; then
    fail "Not running interactively and FORCE=1 not set — aborting."
    echo "       Re-run with 'FORCE=1 make quickstart' to accept the data loss,"
    echo "       or back the database up first:"
    echo "         docker compose exec -T postgres pg_dump -U postgres -d scambuster > backup.sql"
    ERRORS=$((ERRORS + 1))
  else
    printf "  Type 'yes' to delete them and continue: "
    read -r REPLY
    if [ "$REPLY" != "yes" ]; then
      fail "Aborted at the operator's request. Nothing was deleted."
      ERRORS=$((ERRORS + 1))
    else
      ok "Confirmed — volumes will be recreated from scratch."
    fi
  fi
else
  ok "no existing ${PROJECT} volumes — clean install"
fi

echo ""

if [ "$ERRORS" -eq 0 ]; then
  echo -e "${GREEN}[Preflight] OK — nothing has been modified yet.${NC}"
  exit 0
fi

echo -e "${RED}[Preflight] $ERRORS blocker(s). Nothing was deleted; fix and re-run.${NC}"
exit 1
