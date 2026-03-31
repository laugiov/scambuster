#!/bin/sh
# ═══════════════════════════════════════════════════════════════
# ScamBuster — n8n Init Script (Architecture B)
# Auto-imports workflows, seeds IMAP credentials, activates production workflows.
# Runs as Docker entrypoint: starts n8n in background, then configures via REST API.
# Uses wget (not curl) — Alpine-based n8n images don't include curl.
# ═══════════════════════════════════════════════════════════════

set -eu

INIT_DIR="/home/node/init-workflows"
LOG_PREFIX="[n8n-init]"
MAX_RETRIES=30
RETRY_INTERVAL=3
N8N_URL="http://localhost:5678"

PRODUCTION_WORKFLOWS="WF-INTAKE-EMAIL-V2 WF-REPLY-GENERATE-V2 WF-REPLY-SEND-v1 WF-EXTRACT-AND-ENRICH-IOC"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $LOG_PREFIX $1"; }
warn() { echo "$(date '+%Y-%m-%d %H:%M:%S') $LOG_PREFIX WARNING: $1"; }
err() { echo "$(date '+%Y-%m-%d %H:%M:%S') $LOG_PREFIX ERROR: $1" >&2; }

# HTTP helper — uses wget (available in Alpine), falls back to curl
http_get() {
  local url="$1"
  local headers="${2:-}"
  if command -v wget > /dev/null 2>&1; then
    if [ -n "$headers" ]; then
      wget -qO- --header="$headers" "$url" 2>/dev/null
    else
      wget -qO- "$url" 2>/dev/null
    fi
  elif command -v curl > /dev/null 2>&1; then
    if [ -n "$headers" ]; then
      curl -sf -H "$headers" "$url" 2>/dev/null
    else
      curl -sf "$url" 2>/dev/null
    fi
  else
    return 1
  fi
}

http_post() {
  local url="$1"
  local data="$2"
  local headers="${3:-}"
  if command -v wget > /dev/null 2>&1; then
    if [ -n "$headers" ]; then
      wget -qO- --header="Content-Type: application/json" --header="$headers" \
        --post-data="$data" "$url" 2>/dev/null
    else
      wget -qO- --header="Content-Type: application/json" \
        --post-data="$data" "$url" 2>/dev/null
    fi
  elif command -v curl > /dev/null 2>&1; then
    if [ -n "$headers" ]; then
      curl -sf -X POST -H "Content-Type: application/json" -H "$headers" -d "$data" "$url" 2>/dev/null
    else
      curl -sf -X POST -H "Content-Type: application/json" -d "$data" "$url" 2>/dev/null
    fi
  else
    return 1
  fi
}

http_patch() {
  local url="$1"
  local data="$2"
  local auth_header="$3"
  if command -v wget > /dev/null 2>&1; then
    wget -qO- --method=PATCH --header="Content-Type: application/json" \
      --header="$auth_header" --body-data="$data" "$url" 2>/dev/null
  elif command -v curl > /dev/null 2>&1; then
    curl -sf -X PATCH -H "Content-Type: application/json" -H "$auth_header" -d "$data" "$url" 2>/dev/null
  else
    return 1
  fi
}

http_check() {
  local url="$1"
  if command -v wget > /dev/null 2>&1; then
    wget -q --spider "$url" 2>/dev/null
  elif command -v curl > /dev/null 2>&1; then
    curl -sf -o /dev/null "$url" 2>/dev/null
  else
    return 1
  fi
}

# ─── 1. Start n8n in background ───
log "Starting n8n in background..."
n8n start &
N8N_PID=$!

# Relay Docker shutdown signals to n8n
trap "log 'Shutting down n8n...'; kill $N8N_PID; wait $N8N_PID" SIGTERM SIGINT

# ─── 2. Wait for n8n to be ready ───
log "Waiting for n8n to be ready..."
retries=0
while [ $retries -lt $MAX_RETRIES ]; do
  if http_check "$N8N_URL/healthz"; then
    log "n8n is ready."
    break
  fi
  retries=$((retries + 1))
  log "  Waiting... ($retries/$MAX_RETRIES)"
  sleep $RETRY_INTERVAL
done

if [ $retries -eq $MAX_RETRIES ]; then
  err "n8n did not become ready after $((MAX_RETRIES * RETRY_INTERVAL))s. Skipping init."
  wait $N8N_PID
  exit 1
fi

# ─── 3. Authenticate via REST API ───
N8N_TOKEN=""
if [ -n "${N8N_DEFAULT_USER_EMAIL:-}" ] && [ -n "${N8N_DEFAULT_USER_PASSWORD:-}" ]; then
  log "Authenticating with n8n REST API..."
  AUTH_RESPONSE=$(http_post "$N8N_URL/rest/login" \
    "{\"email\":\"${N8N_DEFAULT_USER_EMAIL}\",\"password\":\"${N8N_DEFAULT_USER_PASSWORD}\"}" || echo "")

  if [ -n "$AUTH_RESPONSE" ]; then
    # Extract token — try jq first, fall back to grep
    if command -v jq > /dev/null 2>&1; then
      N8N_TOKEN=$(echo "$AUTH_RESPONSE" | jq -r '.data.token // empty' 2>/dev/null || echo "")
    else
      N8N_TOKEN=$(echo "$AUTH_RESPONSE" | grep -o '"token":"[^"]*"' | head -1 | sed 's/"token":"//;s/"//' || echo "")
    fi
    if [ -n "$N8N_TOKEN" ]; then
      log "Authenticated successfully."
    else
      warn "Could not extract token from auth response. Credential seeding will be skipped."
    fi
  else
    warn "Authentication failed. Is this a fresh instance? Create admin user first at $N8N_URL"
  fi
else
  warn "N8N_DEFAULT_USER_EMAIL/PASSWORD not set. Credential seeding will be skipped."
fi

# ─── 4. Import workflows (idempotent) ───
if [ -d "$INIT_DIR" ] && [ "$(ls -1 "$INIT_DIR"/*.json 2>/dev/null | wc -l)" -gt 0 ]; then
  # Get existing workflow names
  EXISTING_WORKFLOWS=""
  if n8n list:workflow > /dev/null 2>&1; then
    EXISTING_WORKFLOWS=$(n8n list:workflow 2>/dev/null || echo "")
  fi

  IMPORTED=0
  SKIPPED=0
  for wf_file in "$INIT_DIR"/*.json; do
    # Extract workflow name — try jq, fall back to grep
    if command -v jq > /dev/null 2>&1; then
      wf_name=$(jq -r '.name // empty' "$wf_file" 2>/dev/null || echo "")
    else
      wf_name=$(grep -o '"name":"[^"]*"' "$wf_file" | head -1 | sed 's/"name":"//;s/"//' || echo "")
    fi

    if [ -z "$wf_name" ]; then
      warn "Could not read name from $(basename "$wf_file"), skipping."
      continue
    fi

    # Check if workflow already exists (by name)
    if echo "$EXISTING_WORKFLOWS" | grep -qF "$wf_name"; then
      log "  Skip (exists): $wf_name"
      SKIPPED=$((SKIPPED + 1))
    else
      if n8n import:workflow --input="$wf_file" 2>/dev/null; then
        log "  Imported: $wf_name"
        IMPORTED=$((IMPORTED + 1))
      else
        err "  Failed to import: $wf_name"
      fi
    fi
  done
  log "Workflow import done: $IMPORTED imported, $SKIPPED skipped."
else
  warn "No workflow files found in $INIT_DIR"
fi

# ─── 5. Activate production workflows (by name, not --all) ───
if [ -n "$N8N_TOKEN" ]; then
  log "Activating production workflows..."
  AUTH_HDR="Authorization: Bearer $N8N_TOKEN"
  ALL_WORKFLOWS=$(http_get "$N8N_URL/rest/workflows" "$AUTH_HDR" || echo "")

  if [ -n "$ALL_WORKFLOWS" ] && command -v jq > /dev/null 2>&1; then
    for wf_name in $PRODUCTION_WORKFLOWS; do
      wf_id=$(echo "$ALL_WORKFLOWS" | jq -r ".data[] | select(.name == \"$wf_name\") | .id" 2>/dev/null || echo "")
      if [ -n "$wf_id" ]; then
        is_active=$(echo "$ALL_WORKFLOWS" | jq -r ".data[] | select(.id == \"$wf_id\") | .active" 2>/dev/null || echo "false")
        if [ "$is_active" = "true" ]; then
          log "  Already active: $wf_name"
        else
          http_patch "$N8N_URL/rest/workflows/$wf_id" '{"active":true}' "$AUTH_HDR" > /dev/null 2>&1 \
            && log "  Activated: $wf_name" \
            || warn "  Failed to activate: $wf_name"
        fi
      else
        warn "  Workflow not found: $wf_name"
      fi
    done
  else
    warn "Could not list workflows via API. Activate manually in n8n UI."
  fi
else
  warn "No auth token — skipping workflow activation. Activate manually in n8n UI."
fi

# ─── 6. Seed IMAP credential (if env vars set) ───
if [ -n "$N8N_TOKEN" ] && [ -n "${HONEYPOT_IMAP_HOST:-}" ] && [ -n "${HONEYPOT_IMAP_USER:-}" ]; then
  CRED_NAME="ScamBuster IMAP"
  AUTH_HDR="Authorization: Bearer $N8N_TOKEN"

  # Check if credential already exists
  EXISTING_CREDS=$(http_get "$N8N_URL/rest/credentials" "$AUTH_HDR" || echo "")
  CRED_EXISTS=""
  if [ -n "$EXISTING_CREDS" ] && command -v jq > /dev/null 2>&1; then
    CRED_EXISTS=$(echo "$EXISTING_CREDS" | jq -e ".data[] | select(.name == \"$CRED_NAME\")" 2>/dev/null || echo "")
  fi

  if [ -n "$CRED_EXISTS" ]; then
    log "IMAP credential '$CRED_NAME' already exists. Skipping."
  else
    log "Creating IMAP credential '$CRED_NAME'..."
    if command -v jq > /dev/null 2>&1; then
      CRED_PAYLOAD=$(jq -n \
        --arg name "$CRED_NAME" \
        --arg host "${HONEYPOT_IMAP_HOST}" \
        --arg port "${HONEYPOT_IMAP_PORT:-993}" \
        --arg user "${HONEYPOT_IMAP_USER}" \
        --arg pass "${HONEYPOT_IMAP_PASSWORD:-}" \
        --argjson secure "${HONEYPOT_IMAP_SECURE:-true}" \
        '{name: $name, type: "imap", data: {host: $host, port: ($port | tonumber), user: $user, password: $pass, secure: $secure}}')
    else
      # Fallback without jq — simple JSON (no special chars in password supported)
      CRED_PAYLOAD="{\"name\":\"$CRED_NAME\",\"type\":\"imap\",\"data\":{\"host\":\"${HONEYPOT_IMAP_HOST}\",\"port\":${HONEYPOT_IMAP_PORT:-993},\"user\":\"${HONEYPOT_IMAP_USER}\",\"password\":\"${HONEYPOT_IMAP_PASSWORD:-}\",\"secure\":${HONEYPOT_IMAP_SECURE:-true}}}"
    fi

    CRED_RESULT=$(http_post "$N8N_URL/rest/credentials" "$CRED_PAYLOAD" "$AUTH_HDR" || echo "")

    if [ -n "$CRED_RESULT" ] && echo "$CRED_RESULT" | grep -q '"id"'; then
      log "IMAP credential created successfully."
    else
      warn "Failed to create IMAP credential. Create manually in n8n UI."
    fi
  fi
elif [ -z "${HONEYPOT_IMAP_HOST:-}" ]; then
  warn "HONEYPOT_IMAP_HOST not set. IMAP credential not created. Email workflows will fail."
fi

log "═══ Init complete ═══"

# ─── 7. Wait for n8n (main process) ───
wait $N8N_PID
