# Plan: 059-cluster-detail-enrichment

**Spec**: [spec.md](spec.md)
**Quality Protocol**: [quality-protocol.md](quality-protocol.md) — MANDATORY

---

## Architecture

```
┌─ Frontend ─────────────────────────────────────────────┐
│ ClusterDetail.tsx                                       │
│  ├── Header (existing)                                  │
│  ├── STIX ID (existing)                                 │
│  ├── 🆕 Sample Reveals section (S1.3)                  │
│  ├── 🆕 Threat Profile section (S2.2)                  │
│  ├── Anchor IOCs panel                                  │
│  │   └── 🆕 Per-IOC pills (semantic role / stimulus)   │
│  │   └── 🆕 ↗ icon → /ioc-explorer/{id} (S1.2)         │
│  └── Conversations panel (existing)                     │
│                                                         │
│ IocDetail.tsx                                           │
│  └── 🐛 Fix "= Not detected" template bug (S1.1)       │
└─────────────────────────────────────────────────────────┘
                            ↓ GET /api/v1/clusters/{id}
┌─ Backend ──────────────────────────────────────────────┐
│ ClusterController::detail()                             │
│        ↓                                                │
│ ClusterQueryService::getDetail()                        │
│   ├── existing: cluster + anchors + conversations       │
│   ├── 🆕 SQL: per-anchor behavioral aggregates          │
│   ├── 🆕 SQL: cluster-level behavioral profile          │
│   ├── 🆕 SQL: templated excerpt count                   │
│   └── 🆕 SQL: distinct sample excerpts (≤ 5)            │
└─────────────────────────────────────────────────────────┘
                            ↓ reads
┌─ Database (read-only, no schema changes) ──────────────┐
│ ioc_context                                             │
│   ├── semantic_role                                     │
│   ├── stimulus_type                                     │
│   ├── urgency_score                                     │
│   ├── revelation_turn                                   │
│   ├── hesitation_detected                               │
│   ├── language_switch                                   │
│   ├── context_excerpt                                   │
│   └── enrichment_status (filter on 'enriched')          │
└─────────────────────────────────────────────────────────┘
```

**Zero schema change. Zero LLM call. Pure read-and-aggregate.**

---

## Sprint 1 — Quick Wins (P0)

**Estimated**: 30-45 minutes
**Single commit**: `fix(cluster-detail): Sprint 1 quick wins (icons, sample excerpts, IOC bug)`

### Step S1.0 — Fixture preparation (TDD enabler)

**Why first**: Without `ioc_context` rows in `ClusteringFixtures`, none of the new tests can verify aggregations. We add minimal fixture data first so tests can assert exact values.

**Tasks**:
- Extend `ClusteringFixtures::load()` to insert deterministic `ioc_context` rows for cluster A's anchor IBAN:
  - 5 enriched contexts with `stimulus_type = 'urgency-pressure'`, `urgency_score = 0.80`, `semantic_role = 'Payment Destination'`, `revelation_turn = 1`, `context_excerpt = 'Wire transfer demanded urgently'`
  - 3 enriched contexts with the same excerpt (templated marker)
  - 0 hesitation, 0 language switch
- Use deterministic UUIDs for `ic.id` so tests can assert IDs

### Step S1.1 — Fix "= Not detected" (frontend)

**TDD**: write a snapshot test asserting the rendered text equals "Not detected" (no leading "=").

**Code**: locate the offending template literal in `IocDetail.tsx` Behavioral Signals section, remove the spurious `=`.

### Step S1.2 — Navigation icon (frontend)

**TDD**: extend `ClusterDetail.test.tsx` with two tests:
1. Click on icon → `useNavigate` called with `/ioc-explorer/{indicator_id}`
2. Click on icon → filter state unchanged (event propagation stopped)

**Code**: add a `<button>` with stop propagation, an SVG icon, and a `useNavigate()` hook.

### Step S1.3 — Sample Reveals section

**TDD**:
- Backend integration test: `getDetail()` returns `sample_excerpts` array of distinct strings, max 5, ordered by oldest first.
- Frontend test: section renders when array non-empty, hidden when empty.

**Code**:
- Backend: add the SQL to `getStixExportData()` or, better, to `getDetail()` (since cluster detail is the consumer)
- Frontend: new section above the two columns

### Sprint 1 commit gate

All 8 gates must pass before commit. User validates the diff.

---

## Sprint 2 — Behavioral Profile (P1)

**Estimated**: 4-6 hours
**Multi-commit**: backend first, then frontend, atomic commits per concern.

### Step S2.0 — Extend fixtures

Add varied `ioc_context` data for cluster B and C so tests cover:
- Different dominant stimuli per cluster
- Mixed urgency scores (avg calculation)
- Hesitation detected on some convs
- Language switch on some convs

### Step S2.1 — Backend behavioral aggregations

**TDD order**:

1. **Test**: `testBehavioralProfileDominantStimulus` — assert `behavioral_profile.dominant_stimulus === 'urgency-pressure'` for cluster A
2. **Code**: add cluster-level aggregation SQL to `getDetail()`
3. **Test**: `testBehavioralProfileAvgUrgency` — assert avg = 0.80
4. **Test**: `testBehavioralProfileHesitationCount` — assert count = 0
5. **Test**: `testBehavioralProfileLanguageSwitchCount` — assert count = 0
6. **Test**: `testBehavioralProfileTemplatedExcerptCount` — assert count = 1 (the repeated excerpt)
7. **Test**: `testAnchorIocsHaveDominantSemanticRole` — assert IBAN has 'Payment Destination'
8. **Test**: `testAnchorIocsHaveDominantStimulus` — assert IBAN has 'urgency-pressure'
9. **Test**: `testAnchorIocsHaveAvgUrgency` — assert IBAN avg = 0.80
10. **Test**: `testBehavioralProfileNullWhenNoEnrichedContext` — empty arrays gracefully
11. **Test**: `testNonRegressionExistingDetailFields` — `cluster_id`, `anchor_iocs[].ioc_value` etc still present

**Code**: extend `ClusterQueryService::getDetail()` with:
- One aggregation query per anchor IOC (or batched with `GROUP BY indicator_id`)
- One cluster-level aggregation query
- One templated count subquery
- Merge results into the existing `$detail` array

**SQL constraints**:
- Use PostgreSQL `MODE() WITHIN GROUP` for dominant value
- Use `FILTER (WHERE ...)` for conditional counts
- All queries must use `enrichment_status = 'enriched'` filter

### Step S2.2 — Frontend Threat Profile section

**TDD**:
1. **Test**: section renders 6 fields when `behavioral_profile` present
2. **Test**: section hidden when `behavioral_profile.total_enriched_iocs === 0`
3. **Test**: anchor IOC pills render when dominant_semantic_role present
4. **Test**: pills hidden when dominant_semantic_role null

**Code**:
- Update `ClusterDetail` interface in `useClusters.ts`
- Add `<section>` for Threat Profile in `ClusterDetail.tsx`
- Add pills under each anchor IOC

### Step S2.3 — (P2 optional) Aggregated semantic role line

If time permits: add a one-line summary above Threat Profile:
> "2 IBANs → Payment Destination · 3 phones → Contact Channel"

Computed client-side from `anchor_iocs[].dominant_semantic_role`.

---

## Risks and mitigations

| Risk | Mitigation |
|------|-----------|
| `ioc_context` rows missing for many IOCs (enrichment cost) | Filter `enrichment_status = 'enriched'`, gracefully return null |
| `MODE() WITHIN GROUP` PostgreSQL-specific | OK — we're on PG 15, no DB portability requirement |
| Aggregation queries slow on large clusters | Index on `idx_ioc_context_indicator` already exists; query budget < 50ms |
| Frontend breaks existing tests | Run full vitest suite after each frontend change |
| Backfill needed after fixtures update | Tests use isolated fixtures; no production backfill needed |

---

## Performance budget

| Operation | Target | Validation |
|-----------|--------|-----------|
| `getDetail()` total query time | < 50ms | EXPLAIN ANALYZE on 100-cluster fixture |
| Frontend re-render on filter click | < 16ms | React profiler |
| Bundle size delta | < 5kb | Vite build report |

---

## Rollback plan

If Sprint 2 introduces regressions:
- Sprint 1 commit is independent and stays merged
- Sprint 2 commits can be reverted individually
- No DB schema change → no migration to roll back

---

## Deliverables

| Sprint | Backend | Frontend | Tests |
|--------|---------|----------|-------|
| 1 | `getDetail()` + sample_excerpts | IocDetail bug fix, navigation icon, Sample Reveals | 1 backend + 3 frontend |
| 2 | `getDetail()` + behavioral_profile + anchor enrichment | Threat Profile section, anchor pills | 11 backend + 5 frontend |

**Total: 12 backend tests + 8 frontend tests + fixture extension.**
