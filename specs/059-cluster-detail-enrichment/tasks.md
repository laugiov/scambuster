# Tasks: 059-cluster-detail-enrichment

**Estimated**: 1.5 days (Sprint 1: 0.5d + Sprint 2: 1d)
**Quality Protocol**: [quality-protocol.md](quality-protocol.md) — MANDATORY on every task

> **REMINDER — Definition of Done per commit**:
> 1. Test written FIRST and failing (TDD red)
> 2. Code written to pass the test (TDD green)
> 3. All 8 gates pass:
>    ```bash
>    make cs-fixer && make stan && make test && cd frontend-react && npx tsc --noEmit && npx eslint src && npx vitest run
>    ```
> 4. No existing test broken (2346+ backend, 88+ frontend)
> 5. Coverage 100% on new code
> 6. User validates diff before commit
> 7. Commit is atomic (1 concern per commit)
> 8. PHPDoc on all public methods

---

# SPRINT 1 — Quick Wins (P0)

## Task 1.0 — Extend ClusteringFixtures with ioc_context rows

**TDD**: N/A (fixture, not production code) — but tests in 1.3 depend on this.

- [ ] Read existing `ClusteringFixtures::load()` to understand insertion order
- [ ] After observed_iocs are inserted, insert `ioc_context` rows for cluster A's anchor IBAN:
  - 5 rows with `enrichment_status = 'enriched'`, `stimulus_type = 'urgency-pressure'`, `urgency_score = 0.80`, `semantic_role = 'Payment Destination'`, `revelation_turn = 1`, `context_excerpt = 'Wire transfer demanded urgently'`, `hesitation_detected = false`, `language_switch = false`
  - 3 rows with the same `context_excerpt` (templated marker validation)
- [ ] Use deterministic UUIDs prefixed `gggggggg-` for `ioc_context.id`
- [ ] Ensure `cleanup()` removes these rows (`DELETE FROM ioc_context WHERE id::text LIKE 'gggggggg-%'`)
- [ ] Run quality gates
- [ ] User validates diff

**Gate**: `make test` passes with extended fixtures (no regression).

---

## Task 1.1 — Fix "= Not detected" template bug

**TDD**: write a snapshot or text-content test FIRST.

- [ ] **TEST FIRST**: in `frontend-react/src/pages/IocDetail.test.tsx`, add test:
  ```typescript
  it('renders "Not detected" without leading equals sign in Behavioral Signals', async () => {
    // mock API response with hesitation_detected: false
    // render IocDetail
    // assert screen.getByText('Not detected') exists
    // assert no element contains "= Not detected"
  });
  ```
- [ ] Verify test FAILS (TDD red)
- [ ] Locate the offending line in `IocDetail.tsx` Behavioral Signals section
- [ ] Remove the spurious `= ` prefix
- [ ] Verify test PASSES (TDD green)
- [ ] Run all gates
- [ ] User validates diff

---

## Task 1.2 — Navigation icon on anchor IOCs

**TDD**: write 3 tests FIRST.

- [ ] **TEST FIRST**: in `frontend-react/src/pages/ClusterDetail.test.tsx`:
  - `testNavigationIconRendersOnEachAnchor` — count `↗` icons === anchor_iocs.length
  - `testNavigationIconNavigatesToIocDetail` — fire click on icon, assert navigate called with `/ioc-explorer/{indicator_id}`
  - `testNavigationIconDoesNotTriggerFilter` — fire click on icon, assert filter state unchanged
- [ ] Verify all 3 tests FAIL
- [ ] In `ClusterDetail.tsx`, add a `<button>` with `↗` SVG inside each anchor IOC row
- [ ] Use `useNavigate()` from react-router-dom
- [ ] `e.stopPropagation()` on icon click handler
- [ ] Add `title="View IOC details"` for accessibility
- [ ] Verify all 3 tests PASS
- [ ] Run all gates
- [ ] User validates diff

---

## Task 1.3 — Sample Reveals section (backend + frontend)

**TDD**: backend test FIRST, then frontend test.

### Backend

- [ ] **TEST FIRST**: in `tests/Integration/Clustering/ClusterApiTest.php` (or new file `ClusterDetailEnrichmentTest.php`):
  - `testGetDetailIncludesSampleExcerpts` — assert `sample_excerpts` is array, max 5 items, distinct values
  - `testSampleExcerptsEmptyWhenNoEnrichedContext` — assert empty array when no `ioc_context` rows
- [ ] Verify tests FAIL
- [ ] Extend `ClusterQueryService::getDetail()` with the SQL query (see spec.md S1.3)
- [ ] Add `'sample_excerpts' => $sampleExcerpts` to the returned array
- [ ] PHPDoc the new field in the return type annotation
- [ ] Verify tests PASS

### Frontend

- [ ] **TEST FIRST**: in `ClusterDetail.test.tsx`:
  - `testSampleExcerptsRenderUpToFive` — render with 7 excerpts, assert only 5 visible
  - `testSampleExcerptsSectionHiddenWhenEmpty` — assert section absent when array empty
- [ ] Verify tests FAIL
- [ ] Update `ClusterDetail` interface in `useClusters.ts` with `sample_excerpts: string[]`
- [ ] Add new `<section>` between header/STIX-ID and the two columns
- [ ] Render in italic, quoted, `text-on-surface-dim`
- [ ] Verify tests PASS

### Sprint 1 commit

- [ ] Run ALL 8 gates
- [ ] User validates the full Sprint 1 diff
- [ ] Single atomic commit: `feat(cluster-detail): Sprint 1 — IOC nav icon, sample reveals, Behavioral Signals fix (059)`

---

# SPRINT 2 — Behavioral Profile (P1)

## Task 2.0 — Extend ClusteringFixtures with varied behavioral data

**TDD**: N/A — enables Sprint 2 tests.

- [ ] Add to cluster B (wallet_btc anchor):
  - 4 enriched ioc_context rows: `stimulus_type = 'authority'`, `urgency_score = 0.45`, `semantic_role = 'Payment Destination'`, `revelation_turn = 3`, `context_excerpt = 'CEO approval required immediately'`
  - 1 row with `hesitation_detected = true`
- [ ] Add to cluster C (transitive — phone + IBAN):
  - 3 enriched rows for phone: `stimulus_type = 'reciprocity'`, `urgency_score = 0.30`, `semantic_role = 'Contact Channel'`, `revelation_turn = 2`
  - 1 row with `language_switch = true`
- [ ] Update `cleanup()` if new ID prefixes used
- [ ] Run all gates
- [ ] User validates diff

---

## Task 2.1 — Backend: Cluster-level behavioral profile aggregation

**TDD**: 7 tests FIRST.

- [ ] **TEST FIRST**: create `tests/Integration/Clustering/ClusterBehavioralProfileTest.php` with 7 tests:
  1. `testGetDetailIncludesBehavioralProfile` — assert key exists
  2. `testBehavioralProfileDominantStimulus` — cluster A → 'urgency-pressure'
  3. `testBehavioralProfileDominantStimulusCount` — cluster A → 8 (5 + 3)
  4. `testBehavioralProfileAvgUrgency` — cluster A → 0.80
  5. `testBehavioralProfileDominantRevelationTurn` — cluster A → 1
  6. `testBehavioralProfileHesitationCount` — cluster A → 0, cluster B → 1
  7. `testBehavioralProfileLanguageSwitchCount` — cluster A → 0, cluster C → 1
- [ ] Verify all 7 tests FAIL (TDD red)
- [ ] In `ClusterQueryService::getDetail()`, add the cluster-level aggregation SQL (see spec.md S2.1 query #2)
- [ ] Build the `behavioral_profile` array and merge into `$detail`
- [ ] PHPStan-typed return: `array{...existing..., behavioral_profile: array{...}|null}`
- [ ] Verify all 7 tests PASS (TDD green)
- [ ] Run all gates
- [ ] User validates diff
- [ ] Commit: `feat(cluster-detail): backend behavioral profile aggregation (059-T2.1)`

---

## Task 2.2 — Backend: Per-anchor behavioral aggregation

**TDD**: 3 tests FIRST.

- [ ] **TEST FIRST** in same test class:
  1. `testAnchorIocsHaveDominantSemanticRole` — cluster A IBAN → 'Payment Destination'
  2. `testAnchorIocsHaveDominantStimulus` — cluster A IBAN → 'urgency-pressure'
  3. `testAnchorIocsHaveAvgUrgency` — cluster A IBAN → 0.80
- [ ] Verify FAIL
- [ ] In `ClusterQueryService::getDetail()`, add per-anchor aggregation SQL (spec.md S2.1 query #1)
- [ ] Merge results into each `$anchor` in the loop that already enriches anchors with `conv_ids`
- [ ] Verify PASS
- [ ] Run all gates
- [ ] User validates diff
- [ ] Commit: `feat(cluster-detail): per-anchor behavioral aggregation (059-T2.2)`

---

## Task 2.3 — Backend: Templated excerpt detection

**TDD**: 1 test FIRST.

- [ ] **TEST FIRST**: `testBehavioralProfileTemplatedExcerptCount` — cluster A → 1 (the 3-row repeated excerpt)
- [ ] Verify FAIL
- [ ] Add the templated count subquery to `getDetail()`
- [ ] Add `templated_excerpt_count` to the `behavioral_profile` array
- [ ] Verify PASS
- [ ] Run all gates
- [ ] User validates diff
- [ ] Commit: `feat(cluster-detail): templated excerpt detection (059-T2.3)`

---

## Task 2.4 — Backend: Edge cases + non-regression

**TDD**: 2 tests FIRST.

- [ ] **TEST FIRST**:
  1. `testBehavioralProfileNullWhenNoEnrichedContext` — cluster with 0 enriched rows → `behavioral_profile === null`
  2. `testNonRegressionExistingDetailFields` — verify `cluster_id`, `name`, `anchor_iocs[0].ioc_value`, `conversations[0].conv_id` still present and correct
- [ ] Verify FAIL (or already pass for non-regression)
- [ ] Add null guard in `getDetail()` for the edge case
- [ ] Verify PASS
- [ ] Run all gates including `make test` for full backend suite (must stay 2346+ ✓)
- [ ] User validates diff
- [ ] Commit: `feat(cluster-detail): null guards + non-regression (059-T2.4)`

---

## Task 2.5 — Frontend: Threat Profile section

**TDD**: 4 tests FIRST.

- [ ] **TEST FIRST** in `ClusterDetail.test.tsx`:
  1. `testThreatProfileSectionRendersWhenDataPresent` — render 6 fields
  2. `testThreatProfileSectionHiddenWhenNoEnrichedIocs` — section absent when `behavioral_profile === null`
  3. `testThreatProfilePrimaryTacticDisplaysCount` — "Urgency Pressure (8/10 conversations)"
  4. `testThreatProfileTemplateSignalDisplays` — "3 IOCs share identical excerpt" if count > 0
- [ ] Verify FAIL
- [ ] Update `ClusterDetail` interface in `useClusters.ts` with `behavioral_profile: BehavioralProfile | null`
- [ ] Add new `<section>` to `ClusterDetail.tsx` between STIX ID and the two columns
- [ ] Conditional rendering on `cluster.behavioral_profile?.total_enriched_iocs > 0`
- [ ] Display 6 fields with labels and values
- [ ] Verify PASS
- [ ] Run all gates
- [ ] User validates diff
- [ ] Commit: `feat(cluster-detail): frontend Threat Profile section (059-T2.5)`

---

## Task 2.6 — Frontend: Anchor IOC behavioral pills

**TDD**: 2 tests FIRST.

- [ ] **TEST FIRST**:
  1. `testAnchorIocPillsDisplaySemanticRoleAndStimulus` — assert pills render with role + stimulus + urgency %
  2. `testAnchorIocPillsHiddenWhenNoData` — assert no pills when `dominant_semantic_role === null`
- [ ] Verify FAIL
- [ ] Update anchor IOC interface in `useClusters.ts` with `dominant_semantic_role`, `dominant_stimulus`, `avg_urgency_score`
- [ ] Add pills under each anchor IOC in `ClusterDetail.tsx` (small text, monospace, dim color)
- [ ] Verify PASS
- [ ] Run all gates
- [ ] User validates diff
- [ ] Commit: `feat(cluster-detail): frontend anchor IOC behavioral pills (059-T2.6)`

---

## Task 2.7 — (P2 optional) Aggregated semantic role summary

**Only if Sprint 2 budget allows.**

- [ ] **TEST FIRST**: `testSemanticRoleSummaryDisplays` — "1 IBAN → Payment Destination · 1 BTC wallet → Payment Destination"
- [ ] Compute summary client-side in `ClusterDetail.tsx` from `anchor_iocs[].dominant_semantic_role`
- [ ] Display as a single line above Threat Profile section
- [ ] Run all gates
- [ ] User validates diff
- [ ] Commit: `feat(cluster-detail): aggregated semantic role summary (059-T2.7)`

---

## Task 2.8 — Performance validation

- [ ] Run `EXPLAIN ANALYZE` on the new aggregation queries with the production fixture (100+ conversations cluster)
- [ ] Assert total query time for `getDetail()` < 50ms
- [ ] Document results in commit message
- [ ] If exceeded: add an index on `ioc_context (indicator_id, enrichment_status) WHERE enrichment_status = 'enriched'` (partial index)
- [ ] Run all gates
- [ ] User validates diff

---

## Task 2.9 — Coverage report

- [ ] Run `phpunit --coverage-text --filter=Clustering` after Sprint 2
- [ ] Assert ≥ 95% line coverage on `ClusterQueryService::getDetail()`
- [ ] Assert 100% method coverage on new methods
- [ ] Run `npx vitest run --coverage` for frontend
- [ ] Assert ≥ 95% on `ClusterDetail.tsx`
- [ ] Document coverage in final commit message

---

## Completion Checklist

- [ ] Quality Protocol respected on every task
- [ ] All 8 gates pass on every commit
- [ ] 95-100% coverage on new code
- [ ] Zero regression on existing 2346+ backend / 88+ frontend tests
- [ ] TDD applied: every test written before its code
- [ ] User validated every commit
- [ ] No new LLM cost (verify `llm_usage` table count unchanged)
- [ ] Performance budget respected (< 50ms detail query)
- [ ] PHPDoc on all public methods
- [ ] No PHPStan errors at level 7
- [ ] Audit follow-up: re-run expert audit on the new screens

---

## Out of scope (deferred)

- Cross-cluster template signal (requires similarity matching across clusters)
- Cluster annotations / analyst notes
- Manual split/merge UI
- Real-time WebSocket updates
- LLM-based cluster summary
