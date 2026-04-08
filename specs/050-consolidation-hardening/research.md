# Research: 050-Consolidation-Hardening

## R-1: PHPStan Level 7 Impact

**Decision**: Upgrade from L6 to L7
**Rationale**: Level 7 adds stricter union type checking and requires explicit return types. The codebase already enables `bleedingEdge.neon`, `checkExplicitMixed`, `checkUnionTypes`, and `checkUninitializedProperties` — many L7 checks are already partially active. Expected new errors: 50-150 range (mostly missing `@return` type annotations).
**Alternatives considered**:
- Jump to L8/max: Too risky in one step, may require extensive refactoring
- Stay at L6: Misses real type-safety bugs that L7 catches
**Approach**: Run L7, fix all errors. If count > 200, use baseline file with burn-down plan.

## R-2: Prettier Configuration

**Decision**: Add Prettier with settings matching existing code conventions
**Rationale**: Frontend has ESLint but no formatting tool. Inconsistent formatting wastes review time.
**Config**: `singleQuote: true`, `semi: false` (matches current TS files), `trailingComma: 'all'`, `printWidth: 100`
**Alternatives considered**:
- dprint: Faster but smaller ecosystem, less IDE support
- ESLint --fix only: Doesn't cover formatting (indentation, line breaks)
**Risk mitigation**: Single formatting commit. Add rev to `.git-blame-ignore-revs`.

## R-3: Frontend Test Coverage Strategy

**Decision**: Page-level integration tests with MSW, targeting 25+ test files
**Rationale**: Current 8 test files cover utilities and 2 pages. Missing: auth flows, conversation pages, monitoring, layout.
**Priority order**:
1. Auth pages (Login) — security-critical
2. Conversation list + detail — core feature
3. Monitoring/analytics pages — data display
4. Shared components (Layout, Sidebar, etc.)
**Test approach**: React Testing Library (query by role/text), MSW for API mocking, Zustand store testing via component integration.

## R-4: Redis Application Cache

**Decision**: Cache 3 high-frequency read-only endpoints
**Rationale**: Redis is deployed and configured (`cache.adapter.redis`) but only used for rate limiting. Meta config, analytics, and stats endpoints return slowly-changing data.
**Endpoints to cache**:
| Endpoint | TTL | Invalidation |
|----------|-----|-------------|
| `/api/v1/meta/config` | 5 min | On persona/scamtype write |
| `/api/v1/monitoring/analytics/*` | 1 min | TTL expiry |
| `/api/v1/scambaiting/stats` | 2 min | TTL expiry |
**Alternatives considered**:
- HTTP-level caching (Cache-Control headers): Good but doesn't help server-side
- Full ORM result cache: Too aggressive, invalidation complex

## R-5: External Call Resilience

**Decision**: Add retry + circuit breaker to LLM adapters
**Current state**:
| Client | Timeout | Retry | Circuit Breaker |
|--------|---------|-------|-----------------|
| OpenAIClient | 30s | None | None |
| AnthropicClient | 60s | None | None |
| OllamaClient | 120s | None | None |
| EmbeddingService | Default (~30s) | None | None |
| OpenAIService (legacy) | None | None | None |

**Design**:
- **Retry**: 3 attempts, exponential backoff (1s, 2s, 4s), only on 5xx/timeout/connection errors. NOT on 4xx (client errors).
- **Circuit breaker**: Simple state machine (CLOSED → OPEN → HALF_OPEN). Open after 5 consecutive failures, cooldown 5min, half-open allows 1 probe request.
- **Implementation**: `LlmCircuitBreaker` service (Application/LLM/), injected into each adapter. State stored in Redis (shared across PHP-FPM workers).
- **Missing timeouts**: Add explicit 30s timeout to EmbeddingService and OpenAIService.

**Alternatives considered**:
- External library (ackintosh/ganesha): Adds dependency for 50-line pattern
- Symfony Messenger async retry: LLM calls are synchronous in ReplyOrchestrator pipeline

## R-6: N+1 Query Detection

**Decision**: Use Doctrine DebugStack SQL logger in test env
**Rationale**: Doctrine can generate N+1 queries silently via lazy loading. 43 migrations may have left index gaps.
**Approach**:
1. Enable `DebugStack` logger in test kernel
2. Run full integration test suite, capture SQL per test
3. Flag: same query pattern > 3 times = likely N+1
4. Flag: test with > 20 SELECTs = investigate
5. Fix with `fetch: EAGER` joins, `QueryBuilder` batch loads, or explicit DQL joins
**Alternatives considered**:
- Blackfire: Requires license ($)
- Symfony Profiler: Manual inspection, doesn't scale
- PHPStan Doctrine rules: Catches some N+1 but not all
