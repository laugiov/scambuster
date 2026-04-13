# Spec 067 — Remaining Controller DDD Compliance

> **Branch**: `roadmap/067-remaining-controller-compliance`
> **Sprints**: 2
> **Effort**: ~4 days
> **Sub-specs**: 067a through 067e
> **Release tag**: `v2.20.0` at closure
> **Prerequisite**: v2.19.0 merged (spec 066)

## 1. Context

Post-066 audit found 15 remaining controller violations:
- 3 TOTP controllers inject EntityManagerInterface (already __invoke)
- 2 controllers inject Connection (already __invoke)
- 10 controllers have multiple public methods (need splitting into __invoke)

## 2. Sprint plan

### Sprint 1 — Dependency extraction (zero structural changes)
| Spec | Scope | New files |
|------|-------|-----------|
| **067a** | 3 TOTP controllers: EM → UserRepository port | 2 (interface + impl) |
| **067b** | 2 controllers: Connection → Application services | 2 services |

### Sprint 2 — Controller splits
| Spec | Scope | New controllers |
|------|-------|----------------|
| **067c** | Split 5 Communication controllers (33 methods) | 33 |
| **067d** | Split 3 Monitoring/Clustering controllers (15 methods) | 15 |
| **067e** | Clean up 2 User controllers (route collision) | 4 |

## 3. Quality gates
Same 8 gates as specs 065/066. PHPStan L6, all tests green, atomic commits.

## 4. Success criteria
- Zero multi-action controllers in `src/UI/Http/`
- Zero EntityManagerInterface in `src/UI/Http/`
- Zero Connection in `src/UI/Http/`
- All routes identical (no API breaking changes)
- Full test suite green
