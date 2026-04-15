# Spec 075f: Cluster Sophistication Wiring

**Created**: 2026-04-12
**Status**: Draft
**Type**: Backend (Console Command + Scheduler)
**Parent**: 075 — Data Quality Improvement
**Effort**: 0.5 day
**Branch**: `roadmap/075-data-quality-improvement`

---

## Context

The `inferSophistication()` method exists in `ThreatActorStixBuilder` (lines 288-330) and computes a sophistication level from campaign metrics:
- `avg_engagement_hours` > 24 → +2, > 4 → +1
- `unique_ioc_type_count` >= 5 → +2, >= 3 → +1
- `avg_turns` > 15 → +2, > 7 → +1
- `has_injection_attempts` → +1

Score mapping: 0 → `none`, 1-2 → `minimal`, 3-4 → `intermediate`, 5-6 → `advanced`, 7+ → `expert`

However, `ClusterQueryService` reads `threat_actor_cluster.sophistication` directly from the DB (line 56: `tac.sophistication`), and this column is never computed — it contains the default value `'none'` or whatever was set at cluster creation. The `inferSophistication()` logic is never called for clusters.

---

## Goal

Create a command `app:compute:cluster-sophistication` that computes real sophistication for each cluster using `inferSophistication()` logic applied to cluster-level aggregated metrics. Add to daily scheduler.

**Target**: Cluster sophistication values are real computed values, not hardcoded `'none'`.

---

## Non-Goals

- Changing the `inferSophistication()` algorithm (it is already well-designed)
- Real-time sophistication updates at ingestion time (batch is sufficient)
- Modifying ClusterQueryService to compute on-the-fly (pre-computed is better for STIX exports)

---

## Changes

### 1. Console command: `app:compute:cluster-sophistication`

**File**: `src/UI/Console/ComputeClusterSophisticationCommand.php`

Logic:
1. Query all active/suspect clusters
2. For each cluster, aggregate metrics from cluster conversations:
   ```sql
   SELECT
       AVG(EXTRACT(EPOCH FROM (c.ts_last - c.ts_first)) / 3600) AS avg_engagement_hours,
       COUNT(DISTINCT i.type) AS unique_ioc_type_count,
       AVG(c.turn_count) AS avg_turns,
       BOOL_OR(c.injection_detected) AS has_injection_attempts
   FROM threat_actor_cluster_conversation tacc
   JOIN conversation c ON c.conv_id = tacc.conv_id
   LEFT JOIN observed_ioc oi ON oi.msg_id IN (SELECT msg_id FROM message WHERE conv_id = c.conv_id)
   LEFT JOIN indicator i ON oi.indicator_id = i.indicator_id
   WHERE tacc.cluster_id = :clusterId
   ```
3. Apply `inferSophistication()` scoring logic to compute sophistication level
4. Update `threat_actor_cluster.sophistication` column
5. Support `--dry-run` flag
6. Output: cluster_id, old_sophistication, new_sophistication, score_breakdown

### 2. Reuse `ThreatActorStixBuilder::inferSophistication()`

Make `inferSophistication()` public (it already is — line 288: `public function inferSophistication`). Inject `ThreatActorStixBuilder` in the command to reuse the exact same logic.

### 3. Add to scheduler

Register the command in the existing Symfony scheduler configuration to run daily.

---

## Acceptance Criteria

1. **Integration test**: Cluster with conversations averaging > 24h engagement, 5+ IOC types, 15+ turns → sophistication = 'advanced' or higher
2. **Integration test**: Cluster with 1 short conversation → sophistication = 'minimal' or 'none'
3. **Integration test**: `--dry-run` reports but does not persist
4. **E2E test**: GET `/api/v1/clusters` shows non-`'none'` sophistication for clusters with sufficient data
5. PHPStan L6+ clean on `src/`
6. `make test` passes (4087+ tests)
7. `make endToEndTest` passes (305+ tests)
