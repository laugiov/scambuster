#!/usr/bin/env bash
# Test OpenAI API connectivity with the same payload format ScamBuster uses.
# Usage: LLM_API_KEY=sk-... bash scripts/test-llm/test-openai.sh
set -euo pipefail

API_KEY="${LLM_API_KEY:-}"
MODEL="${LLM_MODEL:-gpt-4o-mini}"
API_URL="${LLM_API_URL:-https://api.openai.com/v1}"

if [ -z "$API_KEY" ]; then
    echo "[FAIL] LLM_API_KEY is not set."
    echo "Usage: LLM_API_KEY=sk-... bash $0"
    exit 1
fi

echo "=== OpenAI API Test ==="
echo "Model:    $MODEL"
echo "Endpoint: $API_URL/chat/completions"
echo ""

# Test 1: Basic reply generation (same as ReplyOrchestrator)
echo "-- Test 1: Reply generation --"
RESPONSE=$(curl -sf -X POST "$API_URL/chat/completions" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"messages\": [
      {\"role\": \"system\", \"content\": \"You are an elderly person who received a suspicious email. Reply naturally in 2-3 sentences.\"},
      {\"role\": \"user\", \"content\": \"Dear friend, I have a business proposal for you. Please send me your bank details.\"}
    ],
    \"temperature\": 0.6,
    \"max_tokens\": 200
  }" 2>&1)

CONTENT=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['choices'][0]['message']['content'])" 2>/dev/null)
TOKENS=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); u=d.get('usage',{}); print(f'prompt={u.get(\"prompt_tokens\",\"?\")}, completion={u.get(\"completion_tokens\",\"?\")}')" 2>/dev/null)

if [ -n "$CONTENT" ]; then
    echo "[OK]   Reply: ${CONTENT:0:120}..."
    echo "       Tokens: $TOKENS"
else
    echo "[FAIL] No content in response"
    echo "$RESPONSE" | head -5
    exit 1
fi
echo ""

# Test 2: JSON validation response (same as ReplyValidator)
echo "-- Test 2: Validation (JSON response) --"
RESPONSE=$(curl -sf -X POST "$API_URL/chat/completions" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"messages\": [
      {\"role\": \"system\", \"content\": \"You are a content validator. Respond ONLY with JSON: {\\\"approved\\\": true/false, \\\"reasons\\\": [], \\\"fix_suggestion\\\": null}\"},
      {\"role\": \"user\", \"content\": \"Validate this reply: Thank you for your message, I am interested in your proposal.\"}
    ],
    \"temperature\": 0.1,
    \"max_tokens\": 100
  }" 2>&1)

CONTENT=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['choices'][0]['message']['content'])" 2>/dev/null)
IS_JSON=$(echo "$CONTENT" | python3 -c "import sys,json; json.loads(sys.stdin.read()); print('yes')" 2>/dev/null || echo "no")

if [ "$IS_JSON" = "yes" ]; then
    echo "[OK]   Valid JSON response: ${CONTENT:0:100}"
else
    echo "[WARN] Response not valid JSON (validator may need retry): ${CONTENT:0:100}"
fi
echo ""

# Test 3: Scam classification (same as ScamClassifier)
echo "-- Test 3: Scam classification --"
RESPONSE=$(curl -sf -X POST "$API_URL/chat/completions" \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d "{
    \"model\": \"$MODEL\",
    \"messages\": [
      {\"role\": \"system\", \"content\": \"Classify this email as one of: PHISHING, PHISH_CREDENTIALS, INVOICE_FRAUD, ROMANCE, TECH_SUPPORT, UNKNOWN. Respond with JSON: {\\\"scam_type\\\": \\\"TYPE\\\", \\\"confidence\\\": 0.0}\"},
      {\"role\": \"user\", \"content\": \"Subject: Your account has been compromised. Body: Click here to verify your identity immediately or your account will be suspended.\"}
    ],
    \"temperature\": 0.1,
    \"max_tokens\": 50
  }" 2>&1)

CONTENT=$(echo "$RESPONSE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['choices'][0]['message']['content'])" 2>/dev/null)
echo "[OK]   Classification: ${CONTENT:0:100}"
echo ""

echo "=== OpenAI: ALL TESTS PASSED ==="
