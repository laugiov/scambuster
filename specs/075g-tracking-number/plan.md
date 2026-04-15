# Plan 075g: Tracking Number IOC Type

## Phase 1: Tests (1h)

### Unit tests
**File**: `tests/Unit/Application/Communication/TrackingNumberIocTest.php`

1. Test `test_regex_matches_valid_tracking_numbers`:
   - DHL-5336154-US → match
   - UPS-12345678 → match
   - FedEx-9876543210 → match
   - USPS 1234567890 → match
   - TNT123456789 → match
   - EMS-123456789-CN → match

2. Test `test_regex_rejects_invalid_tracking_numbers`:
   - DHL123 → no match (too few digits)
   - Random-1234567 → no match (unknown carrier)
   - 12345678 → no match (no carrier prefix)
   - DHL → no match (no number)

3. Test `test_ioc_validator_supports_tracking_number`:
   - `$validator->validate('tracking_number', 'DHL-5336154-US')` → true
   - `$validator->isSupportedType('tracking_number')` → true
   - `$validator->validate('tracking_number', '')` → false

4. Test `test_extractor_extracts_tracking_from_text`:
   - Input: "Your parcel is tracked: DHL-5336154-US. Contact us for details."
   - `extractIocsWithRegex()` returns array with tracking_number IOC

### E2E test
**File**: `tests/EndToEnd/CriticalFlow/TrackingNumberExtractionFlowTest.php`

1. Test `test_ingest_email_with_tracking_number`: POST ingest with body containing DHL tracking → GET message IOCs → assert tracking_number present

## Phase 2: Implementation (1.5h)

### 2a. IocValidator
**File**: `src/Application/Communication/IocValidator.php`
- Add `'tracking_number'` pattern to `IOC_PATTERNS` constant (line 76)

### 2b. IocExtractorOrchestrator
**File**: `src/Application/Communication/IocExtractorOrchestrator.php`
- Add `'tracking_number'` pattern to `$patterns` array in `extractIocsWithRegex()` (line 221)

### 2c. IocExportMapper
**File**: `src/Application/Communication/IocExportMapper.php`
- Add STIX mapping for tracking_number type
- Map to STIX artifact or custom extension

### 2d. ContextualEnrichmentResult — VALID_ROLES
**File**: `src/Application/LLM/ContextualEnrichmentResult.php`
- Add `'TRACKING_REFERENCE'` to `VALID_ROLES` array

### 2e. ContextualEnricher — prompt
**File**: `src/Application/LLM/ContextualEnricher.php`
- Add tracking_number → TRACKING_REFERENCE to IOC Type to Role Constraints
- Update external prompt template if it exists

## Phase 3: Validation (0.5h)

1. PHPStan L6+ clean
2. CS-Fixer clean
3. `make test` (4087+ tests)
4. `make endToEndTest` (305+ tests)
5. Atomic commit

## Technical Approach

The IOC extraction pipeline flows: `IocExtractorOrchestrator::extractIocsWithRegex()` extracts raw matches → `IocValidator::validate()` validates format → `IocNormalizer::normalize()` normalizes value → `IocUpsertService` persists. Adding a new type requires entries in both the extractor patterns and the validator patterns.

The regex `/\b(?:DHL|UPS|FedEx|USPS|TNT|EMS)[-\s]?\d{6,15}[-\s]?[A-Z]{0,2}\b/i` requires:
1. Known carrier prefix (6 major carriers)
2. Optional separator (dash or space)
3. 6-15 digit number (covers all major carrier formats)
4. Optional 0-2 letter country suffix
5. Case-insensitive, word boundary anchored

The `IocNormalizer` does not need a special case for tracking_number — uppercase normalization via default `strtoupper()` is appropriate.
