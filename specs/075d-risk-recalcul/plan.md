# Plan 075d: Risk Score Batch Recalculation

## Phase 1: Tests (1h)

### Integration tests
**File**: `tests/Integration/UI/Console/FixRiskScoresCommandTest.php`
1. Test `test_recalculates_charity_with_enriched_iocs`: Create conversation with IBAN IOC (VT malicious=2 → +70) + wallet (URLscan suspicious → +25) → run command → assert score_risk = min(100, 70+25) = 95 (or max of individual IOC scores = 70)
2. Test `test_conversation_without_enrichment_stays_zero`: Conversation with no enriched IOCs → score_risk remains 0
3. Test `test_dry_run_does_not_persist`: Same as test 1 with --dry-run → score unchanged
4. Test `test_max_score_capped_at_100`: Conversation with VT malicious + URLscan malicious → score = min(100, 70+60) = 100

### E2E test
**File**: `tests/EndToEnd/CriticalFlow/RiskRecalculFlowTest.php`
1. Test `test_risk_recalcul_updates_conversation`: Ingest → enrich IOC → run command → GET conversation → assert new risk

## Phase 2: Command Implementation (1.5h)

**File**: `src/UI/Console/FixRiskScoresCommand.php`

1. Extend `Command`, name `app:fix:risk-scores`
2. Inject `Connection` + `RiskScorer`
3. Query all conversations:
   ```sql
   SELECT c.conv_id, c.score_risk
   FROM conversation c
   WHERE c.deleted_at IS NULL
   ```
4. For each conversation, query IOC enrichment data:
   ```sql
   SELECT i.enrichment
   FROM observed_ioc oi
   JOIN indicator i ON oi.indicator_id = i.indicator_id
   JOIN message m ON oi.msg_id = m.msg_id
   WHERE m.conv_id = :convId
     AND i.enrichment IS NOT NULL
   ```
5. For each IOC enrichment JSON: call `$riskScorer->calculateIocScore($enrichment)`
6. New score = max(all IOC aggregate scores), capped at 100
7. Update conversation.score_risk if different from old value
8. Batch DBAL updates (50 conversations per transaction)

## Phase 3: Validation (0.5h)

1. PHPStan L6+ clean
2. CS-Fixer clean
3. `make test` (4087+ tests)
4. `make endToEndTest` (305+ tests)
5. Atomic commit

## Technical Approach

Reuse the existing `RiskScorer::calculateIocScore()` method directly — this ensures the batch recalculation uses the exact same formula as real-time scoring. The command uses DBAL for performance (no ORM hydration of full entity graphs). The max-across-IOCs aggregation mirrors the original scoring intent: a conversation's risk = highest risk IOC found.
