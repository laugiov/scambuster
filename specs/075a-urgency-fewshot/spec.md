# Spec 075a: Urgency Few-Shot Examples

**Created**: 2026-04-12
**Status**: Draft
**Type**: Backend (LLM Prompt Engineering)
**Parent**: 075 — Data Quality Improvement
**Effort**: 0.5 day
**Branch**: `roadmap/075-data-quality-improvement`

---

## Context

LLM auditor found that 60.8% of urgency scores cluster at exactly 0.75 despite the prompt specifying a 10-bucket calibration scale (0.00-1.00). Agreement rate between LLM auditor and production values is only 40%. The current prompt (in `ContextualEnricher.php` fallback template, lines 226-239) describes the scale verbally but provides only 3 few-shot examples (lines 276-292), none of which demonstrate low-urgency scenarios. The LLM defaults to 0.75 when uncertain.

The external prompt template at `local/prompts/contextual_enrichment.txt` (loaded at line 22 via `PROMPT_TEMPLATE_PATH`) may also need the same examples if it exists.

---

## Goal

Add 10 concrete few-shot examples to the ContextualEnricher prompt, covering the full urgency range from 0.05 to 0.95. Each example includes a real-style message excerpt and the correct urgency score, forcing the LLM to differentiate rather than defaulting to 0.75.

**Target**: Urgency agreement rate from 40% to 80%+. Urgency score distribution no longer peaked at 0.75.

---

## Non-Goals

- Changing the urgency scoring formula or post-processing logic
- Modifying the `ContextualEnrichmentResult::fromLlmResponse()` clamping (lines 76-80)
- Re-enriching existing data (that is spec 075c/075d scope)

---

## Changes

### 1. `src/Application/LLM/ContextualEnricher.php` — `fallbackPromptTemplate()`

Replace the existing 3 few-shot examples (lines 274-292) with 10 examples covering the full urgency spectrum:

| # | Urgency | Scenario | Why this score |
|---|---------|----------|----------------|
| 1 | 0.05 | Casual chitchat, no ask | Zero pressure |
| 2 | 0.15 | Gentle "when you can" | Soft suggestion |
| 3 | 0.25 | Polite request, soft timeline | "When you get a chance this week" |
| 4 | 0.35 | Clear request with reason | "Please respond this week, we need to proceed" |
| 5 | 0.45 | Firm ask, moderate deadline | "By next Friday at the latest" |
| 6 | 0.55 | Strong push, consequences implied | "To avoid further delays in your shipment" |
| 7 | 0.65 | Explicit deadline, mild threat | "Your account will be reviewed if not resolved by Monday" |
| 8 | 0.75 | Hard deadline, explicit threat | "24 hours to confirm or your funds will be frozen" |
| 9 | 0.85 | Extreme pressure, imminent loss | "URGENT: Transfer now or inheritance forfeited permanently" |
| 10 | 0.95 | Direct threat / ultimatum | "Pay immediately or face legal prosecution — final warning" |

Each example must include: scam type context, a realistic message excerpt (anonymized), and the full JSON response with all fields.

### 2. `local/prompts/contextual_enrichment.txt` (if exists)

Mirror the same 10 few-shot examples. The fallback template is only used when this file is missing.

---

## Acceptance Criteria

1. **Unit test**: Prompt string contains all 10 urgency calibration examples (check for 10 distinct `scammer_urgency_score` values)
2. **E2E test** (`tests/EndToEnd/CriticalFlow/UrgencyCalibrationFlowTest.php`):
   - Ingest a message with explicit deadline language ("24 hours or account closure") → urgency > 0.80
   - Ingest a casual message with no ask ("Just wanted to say hello, hope you are well") → urgency < 0.40
3. PHPStan L6+ clean on `src/`
4. `make test` passes (4087+ tests)
5. `make endToEndTest` passes (305+ tests)
