#!/bin/bash
# Activate or deactivate all n8n workflows via REST API
# Usage: ./scripts/manage-workflows.sh [activate|deactivate] [n8n_url]
#
# Prerequisites: n8n must be running and N8N_API_KEY must be set.

set -euo pipefail

ACTION="${1:-activate}"
N8N_URL="${2:-http://localhost:5678}"
API_KEY="${N8N_API_KEY:-}"

if [ -z "$API_KEY" ]; then
    echo "Error: N8N_API_KEY environment variable is not set."
    echo "Set it with: export N8N_API_KEY=your-api-key"
    echo "You can find or create an API key in n8n Settings > API."
    exit 1
fi

if [ "$ACTION" != "activate" ] && [ "$ACTION" != "deactivate" ]; then
    echo "Usage: $0 [activate|deactivate] [n8n_url]"
    exit 1
fi

ACTIVE_VALUE="true"
if [ "$ACTION" = "deactivate" ]; then
    ACTIVE_VALUE="false"
fi

echo "Fetching workflows from $N8N_URL..."

WORKFLOWS=$(curl -s -H "X-N8N-API-KEY: $API_KEY" "$N8N_URL/api/v1/workflows" 2>/dev/null)

if [ -z "$WORKFLOWS" ] || echo "$WORKFLOWS" | grep -q '"code"'; then
    echo "Error: Could not connect to n8n at $N8N_URL"
    echo "Make sure n8n is running and the API key is valid."
    exit 1
fi

WORKFLOW_IDS=$(echo "$WORKFLOWS" | python3 -c "
import sys, json
data = json.load(sys.stdin)
workflows = data.get('data', data) if isinstance(data, dict) else data
for wf in workflows:
    print(wf['id'], wf.get('name', 'unknown'), wf.get('active', False))
" 2>/dev/null)

if [ -z "$WORKFLOW_IDS" ]; then
    echo "No workflows found."
    exit 0
fi

COUNT=0
ERRORS=0

while IFS=' ' read -r id name active; do
    DISPLAY_NAME=$(echo "$name" | head -c 50)
    RESPONSE=$(curl -s -X PATCH \
        -H "X-N8N-API-KEY: $API_KEY" \
        -H "Content-Type: application/json" \
        -d "{\"active\": $ACTIVE_VALUE}" \
        "$N8N_URL/api/v1/workflows/$id" 2>/dev/null)

    if echo "$RESPONSE" | grep -q "\"active\":$ACTIVE_VALUE\|\"active\": $ACTIVE_VALUE"; then
        echo "  [OK] $DISPLAY_NAME (id=$id) -> active=$ACTIVE_VALUE"
        COUNT=$((COUNT + 1))
    else
        echo "  [FAIL] $DISPLAY_NAME (id=$id)"
        ERRORS=$((ERRORS + 1))
    fi
done <<< "$WORKFLOW_IDS"

echo ""
echo "Done: $COUNT workflows ${ACTION}d, $ERRORS errors."

if [ $ERRORS -gt 0 ]; then
    exit 1
fi
