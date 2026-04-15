# Tasks 075a: Urgency Few-Shot Examples

## Task 1: Write unit test for prompt content
**File**: `tests/Unit/Application/LLM/ContextualEnricherPromptTest.php`
- [ ] Create test class
- [ ] Test that fallback prompt contains 10 distinct urgency score values (0.05, 0.15, 0.25, 0.35, 0.45, 0.55, 0.65, 0.75, 0.85, 0.95)
- [ ] Test that each example has a complete JSON block with all required fields
- [ ] Run: `make test` — new test fails (TDD red)

## Task 2: Write E2E test for urgency calibration
**File**: `tests/EndToEnd/CriticalFlow/UrgencyCalibrationFlowTest.php`
- [ ] Test `test_high_urgency_message_scores_above_080`: Ingest message with "24 hours or your account will be permanently closed" → assert urgency_score > 0.80
- [ ] Test `test_casual_message_scores_below_040`: Ingest message with "Just saying hello, no rush on anything" → assert urgency_score < 0.40
- [ ] Run: `make endToEndTest` — new tests fail (TDD red)

## Task 3: Add 10 few-shot examples to fallback prompt
**File**: `src/Application/LLM/ContextualEnricher.php`
- [ ] Replace 3 existing examples (lines 274-292) with 10 new examples covering urgency 0.05 to 0.95
- [ ] Add calibration instruction: "CRITICAL: Do NOT default to 0.75. The examples below show the FULL range. Match the closest example."
- [ ] Each example: context header + message excerpt + full JSON response
- [ ] Ensure no PII in any example

## Task 4: Update external prompt template (if exists)
**File**: `local/prompts/contextual_enrichment.txt`
- [ ] Check if file exists
- [ ] If yes: mirror the same 10 few-shot examples
- [ ] If no: skip (fallback template in PHP is the primary)

## Task 5: Integration process
- [ ] PHPStan L6+: `make stan` clean on `src/`
- [ ] CS-Fixer: `make cs-fixer` clean
- [ ] `make test` — all tests pass including new unit test (4087+ tests)
- [ ] `make endToEndTest` — all tests pass including new E2E test (305+ tests)
- [ ] Atomic commit: `feat(075a): add 10 urgency few-shot examples to ContextualEnricher prompt`
