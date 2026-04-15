# Spec 075g: Tracking Number IOC Type

**Created**: 2026-04-12
**Status**: Draft
**Type**: Backend (IOC Extraction)
**Parent**: 075 — Data Quality Improvement
**Effort**: 0.5 day
**Branch**: `roadmap/075-data-quality-improvement`

---

## Context

LLM auditor identified that scam emails frequently contain shipping tracking numbers (e.g., `DHL-5336154-US`, `UPS-12345678`, `FedEx-9876543210`) that are currently not extracted. These are IOCs that link multiple conversations to the same fake shipping scam operation. No `tracking_number` type exists in `IocValidator::IOC_PATTERNS` (lines 25-77) or `IocExtractorOrchestrator::extractIocsWithRegex()` patterns (lines 201-221).

---

## Goal

Add `tracking_number` as a new IOC type with regex extraction, validation, and STIX mapping. Enable extraction of carrier tracking numbers from scam emails.

**Target**: Tracking numbers extracted from scam emails containing DHL/UPS/FedEx/USPS/TNT/EMS references.

---

## Non-Goals

- Verifying tracking numbers against carrier APIs (no external calls)
- Adding tracking numbers to clustering anchor IOCs (future spec)
- Supporting non-standard carrier formats (e.g., local postal services)

---

## Changes

### 1. `src/Application/Communication/IocValidator.php` — `IOC_PATTERNS`

Add at line 76 (before `bank_account`):

```php
'tracking_number' => '/\b(?:DHL|UPS|FedEx|USPS|TNT|EMS)[-\s]?\d{6,15}[-\s]?[A-Z]{0,2}\b/i',
```

### 2. `src/Application/Communication/IocExtractorOrchestrator.php` — `extractIocsWithRegex()`

Add to the `$patterns` array at line 221 (before the closing of patterns):

```php
'tracking_number' => '/\b(?:DHL|UPS|FedEx|USPS|TNT|EMS)[-\s]?\d{6,15}[-\s]?[A-Z]{0,2}\b/i',
```

### 3. `src/Application/Communication/IocExportMapper.php`

Add STIX mapping for `tracking_number`:
- STIX indicator pattern: `[artifact:payload_bin = '<value>']` or custom `x_scambuster_tracking_number`
- MISP type mapping: `other` (no standard MISP type for tracking numbers)

### 4. `src/Application/LLM/ContextualEnrichmentResult.php` — `VALID_ROLES`

Add `TRACKING_REFERENCE` to the valid roles list (line 16-27) as a new semantic role for tracking numbers.

### 5. `src/Application/LLM/ContextualEnricher.php` — fallback prompt

Add tracking_number to the IOC Type to Role Constraints section:
```
- tracking_number → always TRACKING_REFERENCE (fake shipping/customs tracking number)
```

---

## Regex Design

Pattern: `/\b(?:DHL|UPS|FedEx|USPS|TNT|EMS)[-\s]?\d{6,15}[-\s]?[A-Z]{0,2}\b/i`

Matches:
- `DHL-5336154-US` (DHL with country suffix)
- `UPS-12345678` (UPS standard)
- `FedEx-9876543210` (FedEx long)
- `USPS 1234567890` (USPS with space)
- `TNT123456789` (TNT no separator)
- `EMS-123456789-CN` (EMS with country)

Does NOT match:
- `DHL123` (too short — min 6 digits)
- `Random-1234567` (unknown carrier)
- `12345678` (no carrier prefix)

---

## Acceptance Criteria

1. **Unit test**: Regex matches `DHL-5336154-US`, `UPS-12345678`, `FedEx-9876543210`, `USPS 1234567890`, `TNT123456789`, `EMS-123456789-CN`
2. **Unit test**: Regex does NOT match `DHL123` (too short), `Random-1234567` (unknown carrier), bare numbers
3. **Unit test**: `IocValidator::validate('tracking_number', 'DHL-5336154-US')` returns true
4. **Unit test**: `IocValidator::isSupportedType('tracking_number')` returns true
5. **E2E test**: Ingest email containing "Your DHL tracking: DHL-5336154-US" → `tracking_number` IOC extracted in observed_ioc
6. PHPStan L6+ clean on `src/`
7. `make test` passes (4087+ tests)
8. `make endToEndTest` passes (305+ tests)
