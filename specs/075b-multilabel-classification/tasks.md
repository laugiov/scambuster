# Tasks 075b: Multi-Label Scam Classification

## Task 1: Write unit tests (TDD red)
**Files**: `tests/Unit/Application/Communication/ClassificationResultSecondaryTest.php`
- [ ] Test `test_classification_result_with_secondary_types`: construct with secondaryTypes array → assert getter returns it
- [ ] Test `test_classification_result_without_secondary_types`: construct with null → assert backward compat
- [ ] Test `test_classification_result_filters_low_confidence_secondary`: secondary with confidence < 0.50 → filtered out
- [ ] Run: `make test` — new tests fail

## Task 2: Write integration test (TDD red)
**File**: `tests/Integration/Application/Communication/MultiLabelClassificationTest.php`
- [ ] Test `test_hybrid_romance_invoice_returns_secondary_type`: provide romance+invoice messages → classify → primary ROMANCE, secondary includes INVOICE_FRAUD
- [ ] Test `test_pure_phishing_returns_no_secondary`: provide pure phishing → secondary is null
- [ ] Run: `make test` — new tests fail

## Task 3: Write E2E test (TDD red)
**File**: `tests/EndToEnd/CriticalFlow/MultiLabelClassificationFlowTest.php`
- [ ] Test `test_ingest_hybrid_scam_returns_secondary_types`: POST ingest romance+invoice email → GET conversation → response contains `secondary_scam_types` array with at least 1 entry
- [ ] Run: `make endToEndTest` — new test fails

## Task 4: Create database migration
**File**: `migrations/Version2026041200000001.php`
- [ ] Add `secondary_scam_types JSONB DEFAULT NULL` to `conversation` table
- [ ] Add column comment
- [ ] `down()`: DROP COLUMN
- [ ] Run migration on dev + test DBs

## Task 5: Update Conversation entity
**File**: `src/Domain/Communication/Conversation.php`
- [ ] Add `#[ORM\Column(type: 'json', nullable: true)]` property `$secondaryScamTypes`
- [ ] Add getter `getSecondaryScamTypes(): ?array`
- [ ] Add setter `setSecondaryScamTypes(?array $types): void`

## Task 6: Extend ClassificationResult
**File**: `src/Application/Communication/ClassificationResult.php`
- [ ] Add constructor parameter: `public ?array $secondaryTypes = null`
- [ ] Add PHPDoc: `@var list<array{code: string, confidence: float}>|null`

## Task 7: Update ScamClassifier prompt and parsing
**File**: `src/Application/LLM/ScamClassifier.php`
- [ ] Add `secondary_types` to JSON response format in system prompt
- [ ] Add instruction for multi-label classification
- [ ] Add hybrid scam few-shot example
- [ ] Parse `$data['secondary_types']` in `classify()` method
- [ ] Validate entries: string code + float confidence >= 0.50
- [ ] Pass `secondaryTypes` to `ClassificationResult` constructor

## Task 8: Store secondary types in handler
**File**: `src/Application/Communication/ScamClassificationHandler.php`
- [ ] After setting primary scam type, call `$conversation->setSecondaryScamTypes($result->secondaryTypes)`

## Task 9: Update API serialization
- [ ] Ensure conversation GET endpoint returns `secondary_scam_types` in JSON
- [ ] Check existing serialization (controller/normalizer) and add field if needed

## Task 10: Frontend secondary type pills
**File**: `frontend-react/src/components/ConversationDetail.tsx` (or equivalent)
- [ ] Display secondary types as smaller muted pills next to primary badge
- [ ] Show confidence on hover

## Task 11: Integration process
- [ ] PHPStan L6+: `make stan` clean on `src/`
- [ ] CS-Fixer: `make cs-fixer` clean
- [ ] `make test` — all tests pass (4087+ tests)
- [ ] `make endToEndTest` — all tests pass (305+ tests)
- [ ] Atomic commit: `feat(075b): add multi-label scam classification with secondary_scam_types JSONB`
