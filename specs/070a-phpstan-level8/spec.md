# Spec 070a — PHPStan Level 6 → 8

> **Parent**: `specs/070-code-quality-hardening/`
> **Effort**: 1-2 days
> **Branch**: `070a-phpstan-level8`

## 1. Context

PHPStan level 6 catches basic type errors. Level 7 adds union type narrowing and parameter type checks. Level 8 enforces complete type specificity — no `mixed`, no `array<string, mixed>`.

Current state:
- Level 6 with bleedingEdge enabled
- 480 instances of `array<string, mixed>` (level 8 violations)
- 468 `@var` annotations (many could be replaced by native types)
- 7 existing ignore rules

## 2. Approach — Incremental

### Step 1: Level 6 → 7
Level 7 adds:
- Strict union type checks
- Parameter type specificity
- Return type narrowing

Fix all level 7 errors, commit.

### Step 2: Level 7 → 8
Level 8 adds:
- No `mixed` type allowed
- Complete `array<K, V>` specificity required
- Generic type enforcement

This is the hard part. Strategy:
- Add `@phpstan-type` aliases for complex array shapes
- Replace `array<string, mixed>` with specific shapes where possible
- Use `@phpstan-ignore-next-line` ONLY for framework boundaries (Symfony/Doctrine return types)

### Step 3: Audit ignore rules
- Review all existing + new ignore rules
- Remove any that are no longer needed
- Set `reportUnmatchedIgnoredErrors: true`

## 3. Non-goals

- Reaching level 9 (max) — too strict for a Symfony project
- Rewriting all array returns as DTOs — scope too large
- Fixing third-party library type issues

## 4. Acceptance criteria

- [ ] `phpstan.neon` level: 8
- [ ] `reportUnmatchedIgnoredErrors: true`
- [ ] Zero errors on `make stan`
- [ ] Ignore rules documented with justification
- [ ] `make test` + `make endToEndTest` green
- [ ] CS-Fixer clean

## 5. Tasks

- [ ] **T1** Bump to level 7, run PHPStan, count errors
- [ ] **T2** Fix level 7 errors batch 1: src/Application/ + commit
- [ ] **T3** Fix level 7 errors batch 2: src/Domain/ + src/Infrastructure/ + commit
- [ ] **T4** Fix level 7 errors batch 3: src/UI/ + commit
- [ ] **T5** Verify level 7 clean → commit config change
- [ ] **T6** Bump to level 8, run PHPStan, count errors
- [ ] **T7** Fix level 8 errors batch 1: add type aliases for common shapes
- [ ] **T8** Fix level 8 errors batch 2: Application layer
- [ ] **T9** Fix level 8 errors batch 3: remaining layers
- [ ] **T10** Audit + minimize ignore rules, enable reportUnmatchedIgnoredErrors
- [ ] **T11** Full CI validation

### Integration process per commit:
```
1. Fix errors for one layer/category
2. phpstan analyse src/ --level {7|8} --memory-limit=512M
3. php-cs-fixer fix src/ --dry-run
4. make test
5. make endToEndTest
6. git commit
```
