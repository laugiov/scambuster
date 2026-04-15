# Tasks 075d: Risk Score Batch Recalculation

## Task 1: Write integration tests (TDD red)
**File**: `tests/Integration/UI/Console/FixRiskScoresCommandTest.php`
- [ ] Test `test_recalculates_with_enriched_iocs`: IBAN VT malicious=2 → score = 70
- [ ] Test `test_conversation_without_enrichment_stays_zero`: no enrichment → score 0
- [ ] Test `test_dry_run_does_not_persist`: --dry-run → score unchanged
- [ ] Test `test_max_score_capped_at_100`: VT malicious + URLscan malicious → score = 100
- [ ] Run: `make test` — new tests fail

## Task 2: Write E2E test (TDD red)
**File**: `tests/EndToEnd/CriticalFlow/RiskRecalculFlowTest.php`
- [ ] Test `test_risk_recalcul_updates_conversation`: ingest + enrich + command + GET assert
- [ ] Run: `make endToEndTest` — new test fails

## Task 3: Implement console command
**File**: `src/UI/Console/FixRiskScoresCommand.php`
- [ ] Create `FixRiskScoresCommand` extending `Command`
- [ ] Configure name `app:fix:risk-scores` with `--dry-run` option
- [ ] Inject `Connection` + `RiskScorer`
- [ ] Query all non-deleted conversations
- [ ] For each: query IOC enrichment data joined via observed_ioc + indicator
- [ ] Calculate max IOC score using `RiskScorer::calculateIocScore()`
- [ ] Update conversation.score_risk via DBAL (batch 50)
- [ ] Output: conv_id, old_score, new_score, delta
- [ ] CSV report on --dry-run

## Task 4: Integration process
- [ ] PHPStan L6+: `make stan` clean on `src/`
- [ ] CS-Fixer: `make cs-fixer` clean
- [ ] `make test` — all tests pass (4087+ tests)
- [ ] `make endToEndTest` — all tests pass (305+ tests)
- [ ] Atomic commit: `feat(075d): add app:fix:risk-scores command for batch risk recalculation`
