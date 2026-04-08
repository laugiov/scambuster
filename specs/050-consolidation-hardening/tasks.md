# Tasks: 050-Consolidation-Hardening

**Branch**: `050-consolidation-hardening`
**Protocol**: Every task MUST follow [spec.md — Implementation Protocol §1-§5](spec.md)

> **REMINDER before every commit**:
> ```bash
> make cs-fixer && make stan && make test && make endToEndTest
> cd frontend-react && npm run typecheck && npm run lint && npm run test
> ```
> If any gate fails → fix before continuing. Critical changes → ask user approval.

---

## Phase 1: Code Quality

### P1-1: PHPStan Level Upgrade (L6 → L7+)

- [ ] **P1-1a**: Run PHPStan at level 7, capture full error list, categorize (real bugs / type annotations / framework false positives)
- [ ] **P1-1b**: Fix real bugs found by L7 analysis
- [ ] **P1-1c**: Add missing type annotations (`@return`, `@param`, union narrowing)
- [ ] **P1-1d**: Update `phpstan.neon` to level 7 (or higher if feasible). If errors > 200: create baseline file with burn-down plan. **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P1-1e**: Run all 6 quality gates — verify zero regressions

### P1-2: Resolve TODOs and Deprecations

- [ ] **P1-2a**: `ProfileCampaignHandler.php:75` — TODO: Add profiled_at field. Evaluate: implement or remove with justification. **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P1-2b**: `FeatureExtractor.php:180` — TODO: Integrate WHOIS lookup. Evaluate: implement or remove with justification. **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P1-2c**: `FeatureExtractor.php:188` — TODO: Parse Received headers. Evaluate: implement or remove with justification. **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P1-2d**: `DSLTranspiler.php:44` — TODO: Add tests for MVP. Write the missing tests.
- [ ] **P1-2e**: `ClassificationResult.php` — deprecated persona data getter. Verify zero usage, remove. **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P1-2f**: `CampaignRule.php` — 2 deprecated getters. Verify zero usage, remove. **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P1-2g**: Run all 6 quality gates — verify zero regressions

### P1-3: Dead Code Analysis & Cleanup

- [ ] **P1-3a**: Run static analysis — detect unused classes, methods, constants, imports across `src/`
- [ ] **P1-3b**: Cross-reference with route definitions (`debug:router`), service wiring (`services.yaml`), event subscribers
- [ ] **P1-3c**: Produce structured dead code report (file, element, evidence, risk). **Present to user for review**
- [ ] **P1-3d**: Delete user-approved dead code items, one commit per logical group. **⚠️ CRITICAL CHANGE → user approval per item**
- [ ] **P1-3e**: Run all 6 quality gates — verify zero regressions

### P1-4: Frontend — Prettier + Lint Hardening

- [ ] **P1-4a**: Create `.prettierrc` config (`singleQuote: true`, `semi: false`, `trailingComma: 'all'`, `printWidth: 100`). **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P1-4b**: Add `format`, `format:check` scripts to `package.json`
- [ ] **P1-4c**: Run Prettier on all `.ts`/`.tsx` files — single formatting commit. Add commit SHA to `.git-blame-ignore-revs`
- [ ] **P1-4d**: Tighten ESLint rules (no-unused-imports, consistent-type-imports). **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P1-4e**: Add `npm run format:check` to CI pipeline (`ci.yml` frontend job)
- [ ] **P1-4f**: Run all 6 quality gates — verify zero regressions

---

## Phase 2: Test Coverage

### P2-1: Backend — Close Coverage Exclusion Gaps

- [ ] **P2-1a**: Audit current coverage exclusions in `phpunit.xml.dist` — list all excluded paths and their justification
- [ ] **P2-1b**: Remove coverage exclusion for `src/Service` (if classes exist and contain business logic)
- [ ] **P2-1c**: Remove coverage exclusion for `src/UI/Console` (non-test/non-preprod commands)
- [ ] **P2-1d**: Write unit/integration tests for previously-excluded Console commands (`CalculateRewardsCommand`, `CloseStaleConversationsCommand`, `WeeklyCleanupCommand`, etc.)
- [ ] **P2-1e**: Write tests for previously-excluded Service layer methods
- [ ] **P2-1f**: Run coverage report — verify Application/ ≥ 80%, UI/Console/ ≥ 80%
- [ ] **P2-1g**: Run all 6 quality gates — verify zero regressions

### P2-2: Frontend — Triple Test Coverage

- [ ] **P2-2a**: Set up Vitest coverage config (`@vitest/coverage-v8`), add `test:coverage` script
- [ ] **P2-2b**: Write tests for auth flow: Login page (form submission, error handling, token storage)
- [ ] **P2-2c**: Write tests for ConversationList page (data loading, filtering, pagination)
- [ ] **P2-2d**: Write tests for ConversationDetail page (message display, reply status)
- [ ] **P2-2e**: Write tests for monitoring/analytics pages (chart rendering, data fetching)
- [ ] **P2-2f**: Write tests for shared layout components (Sidebar, Header, ProtectedRoute)
- [ ] **P2-2g**: Write tests for Zustand stores (auth store, config store)
- [ ] **P2-2h**: Write tests for remaining pages (PersonaList, Dashboard, Settings, etc.)
- [ ] **P2-2i**: Verify ≥ 25 test files, all pages covered. Run all 6 quality gates.

### P2-3: Backend — Integration Test Gap Audit

- [ ] **P2-3a**: Audit: list all controller endpoints vs. existing test files — identify gaps
- [ ] **P2-3b**: Write integration tests for uncovered endpoints (happy path + error cases)
- [ ] **P2-3c**: Add 403 authorization tests for endpoints that require specific permissions
- [ ] **P2-3d**: Run all 6 quality gates — verify zero regressions

---

## Phase 3: Performance

### P3-1: Database Query Audit (N+1, Missing Indexes)

- [ ] **P3-1a**: Enable Doctrine `DebugStack` SQL logger in test kernel config
- [ ] **P3-1b**: Run integration tests, capture SQL query log per test
- [ ] **P3-1c**: Analyze: flag N+1 patterns (same query > 3x), flag tests with > 20 SELECTs
- [ ] **P3-1d**: Fix N+1 queries (eager joins, batch DQL, QueryBuilder optimization)
- [ ] **P3-1e**: Review existing indexes vs. actual query patterns (`EXPLAIN ANALYZE` on slow queries)
- [ ] **P3-1f**: Create migration for missing indexes. **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P3-1g**: Run all 6 quality gates — verify zero regressions

### P3-2: Redis Application Cache

- [ ] **P3-2a**: Audit current Redis usage — document what's cached vs. not
- [ ] **P3-2b**: Add Symfony cache pool for meta config (TTL 5min). Wire invalidation on config write endpoints.
- [ ] **P3-2c**: Add cache for analytics/stats endpoints (TTL 1-2min)
- [ ] **P3-2d**: Write integration tests for cache hit/miss/invalidation
- [ ] **P3-2e**: Measure before/after response times on cached endpoints
- [ ] **P3-2f**: Run all 6 quality gates — verify zero regressions

### P3-3: Frontend Performance Audit

- [ ] **P3-3a**: Measure current bundle size (`npm run build`, analyze output)
- [ ] **P3-3b**: Implement React.lazy + Suspense for route-level code splitting in App.tsx
- [ ] **P3-3c**: Audit unnecessary re-renders on key pages (ConversationList, IocExplorer, Dashboard)
- [ ] **P3-3d**: Add React.memo / useMemo where measurably beneficial
- [ ] **P3-3e**: Verify bundle size reduction. Add bundle size check script.
- [ ] **P3-3f**: Run all 6 quality gates — verify zero regressions

---

## Phase 4: Resilience & Robustness

### P4-1: External Call Resilience

- [ ] **P4-1a**: Add explicit 30s timeout to `EmbeddingService.php` and `OpenAIService.php` (currently missing)
- [ ] **P4-1b**: Create `LlmCircuitBreaker` service in `Application/LLM/` — Redis-backed state machine (CLOSED/OPEN/HALF_OPEN), threshold 5 failures, cooldown 5min
- [ ] **P4-1c**: Add retry with exponential backoff (3 attempts, 1s/2s/4s) to `OpenAIClient`, `AnthropicClient`, `OllamaClient` — only on 5xx/timeout, NOT on 4xx
- [ ] **P4-1d**: Integrate circuit breaker into all LLM adapters — skip call if circuit open, record success/failure
- [ ] **P4-1e**: Ensure pipeline graceful degradation: IOC enrichment failure → IOC saved unenriched, LLM failure after 3 retries → static fallback (FallbackProvider)
- [ ] **P4-1f**: Write unit tests for circuit breaker (state transitions), integration tests for retry behavior
- [ ] **P4-1g**: Run all 6 quality gates — verify zero regressions

### P4-2: Error Handling Audit

- [ ] **P4-2a**: Audit exception handling in `Application/` handlers — list swallowed exceptions, inconsistent error formats
- [ ] **P4-2b**: Verify consistent JSON error response format (RFC 7807 Problem Details) across all endpoints
- [ ] **P4-2c**: Verify no stack trace leakage in production error responses (check `kernel.exception` listener)
- [ ] **P4-2d**: Add structured logging context to all caught exceptions (handler name, entity IDs, operation)
- [ ] **P4-2e**: Write tests for error response format (400, 404, 403, 500)
- [ ] **P4-2f**: Run all 6 quality gates — verify zero regressions

### P4-3: Dependency Hygiene

- [ ] **P4-3a**: Audit Composer dependencies — identify unused packages (not imported in `src/`). Note: investigate 2 mail parsers (Mail MIME Parser 1.0 + ZBateson 3.0)
- [ ] **P4-3b**: Audit npm dependencies — identify unused packages (not imported in `src/`)
- [ ] **P4-3c**: Produce dependency audit report. **Present to user for review**
- [ ] **P4-3d**: Remove user-approved unused dependencies. **⚠️ CRITICAL CHANGE → user approval per removal**
- [ ] **P4-3e**: Run `composer audit` + `npm audit` — resolve actionable advisories
- [ ] **P4-3f**: Run all 6 quality gates — verify zero regressions

### P4-4: CI Pipeline Hardening

- [ ] **P4-4a**: Audit current CI job timings (check GitHub Actions run history)
- [ ] **P4-4b**: Add Composer dependency caching to backend CI jobs. **⚠️ CRITICAL CHANGE → user approval required**
- [ ] **P4-4c**: Verify npm caching is optimal in frontend CI job
- [ ] **P4-4d**: Add frontend Vitest coverage upload to Codecov
- [ ] **P4-4e**: Add bundle size check to CI (fail if main chunk > 200KB gzipped)
- [ ] **P4-4f**: Run all 6 quality gates — verify zero regressions

---

## Completion Checklist

After all phases:

- [ ] All 6 quality gates pass locally
- [ ] Full CI pipeline passes on branch
- [ ] PHPStan level ≥ 7
- [ ] 0 TODO comments in `src/`
- [ ] 0 `@deprecated` in `src/`
- [ ] Frontend has Prettier + format:check in CI
- [ ] ≥ 25 frontend test files
- [ ] Backend coverage exclusions reduced
- [ ] N+1 queries fixed
- [ ] Redis cache on ≥ 3 endpoints
- [ ] LLM adapters have retry + circuit breaker
- [ ] Unused dependencies removed
- [ ] CI has dependency caching + frontend coverage
- [ ] **User final smoke test**: ingestion → reply → IOC → export → frontend navigation → auth
