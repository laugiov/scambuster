# Quality Protocol — Spec 059 (Cluster Detail Enrichment)

**Applies to**: ALL code produced for spec 059
**Non-negotiable**: Every point below is mandatory, no exception

---

## 1. TDD Strict

For every component:
1. **Write the test FIRST** — it must fail (red)
2. **Write the minimal code** to make it pass (green)
3. **Refactor** if needed
4. **Never write code without a pre-existing failing test**

Show the test to the user BEFORE implementing the code.

**Exception**: fixture extensions (Task 1.0, 2.0) are not "production code" — they enable tests. They still require user validation before commit.

---

## 2. Coverage 95-100% on new code

Every method created must have:
- **Unit tests** for pure logic
- **Integration tests** for DB interactions (DBAL queries on `ioc_context`)
- **Frontend tests** for new components / sections / interactions
- **Non-regression tests** for modified files (ClusterQueryService, ClusterDetail.tsx, IocDetail.tsx)

Measure after each commit:
```bash
docker compose exec -T backend-test php bin/phpunit --coverage-text --filter=Clustering | grep -A2 ClusterQueryService
cd frontend-react && npx vitest run --coverage src/pages/ClusterDetail.test.tsx
```

Target: ≥ 95% line coverage on `ClusterQueryService::getDetail()` and `ClusterDetail.tsx`.

---

## 3. All 8 gates before EVERY commit

```bash
# Backend (4 gates)
cd /var/www/html/scambuster-github
docker compose exec -T backend-test php vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec -T backend-test php vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec -T backend-test php bin/phpunit --testsuite=integration,unit --no-progress

# Frontend (4 gates)
cd frontend-react
npx tsc --noEmit
npx eslint src
npx vitest run
# (build is implied — Railway will validate)
```

If ANY gate fails → fix it before commit. No `--no-verify`.

---

## 4. No new LLM calls — zero inference

This spec is **strictly aggregation-and-display** of pre-existing `ioc_context` data.

**Forbidden**:
- Calling `ContextualEnricher` or any LLM client
- Inserting new rows into `ioc_context`
- Adding any "summary" or "synthesis" of multiple excerpts
- Any feature that increments `llm_usage.total_cost_usd`

**Validation**:
```sql
-- Before/after Sprint 2: this should be 0
SELECT COUNT(*) FROM llm_usage WHERE created_at > NOW() - INTERVAL '1 day' AND task_type LIKE '%cluster%';
```

---

## 5. No schema changes

Spec 059 must NOT touch the database schema. No migrations, no `ALTER TABLE`, no new tables.

If a query needs an index for performance:
- Document it in the spec
- Add it as a SEPARATE migration with a clear name (`Version20260410000000_AddClusterDetailIndex.php`)
- Justify with EXPLAIN ANALYZE results

---

## 6. PHPStan level 7 strict

All new and modified files must pass `phpstan analyse` at level 7 (project default).

Forbidden:
- `mixed` casts without `is_string()`/`is_int()` guards
- `@phpstan-ignore` annotations (unless documented in commit message)
- Suppressing errors with `// @phpstan-ignore-next-line`

---

## 7. CS-Fixer clean

All files must be CS-Fixer clean before commit:
```bash
docker compose exec -T backend-test php vendor/bin/php-cs-fixer fix --dry-run --diff -- <file>
```

Common rules to respect:
- Single quotes for strings without interpolation
- PHPDoc parameter alignment
- No trailing whitespace
- Blank line before `if`/`foreach`/`return` after assignment

---

## 8. User validates EVERY commit

**Process**:
1. Show the diff to the user
2. Wait for explicit "OK"
3. Then commit
4. Then move to next task

**Never batch commits.** Every atomic concern = 1 commit = 1 user validation.

---

## 9. Atomic commits

Each commit addresses ONE concern:
- ✅ "feat(cluster-detail): backend behavioral profile aggregation (059-T2.1)"
- ❌ "feat(cluster-detail): backend + frontend + tests + fixtures"

Sprint 1 is the only exception: it's small enough to be one atomic commit covering S1.1 + S1.2 + S1.3 (a single coherent UX delivery).

---

## 10. PHPDoc on all public/protected methods

Every new public/protected method must have:
- `@param` types (when not obvious from PHP types)
- `@return` types with shape annotations for complex arrays
- Brief description (1 line)

Example:
```php
/**
 * Get behavioral aggregations for a cluster from ioc_context.
 *
 * @return array{
 *     dominant_stimulus: string|null,
 *     avg_urgency_score: float,
 *     hesitation_count: int,
 *     ...
 * }|null Null if no enriched contexts exist
 */
private function getBehavioralProfile(string $clusterId): ?array
```

---

## 11. Performance budget

| Operation | Budget |
|-----------|--------|
| `getDetail()` total time | < 50ms |
| Per-anchor aggregation query | < 10ms |
| Cluster-level aggregation query | < 20ms |
| Templated count query | < 10ms |
| Sample excerpts query | < 10ms |

Validate with EXPLAIN ANALYZE on production fixture (100+ cluster) before final commit.

If exceeded: add a partial index, document it in a separate migration.

---

## 12. Rollback safety

Each commit must be independently revertable:
- No commit depends on a future commit (only on past ones)
- No commit leaves the codebase in a broken state
- Backend changes never break the frontend (versioned API contract)
- Frontend changes never break existing tests

**Validation**: after each commit, the full test suite must pass on a clean checkout.

---

## Definition of Done (per task)

A task is DONE when:
- [ ] TDD red → green cycle completed
- [ ] All 8 gates green
- [ ] Coverage target met
- [ ] User validated the diff
- [ ] Commit pushed to feature branch
- [ ] No regression in existing tests
- [ ] PHPDoc complete
- [ ] No new LLM cost
- [ ] Performance budget respected (where applicable)

---

## Definition of Done (per spec)

Spec 059 is DONE when:
- [ ] All Sprint 1 + Sprint 2 tasks completed
- [ ] Re-run expert audit on the new screens
- [ ] User explicit "GO for merge to main + demo"
- [ ] Branch merged with `--no-ff`
- [ ] CHANGELOG.md updated with v2.9.0 section
- [ ] CLAUDE.md updated with the new feature reference
