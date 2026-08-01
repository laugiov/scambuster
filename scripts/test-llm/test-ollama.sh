#!/usr/bin/env bash
# Test Ollama API connectivity with the same payload format ScamBuster uses.
# Prerequisites: Ollama must be installed and a model pulled.
# Usage: bash scripts/test-llm/test-ollama.sh [model] [base_url]
set -euo pipefail

MODEL="${1:-${LLM_MODEL:-llama3}}"
BASE_URL="${2:-${OLLAMA_BASE_URL:-http://localhost:11434}}"

echo "=== Ollama API Test ==="
echo "Model:    $MODEL"
echo "Endpoint: $BASE_URL/api/chat"
echo ""

# Pre-check: is Ollama running?
echo "-- Pre-check: Ollama reachable --"
if ! curl -sf "$BASE_URL/api/tags" > /dev/null 2>&1; then
    echo "[FAIL] Ollama is not running at $BASE_URL"
    echo ""
    echo "Install Ollama:"
    echo "  curl -fsSL https://ollama.com/install.sh | sh"
    echo ""
    echo "Pull a model:"
    echo "  ollama pull $MODEL"
    echo ""
    echo "Then re-run this script."
    exit 1
fi
echo "[OK]   Ollama is running"

# Pre-check: is the model available?
echo "-- Pre-check: Model available --"
HAS_MODEL=$(curl -sf "$BASE_URL/api/tags" | python3 -c "
import sys, json
d = json.load(sys.stdin)
models = [m['name'].split(':')[0] for m in d.get('models', [])]
print('yes' if '$MODEL' in models or '${MODEL}:latest' in [m['name'] for m in d.get('models', [])] else 'no')
" 2>/dev/null || echo "no")

if [ "$HAS_MODEL" = "no" ]; then
    echo "[FAIL] Model '$MODEL' not found."
    echo "Available models:"
    curl -sf "$BASE_URL/api/tags" | python3 -c "
import sys, json
d = json.load(sys.stdin)
for m in d.get('models', []):
    print(f'  - {m[\"name\"]}')
" 2>/dev/null
    echo ""
    echo "Pull it: ollama pull $MODEL"
    exit 1
fi
echo "[OK]   Model '$MODEL' is available"
echo ""

# Test 1: Reply generation
echo "-- Test 1: Reply generation --"
RESPONSE=$(curl -sf -X POST "$BASE_URL/api/chat" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"messages\": [
      {\"role\": \"system\", \"content\": \"You are an elderly person who received a suspicious email. Reply naturally in 2-3 sentences.\"},
      {\"role\": \"user\", \"content\": \"Dear friend, I have a business proposal for you. Please send me your bank details.\"}
    ],
    \"stream\": false,
    \"options\": {\"temperature\": 0.6}
  }" 2>&1)

CONTENT=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['message']['content'])" 2>/dev/null)
EVAL=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f'eval_count={d.get(\"eval_count\",\"?\")}, prompt_eval={d.get(\"prompt_eval_count\",\"?\")}')" 2>/dev/null)

if [ -n "$CONTENT" ]; then
    echo "[OK]   Reply: ${CONTENT:0:120}..."
    echo "       Tokens: $EVAL"
else
    echo "[FAIL] No content in response"
    echo "$RESPONSE" | head -5
    exit 1
fi
echo ""

# Test 2: JSON response (classification)
echo "-- Test 2: JSON classification --"
RESPONSE=$(curl -sf -X POST "$BASE_URL/api/chat" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"messages\": [
      {\"role\": \"system\", \"content\": \"Classify this email. Respond ONLY with JSON: {\\\"scam_type\\\": \\\"TYPE\\\", \\\"confidence\\\": 0.0}. Types: PHISHING, ROMANCE, TECH_SUPPORT, UNKNOWN.\"},
      {\"role\": \"user\", \"content\": \"Your account has been compromised. Click here to verify your identity immediately.\"}
    ],
    \"stream\": false,
    \"options\": {\"temperature\": 0.1}
  }" 2>&1)

CONTENT=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['message']['content'])" 2>/dev/null)
echo "[OK]   Classification: ${CONTENT:0:100}"
echo ""

# Test 3: Verify response structure matches OllamaClient expectations
echo "-- Test 3: Response structure check --"
RESPONSE=$(curl -sf -X POST "$BASE_URL/api/chat" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"messages\": [{\"role\": \"user\", \"content\": \"Say hello.\"}],
    \"stream\": false
  }" 2>&1)

STRUCT_OK=$(echo "$RESPONSE" | python3 -c "
import sys, json
d = json.load(sys.stdin)
assert 'message' in d, 'missing message'
assert 'content' in d['message'], 'missing message.content'
assert 'role' in d['message'], 'missing message.role'
print('yes')
" 2>/dev/null || echo "no")

if [ "$STRUCT_OK" = "yes" ]; then
    echo "[OK]   Response structure matches OllamaClient expectations"
else
    echo "[FAIL] Unexpected response structure"
    echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
    exit 1
fi
echo ""

echo "=== Ollama: ALL TESTS PASSED ==="
