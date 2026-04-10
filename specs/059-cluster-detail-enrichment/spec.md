# Spec 059: Cluster Detail Enrichment — Behavioral Profile + IOC Navigation

**Created**: 2026-04-10
**Status**: Draft
**Type**: Backend (DB aggregation) + Frontend (UX enrichment)
**Depends on**: 058a/b/c (clustering domain + STIX + frontend), `ioc_context` table (043)
**Effort**: 1.5 days (Sprint 1: 0.5d + Sprint 2: 1d)
**Branch**: `059-cluster-detail-enrichment`

---

## Context

The cluster detail page (058c) shows anchor IOCs and conversations but **does not surface the rich behavioral context already computed and stored in the `ioc_context` table** (semantic role, stimulus type, urgency score, context excerpt, revelation turn). This is a missed opportunity: the data exists, the LLM enrichment has already paid for it, but it's invisible from the cluster view.

A CTI analyst opening a cluster cannot answer:
- *What tactic does this threat actor use?* (urgency pressure, authority, reciprocity)
- *When in the conversation does the scammer reveal payment details?* (turn 1 = blast, turn 5 = trust-building)
- *Are these conversations templated or hand-written?* (identical excerpts = automated campaign)
- *What does the scammer actually say?* (raw context excerpts)

Additionally, **clicking on an anchor IOC filters conversations but blocks navigation** to the IOC detail page where all this richness already lives.

This spec addresses both gaps **without any new LLM inference** — only aggregation and display of existing data.

---

## Sprint 1 — Quick Wins (P0, ~30 min)

### S1.1 — Fix "= Not detected" template bug

**Constat (audit v3)**: In IOC Detail → Behavioral Signals, "Not detected" is rendered as "= Not detected" — an unescaped template literal artifact.

**Files**: `frontend-react/src/pages/IocDetail.tsx` (Behavioral Signals section)

**Fix**: Remove the leading `= ` character from the rendered value (likely a `${value}` interpolation issue or stray text node).

**Acceptance**: "Not detected" displays cleanly without prefix.

---

### S1.2 — IOC Detail navigation icon on each anchor IOC

**Problem**: Clicking an anchor IOC in cluster detail filters conversations (good) but blocks access to the full IOC detail page (bad).

**Solution**: Add an external link icon `↗` aligned right on each anchor IOC row. Clicking the icon navigates to `/ioc-explorer/{indicator_id}` (in same tab). Clicking the rest of the row keeps the filter behavior.

**Files**:
- `frontend-react/src/pages/ClusterDetail.tsx` — add icon button per anchor IOC

**Acceptance**:
- Clicking the body of an anchor IOC row toggles the conversation filter (existing behavior)
- Clicking the `↗` icon navigates to IOC Detail
- Icon has a tooltip "View IOC details"
- Stop event propagation on icon click so filter doesn't toggle

---

### S1.3 — Sample Reveals section (raw excerpts)

**Problem**: Cluster detail shows no narrative — analysts can't see what the scammer actually says.

**Solution**: New section "Sample Reveals" between Threat Profile and the two columns. Shows up to 5 distinct `context_excerpt` values from the cluster's anchor IOCs, ordered by `created_at ASC` (oldest first). Each excerpt is shown verbatim — **no LLM summary, no synthesis**.

**Backend**: Extend `ClusterQueryService::getDetail()` to return a `sample_excerpts` array (up to 5 distinct strings).

**Frontend**: New section in `ClusterDetail.tsx`:
```
SAMPLE REVEALS
"Scammer demanded immediate wire transfer to avoid penalties..."
"Please transfer EUR 5,000 to secure your allocation..."
"Hospital requires payment TODAY or treatment stops..."
```

**Acceptance**:
- Section hidden if 0 excerpts
- Maximum 5 excerpts
- Distinct values only (no duplicates)
- Italic styling, quoted, with `text-on-surface-dim`
- No LLM call, no inference — pure SQL aggregation

---

## Sprint 2 — Behavioral Profile (P1, ~1 day)

### S2.1 — Backend: aggregate behavioral data per cluster

**Goal**: Enrich `ClusterQueryService::getDetail()` with cluster-level behavioral aggregations from `ioc_context`.

**New fields in API response**:

```json
{
  "...existing fields...",
  "behavioral_profile": {
    "dominant_stimulus": "urgency-pressure",
    "dominant_stimulus_count": 8,
    "avg_urgency_score": 0.76,
    "dominant_revelation_turn": 1,
    "hesitation_count": 0,
    "language_switch_count": 0,
    "templated_excerpt_count": 3,
    "total_enriched_iocs": 12
  },
  "anchor_iocs": [
    {
      "...existing fields...",
      "dominant_semantic_role": "Payment Destination",
      "dominant_stimulus": "urgency-pressure",
      "avg_urgency_score": 0.78
    }
  ],
  "sample_excerpts": [
    "Scammer demanded immediate wire transfer...",
    "..."
  ]
}
```

**SQL aggregations** (single transaction, ≤ 3 queries):

1. **Per anchor IOC** — for each indicator in `threat_actor_cluster_ioc`:
   ```sql
   SELECT
     indicator_id,
     MODE() WITHIN GROUP (ORDER BY semantic_role) AS dominant_semantic_role,
     MODE() WITHIN GROUP (ORDER BY stimulus_type) AS dominant_stimulus,
     AVG(urgency_score) AS avg_urgency_score
   FROM ioc_context
   WHERE indicator_id = ANY(:ids)
     AND enrichment_status = 'enriched'
   GROUP BY indicator_id
   ```

2. **Cluster-level behavioral profile**:
   ```sql
   SELECT
     MODE() WITHIN GROUP (ORDER BY ic.stimulus_type) AS dominant_stimulus,
     COUNT(*) FILTER (WHERE ic.stimulus_type = (MODE() WITHIN GROUP (ORDER BY ic.stimulus_type))) AS dominant_stimulus_count,
     AVG(ic.urgency_score) AS avg_urgency_score,
     MODE() WITHIN GROUP (ORDER BY ic.revelation_turn) AS dominant_revelation_turn,
     COUNT(*) FILTER (WHERE ic.hesitation_detected) AS hesitation_count,
     COUNT(*) FILTER (WHERE ic.language_switch) AS language_switch_count,
     COUNT(*) AS total_enriched_iocs
   FROM ioc_context ic
   JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
   JOIN message m ON oi.msg_id = m.msg_id
   JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
   WHERE tacc.cluster_id = :clusterId
     AND ic.enrichment_status = 'enriched'
   ```

3. **Templated excerpt detection** — count distinct `context_excerpt` values that appear ≥ 3 times:
   ```sql
   SELECT COUNT(*) AS templated_count FROM (
     SELECT context_excerpt
     FROM ioc_context ic
     JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
     JOIN message m ON oi.msg_id = m.msg_id
     JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
     WHERE tacc.cluster_id = :clusterId
       AND ic.context_excerpt IS NOT NULL
     GROUP BY context_excerpt
     HAVING COUNT(*) >= 3
   ) sub
   ```

4. **Sample excerpts** (Sprint 1 dependency):
   ```sql
   SELECT DISTINCT ON (ic.context_excerpt) ic.context_excerpt
   FROM ioc_context ic
   JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
   JOIN message m ON oi.msg_id = m.msg_id
   JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
   WHERE tacc.cluster_id = :clusterId
     AND ic.context_excerpt IS NOT NULL
     AND ic.context_excerpt != ''
   ORDER BY ic.context_excerpt, ic.created_at ASC
   LIMIT 5
   ```

**Performance budget**: total query time < 50ms on 100-cluster fixture.

---

### S2.2 — Frontend: Threat Profile section + anchor IOC enrichment

**Threat Profile section** (between header and the two columns):

```
┌─ THREAT PROFILE ─────────────────────────────────────────────┐
│ Primary tactic       Urgency Pressure (8/10 conversations)   │
│ IOC revelation       Turn 1 · Initial email                  │
│ Avg scammer urgency  76%                                     │
│ Hesitation detected  0 conversations                         │
│ Language switches    0 conversations                         │
│ Template signal      3 IOCs share identical excerpt          │
└──────────────────────────────────────────────────────────────┘
```

Conditional display: hide entire section if `total_enriched_iocs === 0`.

**Anchor IOC enrichment** (panel left, under each IOC):

```
[IBAN]  DE89370400440532013000           10 conv. [↗]
        Payment Destination · Urgency Pressure · 78%
```

Pills are subtle (small, monospace, `text-on-surface-dim`). Hidden if `dominant_semantic_role === null`.

**Files**:
- `frontend-react/src/hooks/useClusters.ts` — extend `ClusterDetail` interface
- `frontend-react/src/pages/ClusterDetail.tsx` — new section + anchor enrichment

---

### S2.3 — Optional: Aggregated semantic role summary (P2 — only if time)

Below the Threat Profile, a one-line summary:
> "Payment destinations: 1 IBAN, 1 BTC wallet · Contact channels: 3 phones"

Computed from `anchor_iocs[].dominant_semantic_role` grouped by role and counted by type.

Optional. Skip if Sprint 2 runs over budget.

---

## What is NOT in scope (explicit non-goals)

- **No new LLM calls**. Zero inference. Only aggregation of existing `ioc_context` data.
- **No "summary" or "synthesis"** of multiple excerpts. Show raw text or nothing.
- **No new tables, no new migrations**. Pure read queries on existing schema.
- **No goals re-pondering** (already done in 058 audit fixes via `weighted_scam_types`).
- **No template signal cross-cluster comparison**. Only intra-cluster excerpt counting.
- **No re-clustering or algorithm changes**. Display layer only.

---

## Tests required

### Backend (TDD strict — test first, code second)

```
tests/Integration/Clustering/
└── ClusterBehavioralProfileTest.php
    ├── testGetDetailIncludesBehavioralProfile
    ├── testBehavioralProfileDominantStimulus
    ├── testBehavioralProfileAvgUrgency
    ├── testBehavioralProfileHesitationCount
    ├── testBehavioralProfileLanguageSwitchCount
    ├── testBehavioralProfileTemplatedExcerptCount
    ├── testAnchorIocsHaveDominantSemanticRole
    ├── testAnchorIocsHaveDominantStimulus
    ├── testAnchorIocsHaveAvgUrgency
    ├── testSampleExcerptsLimit5
    ├── testSampleExcerptsDistinct
    ├── testSampleExcerptsHiddenWhenEmpty
    ├── testBehavioralProfileNullWhenNoEnrichedContext
    └── testNonRegressionExistingDetailFields
```

### Frontend

```
src/pages/ClusterDetail.test.tsx (extend existing)
├── testThreatProfileSectionRendersWhenDataPresent
├── testThreatProfileSectionHiddenWhenNoEnrichedIocs
├── testAnchorIocPillsDisplaySemanticRoleAndStimulus
├── testAnchorIocPillsHiddenWhenNoData
├── testSampleExcerptsRenderUpToFive
├── testSampleExcerptsSectionHiddenWhenEmpty
├── testNavigationIconNavigatesToIocDetail
├── testNavigationIconDoesNotTriggerFilter
└── testRowClickStillTogglesFilter
```

### Fixtures

Extend `ClusteringFixtures.php` to inject `ioc_context` rows with known stimulus/urgency/excerpt values, so tests can assert exact aggregations.

---

## Success criteria

- [ ] All API endpoints return enriched data without breaking existing 058c tests
- [ ] Cluster detail page displays Threat Profile when enrichment data exists
- [ ] Anchor IOCs show dominant role + stimulus + urgency pills
- [ ] Sample Reveals section displays up to 5 raw excerpts
- [ ] Navigation icon `↗` opens IOC Detail
- [ ] "= Not detected" bug fixed in IOC Detail
- [ ] No new LLM cost (audit `llm_usage` table — 0 new rows during the cluster export flow)
- [ ] PHPStan L7 clean
- [ ] CS-Fixer clean
- [ ] All 8 gates pass
- [ ] Coverage 100% on new code
- [ ] User validates each commit

---

## Out of scope (deferred to future specs)

- Cross-cluster template signal detection (similarity matching across clusters)
- Cluster annotations / analyst notes
- Manual cluster split/merge UI
- LLM-based cluster summary generation
- Real-time WebSocket updates on cluster changes
