# Plan 075b: Multi-Label Scam Classification

## Phase 1: Schema + Domain (1h)

1. Create Doctrine migration adding `secondary_scam_types JSONB DEFAULT NULL` to `conversation`
2. Add `secondaryScamTypes` property + getter/setter to `Conversation` entity with Doctrine `#[ORM\Column(type: 'json', nullable: true)]`
3. Run migration on dev + test DBs

## Phase 2: ClassificationResult Extension (0.5h)

1. Add `secondaryTypes` parameter to `ClassificationResult::__construct()` with default `null`
2. Type: `?array` with PHPDoc `@var list<array{code: string, confidence: float}>|null`
3. Backward-compatible: existing callers pass nothing for this parameter

## Phase 3: ScamClassifier Prompt Update (1.5h)

1. Modify `buildClassificationPrompt()` in `ScamClassifier.php`:
   - Add `secondary_types` to the JSON response format in the system prompt (around line 172)
   - Add instruction: "If the scam shows characteristics of 2+ types, include up to 3 secondary_types with confidence >= 0.50. If purely one type, set secondary_types to null."
   - Add a hybrid scam few-shot example
2. Modify `classify()` method:
   - Parse `$data['secondary_types']` from LLM response
   - Validate each entry has `code` (string in known types) and `confidence` (float >= 0.50)
   - Pass to `ClassificationResult` constructor

## Phase 4: Handler + Storage (0.5h)

1. Modify `ScamClassificationHandler.php` to store secondary types:
   - After setting primary scam type on conversation
   - Call `$conversation->setSecondaryScamTypes($result->secondaryTypes)`
   - EntityManager flush persists JSONB

## Phase 5: API Response (0.5h)

1. Ensure conversation serialization includes `secondary_scam_types` in JSON response
2. Check existing serialization logic (controller or normalizer) — add field if not auto-serialized

## Phase 6: Frontend (1h)

1. Update conversation detail component to display secondary types
2. Render as smaller muted pills next to primary type badge
3. Show confidence percentage on hover/tooltip

## Phase 7: Tests (1.5h)

### Unit tests
- `ClassificationResult` with secondary types parses correctly
- `ClassificationResult` with null secondary types (backward compat)
- ScamClassifier prompt contains `secondary_types` instruction

### Integration tests
- Classify hybrid romance+invoice message → primary ROMANCE, secondary includes INVOICE_FRAUD
- Classify pure phishing → secondary_types is null

### E2E test
- Ingest romance+invoice email → GET conversation shows secondary_scam_types

## Phase 8: Validation
1. PHPStan L6+ clean
2. CS-Fixer clean
3. `make test` (4087+ tests)
4. `make endToEndTest` (305+ tests)

## Technical Approach

The `ScamClassifier` (line 56) calls `$this->llmClient->chat()` with temperature 0.3 and max_tokens 1000. The response is parsed via `JsonValidator::parseAndValidate()`. The existing prompt is in French (lines 159-220) — the secondary types instruction should follow the same language and style.

`ClassificationResult` is a `final readonly` VO — adding a constructor parameter with a default value is backward-compatible.

The `conversation.secondary_scam_types` JSONB column stores the raw array: `[{"code": "INVOICE_FRAUD", "confidence": 0.72}]`. This avoids a join table and keeps the schema simple for an advisory field.
