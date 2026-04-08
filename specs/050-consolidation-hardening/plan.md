# Implementation Plan: 050-Consolidation-Hardening

**Branch**: `050-consolidation-hardening` | **Date**: 2026-04-07 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/050-consolidation-hardening/spec.md`

## Summary

Zero-feature consolidation: raise code quality (PHPStan L7+, Prettier, dead code cleanup), close test coverage gaps (frontend 8→25+ test files, backend coverage exclusion removal), improve performance (N+1 audit, Redis caching, frontend bundle optimization), and strengthen resilience (external call timeouts/retry/circuit breaker, error handling, dependency hygiene, CI hardening).

## Technical Context

**Language/Version**: PHP 8.3 / Symfony 7.2 (backend), TypeScript 5.9 / React 19 (frontend)
**Primary Dependencies**: Doctrine ORM 3.3, Lexik JWT 3.1, TanStack Query 5.91, Zustand 5.0, Recharts 3.8, TailwindCSS 4.2
**Storage**: PostgreSQL 15, Redis 7 (cache.adapter.redis for rate limiting + framework cache)
**Testing**: PHPUnit 10.5 (260 test files), Vitest 4.1 (8 test files), MSW 2.12
**Target Platform**: Docker Compose (10 containers), GitHub Actions CI
**Project Type**: Web service (monorepo: backend + frontend)
**Performance Goals**: Eliminate N+1 queries, frontend main bundle < 200KB gzipped
**Constraints**: Zero functional regression, zero new features, all changes atomic
**Scale/Scope**: 351 PHP source files, 72 TS/TSX source files, 43 DB migrations

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| I. DDD Layer Separation | PASS | No new layers or violations — consolidation only |
| II. Hexagonal Ports | PASS | P4-1 adds retry/circuit breaker inside existing adapters |
| III. Test-Driven Quality | PASS | Core goal of this spec — extends coverage |
| IV. Safety & Ethics | PASS | No new functionality, no new LLM calls |
| V. Cost Awareness | PASS | No new LLM calls, Redis cache reduces load |
| VI. Internationalization | PASS | No new user-facing strings |
| VII. Simplicity | PASS | No new abstractions beyond justified patterns (circuit breaker) |

**No violations. Gate passed.**

## Project Structure

### Documentation (this feature)

```text
specs/050-consolidation-hardening/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output (minimal — no new entities)
├── tasks.md             # Phase 2 output (/speckit.tasks)
└── contracts/           # N/A — no new APIs
```

### Source Code (affected areas)

```text
backend-symfony/
├── phpstan.neon                          # P1-1: level upgrade
├── config/packages/cache.yaml            # P3-2: cache strategy
├── src/
│   ├── Application/                      # P2-1: coverage gaps, P4-2: error handling
│   │   ├── Communication/                # P4-1: resilience (ReplyCadenceService)
│   │   └── LLM/EmbeddingService.php      # P4-1: add timeout
│   ├── Infrastructure/LLM/Provider/      # P4-1: retry + circuit breaker
│   │   ├── OpenAIClient.php              # timeout: 30s, no retry
│   │   ├── AnthropicClient.php           # timeout: 60s, no retry
│   │   └── OllamaClient.php             # timeout: 120s, no retry
│   └── Infrastructure/LLM/OpenAIService.php  # P4-1: NO timeout currently
└── tests/
    ├── Integration/                      # P2-3: gap audit
    └── Unit/                             # P2-1: new tests

frontend-react/
├── .prettierrc                           # P1-4: NEW
├── eslint.config.js                      # P1-4: tighten rules
├── package.json                          # P1-4: add format scripts
├── src/
│   ├── components/                       # P2-2: test coverage
│   ├── pages/                            # P2-2: test coverage
│   └── App.tsx                           # P3-3: lazy loading
└── vitest.config.ts                      # P2-2: coverage config

.github/workflows/ci.yml                  # P4-4: caching, frontend coverage
```

---

## Phase 0: Research

### R-1: PHPStan L6 → L7 Error Count

**Current state**: PHPStan level 6 with `bleedingEdge.neon`, 7 ignored errors (all Symfony test helpers).
**Research needed**: How many new errors does L7 introduce?

**Decision**: Run PHPStan at L7 to quantify the effort. L7 adds stricter union type checking and requires explicit `@return` types. If error count > 200, consider L7 with a temporary baseline file that gets burned down incrementally.

### R-2: Prettier Configuration for Existing Codebase

**Decision**: Use Prettier with `singleQuote: true`, `semi: false`, `trailingComma: 'all'` to match existing code style (observed in ESLint config and existing `.ts` files). Run once as a single formatting commit.
**Alternatives considered**: ESLint `--fix` only (rejected: doesn't cover formatting), dprint (rejected: less ecosystem support for React).

### R-3: Frontend Test Strategy

**Current state**: 8 test files covering: StatCard, Badge, ErrorBoundary (components), useMetaConfig (hook), time/format (utils), IocDetail/IocExplorer (pages).
**Missing**: All auth flows, conversation pages, reply display, monitoring pages, layout components, stores, API services.
**Decision**: Prioritize page-level integration tests with MSW mocking. Target 25+ test files covering all routes.

### R-4: Redis Cache — What to Cache

**Current state**: Redis used for rate limiting (6 limiters) and framework cache adapter. No explicit application-level caching.
**Decision**: Cache these high-frequency read endpoints:
1. `/api/v1/meta/config` (persona list, scam types, IOC types) — TTL 5min, invalidate on config write
2. `/api/v1/monitoring/analytics/*` — TTL 1min (dashboard refresh)
3. `/api/v1/scambaiting/stats` — TTL 2min
**Alternatives considered**: Full page caching (rejected: JWT auth makes it complex), Varnish (rejected: overkill for single-user academic project).

### R-5: External Call Resilience Patterns

**Current state**:
- OpenAI: 30s timeout, no retry
- Anthropic: 60s timeout, no retry
- Ollama: 120s timeout, no retry
- EmbeddingService: NO explicit timeout, no retry
- OpenAIService (legacy): NO explicit timeout, no retry
- No circuit breaker anywhere
- FallbackProvider exists but only provides static fallback messages

**Decision**: Implement retry with exponential backoff (3 attempts, base 1s, max 10s) inside each LLM adapter. Add a simple circuit breaker (threshold: 5 consecutive failures, cooldown: 5min) as a shared service injected into adapters. Use Symfony's `Stopwatch` for latency tracking.
**Alternatives considered**: External library like `ganesha` (rejected: adds dependency for simple pattern), Symfony Messenger retry (rejected: LLM calls are synchronous in ReplyOrchestrator).

### R-6: N+1 Query Detection Strategy

**Decision**: Use Doctrine SQL logger (`DebugStack`) in test environment. Run integration tests, collect queries per test, flag any test executing > 20 SELECT queries or same query pattern > 3 times.
**Alternatives considered**: Blackfire (rejected: requires license), Symfony Profiler toolbar (rejected: manual inspection doesn't scale).

---

## Phase 1: Design

### Data Model

No new entities. Changes are limited to:
- **Configuration files**: phpstan.neon, .prettierrc, eslint.config.js, cache.yaml, ci.yml
- **Existing service modifications**: LLM adapters (retry/circuit breaker), cache decorators
- **New test files only**

See [data-model.md](data-model.md) for the circuit breaker service design.

### Contracts

No new API contracts. All existing endpoints preserve their current request/response format. The only observable change: cached endpoints may return faster, and external call failures may retry transparently.

### Implementation Protocol (from spec — NORMATIVE)

Every task in tasks.md MUST follow this protocol:

1. **Atomic commits** — one concern per commit
2. **All 6 local quality gates before every commit**:
   - `make cs-fixer` → `make stan` → `make test` → `make endToEndTest`
   - `cd frontend-react && npm run typecheck && npm run lint && npm run test`
3. **Critical changes require user approval** before commit:
   - Code deletion, public signature changes, config changes, DB migrations, major dep upgrades, security-sensitive changes
4. **Dead code**: report first, user approves each deletion
5. **No test weakening**: tests only added or strengthened

### Risk Assessment

| Risk | Mitigation |
|------|-----------|
| PHPStan L7 breaks too many things | Use baseline file, burn down incrementally |
| Prettier reformats break git blame | Single commit, use `git blame --ignore-rev` |
| Frontend tests depend on implementation details | Use Testing Library best practices (query by role/text) |
| Redis cache causes stale data | Short TTLs + explicit invalidation on write |
| Circuit breaker triggers too aggressively | Conservative threshold (5 failures), short cooldown (5min) |
| N+1 fix changes query semantics | Integration tests validate correct data returned |

### Phase Dependency Graph

```
P1 (Code Quality) ──→ P2 (Tests) ──→ P3 (Performance) ──→ P4 (Resilience)
     │                      │                │                    │
     │                      │                │                    │
   P1-1 PHPStan           P2-1 Back cover  P3-1 N+1 audit     P4-1 Ext. calls
   P1-2 TODOs/deprec.     P2-2 Front tests P3-2 Redis cache   P4-2 Error handling
   P1-3 Dead code         P2-3 Integ. gaps P3-3 Front perf    P4-3 Dep. hygiene
   P1-4 Prettier                                               P4-4 CI hardening
```

Phases are sequential (P1 before P2) because:
- P1 (quality) provides a clean baseline for P2 (tests)
- P2 (tests) provides safety net for P3 (performance changes)
- P3 (performance) should be stable before P4 (resilience patterns)

Within each phase, tasks are independent and can be done in any order.

---

## Complexity Tracking

No constitution violations. No complexity justifications needed.
