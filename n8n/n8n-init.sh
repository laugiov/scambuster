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

# Only the intake workflow needs activation (IMAP trigger polling).
# The others are sub-workflows called via executeWorkflow — they run when invoked.
ACTIVATE_WORKFLOWS="WF-INTAKE-EMAIL-V2"

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
  # BusyBox wget doesn't support PATCH — use Node.js with temp file (avoids shell quoting issues)
  if command -v node > /dev/null 2>&1; then
    local cookie_val=$(echo "$auth_header" | sed 's/Cookie: //')
    local tmp_file="/tmp/n8n-patch-$$.json"
    echo "$data" > "$tmp_file"
    node -e "
      const http = require('http');
      const fs = require('fs');
      const data = fs.readFileSync('$tmp_file', 'utf8');
      const u = new URL('$url');
      const req = http.request({
        hostname: u.hostname, port: u.port, path: u.pathname,
        method: 'PATCH',
        headers: {'Content-Type':'application/json','Cookie':'$cookie_val','Content-Length':Buffer.byteLength(data)}
      }, res => { let d=''; res.on('data',c=>d+=c); res.on('end',()=>process.stdout.write(d)); });
      req.write(data);
      req.end();
    " 2>/dev/null
    rm -f "$tmp_file"
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

# ─── 0. Ensure data directory is writable ───
# The container runs as root (for permission fixes), then drops to user "node" for n8n.
# On fresh installs, Docker creates the bind mount dir as root — n8n (node) can't write.
log "Ensuring /home/node/.n8n is writable by node user..."
mkdir -p /home/node/.n8n
chown -R 1000:1000 /home/node/.n8n

# ─── 1. Start n8n in background as user "node" ───
log "Starting n8n in background (as user node)..."
su -s /bin/sh node -c "n8n start" &
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
# n8n returns auth token as a cookie (n8n-auth=...), not in the response body
N8N_COOKIE=""
AUTH_HDR=""
if [ -n "${N8N_DEFAULT_USER_EMAIL:-}" ] && [ -n "${N8N_DEFAULT_USER_PASSWORD:-}" ]; then
  log "Authenticating with n8n REST API..."

  # Wait a bit for n8n auth service to fully initialize after healthcheck
  sleep 3

  # Build JSON payload safely with printf (avoids shell quoting issues)
  AUTH_JSON=$(printf '{"emailOrLdapLoginId":"%s","password":"%s"}' "$N8N_DEFAULT_USER_EMAIL" "$N8N_DEFAULT_USER_PASSWORD")
  AUTH_HEADERS_FILE="/tmp/n8n-auth-headers.txt"

  # Retry auth up to 3 times (auth service may take a moment after healthcheck)
  auth_attempt=0
  while [ $auth_attempt -lt 3 ]; do
    if command -v wget > /dev/null 2>&1; then
      wget -qO /dev/null -S --header="Content-Type: application/json" \
        --post-data="$AUTH_JSON" "$N8N_URL/rest/login" 2>"$AUTH_HEADERS_FILE" || true
      N8N_COOKIE=$(grep -i "set-cookie.*n8n-auth=" "$AUTH_HEADERS_FILE" | sed 's/.*n8n-auth=//;s/;.*//' | head -1)
    elif command -v curl > /dev/null 2>&1; then
      N8N_COOKIE=$(curl -s -v -X POST -H "Content-Type: application/json" \
        -d "$AUTH_JSON" "$N8N_URL/rest/login" 2>&1 | grep -i "set-cookie.*n8n-auth=" | sed 's/.*n8n-auth=//;s/;.*//' | head -1)
    fi

    if [ -n "$N8N_COOKIE" ]; then
      break
    fi
    auth_attempt=$((auth_attempt + 1))
    log "  Auth attempt $auth_attempt failed, retrying in 3s..."
    sleep 3
  done
  rm -f "$AUTH_HEADERS_FILE"

  if [ -n "$N8N_COOKIE" ]; then
    AUTH_HDR="Cookie: n8n-auth=$N8N_COOKIE"
    log "Authenticated successfully."
  else
    warn "Authentication failed. Is this a fresh instance? Create admin user first at $N8N_URL"
  fi
else
  warn "N8N_DEFAULT_USER_EMAIL/PASSWORD not set. Credential seeding will be skipped."
fi

# ─── 4. Seed IMAP credential FIRST (needed before workflow import for activation) ───
IMAP_CRED_ID=""
if [ -n "$AUTH_HDR" ] && [ -n "${HONEYPOT_IMAP_HOST:-}" ] && [ -n "${HONEYPOT_IMAP_USER:-}" ]; then
  CRED_NAME="ScamBuster IMAP"

  EXISTING_CREDS=$(http_get "$N8N_URL/rest/credentials" "$AUTH_HDR" || echo "")
  CRED_EXISTS=""
  if [ -n "$EXISTING_CREDS" ] && command -v jq > /dev/null 2>&1; then
    IMAP_CRED_ID=$(echo "$EXISTING_CREDS" | jq -r ".data[] | select(.name == \"$CRED_NAME\") | .id" 2>/dev/null || echo "")
  fi

  if [ -n "$IMAP_CRED_ID" ]; then
    log "IMAP credential '$CRED_NAME' already exists (ID: $IMAP_CRED_ID). Skipping."
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
      CRED_PAYLOAD="{\"name\":\"$CRED_NAME\",\"type\":\"imap\",\"data\":{\"host\":\"${HONEYPOT_IMAP_HOST}\",\"port\":${HONEYPOT_IMAP_PORT:-993},\"user\":\"${HONEYPOT_IMAP_USER}\",\"password\":\"${HONEYPOT_IMAP_PASSWORD:-}\",\"secure\":${HONEYPOT_IMAP_SECURE:-true}}}"
    fi

    CRED_RESULT=$(http_post "$N8N_URL/rest/credentials" "$CRED_PAYLOAD" "$AUTH_HDR" || echo "")
    if [ -n "$CRED_RESULT" ] && command -v jq > /dev/null 2>&1; then
      IMAP_CRED_ID=$(echo "$CRED_RESULT" | jq -r '.data.id // empty' 2>/dev/null || echo "")
    fi
    if [ -n "$IMAP_CRED_ID" ]; then
      log "IMAP credential created (ID: $IMAP_CRED_ID)."
    else
      warn "Failed to create IMAP credential."
    fi
  fi
elif [ -z "${HONEYPOT_IMAP_HOST:-}" ]; then
  warn "HONEYPOT_IMAP_HOST not set. IMAP credential not created."
fi

# ─── 5. Import workflows (idempotent) ───
# Use REST API if authenticated (workflows belong to the admin user and are visible in UI).
# Fall back to CLI if no auth (workflows import but may not be visible to the admin user).
if [ -d "$INIT_DIR" ] && [ "$(ls -1 "$INIT_DIR"/*.json 2>/dev/null | wc -l)" -gt 0 ]; then

  # Get existing workflow names
  # IMPORTANT: When authenticated, check ONLY via API (not CLI).
  # CLI-imported workflows belong to no user and are invisible to the admin.
  # We must re-import them via API so they belong to the admin.
  EXISTING_NAMES=""
  if [ -n "$AUTH_HDR" ]; then
    ALL_WF_RESPONSE=$(http_get "$N8N_URL/rest/workflows" "$AUTH_HDR" || echo "")
    if [ -n "$ALL_WF_RESPONSE" ] && command -v jq > /dev/null 2>&1; then
      EXISTING_NAMES=$(echo "$ALL_WF_RESPONSE" | jq -r '.data[].name' 2>/dev/null || echo "")
    fi
  else
    # No auth — use CLI (best effort, workflows may not be visible to future admin)
    EXISTING_NAMES=$(su -s /bin/sh node -c "n8n list:workflow" 2>/dev/null || echo "")
  fi

  IMPORTED=0
  SKIPPED=0
  for wf_file in "$INIT_DIR"/*.json; do
    # Extract workflow name
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
    if echo "$EXISTING_NAMES" | grep -qF "$wf_name"; then
      log "  Skip (exists): $wf_name"
      SKIPPED=$((SKIPPED + 1))
    else
      # Import via REST API (preferred — workflows belong to admin user)
      if [ -n "$AUTH_HDR" ]; then
        WF_DATA=$(cat "$wf_file")
        # Replace placeholder credential ID with the real IMAP credential ID
        if [ -n "$IMAP_CRED_ID" ] && command -v jq > /dev/null 2>&1; then
          WF_DATA=$(echo "$WF_DATA" | jq --arg cid "$IMAP_CRED_ID" '
            .nodes |= map(
              if .credentials?.imap?.id == "ScamBuster-IMAP" then
                .credentials.imap.id = $cid
              else . end
            )' 2>/dev/null || echo "$WF_DATA")
        fi
        IMPORT_RESULT=$(http_post "$N8N_URL/rest/workflows" "$WF_DATA" "$AUTH_HDR" || echo "")
        if [ -n "$IMPORT_RESULT" ] && echo "$IMPORT_RESULT" | grep -q '"id"'; then
          log "  Imported (API): $wf_name"
          IMPORTED=$((IMPORTED + 1))
        else
          err "  Failed to import (API): $wf_name"
        fi
      else
        # Fallback: CLI import (works without auth but workflows may not be visible to admin)
        if su -s /bin/sh node -c "n8n import:workflow --input='$wf_file'" 2>/dev/null; then
          log "  Imported (CLI): $wf_name"
          IMPORTED=$((IMPORTED + 1))
        else
          err "  Failed to import (CLI): $wf_name"
        fi
      fi
    fi
  done
  log "Workflow import done: $IMPORTED imported, $SKIPPED skipped."
else
  warn "No workflow files found in $INIT_DIR"
fi

# ─── 6. Fix credential IDs + Activate production workflows ───
if [ -n "$AUTH_HDR" ]; then
  log "Fixing credentials and activating production workflows..."
  ALL_WORKFLOWS=$(http_get "$N8N_URL/rest/workflows" "$AUTH_HDR" || echo "")

  if [ -n "$ALL_WORKFLOWS" ] && command -v jq > /dev/null 2>&1; then
    for wf_name in $ACTIVATE_WORKFLOWS; do
      wf_id=$(echo "$ALL_WORKFLOWS" | jq -r ".data[] | select(.name == \"$wf_name\") | .id" 2>/dev/null || echo "")
      if [ -n "$wf_id" ]; then
        is_active=$(echo "$ALL_WORKFLOWS" | jq -r ".data[] | select(.id == \"$wf_id\") | .active" 2>/dev/null || echo "false")
        if [ "$is_active" = "true" ]; then
          log "  Already active: $wf_name"
        else
          # Get the full workflow, fix the credential ID, set active=true, and PATCH it back
          # Use temp files to avoid shell quoting issues with large JSON
          FULL_WF_FILE="/tmp/n8n-wf-full-$$.json"
          PATCHED_WF_FILE="/tmp/n8n-wf-patched-$$.json"
          http_get "$N8N_URL/rest/workflows/$wf_id" "$AUTH_HDR" > "$FULL_WF_FILE" 2>/dev/null || true

          if [ -s "$FULL_WF_FILE" ] && [ -n "$IMAP_CRED_ID" ]; then
            jq --arg cid "$IMAP_CRED_ID" '
              .data.active = true |
              .data.nodes |= map(
                if .credentials?.imap?.id then
                  .credentials.imap.id = $cid
                else . end
              ) | .data' "$FULL_WF_FILE" > "$PATCHED_WF_FILE" 2>/dev/null

            if [ -s "$PATCHED_WF_FILE" ]; then
              PATCH_RESULT=$(http_patch "$N8N_URL/rest/workflows/$wf_id" "$(cat "$PATCHED_WF_FILE")" "$AUTH_HDR" 2>/dev/null || echo "")
              if echo "$PATCH_RESULT" | grep -q '"active":true' 2>/dev/null; then
                log "  Activated: $wf_name (credential ID updated)"
              else
                warn "  Failed to activate: $wf_name"
              fi
            else
              warn "  Failed to patch workflow JSON: $wf_name"
            fi
          else
            # No credential to fix — just activate
            http_patch "$N8N_URL/rest/workflows/$wf_id" '{"active":true}' "$AUTH_HDR" > /dev/null 2>&1 \
              && log "  Activated: $wf_name" \
              || warn "  Failed to activate: $wf_name"
          fi
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

# (Credential seeding moved to step 4, before workflow import)

log "═══ Init complete ═══"

# ─── 7. Wait for n8n (main process) ───
wait $N8N_PID
