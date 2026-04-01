#!/usr/bin/env bash
# Validate n8n workflow JSON files for hardcoded values
# Usage: ./scripts/validate-n8n-workflows.sh
# Exit code: 0 = all checks pass, 1 = at least one check fails

set -euo pipefail

WORKFLOW_DIR="n8n/workflows"
ERRORS=0

echo "[validate-n8n] Checking workflow JSON files..."
echo ""

# Check 1: No hardcoded backend URLs
echo "=== Hardcoded Backend URLs ==="
if grep -r "backend-dev:8080" "$WORKFLOW_DIR"/WF-*.json 2>/dev/null; then
  echo "  ❌ FAIL: hardcoded backend-dev:8080 URLs found"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✅ PASS: zero hardcoded backend URLs"
fi
echo ""

# Check 2: No plaintext passwords
echo "=== Plaintext Passwords ==="
if grep -r "Un1que" "$WORKFLOW_DIR"/WF-*.json 2>/dev/null; then
  echo "  ❌ FAIL: plaintext passwords found"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✅ PASS: zero plaintext passwords"
fi
echo ""

# Check 3: No hardcoded user@example.com in workflow credentials
echo "=== Hardcoded Credentials ==="
if grep -r '"user@example.com"' "$WORKFLOW_DIR"/WF-*.json 2>/dev/null; then
  echo "  ❌ FAIL: hardcoded user@example.com found"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✅ PASS: zero hardcoded email credentials"
fi
echo ""

# Check 4: All executeWorkflow nodes use mode=id with PLACEHOLDER_* values
# (n8n-init.sh replaces placeholders with real IDs at startup)
echo "=== Workflow ID References ==="
if command -v python3 &>/dev/null; then
  RESULT=$(python3 -c "
import json, glob, sys
errors = 0
for f in sorted(glob.glob('$WORKFLOW_DIR/WF-*.json')):
    with open(f) as fh:
        wf = json.load(fh)
    for node in wf.get('nodes', []):
        if node.get('type') == 'n8n-nodes-base.executeWorkflow':
            wf_id = node['parameters']['workflowId']
            mode = wf_id.get('mode', 'UNKNOWN')
            value = wf_id.get('value', '')
            if mode == 'id' and value.startswith('PLACEHOLDER_'):
                print(f'  ✅ {node[\"name\"]}: mode=id, placeholder={value}')
            elif mode == 'id':
                print(f'  ❌ {node[\"name\"]} in {f}: mode=id but value={value} (expected PLACEHOLDER_*)')
                errors += 1
            else:
                print(f'  ❌ {node[\"name\"]} in {f}: mode={mode} (expected id)')
                errors += 1
sys.exit(errors)
" 2>&1) || ERRORS=$((ERRORS + $?))
  echo "$RESULT"
else
  echo "  ⚠️  python3 not available, skipping workflow ID check"
fi
echo ""

# Check 5: JSON validity
echo "=== JSON Validity ==="
for f in "$WORKFLOW_DIR"/WF-*.json; do
  if python3 -c "import json; json.load(open('$f'))" 2>/dev/null; then
    echo "  ✅ $(basename "$f")"
  else
    echo "  ❌ $(basename "$f") — invalid JSON"
    ERRORS=$((ERRORS + 1))
  fi
done
echo ""

# Summary
echo "========================================="
if [ "$ERRORS" -eq 0 ]; then
  echo "[validate-n8n] All checks passed ✅"
  exit 0
else
  echo "[validate-n8n] $ERRORS check(s) failed ❌"
  exit 1
fi
