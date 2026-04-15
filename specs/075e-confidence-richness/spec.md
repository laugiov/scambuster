# Spec 075e: Confidence by Context Richness

**Created**: 2026-04-12
**Status**: Draft
**Type**: Backend (LLM Result Processing)
**Parent**: 075 — Data Quality Improvement
**Effort**: 1 day
**Branch**: `roadmap/075-data-quality-improvement`

---

## Context

LLM auditor found 43% of `ioc_context.enrichment_confidence` values sit at exactly 0.55. The current confidence capping in `ContextualEnrichmentResult::fromLlmResponse()` (lines 97-106) uses only the `availableMessages` count (1-3 messages in the window):

```php
$maxConfidence = match (true) {
    $availableMessages <= 1 => 0.60,
    $availableMessages === 2 => 0.80,
    default => 1.0,
};
```

This is too crude: a 1-message window with a 500-word detailed email containing 5 IOCs and explicit threats is capped at 0.60, identical to a 1-message generic spam blast with 1 URL. The LLM returns 0.55 for most 1-message cases because it knows the cap is near 0.60.

---

## Goal

Replace the message-count-only capping with a richness-aware confidence cap that considers message content quality. A 1-message window with rich content should be able to reach 0.80.

**Target**: Confidence distribution produces useful signal instead of 43% at 0.55 constant.

---

## Non-Goals

- Changing the LLM prompt (confidence calibration instructions stay the same)
- Re-enriching existing data (existing values are already stored)
- Modifying the urgency score or other enrichment fields

---

## Changes

### 1. `src/Application/LLM/ContextualEnrichmentResult.php` — `fromLlmResponse()`

Replace the current message-count cap (lines 97-106) with a richness-based cap:

**New algorithm**:

```
baseCap = match(availableMessages) {
    <= 1 => 0.50,    // lowered from 0.60 — base is lower, but bonuses lift it
    2    => 0.70,    // lowered from 0.80
    3+   => 0.90,    // lowered from 1.00
};

bonuses = 0.0;

// Message length bonus (revelation message)
if (strlen(revelationMessageText) > 200) bonuses += 0.10;

// IOC count bonus
if (count(iocTypes) > 3) bonuses += 0.10;

// Explicit urgency patterns bonus (deadline, threat, legal language)
if (containsUrgencyPatterns(revelationMessageText)) bonuses += 0.10;

maxConfidence = min(1.0, baseCap + bonuses);
enrichmentConfidence = min(enrichmentConfidence, maxConfidence);
```

This means:
- 1-message, short, 1 IOC → cap 0.50 (was 0.60 — lower for thin context)
- 1-message, long, 5 IOCs, threats → cap 0.80 (was 0.60 — much better)
- 3-message, full window → cap 0.90 to 1.00 depending on richness

### 2. `fromLlmResponse()` signature change

Add `revelationMessageText` parameter to `fromLlmResponse()` for pattern matching. Update all callers.

### 3. Urgency pattern constants

Add a constant `URGENCY_PATTERNS` regex list to `ContextualEnrichmentResult`:

```php
private const URGENCY_PATTERNS = [
    '/\b(?:deadline|expire|urgent|immediately|within\s+\d+\s+hours?)\b/i',
    '/\b(?:legal\s+action|prosecution|court|lawsuit|police)\b/i',
    '/\b(?:account\s+(?:suspended|closed|frozen|restricted))\b/i',
    '/\b(?:final\s+(?:notice|warning|reminder))\b/i',
];
```

---

## Acceptance Criteria

1. **Unit test**: Long message (500 chars) with 5 IOCs and deadline language → confidence cap >= 0.80
2. **Unit test**: Short message (50 chars) with 1 IOC, no urgency → confidence cap <= 0.50
3. **Unit test**: 3-message window, rich content → confidence cap >= 0.90
4. **Unit test**: Backward compat — existing callers still work (default revelation text parameter)
5. PHPStan L6+ clean on `src/`
6. `make test` passes (4087+ tests)
7. `make endToEndTest` passes (305+ tests)
