# Pass 2 — Consolidation Hardening: Complete What Was Promised

**Branch**: `050-consolidation-hardening`
**Created**: 2026-04-08
**Trigger**: Self-audit revealed 10 gaps vs original spec commitments

## Methodology

Every item below has:
- **Spec reference**: which spec section promised it
- **Acceptance criteria**: binary pass/fail, measurable
- **Verification**: exact command to verify
- **Order**: dependencies respected (tests before perf, measurements before "it's fine")

**Protocol**: Same as Phase 1-4 — all 6 gates before every commit, critical changes need user approval.

---

## GAP 1: Frontend test files (spec says ≥25, delivered 18)

**Spec ref**: P2-2 — "≥ 25 test files covering all pages and critical shared components"
**Missing**: Analytics, ConversationDetail, Impact, StixExport, PipelineMonitor, ConvergenceHistory, ConversationMonitoring
**Acceptance**: `find frontend-react/src -name '*.test.*' | wc -l` ≥ 25
**Verification**: `npm run test` — all pass

## GAP 2: 403 authorization tests (spec says "Add 403 tests", delivered 0)

**Spec ref**: P2-3 — "Add 403 authorization tests for endpoints that require specific permissions"
**What to do**: For key permission groups (monitoring:read, ioc:read, config:write), write 1 test that calls the endpoint WITHOUT auth and verifies 401, then WITH auth but wrong permission → 403
**Acceptance**: ≥ 5 new 403 tests
**Verification**: `phpunit --filter=403` shows tests

## GAP 3: Redis cache (spec says ≥3 endpoints, delivered 1)

**Spec ref**: P3-2 — "≥ 3 high-frequency read endpoints cached"
**Missing**: `/api/v1/monitoring/analytics/*` (TTL 1min), `/api/v1/scambaiting/stats` (TTL 2min)
**Acceptance**: grep for CacheInterface in ≥ 3 handlers
**Verification**: Integration test verifies cache hit on second call

## GAP 4: N+1 IOC batch load (identified but not fixed)

**Spec ref**: P3-1 — "N+1 queries identified and fixed"
**Missing**: N+1 #4 — `computeConfidenceData()` per IOC in conversation IOC list (30 individual SELECTs)
**Acceptance**: IOC list for a conversation executes ≤ 5 queries (was 30+)
**Verification**: SQL count in test

## GAP 5: ESLint hardening (spec says tighten rules, delivered nothing)

**Spec ref**: P1-4d — "Tighten ESLint rules (no-unused-imports, consistent-type-imports)"
**What to do**: Add rules, fix violations, commit
**Acceptance**: `npm run lint` passes with stricter rules
**Verification**: ESLint config includes new rules

## GAP 6: Frontend bundle size gate in CI (promised, not delivered)

**Spec ref**: P4-4 — "Add bundle size check to CI (fail if > threshold)"
**What to do**: Add `npm run build` output parsing or `bundlesize` package
**Acceptance**: CI fails if main chunk > 250KB gzipped
**Verification**: CI yml contains bundle size step

## GAP 7: Frontend Codecov (promised, not delivered)

**Spec ref**: P4-4 — "Add frontend Vitest coverage upload to Codecov"
**What to do**: Install @vitest/coverage-v8, add test:coverage script, upload in CI
**Acceptance**: Codecov receives frontend coverage report
**Verification**: CI yml contains coverage upload step

## GAP 8: Measure actual backend coverage (never measured)

**Spec ref**: P2-1 — "Coverage for Application/ ≥ 80%"
**What to do**: Run `phpunit --coverage-text`, capture Application/ and UI/Console/ percentages
**Acceptance**: Document actual numbers. If < 80%, identify top uncovered files
**Verification**: Coverage report in commit message

## GAP 9: Frontend re-render audit (said "it's fine" with no data)

**Spec ref**: P3-3 — "No unnecessary re-renders on key pages"
**What to do**: Actually audit with React.memo usage check, identify components re-rendering on unrelated state changes. At minimum: verify that list pages use proper key props and that expensive computations are memoized
**Acceptance**: Document findings. Fix any found issues
**Verification**: Code review of key pages

## GAP 10: Circuit breaker scope change (changed scope without asking)

**Spec ref**: P4-1 — "Add circuit breaker pattern"
**Decision**: ReplyOrchestrator already handles 3-attempt retry. HTTP-level circuit breaker would be redundant for a single-user academic project. **BUT I should have asked before dropping it from scope.**
**Action**: Document this decision in spec as a conscious scope reduction, not a silent skip
**Acceptance**: spec.md updated with rationale

---

## Execution Order

1. GAP 8 first (measure coverage — need data before claiming "it's fine")
2. GAP 1 + GAP 2 (tests — builds safety net)
3. GAP 4 + GAP 3 (performance — N+1 fix + cache)
4. GAP 5 (ESLint — code quality)
5. GAP 9 (re-render audit — need findings)
6. GAP 6 + GAP 7 (CI — infrastructure)
7. GAP 10 (doc — scope decision)

Each gap = 1 atomic commit. Gates before every commit.
