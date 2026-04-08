# Spec 050: Consolidation & Hardening — Code Quality, Tests, Performance, Resilience

**Created**: 2026-04-07
**Status**: Draft
**Type**: Consolidation (zero new features)
**Branch**: `050-consolidation-hardening`
**Effort**: ~15-20 days across 4 phases
**Baseline**: 2,101+ backend tests, 8 frontend test files, PHPStan L6, TS strict

---

## Context

ScamBuster is stable in production (60+ days, 0 incidents, EUR 5.2 total cost). Feature velocity has been high (48 specs shipped), but the codebase now needs a consolidation pause to:

- Raise code quality standards (back + front)
- Close test coverage gaps (especially frontend: 8 test files / 72 source files)
- Improve application performance and resilience
- Eliminate dead code and technical debt
- Strengthen CI quality gates

**This is a zero-feature spec.** No new functionality. Every change must preserve or improve existing behavior.

---

## Implementation Protocol (NORMATIVE)

This protocol governs **every change** in this spec. No exception, no shortcut.

### 1. Atomic Changes

Each commit addresses **one concern only**. No mixing of axes (e.g., code style fix + test addition = 2 commits).

### 2. Local Quality Gates — Mandatory Before Every Commit

Every change must pass **all** gates locally before commit:

```bash
# Backend gates
make cs-fixer        # PHP-CS-Fixer (code style)
make stan            # PHPStan level 6+ (static analysis)
make test            # Unit + Integration tests
make endToEndTest    # E2E tests

# Frontend gates
cd frontend-react && npm run typecheck   # TypeScript strict
cd frontend-react && npm run lint        # ESLint
cd frontend-react && npm run test        # Vitest
```

If **any** gate fails → fix before continuing. Never skip a gate.

### 3. Critical Change Validation — User Approval Required

The following changes require presenting the diff to the user and **waiting for explicit approval** before committing:

| Change type | Why |
|-------------|-----|
| Code deletion (dead code, deprecations) | Risk of removing code with non-obvious side effects |
| Public signature changes (API endpoints, service interfaces, events) | Breaking change risk for consumers |
| Configuration changes (PHPStan level, ESLint rules, Docker, CI) | Blast radius on entire codebase/pipeline |
| Database migrations or schema changes | Irreversible in production |
| Major dependency upgrades | Compatibility risk |
| Security-sensitive changes (auth, rate limiting, CORS, CSP) | Security regression risk |

### 4. Dead Code Analysis Protocol

Dead code is **never** deleted without explicit user validation:

1. **Detect**: Static analysis (unused classes, methods, imports, routes, config keys)
2. **Report**: Structured list — file, element, evidence of non-usage, risk assessment
3. **User validates**: Each deletion individually approved
4. **Delete + verify**: Remove, run all gates, commit

### 5. Regression Prevention Checklist

Before marking any task complete:

- [ ] All 6 local quality gates pass (back: cs-fixer, stan, test, e2e / front: typecheck, lint, test)
- [ ] No test was deleted or weakened (modified tests must be strictly equal or stronger)
- [ ] If critical change → user approval obtained
- [ ] Commit is atomic (one concern)

---

## Phase 1: Code Quality (back + front)

### P1-1: PHPStan Level Upgrade (L6 → L7 or max)

**Problem**: PHPStan at level 6 misses type-safety issues (mixed types, union narrowing, generic class checks). Level 7+ catches more real bugs.

**Approach**:
1. Run PHPStan at L7, collect all new errors
2. Categorize: real bugs vs. false positives vs. type annotation gaps
3. Fix real bugs first, add type annotations, configure minimal baseline for framework false positives
4. Target: L7 with zero baseline entries (or max with documented baseline)

**Success criteria**:
- [ ] PHPStan level ≥ 7 in `phpstan.neon`
- [ ] Zero new baseline entries for application code (framework-only allowed)
- [ ] All existing tests pass

### P1-2: Resolve TODOs and Deprecations

**Problem**: 4 TODOs and 3 `@deprecated` markers in production code.

**TODOs**:
- `ProfileCampaignHandler.php:75` — TODO: Add profiled_at field
- `FeatureExtractor.php:180` — TODO: Integrate WHOIS lookup
- `FeatureExtractor.php:188` — TODO: Parse Received headers
- `DSLTranspiler.php:44` — TODO: Add tests for MVP

**Deprecations**:
- `ClassificationResult.php` — old persona data getter
- `CampaignRule.php` — 2 deprecated getters

**Approach**: Resolve each TODO (implement or remove with justification). Remove deprecated code after verifying zero usage. **User validation required for each deletion.**

**Success criteria**:
- [ ] 0 TODO comments in `src/`
- [ ] 0 `@deprecated` annotations in `src/`
- [ ] Each removal validated by user

### P1-3: Dead Code Analysis & Cleanup

**Problem**: After 48 features, dead code likely accumulated (unused services, orphan event listeners, unused imports, unreachable branches).

**Approach**:
1. Run static analysis to detect unused classes, methods, constants, imports
2. Cross-reference with route definitions, service wiring, event subscriptions
3. Produce structured report for user review
4. Delete only after explicit user approval per item

**Success criteria**:
- [ ] Dead code report produced and reviewed by user
- [ ] All validated dead code removed
- [ ] All quality gates pass after cleanup

### P1-4: Frontend Code Quality — Prettier + Lint Hardening

**Problem**: No Prettier config. ESLint rules could be stricter (no unused vars enforcement, import ordering).

**Approach**:
1. Add Prettier config (`.prettierrc`) aligned with existing code style
2. Run Prettier on all files, single formatting commit
3. Review ESLint rules for gaps (unused imports, consistent type imports)
4. Add Prettier check to CI pipeline

**Success criteria**:
- [ ] `.prettierrc` exists and enforces consistent formatting
- [ ] All `.ts`/`.tsx` files formatted
- [ ] `npm run format:check` added to CI
- [ ] ESLint rules tightened (no-unused-imports, consistent-type-imports)

---

## Phase 2: Test Coverage

### P2-1: Backend — Close Coverage Exclusion Gaps

**Problem**: Coverage currently excludes Service layer, Console commands, DataFixtures. These contain business logic that should be tested.

**Approach**:
1. Audit coverage exclusions in `phpunit.xml.dist`
2. Remove exclusions for Service layer and Console commands
3. Write tests for untested service methods and console commands
4. Target: 80%+ line coverage on `src/Application/` and `src/UI/Console/`

**Success criteria**:
- [ ] Service layer no longer excluded from coverage
- [ ] Console commands no longer excluded from coverage
- [ ] Coverage for `Application/` ≥ 80%
- [ ] Coverage for `UI/Console/` ≥ 80%

### P2-2: Frontend — Triple Test Coverage

**Problem**: 8 test files for 72 source files (~11% file coverage). Critical UI flows untested.

**Approach**:
1. Identify highest-risk untested components (auth flows, conversation list, IOC explorer, reply display)
2. Add tests with React Testing Library + MSW for API mocking
3. Priority: pages > shared components > utilities
4. Target: 25+ test files covering all pages and critical shared components

**Success criteria**:
- [ ] ≥ 25 test files in `frontend-react/src/`
- [ ] All page components have at least 1 test file
- [ ] Auth flow fully tested (login, logout, token refresh)
- [ ] Conversation list + detail tested
- [ ] IOC explorer tested

### P2-3: Backend — Integration Test Gaps

**Problem**: Some controllers and handlers may lack integration tests, especially newer features (043+).

**Approach**:
1. Audit test coverage by controller/handler — identify untested endpoints
2. Write integration tests for uncovered endpoints
3. Focus on happy path + error cases + authorization (403 tests)

**Success criteria**:
- [ ] Every controller endpoint has ≥ 1 integration test
- [ ] Error paths tested (400, 404, 403)
- [ ] All quality gates pass

---

## Phase 3: Performance

### P3-1: Database Query Audit (N+1, Missing Indexes)

**Problem**: Doctrine ORM can hide N+1 queries. 43 migrations may have left index gaps. JSON columns (`message.headers`, `actor_profile.style_dna`) may lack proper indexing.

**Approach**:
1. Enable Doctrine SQL logger in test environment
2. Run integration tests, collect all queries
3. Identify N+1 patterns (same query executed in loop)
4. Review existing indexes vs. actual query patterns
5. Add missing indexes via migration. **User validation required.**

**Success criteria**:
- [ ] N+1 queries identified and fixed (eager loading or batch queries)
- [ ] Missing indexes added (with migration)
- [ ] Query count per endpoint documented
- [ ] No performance regression on existing tests

### P3-2: Redis Cache Strategy

**Problem**: Redis is deployed but unclear if used for application caching (beyond rate limiting and sessions).

**Approach**:
1. Audit current Redis usage (rate limiting, sessions, anything else?)
2. Identify cacheable data (persona list, scam types, config, IOC stats, analytics aggregations)
3. Implement cache with appropriate TTL and invalidation
4. Measure before/after response times on key endpoints

**Success criteria**:
- [ ] Redis cache audit documented
- [ ] ≥ 3 high-frequency read endpoints cached
- [ ] Cache invalidation on write operations
- [ ] Response time improvement measurable

### P3-3: Frontend Performance Audit

**Problem**: No documented frontend performance baseline. Possible issues: unnecessary re-renders, large bundle, no code splitting, no lazy loading.

**Approach**:
1. Measure current bundle size (`npm run build` + analyze)
2. Audit React re-renders with React DevTools profiler
3. Implement React.lazy + Suspense for route-level code splitting
4. Memoize expensive computations (useMemo, React.memo where justified)
5. Measure after — target: main bundle < 200KB gzipped

**Success criteria**:
- [ ] Bundle size baseline documented
- [ ] Route-level code splitting implemented
- [ ] No unnecessary re-renders on key pages
- [ ] Bundle size reduced (target: < 200KB gzipped main chunk)

---

## Phase 4: Resilience & Robustness

### P4-1: External Call Resilience (LLM, VirusTotal, URLscan)

**Problem**: External API calls (OpenAI, VirusTotal, URLscan) can timeout or fail. Current error handling may not gracefully degrade.

**Approach**:
1. Audit all external HTTP calls (Guzzle clients)
2. Verify timeouts are configured (connect + read)
3. Add retry with exponential backoff for transient failures
4. Add circuit breaker pattern for persistent failures
5. Ensure pipeline continues on enrichment failure (IOC still saved, just unenriched)

**Success criteria**:
- [ ] All external calls have explicit timeouts (connect: 5s, read: 30s for LLM, 10s for others)
- [ ] Retry policy: 3 attempts with exponential backoff for 5xx/timeout
- [ ] Circuit breaker: disable enrichment after 5 consecutive failures, auto-reset after 5min
- [ ] Integration tests for timeout/failure scenarios
- [ ] Pipeline never blocks on enrichment failure

### P4-2: Error Handling Audit

**Problem**: Inconsistent error handling across the application. Some handlers may swallow exceptions, others may leak stack traces.

**Approach**:
1. Audit exception handling patterns in Application/ handlers
2. Ensure consistent error response format (JSON problem details RFC 7807)
3. Verify no stack trace leakage in production error responses
4. Add structured logging for all caught exceptions

**Success criteria**:
- [ ] Consistent error response format across all endpoints
- [ ] No stack trace in production responses
- [ ] All caught exceptions logged with context
- [ ] Error response tests for each error type

### P4-3: Dependency Hygiene

**Problem**: 2 mail parsers in composer.json (Mail MIME Parser 1.0 + ZBateson 3.0). Potential unused or duplicate dependencies after 48 features.

**Approach**:
1. Audit all Composer and npm dependencies
2. Identify unused dependencies (not imported anywhere)
3. Identify duplicates (2 libraries for same purpose)
4. Remove unused, consolidate duplicates. **User validation required.**
5. Run `composer audit` and `npm audit` — resolve all actionable advisories

**Success criteria**:
- [ ] Dependency audit report produced
- [ ] Unused dependencies removed (user validated)
- [ ] Duplicate libraries consolidated
- [ ] Zero actionable security advisories

### P4-4: CI Pipeline Hardening

**Problem**: CI speed and caching not optimized. Slow CI slows the consolidation itself.

**Approach**:
1. Audit current CI timings (identify slowest jobs)
2. Add Composer + npm dependency caching
3. Parallelize test suites where possible
4. Add frontend coverage reporting to Codecov
5. Add bundle size check to CI (fail if > threshold)

**Success criteria**:
- [ ] CI run time reduced by ≥ 20%
- [ ] Dependency caching active for Composer and npm
- [ ] Frontend coverage reported to Codecov
- [ ] Bundle size gate in CI

---

## Out of Scope

- New features or endpoints
- Database schema changes (except index additions)
- UI/UX redesign
- New integrations
- Symfony 7.3 migration (separate spec)

---

## Validation Plan

### Automated (every commit — Implementation Protocol §2)

All 6 quality gates must pass locally before every commit.

### User Validation Checkpoints

| Checkpoint | When | What to validate |
|-----------|------|-----------------|
| Dead code report | After P1-3 analysis | Review report, approve/reject each deletion |
| PHPStan level change | After P1-1 | Review new level + any baseline entries |
| Prettier formatting | After P1-4 | Review formatting choices |
| Database index migration | After P3-1 | Review migration SQL |
| Dependency removal | After P4-3 | Review each removal |
| Critical deletions | Throughout | Any code deletion per Implementation Protocol §3 |

### End-of-Phase Validation

After each phase completion:

1. Full CI pipeline passes (GitHub Actions)
2. User manual smoke test:
   - Ingestion pipeline (email → ingest → IOC extraction)
   - Reply pipeline (generate → validate → store)
   - STIX/MISP export
   - Frontend navigation (conversations, IOC explorer, monitoring)
   - Auth flow (login, logout, 403 on unauthorized)

---

## Metrics & Targets

| Metric | Before | Target |
|--------|--------|--------|
| PHPStan level | 6 | ≥ 7 |
| Backend test files | 264 | ≥ 290 |
| Frontend test files | 8 | ≥ 25 |
| TODO comments in src/ | 4 | 0 |
| @deprecated in src/ | 3 | 0 |
| Frontend has Prettier | No | Yes |
| Unused dependencies | Unknown | 0 |
| N+1 queries | Unknown | 0 |
| External call timeouts configured | Unknown | 100% |
| CI cache | No | Yes |
| Frontend coverage in Codecov | No | Yes |
| Bundle size CI gate | No | Yes |
| ESLint consistent-type-imports | No | Yes |

---

## Scope Decisions (documented post-implementation)

### Circuit breaker (P4-1): Descoped

**Original spec**: Add circuit breaker pattern (CLOSED/OPEN/HALF_OPEN) with Redis-backed state.
**Decision**: Descoped. The `ReplyOrchestrator` already implements a 3-attempt retry loop with feedback at the application level. Adding HTTP-level retry + circuit breaker inside each LLM adapter would create 3×3=9 total attempts on failure, worsening response times. For a single-user academic project, the existing retry is sufficient.
**What was done instead**: Added explicit 30s timeouts to 2 services that were missing them (OpenAIService, EmbeddingService).

### 403 authorization tests (P2-3): Descoped

**Original spec**: "Add 403 authorization tests for endpoints that require specific permissions."
**Decision**: Cannot test in current architecture. The `PermissionVoter` grants all permissions to `ROLE_USER` for `InMemoryUser` in test environment (line 51). Testing real 403 would require creating a DB user without specific permissions — a refactoring beyond consolidation scope.
**What was done instead**: 401 (unauthorized) tests are covered for all endpoint groups.

### Frontend re-render audit (P3-3): No issues found

**Original spec**: "Audit unnecessary re-renders on key pages."
**Finding**: 0 React.memo, 3 useMemo, 3 useCallback. All key props present in map() calls. TanStack Query handles data dedup. Zustand store is minimal (auth only). No re-render issues identified — the app is small enough that memoization adds complexity without measurable benefit.
