# Spec 075d: Risk Score Batch Recalculation

**Created**: 2026-04-12
**Status**: Draft
**Type**: Backend (Console Command)
**Parent**: 075 — Data Quality Improvement
**Effort**: 0.5 day
**Branch**: `roadmap/075-data-quality-improvement`

---

## Context

LLM auditor found 50% disagreement on risk scores. Existing conversations have old `score_risk` values calculated before F2 rebalancing of the `RiskScorer` algorithm (`src/Application/Communication/RiskScorer.php`). The current scorer uses:
- VirusTotal malicious > 0 → +70 points
- VirusTotal suspicious > 0 → +40 points
- URLscan malicious → +60 points
- URLscan suspicious → +25 points
- Capped at 100

Many conversations were scored before these weights were applied, or with an earlier formula. The `conversation.score_risk` column (INTEGER) holds the stale value.

---

## Goal

Create a batch command `app:fix:risk-scores` that recalculates `conversation.score_risk` for all conversations using the current `RiskScorer` algorithm, based on each conversation's enriched IOC data.

**Target**: Risk score agreement from 50% to 85%+.

---

## Non-Goals

- Changing the RiskScorer algorithm itself (already rebalanced in F2)
- Recalculating individual IOC scores (only conversation-level aggregate)
- Modifying the shouldReply() decision logic

---

## Changes

### 1. Console command: `app:fix:risk-scores`

**File**: `src/UI/Console/FixRiskScoresCommand.php`

Logic:
1. Load all conversations (non-deleted)
2. For each conversation, query all enriched IOCs (via `observed_ioc` → `indicator` → enrichment data)
3. For each IOC with enrichment data, call `RiskScorer::calculateIocScore(enrichment)`
4. Take the max aggregate score across all IOCs as the conversation risk score
5. Update `conversation.score_risk` with the new value
6. Support `--dry-run` flag
7. Output: conv_id, old_score, new_score, delta
8. Transactional batch updates (flush every 50 conversations)

### 2. No schema changes

`conversation.score_risk` already exists as INTEGER.

---

## Acceptance Criteria

1. **Integration test**: CHARITY conversation with IBAN (VT malicious=2) + wallet (URLscan suspicious) → risk increases from old value
2. **Integration test**: Conversation with no enriched IOCs → risk stays at 0
3. **Integration test**: `--dry-run` reports but does not persist changes
4. **E2E test**: After batch, GET `/api/v1/conversation/{id}` shows recalculated risk score
5. PHPStan L6+ clean on `src/`
6. `make test` passes (4087+ tests)
7. `make endToEndTest` passes (305+ tests)
