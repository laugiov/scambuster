# Tasks 075g: Tracking Number IOC Type

## Task 1: Write unit tests (TDD red)
**File**: `tests/Unit/Application/Communication/TrackingNumberIocTest.php`
- [ ] Test `test_regex_matches_valid_tracking_numbers`: DHL-5336154-US, UPS-12345678, FedEx-9876543210, USPS 1234567890, TNT123456789, EMS-123456789-CN
- [ ] Test `test_regex_rejects_invalid_tracking_numbers`: DHL123 (short), Random-1234567 (unknown carrier), bare 12345678, DHL alone
- [ ] Test `test_ioc_validator_supports_tracking_number`: validate returns true, isSupportedType returns true
- [ ] Test `test_extractor_extracts_tracking_from_text`: extractIocsWithRegex returns tracking_number IOC
- [ ] Run: `make test` — new tests fail

## Task 2: Write E2E test (TDD red)
**File**: `tests/EndToEnd/CriticalFlow/TrackingNumberExtractionFlowTest.php`
- [ ] Test `test_ingest_email_with_tracking_number`: POST ingest with DHL tracking in body → assert tracking_number IOC extracted
- [ ] Run: `make endToEndTest` — new test fails

## Task 3: Add tracking_number to IocValidator
**File**: `src/Application/Communication/IocValidator.php`
- [ ] Add to `IOC_PATTERNS` constant at line 76: `'tracking_number' => '/\b(?:DHL|UPS|FedEx|USPS|TNT|EMS)[-\s]?\d{6,15}[-\s]?[A-Z]{0,2}\b/i'`

## Task 4: Add tracking_number to IocExtractorOrchestrator
**File**: `src/Application/Communication/IocExtractorOrchestrator.php`
- [ ] Add to `$patterns` array in `extractIocsWithRegex()` at line 221: `'tracking_number' => '/\b(?:DHL|UPS|FedEx|USPS|TNT|EMS)[-\s]?\d{6,15}[-\s]?[A-Z]{0,2}\b/i'`

## Task 5: Add STIX mapping for tracking_number
**File**: `src/Application/Communication/IocExportMapper.php`
- [ ] Add tracking_number → STIX artifact or custom x_scambuster extension mapping
- [ ] Add MISP type mapping: 'other'

## Task 6: Add TRACKING_REFERENCE to valid roles
**File**: `src/Application/LLM/ContextualEnrichmentResult.php`
- [ ] Add `'TRACKING_REFERENCE'` to `VALID_ROLES` constant array

## Task 7: Update ContextualEnricher prompt
**File**: `src/Application/LLM/ContextualEnricher.php`
- [ ] Add to IOC Type to Role Constraints in fallbackPromptTemplate(): `- tracking_number → always TRACKING_REFERENCE (fake shipping/customs tracking number)`
- [ ] Update external prompt template (`local/prompts/contextual_enrichment.txt`) if it exists

## Task 8: Integration process
- [ ] PHPStan L6+: `make stan` clean on `src/`
- [ ] CS-Fixer: `make cs-fixer` clean
- [ ] `make test` — all tests pass including new tests (4087+ tests)
- [ ] `make endToEndTest` — all tests pass including new E2E test (305+ tests)
- [ ] Atomic commit: `feat(075g): add tracking_number IOC type with regex extraction and STIX mapping`
