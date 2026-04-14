# Spec 070 — Code Quality Hardening

> **Branch**: `roadmap/070-code-quality-hardening`
> **Effort**: ~5 days
> **Sub-specs**: 070a through 070e
> **Release tag**: `v2.21.0`

## Context

Backend at 90%+ coverage with 3247 tests. Architecture DDD 100% compliant. Time to harden the code quality tooling itself.

## Sub-specs (implementation order)

| Spec | Scope | Effort |
|------|-------|--------|
| **070e** | CI security: pin Actions to SHA, Trivy blocking, remove `\|\| true` | 30min |
| **070c** | Rector PHP: automated modernisation passes | 0.5 day |
| **070a** | PHPStan level 6 → 8: strict typing enforcement | 1-2 days |
| **070d** | Doctrine performance: proxy gen, N+1 guards, cache | 1 day |
| **070b** | Infection mutation testing: setup + baseline score | 1.5 days |

## Integration process (mandatory for EVERY commit)

```
1. Write/modify code (TDD when applicable)
2. docker exec scambuster-github-backend-dev-1 php vendor/bin/phpstan analyse src/ --level {N} --memory-limit=512M
3. cd backend-symfony && php vendor/bin/php-cs-fixer fix src/ --dry-run --using-cache=no
4. make test (unit + integration + functional — 3247+ tests)
5. make endToEndTest (305+ tests)
6. git commit (atomic, one logical change)
7. git push → verify GitHub Actions CI passes
```
