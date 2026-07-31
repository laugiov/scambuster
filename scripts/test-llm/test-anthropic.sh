#!/usr/bin/env bash
# Test Anthropic API connectivity with the same payload format ScamBuster uses.
# Usage: ANTHROPIC_API_KEY=sk-ant-... bash scripts/test-llm/test-anthropic.sh
set -euo pipefail

API_KEY="${ANTHROPIC_API_KEY:-}"
MODEL="${LLM_MODEL:-claude-haiku-4-5-20251001}"

if [ -z "$API_KEY" ]; then
    echo "[FAIL] ANTHROPIC_API_KEY is not set."
    echo "Usage: ANTHROPIC_API_KEY=sk-ant-... bash $0"
    exit 1
fi

echo "=== Anthropic API Test ==="
echo "Model:    $MODEL"
echo "Endpoint: https://api.anthropic.com/v1/messages"
echo ""

# Test 1: Reply generation with system message as separate param
echo "-- Test 1: Reply generation (system as separate param) --"
RESPONSE=$(curl -sf -X POST "https://api.anthropic.com/v1/messages" \
  -H "x-api-key: $API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"system\": \"You are an elderly person who received a suspicious email. Reply naturally in 2-3 sentences.\",
    \"messages\": [
      {\"role\": \"user\", \"content\": \"Dear friend, I have a business proposal for you. Please send me your bank details.\"}
    ],
    \"max_tokens\": 200,
    \"temperature\": 0.6
  }" 2>&1)

CONTENT=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['content'][0]['text'])" 2>/dev/null)
TOKENS=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); u=d.get('usage',{}); print(f'input={u.get(\"input_tokens\",\"?\")}, output={u.get(\"output_tokens\",\"?\")}')" 2>/dev/null)

if [ -n "$CONTENT" ]; then
    echo "[OK]   Reply: ${CONTENT:0:120}..."
    echo "       Tokens: $TOKENS"
else
    echo "[FAIL] No content in response"
    echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
    exit 1
fi
echo ""

# Test 2: JSON validation (same as ReplyValidator)
echo "-- Test 2: Validation (JSON response) --"
RESPONSE=$(curl -sf -X POST "https://api.anthropic.com/v1/messages" \
  -H "x-api-key: $API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"system\": \"You are a content validator. Respond ONLY with JSON: {\\\"approved\\\": true/false, \\\"reasons\\\": [], \\\"fix_suggestion\\\": null}\",
    \"messages\": [
      {\"role\": \"user\", \"content\": \"Validate this reply: Thank you for your message, I am interested in your proposal.\"}
    ],
    \"max_tokens\": 100,
    \"temperature\": 0.1
  }" 2>&1)

CONTENT=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['content'][0]['text'])" 2>/dev/null)
IS_JSON=$(echo "$CONTENT" | python3 -c "import sys,json; json.loads(sys.stdin.read()); print('yes')" 2>/dev/null || echo "no")

if [ "$IS_JSON" = "yes" ]; then
    echo "[OK]   Valid JSON response: ${CONTENT:0:100}"
else
    echo "[WARN] Response not valid JSON (validator may need retry): ${CONTENT:0:100}"
fi
echo ""

# Test 3: Verify response structure matches AnthropicClient expectations
echo "-- Test 3: Response structure check --"
RESPONSE=$(curl -sf -X POST "https://api.anthropic.com/v1/messages" \
  -H "x-api-key: $API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"messages\": [{\"role\": \"user\", \"content\": \"Say hello in one word.\"}],
    \"max_tokens\": 10,
    \"temperature\": 0.0
  }" 2>&1)

HAS_CONTENT=$(echo "$RESPONSE" | python3 -c "
import sys, json
d = json.load(sys.stdin)
assert 'content' in d, 'missing content'
assert len(d['content']) > 0, 'empty content array'
assert 'text' in d['content'][0], 'missing text field'
assert 'usage' in d, 'missing usage'
assert 'input_tokens' in d['usage'], 'missing input_tokens'
assert 'output_tokens' in d['usage'], 'missing output_tokens'
print('yes')
" 2>/dev/null || echo "no")

if [ "$HAS_CONTENT" = "yes" ]; then
    echo "[OK]   Response structure matches AnthropicClient expectations"
else
    echo "[FAIL] Unexpected response structure"
    echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
    exit 1
fi
echo ""

echo "=== Anthropic: ALL TESTS PASSED ==="
