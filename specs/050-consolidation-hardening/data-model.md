# Data Model: 050-Consolidation-Hardening

## No New Entities

This spec creates zero new database tables or entities. All changes operate on existing code and configuration.

## New Service: LlmCircuitBreaker (P4-1)

A lightweight circuit breaker for LLM external calls, stored in Redis.

```
LlmCircuitBreaker
├── state: CLOSED | OPEN | HALF_OPEN  (per provider key)
├── failureCount: int                  (consecutive failures)
├── lastFailureAt: DateTimeImmutable
├── threshold: 5                       (failures before OPEN)
├── cooldownSeconds: 300               (5 min before HALF_OPEN)
└── Methods:
    ├── isAvailable(string $providerKey): bool
    ├── recordSuccess(string $providerKey): void
    └── recordFailure(string $providerKey): void
```

**Storage**: Redis key `circuit_breaker:{providerKey}` with JSON payload + TTL.
**Provider keys**: `openai`, `anthropic`, `ollama`, `embedding`.

## Configuration Changes

| File | Change | Type |
|------|--------|------|
| `phpstan.neon` | level: 6 → 7+ | Config |
| `cache.yaml` | Add cache pools for meta/analytics/stats | Config |
| `.prettierrc` | New file | Config |
| `eslint.config.js` | Tighter rules | Config |
| `ci.yml` | Add caching, frontend coverage | Config |
| `phpunit.xml.dist` | Remove coverage exclusions | Config |

## Index Changes (P3-1 — TBD after N+1 audit)

Index additions will be determined after the N+1 query audit. Any migration requires user approval per Implementation Protocol §3.
