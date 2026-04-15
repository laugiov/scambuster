# Tasks 075e: Confidence by Context Richness

## Task 1: Write unit tests (TDD red)
**File**: `tests/Unit/Application/LLM/ContextualEnrichmentResultRichnessTest.php`
- [ ] Test `test_one_message_long_with_many_iocs_and_urgency_cap_080`
- [ ] Test `test_one_message_short_single_ioc_cap_050`
- [ ] Test `test_three_messages_rich_content_cap_100`
- [ ] Test `test_two_messages_moderate_content`
- [ ] Test `test_backward_compat_no_revelation_text`
- [ ] Test `test_urgency_patterns_detected` (account suspended, final warning, within 24 hours)
- [ ] Run: `make test` — new tests fail

## Task 2: Add URGENCY_PATTERNS constant
**File**: `src/Application/LLM/ContextualEnrichmentResult.php`
- [ ] Add `private const URGENCY_PATTERNS` with 4 regex patterns (deadline/expire, legal action, account suspended/frozen, final notice/warning)
- [ ] Add private static method `containsUrgencyPatterns(string $text): bool` — returns true if any pattern matches

## Task 3: Replace message-count cap with richness cap
**File**: `src/Application/LLM/ContextualEnrichmentResult.php`
- [ ] Add parameter `string $revelationMessageText = ''` to `fromLlmResponse()` (backward compat default)
- [ ] Replace lines 97-106 with richness-based algorithm:
  - Base cap: 1 msg → 0.50, 2 msg → 0.70, 3 msg → 0.90
  - +0.10 if strlen(revelationMessageText) > 200
  - +0.10 if count(iocTypes) > 3
  - +0.10 if containsUrgencyPatterns(revelationMessageText)
  - maxConfidence = min(1.0, baseCap + bonuses)
- [ ] Apply: `$enrichmentConfidence = min($enrichmentConfidence, $maxConfidence)`

## Task 4: Update ContextualEnricher caller
**File**: `src/Application/LLM/ContextualEnricher.php`
- [ ] At line 85, pass `$request->revelationMessageText` as the new parameter to `fromLlmResponse()`

## Task 5: Integration process
- [ ] PHPStan L6+: `make stan` clean on `src/`
- [ ] CS-Fixer: `make cs-fixer` clean
- [ ] `make test` — all tests pass including new unit tests (4087+ tests)
- [ ] `make endToEndTest` — all tests pass (305+ tests)
- [ ] Atomic commit: `feat(075e): replace message-count confidence cap with richness-aware algorithm`
