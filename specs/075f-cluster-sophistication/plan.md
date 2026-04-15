# Plan 075f: Cluster Sophistication Wiring

## Phase 1: Tests (1h)

### Integration tests
**File**: `tests/Integration/UI/Console/ComputeClusterSophisticationCommandTest.php`

1. Test `test_high_engagement_cluster_gets_advanced`: Create cluster with 3 conversations averaging 30h engagement, 6 unique IOC types, 20 turns, injection attempts → run command → assert sophistication = 'advanced' or 'expert'
2. Test `test_minimal_cluster_stays_minimal`: Create cluster with 1 short conversation (1h, 2 IOC types, 3 turns) → run command → assert sophistication = 'minimal'
3. Test `test_dry_run_does_not_persist`: Same as test 1 with --dry-run → sophistication still 'none'

### E2E test
**File**: `tests/EndToEnd/CriticalFlow/ClusterSophisticationFlowTest.php`
1. Test `test_cluster_sophistication_computed`: GET /api/v1/clusters → at least one cluster with sophistication != 'none' after command run

## Phase 2: Command Implementation (1.5h)

**File**: `src/UI/Console/ComputeClusterSophisticationCommand.php`

1. Extend `Command`, name `app:compute:cluster-sophistication`
2. Inject `Connection` + `ThreatActorStixBuilder`
3. Query active/suspect clusters:
   ```sql
   SELECT cluster_id, sophistication FROM threat_actor_cluster
   WHERE status IN ('active', 'suspect')
   ```
4. For each cluster, aggregate metrics from conversations:
   - avg_engagement_hours: AVG(EXTRACT(EPOCH FROM (ts_last - ts_first)) / 3600)
   - unique_ioc_type_count: COUNT(DISTINCT indicator.type) across all cluster conversation IOCs
   - avg_turns: AVG(conversation turn count — count messages per conversation)
   - has_injection_attempts: check if any conversation has injection_detected = true or prompt_injection_score > 0
5. Call `$threatActorStixBuilder->inferSophistication($metrics)`
6. Update cluster if sophistication changed
7. Support --dry-run, output summary

## Phase 3: Scheduler Registration (0.25h)

Add to existing scheduler config (Symfony Scheduler or cron):
- Schedule: daily at 02:00 UTC
- Command: `app:compute:cluster-sophistication`

## Phase 4: Validation (0.5h)

1. PHPStan L6+ clean
2. CS-Fixer clean
3. `make test` (4087+ tests)
4. `make endToEndTest` (305+ tests)
5. Atomic commit

## Technical Approach

The `ThreatActorStixBuilder::inferSophistication()` method (lines 288-330) accepts an `array $metrics` with keys: `avg_engagement_hours`, `unique_ioc_type_count`, `avg_turns`, `has_injection_attempts`. The command aggregates these from cluster conversations using DBAL queries and passes them directly. This ensures cluster sophistication uses the exact same algorithm as campaign-level sophistication.

The `threat_actor_cluster.sophistication` column is VARCHAR(20) — values: 'none', 'minimal', 'intermediate', 'advanced', 'expert'. The column already exists (created in spec 058a migration).
