# Plan 075a: Urgency Few-Shot Examples

## Phase 1: Research (0.5h)

1. Query production DB for 10 messages with clearly different urgency levels (from casual to ultimatum)
2. Anonymize excerpts — strip all PII (emails, phones, IBANs, wallets, names)
3. Assign ground-truth urgency scores using the 10-bucket scale from the prompt

## Phase 2: Prompt Engineering (1h)

1. Read current `fallbackPromptTemplate()` in `ContextualEnricher.php` (lines 191-313)
2. Read external template at `local/prompts/contextual_enrichment.txt` if it exists
3. Replace the 3 existing few-shot examples (Example 1: advance fee 0.65, Example 2: phishing 0.80, Example 3: invoice fraud 0.85) with 10 new examples spanning 0.05 to 0.95
4. Each example includes:
   - Context header (scam type, turn, engagement duration)
   - Realistic anonymized message excerpt
   - Complete JSON response with all fields (stimulus_type, scammer_urgency_score, language_switch_detected, hesitation_detected, context_excerpt, enrichment_confidence, ioc_roles)
5. Add a calibration instruction block before the examples emphasizing score diversity

## Phase 3: Tests (1h)

### Unit test
- `tests/Unit/Application/LLM/ContextualEnricherPromptTest.php`
- Instantiate `ContextualEnricher` with mock dependencies
- Call `buildPrompt()` (via reflection or by testing the prompt content indirectly)
- Assert prompt contains urgency scores: 0.05, 0.15, 0.25, 0.35, 0.45, 0.55, 0.65, 0.75, 0.85, 0.95

### E2E test
- `tests/EndToEnd/CriticalFlow/UrgencyCalibrationFlowTest.php`
- Test 1: POST `/api/v1/communication/ingest/raw` with a high-urgency message body containing deadline language → assert ioc_context.urgency_score > 0.80
- Test 2: POST `/api/v1/communication/ingest/raw` with a casual no-ask message → assert ioc_context.urgency_score < 0.40

## Phase 4: Validation (0.5h)

1. PHPStan L6+ on `src/`
2. `make test` (full suite)
3. `make endToEndTest` (E2E suite)
4. Commit atomic

## Technical Approach

The `ContextualEnricher` loads the prompt from `PROMPT_TEMPLATE_PATH` (line 22) first, falling back to `fallbackPromptTemplate()` (line 129). Both must be updated. The prompt is built via string replacement in `buildPrompt()` (lines 123-148) — the few-shot examples are static text, not templated, so they just need to be rewritten in place.

No changes to `ContextualEnrichmentResult::fromLlmResponse()` — the scoring logic (clamp [0,1], confidence cap by message count) remains unchanged.
