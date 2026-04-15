# Spec 075c: Re-Enrichment Semantic Roles (Batch Fix)

**Created**: 2026-04-12
**Status**: Draft
**Type**: Backend (Console Command)
**Parent**: 075 — Data Quality Improvement
**Effort**: 0.5 day
**Branch**: `roadmap/075-data-quality-improvement`

---

## Context

LLM auditor found SHA256 hashes labeled `MALWARE_DOWNLOAD_URL` when they should be `IDENTITY_DOCUMENT` (document signatures in email footers). The prompt was already fixed (F5 — see `ContextualEnricher.php` lines 266-270: SHA256 in footer/signature → `IDENTITY_DOCUMENT`), but existing `ioc_context` rows still have the wrong `semantic_role` value.

The valid roles are defined in `ContextualEnrichmentResult::VALID_ROLES` (lines 16-27): `PAYMENT_DESTINATION`, `PAYMENT_REDIRECT_URL`, `PHISHING_CREDENTIAL_URL`, `MALWARE_DOWNLOAD_URL`, `CONTACT_CHANNEL`, `IDENTITY_DOCUMENT`, `VERIFICATION_CODE_URL`, `INFRASTRUCTURE_DOMAIN`, `MONEY_MULE_ACCOUNT`, `UNKNOWN`.

---

## Goal

Create a batch command `app:fix:semantic-roles` that corrects misclassified SHA256 IOC roles in existing `ioc_context` data using a footer-position heuristic.

**Target**: Semantic role agreement from current baseline to 80%+.

---

## Non-Goals

- Re-running the full LLM enrichment pipeline (too expensive, ~$2-3 for all IOCs)
- Fixing URL roles (those require LLM analysis of URL path structure)
- Modifying the prompt (already fixed in F5)

---

## Changes

### 1. Console command: `app:fix:semantic-roles`

**File**: `src/UI/Console/FixSemanticRolesCommand.php`

Logic:
1. Query all `ioc_context` rows where:
   - `semantic_role = 'MALWARE_DOWNLOAD_URL'`
   - Joined indicator has `type = 'sha256'`
2. For each row, load the associated message body via `observed_ioc.msg_id → message.body_text`
3. Apply footer heuristic: if the SHA256 value appears in the last 20% of the message body text, it is a footer hash → update role to `IDENTITY_DOCUMENT`
4. Support `--dry-run` flag (report only, no updates)
5. Output CSV-style report: `obs_id, indicator_value, old_role, new_role, position_pct`
6. Transactional batch updates (flush every 100 rows)

### 2. No schema changes

The `ioc_context.semantic_role` column already exists as VARCHAR. No migration needed.

---

## Acceptance Criteria

1. **Integration test**: Command changes SHA256 footer roles from `MALWARE_DOWNLOAD_URL` to `IDENTITY_DOCUMENT`
2. **Integration test**: Command does NOT change SHA256 roles when hash appears in message body (not footer)
3. **Integration test**: `--dry-run` mode reports changes but does not persist them
4. **E2E test**: After running command, GET IOC context shows `IDENTITY_DOCUMENT` role for footer SHA256
5. PHPStan L6+ clean on `src/`
6. `make test` passes (4087+ tests)
7. `make endToEndTest` passes (305+ tests)
