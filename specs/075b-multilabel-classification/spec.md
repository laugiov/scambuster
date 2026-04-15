# Spec 075b: Multi-Label Scam Classification

**Created**: 2026-04-12
**Status**: Draft
**Type**: Backend (Domain + LLM) + Frontend
**Parent**: 075 — Data Quality Improvement
**Effort**: 1 day
**Branch**: `roadmap/075-data-quality-improvement`

---

## Context

LLM auditor found 30% of classification errors occur on hybrid scams (e.g., romance + invoice fraud, phishing + tech support). The current `Conversation` entity has a single `scam_type_id` FK to `lkp_scam_type`, which forces a single classification. The `ScamClassifier` prompt (in `ScamClassifier.php`) returns only `scam_type_code` and `confidence` — no secondary types.

The `ClassificationResult` value object (`src/Application/Communication/ClassificationResult.php`) has no field for secondary types.

---

## Goal

Support multi-label scam classification while preserving backward compatibility. Primary `scam_type_id` FK stays unchanged. A new `secondary_scam_types` JSONB column stores advisory secondary classifications with confidence scores.

**Target**: Classification agreement rate from 70% to 90%+.

---

## Non-Goals

- Changing the primary scam_type FK (backward compat must be preserved)
- Multi-label persona assignment (personas stay tied to primary type)
- Retroactive reclassification of existing conversations (future batch command)

---

## Changes

### 1. Database migration

Add `secondary_scam_types` JSONB column to `conversation` table:

```sql
ALTER TABLE conversation
    ADD COLUMN secondary_scam_types JSONB DEFAULT NULL;

COMMENT ON COLUMN conversation.secondary_scam_types
    IS 'Advisory secondary scam classifications: [{code, confidence}]';
```

### 2. `src/Application/Communication/ClassificationResult.php`

Add `secondaryTypes` property:

```php
/** @var list<array{code: string, confidence: float}>|null */
public ?array $secondaryTypes = null,
```

### 3. `src/Application/LLM/ScamClassifier.php` — `buildClassificationPrompt()`

Modify the prompt to request secondary types:

- Add to JSON response format: `"secondary_types": [{"code": "romance", "confidence": 0.65}]`
- Add instruction: "If the scam shows characteristics of multiple types, return up to 3 secondary types with confidence >= 0.50"
- Add few-shot example of a hybrid scam (romance + advance fee)

Parse `secondary_types` from LLM response in `classify()` method, populate `ClassificationResult::secondaryTypes`.

### 4. `src/Application/Communication/ScamClassificationHandler.php`

After storing primary type, also store secondary types in `conversation.secondary_scam_types` JSONB column.

### 5. `src/Domain/Communication/Conversation.php`

Add getter/setter for `secondaryScamTypes`:

```php
private ?array $secondaryScamTypes = null;

public function getSecondaryScamTypes(): ?array { ... }
public function setSecondaryScamTypes(?array $types): void { ... }
```

### 6. Frontend: Conversation detail

Display secondary types as smaller pills next to the primary type badge. Use muted color variant to visually distinguish primary from secondary.

---

## Acceptance Criteria

1. **Migration**: `secondary_scam_types` JSONB column exists on `conversation`
2. **Unit test**: `ClassificationResult` parses `secondaryTypes` from LLM JSON
3. **Integration test**: Classify a hybrid romance+invoice message → primary = ROMANCE, secondary contains INVOICE_FRAUD with confidence >= 0.50
4. **E2E test**: Ingest a romance+invoice email → GET `/api/v1/conversation/{id}` response includes `secondary_scam_types` array
5. Primary `scam_type_id` FK unchanged for all existing conversations
6. PHPStan L6+ clean on `src/`
7. `make test` passes (4087+ tests)
8. `make endToEndTest` passes (305+ tests)
