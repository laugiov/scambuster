# Plan 075e: Confidence by Context Richness

## Phase 1: Tests (1.5h)

### Unit tests
**File**: `tests/Unit/Application/LLM/ContextualEnrichmentResultRichnessTest.php`

1. Test `test_one_message_long_with_many_iocs_and_urgency_cap_080`:
   - availableMessages=1, revelationText=500 chars with "deadline", iocTypes=5 items
   - LLM returns confidence 0.90
   - Assert capped to 0.80 (base 0.50 + 0.10 length + 0.10 iocs + 0.10 urgency)

2. Test `test_one_message_short_single_ioc_cap_050`:
   - availableMessages=1, revelationText=50 chars, iocTypes=1 item
   - LLM returns confidence 0.70
   - Assert capped to 0.50 (base 0.50, no bonuses)

3. Test `test_three_messages_rich_content_cap_100`:
   - availableMessages=3, revelationText=300 chars with "legal action", iocTypes=4 items
   - LLM returns confidence 0.95
   - Assert capped to min(1.0, 0.90 + 0.10 + 0.10 + 0.10) = 1.0

4. Test `test_two_messages_moderate_content`:
   - availableMessages=2, revelationText=250 chars no urgency, iocTypes=2 items
   - LLM returns confidence 0.85
   - Assert capped to 0.80 (base 0.70 + 0.10 length, no ioc bonus, no urgency)

5. Test `test_backward_compat_no_revelation_text`:
   - Call `fromLlmResponse()` without revelationText parameter
   - Assert no error, uses base cap only (same as old behavior)

6. Test `test_urgency_patterns_detected`:
   - Messages with "account suspended", "final warning", "within 24 hours" → bonus applied
   - Message with "hello, please check" → no urgency bonus

## Phase 2: Implementation (2h)

### 2a. Update `ContextualEnrichmentResult::fromLlmResponse()`
**File**: `src/Application/LLM/ContextualEnrichmentResult.php`

1. Add `URGENCY_PATTERNS` constant (4 regex patterns)
2. Add parameter `string $revelationMessageText = ''` to `fromLlmResponse()`
3. Replace lines 97-106 (message-count cap) with richness-based algorithm:
   - Base cap by message count (0.50 / 0.70 / 0.90)
   - +0.10 if revelation text length > 200 chars
   - +0.10 if IOC types count > 3
   - +0.10 if any URGENCY_PATTERNS match revelation text
   - Final cap = min(1.0, baseCap + bonuses)
4. Add private static method `containsUrgencyPatterns(string $text): bool`

### 2b. Update caller in ContextualEnricher
**File**: `src/Application/LLM/ContextualEnricher.php`

1. Pass `$request->revelationMessageText` to `fromLlmResponse()` at line 85

## Phase 3: Validation (0.5h)

1. PHPStan L6+ clean
2. CS-Fixer clean
3. `make test` (4087+ tests)
4. `make endToEndTest` (305+ tests)
5. Atomic commit

## Technical Approach

The `fromLlmResponse()` static factory method is called from `ContextualEnricher::enrich()` at line 85. The revelation message text is available in the `ContextualEnrichmentRequest::$revelationMessageText` property. Adding it as a parameter to `fromLlmResponse()` with a default empty string ensures backward compatibility — any other callers (tests, etc.) that do not pass it will get the base cap only.

The urgency patterns are intentionally simple regex (no LLM call). They catch explicit deadline/threat language that strongly correlates with higher context richness. False positives are acceptable since this only raises the cap — the LLM can still return a low confidence.
