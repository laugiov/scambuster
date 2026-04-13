# Spec 066 — DDD Architecture Compliance Roadmap

> **Branch**: `roadmap/066-ddd-architecture-compliance`
> **Sprints**: 2 (1 week each)
> **Effort**: ~2 weeks total
> **Sub-specs**: 066a through 066e
> **Release tag**: `v2.19.0` at closure
> **Prerequisite**: main branch clean, all tests green

## 1. Context

A comprehensive DDD architecture audit conducted on 2026-04-12 revealed **88 structural violations** across the backend-symfony codebase. These violations fall into five categories:

1. **Test misclassification** — 59 test files in wrong directories (EndToEnd tests classified as Integration, Unit tests classified as Integration, plus 9 duplicates across both).
2. **Console command split** — 22 commands in `src/Command/` instead of the DDD-canonical `src/UI/Console/`.
3. **Controller violations** — 2 controllers breaking DDD rules (EntityManager injection in UI layer, multi-action controller).
4. **Missing hexagonal ports** — 1 Application service directly coupling to Doctrine EntityManager instead of using Domain repository interfaces.
5. **EntityManager leakage in commands** — 15 console commands injecting EntityManager or Connection directly instead of delegating to Application services.

None of these are functional bugs — the platform works. But they undermine the DDD architecture, make testing harder, confuse new contributors, and create coupling that will compound with every new feature.

## 2. Audit findings summary

| ID | Description | Severity | Files | Sub-spec |
|----|-------------|----------|-------|----------|
| T1 | 51 WebTestCase tests in `Integration/UI/Http/` should be `EndToEnd/` | CRITICAL | 51 | 066a |
| T2 | 5 WebTestCase tests in `Integration/Auth/` should be `EndToEnd/` | HIGH | 5 | 066a |
| T3 | 1 WebTestCase test in `Integration/Communication/` should be `EndToEnd/` | HIGH | 1 | 066a |
| T4 | 2 PHPUnit\TestCase tests in `Integration/` should be `Unit/` | MEDIUM | 2 | 066a |
| T5 | 9 duplicate test files across `Integration/` and `EndToEnd/` | HIGH | 9 | 066a |
| C1 | 22 commands in `src/Command/` instead of `src/UI/Console/` | HIGH | 22 | 066b |
| V1 | `LoginController` injects EntityManager directly | MEDIUM | 1 | 066c |
| V3 | `AdminLlmKillSwitchController` has 2 public methods | MEDIUM | 1 | 066c |
| V2 | `EntityReferenceResolver` uses EM instead of repository ports | HIGH | 1 | 066d |
| E1 | 11 `src/Command/` files inject EntityManagerInterface | MEDIUM | 11 | 066e |
| E2 | 4 `src/UI/Console/` files inject EntityManagerInterface | MEDIUM | 4 | 066e |

**Total**: 88 file-level violations across 5 sub-specs.

## 3. Architecture rules enforced

These rules are already documented in CLAUDE.md and development-instructions.md. This roadmap enforces them retroactively:

| Rule | Assertion |
|------|-----------|
| **R1** | Controllers are `final`, have exactly one public method (`__invoke`), and NEVER inject EntityManager |
| **R2** | Console commands are in `src/UI/Console/` — the CLI equivalent of HTTP controllers |
| **R3** | Application services use Domain repository interfaces (ports), not Doctrine EntityManager |
| **R4** | `tests/Unit/` = `PHPUnit\Framework\TestCase` only, no container, no DB, no HTTP |
| **R5** | `tests/Integration/` = `KernelTestCase` only, container + DB, no HTTP requests |
| **R6** | `tests/EndToEnd/` = `WebTestCase`, HTTP requests via KernelBrowser |
| **R7** | No duplicate test files across test directories |

## 4. Sprint plan

### Sprint 1 — Structural moves (zero logic changes)

| Sub-spec | Scope | Risk | Effort |
|----------|-------|------|--------|
| **066a** | Move 59 test files + consolidate 9 duplicates | Low | 1 day |
| **066b** | Move 22 commands to `src/UI/Console/` | Medium | 1 day |

Sprint 1 changes ONLY file locations + namespaces. Zero business logic is modified. All tests must remain green after each move.

### Sprint 2 — Logic refactoring

| Sub-spec | Scope | Risk | Effort |
|----------|-------|------|--------|
| **066c** | Split AdminLlmKillSwitchController, extract LoginController EM | Low | 0.5 day |
| **066d** | Create 3 Domain repository interfaces for EntityReferenceResolver | Medium | 1 day |
| **066e** | Extract EM from 15 commands into Application services | High | 2-3 days |

## 5. Sequencing constraints

```
066a ─┐
      ├── Sprint 1 (parallel, no dependencies)
066b ─┘
       │
       ▼
066c ─┐
066d ─┤── Sprint 2 (parallel, no dependencies between them)
066e ─┘   (066e SHOULD run after 066b so commands are in final location)
```

## 6. Quality gates (per commit)

Same 8 gates as spec 065:

1. `make stan` — PHPStan level 6 clean
2. `make cs-fixer` — PHP-CS-Fixer clean
3. `make test` — All unit + integration tests green
4. `make endToEndTest` — All E2E tests green
5. No new `FAKE_CONTENT`, no hardcoded secrets
6. Atomic commits (one logical change per commit)
7. TDD where applicable (new code written test-first)
8. User approval before merge to main

## 7. Success criteria

After `v2.19.0` ships:

- [ ] Zero files in `src/Command/` (directory removed or empty)
- [ ] Zero `WebTestCase` subclasses in `tests/Integration/`
- [ ] Zero `PHPUnit\Framework\TestCase` subclasses in `tests/Integration/` (that don't use container)
- [ ] Zero duplicate test files across `Integration/` and `EndToEnd/`
- [ ] All controllers have exactly one public method (`__invoke`)
- [ ] `EntityReferenceResolver` injects repository interfaces, not EntityManager
- [ ] `LoginController` does not inject EntityManager
- [ ] Full test suite green (2430+ tests)

## 8. Non-goals

- **Refactoring all 46 Application services that use EntityManager** — that is a separate architectural debt item (A3 from the audit). Only EntityReferenceResolver is in scope because it was explicitly extracted during spec 065 and should have used ports from the start.
- **Adding CI enforcement rules** (e.g., PHPStan rules to prevent future regressions) — desirable but out of scope. Can be a follow-up spec.
- **Touching Domain layer** — IocExtractionPolicy was audited and found correctly placed. No Domain changes needed.
- **Frontend changes** — this roadmap is backend-only.

## 9. References

- Architecture audit: 2026-04-12, DDD layer compliance + test/command audit
- CLAUDE.md: Controller rules, handler rules, DDD architecture
- Spec 065: Security quality roadmap (introduced some of the violations being fixed)
