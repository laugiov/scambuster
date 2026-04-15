# Tasks 075f: Cluster Sophistication Wiring

## Task 1: Write integration tests (TDD red)
**File**: `tests/Integration/UI/Console/ComputeClusterSophisticationCommandTest.php`
- [ ] Test `test_high_engagement_cluster_gets_advanced`: 3 conversations, 30h avg, 6 IOC types, 20 turns, injection → 'advanced' or 'expert'
- [ ] Test `test_minimal_cluster_stays_minimal`: 1 conversation, 1h, 2 IOC types, 3 turns → 'minimal'
- [ ] Test `test_dry_run_does_not_persist`: --dry-run → sophistication unchanged
- [ ] Run: `make test` — new tests fail

## Task 2: Write E2E test (TDD red)
**File**: `tests/EndToEnd/CriticalFlow/ClusterSophisticationFlowTest.php`
- [ ] Test `test_cluster_sophistication_computed`: run command → GET /api/v1/clusters → assert non-'none' sophistication
- [ ] Run: `make endToEndTest` — new test fails

## Task 3: Implement console command
**File**: `src/UI/Console/ComputeClusterSophisticationCommand.php`
- [ ] Create `ComputeClusterSophisticationCommand` extending `Command`
- [ ] Configure name `app:compute:cluster-sophistication` with `--dry-run` option
- [ ] Inject `Connection` + `ThreatActorStixBuilder`
- [ ] Query active/suspect clusters
- [ ] For each cluster: aggregate avg_engagement_hours, unique_ioc_type_count, avg_turns, has_injection_attempts from cluster conversations
- [ ] Call `$threatActorStixBuilder->inferSophistication($metrics)`
- [ ] Update threat_actor_cluster.sophistication via DBAL if changed
- [ ] Output: cluster_id, old value, new value, score breakdown

## Task 4: Register in scheduler
- [ ] Add daily schedule (02:00 UTC) for `app:compute:cluster-sophistication` in scheduler config

## Task 5: Integration process
- [ ] PHPStan L6+: `make stan` clean on `src/`
- [ ] CS-Fixer: `make cs-fixer` clean
- [ ] `make test` — all tests pass (4087+ tests)
- [ ] `make endToEndTest` — all tests pass (305+ tests)
- [ ] Atomic commit: `feat(075f): add app:compute:cluster-sophistication command with daily scheduler`
